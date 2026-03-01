<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unidad;
use App\Models\Vehiculo;
use Illuminate\Http\Request;

class VehiculoController extends Controller
{
    // ── GET /api/v1/unidades/:unidad/vehiculos ────────────────────────────────
    // Lista todos los vehículos registrados en una unidad del tenant.
    public function getVehiculos(Request $request, string $unidad)
    {
        $tenantId = $request->get('tenant_id');

        // Validar que la unidad pertenezca al tenant
        $unidadModel = Unidad::where('id', $unidad)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $vehiculos = Vehiculo::where('unidad_id', $unidadModel->id)
            ->orderBy('placa')
            ->get();

        return response()->json(['data' => $vehiculos]);
    }

    // ── POST /api/v1/unidades/:unidad/vehiculos ───────────────────────────────
    // Registra un vehículo en una unidad.
    // Regla: placa única dentro del tenant (join unidades).
    // Nota: 'tipo' se acepta pero no se persiste (no existe la columna).
    public function storeVehiculo(Request $request, string $unidad)
    {
        $tenantId = $request->get('tenant_id');

        // Validar que la unidad pertenezca al tenant
        $unidadModel = Unidad::where('id', $unidad)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'placa'  => 'required|string|max:20',
            'marca'  => 'nullable|string|max:80',
            'modelo' => 'nullable|string|max:80',
            'color'  => 'nullable|string|max:50',
            'tipo'   => 'nullable|string', // aceptado, no persistido
        ]);

        // Placa única por tenant (vehiculos no tiene tenant_id → JOIN unidades)
        $placaExiste = Vehiculo::join('unidades', 'unidades.id', '=', 'vehiculos.unidad_id')
            ->where('unidades.tenant_id', $tenantId)
            ->where('vehiculos.placa', strtoupper(trim($validated['placa'])))
            ->exists();

        if ($placaExiste) {
            return response()->json([
                'message' => 'Ya existe un vehículo con esa placa en el PH.',
            ], 422);
        }

        $vehiculo = Vehiculo::create([
            'unidad_id' => $unidadModel->id,
            'placa'     => strtoupper(trim($validated['placa'])),
            'marca'     => $validated['marca']  ?? null,
            'modelo'    => $validated['modelo'] ?? null,
            'color'     => $validated['color']  ?? null,
        ]);

        return response()->json([
            'data'    => $vehiculo->load('unidad:id,numero,tipo'),
            'message' => 'Vehículo registrado.',
        ], 201);
    }

    // ── DELETE /api/v1/vehiculos/:vehiculo ────────────────────────────────────
    // Elimina un vehículo (hard delete; no hay columna activo).
    // Valida que el vehículo pertenezca al tenant vía unidad.
    public function destroyVehiculo(Request $request, string $vehiculo)
    {
        $tenantId = $request->get('tenant_id');

        $vehiculoModel = Vehiculo::join('unidades', 'unidades.id', '=', 'vehiculos.unidad_id')
            ->where('unidades.tenant_id', $tenantId)
            ->where('vehiculos.id', $vehiculo)
            ->select('vehiculos.*')
            ->firstOrFail();

        $vehiculoModel->delete();

        return response()->json(['message' => 'Vehículo eliminado.']);
    }
}
