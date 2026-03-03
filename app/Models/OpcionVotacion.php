<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpcionVotacion extends Model
{
    protected $table = 'opciones_votacion';

    public $timestamps = false;

    protected $fillable = ['votacion_id', 'texto', 'orden'];

    public function votacion() { return $this->belongsTo(Votacion::class); }
    public function votos()    { return $this->hasMany(Voto::class, 'opcion_id'); }
}
