<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Estacionamiento extends Model
{
    protected $table = 'estacionamientos';

    protected $fillable = [
        'tenant_id',
        'numero',
        'piso',
        'tipo_vehiculo',      // ENUM: 'carro' | 'moto' | 'otro'
        'placa',
        'propietario_nombre',
        'propietario_tel',
        'unidad_id',
        'mensualidad',
        'activo',
        'observaciones',
    ];

    protected $casts = [
        'mensualidad' => 'float',
        'activo'      => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant()  { return $this->belongsTo(Tenant::class); }
    public function unidad()  { return $this->belongsTo(Unidad::class); }
}
