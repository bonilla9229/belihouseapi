<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HorarioArea extends Model
{
    protected $table = 'horarios_area';

    public $timestamps = false;

    protected $fillable = ['area_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'disponible'];

    protected $casts = ['disponible' => 'boolean'];

    public function area() { return $this->belongsTo(AreaComun::class, 'area_id'); }
}
