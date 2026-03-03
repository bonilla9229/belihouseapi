<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AreaCatalogo;
use App\Models\AreaComun;
use App\Models\HorarioArea;
use App\Models\PropietarioUnidad;
use App\Models\Reserva;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReservaController extends Controller
{
    // â”€â”€ GET /api/v1/reservas â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Params: area_id, estado, fecha_desde, fecha_hasta, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Reserva::with([
                'area:id,nombre,catalogo_id',
                'unidad:id,numero',
            ])
            ->where('reservas.tenant_id', $tenantId);

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->fecha_hasta);
        }
        if ($request->filled('unidad_id')) {
            $query->where('reservas.unidad_id', $request->unidad_id);
        }

        $paginado = $query->orderByDesc('fecha')->orderBy('hora_inicio')->paginate(20);

        // Propietario activo de cada unidad en batch
        $unidadIds      = $paginado->pluck('unidad_id')->filter()->unique()->values();
        $propsPorUnidad = PropietarioUnidad::with('propietario:id,nombre,apellido')
            ->whereIn('unidad_id', $unidadIds)
            ->where('activo', true)
            ->get()
            ->keyBy('unidad_id');

        $items = $paginado->getCollection()->map(function (Reserva $r) use ($propsPorUnidad) {
            $prop = $r->unidad_id ? ($propsPorUnidad[$r->unidad_id]?->propietario ?? null) : null;

            return [
                'id'           => $r->id,
                'area_id'      => $r->area_id,
                'area'         => $r->area?->nombre,
                'unidad'       => $r->unidad?->numero,
                'propietario'  => $prop ? trim("{$prop->nombre} {$prop->apellido}") : null,
                'fecha'        => \Carbon\Carbon::parse($r->fecha)->toDateString(),
                'hora_inicio'  => $r->hora_inicio,
                'hora_fin'     => $r->hora_fin,
                'num_personas' => $r->num_personas,
                'estado'       => $r->estado,
                'notas'        => $r->notas,
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

    // â”€â”€ POST /api/v1/reservas â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Body: { area_id, unidad_id, fecha_reserva, hora_inicio, hora_fin }
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'area_id'       => 'required|integer',
            'unidad_id'     => 'nullable|integer',
            'fecha_reserva' => 'required|date|after_or_equal:today',
            'hora_inicio'   => 'required|date_format:H:i',
            'hora_fin'      => 'required|date_format:H:i|after:hora_inicio',
            'num_personas'  => 'nullable|integer|min:1',
            'notas'         => 'nullable|string',
        ]);

        // 1. Verificar que el area pertenezca al tenant y este activa
        $area = AreaComun::where('id', $validated['area_id'])
            ->where('tenant_id', $tenantId)
            ->where('activa', true)
            ->first();

        if (!$area) {
            return response()->json(['message' => 'Ãrea comÃºn no encontrada o inactiva.'], 422);
        }

        // 2. Verificar unidad pertenece al tenant
        if (!empty($validated['unidad_id'])) {
            $unidadOk = \Illuminate\Support\Facades\DB::table('unidades')
                ->where('id', $validated['unidad_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (!$unidadOk) {
                return response()->json(['message' => 'Unidad no encontrada en este PH.'], 422);
            }
        }

        // 3. Verificar que la fecha/hora estÃ© dentro del horario del Ã¡rea para ese dÃ­a de semana
        $diaSemana = Carbon::parse($validated['fecha_reserva'])->dayOfWeek; // 0=Dom ... 6=SÃ¡b
        $horarioDelDia = HorarioArea::where('area_id', $area->id)
            ->where('dia_semana', $diaSemana)
            ->first();

        if (!$horarioDelDia) {
            return response()->json([
                'message' => 'El Ã¡rea no tiene horario disponible para ese dÃ­a de la semana.',
            ], 422);
        }

        if ($validated['hora_inicio'] < $horarioDelDia->hora_inicio ||
            $validated['hora_fin']    > $horarioDelDia->hora_fin) {
            return response()->json([
                'message' => "El horario solicitado estÃ¡ fuera del rango permitido "
                           . "({$horarioDelDia->hora_inicio} â€“ {$horarioDelDia->hora_fin}).",
            ], 422);
        }

        // 4. Verificar solapamiento con reservas activas en ese Ã¡rea y fecha
        $solapada = Reserva::where('area_id', $area->id)
            ->whereDate('fecha', $validated['fecha_reserva'])
            ->whereNotIn('estado', ['rechazada', 'cancelada'])
            ->where(function ($q) use ($validated) {
                $hi = $validated['hora_inicio'];
                $hf = $validated['hora_fin'];
                // Solapamiento: NOT (nueva_fin <= existente_inicio OR nueva_inicio >= existente_fin)
                $q->where('hora_inicio', '<', $hf)
                  ->where('hora_fin',    '>',  $hi);
            })
            ->exists();

        if ($solapada) {
            return response()->json(['message' => 'El horario ya estÃ¡ ocupado por otra reserva.'], 422);
        }

        // 5. Crear reserva
        $reserva = Reserva::create([
            'tenant_id'    => $tenantId,
            'area_id'      => $area->id,
            'unidad_id'    => $validated['unidad_id'] ?? null,
            'residente_id' => null,
            'fecha'        => $validated['fecha_reserva'],
            'hora_inicio'  => $validated['hora_inicio'],
            'hora_fin'     => $validated['hora_fin'],
            'num_personas' => $validated['num_personas'] ?? null,
            'estado'       => 'pendiente',
            'notas'        => $validated['notas'] ?? null,
        ]);

        return response()->json([
            'data'    => $reserva->load(['area:id,nombre,capacidad', 'unidad:id,numero']),
            'message' => 'Reserva creada. Pendiente de aprobaciÃ³n.',
        ], 201);
    }

    // â”€â”€ GET /api/v1/reservas/:id â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $reserva = Reserva::with([
                'area:id,nombre,descripcion,capacidad',
                'unidad:id,numero',
                'residente:id,nombre,apellido,telefono',
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $reserva]);
    }

    /** PUT /reservas/{id} */
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $reserva = Reserva::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($reserva->estado, ['aprobada', 'rechazada'])) {
            return response()->json(['message' => 'No se puede editar una reserva ya procesada.'], 422);
        }

        $validated = $request->validate([
            'fecha'       => 'sometimes|date',
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin'    => 'sometimes|date_format:H:i',
            'notas'       => 'nullable|string',
        ]);

        $reserva->update($validated);

        return response()->json(['data' => $reserva->fresh(['area', 'unidad']), 'message' => 'Reserva actualizada.']);
    }

    /** DELETE /reservas/{id} */
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $reserva = Reserva::where('tenant_id', $tenantId)->findOrFail($id);
        $reserva->update(['estado' => 'cancelada']);

        return response()->json(['message' => 'Reserva cancelada.']);
    }

    // â”€â”€ POST /api/v1/reservas/:id/aprobar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function aprobar(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $reserva  = Reserva::where('tenant_id', $tenantId)
            ->whereIn('estado', ['pendiente'])
            ->findOrFail($id);

        $reserva->update(['estado' => 'aprobada']);

        return response()->json([
            'data'    => $reserva->fresh(['area:id,nombre', 'unidad:id,numero']),
            'message' => 'Reserva aprobada.',
        ]);
    }

    // â”€â”€ POST /api/v1/reservas/:id/rechazar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Body: { motivo }
    public function rechazar(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $reserva  = Reserva::where('tenant_id', $tenantId)
            ->whereIn('estado', ['pendiente'])
            ->findOrFail($id);

        $validated = $request->validate([
            'motivo' => 'required|string|max:500',
        ]);

        $reserva->update([
            'estado' => 'rechazada',
            'notas'  => $validated['motivo'],
        ]);

        return response()->json([
            'data'    => $reserva->fresh(['area:id,nombre', 'unidad:id,numero']),
            'message' => 'Reserva rechazada.',
        ]);
    }

    // â”€â”€ GET /api/v1/areas-comunes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Lista Ã¡reas activas con sus horarios por dÃ­a de semana
    // -- GET /api/v1/areas-catalogo -----------------------------------------------
    // Catalogo global de tipos de areas comunes (sin tenant)
    public function catalogoIndex()
    {
        $catalogo = AreaCatalogo::orderBy('id')->get();
        return response()->json(['data' => $catalogo]);
    }

    // -- GET /api/v1/areas-comunes ------------------------------------------------
    // Lista areas activas con sus horarios por dia de semana
    public function areas(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $areas = AreaComun::with(['horarios' => fn ($q) => $q->orderBy('dia_semana')])
            ->where('tenant_id', $tenantId)
            ->orderBy('nombre')
            ->get(['id', 'catalogo_id', 'nombre', 'descripcion', 'capacidad', 'costo', 'requiere_pago', 'hora_inicio', 'hora_fin', 'activa'])
            ->map(function ($a) {
                // Normalize TIME columns: MySQL returns "HH:MM:SS", frontend wants "HH:MM"
                $a->hora_inicio = $a->hora_inicio ? substr($a->hora_inicio, 0, 5) : null;
                $a->hora_fin    = $a->hora_fin    ? substr($a->hora_fin,    0, 5) : null;
                return $a;
            });

        return response()->json(['data' => $areas]);
    }

    // â”€â”€ GET /api/v1/areas-comunes/:id/horarios â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function horarios(Request $request, string $areaId)
    {
        $tenantId = $request->get('tenant_id');
        $area     = AreaComun::where('tenant_id', $tenantId)->findOrFail($areaId);

        $horarios = HorarioArea::where('area_id', $area->id)
            ->orderBy('dia_semana')
            ->get(['id', 'dia_semana', 'hora_inicio', 'hora_fin']);

        return response()->json(['data' => $horarios]);
    }

    // â”€â”€ GET /api/v1/areas-comunes/:id/disponibilidad?fecha=YYYY-MM-DD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Retorna horarios del Ã¡rea para ese dÃ­a + bloques ya reservados
    public function disponibilidad(Request $request, string $areaId)
    {
        $tenantId = $request->get('tenant_id');
        $request->validate(['fecha' => 'required|date']);

        $area = AreaComun::where('tenant_id', $tenantId)->findOrFail($areaId);

        $diaSemana    = Carbon::parse($request->fecha)->dayOfWeek;
        $horarioDelDia = HorarioArea::where('area_id', $area->id)
            ->where('dia_semana', $diaSemana)
            ->first(['id', 'dia_semana', 'hora_inicio', 'hora_fin']);

        $reservasActivas = Reserva::where('area_id', $area->id)
            ->whereDate('fecha', $request->fecha)
            ->whereNotIn('estado', ['rechazada', 'cancelada'])
            ->get(['id', 'unidad_id', 'hora_inicio', 'hora_fin', 'estado']);

        return response()->json([
            'data' => [
                'area'            => ['id' => $area->id, 'nombre' => $area->nombre, 'capacidad' => $area->capacidad],
                'fecha'           => $request->fecha,
                'dia_semana'      => $diaSemana,
                'horario'         => $horarioDelDia,           // null si el Ã¡rea no abre ese dÃ­a
                'reservas'        => $reservasActivas,
                'disponible'      => $horarioDelDia && $reservasActivas->isEmpty(),
            ],
        ]);
    }

    // â”€â”€ POST /api/v1/areas-comunes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Body: { nombre, descripcion?, capacidad?, activa? }
    // Si envias horarios[]: [{ dia_semana(0-6), hora_inicio, hora_fin }] se crean junto al Ã¡rea
    public function storeArea(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'catalogo_id'            => 'required|integer|exists:areas_catalogo,id',
            'descripcion'            => 'nullable|string',
            'capacidad'              => 'nullable|integer|min:1',
            'costo'                  => 'nullable|numeric|min:0',
            'requiere_pago'          => 'nullable|boolean',
            'hora_inicio'            => 'nullable|date_format:H:i,H:i:s',
            'hora_fin'               => 'nullable|date_format:H:i,H:i:s',
            'activa'                 => 'nullable|boolean',
            'horarios'               => 'nullable|array',
            'horarios.*.dia_semana'  => 'required_with:horarios|integer|between:0,6',
            'horarios.*.hora_inicio' => 'required_with:horarios|date_format:H:i',
            'horarios.*.hora_fin'    => 'required_with:horarios|date_format:H:i',
        ]);

        // Nombre siempre viene del catalogo
        $catalogo = AreaCatalogo::findOrFail($validated['catalogo_id']);
        $nombre   = $catalogo->nombre;

        $area = AreaComun::create([
            'tenant_id'    => $tenantId,
            'catalogo_id'  => $validated['catalogo_id'],
            'nombre'       => $nombre,
            'descripcion'  => $validated['descripcion']   ?? $catalogo?->descripcion,
            'capacidad'    => $validated['capacidad']     ?? null,
            'costo'        => $validated['costo']         ?? 0,
            'requiere_pago'=> $validated['requiere_pago'] ?? (($validated['costo'] ?? 0) > 0 ? 1 : 0),
            'hora_inicio'  => $validated['hora_inicio']   ?? null,
            'hora_fin'     => $validated['hora_fin']      ?? null,
            'activa'       => $validated['activa']        ?? true,
        ]);

        if (!empty($validated['horarios'])) {
            foreach ($validated['horarios'] as $h) {
                $area->horarios()->create([
                    'dia_semana'  => $h['dia_semana'],
                    'hora_inicio' => $h['hora_inicio'],
                    'hora_fin'    => $h['hora_fin'],
                ]);
            }
        }

        return response()->json([
            'data'    => $area->load(['horarios', 'catalogo']),
            'message' => 'Ãrea comÃºn creada.',
        ], 201);
    }

    // â”€â”€ PUT /api/v1/areas-comunes/:id â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function updateArea(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $area     = AreaComun::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'nombre'        => 'sometimes|nullable|string|max:100',
            'descripcion'   => 'nullable|string',
            'capacidad'     => 'nullable|integer|min:1',
            'costo'         => 'nullable|numeric|min:0',
            'requiere_pago' => 'nullable|boolean',
            'hora_inicio'   => 'nullable|date_format:H:i,H:i:s',
            'hora_fin'      => 'nullable|date_format:H:i,H:i:s',
            'activa'        => 'nullable|boolean',
            'dias'          => 'nullable|array',
            'dias.*'        => 'integer|between:1,7',
        ]);

        // Normalize time: strip seconds if sent as "HH:MM:SS"
        foreach (['hora_inicio', 'hora_fin'] as $tf) {
            if (!empty($validated[$tf])) {
                $validated[$tf] = substr($validated[$tf], 0, 5);
            }
        }

        if (isset($validated['costo']) && !isset($validated['requiere_pago'])) {
            $validated['requiere_pago'] = $validated['costo'] > 0 ? 1 : 0;
        }

        $dias        = $validated['dias'] ?? null;
        $horaInicio  = $validated['hora_inicio'] ?? $area->hora_inicio;
        $horaFin     = $validated['hora_fin']    ?? $area->hora_fin;

        $areaFields = collect($validated)->except(['dias'])->toArray();
        $area->update($areaFields);

        // Sync horarios cuando se envÃ­a el array de dÃ­as (incluso vacÃ­o = borrar todos)
        if ($dias !== null && $horaInicio && $horaFin) {
            // Frontend usa 1=Lunâ€¦7=Dom (ISO); Carbon/DB usa 0=Domâ€¦6=SÃ¡b â†’ 7â†’0, resto igual
            $carbonDias = array_map(fn($d) => $d === 7 ? 0 : $d, $dias);

            // Borrar dÃ­as no seleccionados
            HorarioArea::where('area_id', $area->id)
                ->whereNotIn('dia_semana', $carbonDias)
                ->delete();

            // Upsert dÃ­as seleccionados
            foreach ($carbonDias as $dia) {
                HorarioArea::updateOrCreate(
                    ['area_id' => $area->id, 'dia_semana' => $dia],
                    ['hora_inicio' => $horaInicio, 'hora_fin' => $horaFin]
                );
            }
        }

        return response()->json([
            'data'    => $area->fresh('horarios'),
            'message' => 'Ãrea comÃºn actualizada.',
        ]);
    }

    // â”€â”€ DELETE /api/v1/areas-comunes/:id (soft delete â†’ activa = false) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Error 422 si tiene reservas pendientes o aprobadas
    public function destroyArea(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $area     = AreaComun::where('tenant_id', $tenantId)->findOrFail($id);

        if ($area->reservas()->whereIn('estado', ['pendiente', 'aprobada'])->exists()) {
            return response()->json([
                'message' => 'No se puede desactivar: el Ã¡rea tiene reservas activas.',
            ], 422);
        }

        $area->update(['activa' => false]);

        return response()->json(['message' => 'Ãrea comÃºn desactivada.']);
    }

    // â”€â”€ POST /api/v1/areas-comunes/:id/horarios â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Body: { dia_semana(0=Dom..6=SÃ¡b), hora_inicio, hora_fin }
    // Upsert: si ya existe horario para ese dÃ­a en esa Ã¡rea, lo actualiza
    public function storeHorario(Request $request, string $areaId)
    {
        $tenantId = $request->get('tenant_id');
        $area     = AreaComun::where('tenant_id', $tenantId)->findOrFail($areaId);

        $validated = $request->validate([
            'dia_semana'  => 'required|integer|between:0,6',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin'    => 'required|date_format:H:i|after:hora_inicio',
        ]);

        $horario = HorarioArea::updateOrCreate(
            ['area_id' => $area->id, 'dia_semana' => $validated['dia_semana']],
            ['hora_inicio' => $validated['hora_inicio'], 'hora_fin' => $validated['hora_fin']]
        );

        return response()->json([
            'data'    => $horario,
            'message' => 'Horario guardado.',
        ], 201);
    }

    // â”€â”€ PUT /api/v1/horarios/:id â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function updateHorario(Request $request, string $horarioId)
    {
        $tenantId = $request->get('tenant_id');

        $horario = HorarioArea::whereHas('area', fn ($q) => $q->where('tenant_id', $tenantId))
            ->findOrFail($horarioId);

        $validated = $request->validate([
            'hora_inicio' => 'sometimes|required|date_format:H:i',
            'hora_fin'    => 'sometimes|required|date_format:H:i',
        ]);

        $horario->update($validated);

        return response()->json(['data' => $horario->fresh(), 'message' => 'Horario actualizado.']);
    }

    // â”€â”€ DELETE /api/v1/horarios/:id â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function destroyHorario(Request $request, string $horarioId)
    {
        $tenantId = $request->get('tenant_id');

        $horario = HorarioArea::whereHas('area', fn ($q) => $q->where('tenant_id', $tenantId))
            ->findOrFail($horarioId);

        $horario->delete();

        return response()->json(['message' => 'Horario eliminado.']);
    }
}



