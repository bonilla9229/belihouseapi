<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $fillable = ['tenant_id', 'nombre', 'ruc', 'telefono', 'email', 'contacto', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function gastos() { return $this->hasMany(Gasto::class); }
}
