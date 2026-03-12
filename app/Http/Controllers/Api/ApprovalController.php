<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CredencialesAprobado;
use App\Mail\SolicitudRechazada;
use App\Models\Residente;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApprovalController extends Controller
{
    private function adminGuard(Request $request): Usuario
    {
        $admin = $request->user();
        abort_if(!$admin || $admin->rol?->nombre !== 'admin', 403, 'Solo administradores.');
        return $admin;
    }

    // GET /api/v1/aprobaciones
    public function index(Request $request)
    {
        $admin = $this->adminGuard($request);

        $pendientes = Usuario::with('rol')
            ->where('tenant_id', $admin->tenant_id)
            ->where('status', 'pendiente')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($u) => $this->format($u));

        $recientes = Usuario::with('rol')
            ->where('tenant_id', $admin->tenant_id)
            ->whereIn('status', ['aprobado', 'rechazado'])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn($u) => $this->format($u));

        return response()->json([
            'data' => ['pendientes' => $pendientes, 'recientes' => $recientes],
        ]);
    }

    // POST /api/v1/aprobaciones/{id}/aprobar
    public function approve(Request $request, int $id)
    {
        $admin   = $this->adminGuard($request);
        $usuario = Usuario::with('rol')
            ->where('tenant_id', $admin->tenant_id)
            ->where('status', 'pendiente')
            ->findOrFail($id);

        $rolNombre = $usuario->rol?->nombre;

        $validated = $request->validate([
            'unidad_id' => $rolNombre === 'residente'
                ? 'required|integer|exists:unidades,id'
                : 'nullable|integer',
        ], [
            'unidad_id.required' => 'Debes asignar una unidad al residente.',
        ]);

        $plainPassword = Str::password(12);

        $usuario->update([
            'status'        => 'aprobado',
            'activo'        => true,
            'password_hash' => bcrypt($plainPassword),
        ]);

        // Crear registro de residente vinculado a la unidad asignada
        if ($rolNombre === 'residente' && !empty($validated['unidad_id'])) {
            Residente::create([
                'tenant_id'     => $admin->tenant_id,
                'unidad_id'     => $validated['unidad_id'],
                'usuario_id'    => $usuario->id,
                'nombre'        => $usuario->nombre,
                'apellido'      => $usuario->apellido ?? null,
                'email'         => $usuario->email,
                'tipo'          => 'inquilino',
                'es_contacto'   => true,
                'activo'        => true,
                'fecha_ingreso' => now()->toDateString(),
            ]);
        }

        $mailSent  = false;
        $mailError = null;

        if (config('mail.default') !== 'log') {
            try {
                Mail::to($usuario->email)->send(new CredencialesAprobado($usuario, $plainPassword));
                $mailSent = true;
            } catch (\Exception $e) {
                $mailError = $e->getMessage();
            }
        }

        return response()->json([
            'message'       => $mailSent
                ? 'Usuario aprobado. Se envio el correo con las credenciales.'
                : 'Usuario aprobado. Correo no enviado — comparte la contrasena manualmente.',
            'temp_password' => $plainPassword,
            'mail_sent'     => $mailSent,
            'data'          => $this->format($usuario->fresh()),
        ]);
    }

    // POST /api/v1/aprobaciones/{id}/rechazar
    public function reject(Request $request, int $id)
    {
        $admin   = $this->adminGuard($request);
        $usuario = Usuario::where('tenant_id', $admin->tenant_id)
            ->where('status', 'pendiente')
            ->findOrFail($id);

        $motivo = $request->input('motivo', '');

        $usuario->update(['status' => 'rechazado', 'activo' => false]);

        try {
            Mail::to($usuario->email)->send(new SolicitudRechazada($usuario, $motivo));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Rechazado pero el email fallo: ' . $e->getMessage(),
                'data'    => $this->format($usuario->fresh()),
            ]);
        }

        return response()->json([
            'message' => 'Solicitud rechazada.',
            'data'    => $this->format($usuario->fresh()),
        ]);
    }

    private function format(Usuario $u): array
    {
        return [
            'id'         => $u->id,
            'nombre'     => $u->nombre,
            'apellido'   => $u->apellido,
            'email'      => $u->email,
            'avatar_url' => $u->avatar_url,
            'rol'        => $u->rol?->nombre,
            'status'     => $u->status,
            'created_at' => $u->created_at,
        ];
    }
}
