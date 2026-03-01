<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public $timestamps = false;

    protected $table = 'roles';
    protected $fillable = ['tenant_id','nombre','permisos'];
    protected $casts = ['permisos' => 'array'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function usuarios() { return $this->hasMany(Usuario::class); }
}