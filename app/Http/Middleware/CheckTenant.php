<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Verificar que hay un usuario autenticado con tenant_id
        $user = $request->user();

        if (!$user || !$user->tenant_id) {
            return response()->json([
                'message' => 'Acceso denegado: tenant no identificado.',
            ], 403);
        }

        // 2. Verificar que el tenant existe y está activo en la BD
        $tenant = Tenant::where('id', $user->tenant_id)
            ->where('activo', true)
            ->first();

        if (!$tenant) {
            return response()->json([
                'message' => 'El tenant no existe o se encuentra inactivo.',
            ], 403);
        }

        // 3. Inyectar tenant_id en el request para uso en controllers
        $request->merge(['tenant_id' => $tenant->id]);

        // 4. Continuar y agregar header de debugging
        $response = $next($request);
        $response->headers->set('X-Tenant-ID', (string) $tenant->id);

        return $response;
    }
}