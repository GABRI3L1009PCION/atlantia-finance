<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Quotes, sales orders and purchase orders. They move no accounting: a quote is sent and accepted (or becomes a sales
 * order), and an accepted quote or a sales order becomes a sales invoice draft with the same lines; a purchase order
 * becomes a purchase draft when the supplier's invoice arrives. Each document converts once and keeps the link.
 *
 * @phpstan-type CommercialLine array{description: string, quantity: int, unit_price: string, tax_treatment: string, account_id: int|null}
 */
class CommercialDocumentService
{
    public const KINDS = ['quote' => 'Cotización', 'sales_order' => 'Orden de venta', 'purchase_order' => 'Orden de compra'];

    public const STATUSES = ['draft' => 'Borrador', 'sent' => 'Enviada', 'accepted' => 'Aceptada', 'rejected' => 'Rechazada', 'converted' => 'Convertida', 'cancelled' => 'Anulada'];

    /** Status changes each status allows by hand (conversion has its own action). */
    private const TRANSITIONS = ['draft' => ['sent', 'cancelled'], 'sent' => ['accepted', 'rejected', 'cancelled'], 'accepted' => ['cancelled'], 'rejected' => [], 'converted' => [], 'cancelled' => []];

    public function __construct(
        private AccountingService $accounting,
        private SalesInvoiceDraftService $invoices,
        private PurchaseDocumentDraftService $purchases,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(int $companyId): array
    {
        return array_values(DB::table('commercial_documents')->where('company_id', $companyId)->orderByDesc('issued_on')->orderByDesc('id')->limit(300)->get()
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'kind' => (string) $row->kind,
                'code' => $this->code((string) $row->kind, (int) $row->number), 'counterparty' => (string) $row->counterparty_name, 'issued_on' => (string) $row->issued_on,
                'valid_until' => $row->valid_until === null ? null : (string) $row->valid_until, 'status' => (string) $row->status, 'total_cents' => (int) $row->total_cents,
                'lines' => json_decode((string) $row->lines, true), 'notes' => $row->notes === null ? null : (string) $row->notes,
                'converted_type' => $row->converted_type === null ? null : (string) $row->converted_type, 'converted_public_id' => $this->convertedPublicId($row)])->all());
    }

    /**
     * @param  array{kind: string, counterparty_id: int, issued_on: string, valid_until: string|null, notes: string|null, lines: list<array{description: string, quantity: int|string, unit_price: string, tax_treatment?: string|null, account_id?: int|string|null}>}  $data
     */
    public function create(Organization $organization, User $user, int $companyId, array $data): int
    {
        $isPurchase = $data['kind'] === 'purchase_order';
        $counterparty = DB::table($isPurchase ? 'purchase_suppliers' : 'sales_customers')->where('company_id', $companyId)->where('id', $data['counterparty_id'])->first(['id', 'name']);
        if ($counterparty === null) {
            throw ValidationException::withMessages(['counterparty_id' => $isPurchase ? 'Elige un proveedor de esta empresa.' : 'Elige un cliente de esta empresa.']);
        }
        if ($data['valid_until'] !== null && $data['valid_until'] < $data['issued_on']) {
            throw ValidationException::withMessages(['valid_until' => 'La vigencia debe ser posterior a la fecha del documento.']);
        }
        $lines = $this->lines($companyId, $data['lines'], $isPurchase);
        $total = array_sum(array_map(fn (array $line): int => $this->accounting->cents($line['unit_price']) * $line['quantity'], $lines));

        return DB::transaction(function () use ($organization, $user, $companyId, $data, $isPurchase, $counterparty, $lines, $total): int {
            $this->accounting->lock($companyId);
            $number = (int) DB::table('commercial_documents')->where('company_id', $companyId)->where('kind', $data['kind'])->max('number') + 1;
            $id = DB::table('commercial_documents')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'kind' => $data['kind'], 'number' => $number,
                'sales_customer_id' => $isPurchase ? null : $counterparty->id, 'purchase_supplier_id' => $isPurchase ? $counterparty->id : null,
                'counterparty_name' => (string) $counterparty->name, 'issued_on' => $data['issued_on'], 'valid_until' => $data['valid_until'], 'status' => 'draft',
                'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total_cents' => $total, 'notes' => $data['notes'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $user, 'commercial_document.created', 'commercial_documents', $id, ['kind' => $data['kind'], 'total_cents' => $total]);

            return $id;
        });
    }

    public function transition(Organization $organization, User $user, int $companyId, string $publicId, string $status): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $publicId, $status): void {
            $document = $this->document($companyId, $publicId);
            if (! in_array($status, self::TRANSITIONS[$document->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Un documento '.mb_strtolower(self::STATUSES[$document->status] ?? $document->status).' no puede pasar a '.mb_strtolower(self::STATUSES[$status] ?? $status).'.']);
            }
            DB::table('commercial_documents')->where('id', $document->id)->update(['status' => $status, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'commercial_document.'.$status, 'commercial_documents', (int) $document->id, ['from' => $document->status]);
        });
    }

    /**
     * Converts the document: a quote into a sales order or an invoice, a sales order into an invoice, a purchase order
     * into a purchase. Returns the public id of what it created.
     *
     * @param  array{target: string, document_date: string, due_on: string|null, fiscal_series?: string|null, fiscal_number?: string|null, fiscal_authorization?: string|null}  $data
     */
    public function convert(Organization $organization, User $user, stdClass $company, string $publicId, array $data): string
    {
        return DB::transaction(function () use ($organization, $user, $company, $publicId, $data): string {
            $document = $this->document((int) $company->id, $publicId);
            if (in_array($document->status, ['converted', 'cancelled', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Este documento ya no se puede convertir.']);
            }
            $allowed = match ($document->kind) {
                'quote' => ['sales_order', 'sales_invoice'],
                'sales_order' => ['sales_invoice'],
                default => ['purchase_document'],
            };
            if (! in_array($data['target'], $allowed, true)) {
                throw ValidationException::withMessages(['target' => 'Ese documento no se convierte en '.$data['target'].'.']);
            }
            $lines = json_decode((string) $document->lines, true);
            $requestId = (string) Str::uuid();
            if ($data['target'] === 'sales_order') {
                $id = $this->create($organization, $user, (int) $company->id, ['kind' => 'sales_order', 'counterparty_id' => (int) $document->sales_customer_id,
                    'issued_on' => $data['document_date'], 'valid_until' => null, 'notes' => $document->notes, 'lines' => $lines]);
                DB::table('commercial_documents')->where('id', $id)->update(['origin_id' => $document->id, 'status' => 'accepted']);
                $requestId = (string) DB::table('commercial_documents')->where('id', $id)->value('public_id');
                $convertedId = $id;
            } elseif ($data['target'] === 'sales_invoice') {
                $convertedId = $this->invoices->create($organization, $user, $company, [
                    'request_id' => $requestId, 'customer_id' => (int) $document->sales_customer_id, 'issued_on' => $data['document_date'], 'due_on' => $data['due_on'],
                    'lines' => array_values(array_map(fn (array $line): array => ['description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                        'tax_treatment' => $line['tax_treatment']], $lines)),
                ], ['source' => 'commercial_document', 'document' => $document->public_id])['id'];
            } else {
                $convertedId = $this->purchases->create($organization, $user, $company, [
                    'request_id' => $requestId, 'supplier_id' => (int) $document->purchase_supplier_id, 'document_type' => 'purchase', 'document_date' => $data['document_date'],
                    'due_on' => $data['due_on'], 'external_reference' => $this->code('purchase_order', (int) $document->number),
                    'lines' => array_values(array_map(fn (array $line): array => ['account_id' => (int) $line['account_id'], 'description' => $line['description'], 'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'], 'tax_treatment' => $line['tax_treatment']], $lines)),
                    'fiscal_document_type' => 'FACT', 'fiscal_series' => $data['fiscal_series'] ?? null, 'fiscal_number' => $data['fiscal_number'] ?? null,
                    'fiscal_authorization' => $data['fiscal_authorization'] ?? null,
                ], ['source' => 'commercial_document', 'document' => $document->public_id])['id'];
            }
            DB::table('commercial_documents')->where('id', $document->id)->update(['status' => 'converted', 'converted_type' => $data['target'], 'converted_id' => $convertedId, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'commercial_document.converted', 'commercial_documents', (int) $document->id, ['target' => $data['target'], 'id' => $convertedId]);

            return $requestId;
        });
    }

    public function code(string $kind, int $number): string
    {
        return ['quote' => 'COT', 'sales_order' => 'OV', 'purchase_order' => 'OC'][$kind].'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    private function document(int $companyId, string $publicId): stdClass
    {
        return DB::table('commercial_documents')->where('company_id', $companyId)->where('public_id', $publicId)->lockForUpdate()->first() ?? abort(404);
    }

    private function convertedPublicId(stdClass $row): ?string
    {
        $table = match ($row->converted_type) {
            'sales_invoice' => 'sales_invoices',
            'purchase_document' => 'purchase_documents',
            'sales_order' => 'commercial_documents',
            default => null,
        };

        return $table === null || $row->converted_id === null ? null : (DB::table($table)->where('id', $row->converted_id)->value('public_id') ?: null);
    }

    /**
     * @param  list<array{description: string, quantity: int|string, unit_price: string, tax_treatment?: string|null, account_id?: int|string|null}>  $lines
     * @return list<CommercialLine>
     */
    private function lines(int $companyId, array $lines, bool $isPurchase): array
    {
        $accounts = $isPurchase ? DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('type', ['asset', 'expense'])->pluck('id')->map(fn ($id): int => (int) $id)->all() : [];
        $clean = [];
        foreach ($lines as $index => $line) {
            $price = trim((string) $line['unit_price']);
            if (trim($line['description']) === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $price) || $this->accounting->cents($price) <= 0 || (int) $line['quantity'] < 1) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Cada línea necesita descripción, cantidad y un precio mayor que cero.']);
            }
            $account = $isPurchase ? (int) ($line['account_id'] ?? 0) : null;
            if ($isPurchase && ! in_array($account, $accounts, true)) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Cada línea de la orden de compra necesita una cuenta de gasto o activo de esta empresa.']);
            }
            $treatment = (string) ($line['tax_treatment'] ?? 'goods');
            $clean[] = ['description' => mb_substr(trim($line['description']), 0, 250), 'quantity' => (int) $line['quantity'], 'unit_price' => $price,
                'tax_treatment' => in_array($treatment, ['goods', 'services', 'exempt'], true) ? $treatment : 'goods', 'account_id' => $account];
        }
        if ($clean === []) {
            throw ValidationException::withMessages(['lines' => 'Agrega al menos una línea.']);
        }

        return $clean;
    }
}
