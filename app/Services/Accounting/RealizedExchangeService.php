<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Realized exchange difference: a document in dollars or euros is settled at the rate it was recorded with, while the
 * bank moves at the rate of the day it is collected or paid. The difference is a gain or a loss of that day
 * (4303 / 5303); the receivable or payable is cleared at its book value, so the sub-ledgers keep matching.
 */
class RealizedExchangeService
{
    public function __construct(private AccountingAutomationService $automation) {}

    /**
     * What a foreign amount settles of a document at its own rate, what it is worth at the day's rate, and the difference.
     *
     * @return array{book_cents: int, cash_cents: int, difference_cents: int}
     */
    public function settle(stdClass $document, int $foreignCents, string $rate): array
    {
        $foreignTotal = (int) $document->foreign_total_cents;
        if (($document->currency ?? 'GTQ') === 'GTQ' || $foreignTotal <= 0) {
            throw ValidationException::withMessages(['foreign_amount' => 'El documento está en quetzales: registra el monto en quetzales.']);
        }
        if ($foreignCents <= 0 || (float) $rate <= 0) {
            throw ValidationException::withMessages(['foreign_amount' => 'Indica el monto en '.$document->currency.' y el tipo de cambio del día.']);
        }
        // In the same proportion as the document: the whole foreign amount settles the whole book value.
        $book = intdiv($foreignCents * (int) $document->total_cents + intdiv($foreignTotal, 2), $foreignTotal);
        $cash = (int) round($foreignCents * (float) $rate);

        return ['book_cents' => $book, 'cash_cents' => $cash, 'difference_cents' => $cash - $book];
    }

    /**
     * Drafts the receipt or payment with its realized difference. For a receipt the bank gets what the dollars are worth
     * today and the customer is cleared at book value; for a payment the supplier is cleared at book value and the bank
     * pays what the dollars cost today.
     *
     * @param  array{book_cents: int, cash_cents: int, difference_cents: int}  $settlement
     * @param  array<string, mixed>  $dimensions
     */
    public function draft(Organization $organization, User $user, int $companyId, string $sourceType, int $sourceId, string $date, string $description, string $reference, array $settlement, array $dimensions): ?int
    {
        $event = $sourceType.'.origin';
        [$debit, $credit] = $this->automation->ruleLines($companyId, $event, $settlement['book_cents'], ['description' => $description, ...$dimensions]);
        $difference = $settlement['difference_cents'];
        $lines = [$debit, $credit];
        $cash = $this->decimal($settlement['cash_cents']);
        if ($sourceType === 'sales_receipt') {
            $lines[0]['debit'] = $cash;
        } else {
            $lines[1]['credit'] = $cash;
        }
        if ($difference !== 0) {
            // Collecting more quetzales than the book value, or paying fewer, is a gain.
            $gain = $sourceType === 'sales_receipt' ? $difference > 0 : $difference < 0;
            $account = $this->account($companyId, $gain ? ExchangeRevaluationService::GAIN_ACCOUNT : ExchangeRevaluationService::LOSS_ACCOUNT);
            $amount = $this->decimal(abs($difference));
            $lines[] = ['account_id' => $account, 'debit' => $gain ? '0' : $amount, 'credit' => $gain ? $amount : '0', 'description' => 'Diferencial cambiario realizado '.($gain ? 'a favor' : 'en contra')];
        }

        return $this->automation->draft($organization, $user, $companyId, $sourceType, $sourceId, $date, $description, $reference, $lines, $event);
    }

    private function account(int $companyId, string $code): int
    {
        $id = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->where('is_active', true)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['foreign_amount' => 'Falta la cuenta '.$code.' de diferencial cambiario en el catálogo de la empresa.']);
        }

        return (int) $id;
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
