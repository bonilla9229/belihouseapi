<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsambleaAsistencia;
use App\Models\OpcionVotacion;
use App\Models\Unidad;
use App\Models\Votacion;
use App\Models\Voto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VotacionController extends Controller
{
    // ── GET /api/v1/votaciones ───────────────────────────────────────────────────
    // Params: asamblea_id, estado, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Votacion::with(['opciones:id,votacion_id,texto', 'asamblea:id,titulo'])
            ->withCount('votos')
            ->where('tenant_id', $tenantId);

        if ($request->filled('asamblea_id')) {
            $query->where('asamblea_id', $request->asamblea_id);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $paginado = $query->orderByDesc('created_at')->paginate(20);

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

    // ── POST /api/v1/votaciones ──────────────────────────────────────────────────
    // Body: { asamblea_id, titulo, descripcion, tipo_voto, opciones[] }
    // titulo → columna pregunta; tipo_voto aceptado pero sin columna en schema
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'titulo'      => 'required_without:pregunta|string|max:500',
            'pregunta'    => 'required_without:titulo|string|max:500',
            'descripcion' => 'nullable|string',   // informativo; sin columna en schema
            'tipo_voto'   => 'nullable|in:simple,ponderado',
            'asamblea_id' => 'nullable|integer',
            'opciones'    => 'required|array|min:2',
            'opciones.*'  => 'required|string|max:200',
        ]);

        // Validar asamblea pertenece al tenant si se envió
        if (!empty($validated['asamblea_id'])) {
            $ok = \App\Models\Asamblea::where('id', $validated['asamblea_id'])
                ->where('tenant_id', $tenantId)->exists();
            if (!$ok) {
                return response()->json(['message' => 'Asamblea no encontrada en este PH.'], 422);
            }
        }

        $pregunta = $validated['titulo'] ?? $validated['pregunta'];

        DB::beginTransaction();
        try {
            $votacion = Votacion::create([
                'tenant_id'   => $tenantId,
                'asamblea_id' => $validated['asamblea_id'] ?? null,
                'pregunta'    => $pregunta,
                'estado'      => 'abierta',
            ]);

            foreach ($validated['opciones'] as $texto) {
                OpcionVotacion::create(['votacion_id' => $votacion->id, 'texto' => $texto]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear la votación.'], 500);
        }

        return response()->json([
            'data'    => $votacion->load(['opciones:id,votacion_id,texto', 'asamblea:id,titulo']),
            'message' => 'Votación creada.',
        ], 201);
    }

    // ── GET /api/v1/votaciones/:id ──────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $votacion = Votacion::with([
                'opciones:id,votacion_id,texto',
                'asamblea:id,titulo',
                'votos:id,votacion_id,opcion_id,unidad_id',
            ])
            ->withCount('votos')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $votacion]);
    }

    // ── PUT /api/v1/votaciones/:id ──────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $votacion = Votacion::where('tenant_id', $tenantId)->findOrFail($id);

        if ($votacion->estado === 'cerrada') {
            return response()->json(['message' => 'No se puede editar una votación cerrada.'], 422);
        }

        $validated = $request->validate([
            'titulo'    => 'sometimes|string|max:500',  // alias de pregunta
            'pregunta'  => 'sometimes|string|max:500',
        ]);

        $payload = [];
        if (isset($validated['titulo']))   $payload['pregunta'] = $validated['titulo'];
        if (isset($validated['pregunta'])) $payload['pregunta'] = $validated['pregunta'];

        if (!empty($payload)) {
            $votacion->update($payload);
        }

        return response()->json(['data' => $votacion->fresh('opciones'), 'message' => 'Votación actualizada.']);
    }

    // ── DELETE /api/v1/votaciones/:id ─────────────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $votacion = Votacion::where('tenant_id', $tenantId)->findOrFail($id);

        if ($votacion->votos()->exists()) {
            return response()->json(['message' => 'No se puede eliminar una votación con votos registrados.'], 422);
        }

        $votacion->delete();

        return response()->json(['message' => 'Votación eliminada.']);
    }

    // ── POST /api/v1/votaciones/:id/votar ───────────────────────────────────────
    // Body: { unidad_id, opcion_id }
    // Valida: votación abierta, unidad no ha votado ya, unidad presente en asamblea
    public function votar(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $votacion = Votacion::where('tenant_id', $tenantId)
            ->where('estado', 'abierta')
            ->findOrFail($id);

        $validated = $request->validate([
            'unidad_id' => 'required|integer',
            'opcion_id' => 'required|integer',
        ]);

        // Verificar que la unidad pertenezca al tenant
        $unidad = Unidad::where('id', $validated['unidad_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Verificar que la opción pertenezca a esta votación
        $opcion = OpcionVotacion::where('id', $validated['opcion_id'])
            ->where('votacion_id', $votacion->id)
            ->firstOrFail();

        // Verificar que la unidad esté presente en la asamblea (si la votación tiene asamblea)
        if ($votacion->asamblea_id) {
            $presente = AsambleaAsistencia::where('asamblea_id', $votacion->asamblea_id)
                ->where('unidad_id', $unidad->id)
                ->where('presente', true)
                ->exists();
            if (!$presente) {
                return response()->json([
                    'message' => 'La unidad no está registrada como presente en la asamblea.',
                ], 422);
            }
        }

        // Voto único por unidad (unique constraint en votos)
        if (Voto::where('votacion_id', $votacion->id)->where('unidad_id', $unidad->id)->exists()) {
            return response()->json(['message' => 'Esta unidad ya emitió su voto en esta votación.'], 422);
        }

        $voto = Voto::create([
            'votacion_id' => $votacion->id,
            'opcion_id'   => $opcion->id,
            'unidad_id'   => $unidad->id,
            'usuario_id'  => $request->user()->id,
        ]);

        return response()->json([
            'data'    => [
                'voto'   => $voto,
                'unidad' => $unidad->only(['id', 'numero']),
                'opcion' => $opcion->only(['id', 'texto']),
            ],
            'message' => 'Voto registrado.',
        ], 201);
    }

    // ── POST /api/v1/votaciones/:id/cerrar ──────────────────────────────────────
    public function cerrar(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $votacion = Votacion::where('tenant_id', $tenantId)
            ->where('estado', 'abierta')
            ->findOrFail($id);

        $votacion->update(['estado' => 'cerrada', 'cierre_at' => now()]);

        return response()->json([
            'data'    => $votacion->fresh(['opciones:id,votacion_id,texto']),
            'message' => 'Votación cerrada.',
        ]);
    }

    // ── GET /api/v1/votaciones/:id/resultados ──────────────────────────────────
    // Por opción: votos_count, peso_total (suma area_m2 de unidades votantes), porcentaje
    // Ganador: opción con mayor votos_count (o peso_total si ponderado)
    // Nota: votos no tiene columna peso_coeficiente; se usa area_m2 de la unidad
    public function resultados(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $votacion = Votacion::with('opciones:id,votacion_id,texto')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $totalVotos = Voto::where('votacion_id', $votacion->id)->count();

        // Calcular peso total del PH (suma area_m2) para % ponderado
        $totalArea = Unidad::where('tenant_id', $tenantId)->where('estado', 'activa')->sum('area_m2');
        if (!$totalArea) $totalArea = Unidad::where('tenant_id', $tenantId)->where('estado', 'activa')->count();

        $resultadosPorOpcion = $votacion->opciones->map(function (OpcionVotacion $opcion) use ($votacion, $totalVotos, $totalArea) {
            $votosOpcion = Voto::join('unidades', 'unidades.id', '=', 'votos.unidad_id')
                ->where('votos.votacion_id', $votacion->id)
                ->where('votos.opcion_id', $opcion->id)
                ->select('votos.*', 'unidades.area_m2')
                ->get();

            $votosCount = $votosOpcion->count();
            $pesoTotal  = $votosOpcion->sum(fn ($v) => (float) ($v->area_m2 ?? 1));

            return [
                'opcion_id'   => $opcion->id,
                'texto'       => $opcion->texto,
                'votos_count' => $votosCount,
                'peso_total'  => round($pesoTotal, 4),
                'porcentaje_votos'  => $totalVotos > 0
                    ? round($votosCount / $totalVotos * 100, 2)
                    : 0,
                'porcentaje_peso'   => $totalArea > 0
                    ? round($pesoTotal  / $totalArea  * 100, 2)
                    : 0,
            ];
        });

        // Ganador: mayor peso_total
        $ganador = $resultadosPorOpcion->sortByDesc('peso_total')->first();

        return response()->json([
            'data' => [
                'votacion_id'  => $votacion->id,
                'pregunta'     => $votacion->pregunta,
                'estado'       => $votacion->estado,
                'total_votos'  => $totalVotos,
                'resultados'   => $resultadosPorOpcion->values(),
                'ganador'      => $ganador,
            ],
        ]);
    }
}
