<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Preautorizacion;
use App\Models\Visitante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccesoController extends Controller
{
    // ── GET /api/v1/accesos ──────────────────────────────────────────────────────
    // Params: fecha (YYYY-MM-DD, default hoy), tipo_visita, unidad_id, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $fecha    = $request->get('fecha', now()->toDateString());

        $query = Acceso::with([
                'visitante:id,nombre,cedula,placa',
                'unidad:id,numero',
                'autorizadoPor:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId)
            ->whereDate('fecha_hora_entrada', $fecha);

        // tipo_visita → columna tipo (entrada | salida)
        if ($request->filled('tipo_visita')) {
            $query->where('tipo', $request->tipo_visita);
        }

        if ($request->filled('unidad_id')) {
            $query->where('unidad_id', $request->unidad_id);
        }

        // buscar por nombre, cédula o placa del visitante
        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->whereHas('visitante', fn ($q) =>
                $q->where('nombre', 'like', $b)
                  ->orWhere('cedula', 'like', $b)
                  ->orWhere('placa',  'like', $b)
            );
        }

        $paginado = $query->orderByDesc('fecha_hora_entrada')->paginate(20);

        $items = $paginado->getCollection()->map(fn (Acceso $a) => [
            'id'                  => $a->id,
            'visitante'           => $a->visitante,
            'unidad'              => $a->unidad,
            'tipo'                => $a->tipo,
            'fecha_hora_entrada'  => $a->fecha_hora_entrada,
            'fecha_hora_salida'   => $a->fecha_hora_salida,
            'observaciones'       => $a->observaciones,
            'metodo_entrada'      => $a->metodo_entrada,
            'cedula'              => $a->cedula,
            'empresa'             => $a->empresa,
            'qr_token'            => $a->qr_token,
            'registrado_por'      => $a->autorizadoPor
                ? trim("{$a->autorizadoPor->nombre} {$a->autorizadoPor->apellido}")
                : null,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'fecha'        => $fecha,
                'total'        => $paginado->total(),
                'per_page'     => $paginado->perPage(),
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/accesos ──────────────────────────────────────────────────────
    // Body: { nombre, cedula?, tipo, unidad_id?, motivo?, metodo_entrada?, qr_token? }
    // tipo enum real: visitante|delivery|proveedor|empleado|otro
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'nombre'         => 'required|string|max:150',
            'cedula'         => 'nullable|string|max:30',
            'empresa'        => 'nullable|string|max:120',
            'placa'          => 'nullable|string|max:20',
            'tipo'           => 'required|in:visitante,delivery,proveedor,otro',
            'unidad_id'      => 'nullable|integer',
            'motivo'         => 'nullable|string|max:150',
            'metodo_entrada' => 'nullable|string|max:30',
            'qr_token'       => 'nullable|string|max:100',
        ]);

        if (!empty($validated['unidad_id'])) {
            $unidadOk = DB::table('unidades')
                ->where('id', $validated['unidad_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (!$unidadOk) {
                return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
            }
        }

        // Busca visitante existente por cédula o crea uno nuevo
        $visitante = null;
        if (!empty($validated['cedula'])) {
            $visitante = Visitante::where('tenant_id', $tenantId)
                ->where('cedula', $validated['cedula'])
                ->first();
        }

        if (!$visitante) {
            $visitante = Visitante::create([
                'tenant_id' => $tenantId,
                'nombre'    => $validated['nombre'],
                'cedula'    => $validated['cedula'] ?? null,
                'placa'     => $validated['placa']  ?? null,
            ]);
        } else {
            if (!empty($validated['placa'])) {
                $visitante->update(['placa' => $validated['placa']]);
            }
        }

        $acceso = Acceso::create([
            'tenant_id'          => $tenantId,
            'unidad_id'          => $validated['unidad_id'] ?? null,
            'visitante_id'       => $visitante->id,
            'autorizado_por'     => $request->user()->id,
            'tipo'               => $validated['tipo'],
            'motivo'             => $validated['motivo'] ?? null,
            'cedula'             => $validated['cedula'] ?? null,
            'empresa'            => $validated['empresa'] ?? null,
            'metodo_entrada'     => $validated['metodo_entrada'] ?? 'Manual',
            'qr_token'           => $validated['qr_token'] ?? null,
            'fecha_hora_entrada' => now(),
        ]);

        return response()->json([
            'data'    => $acceso->load([
                'visitante:id,nombre,cedula,placa',
                'unidad:id,numero',
                'autorizadoPor:id,nombre,apellido',
            ]),
            'message' => 'Acceso registrado.',
        ], 201);
    }

    // ── POST /api/v1/accesos/verificar-pin ────────────────────────────────────
    public function verificarPin(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $pin      = $request->input('pin');

        $stored = DB::table('configuracion')
            ->where('tenant_id', $tenantId)
            ->where('clave', 'pin_acceso')
            ->value('valor');

        if (!$stored) {
            return response()->json(['valid' => true]); // if no PIN configured, allow
        }

        return response()->json(['valid' => $pin === $stored]);
    }

    // ── GET /api/v1/accesos/:id ───────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $acceso = Acceso::with([
                'visitante:id,nombre,cedula,placa',
                'unidad:id,numero',
                'autorizadoPor:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $acceso]);
    }

    // ── PUT /api/v1/accesos/:id ───────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $acceso   = Acceso::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'unidad_id'    => 'nullable|integer',
            'observaciones'=> 'nullable|string',
        ]);

        $acceso->update($validated);

        return response()->json(['data' => $acceso->fresh(['visitante:id,nombre,cedula', 'unidad:id,numero']), 'message' => 'Acceso actualizado.']);
    }

    // ── DELETE /api/v1/accesos/:id ───────────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $acceso   = Acceso::where('tenant_id', $tenantId)->findOrFail($id);
        $acceso->delete();

        return response()->json(['message' => 'Acceso eliminado.']);
    }

    // ── POST /api/v1/accesos/:id/salida ─────────────────────────────────────────
    public function registrarSalida(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $acceso   = Acceso::where('tenant_id', $tenantId)->findOrFail($id);

        if ($acceso->fecha_hora_salida) {
            return response()->json([
                'message' => 'La salida ya fue registrada el ' . $acceso->fecha_hora_salida->format('d/m/Y H:i') . '.',
            ], 422);
        }

        $acceso->update(['fecha_hora_salida' => now()]);

        return response()->json([
            'data'    => $acceso->fresh(['visitante:id,nombre,cedula,placa', 'unidad:id,numero']),
            'message' => 'Salida registrada.',
        ]);
    }

    // ── GET /api/v1/accesos/buscar-preauth ───────────────────────────────────────
    // Params: cedula, placa (al menos uno)
    // Busca preautorizaciones vigentes (fecha_desde <= hoy <= fecha_hasta)
    public function buscarPreauth(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $hoy      = now()->toDateString();

        $request->validate([
            'cedula' => 'nullable|string',
            'placa'  => 'nullable|string',
        ]);

        if (!$request->filled('cedula') && !$request->filled('placa')) {
            return response()->json(['message' => 'Debe proporcionar cédula o placa.'], 422);
        }

        // Columnas reales en preautorizaciones: cedula_visitante, placa_visitante
        $query = Preautorizacion::with('unidad:id,numero,torre_id', 'unidad.torre:id,nombre')
            ->where('tenant_id', $tenantId)
            ->where('activa', true)
            ->where(fn ($q) => $q->whereNull('fecha_desde')->orWhere('fecha_desde', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('fecha_hasta')->orWhere('fecha_hasta', '>=', $hoy))
            ->where(function ($q) use ($request) {
                if ($request->filled('cedula')) {
                    $q->where('cedula_visitante', $request->cedula);
                }
                if ($request->filled('placa')) {
                    $q->orWhere('placa_visitante', $request->placa);
                }
            });

        $preauths = $query->get();

        return response()->json([
            'data'       => $preauths,
            'encontrado' => $preauths->isNotEmpty(),
        ]);
    }

    // ── GET /api/v1/accesos/analitica?fecha=YYYY-MM-DD ───────────────────────────────
    // Gráfico de barras por hora (0-23) + top 10 unidades con más visitas
    // params: fecha (default: hoy)
    public function analitica(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $fecha    = $request->get('fecha', today()->toDateString());

        // Una sola query: accesos por hora
        $porHoraRaw = \Illuminate\Support\Facades\DB::table('accesos')
            ->where('tenant_id', $tenantId)
            ->whereDate('fecha_hora_entrada', $fecha)
            ->selectRaw('HOUR(fecha_hora_entrada) as hora, COUNT(*) as total')
            ->groupByRaw('HOUR(fecha_hora_entrada)')
            ->orderBy('hora')
            ->get()
            ->keyBy('hora');

        // Rellena horas sin accesos con 0
        $porHora = collect(range(0, 23))->map(fn ($h) => [
            'hora'  => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00',
            'total' => (int) ($porHoraRaw->get($h)?->total ?? 0),
        ]);

        // Top 10 unidades del día
        $topUnidades = \Illuminate\Support\Facades\DB::table('accesos')
            ->join('unidades', 'unidades.id', '=', 'accesos.unidad_id')
            ->where('accesos.tenant_id', $tenantId)
            ->whereDate('accesos.fecha_hora_entrada', $fecha)
            ->selectRaw('unidades.id, unidades.numero, COUNT(*) as total')
            ->groupBy('unidades.id', 'unidades.numero')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // Activos ahora (sin salida, hoy)
        $activosAhora = \Illuminate\Support\Facades\DB::table('accesos')
            ->where('tenant_id', $tenantId)
            ->whereDate('fecha_hora_entrada', $fecha)
            ->whereNull('fecha_hora_salida')
            ->count();

        // Esta semana
        $semanaTotal = \Illuminate\Support\Facades\DB::table('accesos')
            ->where('tenant_id', $tenantId)
            ->whereBetween('fecha_hora_entrada', [
                now()->startOfWeek()->toDateTimeString(),
                now()->endOfWeek()->toDateTimeString(),
            ])
            ->count();

        // Este mes
        $mesTotal = \Illuminate\Support\Facades\DB::table('accesos')
            ->where('tenant_id', $tenantId)
            ->whereYear('fecha_hora_entrada', now()->year)
            ->whereMonth('fecha_hora_entrada', now()->month)
            ->count();

        return response()->json([
            'data' => [
                'fecha'         => $fecha,
                'total_dia'     => $porHoraRaw->sum('total'),
                'activos_ahora' => $activosAhora,
                'semana_total'  => $semanaTotal,
                'mes_total'     => $mesTotal,
                'por_hora'      => $porHora,
                'top_unidades'  => $topUnidades,
            ],
        ]);
    }
}