<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaTicket;
use App\Models\PropietarioUnidad;
use App\Models\Ticket;
use App\Models\TicketComentario;
use App\Models\TicketHistorial;
use App\Models\Unidad;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    // ── GET /api/v1/tickets ───────────────────────────────────────────────────────
    // Params: estado, prioridad, categoria_id, asignado_a, buscar, page
    // Orden: urgentes abiertos primero, luego created_at desc
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        $userId   = $request->user()->id;

        $query = Ticket::with([
                'categoria:id,nombre,color',
                'unidad:id,numero',
                // eager load del propietario activo para evitar N+1
                'unidad.propietariosActivos' => fn ($q) => $q->select(
                    'propietarios.id', 'propietarios.nombre', 'propietarios.apellido'
                ),
                'asignado:id,nombre,apellido',  // alias definido en Ticket::asignado()
            ])
            ->withCount('comentarios')
            ->where('tickets.tenant_id', $tenantId);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('prioridad')) {
            $query->where('prioridad', $request->prioridad);
        }
        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->categoria_id);
        }
        if ($request->filled('asignado_a')) {
            $query->where('asignado_a', $request->asignado_a);
        }
        if ($request->filled('unidad_id')) {
            $query->where('tickets.unidad_id', $request->unidad_id);
        }
        if ($request->filled('buscar')) {
            $s = '%' . $request->buscar . '%';
            $query->where(fn ($q) =>
                $q->where('titulo', 'like', $s)
                  ->orWhere('descripcion', 'like', $s)
            );
        }

        // Urgentes primero → alta → media → baja, luego por fecha de creación desc
        $query->orderByRaw("
            FIELD(prioridad, 'urgente', 'alta', 'media', 'baja')
        ")->orderByDesc('tickets.created_at');

        $paginado = $query->paginate(20);

        // propietariosActivos ya viene por eager loading — sin N+1
        $user = $request->user();

        $items = $paginado->getCollection()->map(function (Ticket $t) use ($user) {
            $prop = $t->unidad?->propietariosActivos?->first();

            return [
                'id'                => $t->id,
                'titulo'            => $t->titulo,
                'prioridad'         => $t->prioridad,
                'estado'            => $t->estado,
                'categoria'         => $t->categoria,
                'unidad'            => $t->unidad ? ['id' => $t->unidad->id, 'numero' => $t->unidad->numero] : null,
                'ubicacion'         => $t->ubicacion,
                'propietario'       => $prop ? trim("{$prop->nombre} {$prop->apellido}") : null,
                'asignado_nombre'   => $t->asignado_nombre,
                'asignado_a'        => $t->asignado,
                'costo_real'        => $t->costo_real,
                'dias_abierto'      => $t->created_at->diffInDays(now()),
                'total_comentarios' => $t->comentarios_count,
                'created_at'        => $t->created_at,
            ];
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

    // ── POST /api/v1/tickets ──────────────────────────────────────────────────────
    // Body: { titulo, descripcion, categoria_id, unidad_id, prioridad }
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'titulo'       => 'required|string|max:200',
            'descripcion'  => 'nullable|string',
            'unidad_id'    => 'nullable|integer',
            'ubicacion'    => 'nullable|string|max:200',
            'categoria_id' => 'nullable|integer',
            'prioridad'    => 'nullable|in:baja,media,alta,urgente',
            'foto'         => 'nullable|file|image|max:5120',  // max 5 MB
        ]);

        // Validar que la categoría pertenece al tenant
        if (!empty($validated['categoria_id'])) {
            $catOk = CategoriaTicket::where('id', $validated['categoria_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (!$catOk) {
                return response()->json(['message' => 'Categoría no encontrada en este PH.'], 422);
            }
        }

        // Validar que la unidad pertenece al tenant
        if (!empty($validated['unidad_id'])) {
            $unidadOk = Unidad::where('id', $validated['unidad_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (!$unidadOk) {
                return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
            }
        }

        // Handle photo upload
        $fotoUrl = null;
        if ($request->hasFile('foto')) {
            $path    = $request->file('foto')->store('tickets', 'public');
            $fotoUrl = url(Storage::url($path));
        }

        $ticket = Ticket::create([
            'tenant_id'    => $tenantId,
            'numero'       => 'TMP',   // will be replaced after insert
            'titulo'       => $validated['titulo'],
            'descripcion'  => $validated['descripcion'] ?? null,
            'unidad_id'    => $validated['unidad_id'] ?? null,
            'ubicacion'    => $validated['ubicacion'] ?? null,
            'categoria_id' => $validated['categoria_id'] ?? null,
            'prioridad'    => $validated['prioridad'] ?? 'media',
            'estado'       => 'abierto',
            'foto_url'     => $fotoUrl,
            'reportado_por' => $request->user()->id,
        ]);

        // Assign a sequential numero based on the auto-increment id
        $ticket->numero = 'T-' . str_pad($ticket->id, 4, '0', STR_PAD_LEFT);
        $ticket->save();

        $this->registrarHistorial($ticket->id, $request->user()->id, 'creado',
            null, 'Ticket creado con estado: abierto');

        return response()->json([
            'data'    => $ticket->load([
                'unidad:id,numero',
                'categoria:id,nombre,color',
            ]),
            'message' => 'Ticket creado.',
        ], 201);
    }

    // ── GET /api/v1/tickets/:id ───────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $ticket = Ticket::with([
                'unidad:id,numero,torre_id',
                'unidad.torre:id,nombre',
                'categoria:id,nombre,color',
                'asignadoA:id,nombre,apellido,email',
                'creadoPor:id,nombre,apellido',
                'comentarios' => fn ($q) => $q->orderBy('created_at'),
                'comentarios.usuario:id,nombre,apellido',
                'historial' => fn ($q) => $q->orderBy('created_at'),
                'historial.usuario:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        // Propietario activo de la unidad
        $propPivot = $ticket->unidad_id
            ? PropietarioUnidad::with('propietario:id,nombre,apellido,cedula,telefono')
                ->where('unidad_id', $ticket->unidad_id)
                ->where('activo', true)
                ->first()
            : null;

        // Ocultar comentarios internos para no-admin (los admin tienen rol 'admin')
        $user       = $request->user();
        $esAdmin    = strtolower($user->rol?->nombre ?? '') === 'admin';
        $comentarios = $ticket->comentarios->filter(
            fn ($c) => $esAdmin || !$c->es_interno
        )->values();

        return response()->json([
            'data' => array_merge($ticket->only([
                'id', 'titulo', 'descripcion', 'foto_url', 'prioridad', 'estado',
                'created_at', 'updated_at',
            ]), [
                'unidad'      => $ticket->unidad,
                'torre'       => $ticket->unidad?->torre,
                'categoria'   => $ticket->categoria,
                'propietario' => $propPivot?->propietario ?? null,
                'asignado_a'  => $ticket->asignadoA,
                'creado_por'  => $ticket->creadoPor,
                'comentarios' => $comentarios,
                'historial'   => $ticket->historial,
            ]),
        ]);
    }

    // ── PUT /api/v1/tickets/:id ───────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $ticket   = Ticket::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'titulo'           => 'sometimes|string|max:200',
            'descripcion'      => 'nullable|string',
            'unidad_id'        => 'nullable|integer',
            'categoria_id'     => 'nullable|integer',
            'estado'           => 'nullable|in:abierto,en_progreso,en_espera,resuelto,cerrado,cancelado',
            'prioridad'        => 'nullable|in:baja,media,alta,urgente',
            'asignado_nombre'  => 'nullable|string|max:150',
            'costo_real'       => 'nullable|numeric|min:0',
        ]);

        $ticket->update($validated);

        return response()->json([
            'data'    => $ticket->fresh(['unidad:id,numero', 'categoria:id,nombre,color']),
            'message' => 'Ticket actualizado.',
        ]);
    }

    /** DELETE /tickets/{id} */
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $ticket = Ticket::where('tenant_id', $tenantId)->findOrFail($id);
        $ticket->delete();

        return response()->json(['message' => 'Ticket eliminado.']);
    }

    // ── GET /api/v1/tickets/:id/comentarios ──────────────────────────────────────
    public function getComentarios(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $ticket   = Ticket::where('tenant_id', $tenantId)->findOrFail($id);

        $user    = $request->user();
        $esAdmin = strtolower($user->rol?->nombre ?? '') === 'admin';

        $comentarios = TicketComentario::with('usuario:id,nombre,apellido,avatar_url')
            ->where('ticket_id', $ticket->id)
            ->when(!$esAdmin, fn ($q) => $q->where('es_interno', false))
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $comentarios]);
    }

    // ── POST /api/v1/tickets/:id/comentarios ─────────────────────────────────────
    // Body: { contenido, interno? (boolean) }
    // Los comentarios internos solo son visibles para admin
    public function agregarComentario(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $ticket   = Ticket::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'comentario' => 'required|string',
            'es_interno' => 'nullable|boolean',
        ]);

        $comentario = TicketComentario::create([
            'ticket_id'  => $ticket->id,
            'usuario_id' => $request->user()->id,
            'comentario' => $validated['comentario'],
            'es_interno' => $validated['es_interno'] ?? false,
        ]);

        return response()->json([
            'data'    => $comentario->load('usuario:id,nombre,apellido,avatar_url'),
            'message' => 'Comentario agregado.',
        ], 201);
    }

    // ── PUT /api/v1/tickets/:id/estado ───────────────────────────────────────────
    // Body: { estado, notas? }
    // Estados: abierto | en_progreso | en_espera | resuelto | cerrado
    public function cambiarEstado(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $ticket   = Ticket::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|in:abierto,en_progreso,en_espera,resuelto,cerrado',
            'notas'  => 'nullable|string',
        ]);

        if ($validated['estado'] === $ticket->estado) {
            return response()->json(['message' => 'El ticket ya se encuentra en ese estado.'], 422);
        }

        $estadoAnterior = $ticket->estado;
        $ticket->update(['estado' => $validated['estado']]);

        $detalle = "De: {$estadoAnterior} → A: {$validated['estado']}";
        if (!empty($validated['notas'])) {
            $detalle .= " | Notas: {$validated['notas']}";
        }

        $this->registrarHistorial($ticket->id, $request->user()->id, 'estado_cambiado',
            $estadoAnterior, $detalle);

        return response()->json([
            'data'    => $ticket->fresh(),
            'message' => "Estado cambiado de '{$estadoAnterior}' a '{$validated['estado']}'.",
        ]);
    }

    // ── POST /api/v1/tickets/:id/asignar ─────────────────────────────────────────
    // Body: { usuario_id }
    public function asignar(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $ticket   = Ticket::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'usuario_id' => 'required|integer',
        ]);

        // Verificar que el usuario pertenece al mismo tenant
        $usuario = Usuario::where('id', $validated['usuario_id'])
            ->where('tenant_id', $tenantId)
            ->where('activo', true)
            ->first();

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado en este PH.'], 422);
        }

        $anteriorId = $ticket->asignado_a;
        $ticket->update([
            'asignado_a' => $usuario->id,
            'estado'     => $ticket->estado === 'abierto' ? 'en_progreso' : $ticket->estado,
        ]);

        $anteriorNombre = $anteriorId
            ? (Usuario::find($anteriorId)?->nombre ?? "#{$anteriorId}")
            : 'sin asignar';

        $this->registrarHistorial($ticket->id, $request->user()->id, 'asignado',
            $anteriorNombre, "Asignado a: {$usuario->nombre} {$usuario->apellido}");

        return response()->json([
            'data'    => $ticket->fresh('asignadoA:id,nombre,apellido,email'),
            'message' => "Ticket asignado a {$usuario->nombre} {$usuario->apellido}.",
        ]);
    }

    // ── GET /api/v1/categorias-ticket ────────────────────────────────────────────
    public function categorias(Request $request)
    {
        $tenantId   = $request->get('tenant_id');
        $categorias = CategoriaTicket::where('tenant_id', $tenantId)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'color']);

        return response()->json(['data' => $categorias]);
    }

    private function registrarHistorial(int $ticketId, int $usuarioId, string $accion, $anterior, $detalle): void
    {
        TicketHistorial::create([
            'ticket_id'      => $ticketId,
            'usuario_id'     => $usuarioId,
            'campo_cambiado' => $accion,
            'valor_anterior' => $anterior,
            'valor_nuevo'    => $detalle,
        ]);
    }
}
