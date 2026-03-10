<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UnidadController;
use App\Http\Controllers\Api\PropietarioController;
use App\Http\Controllers\Api\ResidenteController;
use App\Http\Controllers\Api\CuotaController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\AccesoController;
use App\Http\Controllers\Api\PreautorizacionController;
use App\Http\Controllers\Api\ReservaController;
use App\Http\Controllers\Api\GastoController;
use App\Http\Controllers\Api\ComunicadoController;
use App\Http\Controllers\Api\VehiculoController;
use App\Http\Controllers\Api\VotacionController;
use App\Http\Controllers\Api\AsambleaController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ComprobantePagoController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\ConceptoCobroController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\LocalController;
use App\Http\Controllers\Api\EstacionamientoController;

// ============================================================
// RUTAS PÚBLICAS (sin autenticación)
// ============================================================
Route::prefix('v1')->group(function () {

    // Auth
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']); // registro nuevo PH

});

// ============================================================
// RUTAS PROTEGIDAS (requieren token Sanctum)
// ============================================================
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant'])->group(function () {

    // Auth
    Route::post('logout',  [AuthController::class, 'logout']);
    Route::get('me',       [AuthController::class, 'me']);

    // Tenant
    Route::get('tenant',   [TenantController::class, 'show']);
    Route::put('tenant',   [TenantController::class, 'update']);

    // Unidades
    Route::apiResource('unidades',    UnidadController::class);
    Route::post('unidades/{unidad}/propietario',   [UnidadController::class, 'asignarPropietario']);
    Route::delete('unidades/{unidad}/propietario', [UnidadController::class, 'desasignarPropietario']);
    Route::get('torres',              [UnidadController::class, 'torres']);
    Route::post('torres',             [UnidadController::class, 'storeTorre']);
    Route::put('torres/{id}',         [UnidadController::class, 'updateTorre']);
    Route::delete('torres/{id}',      [UnidadController::class, 'destroyTorre']);

    // Propietarios y Residentes
    Route::apiResource('propietarios', PropietarioController::class);
    Route::post('propietarios/{propietario}/asignar-unidad', [PropietarioController::class, 'asignarUnidad']);
    Route::apiResource('residentes',   ResidenteController::class);
    Route::post('residentes/{residente}/cuenta', [ResidenteController::class, 'crearCuenta']);

    // Roles disponibles (para selects de invitación)
    Route::get('roles', function (\Illuminate\Http\Request $request) {
        $tenantId = $request->get('tenant_id');
        return response()->json([
            'data' => \App\Models\Role::where('tenant_id', $tenantId)->select('id', 'nombre')->get(),
        ]);
    });

    // Vehículos
    Route::get('unidades/{unidad}/vehiculos',  [VehiculoController::class, 'getVehiculos']);
    Route::post('unidades/{unidad}/vehiculos', [VehiculoController::class, 'storeVehiculo']);
    Route::delete('vehiculos/{vehiculo}',      [VehiculoController::class, 'destroyVehiculo']);

    // Conceptos de cobro (CRUD completo)
    Route::get('conceptos-cobro',         [ConceptoCobroController::class, 'index']);
    Route::post('conceptos-cobro',        [ConceptoCobroController::class, 'store']);
    Route::get('conceptos-cobro/{id}',    [ConceptoCobroController::class, 'show']);
    Route::put('conceptos-cobro/{id}',    [ConceptoCobroController::class, 'update']);
    Route::delete('conceptos-cobro/{id}', [ConceptoCobroController::class, 'destroy']);

    // Usuarios (CRUD completo)
    Route::get('usuarios',          [UsuarioController::class, 'index']);
    Route::post('usuarios',         [UsuarioController::class, 'store']);
    Route::get('usuarios/{id}',     [UsuarioController::class, 'show']);
    Route::put('usuarios/{id}',     [UsuarioController::class, 'update']);
    Route::patch('usuarios/{id}/rol', [UsuarioController::class, 'cambiarRol']);
    Route::delete('usuarios/{id}',  [UsuarioController::class, 'destroy']);

    // Cuotas — rutas estáticas ANTES del apiResource para evitar shadowing
    Route::post('cuotas/generar',      [CuotaController::class, 'generar']);
    Route::post('cuotas/recordatorio', [CuotaController::class, 'recordatorio']);
    Route::get('cuotas/resumen',       [CuotaController::class, 'resumen']);
    Route::apiResource('cuotas',       CuotaController::class);
    Route::post('cuotas/{cuota}/mora', [CuotaController::class, 'aplicarMora']);

    // Pagos
    Route::apiResource('pagos',        PagoController::class);
    Route::post('pagos/{pago}/anular', [PagoController::class, 'anular']);

    // Comprobantes de pago (enviados por residentes, pendientes de aprobacion admin)
    Route::get('comprobantes-pago',                [ComprobantePagoController::class, 'index']);
    Route::post('comprobantes-pago',               [ComprobantePagoController::class, 'store']);
    Route::post('comprobantes-pago/{id}/aprobar',  [ComprobantePagoController::class, 'aprobar']);
    Route::post('comprobantes-pago/{id}/rechazar', [ComprobantePagoController::class, 'rechazar']);

    // Tickets
    Route::apiResource('tickets',      TicketController::class);
    Route::get('tickets/{ticket}/comentarios',         [TicketController::class, 'getComentarios']);
    Route::post('tickets/{ticket}/comentarios',        [TicketController::class, 'agregarComentario']);
    Route::patch('tickets/{ticket}/estado',             [TicketController::class, 'cambiarEstado']);
    Route::post('tickets/{ticket}/asignar',            [TicketController::class, 'asignar']);
    Route::get('categorias-ticket',                    [TicketController::class, 'categorias']);

    // Accesos — ruta estática ANTES del apiResource para evitar shadowing
    Route::get('accesos/analitica',            [AccesoController::class, 'analitica']);    Route::post('accesos/verificar-pin',       [AccesoController::class, 'verificarPin']);    Route::apiResource('accesos',              AccesoController::class);
    Route::post('accesos/{acceso}/salida',     [AccesoController::class, 'registrarSalida']);
    Route::get('accesos/buscar-preauth',       [AccesoController::class, 'buscarPreauth']); // por cédula o placa
    Route::apiResource('preautorizaciones',    PreautorizacionController::class);

    // Reservas
    Route::get('areas-catalogo',                        [ReservaController::class, 'catalogoIndex']);
    Route::apiResource('reservas',               ReservaController::class);
    Route::post('reservas/{reserva}/aprobar',    [ReservaController::class, 'aprobar']);
    Route::post('reservas/{reserva}/rechazar',   [ReservaController::class, 'rechazar']);
    Route::get('areas-comunes',                      [ReservaController::class, 'areas']);
    Route::post('areas-comunes',                     [ReservaController::class, 'storeArea']);
    Route::put('areas-comunes/{id}',                 [ReservaController::class, 'updateArea']);
    Route::delete('areas-comunes/{id}',              [ReservaController::class, 'destroyArea']);
    Route::get('areas-comunes/{area}/horarios',      [ReservaController::class, 'horarios']);
    Route::post('areas-comunes/{id}/horarios',       [ReservaController::class, 'storeHorario']);
    Route::put('horarios/{id}',                      [ReservaController::class, 'updateHorario']);
    Route::delete('horarios/{id}',                   [ReservaController::class, 'destroyHorario']);
    Route::get('areas-comunes/{area}/disponibilidad',[ReservaController::class, 'disponibilidad']);

    // Gastos
    Route::get('gastos/resumen',           [GastoController::class, 'resumen']);
    Route::apiResource('gastos',           GastoController::class);
    Route::apiResource('proveedores',      ProveedorController::class);
    Route::get('categorias-gasto',         [GastoController::class, 'categorias']);
    Route::post('categorias-gasto',        [GastoController::class, 'storeCategorias']);
    Route::put('categorias-gasto/{id}',    [GastoController::class, 'updateCategoria']);
    Route::delete('categorias-gasto/{id}', [GastoController::class, 'destroyCategoria']);

    // Comunicados
    Route::apiResource('comunicados',                    ComunicadoController::class);
    Route::post('comunicados/{comunicado}/publicar',     [ComunicadoController::class, 'publicar']);
    Route::post('comunicados/{comunicado}/leer',         [ComunicadoController::class, 'marcarLeido']);
    Route::get('comunicados/{comunicado}/estadisticas',  [ComunicadoController::class, 'estadisticas']);

    // Asambleas
    Route::apiResource('asambleas',                          AsambleaController::class);
    Route::post('asambleas/{asamblea}/asistencia',           [AsambleaController::class, 'registrarAsistencia']);
    Route::post('asambleas/{asamblea}/asistencia/bulk',      [AsambleaController::class, 'registrarAsistenciaBulk']);
    Route::patch('asambleas/{asamblea}/estado',             [AsambleaController::class, 'cambiarEstado']);
    Route::get('asambleas/{asamblea}/quorum',                [AsambleaController::class, 'quorum']);

    // Votaciones
    Route::apiResource('votaciones',                         VotacionController::class);
    Route::post('votaciones/{votacion}/votar',               [VotacionController::class, 'votar']);
    Route::post('votaciones/{votacion}/cerrar',              [VotacionController::class, 'cerrar']);
    Route::get('votaciones/{votacion}/resultados',           [VotacionController::class, 'resultados']);

    // Configuración — rutas estáticas ANTES para evitar shadowing por {id}
    Route::get('configuracion/mora',              [ConfiguracionController::class, 'mora']);
    Route::put('configuracion/mora',              [ConfiguracionController::class, 'updateMora']);
    Route::get('configuracion/notificaciones',    [ConfiguracionController::class, 'notificaciones']);
    Route::put('configuracion/notificaciones',    [ConfiguracionController::class, 'updateNotificaciones']);
    Route::get('configuracion',                   [ConfiguracionController::class, 'index']);
    Route::put('configuracion',                   [ConfiguracionController::class, 'update']);

    // Extras — Oficinas / Locales comerciales
    Route::apiResource('locales',              LocalController::class);

    // Extras — Estacionamientos / Parqueos
    Route::get('estacionamientos/pisos',       [EstacionamientoController::class, 'pisos']);
    Route::apiResource('estacionamientos',     EstacionamientoController::class);

    // Dashboard - stats generales
    Route::get('dashboard',               [TenantController::class, 'dashboard']);

    // Notificaciones
    Route::get('notificaciones',          [AuthController::class, 'notificaciones']);
    Route::post('notificaciones/leer-todas', [AuthController::class, 'leerTodas']);

});

