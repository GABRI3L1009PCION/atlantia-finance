<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;

/**
 * The legal books of the Código de Comercio (Diario, Mayor, Inventarios and Estados Financieros) printed for the sheets
 * authorized by the SAT: every page carries the company, its NIT, the book and a folio number that continues from the
 * last printing of that book. Each printing is recorded with its folios and the hash of the file.
 *
 * @phpstan-type BookRow array{type: string, cells: list<string>}
 * @phpstan-type BookSection array{title: string, columns: list<string>, money: list<int>, rows: list<BookRow>}
 */
class LegalBooksService
{
    public const BOOKS = ['diario' => 'Libro Diario', 'mayor' => 'Libro Mayor', 'inventarios' => 'Libro de Inventarios', 'estados' => 'Libro de Estados Financieros'];

    /** Rows a single printing may hold; a larger book is printed in several parts (by quarter or month). */
    private const MAX_ROWS = 6000;

    public function __construct(private FinancialStatementService $statements, private InventoryService $inventory, private StatementCertificationService $certification) {}

    /**
     * Next folio of each book: one after the last printed sheet.
     *
     * @return array<string, int>
     */
    public function nextFolios(int $companyId): array
    {
        $last = DB::table('legal_book_prints')->where('company_id', $companyId)->groupBy('book')->selectRaw('book, MAX(last_folio) AS folio')->pluck('folio', 'book');

        return array_map(fn (string $book): int => (int) ($last[$book] ?? 0) + 1, array_combine(array_keys(self::BOOKS), array_keys(self::BOOKS)));
    }

