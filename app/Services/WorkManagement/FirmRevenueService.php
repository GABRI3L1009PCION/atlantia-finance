<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The firm's own income: recurring fees, what has been collected, what is pending or overdue. Money is in cents of quetzales. */
class FirmRevenueService
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function __construct(private WorkDashboardService $dashboard, private AccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization, User $user, ?string $month): array
    {
        $now = CarbonImmutable::now();
        $selected = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth() : $now->startOfMonth();
        $today = $now->toDateString();
        $executive = $this->dashboard->dashboard($organization, $user, 'executive');
        $companies = collect($executive['companies'])->keyBy('id');

        $contracts = DB::table('crm_contracts')->where('organization_id', $organization->id)->whereIn('company_id', $companies->keys()->all())->get();
        $invoices = DB::table('crm_invoices')->where('organization_id', $organization->id)->whereIn('company_id', $companies->keys()->all())->orderByDesc('issued_on')->orderByDesc('id')->get();
        $active = $contracts->whereIn('status', ['active', 'onboarding', 'at_risk']);
        $mrrAt = fn (CarbonImmutable $moment): int => (int) $contracts->filter(fn (object $contract): bool => $contract->starts_on <= $moment->toDateString() && ($contract->status !== 'cancelled' || $contract->updated_at > $moment->toDateTimeString()))->sum('monthly_fee_cents');
        $mrr = $mrrAt($selected->endOfMonth());
        $previousMrr = $mrrAt($selected->subMonth()->endOfMonth());

        $inMonth = $invoices->filter(fn (object $invoice): bool => substr((string) $invoice->issued_on, 0, 7) === $selected->format('Y-m'));
        $collected = (int) $invoices->where('status', 'paid')->filter(fn (object $invoice): bool => substr((string) $invoice->paid_on, 0, 7) === $selected->format('Y-m'))->sum('amount_cents');
        $pending = $inMonth->where('status', 'pending');
        $overdueMonth = $pending->filter(fn (object $invoice): bool => $invoice->due_on < $today);
        $billed = (int) $inMonth->sum('amount_cents');

        $months = collect(range(11, 0))->map(function (int $back) use ($selected, $invoices): array {
            $month = $selected->subMonths($back);
            $slice = $invoices->filter(fn (object $invoice): bool => substr((string) $invoice->issued_on, 0, 7) === $month->format('Y-m'));

            return [
                'label' => self::MONTHS[$month->month - 1],
                'collected_cents' => (int) $slice->where('status', 'paid')->sum('amount_cents'),
                'pending_cents' => (int) $slice->where('status', 'pending')->sum('amount_cents'),
            ];
        })->values();

        $feeShare = $active->flatMap(function (object $contract): array {
            $parts = array_map('trim', explode('+', $contract->services));

            return array_map(fn (string $service): array => ['name' => $service, 'cents' => intdiv((int) $contract->monthly_fee_cents, max(1, count($parts)))], $parts);
        })->groupBy('name')->map(fn ($rows, string $name): array => ['name' => $name, 'value_cents' => (int) $rows->sum('cents')])->sortByDesc('value_cents')->values();

        $byOffice = $active->groupBy(fn (object $contract): string => (string) ($companies[$contract->company_id]['office_name'] ?? 'Sin oficina'))
            ->map(fn ($rows, string $office): array => ['name' => $office, 'value_cents' => (int) $rows->sum('monthly_fee_cents')])->sortByDesc('value_cents')->values();

        $topClients = $inMonth->groupBy('company_id')->map(fn ($rows, $companyId): array => ['name' => $companies[$companyId]['name'] ?? 'Empresa', 'value_cents' => (int) $rows->sum('amount_cents')])
            ->sortByDesc('value_cents')->take(5)->values();

        $late = $invoices->where('status', 'pending')->filter(fn (object $invoice): bool => $invoice->due_on < $today)->sortBy('due_on');

        return [
            'month' => $selected->format('Y-m'),
            'month_label' => ucfirst($selected->locale('es')->translatedFormat('F Y')),
            'month_options' => collect(range(0, 11))->map(fn (int $back): array => ['value' => $now->startOfMonth()->subMonths($back)->format('Y-m'), 'label' => ucfirst($now->startOfMonth()->subMonths($back)->locale('es')->translatedFormat('F Y'))])->all(),
            'kpis' => [
                'mrr_cents' => $mrr,
                'mrr_change' => $previousMrr > 0 ? round(($mrr - $previousMrr) / $previousMrr * 100, 1) : null,
                'billed_cents' => $billed,
                'collected_cents' => $collected,
                'collected_rate' => $billed > 0 ? (int) round(min($collected, $billed) / $billed * 100) : null,
                'pending_cents' => (int) $pending->sum('amount_cents'),
                'pending_rate' => $billed > 0 ? (int) round($pending->sum('amount_cents') / $billed * 100) : null,
                'ticket_cents' => $active->isEmpty() ? 0 : intdiv($mrr, max(1, $active->count())),
                'clients' => $active->count(),
            ],
            'months' => $months->all(),
            'by_office' => $byOffice->all(),
            'by_service' => $feeShare->all(),
            'collection' => [
                'collected_cents' => (int) $inMonth->where('status', 'paid')->sum('amount_cents'),
                'pending_cents' => (int) $pending->sum('amount_cents') - (int) $overdueMonth->sum('amount_cents'),
                'overdue_cents' => (int) $overdueMonth->sum('amount_cents'),
            ],
            'top_clients' => $topClients->all(),
            'late' => $late->take(5)->map(fn (object $invoice): array => [
                'id' => (int) $invoice->id,
                'company' => $companies[$invoice->company_id]['name'] ?? 'Empresa',
                'number' => $invoice->number,
                'days' => (int) CarbonImmutable::parse($invoice->due_on)->diffInDays($now->startOfDay()),
                'amount_cents' => (int) $invoice->amount_cents,
            ])->values()->all(),
            'late_total' => $late->count(),
            'renewals' => $contracts->where('status', '!=', 'cancelled')->filter(fn (object $contract): bool => $contract->renews_on !== null && $contract->renews_on >= $today)->sortBy('renews_on')->take(5)->map(fn (object $contract): array => [
                'company' => $companies[$contract->company_id]['name'] ?? 'Empresa',
                'services' => $contract->services,
                'renews_on' => $contract->renews_on,
                'amount_cents' => (int) $contract->monthly_fee_cents,
            ])->values()->all(),
            'invoices' => $invoices->take(10)->map(fn (object $invoice): array => [
                'id' => (int) $invoice->id,
                'number' => $invoice->number,
                'company' => $companies[$invoice->company_id]['name'] ?? 'Empresa',
                'concept' => $invoice->concept,
                'amount_cents' => (int) $invoice->amount_cents,
                'status' => $invoice->status === 'pending' && $invoice->due_on < $today ? 'overdue' : $invoice->status,
                'due_on' => $invoice->due_on,
                'issued_on' => $invoice->issued_on,
            ])->values()->all(),
            'companies' => $companies->map(fn (array $company): array => ['id' => (int) $company['id'], 'name' => $company['name']])->values()->all(),
        ];
    }

    /**
     * Every charge of the firm as export rows; the amount stays in cents (the export divides it).
     *
     * @return list<list<string|int>>
     */
    public function exportRows(Organization $organization, User $user): array
    {
        $companies = collect($this->dashboard->dashboard($organization, $user, 'executive')['companies'])->keyBy('id');
        $today = now()->toDateString();

        return DB::table('crm_invoices')->where('organization_id', $organization->id)->whereIn('company_id', $companies->keys()->all())->orderByDesc('issued_on')->orderByDesc('id')->get()
            ->map(fn (object $invoice): array => [
                $invoice->number,
                $companies[$invoice->company_id]['name'] ?? 'Empresa',
                $invoice->concept,
                (int) $invoice->amount_cents,
                $invoice->status === 'paid' ? 'Pagada' : ($invoice->status === 'pending' && $invoice->due_on < $today ? 'Vencida' : ($invoice->status === 'pending' ? 'Pendiente' : 'Anulada')),
                $invoice->due_on,
                $invoice->issued_on,
            ])->all();
    }

    /** Creates one charge per live contract for the month, skipping contracts already charged that month. */
    public function generateMonth(Organization $organization, User $actor, string $month): int
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $created = 0;

        foreach (DB::table('crm_contracts')->where('organization_id', $organization->id)->whereIn('status', ['active', 'onboarding', 'at_risk'])->where('starts_on', '<=', $start->endOfMonth()->toDateString())->get() as $contract) {
            $exists = DB::table('crm_invoices')->where('organization_id', $organization->id)->where('crm_contract_id', $contract->id)->whereBetween('issued_on', [$start->toDateString(), $start->endOfMonth()->toDateString()])->exists();

            if ($exists || $contract->monthly_fee_cents <= 0) {
                continue;
            }

            $created += $this->insertInvoice($organization, $actor, [
                'company_id' => $contract->company_id,
                'crm_contract_id' => $contract->id,
                'concept' => $contract->services.' – '.ucfirst($start->locale('es')->translatedFormat('F Y')),
                'amount_cents' => $contract->monthly_fee_cents,
                'issued_on' => $start->toDateString(),
                'due_on' => $start->addDays(14)->toDateString(),
            ]) > 0 ? 1 : 0;
        }

        $this->accounting->audit($organization, $actor, 'revenue.month_generated', 'organizations', $organization->id, ['month' => $month, 'created' => $created]);

        return $created;
    }

    /** @param array<string, mixed> $data */
    public function createInvoice(Organization $organization, User $actor, array $data): int
    {
        abort_unless(DB::table('companies')->where('organization_id', $organization->id)->where('id', $data['company_id'])->exists(), 422);

        return $this->insertInvoice($organization, $actor, [
            'company_id' => $data['company_id'],
            'crm_contract_id' => null,
            'concept' => $data['concept'],
            'amount_cents' => (int) round(((float) $data['amount']) * 100),
            'issued_on' => $data['issued_on'],
            'due_on' => $data['due_on'],
        ]);
    }

    public function markPaid(Organization $organization, User $actor, int $invoiceId, ?string $paidOn): void
    {
        $updated = DB::table('crm_invoices')->where('organization_id', $organization->id)->where('id', $invoiceId)->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_on' => $paidOn ?? now()->toDateString(), 'updated_at' => now()]);
        abort_if($updated === 0, 404);
        $this->accounting->audit($organization, $actor, 'revenue.invoice_paid', 'crm_invoices', $invoiceId);
    }

    /** @param array<string, mixed> $data */
    private function insertInvoice(Organization $organization, User $actor, array $data): int
    {
        $next = (int) DB::table('crm_invoices')->where('organization_id', $organization->id)->count() + 1;

        do {
            $number = 'COB-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
        } while (DB::table('crm_invoices')->where('organization_id', $organization->id)->where('number', $number)->exists());

        $id = (int) DB::table('crm_invoices')->insertGetId([...$data, 'organization_id' => $organization->id, 'number' => $number, 'status' => 'pending', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'revenue.invoice_created', 'crm_invoices', $id, ['number' => $number]);

        return $id;
    }
}
