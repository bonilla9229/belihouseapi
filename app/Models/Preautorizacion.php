<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla: preautorizaciones — columnas reales: unidad_id, residente_id, nombre_visitante, cedula_visitante, placa_visitante, fecha_desde, fecha_hasta, descripcion, activa, created_at
class Preautorizacion extends Model
{
    protected $table = 'preautorizaciones';

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'unidad_id', 'residente_id', 'nombre_visitante',
        'cedula_visitante', 'placa_visitante', 'fecha_desde', 'fecha_hasta',
        'descripcion', 'qr_token', 'activa', 'estado',
    ];

    protected $casts = [
        'activa'      => 'boolean',
        'fecha_desde' => 'datetime',
        'fecha_hasta' => 'datetime',
    ];

    public function tenant()    { return $this->belongsTo(Tenant::class); }
    public function unidad()    { return $this->belongsTo(Unidad::class); }
    public function residente() { return $this->belongsTo(Residente::class); }
}

