<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Fiscal\VatBookService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Monthly VAT books (Libro de Ventas y Servicios, Libro de Compras y Servicios).
 */
class VatBookController extends Controller
{
    private const DOCUMENT_TYPES = ['FACT' => 'Factura', 'FCAM' => 'Factura cambiaria', 'FPEQ' => 'Factura pequeño contribuyente', 'NCRE' => 'Nota de crédito', 'NDEB' => 'Nota de débito'];

    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private VatBookService $books,
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scope($request, $company, 'vat_books.view');
        [$year, $month] = $this->period($request);

        return Inertia::render('accounting/vat-books', [
            'company' => $record,
            'book' => $this->books->book($record->id, $year, $month),
            'periods' => $this->books->periods($record->id),
            'documentTypes' => self::DOCUMENT_TYPES,
            'canClose' => $this->accounting->canModule($organization, $record->id, $request->user(), 'vat_books.close'),
            'canReopen' => $organization->pivot->role === 'owner',
            'canExport' => $this->accounting->canModule($organization, $record->id, $request->user(), 'vat_books.export'),
        ]);
    }

    public function close(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'vat_books.close');
        [$year, $month] = $this->period($request);
        $period = $this->books->close($organization, $request->user(), $record->id, $year, $month);

        return back()->with('vat_book_notice', 'Libro IVA de '.$this->books->label($year, $month).' cerrado. '
            .((int) $period->payable_cents > 0 ? 'IVA por pagar: Q'.number_format($period->payable_cents / 100, 2).'.' : 'Remanente de crédito: Q'.number_format($period->carry_forward_cents / 100, 2).'.'));
    }

    public function reopen(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'vat_books.close');
        abort_unless($organization->pivot->role === 'owner', 403, 'Solo el propietario puede reabrir un Libro IVA cerrado.');
        [$year, $month] = $this->period($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], [
            'reason.min' => 'Explica el motivo de la reapertura (mínimo 10 caracteres).',
        ]);
        $this->books->reopen($organization, $request->user(), $record->id, $year, $month, $data['reason']);

        return back()->with('vat_book_notice', 'Libro IVA de '.$this->books->label($year, $month).' reabierto y registrado en auditoría.');
    }

    public function export(Request $request, string $company, ReportExportService $exports): HttpResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'vat_books.export');
        [$year, $month] = $this->period($request);
        $data = $request->validate([
            'book' => ['required', Rule::in(['sales', 'purchases'])],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);
        $book = $this->books->book($record->id, $year, $month);
        $isSales = $data['book'] === 'sales';
        $this->accounting->audit($organization, $request->user(), 'vat_book.exported', 'companies', $record->id, [
            'book' => $data['book'], 'period' => sprintf('%04d-%02d', $year, $month),
        ]);

        return $exports->download([
            'title' => $isSales ? 'Libro de Ventas y Servicios' : 'Libro de Compras y Servicios',
            'filename' => 'libro-'.($isSales ? 'ventas' : 'compras').'-'.sprintf('%04d-%02d', $year, $month).'-'.$record->tax_id,
            'company' => $record->name.' · NIT '.$record->tax_id,
            'scope' => 'Período: '.$book['label'].($book['status'] === 'closed' ? ' · Cerrado' : ' · Abierto'),
            'note' => 'Montos en quetzales. Los documentos anulados se presentan con valor cero.',
            'headers' => ['Fecha', 'Tipo', 'Serie', 'Número', 'NIT', $isSales ? 'Cliente' : 'Proveedor', 'Estado',
                'Bienes', 'Servicios', 'Exento', 'Pequeño contribuyente', 'IVA', 'Total'],
            'money' => [7, 8, 9, 10, 11, 12],
            'rows' => array_map(fn (array $row): array => [
                $row['date'], $row['document_type'], $row['series'] ?? '', $row['number'] ?? '', $row['tax_id'] ?? '', $row['name'],
                $row['status'] === 'annulled' ? 'Anulado' : 'Vigente',
                $row['taxable_goods_cents'], $row['taxable_services_cents'], $row['exempt_cents'], $row['small_taxpayer_cents'],
                $row['vat_cents'], $row['total_cents'],
            ], $isSales ? $book['sales'] : $book['purchases']),
        ], $data['format'] ?? 'xlsx');
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return [(int) ($data['year'] ?? now()->year), (int) ($data['month'] ?? now()->month)];
    }

    /** @return array{0: Organization, 1: stdClass} */
    private function scope(Request $request, string $company, string $permission): array
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'advanced_reports');
        $isRead = str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
        $record = $this->accounting->company($organization, $request->user(), $company, ! $isRead);
        $this->accounting->assertModule($organization, $record->id, $request->user(), $permission);

        return [$organization, $record];
    }
}
