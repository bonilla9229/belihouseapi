<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Estacionamiento;
use Illuminate\Http\Request;

class EstacionamientoController extends Controller
{
    // ── GET /api/v1/estacionamientos ─────────────────────────────────────────
    // Params: piso, tipo_vehiculo, buscar, activo, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Estacionamiento::with('unidad:id,numero')
            ->where('tenant_id', $tenantId);

        if ($request->filled('piso')) {
            $query->where('piso', $request->piso);
        }
        if ($request->filled('tipo_vehiculo')) {
            $query->where('tipo_vehiculo', $request->tipo_vehiculo);
        }
        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($b) {
                $q->where('numero',             'like', $b)
                  ->orWhere('placa',            'like', $b)
                  ->orWhere('propietario_nombre','like', $b);
            });
        }
        if ($request->has('activo')) {
            $query->where('activo', (bool) $request->activo);
        }

        $items = $query->orderBy('piso')->orderBy('numero')->paginate(100);

        return response()->json([
            'data' => $items->getCollection()->map(fn (Estacionamiento $e) => $this->format($e)),
            'meta' => [
                'total'        => $items->total(),
                'per_page'     => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/estacionamientos ────────────────────────────────────────
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'numero'             => 'required|string|max:20',
            'piso'               => 'nullable|string|max:15',
            'tipo_vehiculo'      => 'nullable|in:carro,moto,otro',
            'placa'              => 'nullable|string|max:20',
            'propietario_nombre' => 'nullable|string|max:120',
            'propietario_tel'    => 'nullable|string|max:30',
            'unidad_id'          => 'nullable|integer|exists:unidades,id',
            'mensualidad'        => 'nullable|numeric|min:0',
            'observaciones'      => 'nullable|string',
        ]);

        $validated['tenant_id'] = $tenantId;
        $validated['activo']    = true;

        $est = Estacionamiento::create($validated);

        return response()->json(['data' => $this->format($est->load('unidad:id,numero'))], 201);
    }

    // ── GET /api/v1/estacionamientos/{id} ────────────────────────────────────
    public function show(Request $request, int $id)
    {
        $tenantId = $request->get('tenant_id');
        $est = Estacionamiento::with('unidad:id,numero')
            ->where('tenant_id', $tenantId)->findOrFail($id);
        return response()->json(['data' => $this->format($est)]);
    }

    // ── PUT /api/v1/estacionamientos/{id} ────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $tenantId = $request->get('tenant_id');
        $est = Estacionamiento::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'numero'             => 'sometimes|string|max:20',
            'piso'               => 'nullable|string|max:15',
            'tipo_vehiculo'      => 'nullable|in:carro,moto,otro',
            'placa'              => 'nullable|string|max:20',
            'propietario_nombre' => 'nullable|string|max:120',
            'propietario_tel'    => 'nullable|string|max:30',
            'unidad_id'          => 'nullable|integer|exists:unidades,id',
            'mensualidad'        => 'nullable|numeric|min:0',
            'activo'             => 'nullable|boolean',
            'observaciones'      => 'nullable|string',
        ]);

        $est->update($validated);

        return response()->json(['data' => $this->format($est->fresh()->load('unidad:id,numero'))]);
    }

    // ── DELETE /api/v1/estacionamientos/{id} ─────────────────────────────────
    public function destroy(Request $request, int $id)
    {
        $tenantId = $request->get('tenant_id');
        $est = Estacionamiento::where('tenant_id', $tenantId)->findOrFail($id);
        $est->delete();
        return response()->json(['message' => 'Eliminado'], 200);
    }

    // ── GET /api/v1/estacionamientos/pisos ───────────────────────────────────
    // Lista de pisos únicos para filtros/selects
    public function pisos(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $pisos = Estacionamiento::where('tenant_id', $tenantId)
            ->whereNotNull('piso')
            ->distinct()
            ->orderBy('piso')
            ->pluck('piso');
        return response()->json(['data' => $pisos]);
    }

    // ── Formato de salida ────────────────────────────────────────────────────
    private function format(Estacionamiento $e): array
    {
        return [
            'id'                 => $e->id,
            'numero'             => $e->numero,
            'piso'               => $e->piso,
            'tipo_vehiculo'      => $e->tipo_vehiculo,
            'placa'              => $e->placa,
            'propietario_nombre' => $e->propietario_nombre,
            'propietario_tel'    => $e->propietario_tel,
            'unidad_id'          => $e->unidad_id,
            'unidad_numero'      => $e->unidad?->numero,
            'mensualidad'        => $e->mensualidad,
            'activo'             => $e->activo,
            'observaciones'      => $e->observaciones,
            'created_at'         => $e->created_at,
        ];
    }
}
