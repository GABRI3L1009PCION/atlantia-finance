<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 46px 30px 36px 30px; } body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5px; color: #111; }
h1 { font-size: 13px; margin: 0 0 2px 0; text-transform: uppercase; letter-spacing: .5px; }
.company { font-size: 9.5px; font-weight: bold; } .meta { margin: 2px 0 8px 0; color: #333; }
.worker { width: 100%; border-collapse: collapse; margin-bottom: 8px; } .worker td { border: 1px solid #999; padding: 3px 4px; } .worker td.label { background: #f1f1f1; font-weight: bold; width: 13%; }
.lines { width: 100%; border-collapse: collapse; table-layout: fixed; } .lines th { border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 3px 2px; font-size: 6.8px; text-transform: uppercase; text-align: right; }
.lines th:first-child, .lines td:first-child { text-align: left; } .lines td { padding: 2.5px 2px; text-align: right; white-space: nowrap; } .lines tr.total td { border-top: 1.5px solid #111; font-weight: bold; }
.folio { page-break-after: always; } .folio:last-child { page-break-after: auto; }
.signatures { margin-top: 30px; width: 100%; } .signatures td { width: 50%; text-align: center; padding-top: 28px; } .line { border-top: 1px solid #111; width: 60%; margin: 0 auto; padding-top: 3px; }
</style></head><body>
@forelse($workers as $worker)
<div class="folio">
<h1>Libro de salarios · {{ $year }}</h1>
<div class="company">{{ $company->name }}@if(filled($company->tax_id ?? null)) · NIT {{ $company->tax_id }}@endif</div>
<div class="meta">Formato único (Acuerdo Ministerial 124-2019) · Cifras en quetzales (Q) · Datos de las nóminas aprobadas</div>
<table class="worker">
<tr><td class="label">Trabajador</td><td>{{ $worker['name'] }}</td><td class="label">DPI</td><td>{{ $worker['dpi'] ?: 'Sin indicar' }}</td><td class="label">Afiliación IGSS</td><td>{{ $worker['igss_number'] ?: 'Sin indicar' }}</td></tr>
<tr><td class="label">Edad</td><td>{{ $worker['age'] }}</td><td class="label">Sexo</td><td>{{ $worker['sex'] }}</td><td class="label">Nacionalidad</td><td>{{ $worker['nationality'] }}</td></tr>
<tr><td class="label">Ocupación</td><td>{{ $worker['position'] ?: 'Sin indicar' }}</td><td class="label">Inicio de la relación</td><td>{{ $worker['hire_date'] }}</td><td class="label">Fin de la relación</td><td>{{ $worker['termination_date'] ?: 'Vigente' }}</td></tr>
</table>
<table class="lines"><thead><tr>@foreach($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
<tbody>@foreach($worker['rows'] as $row)<tr>@foreach(app(\App\Services\Payroll\SalaryBookService::class)->cells($row) as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach
<tr class="total">@foreach(app(\App\Services\Payroll\SalaryBookService::class)->cells($worker['totals']) as $cell)<td>{{ $cell }}</td>@endforeach</tr></tbody></table>
<table class="signatures"><tr><td><div class="line">Firma del patrono o representante</div></td><td><div class="line">Firma del trabajador</div></td></tr></table>
<p class="meta">Impreso el {{ $printed_at }} con Atlantia Finance.</p>
</div>
@empty
<h1>Libro de salarios · {{ $year }}</h1>
<div class="company">{{ $company->name }}</div>
<p class="meta">No hay nóminas aprobadas en el año.</p>
@endforelse
</body></html>
