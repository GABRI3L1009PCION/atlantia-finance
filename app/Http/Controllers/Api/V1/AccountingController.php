<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Accounting\TrialBalanceService;
use App\Services\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Read-only accounting: chart of accounts, financial statements built from posted entries only, and certified FEL
 * documents. Nothing here writes to the books.
 */
class AccountingController extends ApiController
{
    public function accounts(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'accounts:read');
        $filters = $request->validate(['type' => ['nullable', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])], 'accepts_movements' => 'nullable|boolean']);
        $query = DB::table('ledger_accounts')->where('company_id', $record->id)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when(array_key_exists('accepts_movements', $filters) && $filters['accepts_movements'] !== null, fn ($q) => $q->where('accepts_movements', (bool) $filters['accepts_movements']))
            ->orderBy('code');

        return $this->paginate($request, $query, fn (stdClass $row): array => $this->resources->ledgerAccount($row));
    }

    public function report(Request $request, string $company, string $report, FinancialReportService $reports, TrialBalanceService $trialBalance): JsonResponse
    {
        $record = $this->company($request, $company, 'reports:read');
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $meta = ['company' => $this->resources->company($record), 'from' => $data['from'], 'to' => $data['to'], 'currency' => (string) $record->currency,
            'basis' => 'Solo partidas contabilizadas (posted). Importes en centavos.'];

        if ($report === 'trial-balance') {
            $built = $trialBalance->build((int) $record->id, $data['from'], $data['to']);

            return $this->item([...$meta, 'report' => 'trial_balance', 'summary' => $built['summary'],
                'rows' => array_values(array_map(fn (array $row): array => ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'],
                    'opening_debit_cents' => $row['opening_debit_cents'], 'opening_credit_cents' => $row['opening_credit_cents'], 'period_debit_cents' => $row['period_debit_cents'],
                    'period_credit_cents' => $row['period_credit_cents'], 'closing_debit_cents' => $row['closing_debit_cents'], 'closing_credit_cents' => $row['closing_credit_cents']], $built['rows']))]);
        }

        $statements = $reports->statements((int) $record->id, $data['from'], $data['to']);
        $map = fn (array $row, int $sign): array => ['account_id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'type' => (string) $row['type'], 'amount_cents' => $sign * (int) $row['net']];
        if ($report === 'income-statement') {
            return $this->item([...$meta, 'report' => 'income_statement', 'income_cents' => (int) $statements['income'], 'expenses_cents' => (int) $statements['expenses'], 'profit_cents' => (int) $statements['profit'],
                'rows' => array_values(array_map(fn (array $row): array => $map($row, $row['type'] === 'income' ? -1 : 1), $statements['incomeRows']))]);
        }

        return $this->item([...$meta, 'report' => 'balance_sheet', 'assets_cents' => (int) $statements['assets'], 'liabilities_cents' => (int) $statements['liabilities'],
            'equity_cents' => (int) $statements['equity'], 'retained_earnings_cents' => (int) $statements['accumulated'], 'difference_cents' => (int) $statements['difference'],
            'rows' => array_values(array_map(fn (array $row): array => $map($row, $row['type'] === 'asset' ? 1 : -1), $statements['positionRows']))]);
    }

    public function felDocuments(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'fel:read');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['certified', 'cancelled'])], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $query = DB::table('fel_documents')->where('company_id', $record->id)->whereIn('status', isset($filters['status']) ? [$filters['status']] : ['certified', 'cancelled'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('issued_on', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('issued_on', '<=', $to))
            ->orderByDesc('issued_on')->orderByDesc('id');

        return $this->paginate($request, $query, fn (stdClass $row): array => $this->resources->felDocument($row));
    }

    public function felDocument(Request $request, string $company, string $document): JsonResponse
    {
        $record = $this->company($request, $company, 'fel:read');
        $row = DB::table('fel_documents')->where('company_id', $record->id)->where('public_id', $document)->whereIn('status', ['certified', 'cancelled'])->first()
            ?? $this->fail(404, 'not_found', 'El documento FEL no existe o no está certificado.');

        return $this->item($this->resources->felDocument($row));
    }
}
