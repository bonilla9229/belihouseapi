<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asamblea;
use App\Models\AsambleaAsistencia;
use App\Models\Unidad;
use Illuminate\Http\Request;

class AsambleaController extends Controller
{
    // ── GET /api/v1/asambleas ───────────────────────────────────────────────────
    // Retorna: título, tipo (desde descripción), fecha, estado, % quórum, page
    public function index(Request $request)
    {
        $tenantId      = $request->get('tenant_id');
        $totalUnidades = Unidad::where('tenant_id', $tenantId)->where('activa', 1)->count();

        $query = Asamblea::withCount([
                'asistencias as presentes_count',
            ])
            ->where('tenant_id', $tenantId);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('buscar')) {
            $query->where('titulo', 'like', '%' . $request->buscar . '%');
        }

        $paginado = $query->orderByDesc('fecha')->paginate(20);

        $items = $paginado->getCollection()->map(fn (Asamblea $a) => [
            'id'               => $a->id,
            'titulo'           => $a->titulo,
            'tipo'             => $a->tipo,
            'fecha'            => $a->fecha,
            'lugar'            => $a->lugar,
            'estado'           => $a->estado,
            'presentes'        => $a->presentes_count,
            'total_unidades'   => $totalUnidades,
            'porcentaje_quorum'=> $totalUnidades > 0
                ? round($a->presentes_count / $totalUnidades * 100, 2)
                : 0,
        ]);

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

