<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use Illuminate\Support\Facades\DB;

/**
 * Day-to-day help of Luzma that reads but changes nothing: which bank movements match which open invoices, and the PDF
 * and Excel files of the reports for the period asked.
 *
 * @phpstan-type ToolResult array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string, download?: bool}>}
 */
class LuzmaOperationsTools
{
    private const EXPORTS = ['income' => 'Estado de resultados', 'balance' => 'Balance general', 'trial' => 'Balanza de comprobación', 'ledger' => 'Libro mayor', 'journal' => 'Libro diario'];

    public function __construct(private ReceivableService $receivables, private PayableService $payables) {}

    /**
     * Pending bank movements with the open invoice of the same amount: deposits against what customers owe,
     * withdrawals against what is owed to suppliers.
     *
     * @return ToolResult
     */
    public function bankMatches(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $movements = DB::table('bank_transactions as t')->join('bank_accounts as b', 'b.id', '=', 't.bank_account_id')->where('b.company_id', $id)->where('t.reconciliation_status', 'pending')
            ->orderByDesc('t.transaction_date')->limit(200)->get(['t.transaction_date', 't.description', 't.reference', 't.flow', 't.amount_cents', 'b.bank_name']);
        $owed = collect($this->receivables->documents([$id]))->groupBy('outstanding_cents');
        $owing = collect($this->payables->documents([$id]))->groupBy('outstanding_cents');
        $matches = [];
        foreach ($movements as $movement) {
            $amount = abs((int) $movement->amount_cents);
            $candidates = $movement->flow === 'inflow'
                ? $owed->get($amount, collect())->map(fn (array $row): string => 'Factura '.$row['document'].' de '.$row['customer'])
                : $owing->get($amount, collect())->map(fn (array $row): string => 'Compra '.$row['document'].' de '.$row['supplier']);
            if ($candidates->isEmpty()) {
                continue;
            }
            $matches[] = [$movement->transaction_date, $movement->bank_name.' · '.($movement->description ?: $movement->reference ?: 'sin descripción'), LuzmaFinanceTools::money($amount),
                $candidates->first().($candidates->count() > 1 ? ' (y '.($candidates->count() - 1).' más del mismo monto)' : '')];
        }
        $facts = [$c->companyName.': '.$movements->count().' movimiento(s) bancario(s) sin conciliar; '.count($matches).' coinciden en monto con una factura abierta.'];
        foreach (array_slice($matches, 0, 8) as [$date, $movement, $amount, $document]) {
            $facts[] = 'SUGERENCIA: el movimiento del '.$date.' ('.$movement.', '.$amount.') podría corresponder a '.$document.'.';
        }
        $facts[] = 'Coincidir en monto no confirma el pago: revisa la referencia antes de aplicarlo en la conciliación.';

        return ['facts' => $facts, 'data' => ['pending' => $movements->count(), 'suggested' => count($matches),
            'table' => LuzmaFinanceTools::table('Posibles coincidencias', ['Fecha', 'Movimiento', 'Monto', 'Podría ser'], array_slice($matches, 0, 20))],
            'references' => [['label' => 'Ir a la conciliación bancaria', 'href' => '/accounting/companies/'.DB::table('companies')->where('id', $id)->value('public_id').'/banks']]];
    }

    /**
     * Download links of the reports for the period of the question.
     *
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function exports(LuzmaContext $c, array $a): array
    {
        $uuid = (string) DB::table('companies')->where('id', $c->companyId)->value('public_id');
        $text = LuzmaContextService::fold((string) ($a['period'] ?? ''));
        $period = LuzmaPeriod::fromText((string) ($a['period'] ?? ''), $c->timezone);
        $format = str_contains($text, 'excel') || str_contains($text, 'xlsx') ? 'xlsx' : (str_contains($text, 'pdf') ? 'pdf' : null);
        $kinds = array_keys(array_filter(['income' => '/resultado/', 'balance' => '/balance general|situacion/', 'trial' => '/balanza|comprobacion/', 'ledger' => '/mayor/', 'journal' => '/diario/'],
            fn (string $pattern): bool => preg_match($pattern, $text) === 1));
        $kinds = $kinds === [] ? array_keys(self::EXPORTS) : $kinds;
        $references = [];
        foreach ($kinds as $kind) {
            foreach ($format === null ? ['pdf', 'xlsx'] : [$format] as $type) {
                $references[] = ['label' => self::EXPORTS[$kind].' · '.($type === 'pdf' ? 'PDF' : 'Excel'), 'download' => true,
                    'href' => '/accounting/companies/'.$uuid.'/export?'.http_build_query(['kind' => $kind, 'format' => $type, 'from' => $period->from, 'to' => $period->to])];
            }
        }

        return ['facts' => [$c->companyName.': listos para descargar los reportes de '.$period->label.' ('.implode(', ', array_map(fn (string $kind): string => mb_strtolower(self::EXPORTS[$kind]), $kinds)).'). Usa los enlaces de abajo.'],
            'data' => ['period' => $period->current(), 'files' => count($references)], 'references' => $references];
    }
}
