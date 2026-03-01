<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * FormRequest base para todos los endpoints de Aleph.
 *
 * Garantiza:
 *  - Respuesta JSON siempre (nunca una redirección HTML)
 *  - Formato de error estándar: { message, errors }
 *  - authorize() = true por defecto (la protección real la hace el middleware)
 *
 * Uso:
 *   class MiRequest extends BaseRequest
 *   {
 *       public function rules(): array { return [...]; }
 *   }
 */
abstract class BaseRequest extends FormRequest
{
    /**
     * Todos los FormRequests de Aleph confían en los middlewares auth:sanctum + tenant.
     * Sobreescribir si se necesita lógica de autorización adicional.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Siempre devuelve JSON con formato estándar en lugar de redirigir.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Error de validación.',
                'errors'  => $validator->errors()->toArray(),
            ], 422)
        );
    }

    /**
     * Respuesta JSON cuando authorize() retorna false.
     */
    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'No tienes permiso para esta acción.',
            ], 403)
        );
    }
}
