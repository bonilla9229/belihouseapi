<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// Tabla: torres — columnas reales: nombre, pisos (smallint), created_at — SIN descripcion
class Torre extends Model
{
    const UPDATED_AT = null; // solo tiene created_at

    protected $fillable = ['tenant_id', 'nombre', 'pisos'];
    protected $casts    = ['pisos' => 'integer'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function unidades() { return $this->hasMany(Unidad::class); }
}