<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Local extends Model
{
    protected $table = 'locales';

    protected $fillable = [
        'tenant_id',
        'tipo',             // ENUM: 'oficina' | 'local'
        'numero',
        'piso',
        'metraje',
        'coeficiente',
        'nombre_empresa',
        'ruc',
        'actividad',
        'propietario_nombre',
        'propietario_ruc',
        'propietario_tel',
        'propietario_email',
        'mensualidad',
        'activo',
        'observaciones',
    ];

    protected $casts = [
        'metraje'     => 'float',
        'coeficiente' => 'float',
        'mensualidad' => 'float',
        'activo'      => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant() { return $this->belongsTo(Tenant::class); }
}
