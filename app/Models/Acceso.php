<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

// Tabla: accesos — tipo enum: visitante|delivery|proveedor|empleado|otro
// Timestamps reales: fecha_hora_entrada, fecha_hora_salida (no created_at/updated_at)
class Acceso extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id', 'visitante_id', 'unidad_id', 'area_comun_id',
        'tipo', 'motivo', 'cedula', 'empresa', 'fecha_hora_entrada', 'fecha_hora_salida',
        'autorizado_por', 'observaciones', 'metodo_entrada', 'qr_token',
    ];

    protected $casts = [
        'fecha_hora_entrada' => 'datetime',
        'fecha_hora_salida'  => 'datetime',
    ];

    public function tenant()       { return $this->belongsTo(Tenant::class); }
    public function visitante()    { return $this->belongsTo(Visitante::class); }
    public function unidad()       { return $this->belongsTo(Unidad::class); }
    public function areaComun()    { return $this->belongsTo(\App\Models\AreaComun::class, 'area_comun_id'); }
    public function autorizadoPor(){ return $this->belongsTo(Usuario::class, 'autorizado_por'); }
}
