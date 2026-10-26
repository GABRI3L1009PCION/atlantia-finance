<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 46px 36px 40px 36px; } body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #111; }
h1 { font-size: 15px; margin: 0 0 2px 0; text-transform: uppercase; letter-spacing: .5px; } h2 { font-size: 10.5px; margin: 14px 0 4px 0; text-transform: uppercase; }
.company { font-size: 10px; font-weight: bold; } .meta { margin: 2px 0 8px 0; color: #333; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
th { border-top: 1px solid #111; border-bottom: 1px solid #111; text-align: left; padding: 4px 3px; font-size: 8px; text-transform: uppercase; }
td { padding: 2.5px 3px; vertical-align: top; overflow-wrap: break-word; } .number { text-align: right; white-space: nowrap; }
tr.heading td { font-weight: bold; padding-top: 6px; } tr.subtotal td { border-top: 1px solid #999; font-style: italic; } tr.total td { border-top: 1.5px solid #111; border-bottom: 1.5px solid #111; font-weight: bold; }
.signatures { margin-top: 40px; width: 100%; } .signatures td { width: 50%; text-align: center; padding-top: 34px; } .line { border-top: 1px solid #111; width: 70%; margin: 0 auto; padding-top: 3px; }
</style></head><body>
<h1>{{ $book['title'] }}</h1>
<div class="company">{{ $book['company'] }}@if($book['tax_id'] !== '') · NIT {{ $book['tax_id'] }}@endif</div>
<div class="meta">{{ $book['scope'] }} · Cifras en quetzales (Q)@if($book['authorization']) · Habilitación SAT: {{ $book['authorization'] }}@endif</div>
@foreach($book['sections'] as $section)
@if($section['title'] !== $book['title'])<h2>{{ $section['title'] }}</h2>@endif
<table><thead><tr>@foreach($section['columns'] as $index => $column)<th class="{{ in_array($index, $section['money']) ? 'number' : '' }}" @if($index === 1 && count($section['columns']) > 3) style="width: 46%" @endif>{{ $column }}</th>@endforeach</tr></thead>
<tbody>@foreach($section['rows'] as $row)<tr class="{{ $row['type'] }}">@foreach($row['cells'] as $index => $cell)<td class="{{ in_array($index, $section['money']) ? 'number' : '' }}">{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
@endforeach
@if(in_array($book['title'], ['Libro de Inventarios', 'Libro de Estados Financieros'], true))
<table class="signatures"><tr><td><div class="line">Propietario o representante legal</div></td><td><div class="line">Contador</div></td></tr></table>
@endif
<p class="meta">Impreso el {{ $book['printed_at'] }} con Atlantia Finance.</p>
</body></html>
