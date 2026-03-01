<?php

namespace App\Http\Requests\Cuotas;

use App\Http\Requests\BaseRequest;
use App\Models\ConceptoCobro;
use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * Validación para POST /api/v1/cuotas/generar
 *
 * Reglas:
 *  - mes:         requerido, formato YYYY-MM, no puede ser mes pasado
 *  - concepto_id: opcional, debe existir en conceptos_cobro del tenant y estar activo
 *
 * Requiere que tenant_id ya esté inyectado por el middleware CheckTenant.
 */
class GenerarCuotaRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'mes'         => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'concepto_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'mes.required' => 'El mes es requerido.',
            'mes.regex'    => 'El mes debe tener formato YYYY-MM (ej: 2026-03).',
        ];
    }

    /**
     * Validaciones de negocio que requieren contexto de BD.
     * Se ejecutan después de que las reglas base pasan sin errores.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // No continuar si ya hay errores de formato
            if ($v->errors()->any()) {
                return;
            }

            $mes      = $this->input('mes');
            $tenantId = (int) $this->get('tenant_id');

            // ── 1. El mes no puede ser anterior al mes actual ───────────────
            [$year, $month]  = explode('-', $mes);
            $mesSolicitado   = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfMonth();
            $mesActual       = now()->startOfMonth();

            if ($mesSolicitado->lt($mesActual)) {
                $v->errors()->add(
                    'mes',
                    'No se pueden generar cuotas para meses pasados. ' .
                    "El mes mínimo permitido es {$mesActual->format('Y-m')}."
                );
                return; // No seguir si el mes es inválido
            }

            // ── 2. concepto_id debe pertenecer al tenant y estar activo ─────
            if ($this->filled('concepto_id')) {
                $existe = ConceptoCobro::where('id', $this->concepto_id)
                    ->where('tenant_id', $tenantId)
                    ->where('activo', true)
                    ->exists();

                if (!$existe) {
                    $v->errors()->add(
                        'concepto_id',
                        'El concepto de cobro no existe o está inactivo en este PH.'
                    );
                }
            }
        });
    }
}
