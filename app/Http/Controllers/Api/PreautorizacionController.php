<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Preautorizacion;
use Illuminate\Http\Request;

class PreautorizacionController extends Controller
{
    /** GET /preautorizaciones */
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Preautorizacion::with('unidad')
            ->where('tenant_id', $tenantId);

        if ($request->filled('unidad_id')) {
            $query->where('unidad_id', $request->unidad_id);
        }
        if ($request->filled('activa')) {
            $query->where('activa', $request->boolean('activa'));
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('nombre_visitante', 'like', $s)
                  ->orWhere('cedula_visitante', 'like', $s)
                  ->orWhere('placa_visitante', 'like', $s);
            });
        }

        $preauths = $query->orderByDesc('created_at')
                          ->paginate($request->get('per_page', 50));

        // Include qr_token in each item
        $preauths->getCollection()->transform(function ($p) {
            return array_merge($p->toArray(), ['qr_token' => $p->qr_token]);
        });

        return response()->json(['data' => $preauths]);
    }

    /** POST /preautorizaciones */
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'unidad_id'         => 'required|exists:unidades,id',
            'residente_id'      => 'nullable|exists:residentes,id',
            'nombre_visitante'  => 'required|string|max:150',
            'cedula_visitante'  => 'required|string|max:30',
            'placa_visitante'   => 'nullable|string|max:20',
            'fecha_desde'       => 'nullable|date',
            'fecha_hasta'       => 'nullable|date|after_or_equal:fecha_desde',
            'descripcion'       => 'nullable|string|max:200',
            'qr_token'          => 'nullable|string|max:100',
            'activa'            => 'nullable|boolean',
        ]);

        $preauth = Preautorizacion::create(array_merge($validated, ['tenant_id' => $tenantId]));

        return response()->json([
            'data'    => $preauth->load('unidad'),
            'message' => 'Preautorización creada.',
        ], 201);
    }

    /** GET /preautorizaciones/{id} */
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $preauth = Preautorizacion::with('unidad')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $preauth]);
    }

    /** PUT /preautorizaciones/{id} */
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $preauth = Preautorizacion::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'unidad_id'        => 'sometimes|exists:unidades,id',
            'residente_id'     => 'sometimes|exists:residentes,id',
            'nombre_visitante' => 'sometimes|string|max:150',
            'cedula_visitante' => 'nullable|string|max:30',
            'placa_visitante'  => 'nullable|string|max:20',
            'fecha_desde'      => 'nullable|date',
            'fecha_hasta'      => 'nullable|date',
            'descripcion'      => 'nullable|string|max:200',
            'activa'           => 'nullable|boolean',
        ]);

        $preauth->update($validated);

        return response()->json(['data' => $preauth->fresh('unidad'), 'message' => 'Preautorización actualizada.']);
    }

    /** DELETE /preautorizaciones/{id} */
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $preauth = Preautorizacion::where('tenant_id', $tenantId)->findOrFail($id);
        $preauth->delete();

        return response()->json(['message' => 'Preautorización eliminada.']);
    }
}
