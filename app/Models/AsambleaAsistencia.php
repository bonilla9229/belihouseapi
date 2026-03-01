<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsambleaAsistencia extends Model
{
    protected $table = 'asamblea_asistencia';

    public $timestamps = false;

    protected $fillable = ['asamblea_id', 'unidad_id', 'propietario_id', 'nombre_asistente', 'tipo'];

    public function asamblea()    { return $this->belongsTo(Asamblea::class); }
    public function unidad()      { return $this->belongsTo(Unidad::class); }
    public function propietario() { return $this->belongsTo(Propietario::class); }
}
