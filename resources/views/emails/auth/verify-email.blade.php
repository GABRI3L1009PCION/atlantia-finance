<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica tu correo electrónico</title>
</head>
<body style="margin:0;padding:0;background:#f7f3f3;color:#111827;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Confirma tu correo para continuar configurando Atlantia Finance.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f7f3f3;">
    <tr>
        <td align="center" style="padding:32px 14px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #eadede;border-radius:18px;overflow:hidden;box-shadow:0 12px 35px rgba(4,11,21,.10);">
                <tr>
                    <td style="background:#040b15;padding:30px 38px;border-bottom:5px solid #640000;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td width="52" valign="middle">
                                    <div style="width:42px;height:42px;line-height:42px;text-align:center;border:2px solid #b67e7d;color:#ffffff;font-family:Georgia,serif;font-size:27px;font-weight:bold;border-radius:12px;">A</div>
                                </td>
                                <td valign="middle" style="color:#ffffff;font-family:Georgia,'Times New Roman',serif;font-size:25px;letter-spacing:.2px;">Atlantia Finance</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:42px 46px 36px;">
                        <div style="margin:0 auto 20px;width:70px;height:70px;line-height:70px;text-align:center;border-radius:50%;background:#f6e4e4;color:#640000;font-size:34px;">&#9993;</div>
                        <h1 style="margin:0 0 14px;text-align:center;color:#640000;font-family:Georgia,'Times New Roman',serif;font-size:31px;line-height:1.25;">Verifica tu correo electrónico</h1>
                        <div style="width:90px;height:2px;margin:0 auto 30px;background:#d7aaaa;"></div>
                        <p style="margin:0 0 14px;font-size:17px;line-height:1.65;"><strong>¡Hola, {{ $userName }}!</strong></p>
                        <p style="margin:0 0 28px;color:#4b5563;font-size:16px;line-height:1.65;">Gracias por crear tu cuenta en Atlantia Finance. Confirma esta dirección para proteger tu identidad y continuar con la contratación de tu plan.</p>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="center" style="padding-bottom:28px;">
                                    <a href="{{ $verificationUrl }}" style="display:inline-block;background:#640000;color:#ffffff;text-decoration:none;font-size:16px;font-weight:bold;padding:16px 30px;border-radius:9px;box-shadow:0 5px 12px rgba(100,0,0,.22);">Verificar correo electrónico</a>
                                </td>
                            </tr>
                        </table>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#fbf5f5;border:1px solid #efdede;border-radius:10px;">
                            <tr>
                                <td style="padding:17px 20px;color:#5b4a4a;font-size:14px;line-height:1.55;">Por seguridad, este enlace vence en {{ $expiresInMinutes }} minutos y solo puede utilizarse para tu cuenta. Si no realizaste este registro, ignora el mensaje.</td>
                            </tr>
                        </table>
                        <p style="margin:28px 0 8px;color:#6b7280;font-size:13px;line-height:1.5;">Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
                        <p style="margin:0;word-break:break-all;color:#640000;font-size:12px;line-height:1.5;"><a href="{{ $verificationUrl }}" style="color:#640000;">{{ $verificationUrl }}</a></p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#040b15;padding:24px 34px;text-align:center;color:#cbd5e1;font-size:12px;line-height:1.6;">
                        <strong style="color:#ffffff;letter-spacing:1.5px;">ATLANTIA SYSTEMS</strong><br>
                        Gestión contable inteligente y segura<br>
                        Este es un correo automático; no compartas el enlace de verificación.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
