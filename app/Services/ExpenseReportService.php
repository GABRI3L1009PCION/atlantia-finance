<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Expense reports of employees: what someone paid out of pocket for the company (fuel, meals, parking), line by line
 * with its receipt. The report is submitted, approved by someone other than whoever submitted it (its entry debits each
 * expense against what is owed to the employee) and reimbursed from a bank (its entry pays that debt). Expenses
 * documented with an invoice that gives VAT credit belong in Purchases; here VAT stays inside the expense.
 */
class ExpenseReportService
{
    public const STATUSES = ['draft' => 'Borrador', 'submitted' => 'Por aprobar', 'approved' => 'Aprobada, por reembolsar', 'rejected' => 'Rechazada', 'reimbursed' => 'Reembolsada'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(int $companyId): array
    {
        $lines = DB::table('expense_report_lines')->join('ledger_accounts', 'ledger_accounts.id', '=', 'expense_report_lines.ledger_account_id')
            ->whereIn('expense_report_id', DB::table('expense_reports')->where('company_id', $companyId)->select('id'))->orderBy('spent_on')
            ->get(['expense_report_lines.*', 'ledger_accounts.code', 'ledger_accounts.name as account_name'])->groupBy('expense_report_id');

        return array_values(DB::table('expense_reports')->where('company_id', $companyId)->orderByDesc('id')->limit(200)->get()
            ->map(fn (stdClass $report): array => ['public_id' => (string) $report->public_id, 'number' => (int) $report->number, 'employee_name' => (string) $report->employee_name,
                'purpose' => (string) $report->purpose, 'status' => (string) $report->status, 'total_cents' => (int) $report->total_cents,
                'submitted_by' => $report->submitted_by === null ? null : (int) $report->submitted_by, 'rejection_reason' => $report->rejection_reason,
                'reimbursed_on' => $report->reimbursed_on, 'reimbursement_reference' => $report->reimbursement_reference,
                'lines' => array_values(($lines->get($report->id) ?? collect())->map(fn (stdClass $line): array => ['id' => (int) $line->id, 'spent_on' => (string) $line->spent_on,
                    'description' => (string) $line->description, 'supplier' => $line->supplier, 'document_reference' => $line->document_reference,
                    'account' => $line->code.' · '.$line->account_name, 'amount_cents' => (int) $line->amount_cents, 'has_receipt' => $line->receipt_path !== null])->all())])->all());
    }

    /**
     * @param  array{employee_name: string, purpose: string, lines: list<array{spent_on: string, description: string, supplier?: string|null, document_reference?: string|null, account_id: int|string, amount: string, receipt?: UploadedFile|null}>}  $data
     */
    public function create(Organization $organization, User $user, int $companyId, array $data): string
    {
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('type', ['expense', 'asset'])->where('accepts_movements', true)->where('is_group', false)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $amount = $this->accounting->cents((string) $line['amount']);
            if ($amount <= 0 || ! in_array((int) $line['account_id'], $accounts, true)) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Cada gasto necesita un monto mayor que cero y una cuenta de gasto de esta empresa.']);
            }
            $lines[] = [...$line, 'amount_cents' => $amount];
        }

        return DB::transaction(function () use ($organization, $user, $companyId, $data, $lines): string {
            $this->accounting->lock($companyId);
            $publicId = (string) Str::uuid();
            $id = DB::table('expense_reports')->insertGetId([
                'public_id' => $publicId, 'organization_id' => $organization->id, 'company_id' => $companyId,
                'number' => (int) DB::table('expense_reports')->where('company_id', $companyId)->max('number') + 1, 'employee_name' => $data['employee_name'], 'purpose' => $data['purpose'],
                'status' => 'draft', 'total_cents' => array_sum(array_column($lines, 'amount_cents')), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                $receipt = $line['receipt'] ?? null;
                $receiptName = $receipt === null ? null : mb_substr($receipt->getClientOriginalName(), 0, 250);
                $path = $receipt === null ? null : $receipt->storeAs('expense-receipts/'.$organization->id.'/'.$companyId, Str::uuid().'.'.$receipt->getClientOriginalExtension(), 'local');
                DB::table('expense_report_lines')->insert(['expense_report_id' => $id, 'spent_on' => $line['spent_on'], 'description' => mb_substr($line['description'], 0, 250),
                    'supplier' => filled($line['supplier'] ?? null) ? mb_substr((string) $line['supplier'], 0, 150) : null,
                    'document_reference' => filled($line['document_reference'] ?? null) ? mb_substr((string) $line['document_reference'], 0, 80) : null,
                    'ledger_account_id' => (int) $line['account_id'], 'amount_cents' => $line['amount_cents'], 'receipt_path' => $path === false ? null : $path,
                    'receipt_name' => $receiptName, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $user, 'expense_report.created', 'expense_reports', $id, ['lines' => count($lines)]);

            return $publicId;
        });
    }

    public function submit(Organization $organization, User $user, int $companyId, string $publicId): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $publicId): void {
            $report = $this->report($companyId, $publicId);
            $this->expect($report, ['draft', 'rejected'], 'Solo se envía a aprobación una liquidación en borrador o rechazada.');
            DB::table('expense_reports')->where('id', $report->id)->update(['status' => 'submitted', 'submitted_by' => $user->id, 'submitted_at' => now(), 'rejection_reason' => null, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'expense_report.submitted', 'expense_reports', (int) $report->id, []);
        });
    }

