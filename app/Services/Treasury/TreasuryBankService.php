<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Bank side of treasury: statement import (parse, validate, detect duplicates, preview, confirm once), matching of
 * movements with receipts, payments and transfers (suggested, confirmed, auto only when unambiguous, always
 * reversible and audited), classification of fees and interest through the company's accounting mapping, unidentified
 * movements kept pending with an incident, transfers between accounts of the same company and cash counts.
 */
class TreasuryBankService
{
    public const CLASSIFICATIONS = ['receipt' => 'Cobro', 'payment' => 'Pago', 'transfer' => 'Transferencia', 'bank_fee' => 'Comisión bancaria', 'interest' => 'Intereses', 'tax' => 'Impuesto', 'payroll' => 'Planilla', 'unknown' => 'Sin identificar', 'other' => 'Otro'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
    ) {}

    /**
     * Parses a CSV statement (fecha, referencia, descripcion, monto[, saldo]; negative amounts are debits) and returns
     * a preview token with the rows marked valid, invalid or duplicate. Nothing is written yet.
     *
     * @return array{token: string, account: array{id: int, name: string}, rows: list<array<string, mixed>>, valid: int, duplicates: int, invalid: int}
     */
    public function preview(int $companyId, int $accountId, UploadedFile $file, User $user): array
    {
        $account = DB::table('bank_accounts')->where('id', $accountId)->where('company_id', $companyId)->where('status', 'active')->first(['id', 'bank_name', 'last_four']);
        abort_if($account === null, 403);
        $handle = fopen($file->getRealPath() ?: '', 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'No fue posible leer el archivo.']);
        }
        $header = array_map(fn ($value): string => mb_strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        foreach (['fecha', 'referencia', 'descripcion', 'monto'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'El archivo debe tener las columnas fecha, referencia, descripcion y monto (saldo opcional).']);
            }
        }

        $rows = [];
        $seen = [];
        $line = 1;
        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($raw, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $data = array_combine($header, array_pad(array_slice($raw, 0, count($header)), count($header), ''));
            $errors = [];
            $date = $this->parseDate((string) $data['fecha']);
            $amountText = str_replace([',', 'Q', ' '], '', (string) $data['monto']);
            $amount = is_numeric($amountText) ? (int) round(((float) $amountText) * 100) : 0;
            $reference = mb_substr(trim((string) $data['referencia']), 0, 100);
            $description = mb_substr(trim((string) $data['descripcion']), 0, 250);
            if ($date === null) {
                $errors[] = 'Fecha inválida';
            } elseif ($date > now()->toDateString()) {
                $errors[] = 'Fecha futura';
            }
            if ($amount === 0) {
                $errors[] = 'Monto inválido';
            }
            if ($reference === '') {
                $errors[] = 'Sin referencia';
            }
            $hash = hash('sha256', $accountId.'|'.$date.'|'.$amount.'|'.$reference.'|'.mb_strtolower($description));
            $duplicate = in_array($hash, $seen, true) || DB::table('bank_transactions')->where('bank_account_id', $accountId)->where(fn ($query) => $query->where('import_hash', $hash)->orWhere('reference', $reference))->exists();
            $seen[] = $hash;
            $balanceText = isset($data['saldo']) ? str_replace([',', 'Q', ' '], '', (string) $data['saldo']) : '';
            $rows[] = [
                'line' => $line, 'date' => $date, 'reference' => $reference, 'description' => $description,
                'flow' => $amount >= 0 ? 'inflow' : 'outflow', 'amount_cents' => abs($amount),
                'balance_cents' => is_numeric($balanceText) ? (int) round(((float) $balanceText) * 100) : null,
                'hash' => $hash, 'status' => $errors !== [] ? 'invalid' : ($duplicate ? 'duplicate' : 'valid'), 'errors' => $errors,
            ];
        }
        fclose($handle);

        $token = (string) Str::uuid();
        Cache::put('bank_import:'.$token, ['user_id' => $user->id, 'company_id' => $companyId, 'account_id' => $accountId, 'rows' => $rows], now()->addMinutes(30));

        return [
            'token' => $token,
            'account' => ['id' => (int) $account->id, 'name' => $account->bank_name.' ****'.$account->last_four],
            'rows' => $rows,
            'valid' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'valid')),
            'duplicates' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'duplicate')),
            'invalid' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'invalid')),
        ];
    }

    /**
     * Persists the valid rows of a preview once (the token is consumed; unique hashes and references protect against
     * double imports) and auto-matches only unambiguous movements.
     *
     * @return array{imported: int, auto_matched: int}
     */
    public function confirm(Organization $organization, User $user, int $companyId, string $token): array
    {
        $preview = Cache::pull('bank_import:'.$token);
        if (! is_array($preview) || $preview['user_id'] !== $user->id || $preview['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['token' => 'La vista previa expiró o no te pertenece; vuelve a cargar el archivo.']);
        }

        return DB::transaction(function () use ($organization, $user, $companyId, $preview): array {
            $this->accounting->lock($companyId);
            $account = DB::table('bank_accounts')->where('id', $preview['account_id'])->where('company_id', $companyId)->lockForUpdate()->first();
            abort_if($account === null, 403);
            $rows = array_values(array_filter($preview['rows'], fn (array $row): bool => $row['status'] === 'valid'));
            usort($rows, fn (array $a, array $b): int => [$a['date'], $a['line']] <=> [$b['date'], $b['line']]);
            $balance = (int) $account->bank_balance_cents;
            $imported = 0;
            $autoMatched = 0;

            foreach ($rows as $row) {
                if (DB::table('bank_transactions')->where('bank_account_id', $account->id)->where(fn ($query) => $query->where('import_hash', $row['hash'])->orWhere('reference', $row['reference']))->exists()) {
                    continue;
                }
                $balance = $row['balance_cents'] ?? ($balance + ($row['flow'] === 'inflow' ? $row['amount_cents'] : -$row['amount_cents']));
                $id = DB::table('bank_transactions')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'bank_account_id' => $account->id, 'transaction_date' => $row['date'], 'reference' => $row['reference'],
                    'description' => $row['description'], 'flow' => $row['flow'], 'amount_cents' => $row['amount_cents'], 'running_balance_cents' => $balance,
                    'reconciliation_status' => 'pending', 'source' => 'import', 'import_hash' => $row['hash'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $imported++;
                $candidates = $this->suggestions($id);
                if (count($candidates) === 1 && $candidates[0]['exact_reference']) {
                    $this->applyMatch($organization, null, $id, $candidates[0]['type'], $candidates[0]['id']);
                    $autoMatched++;
                }
            }
            DB::table('bank_accounts')->where('id', $account->id)->update(['bank_balance_cents' => $balance, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'bank_transaction.imported', 'bank_accounts', (int) $account->id, ['imported' => $imported, 'auto_matched' => $autoMatched], null);

            return ['imported' => $imported, 'auto_matched' => $autoMatched];
        });
    }

    /**
     * Candidate counterparts of a movement: same amount and direction, close date, not matched yet.
     *
     * @return list<array{type: string, id: int, label: string, date: string, amount_cents: int, exact_reference: bool}>
     */
    public function suggestions(int $transactionId): array
    {
        $movement = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_transactions.id', $transactionId)
            ->first(['bank_transactions.*', 'bank_accounts.company_id']);
        if ($movement === null || $movement->reconciliation_status === 'matched') {
            return [];
        }
        $from = Carbon::parse($movement->transaction_date)->subDays(7)->toDateString();
        $to = Carbon::parse($movement->transaction_date)->addDays(7)->toDateString();
        $taken = fn (string $type) => DB::table('bank_transactions')->where('matched_source_type', $type)->whereNotNull('matched_source_id')->select('matched_source_id');
        $rows = [];

        if ($movement->flow === 'inflow') {
            foreach (DB::table('treasury_receipts')->where('company_id', $movement->company_id)->where('bank_account_id', $movement->bank_account_id)->where('amount_cents', $movement->amount_cents)
                ->whereBetween('received_on', [$from, $to])->whereNotIn('id', $taken('treasury_receipts'))->get(['id', 'customer_name', 'reference', 'received_on', 'amount_cents']) as $receipt) {
                $rows[] = ['type' => 'treasury_receipts', 'id' => (int) $receipt->id, 'label' => 'Cobro '.$receipt->reference.' · '.$receipt->customer_name, 'date' => (string) $receipt->received_on, 'amount_cents' => (int) $receipt->amount_cents, 'exact_reference' => $this->sameReference($movement, (string) $receipt->reference)];
            }
        } else {
            foreach (DB::table('payment_instructions')->where('company_id', $movement->company_id)->where('bank_account_id', $movement->bank_account_id)->where('status', 'executed')->where('amount_cents', $movement->amount_cents)
                ->whereBetween('executed_on', [$from, $to])->whereNotIn('id', $taken('payment_instructions'))->get(['id', 'supplier_name', 'execution_reference', 'executed_on', 'amount_cents']) as $payment) {
                $rows[] = ['type' => 'payment_instructions', 'id' => (int) $payment->id, 'label' => 'Pago '.$payment->execution_reference.' · '.$payment->supplier_name, 'date' => (string) $payment->executed_on, 'amount_cents' => (int) $payment->amount_cents, 'exact_reference' => $this->sameReference($movement, (string) $payment->execution_reference)];
            }
        }

        return $rows;
    }

    /** Confirms a suggested (or chosen) counterpart for a movement. */
    public function match(Organization $organization, User $user, int $transactionId, string $type, int $sourceId): void
    {
        DB::transaction(function () use ($organization, $user, $transactionId, $type, $sourceId): void {
            $valid = collect($this->suggestions($transactionId))->contains(fn (array $row): bool => $row['type'] === $type && $row['id'] === $sourceId);
            if (! $valid) {
                throw ValidationException::withMessages(['match' => 'La operación no coincide con el movimiento (monto, cuenta o fecha) o ya está conciliada.']);
            }
            $this->applyMatch($organization, $user, $transactionId, $type, $sourceId);
        });
    }

    /** Undoes a match made by the user or the auto matcher; classified fees and interest are reversed in accounting. */
    public function unmatch(Organization $organization, User $user, int $transactionId): void
    {
        DB::transaction(function () use ($organization, $user, $transactionId): void {
            $movement = DB::table('bank_transactions')->where('id', $transactionId)->lockForUpdate()->first();
            abort_if($movement === null, 404);
            if ($movement->reconciliation_status !== 'matched' || in_array($movement->matched_source_type, ['journal_entries', 'bank_transfers'], true)) {
                throw ValidationException::withMessages(['match' => 'Este movimiento no tiene un match reversible (las comisiones, intereses y transferencias se corrigen con un reverso contable).']);
            }
            DB::table('bank_transactions')->where('id', $transactionId)->update(['reconciliation_status' => 'pending', 'classification' => null, 'matched_source_type' => null, 'matched_source_id' => null, 'matched_by' => null, 'matched_at' => null, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'bank_transaction.unmatched', 'bank_transactions', $transactionId, ['was' => [$movement->matched_source_type, $movement->matched_source_id]]);
        });
    }

    /**
     * Classifies a movement. Fees and interest get their entry from the company's accounting mapping (never a fixed
     * account); unknown movements stay pending as unidentified with an incident for investigation.
     */
    public function classify(Organization $organization, User $user, int $transactionId, string $classification): void
    {
        DB::transaction(function () use ($organization, $user, $transactionId, $classification): void {
            $movement = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_transactions.id', $transactionId)->lockForUpdate()
                ->first(['bank_transactions.*', 'bank_accounts.company_id', 'bank_accounts.ledger_account_id', 'bank_accounts.bank_name']);
            abort_if($movement === null, 404);
            if ($movement->reconciliation_status === 'matched') {
                throw ValidationException::withMessages(['classification' => 'El movimiento ya está conciliado.']);
            }
            $office = (int) DB::table('companies')->where('id', $movement->company_id)->value('office_id');

            if (in_array($classification, ['bank_fee', 'interest'], true)) {
                if (($classification === 'bank_fee') !== ($movement->flow === 'outflow')) {
                    throw ValidationException::withMessages(['classification' => $classification === 'bank_fee' ? 'Una comisión es un débito del banco.' : 'Los intereses son un crédito del banco.']);
                }
                $mapping = $this->automation->ensureMapping((int) $movement->company_id);
                if ($mapping === null) {
                    throw ValidationException::withMessages(['mapping' => 'Configura el mapeo contable de la empresa antes de clasificar.']);
                }
                $amount = number_format((int) $movement->amount_cents / 100, 2, '.', '');
                $lines = $classification === 'bank_fee'
                    ? [['account_id' => (int) $mapping->default_expense_account_id, 'debit' => $amount, 'credit' => '0'], ['account_id' => (int) $movement->ledger_account_id, 'debit' => '0', 'credit' => $amount]]
                    : [['account_id' => (int) $movement->ledger_account_id, 'debit' => $amount, 'credit' => '0'], ['account_id' => (int) $mapping->revenue_account_id, 'debit' => '0', 'credit' => $amount]];
                $entry = $this->automation->draft($organization, $user, (int) $movement->company_id, 'bank_transaction', $transactionId, (string) $movement->transaction_date, (self::CLASSIFICATIONS[$classification]).' '.$movement->bank_name, 'BANCO-'.$movement->reference, $lines, 'bank_transaction.'.$classification);
                if ($entry === null) {
                    throw ValidationException::withMessages(['classification' => 'Abre un período contable que incluya la fecha del movimiento.']);
                }
                DB::table('bank_transactions')->where('id', $transactionId)->update(['classification' => $classification, 'reconciliation_status' => 'matched', 'matched_journal_entry_id' => $entry, 'matched_source_type' => 'journal_entries', 'matched_source_id' => $entry, 'matched_by' => $user->id, 'matched_at' => now(), 'updated_at' => now()]);
            } elseif ($classification === 'unknown') {
                DB::table('bank_transactions')->where('id', $transactionId)->update(['classification' => 'unknown', 'reconciliation_status' => 'unmatched', 'updated_at' => now()]);
                $key = 'unidentified_tx:'.$transactionId;
                if (! DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->exists()) {
                    DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $movement->company_id, 'type' => 'bank_reconciliation', 'severity' => 'medium', 'status' => 'open',
                        'title' => ($movement->flow === 'inflow' ? 'Depósito' : 'Débito').' sin identificar: '.$movement->reference, 'description' => $movement->description.' · Q '.number_format((int) $movement->amount_cents / 100, 2),
                        'blocks_period' => false, 'responsible_id' => $user->id, 'source_type' => 'bank_transactions', 'source_id' => $transactionId, 'automation_key' => $key, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            } else {
                if (! array_key_exists($classification, self::CLASSIFICATIONS)) {
                    throw ValidationException::withMessages(['classification' => 'Clasificación inválida.']);
                }
                DB::table('bank_transactions')->where('id', $transactionId)->update(['classification' => $classification, 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $user, 'bank_transaction.classified', 'bank_transactions', $transactionId, ['classification' => $classification], $office);
        });
    }

    /**
     * Transfer between two accounts of the same company and currency: two linked movements and one entry.
     */
    public function transfer(Organization $organization, User $user, int $companyId, int $fromId, int $toId, int $amountCents, string $date, string $reference): int
    {
        return DB::transaction(function () use ($organization, $user, $companyId, $fromId, $toId, $amountCents, $date, $reference): int {
            $this->accounting->lock($companyId);
            $accounts = DB::table('bank_accounts')->whereIn('id', [$fromId, $toId])->lockForUpdate()->get()->keyBy('id');
            $from = $accounts->get($fromId);
            $to = $accounts->get($toId);
            if ($from === null || $to === null || $fromId === $toId) {
                throw ValidationException::withMessages(['to_bank_account_id' => 'Elige dos cuentas distintas.']);
            }
            if ((int) $from->company_id !== $companyId || (int) $to->company_id !== $companyId) {
                throw ValidationException::withMessages(['to_bank_account_id' => 'Una transferencia interna solo mueve dinero entre cuentas de la misma empresa; entre empresas distintas se registra como operación contable propia.']);
            }
            if ($from->currency !== $to->currency) {
                throw ValidationException::withMessages(['to_bank_account_id' => 'Las cuentas tienen monedas distintas; se requiere tipo de cambio y no se convierte automáticamente.']);
            }
            if ($amountCents <= 0) {
                throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor que cero.']);
            }
            foreach ([$fromId, $toId] as $accountId) {
                if (DB::table('bank_transactions')->where('bank_account_id', $accountId)->where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'Ya existe un movimiento con esa referencia en una de las cuentas.']);
                }
            }
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $id = DB::table('bank_transfers')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'from_bank_account_id' => $fromId, 'to_bank_account_id' => $toId, 'amount_cents' => $amountCents, 'currency' => $from->currency, 'transfer_date' => $date, 'reference' => $reference, 'status' => 'executed', 'created_by' => $user->id, 'executed_by' => $user->id, 'executed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $amount = number_format($amountCents / 100, 2, '.', '');
            $entry = $this->automation->draft($organization, $user, $companyId, 'bank_transfer', $id, $date, 'Transferencia '.$from->bank_name.' → '.$to->bank_name, 'TRF-'.$reference, [
                ['account_id' => (int) $to->ledger_account_id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => (int) $from->ledger_account_id, 'debit' => '0', 'credit' => $amount],
            ], 'bank_transfer.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['transfer_date' => 'Abre un período contable que incluya la fecha de la transferencia.']);
            }
            foreach ([[$from, 'outflow', -$amountCents], [$to, 'inflow', $amountCents]] as [$account, $flow, $signed]) {
                $balance = (int) $account->bank_balance_cents + $signed;
                DB::table('bank_transactions')->insert(['public_id' => (string) Str::uuid(), 'bank_account_id' => $account->id, 'transaction_date' => $date, 'reference' => $reference, 'description' => 'Transferencia '.($flow === 'outflow' ? 'a '.$to->bank_name : 'desde '.$from->bank_name), 'flow' => $flow, 'amount_cents' => $amountCents, 'running_balance_cents' => $balance, 'reconciliation_status' => 'matched', 'source' => 'transfer', 'classification' => 'transfer', 'matched_source_type' => 'bank_transfers', 'matched_source_id' => $id, 'bank_transfer_id' => $id, 'matched_by' => $user->id, 'matched_at' => now(), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('bank_accounts')->where('id', $account->id)->update(['bank_balance_cents' => $balance, 'updated_at' => now()]);
            }
            DB::table('bank_transfers')->where('id', $id)->update(['journal_entry_id' => $entry]);
            $this->accounting->audit($organization, $user, 'transfer.executed', 'bank_transfers', $id, ['from' => $fromId, 'to' => $toId, 'amount_cents' => $amountCents], $office);

            return $id;
        });
    }

    /** Records a cash count against the ledger balance of the cash account; a difference opens an incident. */
    public function countCash(Organization $organization, User $user, int $companyId, int $ledgerAccountId, string $date, int $countedCents, ?string $notes): int
    {
        return DB::transaction(function () use ($organization, $user, $companyId, $ledgerAccountId, $date, $countedCents, $notes): int {
            if (! in_array($ledgerAccountId, array_column($this->cashAccounts($companyId), 'id'), true)) {
                throw ValidationException::withMessages(['ledger_account_id' => 'Elige una cuenta de caja de la empresa.']);
            }
            $system = $this->ledgerBalance($ledgerAccountId, $date);
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $id = DB::table('cash_counts')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'ledger_account_id' => $ledgerAccountId, 'counted_on' => $date, 'system_balance_cents' => $system, 'counted_cents' => $countedCents, 'difference_cents' => $countedCents - $system, 'notes' => $notes, 'counted_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($countedCents !== $system) {
                DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'type' => 'accounting', 'severity' => 'high', 'status' => 'open',
                    'title' => 'Diferencia en arqueo de caja', 'description' => 'Conteo Q '.number_format($countedCents / 100, 2).' vs sistema Q '.number_format($system / 100, 2).'. Investigar y, si se autoriza, registrar un ajuste formal.',
                    'blocks_period' => false, 'responsible_id' => $user->id, 'source_type' => 'cash_counts', 'source_id' => $id, 'automation_key' => 'cash_difference:'.$id, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $user, 'cash_count.completed', 'cash_counts', $id, ['system_cents' => $system, 'counted_cents' => $countedCents], $office);

            return $id;
        });
    }

    /**
     * Cash accounts: asset accounts that accept movements, are not linked to a bank account and are named as cash.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function cashAccounts(int $companyId): array
    {
        return array_values(DB::table('ledger_accounts')->where('company_id', $companyId)->where('type', 'asset')->where('is_active', true)->where('accepts_movements', true)->where('is_group', false)
            ->where('name', 'ilike', '%caja%')->whereNotIn('id', DB::table('bank_accounts')->where('company_id', $companyId)->select('ledger_account_id'))
            ->orderBy('code')->get(['id', 'code', 'name'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name])->all());
    }

    public function ledgerBalance(int $ledgerAccountId, string $cutoff): int
    {
        return (int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.ledger_account_id', $ledgerAccountId)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff)
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) AS BIGINT) as balance')->value('balance');
    }

    private function applyMatch(Organization $organization, ?User $user, int $transactionId, string $type, int $sourceId): void
    {
        DB::table('bank_transactions')->where('id', $transactionId)->update([
            'reconciliation_status' => 'matched', 'classification' => $type === 'treasury_receipts' ? 'receipt' : 'payment',
            'matched_source_type' => $type, 'matched_source_id' => $sourceId, 'matched_by' => $user?->id, 'matched_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', 'unidentified_tx:'.$transactionId)->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->update(['status' => 'resolved', 'resolution' => 'Movimiento identificado y conciliado.', 'resolved_by' => $user?->id, 'resolved_at' => now(), 'updated_at' => now()]);
        if ($user !== null) {
            $this->accounting->audit($organization, $user, 'bank_transaction.matched', 'bank_transactions', $transactionId, ['source' => $type, 'id' => $sourceId]);
        } else {
            DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'user_id' => null, 'action' => 'bank_transaction.auto_matched', 'auditable_type' => 'bank_transactions', 'auditable_id' => $transactionId, 'new_values' => json_encode(['source' => $type, 'id' => $sourceId]), 'occurred_at' => now(), 'created_at' => now()]);
        }
    }

    private function sameReference(stdClass $movement, string $reference): bool
    {
        $reference = mb_strtolower(trim($reference));

        return $reference !== '' && (mb_strtolower((string) $movement->reference) === $reference || str_contains(mb_strtolower((string) $movement->description), $reference));
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== null && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
