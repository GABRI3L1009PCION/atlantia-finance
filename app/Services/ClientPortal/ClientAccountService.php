<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\FirmRevenueService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The account between a client company and its accounting office: the fees the office charges (crm_invoices), what has
 * been paid, what is pending and the payments the client reports. A reported payment never changes the charge by
 * itself: the office confirms it against its bank and only then the charge is marked as paid.
 */
class ClientAccountService
{
    public const METHODS = ['transfer' => 'Transferencia', 'deposit' => 'Depósito', 'cash' => 'Efectivo', 'check' => 'Cheque', 'card' => 'Tarjeta', 'other' => 'Otro'];

    public function __construct(
        private AccountingService $accounting,
        private FirmRevenueService $revenue,
        private WorkNotificationService $notifications,
    ) {}

    /** @return array<string, mixed> */
    public function statement(int $companyId): array
    {
        $today = now()->toDateString();
        $notices = DB::table('client_payment_notices')->where('company_id', $companyId)->orderByDesc('id')->get();
        $confirmed = $notices->where('status', 'confirmed')->groupBy('crm_invoice_id')->map(fn ($rows): int => (int) $rows->sum('amount_cents'));
        $pendingNotices = $notices->where('status', 'pending')->groupBy('crm_invoice_id')->map(fn ($rows): int => (int) $rows->sum('amount_cents'));

        $invoices = DB::table('crm_invoices')->where('company_id', $companyId)->orderByDesc('issued_on')->orderByDesc('id')->limit(120)->get()->map(function (stdClass $invoice) use ($today, $confirmed, $pendingNotices): array {
            $amount = (int) $invoice->amount_cents;
            $paid = $invoice->status === 'paid' ? $amount : min($amount, (int) ($confirmed[$invoice->id] ?? 0));
            $status = $invoice->status === 'pending' && $invoice->due_on < $today ? 'overdue' : (string) $invoice->status;

            return [
                'id' => (int) $invoice->id, 'number' => (string) $invoice->number, 'concept' => (string) $invoice->concept, 'issued_on' => (string) $invoice->issued_on, 'due_on' => (string) $invoice->due_on,
                'paid_on' => $invoice->paid_on === null ? null : (string) $invoice->paid_on, 'amount_cents' => $amount, 'paid_cents' => $paid, 'balance_cents' => in_array($invoice->status, ['paid', 'void'], true) ? 0 : $amount - $paid,
                'status' => $status, 'reported_cents' => (int) ($pendingNotices[$invoice->id] ?? 0),
            ];
        })->values();

        $open = $invoices->whereIn('status', ['pending', 'overdue']);
        $contract = DB::table('crm_contracts')->where('company_id', $companyId)->whereNotIn('status', ['cancelled'])->orderByDesc('id')->first();

        return [
            'invoices' => $invoices->all(),
            'totals' => [
                'billed_cents' => (int) $invoices->where('status', '!=', 'void')->sum('amount_cents'),
                'paid_cents' => (int) $invoices->where('status', '!=', 'void')->sum('paid_cents'),
                'balance_cents' => (int) $open->sum('balance_cents'),
                'overdue_cents' => (int) $invoices->where('status', 'overdue')->sum('balance_cents'),
                'open_count' => $open->count(),
            ],
            'contract' => $contract === null ? null : ['services' => (string) $contract->services, 'monthly_fee_cents' => (int) $contract->monthly_fee_cents, 'renews_on' => $contract->renews_on === null ? null : (string) $contract->renews_on, 'status' => (string) $contract->status],
            'notices' => $notices->take(30)->map(fn (stdClass $notice): array => $this->noticeRow($notice))->values()->all(),
            'methods' => self::METHODS,
        ];
    }

    /** @param array{invoice_id: int, amount: string, paid_on: string, method: string, reference: string, note?: string|null} $data */
    public function reportPayment(Organization $organization, User $client, int $companyId, array $data): int
    {
        $invoice = DB::table('crm_invoices')->where('company_id', $companyId)->where('id', $data['invoice_id'])->first();
        if ($invoice === null) {
            throw ValidationException::withMessages(['invoice_id' => 'El cobro no existe en tu empresa.']);
        }
        $amount = $this->accounting->cents($data['amount']);
        $reported = (int) DB::table('client_payment_notices')->where('crm_invoice_id', $invoice->id)->whereIn('status', ['pending', 'confirmed'])->sum('amount_cents');
        if ($invoice->status !== 'pending') {
            throw ValidationException::withMessages(['invoice_id' => 'Este cobro ya no tiene saldo pendiente.']);
        }
        if ($amount <= 0 || $amount > (int) $invoice->amount_cents - $reported) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser positivo y no superar el saldo pendiente del cobro.']);
        }
        if (DB::table('client_payment_notices')->where('crm_invoice_id', $invoice->id)->whereIn('status', ['pending', 'confirmed'])->whereRaw('lower(reference) = ?', [mb_strtolower($data['reference'])])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Ya informaste un pago con esa referencia.']);
        }

