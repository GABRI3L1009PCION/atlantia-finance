<!DOCTYPE html>
<html lang="es">
<body style="margin: 0; padding: 24px 12px; background: #f5f1ef; font-family: Arial, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr><td style="background: #640000; color: #ffffff; padding: 18px 24px; font-size: 16px; font-weight: bold;">Aviso de seguridad · Atlantia Finance</td></tr>
        <tr><td style="padding: 24px;">
            <p style="margin: 0 0 12px;">Hola {{ $name }},</p>
            <p style="margin: 0 0 12px; font-weight: bold;">{{ $title }}</p>
            @foreach ($lines as $line)
                <p style="margin: 0 0 10px;">{{ $line }}</p>
            @endforeach
            <p style="margin: 20px 0 0; text-align: center;">
                <a href="{{ $url }}" style="display: inline-block; background: #730009; color: #ffffff; text-decoration: none; font-weight: bold; padding: 12px 22px; border-radius: 10px;">Abrir Clientes</a>
            </p>
        </td></tr>
    </table>
</body>
</html>
