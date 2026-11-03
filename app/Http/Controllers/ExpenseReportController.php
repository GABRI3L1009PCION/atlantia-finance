<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\ExpenseReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Expense reports of employees: register, submit, approve by someone else and reimburse. */
class ExpenseReportController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private ExpenseReportService $reports) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');

        return Inertia::render('accounting/expenses', [
            'company' => $record,
            'reports' => $this->reports->list((int) $record->id),
            'statuses' => ExpenseReportService::STATUSES,
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->whereIn('type', ['expense', 'asset'])->where('accepts_movements', true)->where('is_group', false)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'liabilities' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('type', 'liability')->where('accepts_movements', true)->where('is_group', false)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'banks' => DB::table('bank_accounts')->where('company_id', $record->id)->whereNotNull('ledger_account_id')->orderBy('bank_name')->get(['id', 'bank_name', 'account_name', 'last_four']),
            'userId' => $request->user()->id,
            'can' => [
                'create' => $this->can($request, $organization, $record, 'journal.create'),
                'approve' => $this->can($request, $organization, $record, 'journal.approve'),
                'reimburse' => $this->can($request, $organization, $record, 'banks.manage'),
            ],
        ]);
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate([
            'employee_name' => 'required|string|max:150', 'purpose' => 'required|string|max:250', 'lines' => 'required|array|min:1|max:50',
            'lines.*.spent_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'lines.*.description' => 'required|string|max:250', 'lines.*.supplier' => 'nullable|string|max:150',
            'lines.*.document_reference' => 'nullable|string|max:80', 'lines.*.account_id' => 'required|integer', 'lines.*.amount' => 'required|numeric|min:0.01|max:10000000',
            'lines.*.receipt' => ['nullable', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png'],
        ], ['lines.*.receipt.extensions' => 'El comprobante debe ser PDF o una foto (JPG o PNG).', 'lines.*.receipt.max' => 'Cada comprobante puede pesar hasta 10 MB.']);
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $lines[] = [...$line, 'amount' => number_format((float) $line['amount'], 2, '.', ''), 'receipt' => $request->file("lines.{$index}.receipt")];
        }
        $this->reports->create($organization, $request->user(), (int) $record->id, ['employee_name' => $data['employee_name'], 'purpose' => $data['purpose'], 'lines' => $lines]);

        return back()->with('workspace_notice', 'Liquidación de gastos registrada en borrador.');
    }

    public function action(Request $request, string $company, string $report, string $action): RedirectResponse
    {
        $permission = match ($action) {
            'submit' => 'journal.create',
            'approve', 'reject' => 'journal.approve',
            default => 'banks.manage',
        };
        [$organization, $record] = $this->scopeCompany($request, $company, $permission);
        $companyId = (int) $record->id;
        if ($action === 'submit') {
            $this->reports->submit($organization, $request->user(), $companyId, $report);
        } elseif ($action === 'approve') {
            $data = $request->validate(['payable_account_id' => 'required|integer']);
            $this->reports->approve($organization, $request->user(), $companyId, $report, (int) $data['payable_account_id']);
        } elseif ($action === 'reject') {
            $data = $request->validate(['reason' => 'required|string|min:5|max:300']);
            $this->reports->reject($organization, $request->user(), $companyId, $report, (string) $data['reason']);
        } else {
            $data = $request->validate(['bank_account_id' => 'required|integer', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'required|string|max:120']);
            $this->reports->reimburse($organization, $request->user(), $companyId, $report, (int) $data['bank_account_id'], $data['paid_on'], $data['reference']);
        }

        return back()->with('workspace_notice', match ($action) {
            'submit' => 'Liquidación enviada a aprobación.',
            'approve' => 'Liquidación aprobada; su asiento quedó en borrador.',
            'reject' => 'Liquidación rechazada.',
            default => 'Reembolso registrado con su asiento en borrador.',
        });
    }

    public function receipt(Request $request, string $company, int $line): HttpResponse
    {
        [, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $row = $this->reports->receipt((int) $record->id, $line);

        return Storage::disk('local')->download((string) $row->receipt_path, (string) ($row->receipt_name ?? 'comprobante'), ['Cache-Control' => 'no-store, private']);
    }
}
