<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigMora;
use App\Models\Configuracion;
use Illuminate\Http\Request;

class ConfiguracionController extends Controller
{
    //  GET /api/v1/configuracion 
    // La tabla `configuracion` es key-value: (tenant_id, clave, valor)
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $config = Configuracion::where('tenant_id', $tenantId)
            ->pluck('valor', 'clave');

        $mora = ConfigMora::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['dias_gracia' => 5, 'tipo_mora' => 'porcentaje', 'valor_mora' => 5, 'mora_acumulable' => true]
        );

        return response()->json([
            'data' => [
                'moneda'            => $config['moneda']        ?? 'USD',
                'dia_vencimiento'   => (int) ($config['dia_vencimiento']  ?? 10),
                'dias_mora_gracia'  => (int) ($config['dias_mora_gracia'] ?? 5),
                'pin_acceso'        => $config['pin_acceso'] ?? '',
                'mora' => [
                    'dias_gracia'    => $mora->dias_gracia,
                    'tipo_mora'      => $mora->tipo_mora,
                    'valor_mora'     => $mora->valor_mora,
                    'mora_acumulable'=> $mora->mora_acumulable,
                ],
            ],
        ]);
    }

    //  PUT /api/v1/configuracion 
    // Body: { moneda?, dia_vencimiento?, dias_mora_gracia? }
    public function update(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'moneda'           => 'nullable|string|max:10',
            'dia_vencimiento'  => 'nullable|integer|between:1,31',
            'dias_mora_gracia' => 'nullable|integer|min:0',
            'pin_acceso'       => 'nullable|string|max:50',
        ]);

        foreach ($validated as $clave => $valor) {
            if ($valor !== null) {
                Configuracion::updateOrCreate(
                    ['tenant_id' => $tenantId, 'clave' => $clave],
                    ['valor' => (string) $valor]
                );
            }
        }

        $config = Configuracion::where('tenant_id', $tenantId)->pluck('valor', 'clave');

        return response()->json([
            'data' => [
                'moneda'           => $config['moneda']        ?? 'USD',
                'dia_vencimiento'  => (int) ($config['dia_vencimiento']  ?? 10),
                'dias_mora_gracia' => (int) ($config['dias_mora_gracia'] ?? 5),
            ],
            'message' => 'Configuracion actualizada.',
        ]);
    }

    //  GET /api/v1/configuracion/mora 
    public function mora(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $mora = ConfigMora::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['dias_gracia' => 5, 'tipo_mora' => 'porcentaje', 'valor_mora' => 5, 'mora_acumulable' => true]
        );

        return response()->json(['data' => $mora]);
    }

    //  PUT /api/v1/configuracion/mora 
    // Body: { dias_gracia?, tipo_mora?, valor_mora?, mora_acumulable? }
    // Columnas reales en config_mora: dias_gracia, tipo_mora, valor_mora, mora_acumulable
    public function updateMora(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $validated = $request->validate([
            'dias_gracia'    => 'nullable|integer|min:0',
            'tipo_mora'      => 'nullable|in:porcentaje,fijo',
            'valor_mora'     => 'nullable|numeric|min:0',
            'mora_acumulable'=> 'nullable|boolean',
        ]);

        $mora = ConfigMora::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['dias_gracia' => 5, 'tipo_mora' => 'porcentaje', 'valor_mora' => 5, 'mora_acumulable' => true]
        );

        $payload = array_filter($validated, fn ($v) => $v !== null);
        if (!empty($payload)) {
            $mora->update($payload);
        }

        return response()->json([
            'data'    => $mora->fresh(),
            'message' => 'Configuracion de mora actualizada.',
        ]);
    }

    //  GET /api/v1/configuracion/notificaciones 
    // Almacenado como JSON en clave 'notificaciones' de la tabla configuracion
    public function notificaciones(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $row = Configuracion::where('tenant_id', $tenantId)
            ->where('clave', 'notificaciones')
            ->first();

        $stored = $row ? json_decode($row->valor, true) : [];

        $result = collect(self::NOTIF_KEYS)
            ->mapWithKeys(fn ($k) => [$k => (bool) ($stored[$k] ?? false)]);

        return response()->json(['data' => $result]);
    }

    //  PUT /api/v1/configuracion/notificaciones 
    // Body: { notif_cuota_vencida_email: bool, ... }
    public function updateNotificaciones(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $rules     = array_fill_keys(self::NOTIF_KEYS, 'nullable|boolean');
        $validated = $request->validate($rules);

        $row      = Configuracion::where('tenant_id', $tenantId)->where('clave', 'notificaciones')->first();
        $existing = $row ? json_decode($row->valor, true) : [];
        $incoming = array_filter($validated, fn ($v) => $v !== null);
        $merged   = array_merge($existing, array_map(fn ($v) => (bool) $v, $incoming));

        Configuracion::updateOrCreate(
            ['tenant_id' => $tenantId, 'clave' => 'notificaciones'],
            ['valor' => json_encode($merged)]
        );

        return response()->json(['message' => 'Configuracion de notificaciones actualizada.']);
    }

    private const NOTIF_KEYS = [
        'notif_cuota_vencida_email', 'notif_cuota_vencida_push',
        'notif_pago_registrado_email', 'notif_pago_registrado_push',
        'notif_ticket_asignado_email', 'notif_ticket_asignado_push',
        'notif_reserva_aprobada_email', 'notif_reserva_aprobada_push',
        'notif_comunicado_email', 'notif_comunicado_push',
        'notif_visita_preauth_push',
    ];
}
