<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asamblea extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'titulo', 'tipo', 'fecha', 'lugar',
        'quorum_requerido', 'estado', 'acta_url', 'notas', 'created_by',
    ];

    protected $casts = ['fecha' => 'datetime'];

    public function tenant()      { return $this->belongsTo(Tenant::class); }
    public function asistencias() { return $this->hasMany(AsambleaAsistencia::class); }
    public function votaciones()  { return $this->hasMany(Votacion::class); }
}
