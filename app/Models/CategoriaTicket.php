<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CategoriaTicket extends Model
{
    protected $table = 'categorias_ticket';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'nombre', 'color', 'activa'];

    protected $casts = ['activa' => 'boolean'];

    public function tenant()  { return $this->belongsTo(Tenant::class); }
    public function tickets() { return $this->hasMany(Ticket::class, 'categoria_id'); }
}
