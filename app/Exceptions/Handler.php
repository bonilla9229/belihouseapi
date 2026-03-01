<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Manejador de excepciones centralizado para Aleph API.
 *
 * Se registra en bootstrap/app.php → withExceptions():
 *
 *   $exceptions->render(
 *       fn (Throwable $e, Request $r) => Handler::render($e, $r)
 *   );
 */
class Handler
{
    /**
     * Convierte cualquier Throwable a una JsonResponse estándar de Aleph.
     * Retorna null para peticiones no-API (Laravel maneja las rutas web normalmente).
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        // Sólo interceptar peticiones que esperen JSON o que sean de la API
        if (!$request->expectsJson() && !$request->is('api/*')) {
            return null;
        }

        // ── 422 Validation ──────────────────────────────────────────────────
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => 'Error de validación.',
                'errors'  => $e->errors(),
            ], 422);
        }

        // ── 401 Unauthenticated ─────────────────────────────────────────────
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        // ── 403 Forbidden ───────────────────────────────────────────────────
        if ($e instanceof AccessDeniedHttpException) {
            return response()->json([
                'message' => 'No tienes permiso para esta acción.',
            ], 403);
        }

        // ── 404 Not Found (ruta o recurso Eloquent) ─────────────────────────
        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            return response()->json([
                'message' => 'Recurso no encontrado.',
            ], 404);
        }

        // ── Otros HTTP exceptions (405, 429, etc.) ──────────────────────────
        if ($e instanceof HttpException) {
            $msg = $e->getMessage() ?: 'Error en la petición.';
            return response()->json(['message' => $msg], $e->getStatusCode());
        }

        // ── 500 — Desarrollo: traza completa | Producción: mensaje genérico ─
        if (config('app.debug')) {
            return response()->json([
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => collect($e->getTrace())->take(15)->map(fn ($f) => [
                    'file'     => $f['file'] ?? null,
                    'line'     => $f['line'] ?? null,
                    'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                ])->toArray(),
            ], 500);
        }

        return response()->json([
            'message' => 'Error interno del servidor.',
        ], 500);
    }
}
