<?php
namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'tenant_id', 'numero', 'unidad_id', 'ubicacion', 'area_comun_id', 'categoria_id',
        'reportado_por', 'asignado_a', 'asignado_nombre',
        'titulo', 'descripcion', 'foto_url', 'prioridad', 'estado',
        'fecha_limite', 'costo_estimado', 'costo_real',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant()     { return $this->belongsTo(Tenant::class); }
    public function unidad()     { return $this->belongsTo(Unidad::class); }
    public function categoria()  { return $this->belongsTo(CategoriaTicket::class, 'categoria_id'); }
    /** Aliás 'asignado' para usarlo en eager loading: with(['asignado']) */
    public function asignado()   { return $this->belongsTo(Usuario::class, 'asignado_a'); }
    public function creadoPor()  { return $this->belongsTo(Usuario::class, 'reportado_por'); }
    public function comentarios(){ return $this->hasMany(TicketComentario::class); }
    public function historial()  { return $this->hasMany(TicketHistorial::class); }
}