<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'cuota_id', 'unidad_id', 'recibido_por',
        'monto', 'metodo_pago', 'referencia', 'fecha_pago',
        'comprobante_url', 'notas', 'anulado',
    ];

    protected $casts = ['anulado' => 'boolean', 'fecha_pago' => 'date'];

    public function tenant()      { return $this->belongsTo(Tenant::class); }
    public function unidad()      { return $this->belongsTo(Unidad::class); }
    public function recibidoPor() { return $this->belongsTo(Usuario::class, 'recibido_por'); }
    public function cuotas()      { return $this->belongsToMany(Cuota::class, 'pago_cuota_detalle')->withPivot('monto_aplicado'); }
}