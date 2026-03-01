<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tabla: config_mora — columnas reales: dias_gracia, tipo_mora, valor_mora, mora_acumulable
class ConfigMora extends Model
{
    protected $table      = 'config_mora';
    public    $timestamps = false;
    protected $fillable   = ['tenant_id', 'dias_gracia', 'tipo_mora', 'valor_mora', 'mora_acumulable'];
    protected $casts      = ['mora_acumulable' => 'boolean'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
}

