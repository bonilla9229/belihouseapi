<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\Tenant;
use App\Models\Role;
use App\Models\Notificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    private function generateCodigo(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'USR-' . substr(str_shuffle(str_repeat($chars, 4)), 0, 6);
        } while (DB::table('usuarios')->where('codigo', $code)->exists());
        return $code;
    }

    // LOGIN
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password_hash)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        if (!$usuario->activo) {
            return response()->json(['message' => 'Usuario inactivo'], 403);
        }

        // Actualizar ultimo login
        $usuario->update(['ultimo_login' => now()]);

        $token = $usuario->createToken('aleph-token')->plainTextToken;

        return response()->json([
            'data' => [
                'token'   => $token,
                'usuario' => [
                    'id'        => $usuario->id,
                    'nombre'    => $usuario->nombre,
                    'apellido'  => $usuario->apellido,
                    'email'     => $usuario->email,
                    'rol'       => $usuario->rol?->nombre,
                    'tenant_id' => $usuario->tenant_id,
                    'avatar'    => $usuario->avatar_url,
                ],
            ],
            'message' => 'Login exitoso',
        ]);
    }

    // REGISTRO NUEVO PH (onboarding)
    public function register(Request $request)
    {
        $request->validate([
            'ph_nombre'    => 'required|string|max:150',
            'ph_slug'      => 'required|string|unique:tenants,slug',
            'admin_nombre'   => 'required|string',
            'admin_apellido' => 'required|string',
            'admin_email'    => 'required|email|unique:usuarios,email',
            'admin_password' => 'required|min:8',
        ]);

        // Crear tenant
        $tenant = Tenant::create([
            'nombre' => $request->ph_nombre,
            'slug'   => $request->ph_slug,
            'plan'   => 'basico',
            'activo' => 1,
        ]);

        // Crear rol admin para este tenant
        $rol = Role::create([
            'tenant_id' => $tenant->id,
            'nombre'    => 'admin',
            'permisos'  => json_encode(['all' => true]),
        ]);

        // Crear usuario administrador
        $usuario = Usuario::create([
            'tenant_id'     => $tenant->id,
            'rol_id'        => $rol->id,
            'nombre'        => $request->admin_nombre,
            'apellido'      => $request->admin_apellido,
            'email'         => $request->admin_email,
            'password_hash' => Hash::make($request->admin_password),
            'activo'        => 1,
        ]);

        $token = $usuario->createToken('aleph-token')->plainTextToken;

        return response()->json([
            'data' => [
                'token'   => $token,
                'usuario' => [
                    'id'        => $usuario->id,
                    'nombre'    => $usuario->nombre,
                    'apellido'  => $usuario->apellido,
                    'email'     => $usuario->email,
                    'rol'       => 'admin',
                    'tenant_id' => $tenant->id,
                ],
                'tenant' => [
                    'id'     => $tenant->id,
                    'nombre' => $tenant->nombre,
                    'slug'   => $tenant->slug,
                ],
            ],
            'message' => 'PH registrado exitosamente',
        ], 201);
    }


    // SOLICITUD DE ACCESO por correo (no requiere Google)
    // POST /api/v1/solicitud-acceso (ruta publica)
    public function solicitudAcceso(Request $request)
    {
        $validated = $request->validate([
            'nombre'    => 'required|string|max:100',
            'apellido'  => 'nullable|string|max:100',
            'email'     => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'rol'       => 'required|in:residente,guardia',
            'tenant_id' => 'required|integer|exists:tenants,id',
        ], [
            'email.unique' => 'Ya existe una cuenta con este correo electr�nico.',
        ]);

        $rol = Role::where('tenant_id', $validated['tenant_id'])
            ->where('nombre', $validated['rol'])
            ->first();

        if (!$rol) {
            return response()->json(['message' => 'El rol solicitado no existe en esta comunidad. Contacta al administrador.'], 422);
        }

        Usuario::create([
            'tenant_id'     => $validated['tenant_id'],
            'rol_id'        => $rol->id,
            'codigo'        => $this->generateCodigo(),
            'nombre'        => $validated['nombre'],
            'apellido'      => $validated['apellido'] ?? null,
            'email'         => $validated['email'],
            'password_hash' => '',
            'status'        => 'pendiente',
            'activo'        => false,
        ]);

        return response()->json([
            'message' => 'Solicitud enviada. El administrador la revisar� y te notificar� por correo.',
        ], 201);
    }
    // LOGOUT
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }

    // MI PERFIL
    public function me(Request $request)
    {
        $user = $request->user()->load('rol', 'tenant');

        // Buscar residente vinculado a este usuario
        $residente = \App\Models\Residente::with(['unidad:id,numero,piso,torre_id', 'unidad.torre:id,nombre'])
            ->where('usuario_id', $user->id)
            ->whereNull('fecha_salida')
            ->first();

        return response()->json([
            'data' => [
                'id'        => $user->id,
                'nombre'    => $user->nombre,
                'apellido'  => $user->apellido,
                'email'     => $user->email,
                'telefono'  => $user->telefono,
                'avatar'    => $user->avatar_url,
                'rol'       => $user->rol?->nombre,
                'permisos'  => $user->rol?->permisos,
                'tenant_id' => $user->tenant_id,
                'tenant'    => $user->tenant?->nombre,
                'residente' => $residente ? [
                    'id'        => $residente->id,
                    'tipo'      => $residente->tipo,
                    'unidad_id' => $residente->unidad_id,
                    'unidad'    => $residente->unidad?->numero,
                    'piso'      => $residente->unidad?->piso,
                    'torre'     => $residente->unidad?->torre?->nombre,
                ] : null,
            ]
        ]);
    }

    // CAMBIAR CONTRASEÑA
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password_hash)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 422);
        }

        $user->update(['password_hash' => \Illuminate\Support\Facades\Hash::make($request->new_password)]);

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    // NOTIFICACIONES
    public function notificaciones(Request $request)
    {
        $notifs = Notificacion::where('usuario_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json(['data' => $notifs]);
    }

    // MARCAR TODAS LEÍDAS
    public function leerTodas(Request $request)
    {
        Notificacion::where('usuario_id', $request->user()->id)
            ->where('leida', 0)
            ->update(['leida' => 1]);

        return response()->json(['message' => 'Notificaciones marcadas como leídas']);
    }
}