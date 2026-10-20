<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Actions\Accounting\CloseAccountingPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkloadService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Accounting supervisor: controls, assigns, reviews and approves the work of accountants and assistants on the
 * companies the supervisor is assigned to. Every read and action is limited to those companies; the supervisor has no
 * global administration powers.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class SupervisorService
{
    public const INCIDENT_LABELS = ['open' => 'Abierta', 'in_progress' => 'En análisis', 'blocked' => 'En corrección', 'resolved' => 'Resuelta', 'dismissed' => 'Cerrada'];

    private const AUDIT_MODULES = [
        'companies' => 'Empresas', 'company_assignments' => 'Asignaciones', 'work_tasks' => 'Tareas', 'company_period_reviews' => 'Revisión',
        'operational_incidents' => 'Incidencias', 'communications' => 'Comunicación', 'journal_entries' => 'Contabilidad', 'accounting_periods' => 'Períodos',
        'purchase_documents' => 'Compras', 'sales_invoices' => 'Ventas', 'vat_book_periods' => 'Fiscal', 'bank_transactions' => 'Bancos',
    ];

    public function __construct(
        private OperationsService $operations,
        private FiscalObligationService $obligations,
        private WorkloadService $workload,
        private WorkAuthorizationService $authorization,
        private AccountingService $accounting,
        private CloseAccountingPeriod $closePeriod,
    ) {}

    /**
     * Companies under the supervisor: the ones they are assigned to (owners see every company of the firm).
     *
     * @return list<int>
     */
    public function companyIds(Organization $organization, User $user): array
    {
        abort_unless($this->authorization->can($organization, $user, 'supervision.dashboard.view'), 403);
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();

        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /** @param list<int> $companyIds */
    public function assertCompany(array $companyIds, int $companyId): void
    {
        abort_unless(in_array($companyId, $companyIds, true), 404);
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<int>
     */
    public function officeIds(array $companyIds): array
    {
        return array_values(array_map('intval', DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->distinct()->pluck('office_id')->all()));
    }

    /**
     * Companies with their period status plus the supervisor columns: accounting progress, fiscal step and documents.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $companyIds, ?array $period): array
    {
        $rows = array_values(array_filter($this->operations->companies($organization, $this->officeIds($companyIds), $period), fn (array $company): bool => in_array($company['id'], $companyIds, true)));
        $documents = $this->documentCounts($companyIds, $period);

        return array_map(function (array $company) use ($documents): array {
            $accounting = collect(is_array($company['stages']) ? $company['stages'] : [])->firstWhere('label', 'Contabilidad');
            [$fiscal, $fiscalTone] = match (true) {
                $company['status'] === 'closed' || $company['fiscal_status'] === 'approved' => ['Fiscal aprobado', 'green'],
                $company['fiscal_status'] === 'pending' => ['Fiscal pendiente', 'amber'],
                $company['fiscal_status'] === 'changes_requested' => ['Corrección requerida', 'violet'],
                default => ['Pendiente', 'red'],
            };

            return [
                ...$company,
                'accounting_progress' => $accounting === null ? ($company['status'] === 'closed' ? 100 : 0) : $accounting['progress'],
                'fiscal_label' => $fiscal,
                'fiscal_tone' => $fiscalTone,
                'documents_done' => $documents[$company['id']]['done'] ?? 0,
                'documents_total' => $documents[$company['id']]['total'] ?? 0,
                'expediente' => '/supervisor/companies/'.$company['id'],
            ];
        }, $rows);
    }

    /**
     * Processed over received documents per company in the period (purchases, sales, bank movements, office documents).
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return array<int, array{done: int, total: int}>
     */
    private function documentCounts(array $companyIds, ?array $period): array
    {
        [$from, $to] = $this->range($period);
        $counts = [];
        $add = function (iterable $rows) use (&$counts): void {
            foreach ($rows as $row) {
                $counts[(int) $row->company_id]['total'] = ($counts[(int) $row->company_id]['total'] ?? 0) + (int) $row->total;
                $counts[(int) $row->company_id]['done'] = ($counts[(int) $row->company_id]['done'] ?? 0) + (int) $row->done;
            }
        };
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $add(DB::table('purchase_documents')->whereIn('company_id', $ids)->whereBetween('document_date', [$from, $to])->select('company_id')->selectRaw('COUNT(*) as total')->selectRaw("CAST(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS BIGINT) as done")->groupBy('company_id')->get());
        $add(DB::table('sales_invoices')->whereIn('company_id', $ids)->whereBetween('issued_on', [$from, $to])->select('company_id')->selectRaw('COUNT(*) as total')->selectRaw("CAST(SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) AS BIGINT) as done")->groupBy('company_id')->get());
        $add(DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->whereIn('bank_accounts.company_id', $ids)->whereBetween('transaction_date', [$from, $to])->select('bank_accounts.company_id')->selectRaw('COUNT(*) as total')->selectRaw("CAST(SUM(CASE WHEN reconciliation_status = 'matched' THEN 1 ELSE 0 END) AS BIGINT) as done")->groupBy('bank_accounts.company_id')->get());
        $add(DB::table('operational_records')->whereIn('company_id', $ids)->where('module', 'documentos')->select('company_id')->selectRaw('COUNT(*) as total')->selectRaw("CAST(SUM(CASE WHEN status IN ('published', 'archived') THEN 1 ELSE 0 END) AS BIGINT) as done")->groupBy('company_id')->get());

        return $counts;
    }

    /**
     * @param  PeriodOption|null  $period
     * @return array{0: string, 1: string}
     */
    public function range(?array $period): array
    {
        return $period === null ? [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()] : [$period['starts_on'], $period['ends_on']];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, array $companyIds, ?array $period, array $filters): array
    {
        $companies = $this->filterCompanies($this->companies($organization, $companyIds, $period), $filters);
        $visibleIds = array_map(fn (array $company): int => $company['id'], $companies);
        $rows = collect($companies);
        $obligations = $this->obligations->obligations($visibleIds, now()->startOfDay(), now()->addDays(45));
        $alerts = $this->alerts($organization, $visibleIds, $period);

        try {
            $team = $this->team($organization, $companyIds);
            $teamError = null;
        } catch (Throwable $exception) {
            report($exception);
            $team = null;
            $teamError = 'Carga de trabajo temporalmente no disponible.';
        }

        $officeIds = $this->officeIds($companyIds);
        $tasks = array_values(array_filter($this->operations->tasks($organization->id, $officeIds, ['status' => 'open']), fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $visibleIds, true)));
        $reviews = array_values(array_filter($this->operations->reviews($organization->id, $officeIds, true), fn (array $review): bool => in_array($review['company_id'], $visibleIds, true)));
        $incidents = array_values(array_filter($this->operations->incidents($organization->id, $officeIds, ['open' => true]), fn (array $incident): bool => $incident['company_id'] !== null && in_array($incident['company_id'], $visibleIds, true)));

        return [
            'metrics' => [
                'companies' => $rows->count(),
                'pending_periods' => $rows->where('period_id', '!=', null)->where('status', '!=', 'closed')->count(),
                'in_review' => $rows->whereIn('status', ['ready_for_review', 'in_review'])->count(),
                'upcoming_declarations' => collect($obligations)->whereIn('status', ['due_soon', 'overdue'])->count(),
                'missing_documents' => collect($incidents)->where('type', 'document')->count() + $rows->sum(fn (array $company): int => max(0, (int) $company['documents_total'] - (int) $company['documents_done'])),
                'pending_reconciliations' => collect($this->banks($visibleIds))->where('status', '!=', 'reconciled')->count(),
                'overdue_tasks' => collect($tasks)->where('overdue_days', '>', 0)->count(),
                'open_incidents' => count($incidents),
                'closed_periods' => $rows->where('status', 'closed')->count(),
                'pending_reviews' => collect($reviews)->count() + $rows->where('status', 'fiscal_pending')->count(),
                'corrections' => $rows->where('status', 'changes_requested')->count(),
                'up_to_date' => $rows->filter(fn (array $company): bool => ! $company['overdue'] && $company['overdue_tasks'] === 0 && ! $company['blocked'])->count(),
            ],
            'status_chart' => [
                ['key' => 'closed', 'label' => 'Completadas', 'value' => $rows->where('status', 'closed')->count(), 'color' => '#16a34a'],
                ['key' => 'in_progress', 'label' => 'En proceso', 'value' => $rows->where('status', 'in_progress')->where('blocked', false)->count(), 'color' => '#2563eb'],
                ['key' => 'review', 'label' => 'En revisión', 'value' => $rows->whereIn('status', ['ready_for_review', 'in_review', 'fiscal_pending', 'approved', 'ready_to_close'])->count(), 'color' => '#d97706'],
                ['key' => 'changes', 'label' => 'Corrección requerida', 'value' => $rows->where('status', 'changes_requested')->count(), 'color' => '#7c3aed'],
                ['key' => 'blocked', 'label' => 'Bloqueadas', 'value' => $rows->where('blocked', true)->where('status', '!=', 'closed')->count(), 'color' => '#dc2626'],
                ['key' => 'pending', 'label' => 'Pendientes', 'value' => $rows->where('status', 'not_started')->count(), 'color' => '#94a3b8'],
            ],
            'team' => $team === null ? null : array_slice($team, 0, 5),
            'team_error' => $teamError,
            'obligations' => array_slice(array_values(array_filter($obligations, fn (array $row): bool => $row['status'] !== 'completed')), 0, 5),
            'alerts' => array_slice($alerts, 0, 6),
            'documents' => $this->documentStats($visibleIds, $period),
            'tasks' => array_slice($tasks, 0, 6),
            'companies' => $companies,
            'badges' => ['reviews' => count($reviews), 'incidents' => count($incidents), 'alerts' => count($alerts)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $companies
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function filterCompanies(array $companies, array $filters): array
    {
        return array_values(array_filter($companies, function (array $company) use ($filters): bool {
            if (! empty($filters['company_id']) && $company['id'] !== (int) $filters['company_id']) {
                return false;
            }
            if (! empty($filters['responsible_id']) && ($company['accountant']['id'] ?? null) !== (int) $filters['responsible_id'] && ($company['assistant']['id'] ?? null) !== (int) $filters['responsible_id']) {
                return false;
            }

            return empty($filters['status']) || $company['status'] === $filters['status'] || ($filters['status'] === 'blocked' && $company['blocked']);
        }));
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array{type: string, received: int, processed: int, pending: int, rejected: int}>
     */
    public function documentStats(array $companyIds, ?array $period): array
    {
        [$from, $to] = $this->range($period);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [
            $this->documentStat('Facturas de compras', DB::table('purchase_documents')->whereIn('company_id', $ids)->whereBetween('document_date', [$from, $to]), "status = 'approved'", "status = 'draft'", "status = 'void'"),
            $this->documentStat('Facturas de ventas', DB::table('sales_invoices')->whereIn('company_id', $ids)->whereBetween('issued_on', [$from, $to]), "status = 'issued'", "status = 'draft'", "status = 'void'"),
            $this->documentStat('Movimientos bancarios', DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->whereIn('bank_accounts.company_id', $ids)->whereBetween('transaction_date', [$from, $to]), "reconciliation_status = 'matched'", "reconciliation_status = 'pending'", "reconciliation_status = 'unmatched'"),
            $this->documentStat('Documentos de la oficina', DB::table('operational_records')->whereIn('company_id', $ids)->where('module', 'documentos'), "status IN ('published', 'archived')", "status IN ('draft', 'review')", "status = 'rejected'"),
            $this->documentStat('Notas de crédito / débito FEL', DB::table('fel_documents')->whereIn('company_id', $ids)->whereIn('document_type', ['NCRE', 'NDEB'])->whereBetween('issued_on', [$from, $to]), "status = 'certified'", "status IN ('draft', 'pending', 'processing')", "status IN ('failed', 'cancelled')"),
        ];
    }

    /**
     * @param  literal-string  $processed
     * @param  literal-string  $pending
     * @param  literal-string  $rejected
     * @return array{type: string, received: int, processed: int, pending: int, rejected: int}
     */
    private function documentStat(string $type, Builder $query, string $processed, string $pending, string $rejected): array
    {
        $row = $query->selectRaw('COUNT(*) as total')
            ->selectRaw('CAST(SUM(CASE WHEN '.$processed.' THEN 1 ELSE 0 END) AS BIGINT) as processed')
            ->selectRaw('CAST(SUM(CASE WHEN '.$pending.' THEN 1 ELSE 0 END) AS BIGINT) as pending')
            ->selectRaw('CAST(SUM(CASE WHEN '.$rejected.' THEN 1 ELSE 0 END) AS BIGINT) as rejected')->first();

        return ['type' => $type, 'received' => (int) ($row->total ?? 0), 'processed' => (int) ($row->processed ?? 0), 'pending' => (int) ($row->pending ?? 0), 'rejected' => (int) ($row->rejected ?? 0)];
    }

    /**
     * Alerts computed from the real modules; each one points to where it is solved.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array{key: string, category: string, title: string, detail: string, company: string|null, date: string|null, priority: string, destination: string}>
     */
    public function alerts(Organization $organization, array $companyIds, ?array $period): array
    {
        $organizationId = $organization->id;
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $names = DB::table('companies')->whereIn('id', $ids)->pluck('name', 'id');
        $publicIds = DB::table('companies')->whereIn('id', $ids)->pluck('public_id', 'id');
        $alerts = [];

        foreach (DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->whereIn('company_id', $ids)->whereIn('status', ['open', 'in_progress', 'blocked'])->orderByDesc('created_at')->get() as $incident) {
            $alerts[] = ['key' => 'incident:'.$incident->id, 'category' => $incident->type === 'document' ? 'Documental' : ($incident->type === 'fiscal' || $incident->type === 'fel' ? 'Fiscal' : 'Contable'), 'title' => (string) $incident->title, 'detail' => (string) ($incident->description ?? 'Incidencia abierta'), 'company' => $names[$incident->company_id] ?? null, 'date' => (string) $incident->created_at, 'priority' => in_array($incident->severity, ['critical', 'high'], true) ? 'high' : 'medium', 'destination' => '/supervisor/incidents'];
        }
        foreach ($this->banks($companyIds) as $bank) {
            if ($bank['pending'] > 0 || $bank['difference_cents'] !== 0) {
                $alerts[] = ['key' => 'bank:'.$bank['id'], 'category' => 'Bancaria', 'title' => $bank['difference_cents'] !== 0 ? 'Conciliación con diferencia' : 'Movimientos sin conciliar', 'detail' => $bank['bank'].' · '.$bank['pending'].' movimiento(s) pendientes'.($bank['difference_cents'] !== 0 ? ' · diferencia Q'.number_format(abs($bank['difference_cents']) / 100, 2) : ''), 'company' => $bank['company'], 'date' => null, 'priority' => $bank['difference_cents'] !== 0 ? 'high' : 'medium', 'destination' => '/supervisor/operations/banks'];
            }
        }
        foreach ($this->obligations->obligations($companyIds, now()->startOfDay(), now()->addDays(7)) as $obligation) {
            if ($obligation['status'] !== 'completed') {
                $alerts[] = ['key' => 'obligation:'.$obligation['key'], 'category' => 'Fiscal', 'title' => $obligation['status'] === 'overdue' ? $obligation['name'].' vencida' : $obligation['name'].' vence en '.$obligation['days_left'].' día(s)', 'detail' => $obligation['period'], 'company' => $obligation['company_name'], 'date' => $obligation['due_on'], 'priority' => 'high', 'destination' => '/supervisor/fiscal/obligations'];
            }
        }
        foreach (DB::table('sales_invoices')->whereIn('company_id', $ids)->where('status', 'issued')->where('due_on', '<', now()->toDateString())->get(['id', 'company_id', 'number', 'customer_name', 'due_on', 'total_cents']) as $invoice) {
            if ($this->invoiceBalance((int) $invoice->id, (int) $invoice->total_cents) > 0) {
                $alerts[] = ['key' => 'receivable:'.$invoice->id, 'category' => 'Cobros', 'title' => 'Cuenta por cobrar vencida', 'detail' => ($invoice->customer_name ?? 'Cliente').' · factura '.($invoice->number ?? $invoice->id), 'company' => $names[$invoice->company_id] ?? null, 'date' => (string) $invoice->due_on, 'priority' => 'medium', 'destination' => '/supervisor/operations/receivables'];
            }
        }
        foreach (DB::table('purchase_documents')->whereIn('company_id', $ids)->where('status', 'approved')->where('due_on', '<', now()->toDateString())->get(['id', 'company_id', 'number', 'supplier_name', 'due_on', 'total_cents']) as $document) {
            if ($this->purchaseBalance((int) $document->id, (int) $document->total_cents) > 0) {
                $alerts[] = ['key' => 'payable:'.$document->id, 'category' => 'Pagos', 'title' => 'Cuenta por pagar vencida', 'detail' => ($document->supplier_name ?? 'Proveedor').' · factura '.$document->number, 'company' => $names[$document->company_id] ?? null, 'date' => (string) $document->due_on, 'priority' => 'medium', 'destination' => '/supervisor/operations/payables'];
            }
        }
        foreach ($this->duplicates($companyIds) as $duplicate) {
            $alerts[] = ['key' => 'duplicate:'.$duplicate['sha256'], 'category' => 'Documental', 'title' => 'Documento duplicado', 'detail' => $duplicate['name'].' se cargó '.$duplicate['count'].' veces', 'company' => $duplicate['company'], 'date' => null, 'priority' => 'low', 'destination' => '/supervisor/operations/documents'];
        }
        $modified = DB::table('audit_logs')->join('journal_entries', 'journal_entries.id', '=', 'audit_logs.auditable_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
            ->join('company_period_reviews as review', function ($join): void {
                $join->on('review.accounting_period_id', '=', 'accounting_periods.id')->whereIn('review.status', ['approved']);
            })
            ->where('audit_logs.organization_id', $organizationId)->where('audit_logs.auditable_type', 'journal_entries')
            ->whereColumn('audit_logs.occurred_at', '>', 'review.approved_at')->whereIn('accounting_periods.company_id', $ids)
            ->get(['audit_logs.id', 'audit_logs.occurred_at', 'accounting_periods.company_id', 'journal_entries.number']);
        foreach ($modified as $event) {
            $alerts[] = ['key' => 'modified:'.$event->id, 'category' => 'Contable', 'title' => 'Póliza modificada después de la aprobación', 'detail' => 'Asiento '.($event->number ?? ''), 'company' => $names[$event->company_id] ?? null, 'date' => (string) $event->occurred_at, 'priority' => 'high', 'destination' => '/accounting/companies/'.($publicIds[$event->company_id] ?? '').'/'];
        }
        foreach ($this->companies($organization, $companyIds, $period) as $company) {
            if ($company['overdue']) {
                $alerts[] = ['key' => 'late:'.$company['id'], 'category' => 'Contable', 'title' => 'Período atrasado', 'detail' => ($company['period_name'] ?? '').' superó su fecha límite de cierre', 'company' => $company['name'], 'date' => $company['due_at'], 'priority' => 'high', 'destination' => $company['expediente']];
            }
        }

        $weight = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($alerts, fn (array $a, array $b): int => $weight[$a['priority']] <=> $weight[$b['priority']]);

        return $alerts;
    }

    private function invoiceBalance(int $invoiceId, int $total): int
    {
        return $total - (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount_cents');
    }

    private function purchaseBalance(int $documentId, int $total): int
    {
        return $total - (int) DB::table('purchase_payments')->where('purchase_document_id', $documentId)->whereNull('voided_at')->sum('amount_cents');
    }

    /**
     * @param  list<int>  $companyIds
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function receivables(array $companyIds): array
    {
        $rows = DB::table('sales_invoices')->join('companies', 'companies.id', '=', 'sales_invoices.company_id')->whereIn('sales_invoices.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('sales_invoices.status', 'issued')->orderBy('sales_invoices.due_on')->limit(300)
            ->get(['sales_invoices.id', 'sales_invoices.number', 'sales_invoices.customer_name', 'sales_invoices.issued_on', 'sales_invoices.due_on', 'sales_invoices.total_cents', 'companies.name as company_name', 'companies.public_id'])
            ->map(function (stdClass $row): array {
                $balance = $this->invoiceBalance((int) $row->id, (int) $row->total_cents);

                return $this->openItem($row, $row->customer_name, $row->issued_on, $balance, '/accounting/companies/'.$row->public_id.'/sales/invoices/'.$row->id);
            });
        $recovered = (int) DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->whereIn('sales_invoices.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereNull('sales_receipts.voided_at')->whereBetween('sales_receipts.received_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('sales_receipts.amount_cents');

        $rows = array_values($rows->all());

        return ['rows' => $rows, 'summary' => $this->openSummary($rows, $recovered)];
    }

    /**
     * @param  list<int>  $companyIds
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function payables(array $companyIds): array
    {
        $rows = DB::table('purchase_documents')->join('companies', 'companies.id', '=', 'purchase_documents.company_id')->whereIn('purchase_documents.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('purchase_documents.status', 'approved')->orderBy('purchase_documents.due_on')->limit(300)
            ->get(['purchase_documents.id', 'purchase_documents.number', 'purchase_documents.supplier_name', 'purchase_documents.document_date', 'purchase_documents.due_on', 'purchase_documents.total_cents', 'companies.name as company_name', 'companies.public_id'])
            ->map(function (stdClass $row): array {
                $balance = $this->purchaseBalance((int) $row->id, (int) $row->total_cents);

                return $this->openItem($row, $row->supplier_name, $row->document_date, $balance, '/accounting/companies/'.$row->public_id.'/purchases/documents/'.$row->id);
            });
        $paid = (int) DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')->whereIn('purchase_documents.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereNull('purchase_payments.voided_at')->whereBetween('purchase_payments.paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('purchase_payments.amount_cents');

        $rows = array_values($rows->all());

        return ['rows' => $rows, 'summary' => $this->openSummary($rows, $paid)];
    }

    /** @return array<string, mixed> */
    private function openItem(stdClass $row, ?string $party, ?string $date, int $balance, string $destination): array
    {
        $due = Carbon::parse($row->due_on);
        $status = match (true) {
            $balance <= 0 => 'paid',
            $due->isPast() && ! $due->isToday() => 'overdue',
            $balance < (int) $row->total_cents => 'partial',
            default => 'pending',
        };

        return [
            'id' => (int) $row->id,
            'company' => (string) $row->company_name,
            'party' => $party ?? '—',
            'document' => (string) ($row->number ?? $row->id),
            'date' => $date,
            'due_on' => (string) $row->due_on,
            'total_cents' => (int) $row->total_cents,
            'balance_cents' => max(0, $balance),
            'status' => $status,
            'status_label' => ['paid' => 'Pagado', 'overdue' => 'Vencido', 'partial' => 'Parcial', 'pending' => 'Pendiente'][$status],
            'destination' => $destination,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function openSummary(array $rows, int $settledThisMonth): array
    {
        $collection = collect($rows);

        return [
            'total_cents' => (int) $collection->sum('balance_cents'),
            'overdue_cents' => (int) $collection->where('status', 'overdue')->sum('balance_cents'),
            'due_soon_cents' => (int) $collection->filter(fn (array $row): bool => $row['status'] !== 'overdue' && $row['balance_cents'] > 0 && Carbon::parse($row['due_on'])->lte(now()->addDays(7)))->sum('balance_cents'),
            'settled_cents' => $settledThisMonth,
        ];
    }

    /**
     * Bank accounts with their bank balance, ledger balance, difference and pending movements.
     *
     * @param  list<int>  $companyIds
     * @return list<array{id: int, company: string, company_id: int, bank: string, bank_balance_cents: int, ledger_balance_cents: int, difference_cents: int, pending: int, status: string, status_label: string, destination: string}>
     */
    public function banks(array $companyIds): array
    {
        return array_values(DB::table('bank_accounts')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')
            ->whereIn('bank_accounts.company_id', $companyIds === [] ? [-1] : $companyIds)->where('bank_accounts.status', 'active')->orderBy('companies.name')
            ->get(['bank_accounts.id', 'bank_accounts.company_id', 'bank_accounts.bank_name', 'bank_accounts.account_name', 'bank_accounts.last_four', 'bank_accounts.bank_balance_cents', 'bank_accounts.ledger_account_id', 'companies.name as company_name', 'companies.public_id'])
            ->map(function (object $account): array {
                $ledger = (int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_lines.ledger_account_id', $account->ledger_account_id)->whereIn('journal_entries.status', ['posted', 'reversed'])
                    ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) AS BIGINT) as balance')->value('balance');
                $pending = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('reconciliation_status', 'pending')->count();
                $difference = (int) $account->bank_balance_cents - $ledger;
                [$status, $label] = match (true) {
                    $difference !== 0 && $pending === 0 => ['difference', 'Con diferencia'],
                    $pending > 0 && DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('reconciliation_status', 'matched')->exists() => ['in_progress', 'En proceso'],
                    $pending > 0 => ['pending', 'Pendiente'],
                    default => ['reconciled', 'Conciliado'],
                };

                return [
                    'id' => (int) $account->id,
                    'company' => (string) $account->company_name,
                    'company_id' => (int) $account->company_id,
                    'bank' => $account->bank_name.' · '.$account->account_name.' ...'.$account->last_four,
                    'bank_balance_cents' => (int) $account->bank_balance_cents,
                    'ledger_balance_cents' => $ledger,
                    'difference_cents' => $difference,
                    'pending' => $pending,
                    'status' => $status,
                    'status_label' => $label,
                    'destination' => '/accounting/companies/'.$account->public_id.'/banks',
                ];
            })->all());
    }

    /**
     * Document repository of the companies: files attached to purchases, journal entries and office records.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function documents(array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $purchase = DB::table('purchase_document_files as file')->join('purchase_documents as parent', 'parent.id', '=', 'file.purchase_document_id')->join('companies', 'companies.id', '=', 'parent.company_id')
            ->whereIn('parent.company_id', $ids)->selectRaw("file.id, 'Compras' as category, file.original_name, file.sha256, file.created_at, parent.company_id, companies.name as company_name, parent.status as parent_status");
        $journal = DB::table('journal_entry_documents as file')->join('journal_entries as parent', 'parent.id', '=', 'file.journal_entry_id')->join('accounting_periods', 'accounting_periods.id', '=', 'parent.accounting_period_id')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
            ->whereIn('accounting_periods.company_id', $ids)->selectRaw("file.id, 'Contabilidad' as category, file.original_name, file.sha256, file.created_at, accounting_periods.company_id, companies.name as company_name, parent.status as parent_status");
        $records = DB::table('operational_record_files as file')->join('operational_records as parent', 'parent.id', '=', 'file.operational_record_id')->join('companies', 'companies.id', '=', 'file.company_id')
            ->whereIn('file.company_id', $ids)->selectRaw("file.id, CASE WHEN parent.module = 'documentos' THEN 'Otros' ELSE 'Operación' END as category, file.original_name, file.sha256, file.created_at, file.company_id, companies.name as company_name, parent.status as parent_status");
        $files = collect($purchase->unionAll($journal)->unionAll($records)->orderByDesc('created_at')->limit(400)->get());
        $counts = $files->groupBy(fn (object $file): string => $file->company_id.':'.$file->sha256)->map->count();

        return array_values($files->map(function (object $file) use ($counts): array {
            $duplicate = ($counts[$file->company_id.':'.$file->sha256] ?? 0) > 1;
            [$status, $label] = match (true) {
                $duplicate => ['duplicate', 'Duplicado'],
                in_array($file->parent_status, ['void', 'rejected'], true) => ['rejected', 'Rechazado'],
                in_array($file->parent_status, ['draft', 'review', 'pending_review'], true) => ['pending', 'Pendiente'],
                default => ['processed', 'Procesado'],
            };

            return ['id' => (int) $file->id, 'category' => (string) $file->category, 'name' => (string) $file->original_name, 'company' => (string) $file->company_name, 'received_at' => (string) $file->created_at, 'status' => $status, 'status_label' => $label];
        })->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{sha256: string, name: string, company: string, count: int}>
     */
    private function duplicates(array $companyIds): array
    {
        return array_values(collect($this->documents($companyIds))->where('status', 'duplicate')->groupBy(fn (array $file): string => $file['company'].'|'.$file['name'])
            ->map(fn ($files, string $key): array => ['sha256' => md5($key), 'name' => (string) $files->first()['name'], 'company' => (string) $files->first()['company'], 'count' => $files->count()])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function accountingSummary(array $companyIds, ?array $period): array
    {
        [$from, $to] = $this->range($period);

        return array_values(DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'name', 'public_id'])
            ->map(function (object $company) use ($from, $to): array {
                $entries = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
                    ->where('accounting_periods.company_id', $company->id)->whereBetween('journal_entries.entry_date', [$from, $to])
                    ->select('journal_entries.status')->selectRaw('COUNT(*) as total')->groupBy('journal_entries.status')->pluck('total', 'journal_entries.status');

                return [
                    'id' => (int) $company->id,
                    'name' => (string) $company->name,
                    'draft' => (int) ($entries['draft'] ?? 0),
                    'pending_review' => (int) ($entries['pending_review'] ?? 0) + (int) ($entries['reviewed'] ?? 0),
                    'approved' => (int) ($entries['approved'] ?? 0),
                    'posted' => (int) ($entries['posted'] ?? 0) + (int) ($entries['reversed'] ?? 0),
                    'books' => '/accounting/companies/'.$company->public_id,
                    'reports' => '/accounting/companies/'.$company->public_id.'/reports',
                ];
            })->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return array{simulated: bool, summary: list<array{type: string, label: string, certified: int, pending: int, failed: int, cancelled: int}>, rows: list<array<string, mixed>>}
     */
    public function fel(array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $labels = ['FACT' => 'Facturas', 'FCAM' => 'Facturas cambiarias', 'FPEQ' => 'Facturas pequeño contribuyente', 'NCRE' => 'Notas de crédito', 'NDEB' => 'Notas de débito'];
        $summary = DB::table('fel_documents')->whereIn('company_id', $ids)->select('document_type')
            ->selectRaw("CAST(SUM(CASE WHEN status = 'certified' THEN 1 ELSE 0 END) AS BIGINT) as certified")
            ->selectRaw("CAST(SUM(CASE WHEN status IN ('draft', 'pending', 'processing') THEN 1 ELSE 0 END) AS BIGINT) as pending")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS BIGINT) as failed")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS BIGINT) as cancelled")
            ->groupBy('document_type')->get()
            ->map(fn (object $row): array => ['type' => (string) $row->document_type, 'label' => $labels[$row->document_type] ?? (string) $row->document_type, 'certified' => (int) $row->certified, 'pending' => (int) $row->pending, 'failed' => (int) $row->failed, 'cancelled' => (int) $row->cancelled])->values()->all();

        return [
            'simulated' => DB::table('fel_documents')->whereIn('company_id', $ids)->where(fn ($query) => $query->where('provider', 'mock'))->exists(),
            'summary' => array_values($summary),
            'rows' => array_values(DB::table('fel_documents')->join('companies', 'companies.id', '=', 'fel_documents.company_id')->whereIn('fel_documents.company_id', $ids)->orderByDesc('fel_documents.issued_on')->limit(150)
                ->get(['fel_documents.id', 'fel_documents.document_type', 'fel_documents.series', 'fel_documents.number', 'fel_documents.issued_on', 'fel_documents.status', 'fel_documents.environment', 'fel_documents.provider', 'companies.name as company_name', 'companies.public_id'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'type' => $labels[$row->document_type] ?? (string) $row->document_type, 'number' => $row->series.'-'.$row->number, 'issued_on' => (string) $row->issued_on, 'status' => (string) $row->status, 'environment' => (string) $row->environment, 'company' => (string) $row->company_name, 'destination' => '/accounting/companies/'.$row->public_id.'/fel/'.$row->id])->all()),
        ];
    }

    /**
     * Team working on the supervised companies with workload and task counts.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function team(Organization $organization, array $companyIds): array
    {
        $memberIds = array_map('intval', DB::table('company_assignments')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('status', 'active')->whereNull('deleted_at')->distinct()->pluck('user_id')->all());
        $completed = DB::table('work_tasks')->whereIn('assigned_to', $memberIds === [] ? [-1] : $memberIds)->whereNull('deleted_at')->whereIn('status', ['approved', 'completed'])
            ->select('assigned_to')->selectRaw('COUNT(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $roles = DB::table('company_assignments')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('user_id', $memberIds === [] ? [-1] : $memberIds)->where('status', 'active')->whereNull('deleted_at')
            ->select('user_id')->selectRaw('COUNT(DISTINCT company_id) as companies')->groupBy('user_id')->pluck('companies', 'user_id');

        return array_values(collect($this->workload->forOrganization($organization->id))
            ->filter(fn (array $row): bool => in_array($row['user_id'], $memberIds, true))
            ->map(fn (array $row): array => [
                ...$row,
                'supervised_companies' => (int) ($roles[$row['user_id']] ?? 0),
                'open_tasks' => $row['tasks']['pending'] + $row['tasks']['in_progress'] + $row['tasks']['changes_requested'] + $row['tasks']['blocked'],
                'in_review' => $row['tasks']['ready_for_review'] + $row['tasks']['in_review'],
                'completed' => (int) ($completed[$row['user_id']] ?? 0),
            ])
            ->sortByDesc('utilization_percentage')->values()->all());
    }

    /**
     * Everything that must be true before a period can close, as a checklist for the confirmation dialog.
     *
     * @return array{checks: list<array{label: string, ok: bool, detail: string}>, can_close: bool, company: string, period: string}
     */
    public function closeChecks(int $organizationId, int $companyId, int $periodId): array
    {
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->first(['id', 'name', 'starts_on', 'ends_on', 'closed_at']);
        abort_if($period === null, 404);
        $review = DB::table('company_period_reviews')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->first(['status', 'fiscal_status']);
        $openTasks = DB::table('work_tasks')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->where('module', '!=', 'closing')->count();
        $documentIncidents = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('company_id', $companyId)->where('type', 'document')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();
        $pendingBank = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_accounts.company_id', $companyId)
            ->where('reconciliation_status', 'pending')->whereBetween('transaction_date', [$period->starts_on, $period->ends_on])->count();
        $end = Carbon::parse($period->ends_on);
        $vatClosed = DB::table('vat_book_periods')->where('company_id', $companyId)->where('year', $end->year)->where('month', $end->month)->where('status', 'closed')->exists();
        $critical = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('company_id', $companyId)->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->where(fn ($query) => $query->where('severity', 'critical')->orWhere('blocks_period', true))->count();
        $corrections = $review !== null && $review->status === 'changes_requested';

        $checks = [
            ['label' => 'Contabilidad completa', 'ok' => $openTasks === 0, 'detail' => $openTasks === 0 ? 'Todas las tareas del período están terminadas.' : $openTasks.' tarea(s) abiertas.'],
            ['label' => 'Revisión contable aprobada', 'ok' => $review !== null && $review->status === 'approved', 'detail' => $review === null ? 'El período no se ha enviado a revisión.' : 'Estado: '.$review->status],
            ['label' => 'Revisión fiscal aprobada', 'ok' => $review !== null && $review->fiscal_status === 'approved', 'detail' => $review?->fiscal_status === 'approved' ? 'IVA, ISR y FEL revisados.' : 'Falta la aprobación fiscal.'],
            ['label' => 'Documentos completos', 'ok' => $documentIncidents === 0, 'detail' => $documentIncidents === 0 ? 'Sin solicitudes de documentos abiertas.' : $documentIncidents.' solicitud(es) de documentos abiertas.'],
            ['label' => 'Conciliación bancaria terminada', 'ok' => $pendingBank === 0, 'detail' => $pendingBank === 0 ? 'Sin movimientos pendientes.' : $pendingBank.' movimiento(s) sin conciliar.'],
            ['label' => 'Declaración de IVA revisada', 'ok' => $vatClosed, 'detail' => $vatClosed ? 'Libro IVA del mes cerrado.' : 'Cierra el Libro IVA del mes.'],
            ['label' => 'Incidencias críticas cerradas', 'ok' => $critical === 0, 'detail' => $critical === 0 ? 'Sin incidencias que bloqueen.' : $critical.' incidencia(s) críticas abiertas.'],
            ['label' => 'Correcciones resueltas', 'ok' => ! $corrections, 'detail' => $corrections ? 'Hay una corrección solicitada pendiente.' : 'Sin correcciones pendientes.'],
        ];

        return [
            'checks' => $checks,
            'can_close' => $period->closed_at === null && collect($checks)->every('ok'),
            'company' => (string) DB::table('companies')->where('id', $companyId)->value('name'),
            'period' => (string) $period->name,
        ];
    }

    public function closePeriod(Organization $organization, User $actor, int $companyId, int $periodId): void
    {
        abort_unless($this->authorization->can($organization, $actor, 'supervision.periods.close'), 403);
        $result = $this->closeChecks($organization->id, $companyId, $periodId);
        if (! $result['can_close']) {
            $pending = collect($result['checks'])->where('ok', false)->pluck('label')->implode(', ');
            throw ValidationException::withMessages(['period' => 'No se puede cerrar el período: '.$pending.'.']);
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        abort_if($company === null, 404);
        $this->closePeriod->execute($organization, $company, $periodId, $actor, true);
        DB::table('work_tasks')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->where('module', 'closing')->whereNotIn('status', ['completed', 'approved', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'supervision.period_closed', 'accounting_periods', $periodId, ['company_id' => $companyId], (int) $company->office_id);
    }

    /**
     * Read-only audit trail of the supervised companies.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function audit(int $organizationId, array $companyIds, array $filters = [], int $limit = 200): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $periodIds = DB::table('accounting_periods')->whereIn('company_id', $ids)->pluck('id');
        $related = [
            'companies' => collect($ids),
            'company_assignments' => DB::table('company_assignments')->whereIn('company_id', $ids)->pluck('id'),
            'work_tasks' => DB::table('work_tasks')->whereIn('company_id', $ids)->pluck('id'),
            'company_period_reviews' => DB::table('company_period_reviews')->whereIn('company_id', $ids)->pluck('id'),
            'operational_incidents' => DB::table('operational_incidents')->whereIn('company_id', $ids)->pluck('id'),
            'communications' => DB::table('communications')->whereIn('company_id', $ids)->pluck('id'),
            'accounting_periods' => $periodIds,
            'journal_entries' => DB::table('journal_entries')->whereIn('accounting_period_id', $periodIds)->pluck('id'),
            'purchase_documents' => DB::table('purchase_documents')->whereIn('company_id', $ids)->pluck('id'),
            'sales_invoices' => DB::table('sales_invoices')->whereIn('company_id', $ids)->pluck('id'),
        ];

        return array_values(DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.organization_id', $organizationId)
            ->where(function (Builder $query) use ($related): void {
                foreach ($related as $type => $entityIds) {
                    $query->orWhere(fn (Builder $q) => $q->where('audit_logs.auditable_type', $type)->whereIn('audit_logs.auditable_id', $entityIds->all() === [] ? [-1] : $entityIds->all()));
                }
            })
            ->when(! empty($filters['module']), fn (Builder $query) => $query->where('audit_logs.auditable_type', $filters['module']))
            ->when(! empty($filters['search']), fn (Builder $query) => $query->whereRaw('LOWER(audit_logs.action) LIKE ?', ['%'.mb_strtolower((string) $filters['search']).'%']))
            ->orderByDesc('audit_logs.id')->limit($limit)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.old_values', 'audit_logs.new_values', 'audit_logs.ip_address', 'audit_logs.occurred_at', 'users.name as user_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'user' => $row->user_name ?? 'Sistema',
                'action' => (string) $row->action,
                'module' => self::AUDIT_MODULES[$row->auditable_type] ?? (string) $row->auditable_type,
                'record' => $row->auditable_type.' #'.$row->auditable_id,
                'old_values' => $row->old_values,
                'new_values' => $row->new_values,
                'ip' => $row->ip_address,
                'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(),
            ])->all());
    }

    /** @return array<string, string> */
    public static function auditModules(): array
    {
        return self::AUDIT_MODULES;
    }

    /**
     * Company file: general data, responsible people and the per-tab data of the expediente.
     *
     * @param  PeriodOption|null  $period
     * @return array<string, mixed>
     */
    public function expediente(Organization $organization, int $companyId, ?array $period): array
    {
        $company = DB::table('companies')->leftJoin('company_fiscal_profiles as profile', 'profile.company_id', '=', 'companies.id')->where('companies.id', $companyId)
            ->first(['companies.id', 'companies.public_id', 'companies.name', 'companies.tax_id', 'companies.email', 'companies.office_id', 'profile.vat_regime', 'profile.income_tax_regime']);
        abort_if($company === null, 404);
        $status = collect($this->companies($organization, [$companyId], $period))->first();
        [$from, $to] = $this->range($period);
        $observations = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)
            ->where('audit_logs.action', 'company_period.observation_added')->whereIn('audit_logs.auditable_id', DB::table('company_period_reviews')->where('company_id', $companyId)->pluck('id'))
            ->orderByDesc('audit_logs.id')->get(['audit_logs.new_values', 'audit_logs.occurred_at', 'users.name'])
            ->map(function (object $row): array {
                $values = json_decode((string) $row->new_values, true);

                return ['text' => is_array($values) ? (string) ($values['observation'] ?? '') : '', 'stage' => is_array($values) && ($values['stage'] ?? '') === 'fiscal' ? 'Fiscal' : 'Contable', 'author' => $row->name, 'date' => (string) $row->occurred_at];
            })->all();

        return [
            'company' => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'tax_id' => (string) $company->tax_id,
                'email' => $company->email,
                'vat_regime' => ['general' => 'IVA general', 'small_taxpayer' => 'Pequeño contribuyente', 'exempt' => 'Exento'][$company->vat_regime ?? 'general'] ?? $company->vat_regime,
                'income_tax_regime' => ['utilities' => 'ISR sobre utilidades', 'optional_simplified' => 'ISR opcional simplificado', 'exempt' => 'Exento'][$company->income_tax_regime ?? 'utilities'] ?? $company->income_tax_regime,
                'office' => (string) DB::table('offices')->where('id', $company->office_id)->value('name'),
                'responsibles' => DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->where('company_id', $companyId)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
                    ->get(['users.name', 'company_assignments.assignment_role'])->map(fn (object $row): string => $row->name.' · '.(CompanyAssignmentService::LABELS[$row->assignment_role] ?? $row->assignment_role))->all(),
                'links' => [
                    'dashboard' => '/accounting/companies/'.$company->public_id.'/dashboard',
                    'books' => '/accounting/companies/'.$company->public_id,
                    'reports' => '/accounting/companies/'.$company->public_id.'/reports',
                    'sales' => '/accounting/companies/'.$company->public_id.'/sales',
                    'purchases' => '/accounting/companies/'.$company->public_id.'/purchases',
                    'receivables' => '/accounting/companies/'.$company->public_id.'/receivables',
                    'payables' => '/accounting/companies/'.$company->public_id.'/payables',
                    'banks' => '/accounting/companies/'.$company->public_id.'/banks',
                    'vat_books' => '/accounting/companies/'.$company->public_id.'/vat-books',
                    'fel' => '/accounting/companies/'.$company->public_id.'/fel',
                ],
            ],
            'status' => $status,
            'documents' => array_values(array_filter($this->documents([$companyId]), fn (array $file): bool => true)),
            'accounting' => collect($this->accountingSummary([$companyId], $period))->first(),
            'purchases' => DB::table('purchase_documents')->where('company_id', $companyId)->whereBetween('document_date', [$from, $to])->orderByDesc('document_date')->limit(30)
                ->get(['id', 'number', 'supplier_name', 'document_date', 'total_cents', 'status'])->map(fn (object $row): array => (array) $row)->all(),
            'sales' => DB::table('sales_invoices')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to])->orderByDesc('issued_on')->limit(30)
                ->get(['id', 'number', 'customer_name', 'issued_on', 'total_cents', 'status'])->map(fn (object $row): array => (array) $row)->all(),
            'receivables' => $this->receivables([$companyId]),
            'payables' => $this->payables([$companyId]),
            'banks' => $this->banks([$companyId]),
            'fiscal' => $this->obligations->obligations([$companyId], now()->subMonths(2)->startOfMonth(), now()->addMonths(2)->endOfMonth()),
            'observations' => $observations,
            'history' => array_slice($this->audit($organization->id, [$companyId]), 0, 60),
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function obligationRows(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->obligations->obligations($companyIds, $from, $to);
    }
}
