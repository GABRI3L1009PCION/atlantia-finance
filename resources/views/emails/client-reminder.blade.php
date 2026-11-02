<!DOCTYPE html>
<html lang="es">
<body style="margin: 0; padding: 24px 12px; background: #f5f1ef; font-family: Arial, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr><td style="background: {{ $identity['color'] }}; color: #ffffff; padding: 18px 24px;">
            <div style="font-size: 17px; font-weight: bold;">{{ $identity['firm'] }}</div>
            <div style="font-size: 12px; opacity: 0.85;">Recordatorio automático de su oficina contable</div>
        </td></tr>
        <tr><td style="padding: 24px;">
            <p style="margin: 0 0 18px;">{{ $body }}</p>
            <a href="{{ $portalUrl }}" style="display: inline-block; background: {{ $identity['color'] }}; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: bold;">Abrir mi portal</a>
        </td></tr>
        <tr><td style="padding: 16px 24px; background: #faf7f5; color: #6b7280; font-size: 12px; border-top: 1px solid #ece4e0;">
            {{ $identity['firm'] }}@if ($identity['phone']) · Tel. {{ $identity['phone'] }}@endif
            @if ($identity['reply_to'])<br>Para cualquier consulta responda este correo o escriba a {{ $identity['reply_to'] }}.@endif
            <br>Si ya lo atendió, por favor ignore este mensaje.
        </td></tr>
    </table>
</body>
</html>
