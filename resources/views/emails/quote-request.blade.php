<p>Llegó una solicitud de cotización del plan Empresarial.</p>
<ul>
    <li><strong>Nombre:</strong> {{ $request['name'] }}</li>
    <li><strong>Firma o despacho:</strong> {{ $request['firm'] }}</li>
    <li><strong>Correo:</strong> {{ $request['email'] }}</li>
    <li><strong>Teléfono / WhatsApp:</strong> {{ $request['phone'] ?? 'No indicado' }}</li>
    <li><strong>Oficinas:</strong> {{ $request['offices'] ?? 'No indicado' }}</li>
</ul>
@if ($request['message'])
    <p><strong>Lo que necesitan:</strong></p>
    <p>{!! nl2br(e($request['message'])) !!}</p>
@endif
<p>Responde a este correo para contestarles directamente.</p>