    /** @return list<array{book: string, book_label: string, from: string, to: string, first_folio: int, last_folio: int, authorization: string|null, printed_by: string, printed_at: string}> */
    public function history(int $companyId): array
    {
        return array_values(DB::table('legal_book_prints as p')->join('users as u', 'u.id', '=', 'p.printed_by')->where('p.company_id', $companyId)->orderByDesc('p.id')->limit(30)
            ->get(['p.book', 'p.period_from', 'p.period_to', 'p.first_folio', 'p.last_folio', 'p.authorization', 'u.name', 'p.created_at'])
            ->map(fn (stdClass $row): array => ['book' => (string) $row->book, 'book_label' => self::BOOKS[$row->book] ?? (string) $row->book, 'from' => (string) $row->period_from, 'to' => (string) $row->period_to,
                'first_folio' => (int) $row->first_folio, 'last_folio' => (int) $row->last_folio, 'authorization' => $row->authorization, 'printed_by' => (string) $row->name,
                'printed_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String()])->all());
    }

    /**
     * Prints a book and records the folios it used.
     *
     * @return array{content: string, filename: string, first_folio: int, last_folio: int}
     */
    public function print(Organization $organization, User $user, stdClass $company, string $book, string $from, string $to, ?int $firstFolio, ?string $authorization): array
    {
        abort_unless(isset(self::BOOKS[$book]), 404);
        $sections = match ($book) {
            'diario' => $this->journal((int) $company->id, $from, $to),
            'mayor' => $this->ledger((int) $company->id, $from, $to),
            'inventarios' => $this->inventories((int) $company->id, $to),
            default => $this->statementsBook((int) $company->id, $from, $to),
        };
        $rows = (int) array_sum(array_map(fn (array $section): int => count($section['rows']), $sections));
        if ($rows > self::MAX_ROWS) {
            throw ValidationException::withMessages(['from' => 'El libro tiene '.number_format($rows).' líneas para ese rango; imprímelo por trimestre o por mes (continúa los folios).']);
        }
        $first = $firstFolio ?? $this->nextFolios((int) $company->id)[$book];
        $taxId = (string) ($company->tax_id ?? '');
        $header = $company->name.($taxId === '' ? '' : ' · NIT '.$taxId).' · '.self::BOOKS[$book];
        $scope = $book === 'inventarios' ? 'Al '.CarbonImmutable::parse($to)->format('d/m/Y') : 'Del '.CarbonImmutable::parse($from)->format('d/m/Y').' al '.CarbonImmutable::parse($to)->format('d/m/Y');
        $certification = match ($book) {
            'inventarios' => $this->certification->certify($company, 'Libro de Inventarios', $to, $to,
                (int) ((array) $this->statements->position((int) $company->id, $to)['totals'])['assets'], 'un total de activo'),
            'estados' => $this->certification->certify($company, 'Libro de Estados Financieros', $from, $to,
                (int) ((array) $this->statements->income((int) $company->id, $from, $to)['totals'])['net_result'], 'un resultado del período'),
            default => null,
        };
        [$content, $pages] = $this->render(['title' => self::BOOKS[$book], 'company' => (string) $company->name, 'tax_id' => $taxId, 'scope' => $scope, 'authorization' => $authorization,
            'sections' => $sections, 'certification' => $certification, 'printed_at' => now()->format('d/m/Y H:i')], $header, $first);
        $last = $first + $pages - 1;
        DB::table('legal_book_prints')->insert(['organization_id' => $organization->id, 'company_id' => $company->id, 'book' => $book, 'period_from' => $book === 'inventarios' ? $to : $from, 'period_to' => $to,
            'first_folio' => $first, 'last_folio' => $last, 'authorization' => $authorization === null ? null : mb_substr($authorization, 0, 120), 'file_hash' => hash('sha256', $content),
            'printed_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'action' => 'legal_book.printed', 'auditable_type' => 'companies', 'auditable_id' => $company->id,
            'new_values' => json_encode(['book' => $book, 'from' => $from, 'to' => $to, 'first_folio' => $first, 'last_folio' => $last], JSON_THROW_ON_ERROR), 'occurred_at' => now()]);

        return ['content' => $content, 'filename' => 'libro-'.$book.'-'.$from.'-'.$to.'-folios-'.$first.'-'.$last.'.pdf', 'first_folio' => $first, 'last_folio' => $last];
    }

    /**
     * Libro Diario: every posted entry in date order with its lines and totals.
     *
     * @return list<BookSection>
     */
    public function journal(int $companyId, string $from, string $to): array
    {
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])
            ->whereBetween('e.entry_date', [$from, $to])->orderBy('e.entry_date')->orderBy('e.number')->orderBy('e.id')->get(['e.id', 'e.number', 'e.entry_date', 'e.description', 'e.reference']);
        $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->whereIn('l.journal_entry_id', $entries->pluck('id')->all() === [] ? [-1] : $entries->pluck('id')->all())
            ->orderBy('l.journal_entry_id')->orderBy('l.position')->get(['l.journal_entry_id', 'a.code', 'a.name', 'l.description', 'l.debit_cents', 'l.credit_cents'])->groupBy('journal_entry_id');
        $rows = [];
        $debit = 0;
        $credit = 0;
        foreach ($entries as $entry) {
            $rows[] = ['type' => 'heading', 'cells' => [CarbonImmutable::parse((string) $entry->entry_date)->format('d/m/Y'), 'Partida No. '.($entry->number ?? $entry->id).' · '.$entry->description.($entry->reference ? ' · Ref. '.$entry->reference : ''), '', '']];
            $entryDebit = 0;
            $entryCredit = 0;
            foreach ($lines->get($entry->id, collect()) as $line) {
                $rows[] = ['type' => 'line', 'cells' => ['', $line->code.' '.$line->name.($line->description ? ' — '.$line->description : ''), self::amount((int) $line->debit_cents), self::amount((int) $line->credit_cents)]];
                $entryDebit += (int) $line->debit_cents;
                $entryCredit += (int) $line->credit_cents;
            }
            $rows[] = ['type' => 'subtotal', 'cells' => ['', 'Sumas iguales', self::amount($entryDebit), self::amount($entryCredit)]];
            $debit += $entryDebit;
            $credit += $entryCredit;
        }
        $rows[] = ['type' => 'total', 'cells' => ['', 'Total del libro ('.$entries->count().' partida(s))', self::amount($debit), self::amount($credit)]];

        return [['title' => 'Libro Diario', 'columns' => ['Fecha', 'Partida, cuenta y concepto', 'Debe', 'Haber'], 'money' => [2, 3], 'rows' => $rows]];
    }

    /**
     * Libro Mayor: each account with its opening balance, its movements and the running balance.
     *
     * @return list<BookSection>
     */
    public function ledger(int $companyId, string $from, string $to): array
    {
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name', 'type'])->keyBy('id');
        $opening = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '<', $from)->groupBy('l.ledger_account_id')
            ->selectRaw('l.ledger_account_id, CAST(SUM(l.debit_cents) - SUM(l.credit_cents) AS BIGINT) AS balance')->pluck('balance', 'ledger_account_id');
        $movements = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('e.number')->orderBy('l.position')->get(['l.ledger_account_id', 'e.entry_date', 'e.number', 'e.description', 'l.debit_cents', 'l.credit_cents'])->groupBy('ledger_account_id');
        $rows = [];
        foreach ($accounts as $id => $account) {
            $debitNature = in_array($account->type, ['asset', 'expense'], true);
            $balance = ($debitNature ? 1 : -1) * (int) ($opening[$id] ?? 0);
            $lines = $movements->get($id, collect());
            if ($balance === 0 && $lines->isEmpty()) {
                continue;
            }
            $rows[] = ['type' => 'heading', 'cells' => ['', $account->code.' '.$account->name, '', '', self::amount($balance)]];
            $debit = 0;
            $credit = 0;
            foreach ($lines as $line) {
                $balance += ($debitNature ? 1 : -1) * ((int) $line->debit_cents - (int) $line->credit_cents);
                $debit += (int) $line->debit_cents;
                $credit += (int) $line->credit_cents;
                $rows[] = ['type' => 'line', 'cells' => [CarbonImmutable::parse((string) $line->entry_date)->format('d/m/Y'), 'Partida '.($line->number ?? '').' · '.$line->description,
                    self::amount((int) $line->debit_cents), self::amount((int) $line->credit_cents), self::amount($balance)]];
            }
            $rows[] = ['type' => 'subtotal', 'cells' => ['', 'Movimiento y saldo final', self::amount($debit), self::amount($credit), self::amount($balance)]];
        }

        return [['title' => 'Libro Mayor', 'columns' => ['Fecha', 'Cuenta y concepto', 'Debe', 'Haber', 'Saldo'], 'money' => [2, 3, 4], 'rows' => $rows]];
    }

