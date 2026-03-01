<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'unidad_id', 'placa', 'marca', 'modelo', 'color', 'tipo', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function unidad() { return $this->belongsTo(Unidad::class); }
}
