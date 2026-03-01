<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConceptoCobro;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConceptoCobroController extends Controller
{
    // ── GET /api/v1/conceptos-cobro ──────────────────────────────────────────────
    // Params: activo=true|false (default: todos)
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = ConceptoCobro::where('tenant_id', $tenantId);

        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        $conceptos = $query->orderBy('nombre')->get();

        return response()->json(['data' => $conceptos]);
    }

    // ── POST /api/v1/conceptos-cobro ─────────────────────────────────────────────
    // Body: { nombre, monto_base, periodicidad, descripcion?, aplica_coeficiente?, activo? }
    // Columnas reales: nombre, descripcion, monto_base, aplica_coeficiente, periodicidad, activo
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'nombre'              => [
                'required', 'string', 'max:100',
                Rule::unique('conceptos_cobro', 'nombre')
                    ->where('tenant_id', $tenantId),
            ],
            'monto_base'          => 'required|numeric|min:0',
            'descripcion'         => 'nullable|string',
            'aplica_coeficiente'  => 'nullable|boolean',
            'periodicidad'        => 'required|in:mensual,trimestral,anual,unico',
            'activo'              => 'nullable|boolean',
        ]);

        $concepto = ConceptoCobro::create([
            'tenant_id'           => $tenantId,
            'nombre'              => $validated['nombre'],
            'monto_base'          => $validated['monto_base'],
            'descripcion'         => $validated['descripcion'] ?? null,
            'aplica_coeficiente'  => $validated['aplica_coeficiente'] ?? false,
            'periodicidad'        => $validated['periodicidad'],
            'activo'              => $validated['activo'] ?? true,
        ]);

        return response()->json([
            'data'    => $concepto,
            'message' => 'Concepto de cobro creado.',
        ], 201);
    }

    // ── GET /api/v1/conceptos-cobro/:id ──────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $concepto = ConceptoCobro::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['data' => $concepto]);
    }

    // ── PUT /api/v1/conceptos-cobro/:id ──────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $concepto = ConceptoCobro::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'nombre'             => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('conceptos_cobro', 'nombre')
                    ->where('tenant_id', $tenantId)
                    ->ignore($concepto->id),
            ],
            'monto_base'         => 'sometimes|required|numeric|min:0',
            'descripcion'        => 'nullable|string',
            'aplica_coeficiente' => 'nullable|boolean',
            'periodicidad'       => 'sometimes|required|in:mensual,trimestral,anual,unico',
            'activo'             => 'nullable|boolean',
        ]);

        $concepto->update($validated);

        return response()->json([
            'data'    => $concepto->fresh(),
            'message' => 'Concepto de cobro actualizado.',
        ]);
    }

    // ── DELETE /api/v1/conceptos-cobro/:id (soft delete → activo = false) ────────
    // Error 422 si tiene cuotas pendientes o vencidas asociadas
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $concepto = ConceptoCobro::where('tenant_id', $tenantId)->findOrFail($id);

        $tienePendientes = $concepto->cuotas()
            ->whereIn('estado', ['pendiente', 'vencida'])
            ->exists();

        if ($tienePendientes) {
            return response()->json([
                'message' => 'No se puede desactivar: este concepto tiene cuotas pendientes o vencidas asociadas.',
            ], 422);
        }

        $concepto->update(['activo' => false]);

        return response()->json(['message' => 'Concepto de cobro desactivado.']);
    }
}
