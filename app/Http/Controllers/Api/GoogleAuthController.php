<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NuevaSolicitudAdmin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /** The React app base URL (set FRONTEND_URL in .env) */
    private function frontendUrl(string $path = '', array $query = []): string
    {
        $base = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $url  = $base . ($path ? '/' . ltrim($path, '/') : '');
        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    /** Generate a unique user code like USR-B7MX4K */
    private function generateCodigo(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'USR-' . substr(str_shuffle(str_repeat($chars, 4)), 0, 6);
        } while (DB::table('usuarios')->where('codigo', $code)->exists());
        return $code;
    }

    // ── GET /api/v1/tenants-publicos ───────────────────────────────────
    // Public endpoint: list active PHs for the register dropdown
    public function tenants()
    {
        $tenants = Tenant::where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo_ph']);

        return response()->json(['data' => $tenants]);
    }

    // ── POST /api/v1/auth/google/pre-register ──────────────────────────
    // React sends { rol, tenant_id } before redirecting to Google
    public function preRegister(Request $request)
    {
        $request->validate([
            'rol'       => 'required|in:residente,seguridad',
            'tenant_id' => 'required|exists:tenants,id',
        ]);

        session([
            'oauth_rol'       => $request->rol,
            'oauth_tenant_id' => (int) $request->tenant_id,
            'oauth_mode'      => 'register',
        ]);

        // Return the URL that React should redirect the browser to
        $googleUrl = Socialite::driver('google')
            ->stateless(false)   // stateless=false so we can use session
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $googleUrl]);
    }

    // ── GET /auth/google/register?rol=xxx&tenant_id=xxx  (web route) ─────────
    // React navigates the browser here directly (avoids API session issues)
    public function registerWithGoogle(Request $request)
    {
        $request->validate([
            'rol'       => 'required|in:residente,guardia',
            'tenant_id' => 'required|exists:tenants,id',
        ]);

        session([
            'oauth_rol'       => $request->rol,
            'oauth_tenant_id' => (int) $request->tenant_id,
            'oauth_mode'      => 'register',
        ]);

        return Socialite::driver('google')->redirect();
    }

    // ── GET /auth/google/redirect  (web route) ────────────────────────────────
    // Used for "Login with Google" (existing users)
    public function redirectToGoogle()
    {
        session(['oauth_mode' => 'login']);
        return Socialite::driver('google')->redirect();
    }

    // ── GET /auth/google/callback  (web route — Google redirects here) ─
    public function handleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect($this->frontendUrl('login', ['error' => 'google_failed']));
        }

        $mode = session('oauth_mode', 'login');

        // ── Existing user login ────────────────────────────────────────
        $existingByGoogle = Usuario::where('google_id', $googleUser->getId())->first();
        if ($existingByGoogle) {
            session()->forget(['oauth_rol', 'oauth_tenant_id', 'oauth_mode']);

            if ($existingByGoogle->status === 'pendiente') {
                return redirect($this->frontendUrl('pendiente'));
            }
            if ($existingByGoogle->status === 'rechazado') {
                return redirect($this->frontendUrl('login', ['error' => 'rejected']));
            }
            if (!$existingByGoogle->activo) {
                return redirect($this->frontendUrl('login', ['error' => 'inactive']));
            }

            $token = $existingByGoogle->createToken('google-oauth')->plainTextToken;
            return redirect($this->frontendUrl('login', [
                'google_token' => $token,
                'user'         => base64_encode(json_encode([
                    'id'        => $existingByGoogle->id,
                    'nombre'    => $existingByGoogle->nombre,
                    'apellido'  => $existingByGoogle->apellido,
                    'email'     => $existingByGoogle->email,
                    'rol'       => $existingByGoogle->rol?->nombre,
                    'tenant_id' => $existingByGoogle->tenant_id,
                    'avatar_url'=> $existingByGoogle->avatar_url,
                ])),
            ]));
        }

        // ── New registration ───────────────────────────────────────────
        if ($mode !== 'register') {
            // Google login attempt but account not found
            return redirect($this->frontendUrl('login', ['error' => 'not_registered']));
        }

        $tenantId = session('oauth_tenant_id');
        $rolName  = session('oauth_rol');

        if (!$tenantId || !$rolName) {
            return redirect($this->frontendUrl('register', ['error' => 'session_expired']));
        }

        // Email already used with password login?
        $byEmail = Usuario::where('email', $googleUser->getEmail())->first();
        if ($byEmail) {
            return redirect($this->frontendUrl('register', ['error' => 'email_taken']));
        }

        // Find the matching role
        $role = Role::where('tenant_id', $tenantId)->where('nombre', $rolName)->first();
        if (!$role) {
            return redirect($this->frontendUrl('register', ['error' => 'role_not_found']));
        }

        // Split name
        $nameParts = explode(' ', trim($googleUser->getName()), 2);

        $usuario = Usuario::create([
            'tenant_id'     => $tenantId,
            'rol_id'        => $role->id,
            'codigo'        => $this->generateCodigo(),
            'nombre'        => $nameParts[0],
            'apellido'      => $nameParts[1] ?? '',
            'email'         => $googleUser->getEmail(),
            'google_id'     => $googleUser->getId(),
            'avatar_url'    => $googleUser->getAvatar(),
            'password_hash' => bcrypt(\Illuminate\Support\Str::random(32)),
            'activo'        => false,
            'status'        => 'pendiente',
        ]);

        $this->notifyAdmins($usuario, $tenantId);

        session()->forget(['oauth_rol', 'oauth_tenant_id', 'oauth_mode']);

        return redirect($this->frontendUrl('pendiente'));
    }

    // ─────────────────────────────────────────────────────────────────
    private function notifyAdmins(Usuario $nuevo, int $tenantId): void
    {
        $adminRole = Role::where('tenant_id', $tenantId)->where('nombre', 'admin')->first();
        if (!$adminRole) return;

        $admins = Usuario::where('tenant_id', $tenantId)
            ->where('rol_id', $adminRole->id)
            ->where('activo', true)
            ->get();

        foreach ($admins as $admin) {
            try { Mail::to($admin->email)->send(new NuevaSolicitudAdmin($admin, $nuevo)); }
            catch (\Exception) {}
        }
    }
}
