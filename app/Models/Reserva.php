<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Reserva extends Model
{
    protected $fillable = [
        'tenant_id', 'area_id', 'unidad_id', 'residente_id', 'pago_id',
        'fecha', 'hora_inicio', 'hora_fin', 'num_personas',
        'estado', 'motivo_rechazo', 'costo_total', 'notas',
    ];

    protected $casts = ['fecha' => 'date'];

    public function tenant()    { return $this->belongsTo(Tenant::class); }
    public function area()      { return $this->belongsTo(AreaComun::class, 'area_id'); }
    public function unidad()    { return $this->belongsTo(Unidad::class); }
    public function residente() { return $this->belongsTo(Residente::class); }
    public function pago()      { return $this->belongsTo(Pago::class); }
}