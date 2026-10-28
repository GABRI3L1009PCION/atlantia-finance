<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\AccountingService;
use App\Services\Fiscal\SatFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Load files for the SAT desktop tools (Asiste Libros, RetenISR 2 and the Informe de Inventarios). */
class SatFileController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private SatFileService $files, private AccountingService $accounting) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.view', 'advanced_reports');
        [$year, $month] = $this->period($request);
        $today = now();
        $semester = $today->month > 6 ? Carbon::create($today->year, 6, 30) : Carbon::create($today->year - 1, 12, 31);

        return Inertia::render('accounting/sat-files', [
            'company' => $record,
            'year' => $year,
            'month' => $month,
            'summary' => $this->files->summary($record, $year, $month),
            'retenisrFiles' => SatFileService::RETENISR_FILES,
            'inventoryTypes' => SatFileService::INVENTORY_TYPES,
            'concepts' => SatFileService::RETENISR_CONCEPTS,
            'semesterDate' => $semester->toDateString(),
            'canExport' => $this->can($request, $organization, $record, 'vat_books.export'),
        ]);
    }

    public function download(Request $request, string $company, string $file): HttpResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'vat_books.export', 'advanced_reports');
        $data = $request->validate([
            'books' => ['nullable', Rule::in(['all', 'sales', 'purchases'])],
            'kind' => ['nullable', Rule::in(array_keys(SatFileService::RETENISR_FILES))],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'type' => ['nullable', Rule::in(array_keys(SatFileService::INVENTORY_TYPES))],
            'by_request' => ['nullable', 'boolean'],
        ]);
        [$year, $month] = $this->period($request);
        $result = match ($file) {
            'asiste-libros' => $this->files->asisteLibros($record, $year, $month, ($data['books'] ?? 'all') === 'all' ? ['sales', 'purchases'] : [$data['books']]),
            'retenisr' => $this->files->retenIsr($record, $data['kind'] ?? 'rentas_trabajo', $year, $month),
            default => $this->files->inventoryReport($record, $data['as_of'] ?? now()->toDateString(), $data['type'] ?? 'BDPVE', (bool) ($data['by_request'] ?? false)),
        };
        $this->accounting->audit($organization, $request->user(), 'fiscal.sat_file_exported', 'companies', $record->id, [
            'file' => $file, 'kind' => $data['kind'] ?? null, 'period' => sprintf('%04d-%02d', $year, $month), 'lines' => $result['lines'],
        ]);

        return response($result['content'], 200, ['Content-Type' => $result['mime'], 'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $previous = now()->subMonthNoOverflow();

        return [(int) ($data['year'] ?? $previous->year), (int) ($data['month'] ?? $previous->month)];
    }
}
