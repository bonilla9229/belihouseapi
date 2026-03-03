<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comunicado;
use App\Models\ComunicadoLectura;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComunicadoController extends Controller
{
    // ── GET /api/v1/comunicados ───────────────────────────────────────────────
    // Params: tipo, activo (boolean), page
    // Admin: todos los comunicados + stats de lectura
    // Residente/otro: solo publicados
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $user     = $request->user();
        $esAdmin  = strtolower($user->rol?->nombre ?? '') === 'admin';

        $query = Comunicado::with('autor:id,nombre,apellido')
            ->withCount('lecturas')
            ->where('tenant_id', $tenantId);

        // Residentes solo ven publicados
        if (!$esAdmin) {
            $query->where('publicado', true);
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        // Param: activo=1 → publicado=true, activo=0 → publicado=false (solo admin)
        if ($request->has('activo') && $esAdmin) {
            $query->where('publicado', $request->boolean('activo'));
        }
        // Param: estado=publicado|borrador (solo admin, alternativa semántica a activo)
        if ($request->filled('estado') && $esAdmin) {
            $query->where('publicado', $request->estado === 'publicado');
        }
        if ($request->filled('buscar') || $request->filled('search')) {
            $b = '%' . ($request->filled('buscar') ? $request->buscar : $request->search) . '%';
            $query->where(function ($q) use ($b) {
                $q->where('titulo', 'like', $b)->orWhere('cuerpo', 'like', $b);
            });
        }

        $paginado = $query->orderByDesc('fecha_publicacion')->orderByDesc('created_at')->paginate(20);

        // Total de usuarios activos del tenant para calcular stats de lectura
        $totalUsuarios = $esAdmin
            ? Usuario::where('tenant_id', $tenantId)->where('activo', true)->count()
            : 0;

        $items = $paginado->getCollection()->map(function (Comunicado $c) use ($esAdmin, $totalUsuarios) {
            $item = [
                'id'                 => $c->id,
                'titulo'             => $c->titulo,
                'tipo'               => $c->tipo,
                'publicado'          => $c->publicado,
                'estado'             => $c->publicado ? 'publicado' : 'borrador',
                'fecha_publicacion'  => $c->fecha_publicacion,
                'autor'              => $c->autor,
                'created_at'         => $c->created_at,
            ];
            if ($esAdmin) {
                $item['lecturas']           = $c->lecturas_count;
                $item['total_destinatarios']= $totalUsuarios;
                $item['porcentaje_lectura'] = $totalUsuarios > 0
                    ? round(($c->lecturas_count / $totalUsuarios) * 100, 1)
                    : 0;
            }
            return $item;
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginado->total(),
                'per_page'     => $paginado->perPage(),
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/comunicados ─────────────────────────────────────────────────
    // Body: { titulo, cuerpo, tipo, audiencia (ignorado), fecha_expiracion (ignorado) }
    // tipos válidos en schema: aviso|urgente|circular|boletin|evento
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'titulo'            => 'required|string|max:200',
            'cuerpo'         => 'required|string',
            'tipo'              => 'nullable|in:aviso,urgente,circular,boletin,evento',
            'audiencia'         => 'nullable|string',   // aceptado, sin columna en schema
            'fecha_expiracion'  => 'nullable|date',     // aceptado, sin columna en schema
        ]);

        $comunicado = Comunicado::create([
            'tenant_id' => $tenantId,
            'autor_id'  => $request->user()->id,
            'titulo'    => $validated['titulo'],
            'cuerpo' => $validated['cuerpo'],
            'tipo'      => $validated['tipo'] ?? 'aviso',
            'publicado' => false,
        ]);

        return response()->json([
            'data'    => $comunicado->load('autor:id,nombre,apellido'),
            'message' => 'Comunicado creado como borrador.',
        ], 201);
    }

    // ── GET /api/v1/comunicados/:id ─────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $user     = $request->user();
        $esAdmin  = strtolower($user->rol?->nombre ?? '') === 'admin';

        $query = Comunicado::with('autor:id,nombre,apellido')
            ->withCount('lecturas')
            ->where('tenant_id', $tenantId);

        if (!$esAdmin) {
            $query->where('publicado', true);
        }

        $comunicado = $query->findOrFail($id);

        return response()->json(['data' => $comunicado]);
    }

    // ── PUT /api/v1/comunicados/:id (solo si publicado=false) ───────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId   = $request->get('tenant_id');
        $comunicado = Comunicado::where('tenant_id', $tenantId)->findOrFail($id);

        if ($comunicado->publicado) {
            return response()->json(['message' => 'No se puede editar un comunicado ya publicado.'], 422);
        }

        $validated = $request->validate([
            'titulo'    => 'sometimes|string|max:200',
            'cuerpo' => 'sometimes|string',
            'tipo'      => 'nullable|in:aviso,urgente,circular,boletin,evento',
        ]);

        $comunicado->update($validated);

        return response()->json([
            'data'    => $comunicado->fresh('autor:id,nombre,apellido'),
            'message' => 'Comunicado actualizado.',
        ]);
    }

    // ── DELETE /api/v1/comunicados/:id (solo si publicado=false) ───────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId   = $request->get('tenant_id');
        $comunicado = Comunicado::where('tenant_id', $tenantId)->findOrFail($id);

        if ($comunicado->publicado) {
            return response()->json(['message' => 'No se puede eliminar un comunicado ya publicado.'], 422);
        }

        $comunicado->delete();

        return response()->json(['message' => 'Comunicado eliminado.']);
    }

    // ── POST /api/v1/comunicados/:id/publicar ───────────────────────────────────
    // Solo admin
    public function publicar(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $user     = $request->user();

        if (strtolower($user->rol?->nombre ?? '') !== 'admin') {
            return response()->json(['message' => 'Solo administradores pueden publicar comunicados.'], 403);
        }

        $comunicado = Comunicado::where('tenant_id', $tenantId)->findOrFail($id);

        if ($comunicado->publicado) {
            return response()->json(['message' => 'El comunicado ya fue publicado.'], 422);
        }

        $comunicado->update([
            'publicado'    => true,
            'fecha_publicacion' => now(),
        ]);

        return response()->json([
            'data'    => $comunicado->fresh(['autor:id,nombre,apellido']),
            'message' => 'Comunicado publicado.',
        ]);
    }

    // ── POST /api/v1/comunicados/:id/leer ────────────────────────────────────────
    // Crea registro en comunicado_lecturas si no existe (idempotente)
    public function marcarLeido(Request $request, string $id)
    {
        $tenantId   = $request->get('tenant_id');
        $comunicado = Comunicado::where('tenant_id', $tenantId)
            ->where('publicado', true)
            ->findOrFail($id);

        $lectura = ComunicadoLectura::firstOrCreate(
            ['comunicado_id' => $comunicado->id, 'usuario_id' => $request->user()->id],
            ['leido_at'      => now()]
        );

        $yaExistia = !$lectura->wasRecentlyCreated;

        return response()->json([
            'message'   => $yaExistia ? 'Ya habías leído este comunicado.' : 'Marcado como leído.',
            'leido_at'  => $lectura->leido_at,
        ]);
    }

    // ── GET /api/v1/comunicados/:id/estadisticas ─────────────────────────────────
    // Retorna: total_destinatarios, total_leido, porcentaje, lista de lectores con fecha
    public function estadisticas(Request $request, string $id)
    {
        $tenantId   = $request->get('tenant_id');
        $comunicado = Comunicado::where('tenant_id', $tenantId)->findOrFail($id);

        // Total de usuarios activos del tenant como proxy de destinatarios
        // (no hay columna audiencia en schema)
        $totalDestinatarios = Usuario::where('tenant_id', $tenantId)
            ->where('activo', true)
            ->count();

        $lecturas = ComunicadoLectura::with('usuario:id,nombre,apellido,email')
            ->where('comunicado_id', $comunicado->id)
            ->orderBy('leido_at')
            ->get()
            ->map(fn (ComunicadoLectura $l) => [
                'usuario'  => $l->usuario,
                'leido_at' => $l->leido_at,
            ]);

        $totalLeido = $lecturas->count();

        return response()->json([
            'data' => [
                'comunicado_id'       => $comunicado->id,
                'titulo'              => $comunicado->titulo,
                'publicado'           => $comunicado->publicado,
                'fecha_publicacion'        => $comunicado->fecha_publicacion,
                'total_destinatarios' => $totalDestinatarios,
                'total_leido'         => $totalLeido,
                'porcentaje'          => $totalDestinatarios > 0
                    ? round(($totalLeido / $totalDestinatarios) * 100, 1)
                    : 0,
                'lecturas'            => $lecturas,
            ],
        ]);
    }
}
