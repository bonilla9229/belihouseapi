<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla: areas_comunes — columnas reales: nombre, descripcion, capacidad, requiere_pago, costo, activa, imagen_url
class AreaComun extends Model
{
    protected $table = 'areas_comunes';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'nombre', 'descripcion', 'capacidad',
        'requiere_pago', 'costo', 'hora_inicio', 'hora_fin', 'activa', 'imagen_url',
    ];
    protected $casts = [
        'activa'        => 'boolean',
        'requiere_pago' => 'boolean',
        'costo'         => 'float',
        'capacidad'     => 'integer',
    ];

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function horarios() { return $this->hasMany(HorarioArea::class, 'area_id'); }
    public function reservas() { return $this->hasMany(Reserva::class, 'area_id'); }
}

