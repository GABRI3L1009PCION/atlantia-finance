<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 40px 32px; } body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #142135; }
h1 { color: #640000; font-size: 22px; margin-bottom: 6px; } .scope { margin: 10px 0; } .note { background: #f7eeee; padding: 12px; margin-bottom: 18px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } thead { display: table-header-group; } tr { page-break-inside: avoid; } th { background: #640000; color: white; text-align: left; } td, th { padding: 7px 5px; border-bottom: 1px solid #ddd; overflow-wrap: break-word; } .number { text-align: right; } tbody tr:nth-child(even) { background: #faf8f7; }
</style></head><body>
<h1>{{ $report['title'] }}</h1><strong>{{ $report['company'] }}</strong>
<div class="scope">{{ $report['scope'] }}</div><div class="note">{{ $report['note'] }}</div>
<table><thead><tr>@foreach($report['headers'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
<tbody>@foreach($report['rows'] as $row)<tr>@foreach($row as $index => $cell)<td class="{{ in_array($index, $report['money']) ? 'number' : '' }}">{{ in_array($index, $report['money']) ? number_format($cell / 100, 2, '.', ',') : $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
<p>Generado: {{ now()->format('d/m/Y H:i') }} · Atlantia Finance</p>
</body></html>
