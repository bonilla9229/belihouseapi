<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    // ── Genera código único p.ej. USR-B7MX4K ────────────────────────────────
    private function generateCodigo(string $prefix, string $table): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = $prefix . '-' . substr(str_shuffle(str_repeat($chars, 4)), 0, 6);
        } while (DB::table($table)->where('codigo', $code)->exists());
        return $code;
    }

    // ── GET /api/v1/usuarios ─────────────────────────────────────────────────────
    // Params: activo=true|false (default: todos activos), buscar
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Usuario::where('tenant_id', $tenantId)
            ->with('rol:id,nombre')
            ->select('id', 'nombre', 'apellido', 'email', 'telefono',
                     'avatar_url', 'activo', 'ultimo_login', 'rol_id');

        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        } else {
            $query->where('activo', true);
        }

        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($b) {
                $q->where('nombre',   'like', $b)
                  ->orWhere('apellido','like', $b)
                  ->orWhere('email',   'like', $b);
            });
        }

        $usuarios = $query->orderBy('nombre')->get()->map(fn ($u) => [
            'id'              => $u->id,
            'nombre'          => $u->nombre,
            'apellido'        => $u->apellido,
            'nombre_completo' => trim("{$u->nombre} {$u->apellido}"),
            'email'           => $u->email,
            'telefono'        => $u->telefono,
            'avatar_url'      => $u->avatar_url,
            'activo'          => (bool) $u->activo,
            'ultimo_login'    => $u->ultimo_login,
            'rol'             => $u->rol,
        ]);

        return response()->json(['data' => $usuarios]);
    }

    // ── GET /api/v1/usuarios/:id ─────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $usuario = Usuario::where('tenant_id', $tenantId)
            ->with('rol:id,nombre')
            ->findOrFail($id);

        return response()->json(['data' => $usuario]);
    }

    // ── POST /api/v1/usuarios ────────────────────────────────────────────────────
    // Body: { nombre, apellido, email, password, rol_id, telefono? }
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'nombre'   => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'email'    => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => 'required|string|min:8',
            'rol_id'   => 'required|exists:roles,id',
            'telefono' => 'nullable|string|max:20',
        ], [
            'nombre.required'   => 'El nombre es obligatorio.',
            'email.required'    => 'El correo electrónico es obligatorio.',
            'email.email'       => 'El correo electrónico no es válido.',
            'email.unique'      => 'El correo electrónico ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
            'rol_id.required'   => 'Debes seleccionar un rol.',
            'rol_id.exists'     => 'El rol seleccionado no es válido.',
        ]);

        $usuario = Usuario::create([
            'tenant_id'     => $tenantId,
            'codigo'        => $this->generateCodigo('USR', 'usuarios'),
            'nombre'        => $validated['nombre'],
            'apellido'      => $validated['apellido'],
            'email'         => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'rol_id'        => $validated['rol_id'],
            'telefono'      => $validated['telefono'] ?? null,
            'activo'        => true,
        ]);

        return response()->json([
            'data'    => $usuario->load('rol:id,nombre'),
            'message' => 'Usuario creado.',
        ], 201);
    }

    // ── PUT /api/v1/usuarios/:id ─────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $usuario = Usuario::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'nombre'   => 'sometimes|required|string|max:100',
            'apellido' => 'sometimes|required|string|max:100',
            'email'    => [
                'sometimes', 'required', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->ignore($usuario->id),
            ],
            'password' => 'nullable|string|min:8',
            'rol_id'   => 'sometimes|required|exists:roles,id',
            'telefono' => 'nullable|string|max:20',
            'activo'   => 'nullable|boolean',
        ]);

        $payload = collect($validated)->except('password')->toArray();

        if (!empty($validated['password'])) {
            $payload['password_hash'] = Hash::make($validated['password']);
        }

        $usuario->update($payload);

        return response()->json([
            'data'    => $usuario->fresh('rol:id,nombre'),
            'message' => 'Usuario actualizado.',
        ]);
    }

    // ── DELETE /api/v1/usuarios/:id (soft delete → activo = false) ───────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $authUser  = $request->user();

        if ((int) $authUser->id === (int) $id) {
            return response()->json(['message' => 'No puedes desactivar tu propio usuario.'], 422);
        }

        $usuario = Usuario::where('tenant_id', $tenantId)->findOrFail($id);
        $usuario->update(['activo' => false]);

        return response()->json(['message' => 'Usuario desactivado.']);
    }

    //  PATCH /api/v1/usuarios/:id/rol 
    public function cambiarRol(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $usuario  = Usuario::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'rol_id' => 'required|exists:roles,id',
        ]);

        $usuario->update(['rol_id' => $validated['rol_id']]);

        return response()->json([
            'data'    => $usuario->fresh('rol:id,nombre'),
            'message' => 'Rol actualizado.',
        ]);
    }
}