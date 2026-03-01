<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla key-value: cada fila es un par (tenant_id, clave, valor)
// UNIQUE KEY uq_config (tenant_id, clave)
class Configuracion extends Model
{
    protected $table      = 'configuracion';
    public    $timestamps = false;
    protected $fillable   = ['tenant_id', 'clave', 'valor'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
}
