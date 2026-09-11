<p>Hola {{ $document->receiver_snapshot['name'] }},</p>
<p>{{ $document->fiscal_snapshot['name'] }} ha generado el documento de prueba
{{ $document->series }}-{{ $document->number }} por {{ \App\Services\Fel\FelPdfService::money($document->total_cents) }}.</p>
<p>Estado: Certificada (simulada). Se adjunta su representación PDF.</p>
<p><strong>AMBIENTE DE PRUEBA — DOCUMENTO FEL SIMULADO — NO VÁLIDO ANTE SAT</strong></p>
<p>{{ config('fel.notice') }}</p>
