<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComunicadoLectura extends Model
{
    public $timestamps = false;
    protected $fillable = ['comunicado_id', 'usuario_id', 'leido_at'];

    protected $casts = ['leido_at' => 'datetime'];

    public function comunicado() { return $this->belongsTo(Comunicado::class); }
    public function usuario()    { return $this->belongsTo(Usuario::class); }
}
