<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'usuarios';
    protected $fillable = [
        'tenant_id','rol_id','codigo','nombre','apellido','email','google_id',
        'telefono','password_hash','avatar_url','activo','status','ultimo_login',
    ];
    protected $hidden = ['password_hash'];

    public function getAuthPassword() { return $this->password_hash; }

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function rol()    { return $this->belongsTo(Role::class); }
}