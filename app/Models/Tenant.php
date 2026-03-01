<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'nombre','ruc','slug','logo_url','telefono','email','direccion',
        'tipo_ph','extras_ph','plan','activo'
    ];

    protected $casts = [
        // tipo_ph es ENUM string — no necesita cast  →  valores: edificio | casa | deposito
        'extras_ph' => 'array',  // JSON <-> PHP array automatico
    ];

    public function usuarios() { return $this->hasMany(Usuario::class); }
    public function torres() { return $this->hasMany(Torre::class); }
    public function unidades() { return $this->hasMany(Unidad::class); }
    public function cuotas() { return $this->hasMany(Cuota::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }
    public function comunicados() { return $this->hasMany(Comunicado::class); }
    public function proveedores() { return $this->hasMany(Proveedor::class); }
    public function roles() { return $this->hasMany(Role::class); }
}