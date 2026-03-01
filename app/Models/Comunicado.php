<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// Tabla: comunicados — columnas reales: autor_id, titulo, cuerpo, tipo, audiencia, torre_id, adjunto_url, publicado, fecha_publicacion, fecha_expiracion, created_at
class Comunicado extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'autor_id', 'titulo', 'cuerpo', 'tipo', 'audiencia',
        'torre_id', 'adjunto_url', 'publicado', 'fecha_publicacion', 'fecha_expiracion',
    ];

    protected $casts = [
        'publicado'         => 'boolean',
        'fecha_publicacion' => 'datetime',
        'fecha_expiracion'  => 'datetime',
    ];

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function autor()    { return $this->belongsTo(Usuario::class, 'autor_id'); }
    public function torre()    { return $this->belongsTo(Torre::class); }
    public function lecturas() { return $this->hasMany(ComunicadoLectura::class); }
}