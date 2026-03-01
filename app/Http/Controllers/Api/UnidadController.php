<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Propietario;
use App\Models\PropietarioUnidad;
use App\Models\Torre;
use App\Models\Unidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnidadController extends Controller
{
    // ── GET /api/v1/unidades ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $mes      = now()->format('Y-m');

        $query = Unidad::with([
                'torre:id,nombre',
                // Sólo propietarios activos en el pivot — cargamos todos y tomamos el primero en PHP
                'propietarios' => fn ($q) => $q
                    ->wherePivot('activo', true)
                    ->select('propietarios.id', 'nombre', 'apellido', 'cedula', 'telefono'),
                'residentes' => fn ($q) => $q
                    ->where('activo', true)
                    ->select('id', 'unidad_id', 'nombre', 'apellido', 'es_contacto'),
            ])
            ->where('unidades.tenant_id', $tenantId)
            // Estado de cuota del mes actual calculado en SQL (evita N+1)
            ->addSelect([
                'unidades.*',
                DB::raw("(
                    CASE
                        WHEN EXISTS(
                            SELECT 1 FROM cuotas
                            WHERE cuotas.unidad_id = unidades.id
                              AND cuotas.periodo   = '{$mes}'
                              AND cuotas.estado    = 'vencida'
                        ) THEN 'moroso'
                        WHEN EXISTS(
                            SELECT 1 FROM cuotas
                            WHERE cuotas.unidad_id = unidades.id
                              AND cuotas.periodo   = '{$mes}'
                              AND cuotas.estado    IN ('pagada', 'pendiente')
                        ) THEN 'al_dia'
                        ELSE 'sin_cuota'
                    END
                ) AS estado_cuota"),
            ]);

        // Filtro por torre_id
        if ($request->filled('torre_id')) {
            $query->where('unidades.torre_id', $request->torre_id);
        }

        // Filtro por nombre de torre
        if ($request->filled('torre')) {
            $query->whereHas('torre', fn ($q) =>
                $q->where('nombre', 'like', '%' . $request->torre . '%')
            );
        }

        // Filtro por tipo
        if ($request->filled('tipo')) {
            $query->where('unidades.tipo', $request->tipo);
        }

        // Filtro por activa (1 = activa, 0 = inactiva)
        if ($request->has('activa') && $request->activa !== '') {
            $query->where('unidades.activa', (bool) $request->activa);
        }

        // Filtro buscar: número de unidad o nombre/apellido del propietario activo
        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('unidades.numero', 'like', "%{$buscar}%")
                  ->orWhereHas('propietarios', fn ($pq) =>
                      $pq->wherePivot('activo', true)
                         ->where(fn ($ppq) =>
                             $ppq->where('propietarios.nombre',   'like', "%{$buscar}%")
                                 ->orWhere('propietarios.apellido', 'like', "%{$buscar}%")
                         )
                  );
            });
        }

        $perPage  = min((int) $request->get('per_page', 20), 200);
        $paginado = $query->orderBy('unidades.numero')->paginate($perPage);

        return response()->json([
            'data' => $paginado->through(fn ($u) => $this->formatListado($u))->items(),
            'meta' => [
                'total'        => $paginado->total(),
                'per_page'     => $paginado->perPage(),
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
            ],
        ]);
    }

    // ── GET /api/v1/unidades/:id ─────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $unidad = Unidad::with([
                'torre:id,nombre,pisos',
                'propietarios' => fn ($q) => $q
                    ->wherePivot('activo', true)
                    ->select('propietarios.id', 'nombre', 'apellido', 'cedula', 'email', 'telefono'),
                'residentes' => fn ($q) => $q
                    ->where('activo', true)
                    ->select('id', 'unidad_id', 'usuario_id', 'codigo', 'nombre', 'apellido', 'cedula', 'telefono', 'tipo', 'es_contacto'),
                'vehiculos:id,unidad_id,placa,marca,modelo,color',
                'cuotas' => fn ($q) => $q
                    ->orderByDesc('fecha_vencimiento')
                    ->limit(3)                      // OK: query de registro único
                    ->select('id', 'unidad_id', 'concepto_id', 'periodo', 'monto', 'mora', 'estado', 'fecha_vencimiento'),
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        // Concepto de las 3 cuotas en una sola query adicional
        $unidad->cuotas->load('concepto:id,nombre');

        return response()->json([
            'data' => [
                'id'          => $unidad->id,
                'numero'      => $unidad->numero,
                'piso'        => $unidad->piso,
                'tipo'        => $unidad->tipo,
                'metraje'     => $unidad->area_m2,
                'coeficiente' => $unidad->coeficiente ?? null,
                'activa'      => (bool) $unidad->activa,
                'estado'      => $unidad->estado,
                'torre'       => $unidad->torre,
                'propietario' => $unidad->propietarios->first(),
                'residentes'  => $unidad->residentes->values(),
                'vehiculos'   => $unidad->vehiculos->values(),
                'cuotas'      => $unidad->cuotas->values(),
            ],
        ]);
    }

    // ── POST /api/v1/unidades ────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'numero'      => 'required|string|max:20',
            'torre_id'    => 'nullable|integer',
            'tipo'        => 'nullable|in:apartamento,casa,local,oficina,parqueo,deposito',
            'metraje'     => 'nullable|numeric|min:0',
            'piso'        => 'nullable|integer',
            'coeficiente' => 'nullable|numeric|min:0|max:1',
            'estado'      => 'nullable|in:activa,inactiva,en_venta',
            'activa'      => 'nullable|boolean',
        ]);
        if (!empty($validated['torre_id'])) {
            $belongs = Torre::where('id', $validated['torre_id'])
                            ->where('tenant_id', $tenantId)
                            ->exists();
            if (!$belongs) {
                return response()->json(['message' => 'La torre indicada no pertenece a este PH.'], 422);
            }
        }

        // Renombrar metraje → area_m2 (nombre real de la columna)
        if (array_key_exists('metraje', $validated)) {
            $validated['area_m2'] = $validated['metraje'];
            unset($validated['metraje']);
        }

        // Número único dentro del tenant
        $existe = Unidad::where('tenant_id', $tenantId)
                        ->where('numero', $validated['numero'])
                        ->exists();
        if ($existe) {
            return response()->json([
                'message' => "Ya existe una unidad con el número \"{$validated['numero']}\" en este PH.",
            ], 422);
        }

        $unidad = Unidad::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'estado'    => $validated['estado'] ?? 'activa',
        ]));

        return response()->json([
            'data'    => $unidad->load('torre:id,nombre'),
            'message' => 'Unidad creada.',
        ], 201);
    }

    // ── PUT /api/v1/unidades/:id ─────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $unidad   = Unidad::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'numero'      => ['sometimes', 'string', 'max:20',
                              Rule::unique('unidades')
                                  ->where('tenant_id', $tenantId)
                                  ->ignore($unidad->id)],
            'torre_id'    => 'nullable|integer',
            'tipo'        => 'nullable|in:apartamento,casa,local,oficina,parqueo,deposito',
            'metraje'     => 'nullable|numeric|min:0',
            'piso'        => 'nullable|integer',
            'coeficiente' => 'nullable|numeric|min:0|max:1',
            'estado'      => 'nullable|in:activa,inactiva,en_venta',
            'activa'      => 'nullable|boolean',
        ]);

        // Torre debe pertenecer al tenant si se cambia
        if (!empty($validated['torre_id'])) {
            $belongs = Torre::where('id', $validated['torre_id'])
                            ->where('tenant_id', $tenantId)
                            ->exists();
            if (!$belongs) {
                return response()->json(['message' => 'La torre indicada no pertenece a este PH.'], 422);
            }
        }

        // Renombrar metraje → area_m2 (nombre real de la columna)
        if (array_key_exists('metraje', $validated)) {
            $validated['area_m2'] = $validated['metraje'];
            unset($validated['metraje']);
        }

        $unidad->update($validated);

        return response()->json([
            'data'    => $unidad->fresh('torre:id,nombre'),
            'message' => 'Unidad actualizada.',
        ]);
    }

    // ── DELETE /api/v1/unidades/:id  (soft-delete → estado = inactiva) ──────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $unidad   = Unidad::where('tenant_id', $tenantId)->findOrFail($id);

        // Bloquear si hay cuotas pendientes o vencidas
        $tienePendientes = $unidad->cuotas()
            ->whereIn('estado', ['pendiente', 'vencida'])
            ->exists();

        if ($tienePendientes) {
            return response()->json([
                'message' => 'No se puede desactivar: la unidad tiene cuotas pendientes o vencidas.',
            ], 422);
        }

        $unidad->update(['estado' => 'inactiva']);

        return response()->json(['message' => 'Unidad desactivada.']);
    }

    // ── GET /api/v1/torres ───────────────────────────────────────────────────────
    public function torres(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $torres = Torre::where('tenant_id', $tenantId)
            ->withCount('unidades')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'pisos']);

        return response()->json(['data' => $torres]);
    }

    // ── POST /api/v1/torres ──────────────────────────────────────────────────────
    public function storeTorre(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $request->validate([
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('torres')->where('tenant_id', $tenantId),
            ],
            'pisos' => 'nullable|integer|min:1',
        ]);

        $torre = Torre::create([
            'tenant_id' => $tenantId,
            'nombre'    => $request->nombre,
            'pisos'     => $request->pisos,
        ]);

        return response()->json(['data' => $torre, 'message' => 'Torre creada.'], 201);
    }

    // ── POST /api/v1/unidades/:id/propietario ────────────────────────────────────
    // Body: { propietario_id, fecha_inicio, fecha_fin? }
    public function asignarPropietario(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $unidad   = Unidad::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'propietario_id' => 'required|integer',
            'fecha_inicio'   => 'nullable|date',
            'fecha_fin'      => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        // Verificar que el propietario pertenece a este tenant
        $propietario = Propietario::where('id', $validated['propietario_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$propietario) {
            return response()->json(['message' => 'Propietario no encontrado en este PH.'], 422);
        }

        // Desactivar asignación activa previa en esta unidad
        PropietarioUnidad::where('unidad_id', $unidad->id)
            ->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now()->toDateString()]);

        $pivot = PropietarioUnidad::create([
            'tenant_id'      => $tenantId,
            'propietario_id' => $propietario->id,
            'unidad_id'      => $unidad->id,
            'activo'         => true,
            'fecha_inicio'   => $validated['fecha_inicio'] ?? now()->toDateString(),
            'fecha_fin'      => $validated['fecha_fin'] ?? null,
        ]);

        return response()->json([
            'data'    => array_merge($pivot->toArray(), [
                'propietario' => $propietario->only(['id', 'nombre', 'apellido', 'cedula', 'telefono']),
            ]),
            'message' => 'Propietario asignado a la unidad.',
        ]);
    }

    // ── DELETE /api/v1/unidades/:id/propietario ──────────────────────────────────
    // Desasigna el propietario activo de la unidad (cierra el pivot, NO elimina el propietario).
    public function desasignarPropietario(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $unidad   = Unidad::where('tenant_id', $tenantId)->findOrFail($id);

        $rows = PropietarioUnidad::where('unidad_id', $unidad->id)
            ->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now()->toDateString()]);

        if ($rows === 0) {
            return response()->json(['message' => 'Esta unidad no tiene propietario asignado.'], 422);
        }

        return response()->json(['message' => 'Propietario desasignado de la unidad.']);
    }

    // ── PUT /api/v1/torres/:id ────────────────────────────────────────────────────
    public function updateTorre(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $torre    = Torre::where('tenant_id', $tenantId)->findOrFail($id);

        $request->validate([
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('torres', 'nombre')
                    ->where('tenant_id', $tenantId)
                    ->ignore($torre->id),
            ],
            'pisos' => 'nullable|integer|min:1',
        ]);

        $torre->update([
            'nombre' => $request->nombre,
            'pisos'  => $request->pisos,
        ]);

        return response()->json(['data' => $torre->fresh(), 'message' => 'Torre actualizada.']);
    }

    // ── DELETE /api/v1/torres/:id ────────────────────────────────────────────────
    // Error 422 si la torre tiene unidades activas
    public function destroyTorre(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $torre    = Torre::where('tenant_id', $tenantId)->findOrFail($id);

        if ($torre->unidades()->where('activa', true)->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: la torre tiene unidades activas.',
            ], 422);
        }

        $torre->delete();

        return response()->json(['message' => 'Torre eliminada.']);
    }

    // ── Helper privado ───────────────────────────────────────────────────────────────

    private function formatListado(Unidad $u): array
    {
        $prop      = $u->propietarios->first();
        $residente = $u->residentes->firstWhere('es_contacto', true) ?? $u->residentes->first();

        return [
            'id'           => $u->id,
            'numero'       => $u->numero,
            'piso'         => $u->piso,
            'tipo'         => $u->tipo,
            'metraje'      => $u->area_m2,
            'activa'       => (bool) $u->activa,
            'estado'       => $u->estado,
            'estado_cuota' => $u->estado_cuota,   // inyectado vía addSelect SQL
            'torre'        => $u->torre ? ['id' => $u->torre->id, 'nombre' => $u->torre->nombre] : null,
            'propietario'  => $prop ? [
                'id'       => $prop->id,
                'nombre'   => trim("{$prop->nombre} {$prop->apellido}"),
                'cedula'   => $prop->cedula,
                'telefono' => $prop->telefono,
            ] : null,
            'residente'    => $residente ? [
                'id'     => $residente->id,
                'nombre' => trim("{$residente->nombre} {$residente->apellido}"),
            ] : null,
        ];
    }
}

