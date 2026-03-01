<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Local;
use Illuminate\Http\Request;

class LocalController extends Controller
{
    // ── GET /api/v1/locales ──────────────────────────────────────────────────
    // Params: tipo (oficina|local), buscar, activo, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Local::where('tenant_id', $tenantId);

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($b) {
                $q->where('numero',         'like', $b)
                  ->orWhere('nombre_empresa','like', $b)
                  ->orWhere('ruc',          'like', $b)
                  ->orWhere('propietario_nombre', 'like', $b);
            });
        }
        if ($request->has('activo')) {
            $query->where('activo', (bool) $request->activo);
        }

        $items = $query->orderBy('tipo')->orderBy('numero')->paginate(50);

        return response()->json([
            'data' => $items->getCollection()->map(fn (Local $l) => $this->format($l)),
            'meta' => [
                'total'        => $items->total(),
                'per_page'     => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/locales ─────────────────────────────────────────────────
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'tipo'               => 'required|in:oficina,local',
            'numero'             => 'required|string|max:30',
            'piso'               => 'nullable|string|max:15',
            'metraje'            => 'nullable|numeric|min:0',
            'coeficiente'        => 'nullable|numeric|min:0',
            'nombre_empresa'     => 'nullable|string|max:120',
            'ruc'                => 'nullable|string|max:30',
            'actividad'          => 'nullable|string|max:120',
            'propietario_nombre' => 'nullable|string|max:120',
            'propietario_ruc'    => 'nullable|string|max:30',
            'propietario_tel'    => 'nullable|string|max:30',
            'propietario_email'  => 'nullable|email|max:100',
            'mensualidad'        => 'nullable|numeric|min:0',
            'observaciones'      => 'nullable|string',
        ]);

        $validated['tenant_id'] = $tenantId;
        $validated['activo']    = true;
        // coeficiente llega en porcentaje desde front (ej: 1.5) → guardamos decimal
        if (isset($validated['coeficiente'])) {
            $validated['coeficiente'] = $validated['coeficiente'] / 100;
        }

        $local = Local::create($validated);

        return response()->json(['data' => $this->format($local)], 201);
    }

    // ── GET /api/v1/locales/{id} ─────────────────────────────────────────────
    public function show(Request $request, int $id)
    {
        $tenantId = $request->get('tenant_id');
        $local = Local::where('tenant_id', $tenantId)->findOrFail($id);
        return response()->json(['data' => $this->format($local)]);
    }

    // ── PUT /api/v1/locales/{id} ─────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $tenantId = $request->get('tenant_id');
        $local = Local::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'tipo'               => 'sometimes|in:oficina,local',
            'numero'             => 'sometimes|string|max:30',
            'piso'               => 'nullable|string|max:15',
            'metraje'            => 'nullable|numeric|min:0',
            'coeficiente'        => 'nullable|numeric|min:0',
            'nombre_empresa'     => 'nullable|string|max:120',
            'ruc'                => 'nullable|string|max:30',
            'actividad'          => 'nullable|string|max:120',
            'propietario_nombre' => 'nullable|string|max:120',
            'propietario_ruc'    => 'nullable|string|max:30',
            'propietario_tel'    => 'nullable|string|max:30',
            'propietario_email'  => 'nullable|email|max:100',
            'mensualidad'        => 'nullable|numeric|min:0',
            'activo'             => 'nullable|boolean',
            'observaciones'      => 'nullable|string',
        ]);

        if (isset($validated['coeficiente'])) {
            $validated['coeficiente'] = $validated['coeficiente'] / 100;
        }

        $local->update($validated);

        return response()->json(['data' => $this->format($local->fresh())]);
    }

    // ── DELETE /api/v1/locales/{id} ──────────────────────────────────────────
    public function destroy(Request $request, int $id)
    {
        $tenantId = $request->get('tenant_id');
        $local = Local::where('tenant_id', $tenantId)->findOrFail($id);
        $local->delete();
        return response()->json(['message' => 'Eliminado'], 200);
    }

    // ── Formato de salida ────────────────────────────────────────────────────
    private function format(Local $l): array
    {
        return [
            'id'                 => $l->id,
            'tipo'               => $l->tipo,
            'numero'             => $l->numero,
            'piso'               => $l->piso,
            'metraje'            => $l->metraje,
            'coeficiente'        => $l->coeficiente ? round($l->coeficiente * 100, 4) : null,
            'nombre_empresa'     => $l->nombre_empresa,
            'ruc'                => $l->ruc,
            'actividad'          => $l->actividad,
            'propietario_nombre' => $l->propietario_nombre,
            'propietario_ruc'    => $l->propietario_ruc,
            'propietario_tel'    => $l->propietario_tel,
            'propietario_email'  => $l->propietario_email,
            'mensualidad'        => $l->mensualidad,
            'activo'             => $l->activo,
            'observaciones'      => $l->observaciones,
            'created_at'         => $l->created_at,
        ];
    }
}
