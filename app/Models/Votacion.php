<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Votacion extends Model
{
    protected $table = 'votaciones';

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'asamblea_id', 'titulo', 'descripcion',
        'tipo', 'fecha_inicio', 'fecha_fin', 'estado', 'created_by',
    ];

    protected $casts = ['fecha_inicio' => 'datetime', 'fecha_fin' => 'datetime'];

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function asamblea() { return $this->belongsTo(Asamblea::class); }
    public function opciones() { return $this->hasMany(OpcionVotacion::class); }
    public function votos()    { return $this->hasMany(Voto::class); }
}