        $id = (int) DB::table('client_payment_notices')->insertGetId([
            'organization_id' => $organization->id, 'company_id' => $companyId, 'crm_invoice_id' => $invoice->id, 'reported_by' => $client->id, 'amount_cents' => $amount,
            'paid_on' => $data['paid_on'], 'method' => $data['method'], 'reference' => $data['reference'], 'note' => $data['note'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $client, 'client.account.payment_reported', 'client_payment_notices', $id, ['invoice' => $invoice->number, 'amount_cents' => $amount]);
        $company = (string) DB::table('companies')->where('id', $companyId)->value('name');
        foreach ($this->recipients($organization) as $recipient) {
            $this->notifications->notify($organization->id, $recipient, $client->id, 'client.payment_reported', $company.' informó un pago de '.$invoice->number, 'Referencia '.$data['reference'], 'client_payment_notices', $id);
        }

        return $id;
    }

    /** Confirms or rejects a reported payment. A confirmed payment that covers the charge marks it as paid. */
    public function review(Organization $organization, User $actor, int $noticeId, string $action, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $noticeId, $action, $note): void {
            $notice = DB::table('client_payment_notices')->where('organization_id', $organization->id)->where('id', $noticeId)->lockForUpdate()->first();
            abort_if($notice === null, 404);
            abort_unless($notice->status === 'pending', 422, 'Este aviso ya fue revisado.');
            DB::table('client_payment_notices')->where('id', $noticeId)->update(['status' => $action === 'confirm' ? 'confirmed' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'client.account.payment_'.($action === 'confirm' ? 'confirmed' : 'rejected'), 'client_payment_notices', $noticeId, ['invoice_id' => (int) $notice->crm_invoice_id]);

            if ($action === 'confirm') {
                $invoice = DB::table('crm_invoices')->where('id', $notice->crm_invoice_id)->first();
                $confirmed = (int) DB::table('client_payment_notices')->where('crm_invoice_id', $notice->crm_invoice_id)->where('status', 'confirmed')->sum('amount_cents');
                if ($invoice !== null && $invoice->status === 'pending' && $confirmed >= (int) $invoice->amount_cents) {
                    $this->revenue->markPaid($organization, $actor, (int) $invoice->id, (string) $notice->paid_on);
                }
            }
            $this->notifications->notify($organization->id, $notice->reported_by === null ? null : (int) $notice->reported_by, $actor->id, 'client.payment_reviewed',
                $action === 'confirm' ? 'Tu oficina confirmó tu pago' : 'Tu oficina no pudo confirmar tu pago', $note, 'client_payment_notices', $noticeId);
        });
    }

    /**
     * Reported payments waiting for the office, with the charge and the company.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForOffice(int $organizationId): array
    {
        return array_values(DB::table('client_payment_notices')->join('crm_invoices', 'crm_invoices.id', '=', 'client_payment_notices.crm_invoice_id')->join('companies', 'companies.id', '=', 'client_payment_notices.company_id')
            ->leftJoin('users', 'users.id', '=', 'client_payment_notices.reported_by')->where('client_payment_notices.organization_id', $organizationId)->where('client_payment_notices.status', 'pending')
            ->orderBy('client_payment_notices.created_at')->limit(50)
            ->get(['client_payment_notices.*', 'crm_invoices.number as invoice_number', 'crm_invoices.amount_cents as invoice_cents', 'companies.name as company_name', 'users.name as reporter'])
            ->map(fn (stdClass $notice): array => [...$this->noticeRow($notice), 'invoice_number' => (string) $notice->invoice_number, 'invoice_cents' => (int) $notice->invoice_cents, 'company' => (string) $notice->company_name, 'reporter' => (string) ($notice->reporter ?? '—')])->all());
    }

    /** @return array<string, mixed> */
    private function noticeRow(stdClass $notice): array
    {
        return ['id' => (int) $notice->id, 'invoice_id' => (int) $notice->crm_invoice_id, 'amount_cents' => (int) $notice->amount_cents, 'paid_on' => (string) $notice->paid_on, 'method' => self::METHODS[$notice->method] ?? (string) $notice->method,
            'reference' => (string) $notice->reference, 'note' => $notice->note, 'status' => (string) $notice->status, 'review_note' => $notice->review_note, 'created_at' => (string) $notice->created_at];
    }

    /** @return list<int> */
    private function recipients(Organization $organization): array
    {
        return array_values(DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }
}
