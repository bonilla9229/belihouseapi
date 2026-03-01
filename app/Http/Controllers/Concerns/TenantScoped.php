<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * TenantScoped — helpers de multi-tenancy para controllers.
 *
 * Uso:
 *   class MiController extends Controller
 *   {
 *       use TenantScoped;
 *
 *       public function index(Request $request)
 *       {
 *           $items = $this->tenantQuery(MiModel::class, $request)->paginate(20);
 *       }
 *   }
 */
trait TenantScoped
{
    /**
     * Devuelve el tenant_id inyectado por el middleware CheckTenant.
     */
    protected function tenantId(Request $request): int
    {
        return (int) $request->get('tenant_id');
    }

    /**
     * Devuelve un Builder pre-filtrado por tenant_id.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    protected function tenantQuery(string $model, Request $request): Builder
    {
        return $model::where('tenant_id', $this->tenantId($request));
    }
}
