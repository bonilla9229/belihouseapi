<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketHistorial extends Model
{
    protected $table = 'ticket_historial';

    const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'usuario_id', 'campo_cambiado', 'valor_anterior', 'valor_nuevo'];

    public function ticket()  { return $this->belongsTo(Ticket::class); }
    public function usuario() { return $this->belongsTo(Usuario::class); }
}
