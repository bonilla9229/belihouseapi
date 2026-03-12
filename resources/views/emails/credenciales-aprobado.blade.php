<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tu acceso ha sido aprobado</title></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',system-ui,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.09);">
      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#059669,#10b981);padding:32px 40px;text-align:center;">
        <div style="font-size:42px;margin-bottom:10px;">✅</div>
        <h1 style="color:#fff;font-size:22px;font-weight:700;margin:0;">¡Tu acceso fue aprobado!</h1>
        <p style="color:rgba(255,255,255,0.85);font-size:14px;margin:8px 0 0;">{{ $usuario->tenant?->nombre }}</p>
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:36px 40px;">
        <p style="font-size:15px;color:#0f172a;margin:0 0 6px;">Hola, <strong>{{ $usuario->nombre }}</strong> 👋</p>
        <p style="font-size:14px;color:#475569;margin:0 0 28px;line-height:1.7;">
          El administrador de <strong>{{ $usuario->tenant?->nombre }}</strong> aprobó tu solicitud de acceso.
          Ya puedes ingresar al sistema con las siguientes credenciales:
        </p>

        <!-- Credentials box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;margin-bottom:28px;">
          <tr><td style="padding:24px 28px;">
            <table cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td style="padding-bottom:16px;">
                  <div style="font-size:11px;color:#16a34a;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:4px;">Correo electrónico</div>
                  <div style="font-size:15px;color:#0f172a;font-weight:600;font-family:monospace;">{{ $usuario->email }}</div>
                </td>
              </tr>
              <tr>
                <td>
                  <div style="font-size:11px;color:#16a34a;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:4px;">Contraseña temporal</div>
                  <div style="font-size:18px;color:#0f172a;font-weight:700;font-family:monospace;background:#fff;border:1px solid #bbf7d0;border-radius:8px;padding:10px 16px;letter-spacing:2px;">{{ $plainPassword }}</div>
                </td>
              </tr>
            </table>
          </td></tr>
        </table>

        <!-- Info boxes -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
          <tr>
            <td style="background:#eff6ff;border-radius:10px;padding:14px 16px;font-size:13px;color:#1e40af;line-height:1.6;">
              💡 <strong>Recomendado:</strong> cambia tu contraseña desde tu perfil después de ingresar.
            </td>
          </tr>
          <tr><td style="padding:8px 0;"></td></tr>
          <tr>
            <td style="background:#f0fdf4;border-radius:10px;padding:14px 16px;font-size:13px;color:#15803d;line-height:1.6;">
              🔑 También puedes ingresar directamente con <strong>Google</strong> sin necesidad de contraseña.
            </td>
          </tr>
        </table>

        <a href="{{ env('FRONTEND_URL','http://localhost:5173') }}/login"
          style="display:inline-block;background:linear-gradient(135deg,#059669,#10b981);color:#fff;text-decoration:none;padding:13px 28px;border-radius:12px;font-size:14px;font-weight:700;">
          Ingresar al sistema →
        </a>
      </td></tr>
      <!-- Footer -->
      <tr><td style="padding:20px 40px;border-top:1px solid #f1f5f9;text-align:center;">
        <p style="font-size:12px;color:#94a3b8;margin:0;">BeliHouse · Este correo fue generado automáticamente, no respondas a este mensaje.</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
