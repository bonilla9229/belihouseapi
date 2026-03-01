<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Gasto extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'categoria_id', 'proveedor_id', 'ticket_id', 'aprobado_por',
        'descripcion', 'monto', 'fecha', 'metodo_pago', 'referencia',
        'comprobante_url', 'notas',
    ];

    protected $casts = ['fecha' => 'date'];

    public function tenant()      { return $this->belongsTo(Tenant::class); }
    public function categoria()   { return $this->belongsTo(CategoriaGasto::class, 'categoria_id'); }
    public function proveedor()   { return $this->belongsTo(Proveedor::class); }
    public function ticket()      { return $this->belongsTo(Ticket::class); }
    public function aprobadoPor() { return $this->belongsTo(Usuario::class, 'aprobado_por'); }
}