<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropietarioUnidad extends Model
{
    protected $table   = 'propietario_unidad';
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'propietario_id', 'unidad_id', 'activo', 'porcentaje', 'fecha_inicio', 'fecha_fin'];

    protected $casts = ['activo' => 'boolean', 'porcentaje' => 'float', 'fecha_inicio' => 'date', 'fecha_fin' => 'date'];

    public function propietario() { return $this->belongsTo(Propietario::class); }
    public function unidad()      { return $this->belongsTo(Unidad::class); }
}
