<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla: visitantes — columnas reales: tenant_id, nombre, cedula, placa, created_at
class Visitante extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'nombre', 'cedula', 'placa'];

    public function accesos() { return $this->hasMany(Acceso::class); }
}
