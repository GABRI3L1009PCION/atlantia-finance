<!DOCTYPE html>
<html lang="es">
<body style="margin: 0; padding: 24px 12px; background: #f5f1ef; font-family: Arial, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr><td style="background: #640000; color: #ffffff; padding: 18px 24px; font-size: 16px; font-weight: bold;">Atlantia Finance</td></tr>
        <tr><td style="padding: 24px;">
            <p style="margin: 0 0 12px;">Hola,</p>
            <p style="margin: 0 0 12px;"><strong>{{ $firm }}</strong> quiere que las respuestas de sus clientes a los recordatorios de cobro, estados de cuenta y facturas lleguen a este correo.</p>
            <p style="margin: 0 0 20px;">Confírmalo con este botón:</p>
            <p style="margin: 0 0 20px; text-align: center;">
                <a href="{{ $url }}" style="display: inline-block; background: #730009; color: #ffffff; text-decoration: none; font-weight: bold; padding: 12px 22px; border-radius: 10px;">Confirmar correo</a>
            </p>
            <p style="margin: 0; color: #6b7280; font-size: 12px;">El enlace vence en {{ $hours }} horas. Si no pediste este cambio, ignora este mensaje: no se activará nada.</p>
        </td></tr>
    </table>
</body>
</html>
