<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Propietario extends Model
{
    protected $fillable = [
        'tenant_id', 'usuario_id', 'nombre', 'apellido', 'cedula', 'email',
        'telefono', 'telefono2', 'fecha_compra', 'notas',
    ];

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function unidades() { return $this->belongsToMany(Unidad::class, 'propietario_unidad')
                                             ->withPivot('activo', 'fecha_inicio', 'fecha_fin'); }
}