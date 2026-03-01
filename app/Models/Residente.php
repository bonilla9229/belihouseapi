<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Residente extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'unidad_id', 'usuario_id', 'codigo',
        'nombre', 'apellido', 'cedula', 'email', 'telefono',
        'tipo', 'es_contacto', 'activo', 'fecha_ingreso', 'fecha_salida',
    ];

    protected $casts = [
        'es_contacto'   => 'boolean',
        'activo'        => 'boolean',
        'fecha_ingreso' => 'date',
        'fecha_salida'  => 'date',
    ];

    public function tenant()  { return $this->belongsTo(Tenant::class); }
    public function unidad()  { return $this->belongsTo(Unidad::class); }
    public function usuario() { return $this->belongsTo(Usuario::class); }
}