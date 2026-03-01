<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cuota;
use App\Models\Propietario;
use App\Models\PropietarioUnidad;
use App\Models\Unidad;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PropietarioController extends Controller
{
    // ── GET /api/v1/propietarios ─────────────────────────────────────────────────
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Propietario::with([
                'unidades' => fn ($q) => $q
                    ->wherePivot('activo', true)
                    ->select('unidades.id', 'numero', 'piso', 'tipo', 'torre_id')
                    ->with('torre:id,nombre'),
            ])
            ->where('tenant_id', $tenantId);

        if ($request->filled('buscar')) {
            $s = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($s) {
                $q->where('nombre',    'like', $s)
                  ->orWhere('apellido', 'like', $s)
                  ->orWhere('cedula',   'like', $s);
            });
        }

        $paginado = $query->orderBy('apellido')->orderBy('nombre')->paginate(20);

        return response()->json([
            'data' => $paginado->items(),
            'meta' => [
                'total'        => $paginado->total(),
                'per_page'     => $paginado->perPage(),
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
            ],
        ]);
    }

    // ── GET /api/v1/propietarios/:id ─────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId    = $request->get('tenant_id');
        $propietario = Propietario::with([
                // Historial completo — todos los registros del pivot, no solo activos
                'unidades' => fn ($q) => $q
                    ->select('unidades.id', 'numero', 'piso', 'tipo', 'torre_id')
                    ->with('torre:id,nombre'),
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        // IDs de unidades actualmente activas para este propietario
        $unidadIds = $propietario->unidades()
            ->wherePivot('activo', true)
            ->pluck('unidades.id');

        // Cuotas pendientes/vencidas de esas unidades
        $cuotasPendientes = Cuota::with('unidad:id,numero', 'concepto:id,nombre')
            ->whereIn('unidad_id', $unidadIds)
            ->whereIn('estado', ['pendiente', 'vencida'])
            ->orderBy('fecha_vencimiento')
            ->get(['id', 'unidad_id', 'concepto_id', 'periodo', 'monto', 'mora', 'estado', 'fecha_vencimiento']);

        // Vehículos de esas unidades
        $vehiculos = Vehiculo::whereIn('unidad_id', $unidadIds)
            ->get(['id', 'unidad_id', 'placa', 'marca', 'modelo', 'color']);

        return response()->json([
            'data' => array_merge($propietario->toArray(), [
                'cuotas_pendientes' => $cuotasPendientes,
                'vehiculos'         => $vehiculos,
            ]),
        ]);
    }

    // ── POST /api/v1/propietarios ────────────────────────────────────────────────
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'nombre'       => 'required|string|max:100',
            'apellido'     => 'nullable|string|max:100',
            'cedula'       => ['nullable', 'string', 'max:30',
                               Rule::unique('propietarios')->where('tenant_id', $tenantId)],
            'email'        => 'nullable|email|max:150',
            'telefono'     => 'nullable|string|max:30',
            'telefono2'    => 'nullable|string|max:30',
            'fecha_compra' => 'nullable|date',
            'notas'        => 'nullable|string',
            // Asignación opcional de unidad al crear
            'unidad_id'    => 'nullable|integer',
            'fecha_inicio' => 'nullable|date',
        ]);

        $propietario = Propietario::create(array_merge(
            collect($validated)->only(['nombre', 'apellido', 'cedula', 'email', 'telefono', 'telefono2', 'fecha_compra', 'notas'])->toArray(),
            ['tenant_id' => $tenantId]
        ));

        // Asignar unidad si se indicó y pertenece al tenant
        if (!empty($validated['unidad_id'])) {
            $unidad = Unidad::where('id', $validated['unidad_id'])
                            ->where('tenant_id', $tenantId)
                            ->first();

            if ($unidad) {
                // Desactivar asignación previa en esa unidad
                PropietarioUnidad::where('unidad_id', $unidad->id)
                    ->where('activo', true)
                    ->update(['activo' => false, 'fecha_fin' => now()->toDateString()]);

                PropietarioUnidad::create([
                    'tenant_id'      => $tenantId,
                    'propietario_id' => $propietario->id,
                    'unidad_id'      => $unidad->id,
                    'activo'         => true,
                    'fecha_inicio'   => $validated['fecha_inicio'] ?? now()->toDateString(),
                ]);
            }
        }

        return response()->json([
            'data'    => $propietario->load(['unidades' => fn ($q) => $q->wherePivot('activo', true)]),
            'message' => 'Propietario creado.',
        ], 201);
    }

    // ── PUT /api/v1/propietarios/:id ─────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId    = $request->get('tenant_id');
        $propietario = Propietario::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'nombre'       => 'sometimes|required|string|max:100',
            'apellido'     => 'nullable|string|max:100',
            'cedula'       => ['nullable', 'string', 'max:30',
                               Rule::unique('propietarios')->where('tenant_id', $tenantId)->ignore($propietario->id)],
            'email'        => 'nullable|email|max:150',
            'telefono'     => 'nullable|string|max:30',
            'telefono2'    => 'nullable|string|max:30',
            'fecha_compra' => 'nullable|date',
            'notas'        => 'nullable|string',
        ]);

        $propietario->update(
            collect($validated)->only(['nombre', 'apellido', 'cedula', 'email', 'telefono', 'telefono2', 'fecha_compra', 'notas'])->toArray()
        );

        return response()->json([
            'data'    => $propietario->fresh(['unidades' => fn ($q) => $q->wherePivot('activo', true)]),
            'message' => 'Propietario actualizado.',
        ]);
    }

    // ── DELETE /api/v1/propietarios/:id ─────────────────────────────────────────
    // Bloquea si tiene cuotas pendientes; desactiva todas las asignaciones activas.
    public function destroy(Request $request, string $id)
    {
        $tenantId    = $request->get('tenant_id');
        $propietario = Propietario::where('tenant_id', $tenantId)->findOrFail($id);

        $unidadIds = $propietario->unidades()
            ->wherePivot('activo', true)
            ->pluck('unidades.id');

        if ($unidadIds->isNotEmpty()) {
            $tienePendientes = Cuota::whereIn('unidad_id', $unidadIds)
                ->whereIn('estado', ['pendiente', 'vencida'])
                ->exists();

            if ($tienePendientes) {
                return response()->json([
                    'message' => 'No se puede eliminar: el propietario tiene cuotas pendientes o vencidas.',
                ], 422);
            }
        }

        // Cerrar todas las asignaciones activas
        PropietarioUnidad::where('propietario_id', $propietario->id)
            ->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now()->toDateString()]);

        $propietario->delete();

        return response()->json(['message' => 'Propietario eliminado.']);
    }

    // ── POST /api/v1/propietarios/:id/asignar-unidad ─────────────────────────────
    public function asignarUnidad(Request $request, string $id)
    {
        $tenantId    = $request->get('tenant_id');
        $propietario = Propietario::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'unidad_id'    => 'required|integer',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $unidad = Unidad::where('id', $validated['unidad_id'])
                        ->where('tenant_id', $tenantId)
                        ->first();

        if (!$unidad) {
            return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
        }

        // Desactivar asignación activa previa en esa unidad (cualquier propietario)
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
            'data'    => $pivot,
            'message' => 'Unidad asignada al propietario.',
        ]);
    }
}
