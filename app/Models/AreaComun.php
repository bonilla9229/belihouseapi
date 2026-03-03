<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla: areas_comunes — columnas reales: nombre, descripcion, capacidad, requiere_pago, costo, activa, imagen_url, catalogo_id
class AreaComun extends Model
{
    protected $table = 'areas_comunes';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'catalogo_id', 'nombre', 'descripcion', 'capacidad',
        'requiere_pago', 'costo', 'hora_inicio', 'hora_fin', 'activa', 'imagen_url',
    ];
    protected $casts = [
        'activa'        => 'boolean',
        'requiere_pago' => 'boolean',
        'costo'         => 'float',
        'capacidad'     => 'integer',
        'catalogo_id'   => 'integer',
    ];

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function catalogo() { return $this->belongsTo(\App\Models\AreaCatalogo::class, 'catalogo_id'); }
    public function horarios() { return $this->hasMany(HorarioArea::class, 'area_id'); }
    public function reservas() { return $this->hasMany(Reserva::class, 'area_id'); }
}

