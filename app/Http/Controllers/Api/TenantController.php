<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigMora;
use App\Models\Tenant;
use App\Models\Unidad;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Gasto;
use App\Models\Ticket;
use App\Models\Reserva;
use App\Models\Acceso;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────
    // GET /api/v1/tenant
    // ──────────────────────────────────────────────────────────────────────
    public function show(Request $request)
    {
        $tenant = Tenant::findOrFail($request->get('tenant_id'));

        return response()->json([
            'data' => $tenant->only([
                'id', 'nombre', 'ruc', 'slug', 'email',
                'telefono', 'direccion', 'logo_url', 'plan',
                'tipo_ph',   // ENUM: edificio | casa | deposito  (solo lectura desde UI)
                'extras_ph', // JSON array: tipos adicionales (solo aplica a apartamento)
            ]),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PUT /api/v1/tenant  (acepta multipart/form-data para subir logo)
    // ──────────────────────────────────────────────────────────────────────
    public function update(Request $request)
    {
        $tenant = Tenant::findOrFail($request->get('tenant_id'));

        $validated = $request->validate([
            'nombre'    => 'required|string|max:150',
            'ruc'       => 'nullable|string|max:20',
            'direccion' => 'nullable|string|max:255',
            'telefono'  => 'nullable|string|max:30',
            'email'     => 'nullable|email|max:150',
            'logo'      => 'nullable|file|mimes:jpg,jpeg,png,svg,webp|max:2048',
            // tipo_ph NO se acepta en el update (es fijo, solo se define en INSERT)
            'extras_ph' => 'nullable|string',  // JSON string desde FormData
        ]);

        // Manejar subida de logo al storage (disco public = storage/app/public/)
        if ($request->hasFile('logo')) {
            // Eliminar logo anterior si existe
            if ($tenant->logo_url) {
                $relativePath = str_replace('/storage/', '', parse_url($tenant->logo_url, PHP_URL_PATH));
                Storage::disk('public')->delete($relativePath);
            }
            // Guardar en storage/app/public/logos/{tenant_id}/
            $path = $request->file('logo')->store("logos/{$tenant->id}", 'public');
            // Construir URL usando la base del request (funciona en cualquier subdirectorio)
            $validated['logo_url'] = url('storage/' . $path);
        }
        unset($validated['logo']);

        // extras_ph llega como JSON string; decodificar y sanear
        if (isset($validated['extras_ph']) && is_string($validated['extras_ph'])) {
            $decoded = json_decode($validated['extras_ph'], true);
            $validated['extras_ph'] = is_array($decoded) ? $decoded : [];
        }
        // tipo_ph nunca se toca desde update()
        unset($validated['tipo_ph']);

        $tenant->update($validated);

        return response()->json([
            'data'    => $tenant->fresh()->only([
                'id', 'nombre', 'ruc', 'slug', 'email', 'telefono',
                'direccion', 'logo_url', 'plan', 'tipo_ph', 'extras_ph',
            ]),
            'message' => 'Datos del PH actualizados.',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/v1/dashboard
    // ──────────────────────────────────────────────────────────────────────
    public function dashboard(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $mes      = now()->format('Y-m');   // "2026-02"
        $hoy      = today();                // Carbon date

        // ── 1. Tenant name (already available from middleware context) ────
        $tenantNombre = Tenant::where('id', $tenantId)->value('nombre');

        // ── 2. Aggregated stats — one query per concern ───────────────────

        // 2a. Unidades activas
        $totalUnidades = Unidad::where('tenant_id', $tenantId)
            ->where('activa', 1)
            ->count();

        // 2b. Morosos: unidades distintas con al menos 1 cuota vencida (cualquier periodo)
        $this->actualizarMoras($tenantId);
        $morosos = Cuota::where('tenant_id', $tenantId)
            ->where('estado', 'vencida')
            ->distinct('unidad_id')
            ->count('unidad_id');

        // 2c. Recaudado + Meta: usar el periodo de facturación más reciente con cuotas
        $ultimoPeriodo = Cuota::where('tenant_id', $tenantId)
            ->max('periodo') ?? $mes;

        $recaudado = Pago::where('pagos.tenant_id', $tenantId)
            ->join('cuotas', 'cuotas.id', '=', 'pagos.cuota_id')
            ->where('cuotas.periodo', $ultimoPeriodo)
            ->where('pagos.anulado', false)
            ->sum('pagos.monto');

        // Si no hay pagos del periodo más reciente, mostrar total de últimos 30 días
        if ((float) $recaudado === 0.0) {
            $recaudado = Pago::where('tenant_id', $tenantId)
                ->where('fecha_pago', '>=', now()->subDays(30)->toDateString())
                ->where('anulado', false)
                ->sum('monto');
        }

        // 2d. Meta: total cuotas generadas para el periodo más reciente
        $meta = Cuota::where('tenant_id', $tenantId)
            ->where('periodo', $ultimoPeriodo)
            ->sum('monto');

        // 2e. Tickets abiertos y urgentes
        [$ticketsAbiertos, $ticketsUrgentes] = $this->statsTickets($tenantId);

        // 2f. Accesos hoy
        $accesosHoy = Acceso::where('tenant_id', $tenantId)
            ->whereDate('fecha_hora_entrada', $hoy)
            ->count();

        // 2g. Reservas pendientes
        $reservasPendientes = Reserva::where('tenant_id', $tenantId)
            ->where('estado', 'pendiente')
            ->count();

        // ── Stats array ───────────────────────────────────────────────────
        $stats = [
            'total_unidades'      => $totalUnidades,
            'morosos'             => $morosos,
            'recaudado'           => round((float) $recaudado, 2),
            'meta'                => round((float) $meta, 2),
            'tickets_abiertos'    => $ticketsAbiertos,
            'tickets_urgentes'    => $ticketsUrgentes,
            'accesos_hoy'         => $accesosHoy,
            'reservas_pendientes' => $reservasPendientes,
        ];

        // ── 3. Últimas 5 cuotas pendientes/vencidas con unidad y propietario ───
        $cuotasPendientes = Cuota::with(['unidad.propietarios' => fn ($q) => $q->select('propietarios.id', 'nombre', 'apellido')])
            ->where('tenant_id', $tenantId)
            ->whereIn('estado', ['pendiente', 'vencida'])
            ->orderByDesc('fecha_vencimiento')
            ->limit(5)
            ->get(['id', 'unidad_id', 'periodo', 'monto', 'mora', 'fecha_vencimiento'])
            ->map(fn ($c) => [
                'id'                => $c->id,
                'periodo'           => $c->periodo,
                'monto'             => $c->monto,
                'mora'              => $c->mora,
                'fecha_vencimiento' => $c->fecha_vencimiento,
                'unidad'            => $c->unidad?->numero,
                'propietario'       => $c->unidad?->propietarios?->first()
                    ? trim(($c->unidad->propietarios->first()->nombre ?? '') . ' ' . ($c->unidad->propietarios->first()->apellido ?? ''))
                    : null,
            ]);

        // ── 4. Últimos 4 tickets con categoría y unidad ───────────────────
        $ticketsRecientes = Ticket::with([
                'categoria:id,nombre,color',
                'unidad:id,numero',
            ])
            ->where('tenant_id', $tenantId)
            ->whereNotIn('estado', ['resuelto', 'cerrado'])
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'titulo', 'prioridad', 'estado', 'categoria_id', 'unidad_id', 'created_at']);

        // ── 5. Últimos 4 accesos de hoy con visitante y unidad ────────────
        $accesosRecientes = Acceso::with([
                'visitante:id,nombre,cedula,placa',
                'unidad:id,numero',
                'areaComun:id,nombre,catalogo_id',
                'areaComun.catalogo:id,icono,color_bg,color_text',
            ])
            ->where('tenant_id', $tenantId)
            ->whereDate('fecha_hora_entrada', $hoy)
            ->orderByDesc('fecha_hora_entrada')
            ->limit(4)
            ->get(['id', 'visitante_id', 'unidad_id', 'area_comun_id', 'tipo', 'fecha_hora_entrada'])
            ->map(fn ($a) => [
                'id'                 => $a->id,
                'visitante'          => $a->visitante,
                'unidad'             => $a->unidad,
                'area_comun'         => $a->areaComun ? [
                    'id'     => $a->areaComun->id,
                    'nombre' => $a->areaComun->nombre,
                    'icono'  => $a->areaComun->catalogo?->icono,
                ] : null,
                'tipo'               => $a->tipo,
                'fecha_hora_entrada' => $a->fecha_hora_entrada,
            ]);

        // ── 6. Actividad mensual — cacheada 5 min (UNION pagos+gastos en 1 query) ──────
        $actividad = Cache::remember("dashboard.actividad.{$tenantId}", 300,
            fn () => $this->actividadMensual($tenantId, 6)
        );

        // ── Respuesta consolidada ─────────────────────────────────────────
        return response()->json([
            'data' => [
                'tenant' => $tenantNombre,
                'stats'  => $stats,
                'cuotas_pendientes' => $cuotasPendientes,
                'tickets_recientes' => $ticketsRecientes,
                'accesos_recientes' => $accesosRecientes,
                'actividad'         => $actividad,
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Devuelve [tickets_abiertos, tickets_urgentes] en 2 queries limpias.
     */
    private function statsTickets(int $tenantId): array
    {
        $base = Ticket::where('tenant_id', $tenantId);

        $abiertos = (clone $base)
            ->whereIn('estado', ['abierto', 'en_proceso', 'en_espera'])
            ->count();

        $urgentes = (clone $base)
            ->where('prioridad', 'urgente')
            ->where('estado', '!=', 'resuelto')
            ->count();

        return [$abiertos, $urgentes];
    }

    /**
     * Últimos $meses con recaudado (pagos) y total_gastos.
     * Una única query UNION ALL para recuperar ambas series en un solo viaje a la BD.
     */
    private function actividadMensual(int $tenantId, int $meses): array
    {
        $periodos = collect(range($meses - 1, 0))
            ->map(fn ($i) => now()->subMonths($i)->format('Y-m'))
            ->all();

        // Primer día del mes más antiguo del rango
        $desde = $periodos[0] . '-01';

        // UNION ALL: pagos + gastos en un solo viaje a la BD
        $pagosQuery = DB::table('pagos')
            ->selectRaw("'pago' as tipo, DATE_FORMAT(fecha_pago, '%Y-%m') as mes, SUM(monto) as total")
            ->where('tenant_id', $tenantId)
            ->where('anulado', false)
            ->where('fecha_pago', '>=', $desde)
            ->groupByRaw("DATE_FORMAT(fecha_pago, '%Y-%m')");

        $rows = DB::table('gastos')
            ->selectRaw("'gasto' as tipo, DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(monto) as total")
            ->where('tenant_id', $tenantId)
            ->where('fecha', '>=', $desde)
            ->groupByRaw("DATE_FORMAT(fecha, '%Y-%m')")
            ->unionAll($pagosQuery)
            ->get();

        // Agrupa por mes para acceso O(1)
        $porMes = $rows->groupBy('mes');

        return array_map(fn ($periodo) => [
            'mes'       => substr($periodo, 5), // "02", "03", etc for chart label
            'recaudado' => round((float) ($porMes->get($periodo)?->firstWhere('tipo', 'pago')?->total  ?? 0), 2),
            'gastos'    => round((float) ($porMes->get($periodo)?->firstWhere('tipo', 'gasto')?->total ?? 0), 2),
        ], $periodos);
    }

    private function actualizarMoras(int $tenantId): void
    {
        $config = ConfigMora::where('tenant_id', $tenantId)
            ->where('activo', true)
            ->first();

        if (!$config) return;

        $limite = now()->startOfDay()->subDays($config->dias_gracia);

        $cuotas = Cuota::where('tenant_id', $tenantId)
            ->whereIn('estado', ['pendiente', 'vencida'])
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<', $limite->toDateString())
            ->get(['id', 'monto', 'mora']);

        foreach ($cuotas as $c) {
            $moraCalculada = $config->tipo_mora === 'porcentaje'
                ? round((float) $c->monto * (float) $config->valor_mora / 100, 2)
                : (float) $config->valor_mora;

            if ((float) $c->mora !== $moraCalculada) {
                $c->update(['mora' => $moraCalculada, 'estado' => 'vencida']);
            }
        }
    }
}
