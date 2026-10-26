<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $verified ? 'Correo confirmado' : 'Enlace no válido' }} · Atlantia Finance</title>
</head>
<body style="margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f1ef; font-family: Arial, sans-serif; color: #1f2937; padding: 16px; box-sizing: border-box;">
    <div style="max-width: 440px; background: #ffffff; border-radius: 14px; padding: 28px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,.08);">
        @if ($verified)
            <h1 style="font-size: 20px; margin: 0 0 10px; color: #640000;">Correo confirmado</h1>
            <p style="margin: 0; line-height: 1.6;">Desde ahora, cuando tus clientes respondan a los recordatorios, estados de cuenta y facturas, la respuesta llegará a este correo. Ya puedes cerrar esta pestaña.</p>
        @else
            <h1 style="font-size: 20px; margin: 0 0 10px; color: #640000;">Enlace no válido</h1>
            <p style="margin: 0; line-height: 1.6;">El enlace venció o el correo se cambió después de enviarlo. Pide uno nuevo desde Configuración en Atlantia Finance.</p>
        @endif
    </div>
</body>
</html>
