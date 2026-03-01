<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voto extends Model
{
    public $timestamps = false;

    protected $fillable = ['votacion_id', 'opcion_id', 'unidad_id', 'propietario_id', 'peso'];

    protected $casts = ['peso' => 'float'];

    public function votacion()    { return $this->belongsTo(Votacion::class); }
    public function opcion()      { return $this->belongsTo(OpcionVotacion::class, 'opcion_id'); }
    public function unidad()      { return $this->belongsTo(Unidad::class); }
    public function propietario() { return $this->belongsTo(Propietario::class); }
}
