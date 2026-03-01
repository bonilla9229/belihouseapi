<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaGasto extends Model
{
    protected $table = 'categorias_gasto';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'nombre', 'presupuesto_mensual', 'activa'];

    protected $casts = ['activa' => 'boolean', 'presupuesto_mensual' => 'float'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function gastos() { return $this->hasMany(Gasto::class, 'categoria_id'); }
}
