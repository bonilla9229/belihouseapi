<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope que aplica automáticamente WHERE tenant_id = <usuario autenticado>
 * en todos los modelos donde se registre.
 *
 * ── Registro en un modelo ────────────────────────────────────────────────────
 *
 *   use App\Models\Scopes\TenantScope;
 *
 *   class Unidad extends Model
 *   {
 *       protected static function booted(): void
 *       {
 *           static::addGlobalScope(new TenantScope());
 *       }
 *   }
 *
 * ── Bypass del scope (seeders, comandos, super-admin) ────────────────────────
 *
 *   // Sin scope por una query puntual:
 *   Unidad::withoutGlobalScope(TenantScope::class)->all();
 *
 *   // Sin scope para toda una operación:
 *   Unidad::withoutGlobalScopes()->where('estado', 'activa')->get();
 *
 * ── Comportamiento ───────────────────────────────────────────────────────────
 *   - Se aplica SÓLO cuando hay un usuario autenticado con tenant_id.
 *   - En migraciones, seeders y comandos artisan (sin auth) NO filtra nada.
 *   - Los controllers que ya hacen ->where('tenant_id', $tenantId) simplemente
 *     tendrán esa condición duplicada (inofensivo, el SQL sigue siendo correcto).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Aplica solo si hay sesión autenticada con tenant_id
        if ($tenantId = auth()->user()?->tenant_id) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
        }
    }
}
