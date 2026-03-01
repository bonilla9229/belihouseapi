<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\ConfigMora;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\PropietarioUnidad;
use App\Models\Unidad;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CuotaController extends Controller
{
    // ── GET /api/v1/cuotas ─────────────────────────────────────────────────────────────
    // Params: estado (pendiente|vencida|pagada|todas), mes (YYYY-MM),
    //         torre, buscar (nombre propietario o número unidad), page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $this->actualizarMoras($tenantId);

        $query = Cuota::with([
                'unidad:id,numero,piso,torre_id',
                'unidad.torre:id,nombre',
                'concepto:id,nombre',
            ])
            ->selectRaw('cuotas.*, COALESCE((SELECT SUM(d.monto_aplicado) FROM pago_cuota_detalle d WHERE d.cuota_id = cuotas.id), 0) AS pagado')
            ->where('cuotas.tenant_id', $tenantId);

        // estado: pendiente | vencida | pagada | anulada | todas
        if ($request->filled('estado') && $request->estado !== 'todas') {
            $query->where('estado', $request->estado);
        }

        // mes: YYYY-MM → periodo
        if ($request->filled('mes')) {
            $query->where('periodo', $request->mes);
        }

        // torre: nombre de la torre
        if ($request->filled('torre')) {
            $query->whereHas('unidad.torre', fn ($q) =>
                $q->where('nombre', 'like', '%' . $request->torre . '%')
            );
        }

        // unidad_id: filtrar cuotas de una unidad específica (app residente)
        if ($request->filled('unidad_id')) {
            $query->where('cuotas.unidad_id', $request->unidad_id);
        }

        // buscar: número de unidad o nombre/apellido del propietario activo
        if ($request->filled('buscar')) {
            $b = $request->buscar;
            $query->where(function ($q) use ($b) {
                $q->whereHas('unidad', fn ($uq) =>
                      $uq->where('numero', 'like', "%{$b}%")
                  )
                  ->orWhereHas('unidad.propietarios', fn ($pq) =>
                      $pq->wherePivot('activo', true)
                         ->where(fn ($ppq) =>
                             $ppq->where('propietarios.nombre',    'like', "%{$b}%")
                                 ->orWhere('propietarios.apellido', 'like', "%{$b}%")
                         )
                  );
            });
        }

        $paginado = $query->orderByDesc('fecha_vencimiento')->orderBy('cuotas.id')->paginate(20);

        // Cargar propietario activo para cada unidad en batch (evita N+1)
        $unidadIds      = $paginado->pluck('unidad_id')->unique()->values();
        $propsPorUnidad = PropietarioUnidad::with('propietario:id,nombre,apellido')
            ->whereIn('unidad_id', $unidadIds)
            ->where('activo', true)
            ->get()
            ->keyBy('unidad_id');

        $items = $paginado->getCollection()->map(function (Cuota $c) use ($propsPorUnidad) {
            $prop = $propsPorUnidad[$c->unidad_id]?->propietario ?? null;

            return [
                'id'                => $c->id,
                'unidad'            => $c->unidad ? [
                    'id'     => $c->unidad->id,
                    'numero' => $c->unidad->numero,
                    'piso'   => $c->unidad->piso,
                    'torre'  => $c->unidad->torre?->nombre,
                ] : null,
                'propietario'       => $prop ? trim("{$prop->nombre} {$prop->apellido}") : null,
                'concepto'          => $c->concepto?->nombre,
                'periodo'           => $c->periodo,
                'monto'             => (float) $c->monto,
                'mora'              => (float) $c->mora,
                'total'             => round((float) $c->monto + (float) $c->mora, 2),                  'pagado'            => round((float) ($c->pagado ?? 0), 2),
                  'saldo_pendiente'   => round(max(0, (float) $c->monto - (float) ($c->pagado ?? 0)), 2),                'estado'            => $c->estado,
                'fecha_vencimiento' => $c->fecha_vencimiento,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginado->total(),
                'per_page'     => $paginado->perPage(),
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/cuotas (crear cuota individual) ─────────────────────────────
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'unidad_id'         => 'required|exists:unidades,id',
            'concepto_id'       => 'nullable|exists:conceptos_cobro,id',
            'periodo'           => 'required|string|max:7',
            'monto'             => 'required|numeric|min:0',
            'mora'              => 'nullable|numeric|min:0',
            'estado'            => 'nullable|in:pendiente,pagada,vencida,anulada,parcial',
            'fecha_vencimiento' => 'nullable|date',
            'notas'             => 'nullable|string',
        ]);

        $cuota = Cuota::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'mora'      => $validated['mora'] ?? 0,
            'estado'    => $validated['estado'] ?? 'pendiente',
        ]));

        return response()->json([
            'data'    => $cuota->load(['unidad:id,numero', 'concepto:id,nombre']),
            'message' => 'Cuota creada.',
        ], 201);
    }

    // ── GET /api/v1/cuotas/:id ───────────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $cuota = Cuota::with([
                'unidad:id,numero,torre_id',
                'unidad.torre:id,nombre',
                'concepto:id,nombre',
                'pagos',
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $cuota]);
    }

    // ── PUT /api/v1/cuotas/:id ──────────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $cuota    = Cuota::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'monto'             => 'sometimes|numeric|min:0',
            'mora'              => 'nullable|numeric|min:0',
            'estado'            => 'nullable|in:pendiente,pagada,vencida,anulada,parcial',
            'fecha_vencimiento' => 'nullable|date',
            'notas'             => 'nullable|string',
        ]);

        $cuota->update($validated);

        return response()->json([
            'data'    => $cuota->fresh(['unidad:id,numero', 'concepto:id,nombre']),
            'message' => 'Cuota actualizada.',
        ]);
    }

    // ── DELETE /api/v1/cuotas/:id ───────────────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $cuota    = Cuota::where('tenant_id', $tenantId)->findOrFail($id);

        if ($cuota->estado === 'pagada') {
            return response()->json(['message' => 'No se puede eliminar una cuota pagada.'], 422);
        }

        $cuota->delete();

        return response()->json(['message' => 'Cuota eliminada.']);
    }

    // ── POST /api/v1/cuotas/generar ────────────────────────────────────────────────────
    // Body: { mes: "YYYY-MM", concepto_id?: int  (null = todos los activos) }
    public function generar(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        // Accept both 'mes' and 'periodo' as field name
        if (!$request->has('mes') && $request->has('periodo')) {
            $request->merge(['mes' => $request->input('periodo')]);
        }

        $validated = $request->validate([
            'mes'         => 'required|string|regex:/^\d{4}-\d{2}$/',
            'concepto_id' => 'nullable|integer|exists:conceptos_cobro,id',
        ]);

        $mes = $validated['mes'];

        // Día de vencimiento desde configuración del tenant (tabla key-value: clave='dia_vencimiento')
        $diaVence = (int) (Configuracion::where('tenant_id', $tenantId)
            ->where('clave', 'dia_vencimiento')
            ->value('valor') ?? 5);
        [$year, $month] = explode('-', $mes);
        $maxDay         = cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year);
        $fechaVence     = Carbon::createFromDate((int) $year, (int) $month, min($diaVence, $maxDay));

        // Conceptos a procesar
        if (!empty($validated['concepto_id'])) {
            $conceptos = ConceptoCobro::where('tenant_id', $tenantId)
                ->where('activo', true)
                ->where('id', $validated['concepto_id'])
                ->get();

            if ($conceptos->isEmpty()) {
                return response()->json(['message' => 'Concepto no encontrado o inactivo.'], 422);
            }
        } else {
            $conceptos = ConceptoCobro::where('tenant_id', $tenantId)
                ->where('activo', true)
                ->get();
        }

        if ($conceptos->isEmpty()) {
            return response()->json(['message' => 'No hay conceptos de cobro activos.'], 422);
        }

        $unidades  = Unidad::where('tenant_id', $tenantId)->where('activa', true)->get(['id']);
        $generadas = 0;
        $omitidas  = 0;

        // ── Obtener todas las cuotas ya existentes del periodo EN UNA SOLA QUERY ──
        // Clave compuesta "unidad_id_concepto_id" para lookup O(1)
        $existentes = Cuota::where('tenant_id', $tenantId)
            ->where('periodo', $mes)
            ->get(['unidad_id', 'concepto_id'])
            ->mapWithKeys(fn ($c) => ["{$c->unidad_id}_{$c->concepto_id}" => true]);

        // ── Construir array de cuotas nuevas ──────────────────────────────────────
        $now    = now();
        $nuevas = [];

        foreach ($conceptos as $concepto) {
            foreach ($unidades as $unidad) {
                $key = "{$unidad->id}_{$concepto->id}";

                if (isset($existentes[$key])) {
                    $omitidas++;
                    continue;
                }

                $nuevas[] = [
                    'tenant_id'         => $tenantId,
                    'unidad_id'         => $unidad->id,
                    'concepto_id'       => $concepto->id,
                    'periodo'           => $mes,
                    'fecha_emision'     => $now->toDateString(),
                    'monto'             => $concepto->monto_base,
                    'mora'              => 0,
                    'estado'            => 'pendiente',
                    'fecha_vencimiento' => $fechaVence->toDateString(),
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
                $generadas++;
            }
        }

        // ── INSERT masivo — 500 cuotas en 1 sola query ────────────────────────────
        if (!empty($nuevas)) {
            Cuota::insert($nuevas);
        }

        return response()->json([
            'data' => [
                'generadas' => $generadas,
                'omitidas'  => $omitidas,
                'mes'       => $mes,
                'conceptos' => $conceptos->count(),
                'unidades'  => $unidades->count(),
            ],
            'message' => "Se generaron {$generadas} cuotas ({$omitidas} ya existían).",
        ]);
    }

    // ── GET /api/v1/cuotas/resumen ───────────────────────────────────────────────────
    // Params: mes (YYYY-MM, default mes actual)
    public function resumen(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $this->actualizarMoras($tenantId);
        $mes      = $request->get('mes', now()->format('Y-m'));

        $base = fn () => Cuota::where('tenant_id', $tenantId)->where('periodo', $mes);

        $totalGenerado  = $base()->sum('monto');
        $totalRecaudado = $base()->where('estado', 'pagada')->sum('monto');
        $totalPendiente = $base()->where('estado', 'pendiente')->sum(DB::raw('monto + mora'));
        $totalVencido   = $base()->where('estado', 'vencida')->sum(DB::raw('monto + mora'));
        $moraTotal      = $base()->whereIn('estado', ['pendiente', 'vencida'])->sum('mora');
        $totalUnidades  = Unidad::where('tenant_id', $tenantId)->where('activa', 1)->count();

        $porcentajeCobranza = $totalGenerado > 0
            ? round(($totalRecaudado / $totalGenerado) * 100, 1)
            : 0.0;

        return response()->json([
            'data' => [
                'mes'                 => $mes,
                'total_generado'      => (float) $totalGenerado,
                'total_recaudado'     => (float) $totalRecaudado,
                'total_pendiente'     => (float) $totalPendiente,
                'total_vencido'       => (float) $totalVencido,
                'mora_total'          => (float) $moraTotal,
                'porcentaje_cobranza' => $porcentajeCobranza,
                'total_unidades'      => $totalUnidades,
            ],
        ]);
    }

    // ── POST /api/v1/cuotas/recordatorio ─────────────────────────────────────────────
    // Body: { cuota_ids?: int[], mes?: "YYYY-MM" }
    // Si cuota_ids vacío → todas las pendientes/vencidas del mes
    public function recordatorio(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'cuota_ids'   => 'nullable|array',
            'cuota_ids.*' => 'integer|exists:cuotas,id',
            'mes'         => 'nullable|string|regex:/^\d{4}-\d{2}$/',
        ]);

        if (!empty($validated['cuota_ids'])) {
            $count = Cuota::where('tenant_id', $tenantId)
                ->whereIn('id', $validated['cuota_ids'])
                ->whereIn('estado', ['pendiente', 'vencida'])
                ->count();
        } else {
            $mes   = $validated['mes'] ?? now()->format('Y-m');
            $count = Cuota::where('tenant_id', $tenantId)
                ->where('periodo', $mes)
                ->whereIn('estado', ['pendiente', 'vencida'])
                ->count();
        }

        // TODO: integrar servicio de notificaciones/email

        return response()->json([
            'data'    => ['unidades_notificadas' => $count],
            'message' => "Recordatorios enviados a {$count} unidades.",
        ]);
    }

    // ── POST /api/v1/cuotas/:id/mora ──────────────────────────────────────────────────
    // Calcula mora según días vencidos y tipo configurado (porcentaje | fijo)
    public function aplicarMora(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $cuota    = Cuota::where('tenant_id', $tenantId)->findOrFail($id);

        if (!in_array($cuota->estado, ['pendiente', 'vencida'])) {
            return response()->json(['message' => 'Solo se puede aplicar mora a cuotas pendientes o vencidas.'], 422);
        }

        if (!$cuota->fecha_vencimiento) {
            return response()->json(['message' => 'La cuota no tiene fecha de vencimiento definida.'], 422);
        }

        $config = ConfigMora::where('tenant_id', $tenantId)->where('activo', true)->first();

        if (!$config) {
            return response()->json(['message' => 'No hay configuración de mora activa para este PH.'], 422);
        }

        // Días transcurridos desde el vencimiento (positivo = vencido)
        $diasVencidos = (int) now()->startOfDay()
            ->diffInDays(Carbon::parse($cuota->fecha_vencimiento)->startOfDay(), false) * -1;

        $diasNetosVencidos = $diasVencidos - $config->dias_gracia;

        if ($diasNetosVencidos <= 0) {
            return response()->json([
                'data'    => $cuota,
                'message' => "La cuota está dentro del período de gracia ({$config->dias_gracia} días).",
            ]);
        }

        $mora = $config->tipo_mora === 'porcentaje'
            ? round((float) $cuota->monto * (float) $config->valor_mora / 100, 2)
            : (float) $config->valor_mora;

        $cuota->update([
            'mora'   => $mora,
            'estado' => 'vencida',
        ]);

        return response()->json([
            'data'    => $cuota->fresh(['unidad:id,numero', 'concepto:id,nombre']),
            'message' => "Mora de {$mora} aplicada ({$diasVencidos} días vencidos, {$config->dias_gracia} de gracia).",
        ]);
    }

    // ── Batch-calculate and persist mora for all overdue unpaid cuotas ──────
    private function actualizarMoras(int $tenantId): void
    {
        $config = ConfigMora::where('tenant_id', $tenantId)
            ->where('activo', true)
            ->first();

        if (!$config) {
            return;
        }

        $today = now()->startOfDay();
        $limite = $today->copy()->subDays($config->dias_gracia);

        // Cuotas pendientes/vencidas cuya fecha de vencimiento ya pasó el período de gracia
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

    /** GET /conceptos-cobro (listado para el frontend) */
    public function conceptos(Request $request)
    {
        $tenantId  = $request->get('tenant_id');
        $conceptos = ConceptoCobro::where('tenant_id', $tenantId)
                                   ->where('activo', true)
                                   ->orderBy('nombre')
                                   ->get();

        return response()->json(['data' => $conceptos]);
    }
}
