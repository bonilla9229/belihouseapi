<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobantePago extends Model
{
    protected $table = 'comprobantes_pago';

    protected $fillable = [
        'tenant_id', 'cuota_id', 'unidad_id', 'residente_id',
        'metodo_pago', 'referencia', 'monto', 'fecha_pago',
        'comprobante_url', 'estado', 'nota_admin',
        'aprobado_por', 'aprobado_at',
    ];

    protected $casts = [
        'fecha_pago'  => 'date',
        'aprobado_at' => 'datetime',
        'monto'       => 'float',
    ];

    public function tenant()     { return $this->belongsTo(Tenant::class); }
    public function cuota()      { return $this->belongsTo(Cuota::class); }
    public function unidad()     { return $this->belongsTo(Unidad::class); }
    public function residente()  { return $this->belongsTo(Residente::class); }
    public function aprobadoPor(){ return $this->belongsTo(Usuario::class, 'aprobado_por'); }
}
