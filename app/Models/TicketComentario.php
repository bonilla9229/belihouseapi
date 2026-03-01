<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketComentario extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'usuario_id', 'comentario', 'es_interno', 'adjunto_url'];

    protected $casts = ['es_interno' => 'boolean'];

    public function ticket()  { return $this->belongsTo(Ticket::class); }
    public function usuario() { return $this->belongsTo(Usuario::class); }
}