    /**
     * Libro de Inventarios: the detail of what the company has and owes at a date, with the inventory of goods.
     *
     * @return list<BookSection>
     */
    public function inventories(int $companyId, string $to, bool $withDetail = true): array
    {
        $position = $this->statements->position($companyId, $to);
        $rows = [];
        foreach (['current_assets' => 'ACTIVO CORRIENTE', 'non_current_assets' => 'ACTIVO NO CORRIENTE', 'current_liabilities' => 'PASIVO CORRIENTE', 'non_current_liabilities' => 'PASIVO NO CORRIENTE', 'equity' => 'PATRIMONIO'] as $section => $label) {
            $rows[] = ['type' => 'heading', 'cells' => [$label, '', '']];
            foreach ((array) $position[$section] as $account) {
                $account = (array) $account;
                $rows[] = ['type' => 'line', 'cells' => [$account['code'].' '.$account['name'], '', self::amount((int) $account['cents'])]];
            }
            if ($section === 'equity') {
                $rows[] = ['type' => 'line', 'cells' => ['Resultado acumulado y del ejercicio', '', self::amount((int) $position['result_cents'])]];
            }
            $total = (int) ((array) $position['totals'])[$section === 'equity' ? 'equity_with_result' : $section];
            $rows[] = ['type' => 'subtotal', 'cells' => ['Total '.mb_strtolower($label), '', self::amount($total)]];
        }
        $totals = (array) $position['totals'];
        $rows[] = ['type' => 'total', 'cells' => ['Total activo', '', self::amount((int) $totals['assets'])]];
        $rows[] = ['type' => 'total', 'cells' => ['Total pasivo y patrimonio', '', self::amount((int) $totals['liabilities_and_equity'])]];
        $sections = [['title' => 'Inventario general al '.CarbonImmutable::parse($to)->format('d/m/Y'), 'columns' => ['Cuenta', '', 'Saldo'], 'money' => [2], 'rows' => $rows]];
        // Stock as the kardex had it at the end of the book's date, not today.
        $items = array_values(array_filter($this->inventory->items($companyId, $to), fn (array $item): bool => $item['quantity'] != 0));
        if ($items !== []) {
            $sections[] = ['title' => 'Detalle de mercaderías y existencias', 'columns' => ['Producto', 'Existencia', 'Costo unitario', 'Valor'], 'money' => [2, 3],
                'rows' => [...array_map(fn (array $item): array => ['type' => 'line', 'cells' => [$item['code'].' '.$item['name'], rtrim(rtrim(number_format((float) $item['quantity'], 4), '0'), '.').' '.$item['unit'],
                    self::amount($item['average_cost_cents']), self::amount($item['value_cents'])]], $items),
                    ['type' => 'total', 'cells' => ['Total existencias', '', '', self::amount((int) array_sum(array_column($items, 'value_cents')))]]]];
            $count = DB::table('inventory_counts')->where('company_id', $companyId)->where('status', 'applied')->where('count_date', '<=', $to)->orderByDesc('count_date')->first(['id', 'count_date']);
            $sections[array_key_last($sections)]['rows'][] = ['type' => 'line', 'cells' => [$count === null
                ? 'Sin toma física aplicada: existencias según el kardex.'
                : 'Existencias según la toma física #'.$count->id.' del '.CarbonImmutable::parse((string) $count->count_date)->format('d/m/Y').' y los movimientos posteriores.', '', '', '']];
        }

        return $withDetail ? [...$sections, ...$this->details($companyId, $to)] : $sections;
    }

