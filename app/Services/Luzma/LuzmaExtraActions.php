<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Accounting\FixedAssetService;
use App\Services\AccountingService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\ClientPortal\ClientRequestService;
use App\Services\PurchaseDocumentDraftService;
use App\Services\Receivables\CollectionReminderService;
use App\Services\Receivables\ReceivableService;
use App\Services\SalesInvoiceDraftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The bookkeeping actions Luzma prepares from the user's words: a journal entry, the month's depreciation, a sales
 * invoice draft, a collection e-mail, a request of documents to the client and a purchase from a supplier invoice.
 * Each one only builds a preview; on confirmation the same services of the modules run, and what they create is a
 * draft that follows its normal review (a journal entry is never posted by Luzma).
 *
 * @phpstan-type Prepared array{payload: array<string, mixed>, preview: array<string, mixed>, company_id: int|null}|array{message: string}
 */
class LuzmaExtraActions
{
    public function __construct(
        private LuzmaContextService $contexts,
        private LuzmaDateParser $dates,
    ) {}

    /**
     * "Registra un asiento de Q 500 a la cuenta 5204 contra 1101", "reclasifica Q 300 de la 5201 a la 5204".
     *
     * @return Prepared
     */
    public function journalEntry(LuzmaContext $c, string $message): array
    {
        if ($c->companyId === null) {
            return ['message' => 'Abre la empresa del asiento; cada asiento es de una sola empresa.'];
        }
        $text = $this->contexts->normalize($message);
        if (preg_match('/\bdeprecia|\bamortiza/u', $text) === 1) {
            return $this->depreciation($c, $text);
        }
        $amount = self::amount($text);
        $reclassify = preg_match('/\breclasific/u', $text) === 1;
        preg_match_all('/\b(\d{4,8})\b/u', $text, $codes);
        $accounts = array_values(array_filter(array_map(fn (string $code): ?stdClass => $this->account((int) $c->companyId, $code), $codes[1])));
        if ($amount === null || count($accounts) < 2) {
            return ['message' => 'Para preparar el asiento necesito el monto y las dos cuentas por su código, por ejemplo: "registra un asiento de Q 500 a la cuenta 5204 contra la 1101" o "reclasifica Q 300 de la 5201 a la 5204".'];
        }
        // "de A a B" moves the amount out of A (credit) into B (debit); "a A contra B" charges A against B.
        [$debit, $credit] = $reclassify ? [$accounts[1], $accounts[0]] : [$accounts[0], $accounts[1]];
        $date = $this->dates->parse($text, $c->timezone)['at'] ?? CarbonImmutable::parse($c->today());
        $period = DB::table('accounting_periods')->where('company_id', $c->companyId)->where('starts_on', '<=', $date->toDateString())->where('ends_on', '>=', $date->toDateString())->first(['id', 'name']);
        if ($period === null) {
            return ['message' => 'No hay un período contable abierto para el '.$date->format('d/m/Y').'; ábrelo primero en Contabilidad.'];
        }
        $description = mb_substr(trim((string) preg_replace('/^(por favor\s+)?(registra|registrar|haz|hacer|crea|crear|prepara|preparar)\s+(un\s+)?(asiento|partida)\s*(de|por|para)?\s*/u', '', $message)), 0, 240) ?: 'Asiento preparado con Luzma';
        $money = number_format($amount / 100, 2, '.', '');

        return ['company_id' => $c->companyId, 'payload' => ['period_id' => (int) $period->id, 'entry_date' => $date->toDateString(), 'description' => $reclassify ? 'Reclasificación: '.$description : $description,
            'lines' => [['account_id' => (int) $debit->id, 'debit' => $money, 'credit' => '0'], ['account_id' => (int) $credit->id, 'debit' => '0', 'credit' => $money]]],
            'preview' => ['company' => $c->companyName, 'total' => LuzmaFinanceTools::money($amount), 'lines' => ['Debe '.$debit->code.' '.$debit->name.' · '.LuzmaFinanceTools::money($amount), 'Haber '.$credit->code.' '.$credit->name.' · '.LuzmaFinanceTools::money($amount)],
                'summary' => 'Prepararé un asiento en borrador del '.$date->format('d/m/Y').' ('.$period->name.') por '.LuzmaFinanceTools::money($amount).': cargo a '.$debit->code.' '.$debit->name.' y abono a '.$credit->code.' '.$credit->name.'. Queda en borrador para su revisión; no se contabiliza solo.']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    public function executeJournalEntry(LuzmaContext $c, int $companyId, array $payload): array
    {
        $accounting = app(AccountingService::class);
        $company = DB::table('companies')->where('id', $companyId)->first() ?? abort(404);
        $id = $accounting->createDraft($c->organization, $c->user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => (int) $payload['period_id'], 'entry_date' => (string) $payload['entry_date'],
            'description' => (string) $payload['description'], 'reference' => 'LUZMA', 'kind' => 'standard'], $accounting->lines($companyId, array_values((array) $payload['lines'])));

        return ['message' => 'Asiento creado en borrador. Revísalo y envíalo a revisión desde el diario.', 'href' => '/accounting/companies/'.$company->public_id.'?entry='.$id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    public function executeDepreciation(LuzmaContext $c, int $companyId, array $payload): array
    {
        $result = app(FixedAssetService::class)->depreciate($c->organization, $c->user, $companyId, Carbon::parse((string) $payload['month']));

        return ['message' => $result['charged'] === 0 ? 'No había activos por depreciar en ese mes (ya estaban cargados o sin cuota).' : 'Listo: '.$result['charged'].' asiento(s) de depreciación en borrador por '.LuzmaFinanceTools::money($result['amount_cents']).'.',
            'href' => '/accounting/companies/'.DB::table('companies')->where('id', $companyId)->value('public_id').'/fixed-assets'];
    }

    /**
     * "Factura a Cliente Alfa por Q 1,500 por servicios contables".
     *
     * @return Prepared
     */
    public function salesInvoice(LuzmaContext $c, string $message): array
    {
        if ($c->companyId === null) {
            return ['message' => 'Abre la empresa que factura; cada factura es de una sola empresa.'];
        }
        $text = $this->contexts->normalize($message);
        $customer = $this->named((int) $c->companyId, 'sales_customers', $text);
        $amount = self::amount($text);
        if ($customer === null || $amount === null) {
            return ['message' => 'Dime el cliente (como está registrado en Ventas) y el monto con IVA, por ejemplo: "factura a Cliente Alfa por Q 1,500 por servicios contables".'];
        }
        $concept = preg_match('/\bpor (?!q\s?\d)([a-z][a-z0-9 ,.]{3,120})$/u', $text, $found) === 1 ? trim($found[1]) : 'Servicios';
        $services = preg_match('/servicio|honorario|asesor|consult|mantenimiento/u', $concept) === 1;
        $issued = CarbonImmutable::parse($c->today());

        return ['company_id' => $c->companyId, 'payload' => ['customer_id' => (int) $customer->id, 'issued_on' => $issued->toDateString(), 'due_on' => $customer->payment_terms_days === null ? $issued->addDays(30)->toDateString() : null,
            'lines' => [['description' => mb_strtoupper(mb_substr($concept, 0, 1)).mb_substr($concept, 1), 'quantity' => '1', 'unit_price' => number_format($amount / 100, 2, '.', ''), 'tax_treatment' => $services ? 'services' : 'goods']]],
            'preview' => ['company' => $c->companyName, 'total' => LuzmaFinanceTools::money($amount), 'lines' => ['1 × '.$concept.' · '.LuzmaFinanceTools::money($amount).' (IVA incluido)'],
                'summary' => 'Prepararé una factura de venta en borrador para '.$customer->name.' por '.LuzmaFinanceTools::money($amount).' con fecha '.$issued->format('d/m/Y').'. No se emite ni se certifica hasta que la revises.']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    public function executeSalesInvoice(LuzmaContext $c, int $companyId, array $payload): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first() ?? abort(404);
        /** @var array{request_id: string, customer_id: int, issued_on: string, due_on?: string|null, lines: list<array{description: string, quantity: string, unit_price: string, tax_treatment?: string|null}>} $data */
        $data = ['request_id' => (string) Str::uuid(), ...$payload];
        $result = app(SalesInvoiceDraftService::class)->create($c->organization, $c->user, $company, $data, ['initiated_via' => 'luzma']);

        return ['message' => 'Factura creada en borrador. Revísala y emítela desde Ventas.', 'href' => '/accounting/companies/'.$company->public_id.'/sales?invoice='.$result['id']];
    }

    /**
     * "Envíale un recordatorio de cobro a Cliente Alfa".
     *
     * @return Prepared
     */
    public function collectionReminder(LuzmaContext $c, string $message): array
    {
        if ($c->companyId === null) {
            return ['message' => 'Abre la empresa del cliente para preparar el recordatorio.'];
        }
        $customer = $this->named((int) $c->companyId, 'sales_customers', $this->contexts->normalize($message));
        if ($customer === null) {
            return ['message' => '¿A qué cliente le envío el recordatorio? Escríbelo como está registrado en Ventas.'];
        }
        $documents = collect(app(ReceivableService::class)->documents([(int) $c->companyId], ['customer_id' => (int) $customer->id]));
        if ($documents->isEmpty()) {
            return ['message' => $customer->name.' no tiene saldo pendiente; no hace falta recordarle nada.'];
        }
        $kind = $documents->contains('overdue', true) ? 'overdue' : 'upcoming';
        $template = collect(app(CollectionReminderService::class)->templates($c->organization))->firstWhere('kind', $kind);
        $email = (string) ($customer->email ?? '');
        $text = 'Estimado(a) '.$customer->name.': le recordamos que tiene '.$documents->count().' documento(s) pendiente(s) con '.$c->companyName.' por '.LuzmaFinanceTools::money((int) $documents->sum('outstanding_cents'))
            .($kind === 'overdue' ? ', de los cuales '.LuzmaFinanceTools::money((int) $documents->where('overdue', true)->sum('outstanding_cents')).' están vencidos' : '').'. Le agradeceremos confirmar su fecha de pago.';
        if ($template === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['message' => $customer->name.' no tiene un correo válido en Ventas, así que no puedo enviarlo yo. Este es un mensaje listo para copiar y mandar por WhatsApp o correo:'."\n\n".$text];
        }

        return ['company_id' => $c->companyId, 'payload' => ['customer_id' => (int) $customer->id, 'template_id' => $template['id']],
            'preview' => ['company' => $c->companyName, 'title' => $template['subject'], 'lines' => $documents->take(10)->map(fn (array $row): string => $row['document'].' · vence '.$row['due_date'].' · '.LuzmaFinanceTools::money($row['outstanding_cents']))->values()->all(),
                'summary' => 'Enviaré por correo a '.$email.' el recordatorio "'.$template['subject'].'" con los '.$documents->count().' documento(s) pendiente(s) de '.$customer->name.'. Si prefieres, copia este texto: '.$text]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    public function executeCollectionReminder(LuzmaContext $c, int $companyId, array $payload): array
    {
        app(CollectionReminderService::class)->send($c->organization, $c->user, $companyId, (int) $payload['customer_id'], (int) $payload['template_id']);

        return ['message' => 'Recordatorio de cobro enviado; queda en el historial de cobranza.', 'href' => '/receivables/companies/'.$companyId];
    }

    /**
     * "Pídele al cliente los estados de cuenta bancarios de octubre".
     *
     * @return Prepared
     */
    public function documentRequest(LuzmaContext $c, string $message): array
    {
        if ($c->companyId === null) {
            return ['message' => 'Abre la empresa del cliente para pedirle los documentos por el portal.'];
        }
        if (app(ClientAccessService::class)->contacts((int) $c->companyId) === []) {
            return ['message' => $c->companyName.' no tiene contactos activos en el portal del cliente. Invita primero al contacto desde Portal del cliente y luego te preparo la solicitud.'];
        }
        $text = $this->contexts->normalize($message);
        $what = trim((string) preg_replace('/^(por favor\s+)?(pidele|pedile|solicita|solicitale|pide|pedir)\s+(al cliente|a la empresa|al contribuyente)?\s*/u', '', $text));
        if (mb_strlen($what) < 4) {
            return ['message' => '¿Qué documentos le pido al cliente? Por ejemplo: "pídele al cliente los estados de cuenta bancarios de octubre".'];
        }
        $period = LuzmaIntentService::namesPeriod($text) ? LuzmaPeriod::fromText($text, $c->timezone)->label : null;
        $due = $this->dates->parse($text, $c->timezone)['at'] ?? CarbonImmutable::parse($c->today())->addDays(5);
        $title = mb_strtoupper(mb_substr($what, 0, 1)).mb_substr($what, 1, 170);

        return ['company_id' => $c->companyId, 'payload' => ['company_id' => $c->companyId, 'type' => 'document', 'title' => $title, 'period_label' => $period, 'due_date' => $due->toDateString()],
            'preview' => ['company' => $c->companyName, 'title' => $title, 'due' => $due->format('d/m/Y'),
                'summary' => 'Enviaré a '.$c->companyName.' por el portal la solicitud "'.$title.'"'.($period === null ? '' : ' ('.$period.')').' con fecha límite '.$due->format('d/m/Y').'.']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    public function executeDocumentRequest(LuzmaContext $c, array $payload): array
    {
        /** @var array{company_id: int, type: string, title: string, period_label?: string|null, due_date?: string|null} $data */
        $data = $payload;
        app(ClientRequestService::class)->create($c->organization, $c->user, $data);

        return ['message' => 'Solicitud enviada al cliente por el portal.', 'href' => '/work/client-portal'];
    }

    /**
     * A purchase draft from an invoice the user uploaded to Luzma (already read and matched by the invoice reader).
     *
     * @param  array<string, mixed>  $invoice
     * @return Prepared
     */
    public function purchaseFromInvoice(LuzmaContext $c, array $invoice): array
    {
        $supplier = (array) ($invoice['supplier'] ?? []);
        $lines = array_values((array) ($invoice['lines'] ?? []));
        if ($c->companyId === null || $lines === [] || ($invoice['document_date'] ?? null) === null) {
            return ['message' => 'La factura no tiene datos suficientes para registrarla; complétala desde Compras.'];
        }
        $expense = (int) (DB::table('company_accounting_mappings')->where('company_id', $c->companyId)->value('default_expense_account_id') ?? 0);
        if ($expense === 0) {
            return ['message' => 'La empresa no tiene una cuenta de gasto por defecto; configúrala en Contabilidad para registrar compras.'];
        }

        return ['company_id' => $c->companyId, 'payload' => ['supplier_id' => $supplier['id'] ?? null, 'supplier_name' => $supplier['name'] ?? null, 'supplier_tax_id' => $supplier['tax_id'] ?? null,
            'document_date' => $invoice['document_date'], 'due_on' => CarbonImmutable::parse((string) $invoice['document_date'])->addDays((int) (DB::table('purchase_suppliers')->where('id', $supplier['id'] ?? 0)->value('payment_terms_days') ?? 0))->toDateString(),
            'currency' => $invoice['currency'] ?? 'GTQ', 'fiscal_document_type' => $invoice['fiscal_document_type'] ?? null,
            'fiscal_series' => $invoice['fiscal_series'] ?? null, 'fiscal_number' => $invoice['fiscal_number'] ?? null, 'fiscal_authorization' => $invoice['fiscal_authorization'] ?? null,
            'lines' => array_map(fn ($line): array => [...(array) $line, 'account_id' => $expense], $lines)],
            'preview' => ['company' => $c->companyName, 'total' => $invoice['total'] === null ? null : 'Q '.$invoice['total'],
                'lines' => array_map(fn ($line): string => ((array) $line)['quantity'].' × '.((array) $line)['description'].' · Q '.((array) $line)['unit_price'], $lines),
                'summary' => 'Registraré la compra en borrador de '.($supplier['name'] ?? 'el proveedor').($supplier['id'] === null ? ' (lo daré de alta como proveedor nuevo)' : '').' del '.$invoice['document_date']
                    .($invoice['total'] === null ? '' : ' por Q '.$invoice['total']).'. Queda en borrador para revisar la cuenta de gasto y aprobarla.']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: string, href: string|null}
     */
    public function executePurchase(LuzmaContext $c, int $companyId, array $payload): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first() ?? abort(404);
        $supplierId = $payload['supplier_id'] ?? null;
        if ($supplierId === null) {
            if (blank($payload['supplier_name'] ?? null)) {
                throw ValidationException::withMessages(['action' => 'La factura no trae el nombre del proveedor; regístralo desde Compras.']);
            }
            $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $companyId, 'name' => mb_substr((string) $payload['supplier_name'], 0, 150),
                'tax_id' => $payload['supplier_tax_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
        /** @var array{request_id: string, supplier_id: int, document_type: string, document_date: string, due_on?: string|null, currency?: string|null, lines: list<array{account_id: int, description: string, quantity: string, unit_price: string, tax_treatment?: string|null}>, fiscal_document_type?: string|null, fiscal_series?: string|null, fiscal_number?: string|null, fiscal_authorization?: string|null} $data */
        $data = ['request_id' => (string) Str::uuid(), 'supplier_id' => (int) $supplierId, 'document_type' => 'purchase', ...array_intersect_key($payload, array_flip(['document_date', 'due_on', 'currency', 'lines', 'fiscal_document_type', 'fiscal_series', 'fiscal_number', 'fiscal_authorization']))];
        $result = app(PurchaseDocumentDraftService::class)->create($c->organization, $c->user, $company, $data, ['initiated_via' => 'luzma']);

        return ['message' => 'Compra registrada en borrador. Revisa la cuenta de gasto y apruébala desde Compras.', 'href' => '/accounting/companies/'.$company->public_id.'/purchases?document='.$result['id']];
    }

    /** @return Prepared */
    private function depreciation(LuzmaContext $c, string $text): array
    {
        $month = LuzmaPeriod::fromText($text, $c->timezone);
        $assets = DB::table('fixed_assets')->where('company_id', $c->companyId)->where('status', 'active')->count();
        if ($assets === 0) {
            return ['message' => $c->companyName.' no tiene activos fijos activos para depreciar.'];
        }

        return ['company_id' => $c->companyId, 'payload' => ['month' => $month->from],
            'preview' => ['company' => $c->companyName, 'title' => 'Depreciación de '.$month->label,
                'summary' => 'Prepararé los asientos de depreciación y amortización de '.$month->label.' para los '.$assets.' activo(s) de '.$c->companyName.' que no tengan cargado ese mes. Quedan en borrador.']];
    }

    /** Amount in cents written as "Q 1,500", "1500.50", "1.5 mil" or "Q2 mil". */
    private static function amount(string $text): ?int
    {
        if (preg_match('/q\s?([\d,]+(?:\.\d{1,2})?)\s*(mil)?\b|\b([\d,]+(?:\.\d{1,2})?)\s*(mil|quetzales)\b/u', $text, $found) !== 1) {
            return null;
        }
        $value = (float) str_replace(',', '', ($found[1] ?? '') !== '' ? $found[1] : ($found[3] ?? '0'));
        $thousands = ($found[2] ?? '') === 'mil' || ($found[4] ?? '') === 'mil';
        $cents = (int) round($value * ($thousands ? 1000 : 1) * 100);

        return $cents > 0 ? $cents : null;
    }

    private function account(int $companyId, string $code): ?stdClass
    {
        return DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->where('is_active', true)->where('accepts_movements', true)->first(['id', 'code', 'name']);
    }

    /** The customer or supplier whose registered name appears in the words (the longest one wins). */
    private function named(int $companyId, string $table, string $text): ?stdClass
    {
        return DB::table($table)->where('company_id', $companyId)->get()->sortByDesc(fn (stdClass $row): int => mb_strlen((string) $row->name))
            ->first(fn (stdClass $row): bool => str_contains($text, $this->contexts->normalize((string) $row->name)));
    }
}
