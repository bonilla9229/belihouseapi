<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Unidad extends Model
{
    protected $table = 'unidades';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id','torre_id','numero','piso','tipo','area_m2','coeficiente','activa'
    ];

    // Aplica WHERE tenant_id automáticamente en todas las queries Eloquent
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function torre()    { return $this->belongsTo(Torre::class); }

    /** Todos los propietarios (incluyendo históricos) */
    public function propietarios()
    {
        return $this->belongsToMany(Propietario::class, 'propietario_unidad')
            ->withPivot('activo', 'porcentaje', 'fecha_inicio', 'fecha_fin');
    }

    /** Solo propietarios con relación activa */
    public function propietariosActivos()
    {
        return $this->belongsToMany(Propietario::class, 'propietario_unidad')
            ->withPivot('activo', 'porcentaje', 'fecha_inicio', 'fecha_fin')
            ->wherePivot('activo', true);
    }

    public function residentes() { return $this->hasMany(Residente::class); }
    public function cuotas()     { return $this->hasMany(Cuota::class); }
    public function tickets()    { return $this->hasMany(Ticket::class); }
    public function accesos()    { return $this->hasMany(Acceso::class); }
    public function reservas()   { return $this->hasMany(Reserva::class); }
    public function vehiculos()  { return $this->hasMany(Vehiculo::class); }
}