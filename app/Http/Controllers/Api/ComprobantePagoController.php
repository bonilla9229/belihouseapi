<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComprobantePago;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\PagoCuotaDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ComprobantePagoController extends Controller
{
    // ── GET /api/v1/comprobantes-pago ─────────────────────────────────────────
    // Params: estado (pendiente|aprobado|rechazado), unidad_id, page
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $query = ComprobantePago::with([
                'cuota:id,periodo,concepto_id,monto,estado',
                'unidad:id,numero,torre_id',
                'unidad.torre:id,nombre',
                'residente:id,nombre,apellido',
                'aprobadoPor:id,nombre,apellido',
            ])
            ->where('tenant_id', $tenantId);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('unidad_id')) {
            $query->where('unidad_id', $request->unidad_id);
        }

        $paginado = $query->orderByDesc('created_at')->paginate(20);

        $items = $paginado->getCollection()->map(fn (ComprobantePago $c) => $this->format($c));

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

    // ── POST /api/v1/comprobantes-pago ────────────────────────────────────────
    // Resident submits voucher. multipart/form-data.
    // Body: cuota_id, monto, metodo_pago, referencia?, fecha_pago, comprobante (file)
    public function store(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $request->merge([
            'metodo_pago' => strtolower($request->input('metodo_pago', 'otro')),
            'fecha_pago'  => $request->input('fecha_pago') ?: now()->toDateString(),
        ]);

        $validated = $request->validate([
            'cuota_id'    => 'required|integer|exists:cuotas,id',
            'monto'       => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|in:efectivo,transferencia,cheque,tarjeta,otro',
            'referencia'  => 'nullable|string|max:80',
            'fecha_pago'  => 'required|date',
            'comprobante' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:5120',
        ]);

        // Verify cuota belongs to tenant and is still payable
        $cuota = Cuota::where('tenant_id', $tenantId)
            ->where('id', $validated['cuota_id'])
            ->whereIn('estado', ['pendiente', 'vencida', 'parcial'])
            ->firstOrFail();

        // Store file
        $comprobanteUrl = null;
        if ($request->hasFile('comprobante')) {
            $path = $request->file('comprobante')->store('comprobantes', 'public');
            $comprobanteUrl = Storage::disk('public')->url($path);
        }

        // Get residente_id from the authenticated user if available
        $residenteId = null;
        $user = $request->user();
        if ($user) {
            $residente = \App\Models\Residente::where('tenant_id', $tenantId)
                ->where('unidad_id', $cuota->unidad_id)
                ->first();
            $residenteId = $residente?->id;
        }

        $comprobante = ComprobantePago::create([
            'tenant_id'       => $tenantId,
            'cuota_id'        => $cuota->id,
            'unidad_id'       => $cuota->unidad_id,
            'residente_id'    => $residenteId,
            'metodo_pago'     => $validated['metodo_pago'],
            'referencia'      => $validated['referencia'] ?? null,
            'monto'           => $validated['monto'],
            'fecha_pago'      => $validated['fecha_pago'],
            'comprobante_url' => $comprobanteUrl,
            'estado'          => 'pendiente',
        ]);

        return response()->json([
            'message' => 'Comprobante enviado. Pendiente de aprobación.',
            'data'    => $this->format($comprobante->load([
                'cuota:id,periodo,monto',
                'unidad:id,numero',
            ])),
        ], 201);
    }

    // ── POST /api/v1/comprobantes-pago/{id}/aprobar ───────────────────────────
    // Admin approves: creates pago record + marks cuota as pagada
    public function aprobar(Request $request, $id)
    {
        $tenantId = $request->get('tenant_id');

        $comprobante = ComprobantePago::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('estado', 'pendiente')
            ->firstOrFail();

        $cuota = Cuota::findOrFail($comprobante->cuota_id);

        DB::transaction(function () use ($comprobante, $cuota, $request) {
            $userId = $request->user()?->id;

            // 1. Create the real pago record
            $pago = Pago::create([
                'tenant_id'       => $comprobante->tenant_id,
                'cuota_id'        => $comprobante->cuota_id,
                'unidad_id'       => $comprobante->unidad_id,
                'recibido_por'    => $userId,
                'monto'           => $comprobante->monto,
                'metodo_pago'     => $comprobante->metodo_pago,
                'referencia'      => $comprobante->referencia,
                'fecha_pago'      => $comprobante->fecha_pago,
                'comprobante_url' => $comprobante->comprobante_url,
                'notas'           => 'Aprobado desde comprobante #' . $comprobante->id,
                'anulado'         => false,
            ]);

            // 2. Link pago ↔ cuota
            PagoCuotaDetalle::create([
                'pago_id'         => $pago->id,
                'cuota_id'        => $cuota->id,
                'monto_aplicado'  => $comprobante->monto,
            ]);

            // 3. Mark cuota as pagada
            $cuota->update(['estado' => 'pagada']);

            // 4. Mark comprobante as aprobado
            $comprobante->update([
                'estado'      => 'aprobado',
                'aprobado_por'=> $userId,
                'aprobado_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Pago aprobado. Cuota marcada como pagada.']);
    }

    // ── POST /api/v1/comprobantes-pago/{id}/rechazar ──────────────────────────
    // Admin rejects. Cuota stays in its current state.
    public function rechazar(Request $request, $id)
    {
        $tenantId = $request->get('tenant_id');

        $comprobante = ComprobantePago::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('estado', 'pendiente')
            ->firstOrFail();

        $notaAdmin = $request->input('nota_admin', 'Rechazado por el administrador.');

        $comprobante->update([
            'estado'      => 'rechazado',
            'nota_admin'  => $notaAdmin,
            'aprobado_por'=> $request->user()?->id,
            'aprobado_at' => now(),
        ]);

        return response()->json(['message' => 'Comprobante rechazado.']);
    }

    // ── Private helper ────────────────────────────────────────────────────────
    private function format(ComprobantePago $c): array
    {
        return [
            'id'              => $c->id,
            'cuota_id'        => $c->cuota_id,
            'estado'          => $c->estado,
            'monto'           => (float) $c->monto,
            'metodo_pago'     => $c->metodo_pago,
            'referencia'      => $c->referencia,
            'fecha_pago'      => $c->fecha_pago instanceof \Carbon\Carbon
                ? $c->fecha_pago->toDateString()
                : $c->fecha_pago,
            'comprobante_url' => $c->comprobante_url,
            'nota_admin'      => $c->nota_admin,
            'created_at'      => $c->created_at,
            'aprobado_at'     => $c->aprobado_at,
            'cuota'           => $c->cuota ? [
                'id'      => $c->cuota->id,
                'periodo' => $c->cuota->periodo,
                'monto'   => (float) $c->cuota->monto,
                'estado'  => $c->cuota->estado,
            ] : null,
            'unidad'          => $c->unidad ? [
                'id'     => $c->unidad->id,
                'numero' => $c->unidad->numero,
                'torre'  => $c->unidad->torre?->nombre,
            ] : null,
            'residente'       => $c->residente
                ? trim("{$c->residente->nombre} {$c->residente->apellido}")
                : null,
            'aprobado_por'    => $c->aprobadoPor
                ? trim("{$c->aprobadoPor->nombre} {$c->aprobadoPor->apellido}")
                : null,
        ];
    }
}
