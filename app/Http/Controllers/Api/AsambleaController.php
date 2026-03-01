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
            'tipo'             => null,   // sin columna en schema; aceptado en store pero no persistido
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
            'titulo'            => 'required|string|max:200',
            'tipo'              => 'nullable|in:ordinaria,extraordinaria',
            'descripcion'       => 'nullable|string',
            'fecha'             => 'required|date',
            'lugar'             => 'nullable|string|max:200',
            'quorum_requerido'  => 'nullable|numeric|between:1,100', // informativo, no se persiste
        ]);

        // Combinar tipo + descripción ya que no hay columna tipo
        $desc = $validated['descripcion'] ?? null;
        if (!empty($validated['tipo'])) {
            $desc = '[' . strtoupper($validated['tipo']) . '] ' . ($desc ?? '');
            $desc = trim($desc);
        }

        $asamblea = Asamblea::create([
            'tenant_id'   => $tenantId,
            'titulo'      => $validated['titulo'],
            'descripcion' => $desc,
            'fecha'       => $validated['fecha'],
            'lugar'       => $validated['lugar'] ?? null,
            'estado'      => 'programada',
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
                'asistencias:id,asamblea_id,unidad_id,usuario_id,nombre_representante,presente',
                'asistencias.unidad:id,numero',
                'votaciones:id,asamblea_id,pregunta,estado,cierre_at',
                'votaciones.opciones:id,votacion_id,texto',
            ])
            ->withCount(['asistencias as presentes_count' => fn ($q) => $q->where('presente', true)])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $totalUnidades = Unidad::where('tenant_id', $tenantId)->where('estado', 'activa')->count();
        $porcentaje    = $totalUnidades > 0
            ? round($asamblea->presentes_count / $totalUnidades * 100, 2)
            : 0;

        $quorumRequerido = 50; // default — sin columna quorum_requerido en schema

        return response()->json([
            'data' => array_merge($asamblea->toArray(), [
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
            'titulo'      => 'sometimes|string|max:200',
            'descripcion' => 'nullable|string',
            'fecha'       => 'sometimes|date',
            'lugar'       => 'nullable|string|max:200',
            'estado'      => 'nullable|in:programada,en_curso,finalizada,cancelada',
            'acta'        => 'nullable|string',
        ]);

        $asamblea->update($validated);

        return response()->json(['data' => $asamblea->fresh(), 'message' => 'Asamblea actualizada.']);
    }

    // ── DELETE /api/v1/asambleas/:id ────────────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $asamblea = Asamblea::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($asamblea->estado, ['en_curso', 'finalizada'])) {
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

        $validated = $request->validate([
            'unidad_id'        => 'required|integer',
            'presente'         => 'nullable|boolean',
            'representado_por' => 'nullable|string|max:150', // → columna nombre_representante
            'usuario_id'       => 'nullable|integer',
        ]);

        // Validar unidad pertenece al tenant
        $unidadOk = Unidad::where('id', $validated['unidad_id'])
            ->where('tenant_id', $tenantId)
            ->exists();
        if (!$unidadOk) {
            return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
        }

        $asistencia = AsambleaAsistencia::updateOrCreate(
            ['asamblea_id' => $asamblea->id, 'unidad_id' => $validated['unidad_id']],
            [
                'presente'             => $validated['presente'] ?? true,
                'nombre_representante' => $validated['representado_por'] ?? null,
                'usuario_id'           => $validated['usuario_id'] ?? null,
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
        $totalUnidades = Unidad::where('tenant_id', $tenantId)->where('estado', 'activa')->count();

        // Unidades presentes con su area_m2 (proxy de coeficiente)
        $presentesUnidades = AsambleaAsistencia::join('unidades', 'unidades.id', '=', 'asamblea_asistencia.unidad_id')
            ->where('asamblea_asistencia.asamblea_id', $asambleaId)
            ->where('asamblea_asistencia.presente', true)
            ->select('unidades.id', 'unidades.area_m2')
            ->get();

        $cantidadPresentes         = $presentesUnidades->count();
        $totalCoeficientePresentes = $presentesUnidades->sum(fn ($u) => (float) ($u->area_m2 ?? 1));
        $totalCoeficientePH        = Unidad::where('tenant_id', $tenantId)
            ->where('estado', 'activa')
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
