<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpcionVotacion extends Model
{
    protected $table = 'opciones_votacion';

    protected $fillable = ['votacion_id', 'texto'];

    public function votacion() { return $this->belongsTo(Votacion::class); }
    public function votos()    { return $this->hasMany(Voto::class, 'opcion_id'); }
}