    // ── POST /api/v1/asambleas ──────────────────────────────────────────────────
    // Body: { titulo, tipo (ordinaria|extraordinaria), fecha, lugar, quorum_requerido }
    // Nota: 'tipo' y 'quorum_requerido' no tienen columna en schema; tipo se almacena en descripcion
    // Estado inicial: 'programada' (valor por defecto del schema)
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'titulo' => 'required|string|max:200',
            'tipo'  => 'nullable|in:ordinaria,extraordinaria,urgente',
            'fecha'  => 'required|date',
            'lugar'  => 'nullable|string|max:200',
            'quorum_requerido' => 'nullable|numeric|between:1,100',
            'orden_dia'        => 'nullable|array',
            'orden_dia.*'      => 'string|max:300',
        ]);

        $asamblea = Asamblea::create([
            'tenant_id'        => $tenantId,
            'titulo'           => $validated['titulo'],
            'tipo'             => $validated['tipo'] ?? 'ordinaria',
            'fecha'            => $validated['fecha'],
            'lugar'            => $validated['lugar'] ?? null,
            'quorum_requerido' => $validated['quorum_requerido'] ?? 50,
            'notas'            => !empty($validated['orden_dia']) ? json_encode($validated['orden_dia']) : null,
            'estado'           => 'convocada',
        ]);
        return response()->json([
            'data'    => $asamblea,
            'message' => 'Asamblea creada.',
        ], 201);
    }

    // ── GET /api/v1/asambleas/:id ─────────────────────────────────────────────────
    // Incluye: asistencias, votaciones, estado del quórum
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $asamblea = Asamblea::with([
                'asistencias:id,asamblea_id,unidad_id,propietario_id,nombre_asistente,tipo',
                'asistencias.unidad:id,numero',
                'votaciones:id,asamblea_id,titulo,estado,fecha_fin',
                'votaciones.opciones:id,votacion_id,texto',
                'votaciones.votos:id,votacion_id,opcion_id,unidad_id',
            ])
            ->withCount('asistencias as presentes_count')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $totalUnidades = Unidad::where('tenant_id', $tenantId)->where('activa', 1)->count();
        $porcentaje    = $totalUnidades > 0
            ? round($asamblea->presentes_count / $totalUnidades * 100, 2)
            : 0;

        $quorumRequerido = (float) ($asamblea->quorum_requerido ?? 50);

        return response()->json([
            'data' => array_merge($asamblea->toArray(), [
                'tipo'      => $asamblea->tipo,
                'orden_dia' => $asamblea->notas ? json_decode($asamblea->notas, true) : [],
                'quorum' => [
                    'total_unidades'   => $totalUnidades,
                    'presentes'        => $asamblea->presentes_count,
                    'porcentaje_actual'=> $porcentaje,
                    'quorum_requerido' => $quorumRequerido,
                    'quorum_alcanzado' => $porcentaje >= $quorumRequerido,
                ],
            ]),
        ]);
    }

    // ── PUT /api/v1/asambleas/:id ─────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'titulo' => 'sometimes|string|max:200',
            'tipo' => 'nullable|in:ordinaria,extraordinaria,urgente',
            'fecha' => 'sometimes|date',
            'lugar' => 'nullable|string|max:200',
            'quorum_requerido' => 'nullable|numeric|between:1,100',
            'estado' => 'nullable|in:convocada,en_curso,realizada,cancelada',
            'acta_url' => 'nullable|string',
            'orden_dia' => 'nullable|array',
            'orden_dia.*' => 'string|max:300',
        ]);

        $payload = array_filter([
            'titulo' => $validated['titulo'] ?? null,
            'tipo' => $validated['tipo'] ?? null,
            'fecha' => $validated['fecha'] ?? null,
            'lugar' => $validated['lugar'] ?? null,
            'quorum_requerido' => $validated['quorum_requerido'] ?? null,
            'estado' => $validated['estado'] ?? null,
            'acta_url' => $validated['acta_url'] ?? null,
        ], fn($v) => !is_null($v));

        if (array_key_exists('orden_dia', $validated)) {
            $payload['notas'] = json_encode($validated['orden_dia']);
        }

        $asamblea->update($payload);

        return response()->json(['data' => $asamblea->fresh(), 'message' => 'Asamblea actualizada.']);
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($asamblea->estado, ['en_curso', 'realizada'])) {
            return response()->json(['message' => 'No se puede eliminar una asamblea en curso o finalizada.'], 422);
        }

        $asamblea->delete();

        return response()->json(['message' => 'Asamblea eliminada.']);
    }

    // ── POST /api/v1/asambleas/:id/asistencia ─────────────────────────────────────
    // Body: { unidad_id, presente, representado_por (nombre del representante) }
    // Upsert: si ya existe para esa unidad en la asamblea, actualiza
    public function registrarAsistencia(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($asamblea->estado, ['realizada', 'cancelada'])) {
            return response()->json(['message' => 'No se puede modificar la asistencia de una asamblea cerrada.'], 422);
        }

        $validated = $request->validate([
            'unidad_id'        => 'required|integer',
            'presente'         => 'nullable|boolean',
            'nombre_asistente' => 'nullable|string|max:150',
            'propietario_id'   => 'nullable|integer',
        ]);

        // Validar unidad pertenece al tenant
        $unidadOk = Unidad::where('id', $validated['unidad_id'])
            ->where('tenant_id', $tenantId)
            ->exists();
        if (!$unidadOk) {
            return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
        }

        // presente=false: eliminar registro en lugar de upsert
        if (isset($validated['presente']) && $validated['presente'] === false) {
            AsambleaAsistencia::where('asamblea_id', $asamblea->id)->where('unidad_id', $validated['unidad_id'])->delete();
            $quorum = $this->calcularQuorum($asamblea->id, $tenantId);
            return response()->json(['quorum' => $quorum, 'message' => 'Asistencia removida.']);
        }

        $asistencia = AsambleaAsistencia::updateOrCreate(
            ['asamblea_id' => $asamblea->id, 'unidad_id' => $validated['unidad_id']],
            [

                'nombre_asistente'     => $validated['nombre_asistente'] ?? null,
                'propietario_id'       => $validated['propietario_id'] ?? null,
            ]
        );

        $quorum = $this->calcularQuorum($asamblea->id, $tenantId);

        return response()->json([
            'data'    => [
                'asistencia' => $asistencia->load('unidad:id,numero'),
                'quorum'     => $quorum,
            ],
            'message' => $asistencia->wasRecentlyCreated ? 'Asistencia registrada.' : 'Asistencia actualizada.',
        ]);
    }

    // ── GET /api/v1/asambleas/:id/quorum ─────────────────────────────────────────
    // Cálculo en tiempo real.
    // Nota: 'coeficiente' no existe en unidades; se usa area_m2 como peso ponderado.
    // total_coeficiente_presentes = suma de area_m2 de unidades presentes (o 1 si area_m2 es null)

    //  POST /api/v1/asambleas/:id/asistencia/bulk 
    // Marcar/desmarcar todas las unidades de un solo golpe.
    public function registrarAsistenciaBulk(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($asamblea->estado, ['realizada', 'cancelada'])) {
            return response()->json(['message' => 'No se puede modificar la asistencia de una asamblea cerrada.'], 422);
        }

        $validated = $request->validate([
            'presente'    => 'required|boolean',
            'unidad_ids'  => 'nullable|array',
            'unidad_ids.*' => 'integer',
        ]);

        $presente  = $validated['presente'];
        $unidadIds = $validated['unidad_ids'] ?? null;

        // Si no se pasan ids especificas, obtener todas las activas del tenant
        if (empty($unidadIds)) {
            $unidadIds = Unidad::where('tenant_id', $tenantId)->where('activa', 1)->pluck('id')->toArray();
        }

        if (!$presente) {
            AsambleaAsistencia::where('asamblea_id', $asamblea->id)
                ->whereIn('unidad_id', $unidadIds)
                ->delete();
        } else {
            $now = now();
            $rows = array_map(fn($uid) => [
                'asamblea_id' => $asamblea->id,
                'unidad_id' => $uid,
                'hora_registro' => $now,
            ], $unidadIds);
            // insertOrIgnore to avoid duplicate key errors
            AsambleaAsistencia::insertOrIgnore($rows);
        }

        $quorum = $this->calcularQuorum($asamblea->id, $tenantId);
        return response()->json([
            'message' => $presente ? 'Todas las unidades marcadas como presentes.' : 'Asistencia limpiada.',
            'quorum'  => $quorum,
        ]);
    }

    // -- PATCH /api/v1/asambleas/:id/estado ------------------------------------------
    public function cambiarEstado(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|in:convocada,en_curso,realizada,cancelada',
        ]);

        $asamblea->update(['estado' => $validated['estado']]);

        return response()->json([
            'data'    => $asamblea->fresh(),
            'message' => 'Estado actualizado.',
        ]);
    }

    public function quorum(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        $quorumRequerido = (float) $request->get('quorum_requerido', 50);
        $quorum          = $this->calcularQuorum($asamblea->id, $tenantId, $quorumRequerido);

        return response()->json(['data' => $quorum]);
    }

    // ── Helper: calcular quórum ───────────────────────────────────────────────────
    private function calcularQuorum(int $asambleaId, int $tenantId, float $quorumRequerido = 50): array
    {
        $totalUnidades = Unidad::where('tenant_id', $tenantId)->where('activa', 1)->count();

        // Unidades presentes con su area_m2 (proxy de coeficiente)
        $presentesUnidades = AsambleaAsistencia::join('unidades', 'unidades.id', '=', 'asamblea_asistencia.unidad_id')
            ->where('asamblea_asistencia.asamblea_id', $asambleaId)
            ->select('unidades.id', 'unidades.area_m2')
            ->get();

        $cantidadPresentes         = $presentesUnidades->count();
        $totalCoeficientePresentes = $presentesUnidades->sum(fn ($u) => (float) ($u->area_m2 ?? 1));
        $totalCoeficientePH        = Unidad::where('tenant_id', $tenantId)
            ->where('activa', 1)
            ->sum('area_m2') ?: $totalUnidades; // fallback a conteo si area_m2 es null

        $porcentajeActual = $totalCoeficientePH > 0
            ? round($totalCoeficientePresentes / $totalCoeficientePH * 100, 2)
            : ($totalUnidades > 0 ? round($cantidadPresentes / $totalUnidades * 100, 2) : 0);

        return [
            'total_unidades'              => $totalUnidades,
            'unidades_presentes'          => $cantidadPresentes,
            'total_coeficiente_presentes' => round($totalCoeficientePresentes, 4),
            'total_coeficiente_ph'        => round($totalCoeficientePH, 4),
            'quorum_requerido'            => $quorumRequerido,
            'porcentaje_actual'           => $porcentajeActual,
            'quorum_alcanzado'            => $porcentajeActual >= $quorumRequerido,
        ];
    }
}