    /**
     * The detail the Código de Comercio asks for besides the balances: banks, what each customer owes, what is owed to
     * each supplier and the fixed assets with their depreciation, all at the book's date.
     *
     * @return list<BookSection>
     */
    private function details(int $companyId, string $to): array
    {
        $sections = [];
        $banks = DB::table('bank_accounts as b')->leftJoin('journal_lines as l', 'l.ledger_account_id', '=', 'b.ledger_account_id')
            ->leftJoin('journal_entries as e', fn ($join) => $join->on('e.id', '=', 'l.journal_entry_id')->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '<=', $to))
            ->where('b.company_id', $companyId)->groupBy('b.id', 'b.bank_name', 'b.account_name', 'b.last_four', 'b.currency')->orderBy('b.bank_name')
            ->selectRaw('b.bank_name, b.account_name, b.last_four, b.currency, CAST(COALESCE(SUM(CASE WHEN e.id IS NULL THEN 0 ELSE l.debit_cents - l.credit_cents END), 0) AS BIGINT) as balance')->get();
        if ($banks->isNotEmpty()) {
            $sections[] = ['title' => 'Detalle de bancos', 'columns' => ['Banco y cuenta', 'Moneda', 'Saldo en libros'], 'money' => [2],
                'rows' => [...$banks->map(fn (stdClass $bank): array => ['type' => 'line', 'cells' => [$bank->bank_name.' · '.$bank->account_name.($bank->last_four ? ' ****'.$bank->last_four : ''), (string) $bank->currency, self::amount((int) $bank->balance)]])->all(),
                    ['type' => 'total', 'cells' => ['Total bancos', '', self::amount((int) $banks->sum('balance'))]]]];
        }

