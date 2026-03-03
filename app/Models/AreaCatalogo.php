<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla: areas_catalogo — catálogo global de tipos de áreas comunes (sin tenant)
class AreaCatalogo extends Model
{
    protected $table = 'areas_catalogo';

    public $timestamps = false;

    protected $fillable = [
        'nombre', 'icono', 'color_bg', 'color_text', 'descripcion',
    ];

    public function areasComunes()
    {
        return $this->hasMany(AreaComun::class, 'catalogo_id');
    }
}