    /** Approves the report (never by whoever submitted it) and drafts its entry: each expense against the debt to the employee. */
    public function approve(Organization $organization, User $user, int $companyId, string $publicId, int $payableAccountId): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $publicId, $payableAccountId): void {
            $report = $this->report($companyId, $publicId);
            $this->expect($report, ['submitted'], 'La liquidación debe estar enviada a aprobación.');
            if ((int) $report->submitted_by === $user->id || (int) $report->created_by === $user->id) {
                throw ValidationException::withMessages(['expense_report' => 'Quien registra o envía una liquidación no puede aprobarla.']);
            }
            if (! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $payableAccountId)->where('type', 'liability')->exists()) {
                throw ValidationException::withMessages(['payable_account_id' => 'Elige la cuenta de pasivo donde queda lo que se le debe al empleado.']);
            }
            $lines = DB::table('expense_report_lines')->where('expense_report_id', $report->id)->get();
            $date = (string) $lines->max('spent_on');
            $entry = $this->automation->draft($organization, $user, $companyId, 'expense_report', (int) $report->id, $date, 'Liquidación de gastos '.$report->number.' · '.$report->employee_name, 'LIQ-'.$report->number, [
                ...$lines->map(fn (stdClass $line): array => ['account_id' => (int) $line->ledger_account_id, 'debit' => $this->decimal((int) $line->amount_cents), 'credit' => '0',
                    'description' => mb_substr($line->description.($line->supplier ? ' · '.$line->supplier : ''), 0, 250)])->all(),
                ['account_id' => $payableAccountId, 'debit' => '0', 'credit' => $this->decimal((int) $report->total_cents), 'description' => 'Por reembolsar a '.$report->employee_name],
            ], 'expense_report.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['expense_report' => 'Abre un período contable que incluya la fecha de los gastos.']);
            }
            DB::table('expense_reports')->where('id', $report->id)->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'payable_account_id' => $payableAccountId,
                'approval_entry_id' => $entry, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'expense_report.approved', 'expense_reports', (int) $report->id, ['entry' => $entry]);
        });
    }

    public function reject(Organization $organization, User $user, int $companyId, string $publicId, string $reason): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $publicId, $reason): void {
            $report = $this->report($companyId, $publicId);
            $this->expect($report, ['submitted'], 'Solo se rechaza una liquidación enviada a aprobación.');
            DB::table('expense_reports')->where('id', $report->id)->update(['status' => 'rejected', 'rejection_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'expense_report.rejected', 'expense_reports', (int) $report->id, ['reason' => $reason]);
        });
    }

    /** Records the reimbursement from a bank account of the company and drafts its entry (the debt against the bank). */
    public function reimburse(Organization $organization, User $user, int $companyId, string $publicId, int $bankAccountId, string $paidOn, string $reference): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $publicId, $bankAccountId, $paidOn, $reference): void {
            $report = $this->report($companyId, $publicId);
            $this->expect($report, ['approved'], 'Solo se reembolsa una liquidación aprobada.');
            $bankLedger = DB::table('bank_accounts')->where('company_id', $companyId)->where('id', $bankAccountId)->value('ledger_account_id');
            if ($bankLedger === null) {
                throw ValidationException::withMessages(['bank_account_id' => 'Elige una cuenta bancaria de esta empresa con su cuenta contable.']);
            }
            $amount = $this->decimal((int) $report->total_cents);
            $entry = $this->automation->draft($organization, $user, $companyId, 'expense_reimbursement', (int) $report->id, $paidOn, 'Reembolso de gastos '.$report->number.' · '.$report->employee_name,
                mb_substr($reference, 0, 100), [
                    ['account_id' => (int) $report->payable_account_id, 'debit' => $amount, 'credit' => '0', 'description' => 'Reembolso a '.$report->employee_name],
                    ['account_id' => (int) $bankLedger, 'debit' => '0', 'credit' => $amount],
                ], 'expense_reimbursement.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['paid_on' => 'Abre un período contable que incluya la fecha del reembolso.']);
            }
            DB::table('expense_reports')->where('id', $report->id)->update(['status' => 'reimbursed', 'reimbursed_on' => $paidOn, 'reimbursement_reference' => mb_substr($reference, 0, 120),
                'payment_entry_id' => $entry, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'expense_report.reimbursed', 'expense_reports', (int) $report->id, ['entry' => $entry]);
        });
    }

    /** The stored receipt of one line of a report of the company. */
    public function receipt(int $companyId, int $lineId): stdClass
    {
        $line = DB::table('expense_report_lines')->join('expense_reports', 'expense_reports.id', '=', 'expense_report_lines.expense_report_id')
            ->where('expense_reports.company_id', $companyId)->where('expense_report_lines.id', $lineId)->first(['expense_report_lines.receipt_path', 'expense_report_lines.receipt_name']);
        abort_if($line === null || $line->receipt_path === null || ! Storage::disk('local')->exists((string) $line->receipt_path), 404);

        return $line;
    }

    private function report(int $companyId, string $publicId): stdClass
    {
        return DB::table('expense_reports')->where('company_id', $companyId)->where('public_id', $publicId)->lockForUpdate()->first() ?? abort(404);
    }

    /** @param  list<string>  $statuses */
    private function expect(stdClass $report, array $statuses, string $message): void
    {
        if (! in_array($report->status, $statuses, true)) {
            throw ValidationException::withMessages(['expense_report' => $message]);
        }
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