        $receivable = DB::table('sales_invoices as i')->where('i.company_id', $companyId)->where('i.status', 'issued')->where('i.issued_on', '<=', $to)
            ->groupBy('i.customer_name', 'i.customer_tax_id')->orderBy('i.customer_name')
            ->selectRaw('i.customer_name as name, i.customer_tax_id as tax_id, CAST(SUM(i.total_cents) AS BIGINT) as invoiced')
            ->selectRaw('CAST(COALESCE(SUM((SELECT SUM(r.amount_cents) FROM sales_receipts r WHERE r.sales_invoice_id = i.id AND r.voided_at IS NULL AND r.received_on <= ?)), 0) AS BIGINT) as settled', [$to])
            ->selectRaw("CAST(COALESCE(SUM((SELECT SUM(c.amount_cents) FROM sales_credit_notes c WHERE c.sales_invoice_id = i.id AND c.status = 'issued' AND c.issued_on <= ?)), 0) AS BIGINT) as credited", [$to])
            ->get()->map(fn (stdClass $row): array => ['name' => (string) $row->name, 'tax_id' => (string) ($row->tax_id ?? ''), 'cents' => (int) $row->invoiced - (int) $row->settled - (int) $row->credited])
            ->filter(fn (array $row): bool => $row['cents'] > 0)->values();
        if ($receivable->isNotEmpty()) {
            $sections[] = ['title' => 'Detalle de cuentas por cobrar', 'columns' => ['Cliente', 'NIT', 'Saldo'], 'money' => [2],
                'rows' => [...$receivable->map(fn (array $row): array => ['type' => 'line', 'cells' => [$row['name'], $row['tax_id'], self::amount($row['cents'])]])->all(),
                    ['type' => 'total', 'cells' => ['Total cuentas por cobrar', '', self::amount((int) $receivable->sum('cents'))]]]];
        }

        $payable = DB::table('purchase_documents as d')->where('d.company_id', $companyId)->where('d.status', 'approved')->where('d.document_date', '<=', $to)
            ->groupBy('d.supplier_name', 'd.supplier_tax_id')->orderBy('d.supplier_name')
            ->selectRaw('d.supplier_name as name, d.supplier_tax_id as tax_id, CAST(SUM(d.total_cents) AS BIGINT) as invoiced')
            ->selectRaw('CAST(COALESCE(SUM((SELECT SUM(p.amount_cents) FROM purchase_payments p WHERE p.purchase_document_id = d.id AND p.voided_at IS NULL AND p.paid_on <= ?)), 0) AS BIGINT) as settled', [$to])
            ->selectRaw("CAST(COALESCE(SUM((SELECT SUM(c.amount_cents) FROM purchase_credit_notes c WHERE c.purchase_document_id = d.id AND c.status = 'issued' AND c.issued_on <= ?)), 0) AS BIGINT) as credited", [$to])
            ->get()->map(fn (stdClass $row): array => ['name' => (string) $row->name, 'tax_id' => (string) ($row->tax_id ?? ''), 'cents' => (int) $row->invoiced - (int) $row->settled - (int) $row->credited])
            ->filter(fn (array $row): bool => $row['cents'] > 0)->values();
        if ($payable->isNotEmpty()) {
            $sections[] = ['title' => 'Detalle de cuentas por pagar', 'columns' => ['Proveedor', 'NIT', 'Saldo'], 'money' => [2],
                'rows' => [...$payable->map(fn (array $row): array => ['type' => 'line', 'cells' => [$row['name'], $row['tax_id'], self::amount($row['cents'])]])->all(),
                    ['type' => 'total', 'cells' => ['Total cuentas por pagar', '', self::amount((int) $payable->sum('cents'))]]]];
        }

