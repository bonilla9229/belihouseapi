<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\PagoCuotaDetalle;
use App\Models\PropietarioUnidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoController extends Controller
{
    // ── GET /api/v1/pagos ─────────────────────────────────────────────────────────
    // Params: unidad_id, mes (YYYY-MM), metodo_pago, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = Pago::with([
                'unidad:id,numero,torre_id',
                'unidad.torre:id,nombre',
                'recibidoPor:id,nombre,apellido',
                'cuotas:id,periodo,concepto_id,monto,mora',
            ])
            ->where('pagos.tenant_id', $tenantId);

        if ($request->filled('unidad_id')) {
            $query->where('unidad_id', $request->unidad_id);
        }

        // Filtro mes: pagos cuyo fecha_pago caiga en ese mes
        if ($request->filled('mes')) {
            $query->whereRaw("DATE_FORMAT(fecha_pago, '%Y-%m') = ?", [$request->mes]);
        }

        // metodo_pago → columna metodo_pago
        if ($request->filled('metodo_pago')) {
            $query->where('metodo_pago', $request->metodo_pago);
        }

        $paginado = $query->orderByDesc('fecha_pago')->orderByDesc('pagos.id')->paginate(20);

        // Cargar propietario activo de cada unidad en batch
        $unidadIds      = $paginado->pluck('unidad_id')->unique()->values();
        $propsPorUnidad = PropietarioUnidad::with('propietario:id,nombre,apellido,telefono')
            ->whereIn('unidad_id', $unidadIds)
            ->where('activo', true)
            ->get()
            ->keyBy('unidad_id');

        $items = $paginado->getCollection()->map(function (Pago $p) use ($propsPorUnidad) {
            $prop = $propsPorUnidad[$p->unidad_id]?->propietario ?? null;

            return [
                'id'           => $p->id,
                'unidad'       => $p->unidad ? [
                    'id'     => $p->unidad->id,
                    'numero' => $p->unidad->numero,
                    'torre'  => $p->unidad->torre?->nombre,
                ] : null,
                'propietario'  => $prop ? trim("{$prop->nombre} {$prop->apellido}") : null,
                'cuotas'       => $p->cuotas->map(fn ($c) => [
                    'id'              => $c->id,
                    'periodo'         => $c->periodo,
                    'monto'           => (float) $c->monto,
                    'mora'            => (float) $c->mora,
                    'monto_aplicado'  => (float) $c->pivot->monto_aplicado,
                ]),
                'monto_total'  => (float) $p->monto,
                'metodo_pago'  => $p->metodo_pago,
                'referencia'   => $p->referencia,
                'fecha_pago'   => $p->fecha_pago,
                'notas'        => $p->notas,
                'anulado'      => $p->anulado,
                'registrado_por' => $p->recibidoPor
                    ? trim("{$p->recibidoPor->nombre} {$p->recibidoPor->apellido}")
                    : null,
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

    // ── POST /api/v1/pagos ────────────────────────────────────────────────────────
    // Body: { unidad_id, cuota_ids[], monto_total, metodo_pago,
    //         referencia, fecha_pago, notas }
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        // Normalize metodo_pago to lowercase and default fecha_pago to today
        $request->merge([
            'metodo_pago' => strtolower($request->input('metodo_pago', '')),
            'fecha_pago'  => $request->input('fecha_pago') ?: now()->toDateString(),
        ]);

        $validated = $request->validate([
            'cuota_id'   => 'required|integer|exists:cuotas,id',
            'monto'      => 'required|numeric|min:0.01',
            'metodo_pago'=> 'required|in:efectivo,transferencia,cheque,tarjeta,otro',
            'referencia' => 'nullable|string|max:80',
            'fecha_pago' => 'required|date',
            'notas'      => 'nullable|string',
        ]);

        // Cargar cuota y validar que pertenece al tenant y está pendiente/vencida
        $cuota = Cuota::where('tenant_id', $tenantId)
            ->where('id', $validated['cuota_id'])
            ->whereIn('estado', ['pendiente', 'vencida', 'parcial'])
            ->first();

        if (!$cuota) {
            return response()->json([
                'message' => 'La cuota no existe, no pertenece a este PH, o ya fue pagada.',
            ], 422);
        }

        // Calcular saldo pendiente (monto - lo ya pagado en cuotas anteriores)
        $yaPagado = PagoCuotaDetalle::where('cuota_id', $cuota->id)->sum('monto_aplicado');
        $saldo    = round((float) $cuota->monto - (float) $yaPagado, 2);

        if ((float) $validated['monto'] > $saldo + 0.001) {
            return response()->json([
                'message' => "El monto ingresado ($" . number_format($validated['monto'], 2) . ") supera el saldo pendiente de la cuota ($" . number_format($saldo, 2) . ").",
            ], 422);
        }

        return DB::transaction(function () use ($validated, $tenantId, $cuota, $request, $yaPagado) {
            $pago = Pago::create([
                'tenant_id'   => $tenantId,
                'unidad_id'   => $cuota->unidad_id,
                'cuota_id'    => $cuota->id,
                'recibido_por'=> $request->user()?->id ?? null,
                'monto'       => $validated['monto'],
                'metodo_pago' => $validated['metodo_pago'],
                'referencia'  => $validated['referencia'] ?? null,
                'fecha_pago'  => $validated['fecha_pago'],
                'notas'       => $validated['notas'] ?? null,
                'anulado'     => false,
            ]);

            PagoCuotaDetalle::create([
                'pago_id'        => $pago->id,
                'cuota_id'       => $cuota->id,
                'monto_aplicado' => $validated['monto'],
            ]);

            // Determinar nuevo estado: pagada si se cubre el total, parcial si no
            $totalPagado = round((float) $yaPagado + (float) $validated['monto'], 2);
            $nuevoEstado = $totalPagado >= (float) $cuota->monto ? 'pagada' : 'parcial';
            $cuota->update(['estado' => $nuevoEstado]);

            return response()->json([
                'data'    => $pago->load(['unidad:id,numero', 'cuotas:id,periodo,monto,mora']),
                'message' => 'Pago registrado.',
            ], 201);
        });
    }

    // ── GET /api/v1/pagos/:id ─────────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $pago = Pago::with([
                'unidad:id,numero,torre_id',
                'unidad.torre:id,nombre',
                'cuotas:id,periodo,concepto_id,monto,mora,estado',
                'cuotas.concepto:id,nombre',
                'recibidoPor:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        // Propietario activo de la unidad
        $propPivot = PropietarioUnidad::with('propietario:id,nombre,apellido,cedula,telefono')
            ->where('unidad_id', $pago->unidad_id)
            ->where('activo', true)
            ->first();

        return response()->json([
            'data' => array_merge($pago->toArray(), [
                'propietario' => $propPivot?->propietario ?? null,
            ]),
        ]);
    }

    /** PUT /pagos/{id} */
    public function update(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');

        $pago = Pago::where('tenant_id', $tenantId)->findOrFail($id);

        if ($pago->anulado) {
            return response()->json(['message' => 'No se puede editar un pago anulado.'], 422);
        }

        $validated = $request->validate([
            'metodo_pago' => 'sometimes|in:efectivo,transferencia,cheque,tarjeta',
            'referencia'  => 'nullable|string|max:100',
            'fecha_pago'  => 'sometimes|date',
            'notas'       => 'nullable|string',
        ]);

        $pago->update($validated);

        return response()->json(['data' => $pago->fresh('unidad'), 'message' => 'Pago actualizado.']);
    }

    // ── DELETE /api/v1/pagos/:id ─────────────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $pago     = Pago::where('tenant_id', $tenantId)->findOrFail($id);

        if ($pago->anulado) {
            return response()->json(['message' => 'El pago ya está anulado.'], 422);
        }

        return DB::transaction(function () use ($pago) {
            $cuotaIds = PagoCuotaDetalle::where('pago_id', $pago->id)->pluck('cuota_id');
            Cuota::whereIn('id', $cuotaIds)->update(['estado' => 'pendiente']);
            PagoCuotaDetalle::where('pago_id', $pago->id)->delete();
            $pago->delete();

            return response()->json(['message' => 'Pago eliminado y cuotas revertidas.']);
        });
    }

    // ── POST /api/v1/pagos/:id/anular ────────────────────────────────────────────
    // Marca pago como anulado, revierte cuotas a pendiente, elimina detalles.
    public function anular(Request $request, string $id)
    {
        $tenantId = $request->get('tenant_id');
        $pago     = Pago::where('tenant_id', $tenantId)->findOrFail($id);

        if ($pago->anulado) {
            return response()->json(['message' => 'El pago ya está anulado.'], 422);
        }

        return DB::transaction(function () use ($pago) {
            // Revertir cuotas antes de eliminar el detalle
            $cuotaIds = PagoCuotaDetalle::where('pago_id', $pago->id)
                ->pluck('cuota_id');

            Cuota::whereIn('id', $cuotaIds)
                ->update(['estado' => 'pendiente']);

            // Eliminar registros de detalle
            PagoCuotaDetalle::where('pago_id', $pago->id)->delete();

            $pago->update(['anulado' => true]);

            return response()->json([
                'data'    => $pago->fresh(),
                'message' => 'Pago anulado y cuotas revertidas a pendiente.',
            ]);
        });
    }
}
