<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Residente;
use App\Models\Role;
use App\Models\Unidad;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ResidenteController extends Controller
{
    // ── Genera código único p.ej. RES-A3KX7Q ────────────────────────────────
    private function generateCodigo(string $prefix, string $table): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = $prefix . '-' . substr(str_shuffle(str_repeat($chars, 4)), 0, 6);
        } while (DB::table($table)->where('codigo', $code)->exists());
        return $code;
    }

    // ── GET /api/v1/residentes ───────────────────────────────────────────────
    // Params: unidad_id, buscar (nombre/cédula), activo (default true), page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Residente::with([
                'unidad:id,numero,tipo',
                'unidad.torre:id,nombre',
            ])
            ->where('tenant_id', $tenantId);

        // Activos por campo booleano
        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        } else {
            $query->where('activo', true);
        }

        if ($request->filled('unidad_id')) {
            $query->where('unidad_id', $request->unidad_id);
        }

        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($b) {
                $q->where('nombre',   'like', $b)
                  ->orWhere('apellido','like', $b)
                  ->orWhere('cedula',  'like', $b)
                  ->orWhere('email',   'like', $b);
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

    // ── POST /api/v1/residentes ───────────────────────────────────────────────
    // Body: { unidad_id, nombre, apellido, cedula, email, telefono, tipo_residente }
    // tipo_residente: propietario|inquilino|familiar|otro → mapea a es_propietario (bool)
    // Validar: unidad pertenece al tenant
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'unidad_id'      => 'required|integer',
            'nombre'         => 'required|string|max:100',
            'apellido'       => 'nullable|string|max:100',
            'cedula'         => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:150',
            'telefono'       => 'nullable|string|max:30',
            'tipo'           => 'nullable|in:propietario,inquilino,familiar,otro',
            'es_contacto'    => 'nullable|boolean',
            'fecha_ingreso'  => 'nullable|date',
        ]);

        // Validar que la unidad pertenezca al tenant
        $unidad = Unidad::where('id', $validated['unidad_id'])
            ->where('tenant_id', $tenantId)
            ->first();
        if (!$unidad) {
            return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
        }

        // Unicidad de cédula, email y teléfono dentro del tenant
        $camposUnicos = [
            'cedula'   => 'La cédula',
            'email'    => 'El correo electrónico',
            'telefono' => 'El teléfono',
        ];
        foreach ($camposUnicos as $campo => $label) {
            if (!empty($validated[$campo])) {
                $existe = Residente::where('tenant_id', $tenantId)
                    ->where($campo, $validated[$campo])
                    ->where('activo', true)
                    ->exists();
                if ($existe) {
                    return response()->json([
                        'message' => 'Error de validación.',
                        'errors'  => [$campo => ["{$label} ya está registrado en este condominio."]],
                    ], 422);
                }
            }
        }

        $residente = Residente::create([
            'tenant_id'     => $tenantId,
            'unidad_id'     => $validated['unidad_id'],
            'codigo'        => $this->generateCodigo('RES', 'residentes'),
            'nombre'        => $validated['nombre'],
            'apellido'      => $validated['apellido'] ?? null,
            'cedula'        => $validated['cedula'] ?? null,
            'email'         => $validated['email'] ?? null,
            'telefono'      => $validated['telefono'] ?? null,
            'tipo'          => $validated['tipo'] ?? 'propietario',
            'es_contacto'   => $validated['es_contacto'] ?? false,
            'activo'        => true,
            'fecha_ingreso' => $validated['fecha_ingreso'] ?? null,
        ]);

        return response()->json([
            'data'    => $residente->load('unidad:id,numero,tipo'),
            'message' => 'Residente creado.',
        ], 201);
    }

    // ── GET /api/v1/residentes/:id ───────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $residente = Residente::with(['unidad:id,numero,tipo', 'unidad.torre:id,nombre'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $residente]);
    }

    // ── PUT /api/v1/residentes/:id ───────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $residente = Residente::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'unidad_id'     => 'sometimes|integer',
            'nombre'        => 'sometimes|string|max:100',
            'apellido'      => 'nullable|string|max:100',
            'cedula'        => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:150',
            'telefono'      => 'nullable|string|max:30',
            'tipo'          => 'nullable|in:propietario,inquilino,familiar,otro',
            'es_contacto'   => 'nullable|boolean',
            'fecha_ingreso' => 'nullable|date',
            'fecha_salida'  => 'nullable|date',
        ]);

        // Validar unidad si se cambia
        if (isset($validated['unidad_id'])) {
            $ok = Unidad::where('id', $validated['unidad_id'])
                ->where('tenant_id', $tenantId)->exists();
            if (!$ok) {
                return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
            }
        }

        $residente->update($validated);

        return response()->json([
            'data'    => $residente->fresh('unidad:id,numero,tipo'),
            'message' => 'Residente actualizado.',
        ]);
    }

    // ── DELETE /api/v1/residentes/:id (soft delete: activo = false) ────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $residente = Residente::where('tenant_id', $tenantId)->findOrFail($id);
        $residente->update(['activo' => false]);

        return response()->json(['message' => 'Residente dado de baja.']);
    }

    //  POST /api/v1/residentes/:id/cuenta 
    public function crearCuenta(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $residente = Residente::where('tenant_id', $tenantId)->findOrFail($id);

        if ($residente->usuario_id) {
            return response()->json(['message' => 'Este residente ya tiene cuenta de acceso.'], 422);
        }

        $validated = $request->validate([
            'email'    => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => 'required|string|min:8',
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email'    => 'El correo electrónico no es válido.',
            'email.unique'   => 'El correo electrónico ya está registrado.',
            'password.min'   => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $rol = Role::where('tenant_id', $tenantId)->where('nombre', 'residente')->first();
        if (!$rol) {
            return response()->json(['message' => 'Rol "residente" no encontrado para este condominio.'], 422);
        }

        $usuario = Usuario::create([
            'tenant_id'     => $tenantId,
            'codigo'        => $this->generateCodigo('USR', 'usuarios'),
            'nombre'        => $residente->nombre,
            'apellido'      => $residente->apellido ?? '',
            'email'         => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'rol_id'        => $rol->id,
            'activo'        => true,
        ]);

        $residente->update(['usuario_id' => $usuario->id]);

        return response()->json([
            'data'    => ['usuario_id' => $usuario->id, 'email' => $usuario->email],
            'message' => 'Cuenta de acceso creada y vinculada al residente.',
        ], 201);
    }
}