<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #061633; font-size: 14px; line-height: 1.5;">
    <p>Hola {{ $name }},</p>
    <p>Estas son las alertas de Atlantia Finance que requieren tu atención:</p>
    <ul style="padding-left: 18px;">
        @foreach ($items as $item)
            <li style="margin-bottom: 8px;"><strong>{{ $item['title'] }}</strong><br><span style="color: #526b92;">{{ $item['detail'] }}</span></li>
        @endforeach
    </ul>
    <p style="color: #526b92; font-size: 12px;">Puedes cambiar qué alertas recibes, cada cuánto y por qué canal en Configuración → Notificaciones.</p>
</body>
</html>
