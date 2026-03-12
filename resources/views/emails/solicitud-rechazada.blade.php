<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Solicitud no aprobada</title></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',system-ui,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.09);">
      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#dc2626,#ef4444);padding:32px 40px;text-align:center;">
        <div style="font-size:42px;margin-bottom:10px;">❌</div>
        <h1 style="color:#fff;font-size:22px;font-weight:700;margin:0;">Solicitud no aprobada</h1>
        <p style="color:rgba(255,255,255,0.85);font-size:14px;margin:8px 0 0;">{{ $usuario->tenant?->nombre }}</p>
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:36px 40px;">
        <p style="font-size:15px;color:#0f172a;margin:0 0 6px;">Hola, <strong>{{ $usuario->nombre }}</strong></p>
        <p style="font-size:14px;color:#475569;margin:0 0 24px;line-height:1.7;">
          Lamentamos informarte que tu solicitud de acceso a <strong>{{ $usuario->tenant?->nombre }}</strong>
          no fue aprobada por el administrador.
        </p>

        @if($motivo)
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;margin-bottom:24px;">
          <tr><td style="padding:18px 22px;">
            <div style="font-size:11px;color:#dc2626;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:8px;">Motivo</div>
            <div style="font-size:14px;color:#0f172a;line-height:1.7;">{{ $motivo }}</div>
          </td></tr>
        </table>
        @endif

        <p style="font-size:14px;color:#475569;margin:0;line-height:1.7;">
          Si crees que esto es un error, comunícate directamente con el administrador de tu edificio o conjunto.
        </p>
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
