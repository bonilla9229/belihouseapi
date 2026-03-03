<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaGasto;
use App\Models\Gasto;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GastoController extends Controller
{
    // ── GET /api/v1/gastos ───────────────────────────────────────────────────
    // Params: categoria_id, proveedor_id, mes (YYYY-MM), buscar, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Gasto::with([
                'categoria:id,nombre',
                'proveedor:id,nombre,ruc',
                'aprobadoPor:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId);

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->categoria_id);
        }
        if ($request->filled('proveedor_id')) {
            $query->where('proveedor_id', $request->proveedor_id);
        }
        // Filtro por rango de fechas (desde / hasta)
        if ($request->filled('desde')) {
            $query->where('fecha', '>=', $request->desde);
        }
        if ($request->filled('hasta')) {
            $query->where('fecha', '<=', $request->hasta);
        }
        // Filtro por mes (YYYY-MM) — legacy
        if ($request->filled('mes')) {
            [$anio, $mesNum] = explode('-', $request->mes);
            $query->whereYear('fecha', $anio)->whereMonth('fecha', $mesNum);
        }
        if ($request->filled('buscar')) {
            $b = '%' . $request->buscar . '%';
            $query->where('descripcion', 'like', $b);
        }

        $paginado = $query->orderByDesc('fecha')->paginate(20);

        return response()->json([
            'data' => $paginado->getCollection()->map(fn (Gasto $g) => [
                'id'          => $g->id,
                'descripcion' => $g->descripcion,
                'categoria'   => $g->categoria,
                'proveedor'   => $g->proveedor,
                'monto'       => $g->monto,
                'fecha'       => $g->fecha,
                'metodo_pago' => $g->metodo_pago,
                'referencia'  => $g->referencia,
                'comprobante_url' => $g->comprobante_url,
                'notas'       => $g->notas,
                'registrado_por' => $g->aprobadoPor
                    ? trim("{$g->aprobadoPor->nombre} {$g->aprobadoPor->apellido}")
                    : null,
            ]),
            'meta' => [
                'total'        => $paginado->total(),
                'per_page'     => $paginado->perPage(),
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/gastos ───────────────────────────────────────────────────
    // Body: { categoria_id, proveedor_id, descripcion, monto,
    //         fecha_gasto, referencia, ticket_id (ignorado — sin columna), metodo_pago (ignorado) }
    // referencia → columna comprobante
    // fecha_gasto → columna fecha
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'descripcion'  => 'required|string|max:250',
            'monto'        => 'required|numeric|min:0.01',
            'fecha_gasto'  => 'required|date',
            'categoria_id' => 'nullable|integer',
            'proveedor_id' => 'nullable|integer',
            'ticket_id'    => 'nullable|integer',
            'referencia'   => 'nullable|string|max:80',
            'metodo_pago'  => 'nullable|in:efectivo,transferencia,cheque,tarjeta,otro',
            'notas'        => 'nullable|string',
        ]);

        // Validar categoria pertenece al tenant
        if (!empty($validated['categoria_id'])) {
            $ok = CategoriaGasto::where('id', $validated['categoria_id'])
                ->where('tenant_id', $tenantId)->exists();
            if (!$ok) {
                return response()->json(['message' => 'Categoría no encontrada en este PH.'], 422);
            }
        }

        // Validar proveedor pertenece al tenant
        if (!empty($validated['proveedor_id'])) {
            $ok = Proveedor::where('id', $validated['proveedor_id'])
                ->where('tenant_id', $tenantId)->exists();
            if (!$ok) {
                return response()->json(['message' => 'Proveedor no encontrado en este PH.'], 422);
            }
        }

        $gasto = Gasto::create([
            'tenant_id'    => $tenantId,
            'aprobado_por' => $request->user()->id,
            'categoria_id' => $validated['categoria_id'] ?? null,
            'proveedor_id' => $validated['proveedor_id'] ?? null,
            'ticket_id'    => $validated['ticket_id'] ?? null,
            'descripcion'  => $validated['descripcion'],
            'monto'        => $validated['monto'],
            'fecha'        => $validated['fecha_gasto'],
            'metodo_pago'  => $validated['metodo_pago'] ?? null,
            'referencia'   => $validated['referencia'] ?? null,
            'notas'        => $validated['notas'] ?? null,
        ]);

        return response()->json([
            'data'    => $gasto->load(['categoria:id,nombre', 'proveedor:id,nombre,ruc']),
            'message' => 'Gasto registrado.',
        ], 201);
    }

    // ── GET /api/v1/gastos/:id ───────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $gasto = Gasto::with([
                'categoria:id,nombre',
                'proveedor:id,nombre,ruc,contacto',
                'aprobadoPor:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $gasto]);
    }

    // ── PUT /api/v1/gastos/:id ───────────────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $gasto    = Gasto::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'descripcion'  => 'sometimes|string|max:250',
            'monto'        => 'sometimes|numeric|min:0.01',
            'fecha_gasto'  => 'sometimes|date',
            'categoria_id' => 'nullable|integer',
            'proveedor_id' => 'nullable|integer',
            'ticket_id'    => 'nullable|integer',
            'referencia'   => 'nullable|string|max:80',
            'metodo_pago'  => 'nullable|in:efectivo,transferencia,cheque,tarjeta,otro',
            'notas'        => 'nullable|string',
        ]);

        // Validar categoria pertenece al tenant
        if (!empty($validated['categoria_id'])) {
            $ok = CategoriaGasto::where('id', $validated['categoria_id'])
                ->where('tenant_id', $tenantId)->exists();
            if (!$ok) {
                return response()->json(['message' => 'Categoría no encontrada en este PH.'], 422);
            }
        }

        // Validar proveedor pertenece al tenant
        if (!empty($validated['proveedor_id'])) {
            $ok = Proveedor::where('id', $validated['proveedor_id'])
                ->where('tenant_id', $tenantId)->exists();
            if (!$ok) {
                return response()->json(['message' => 'Proveedor no encontrado en este PH.'], 422);
            }
        }

        $payload = [];
        if (isset($validated['descripcion']))  $payload['descripcion']  = $validated['descripcion'];
        if (isset($validated['monto']))        $payload['monto']        = $validated['monto'];
        if (isset($validated['fecha_gasto']))  $payload['fecha']        = $validated['fecha_gasto'];
        if (array_key_exists('categoria_id', $validated)) $payload['categoria_id'] = $validated['categoria_id'];
        if (array_key_exists('proveedor_id', $validated)) $payload['proveedor_id'] = $validated['proveedor_id'];
        if (array_key_exists('ticket_id',    $validated)) $payload['ticket_id']    = $validated['ticket_id'];
        if (array_key_exists('referencia',   $validated)) $payload['referencia']   = $validated['referencia'];
        if (isset($validated['metodo_pago'])) $payload['metodo_pago'] = $validated['metodo_pago'];
        if (array_key_exists('notas',        $validated)) $payload['notas']        = $validated['notas'];

        $gasto->update($payload);

        return response()->json([
            'data'    => $gasto->fresh(['categoria:id,nombre', 'proveedor:id,nombre,ruc']),
            'message' => 'Gasto actualizado.',
        ]);
    }

    // ── DELETE /api/v1/gastos/:id ─────────────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $gasto    = Gasto::where('tenant_id', $tenantId)->findOrFail($id);
        $gasto->delete();

        return response()->json(['message' => 'Gasto eliminado.']);
    }

    // ── GET /api/v1/gastos/resumen ────────────────────────────────────────────────
    // Params: desde (YYYY-MM-DD) + hasta (YYYY-MM-DD)  OR  mes (YYYY-MM, default mes actual)
    public function resumen(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        // Build date range: prefer desde/hasta, fall back to mes
        if ($request->filled('desde') && $request->filled('hasta')) {
            $desde = $request->get('desde');
            $hasta = $request->get('hasta');
            $mes   = substr($desde, 0, 7); // used only for response label
        } else {
            $mes   = $request->get('mes', now()->format('Y-m'));
            [$anio, $mesNum] = explode('-', $mes);
            $desde = "{$anio}-{$mesNum}-01";
            $hasta = now()->createFromDate($anio, $mesNum, 1)->endOfMonth()->toDateString();
        }

        $applyRange = function ($q) use ($desde, $hasta) {
            return $q->where('fecha', '>=', $desde)->where('fecha', '<=', $hasta);
        };

        // Todas las categorías del tenant
        $categorias = CategoriaGasto::where('tenant_id', $tenantId)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'presupuesto_mensual']);

        // Gastos agrupados por categoría en el rango (excluye sin categoría)
        $gastos = $applyRange(
            Gasto::where('tenant_id', $tenantId)->whereNotNull('categoria_id')
        )
            ->select('categoria_id', DB::raw('SUM(monto) as gastado'), DB::raw('COUNT(*) as cantidad'))
            ->groupBy('categoria_id')
            ->get()
            ->keyBy('categoria_id');

        $totalMes = (float) $applyRange(Gasto::where('tenant_id', $tenantId))->sum('monto');

        $categoriaResumen = $categorias->map(function (CategoriaGasto $cat) use ($gastos) {
            $gastado             = (float) ($gastos[$cat->id]->gastado ?? 0);
            $presupuesto_mensual = (float) $cat->presupuesto_mensual;

            return [
                'id'                  => $cat->id,
                'nombre'              => $cat->nombre,
                'presupuesto_mensual' => $presupuesto_mensual,
                'gastado'             => $gastado,
                'cantidad'            => (int) ($gastos[$cat->id]->cantidad ?? 0),
                'porcentaje'          => $presupuesto_mensual > 0 ? round(($gastado / $presupuesto_mensual) * 100, 1) : null,
                'excedido'            => $presupuesto_mensual > 0 && $gastado > $presupuesto_mensual,
            ];
        });

        // Gastos sin categoría
        $sinCategoria = (float) $applyRange(
            Gasto::where('tenant_id', $tenantId)->whereNull('categoria_id')
        )->sum('monto');

        return response()->json([
            'data' => [
                'mes'          => $mes,
                'total_mes'    => $totalMes,
                'categorias'   => $categoriaResumen,
                'sin_categoria'=> $sinCategoria,
            ],
        ]);
    }

    // ── GET /api/v1/categorias-gasto ───────────────────────────────────────────────
    public function categorias(Request $request)
    {
        $tenantId   = $request->get('tenant_id');
        $categorias = CategoriaGasto::where('tenant_id', $tenantId)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'presupuesto_mensual', 'activa']);

        return response()->json(['data' => $categorias]);
    }

    // ── POST /api/v1/categorias-gasto ─────────────────────────────────────────────
    // Body: { nombre, presupuesto_mensual, activa }
    public function storeCategorias(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'nombre'              => 'required|string|max:100',
            'presupuesto_mensual' => 'nullable|numeric|min:0',
            'activa'              => 'nullable|boolean',
        ]);

        $categoria = CategoriaGasto::create([
            'tenant_id'           => $tenantId,
            'nombre'              => $validated['nombre'],
            'presupuesto_mensual' => $validated['presupuesto_mensual'] ?? null,
            'activa'              => $validated['activa'] ?? true,
        ]);

        return response()->json(['data' => $categoria, 'message' => 'Categoría creada.'], 201);
    }

    // -- PUT /api/v1/categorias-gasto/{id} -----------------------------------
    public function updateCategoria(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $categoria = CategoriaGasto::where('tenant_id', $tenantId)->findOrFail($id);
        $validated = $request->validate([
            'nombre'              => 'sometimes|string|max:100',
            'presupuesto_mensual' => 'nullable|numeric|min:0',
            'activa'              => 'nullable|boolean',
        ]);
        $categoria->update($validated);
        return response()->json(['data' => $categoria->fresh(), 'message' => 'Categoria actualizada.']);
    }

    // -- DELETE /api/v1/categorias-gasto/{id} --------------------------------
    public function destroyCategoria(Request $request, string $id)
    {
        $tenantId  = $request->get('tenant_id');
        $categoria = CategoriaGasto::where('tenant_id', $tenantId)->findOrFail($id);
        if ($categoria->gastos()->exists()) {
            $categoria->update(['activa' => false]);
            return response()->json(['message' => 'Categoria desactivada (tiene gastos asociados).']);
        }
        $categoria->delete();
        return response()->json(['message' => 'Categoria eliminada.']);
    }
}