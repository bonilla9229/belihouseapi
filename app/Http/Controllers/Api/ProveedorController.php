<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    // ── GET /api/v1/proveedores ────────────────────────────────────────────────
    // Params: buscar (nombre o ruc), activo (boolean, default true)
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Proveedor::where('tenant_id', $tenantId);

        // Por defecto solo activos, a menos que se pase activo=false explícitamente
        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        } else {
            $query->where('activo', true);
        }

        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($b) {
                $q->where('nombre',   'like', $b)
                  ->orWhere('ruc',    'like', $b)
                  ->orWhere('contacto','like', $b);
            });
        }

        $paginado = $query->orderBy('nombre')->paginate(20);

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

    // ── POST /api/v1/proveedores ─────────────────────────────────────────────────
    // nombre requerido; ruc único por tenant (si se envía)
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'nombre'   => 'required|string|max:150',
            'categoria' => 'nullable|string|max:80',
            'ruc'      => 'nullable|string|max:30',
            'telefono' => 'nullable|string|max:30',
            'email'    => 'nullable|email|max:150',
            'contacto' => 'nullable|string|max:100',
        ]);

        // RUC único por tenant
        if (!empty($validated['ruc'])) {
            $existe = Proveedor::where('tenant_id', $tenantId)
                ->where('ruc', $validated['ruc'])
                ->exists();
            if ($existe) {
                return response()->json(['message' => 'Ya existe un proveedor con ese RUC en este PH.'], 422);
            }
        }

        $proveedor = Proveedor::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'activo'    => true,
        ]));

        return response()->json(['data' => $proveedor, 'message' => 'Proveedor creado.'], 201);
    }

    // ── GET /api/v1/proveedores/:id ────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $proveedor = Proveedor::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['data' => $proveedor]);
    }

    // ── PUT /api/v1/proveedores/:id ───────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $proveedor = Proveedor::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'nombre'   => 'sometimes|string|max:150',
            'categoria' => 'nullable|string|max:80',
            'ruc'      => 'nullable|string|max:30',
            'telefono' => 'nullable|string|max:30',
            'email'    => 'nullable|email|max:150',
            'contacto' => 'nullable|string|max:100',
            'activo'   => 'nullable|boolean',
        ]);

        // RUC único por tenant si cambió
        if (!empty($validated['ruc']) && $validated['ruc'] !== $proveedor->ruc) {
            $existe = Proveedor::where('tenant_id', $tenantId)
                ->where('ruc', $validated['ruc'])
                ->where('id', '!=', $proveedor->id)
                ->exists();
            if ($existe) {
                return response()->json(['message' => 'Ya existe un proveedor con ese RUC en este PH.'], 422);
            }
        }

        $proveedor->update($validated);

        return response()->json(['data' => $proveedor->fresh(), 'message' => 'Proveedor actualizado.']);
    }

    // ── DELETE /api/v1/proveedores/:id (soft delete: activo = false) ─────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $proveedor = Proveedor::where('tenant_id', $tenantId)->findOrFail($id);

        // Verificar que no tenga gastos asociados antes de desactivar
        $tieneGastos = $proveedor->gastos()->exists();

        $proveedor->update(['activo' => false]);

        return response()->json([
            'message'      => 'Proveedor desactivado.',
            'tiene_gastos' => $tieneGastos,
        ]);
    }
}
