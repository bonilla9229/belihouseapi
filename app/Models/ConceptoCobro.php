<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// Tabla: conceptos_cobro — columnas reales: nombre, descripcion, monto_base, aplica_coeficiente, periodicidad, activo, created_at
class ConceptoCobro extends Model
{
    protected $table = 'conceptos_cobro';

    const UPDATED_AT = null; // solo tiene created_at

    protected $fillable = [
        'tenant_id', 'nombre', 'descripcion', 'monto_base', 'aplica_coeficiente', 'periodicidad', 'activo',
    ];

    protected $casts = [
        'activo'             => 'boolean',
        'aplica_coeficiente' => 'boolean',
        'monto_base'         => 'float',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function cuotas() { return $this->hasMany(Cuota::class, 'concepto_id'); }
}
