<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 40px 36px; } body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #142135; }
h1 { color: #640000; font-size: 20px; margin: 0 0 4px; } h2 { color: #640000; font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #e5d5d5; padding-bottom: 3px; }
.meta { color: #526b92; } .box { background: #f7eeee; padding: 8px; margin: 6px 0; } table { width: 100%; border-collapse: collapse; } td, th { padding: 5px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
th { background: #640000; color: #fff; } .finding { page-break-inside: avoid; border: 1px solid #e5d5d5; padding: 8px; margin: 8px 0; } .label { font-weight: bold; }
</style></head><body>
<h1>Informe de auditoría {{ $content['general']['code'] }}</h1>
<div class="meta">{{ $content['general']['company'] }} · {{ $content['general']['type'] }} · Período {{ $content['period'] }} · Versión {{ $report->version }} · {{ $status }}</div>
<h2>Información general</h2>
<p><span class="label">Auditoría:</span> {{ $content['general']['title'] }}<br><span class="label">Auditor responsable:</span> {{ $content['general']['lead_auditor'] }}</p>
<h2>Objetivo</h2><p>{{ $content['objective'] ?? '—' }}</p>
<h2>Alcance</h2>
<p><span class="label">Áreas incluidas (versión {{ $content['scope']['version'] }}):</span> {{ implode(', ', $content['scope']['areas']) ?: '—' }}<br><span class="label">Áreas excluidas:</span> {{ implode(', ', $content['scope']['excluded']) ?: 'Ninguna' }}</p>
<h2>Metodología</h2><p>{{ $content['methodology'] ?? '—' }}</p>
<h2>Limitaciones</h2><p>{{ $content['limitations'] ?? 'Sin limitaciones registradas.' }}</p>
<h2>Resumen ejecutivo</h2><div class="box">{{ $content['executive_summary'] ?? 'Pendiente de redactar.' }}</div>
<h2>Riesgos principales</h2>
<table><thead><tr><th>Código</th><th>Riesgo</th><th>Nivel</th></tr></thead><tbody>
@forelse($content['risks'] as $risk)<tr><td>{{ $risk['code'] }}</td><td>{{ $risk['title'] }}</td><td>{{ $risk['level'] }}</td></tr>@empty<tr><td colspan="3">Sin riesgos altos o críticos registrados.</td></tr>@endforelse
</tbody></table>
<h2>Procedimientos realizados</h2>
<table><thead><tr><th>Código</th><th>Procedimiento</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>
@foreach($content['procedures'] as $procedure)<tr><td>{{ $procedure['code'] }}</td><td>{{ $procedure['title'] }}</td><td>{{ $procedure['status'] }}</td><td>{{ $procedure['result'] ?? '—' }}</td></tr>@endforeach
</tbody></table>
<h2>Hallazgos y recomendaciones</h2>
@forelse($content['findings'] as $finding)
<div class="finding"><strong>{{ $finding['code'] }} · {{ $finding['title'] }}</strong> ({{ $finding['severity'] }} · {{ $finding['status'] }})<br>
<span class="label">Criterio:</span> {{ $finding['criterion'] }}<br><span class="label">Condición:</span> {{ $finding['condition'] }}<br>
<span class="label">Causa:</span> {{ $finding['cause'] ?? '—' }}<br><span class="label">Efecto / riesgo:</span> {{ $finding['effect'] }}<br><span class="label">Recomendación:</span> {{ $finding['recommendation'] }}
@if($finding['response'])<div class="box"><span class="label">Respuesta de la administración ({{ $finding['response']['acceptance'] }}):</span> {{ $finding['response']['response'] }}@if($finding['response']['action_plan'])<br><span class="label">Plan de acción:</span> {{ $finding['response']['action_plan'] }} · fecha compromiso {{ $finding['response']['target_date'] }}@endif</div>@endif
</div>
@empty<p>No se emitieron hallazgos.</p>@endforelse
<h2>Conclusiones</h2><div class="box">{{ $content['conclusions'] ?? 'Pendiente de redactar.' }}</div>
<p class="meta">Informe generado por Atlantia Finance a partir de la auditoría registrada. @if($report->issued_at) Emitido el {{ \Illuminate\Support\Carbon::parse($report->issued_at)->format('d/m/Y H:i') }}. @endif</p>
</body></html>
