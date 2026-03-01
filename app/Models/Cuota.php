<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Cuota extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id','unidad_id','concepto_id','periodo','fecha_emision',
        'fecha_vencimiento','monto','mora','descuento','estado','notas'
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function unidad() { return $this->belongsTo(Unidad::class); }
    public function concepto() { return $this->belongsTo(ConceptoCobro::class, 'concepto_id'); }
    public function pagos() { return $this->belongsToMany(Pago::class, 'pago_cuota_detalle')->withPivot('monto_aplicado'); }
    public function detallesPago() { return $this->hasMany(PagoCuotaDetalle::class, 'cuota_id'); }
}