<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PagoCuotaDetalle extends Model
{
    protected $table    = 'pago_cuota_detalle';
    public $timestamps  = false;
    protected $fillable = ['pago_id', 'cuota_id', 'monto_aplicado'];
    protected $casts    = ['monto_aplicado' => 'float'];

    public function pago()  { return $this->belongsTo(Pago::class); }
    public function cuota() { return $this->belongsTo(Cuota::class); }
}