        $assets = DB::table('fixed_assets as a')->where('a.company_id', $companyId)->where('a.acquired_on', '<=', $to)->where(fn ($query) => $query->whereNull('a.disposed_on')->orWhere('a.disposed_on', '>', $to))
            ->orderBy('a.code')->select('a.code', 'a.name', 'a.acquired_on', 'a.cost_cents')
            ->selectRaw('CAST(COALESCE((SELECT SUM(d.amount_cents) FROM fixed_asset_depreciations d WHERE d.fixed_asset_id = a.id AND d.month_on <= ?), 0) AS BIGINT) as depreciated', [$to])->get();
        if ($assets->isNotEmpty()) {
            $sections[] = ['title' => 'Detalle de propiedad, planta y equipo', 'columns' => ['Activo', 'Adquirido', 'Costo', 'Depreciación acumulada', 'Valor en libros'], 'money' => [2, 3, 4],
                'rows' => [...$assets->map(fn (stdClass $asset): array => ['type' => 'line', 'cells' => [$asset->code.' '.$asset->name, CarbonImmutable::parse((string) $asset->acquired_on)->format('d/m/Y'),
                    self::amount((int) $asset->cost_cents), self::amount((int) $asset->depreciated), self::amount((int) $asset->cost_cents - (int) $asset->depreciated)]])->all(),
                    ['type' => 'total', 'cells' => ['Total', '', self::amount((int) $assets->sum('cost_cents')), self::amount((int) $assets->sum('depreciated')), self::amount((int) $assets->sum('cost_cents') - (int) $assets->sum('depreciated'))]]]];
        }

        return $sections;
    }

    /**
     * Libro de Estados Financieros: statement of financial position and income statement of the period.
     *
     * @return list<BookSection>
     */
    public function statementsBook(int $companyId, string $from, string $to): array
    {
        $sections = $this->inventories($companyId, $to, false);
        $balance = $sections[0];
        $balance['title'] = 'Estado de situación financiera al '.CarbonImmutable::parse($to)->format('d/m/Y');
        $income = $this->statements->income($companyId, $from, $to);
        $rows = [];
        foreach (['revenue' => 'INGRESOS', 'cost_of_sales' => 'COSTO DE VENTAS', 'operating_expenses' => 'GASTOS DE OPERACIÓN'] as $section => $label) {
            $rows[] = ['type' => 'heading', 'cells' => [$label, '']];
            foreach ((array) $income[$section] as $account) {
                $account = (array) $account;
                $rows[] = ['type' => 'line', 'cells' => [$account['code'].' '.$account['name'], self::amount((int) $account['cents'])]];
            }
        }
        $t = (array) $income['totals'];
        foreach ([['Utilidad bruta', 'gross_profit'], ['Resultado del período', 'net_result']] as [$label, $key]) {
            $rows[] = ['type' => 'total', 'cells' => [$label, self::amount((int) $t[$key])]];
        }

        return [$balance, ['title' => 'Estado de resultados del '.CarbonImmutable::parse($from)->format('d/m/Y').' al '.CarbonImmutable::parse($to)->format('d/m/Y'), 'columns' => ['Cuenta', 'Monto'], 'money' => [1], 'rows' => $rows]];
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array{0: string, 1: int}
     */
    private function render(array $book, string $header, int $firstFolio): array
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('legal-book', ['book' => $book])->render());
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        // Each sheet carries the company, the book and its folio, numbered from the next authorized sheet.
        $canvas->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($header, $firstFolio): void {
            $font = $metrics->getFont('DejaVu Sans') ?? throw new RuntimeException('No fue posible cargar la fuente del libro.');
            $canvas->text(36, 20, $header, $font, 7.5, [0.25, 0.25, 0.25]);
            $canvas->text($canvas->get_width() - 110, 20, 'Folio No. '.($firstFolio + $page - 1), $font, 9, [0, 0, 0]);
        });

        return [(string) $pdf->output(), $canvas->get_page_count()];
    }

    private static function amount(int $cents): string
    {
        return $cents === 0 ? '' : ($cents < 0 ? '-' : '').number_format(abs($cents) / 100, 2);
    }
}
