<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Samples over a real universe of the company (purchases, sales, posted journal entries, bank movements, executed
 * treasury payments). Candidates are generated with the chosen method, reviewed by the auditor and, once confirmed,
 * frozen with a snapshot and hash of every element: later transactions never change a confirmed sample.
 */
class AuditSampleService
{
    public const UNIVERSES = ['purchases' => 'Compras', 'sales' => 'Ventas', 'journal_entries' => 'Asientos contabilizados', 'bank_transactions' => 'Movimientos bancarios', 'treasury_payments' => 'Pagos ejecutados'];

    public const METHODS = [
        'manual' => 'Selección manual', 'random' => 'Aleatoria', 'monetary_threshold' => 'Umbral monetario', 'materiality' => 'Materialidad del plan', 'criteria_based' => 'Por criterios',
        'date_range' => 'Rango de fechas', 'anomaly_candidates' => 'Candidatos inusuales', 'full_population' => 'Población completa',
    ];

    public const RESULTS = ['pending' => 'Pendiente', 'pass' => 'Conforme', 'exception' => 'Excepción', 'pending_evidence' => 'Espera evidencia', 'not_applicable' => 'No aplica'];

    private const MAX_ITEMS = 500;

    public function __construct(private AccountingService $accounting, private AuditEngagementService $engagements) {}

    /**
     * Population and candidates of a universe with a method; nothing is saved.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{population_count: int, population_cents: int, items: list<array{source_type: string, source_id: int, reference: string, item_date: string|null, amount_cents: int|null, snapshot: array<string, mixed>}>, note: string|null}
     */
    public function candidates(stdClass $engagement, string $universe, string $from, string $to, string $method, array $criteria): array
    {
        abort_unless(isset(self::UNIVERSES[$universe], self::METHODS[$method]), 422);
        if ($from < (string) $engagement->period_start || $to > (string) $engagement->period_end || $from > $to) {
            throw ValidationException::withMessages(['universe_from' => 'El universo debe estar dentro del período auditado.']);
        }
        $base = $this->universe((int) $engagement->company_id, $universe, $from, $to);
        $population = (clone $base)->count();
        $populationCents = (int) (clone $base)->sum('amount_cents');
        $note = null;
        $query = clone $base;
        switch ($method) {
            case 'manual':
                $ids = array_values(array_filter(array_map('intval', (array) ($criteria['ids'] ?? []))));
                $query->whereIn('source_id', $ids === [] ? [-1] : $ids);
                break;
            case 'monetary_threshold':
                $threshold = (int) ($criteria['threshold_cents'] ?? 0);
                if ($threshold <= 0) {
                    throw ValidationException::withMessages(['threshold' => 'Indica el umbral monetario.']);
                }
                $query->where('amount_cents', '>=', $threshold);
                break;
            case 'materiality':
                $materiality = DB::table('audit_plans')->where('audit_engagement_id', $engagement->id)->where('status', 'approved')->value('materiality_cents');
                if ($materiality === null) {
                    throw ValidationException::withMessages(['method' => 'El plan aprobado no define materialidad.']);
                }
                $query->where('amount_cents', '>=', (int) $materiality);
                $note = 'Materialidad del plan: Q '.number_format((int) $materiality / 100, 2);
                break;
            case 'criteria_based':
                $query->when(! empty($criteria['min_cents']), fn (Builder $q) => $q->where('amount_cents', '>=', (int) $criteria['min_cents']))
                    ->when(! empty($criteria['max_cents']), fn (Builder $q) => $q->where('amount_cents', '<=', (int) $criteria['max_cents']))
                    ->when(! empty($criteria['text']), fn (Builder $q) => $q->where('reference', 'ilike', '%'.$criteria['text'].'%'));
                break;
            case 'date_range':
                $query->whereBetween('item_date', [(string) ($criteria['date_from'] ?? $from), (string) ($criteria['date_to'] ?? $to)]);
                break;
            case 'anomaly_candidates':
                // Observable patterns only (round amounts, weekend dates, unusually large amounts); never a conclusion.
                $p95 = (int) (DB::query()->fromSub($base, 'u')->selectRaw('CAST(COALESCE(percentile_cont(0.95) WITHIN GROUP (ORDER BY amount_cents), 0) AS BIGINT) as p')->value('p'));
                $query->where(fn (Builder $q) => $q->whereRaw('amount_cents % 100000 = 0 AND amount_cents > 0')->orWhereRaw('EXTRACT(ISODOW FROM item_date) IN (6, 7)')->orWhere('amount_cents', '>', $p95));
                $note = 'Criterios observables: montos redondos (múltiplos de Q1,000), fechas de fin de semana o montos sobre el percentil 95 (Q '.number_format($p95 / 100, 2).'). No implican irregularidad.';
                break;
            case 'random':
                $size = max(1, min(self::MAX_ITEMS, (int) ($criteria['size'] ?? 10)));
                $seed = (int) ($criteria['seed'] ?? 0);
                $rows = (clone $base)->orderBy('source_id')->get()->all();
                mt_srand($seed === 0 ? random_int(1, 2_147_483_647) : $seed);
                shuffle($rows);
                mt_srand();

                return ['population_count' => $population, 'population_cents' => $populationCents, 'items' => array_map(fn (stdClass $row): array => $this->item($row), array_slice($rows, 0, $size)), 'note' => 'Selección aleatoria de '.$size.' elemento(s).'];
            default:
                break;
        }
        $rows = $query->orderByDesc('amount_cents')->limit(self::MAX_ITEMS + 1)->get();
        if ($rows->count() > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['method' => 'La selección supera '.self::MAX_ITEMS.' elementos; ajusta el criterio.']);
        }

        return ['population_count' => $population, 'population_cents' => $populationCents, 'items' => array_values($rows->map(fn (stdClass $row): array => $this->item($row))->all()), 'note' => $note];
    }

    /**
     * Saves a draft sample with its candidates (a draft may be regenerated; a confirmed one cannot).
     *
     * @param  array<string, mixed>  $criteria
     */
    public function create(Organization $organization, User $actor, int $procedureId, string $universe, string $from, string $to, string $method, array $criteria): int
    {
        return DB::transaction(function () use ($organization, $actor, $procedureId, $universe, $from, $to, $method, $criteria): int {
            $procedure = DB::table('audit_procedures')->where('audit_procedures.organization_id', $organization->id)->where('id', $procedureId)->first() ?? abort(404);
            $engagement = $this->engagements->lock($organization, $actor, (int) $procedure->audit_engagement_id, 'audit.samples.create');
            $this->engagements->assertOpen($engagement);
            if ($method === 'random' && (int) ($criteria['seed'] ?? 0) === 0) {
                // The seed is kept so the random selection can be reproduced.
                $criteria['seed'] = random_int(1, 2_147_483_647);
            }
            $result = $this->candidates($engagement, $universe, $from, $to, $method, $criteria);
            if ($result['items'] === []) {
                throw ValidationException::withMessages(['method' => 'El criterio no seleccionó ningún elemento del universo.']);
            }
            $code = sprintf('MU-%03d', DB::table('audit_samples')->where('audit_engagement_id', $engagement->id)->count() + 1);
            $id = DB::table('audit_samples')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagement->id, 'audit_procedure_id' => $procedureId,
                'code' => $code, 'universe' => $universe, 'universe_from' => $from, 'universe_to' => $to, 'method' => $method, 'criteria' => json_encode([...$criteria, 'note' => $result['note']], JSON_THROW_ON_ERROR),
                'population_count' => $result['population_count'], 'population_cents' => $result['population_cents'], 'sample_count' => count($result['items']), 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->storeItems($organization, (int) $engagement->company_id, $id, $result['items']);
            $this->accounting->audit($organization, $actor, 'audit.sample.created', 'audit_samples', $id, ['method' => $method, 'universe' => $universe, 'count' => count($result['items'])], (int) $engagement->office_id);

            return $id;
        });
    }

    /** Removes elements from a draft sample before it is confirmed. */
    public function removeItem(Organization $organization, User $actor, int $itemId): void
    {
        $item = DB::table('audit_sample_items')->join('audit_samples', 'audit_samples.id', '=', 'audit_sample_items.audit_sample_id')->where('audit_sample_items.organization_id', $organization->id)
            ->where('audit_sample_items.id', $itemId)->first(['audit_sample_items.id', 'audit_samples.id as sample_id', 'audit_samples.status', 'audit_samples.audit_engagement_id']) ?? abort(404);
        $this->engagements->lock($organization, $actor, (int) $item->audit_engagement_id, 'audit.samples.create');
        if ($item->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Una muestra confirmada está congelada.']);
        }
        DB::table('audit_sample_items')->where('id', $itemId)->delete();
        DB::table('audit_samples')->where('id', $item->sample_id)->update(['sample_count' => DB::table('audit_sample_items')->where('audit_sample_id', $item->sample_id)->count(), 'updated_at' => now()]);
    }

    /** Confirms and freezes the sample (snapshot and hash of every element were taken at selection). */
    public function confirm(Organization $organization, User $actor, int $sampleId): void
    {
        DB::transaction(function () use ($organization, $actor, $sampleId): void {
            $sample = DB::table('audit_samples')->where('audit_samples.organization_id', $organization->id)->where('id', $sampleId)->lockForUpdate()->first() ?? abort(404);
            $engagement = $this->engagements->lock($organization, $actor, (int) $sample->audit_engagement_id, 'audit.samples.confirm');
            $this->engagements->assertOpen($engagement);
            if ($sample->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'La muestra ya está confirmada.']);
            }
            $count = DB::table('audit_sample_items')->where('audit_sample_id', $sampleId)->count();
            if ($count === 0) {
                throw ValidationException::withMessages(['status' => 'La muestra no tiene elementos.']);
            }
            DB::table('audit_samples')->where('id', $sampleId)->update(['status' => 'confirmed', 'sample_count' => $count, 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'audit.sample.confirmed', 'audit_samples', $sampleId, ['count' => $count, 'population' => (int) $sample->population_count], (int) $engagement->office_id);
        });
    }

    /** Test result of one element of a confirmed sample. */
    public function recordResult(Organization $organization, User $actor, int $itemId, string $result, ?string $note): void
    {
        abort_unless(isset(self::RESULTS[$result]) && $result !== 'pending', 422);
        DB::transaction(function () use ($organization, $actor, $itemId, $result, $note): void {
            $item = DB::table('audit_sample_items')->join('audit_samples', 'audit_samples.id', '=', 'audit_sample_items.audit_sample_id')->where('audit_sample_items.organization_id', $organization->id)
                ->where('audit_sample_items.id', $itemId)->first(['audit_sample_items.*', 'audit_samples.status as sample_status', 'audit_samples.audit_engagement_id', 'audit_samples.audit_procedure_id']) ?? abort(404);
            $engagement = $this->engagements->lock($organization, $actor, (int) $item->audit_engagement_id, 'audit.procedures.execute');
            $this->engagements->assertOpen($engagement);
            if ($item->sample_status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Confirma la muestra antes de registrar resultados.']);
            }
            if (in_array($result, ['exception', 'not_applicable'], true) && mb_strlen(trim((string) $note)) < 5) {
                throw ValidationException::withMessages(['note' => 'Documenta la nota de la excepción o por qué no aplica.']);
            }
            DB::table('audit_sample_items')->where('id', $itemId)->update(['result' => $result, 'note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            if ($result === 'exception') {
                DB::table('audit_procedures')->where('id', $item->audit_procedure_id)->whereIn('status', ['not_started', 'in_progress', 'waiting_evidence'])->update(['status' => 'exception_found', 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'audit.sample_item.tested', 'audit_sample_items', $itemId, ['result' => $result], (int) $engagement->office_id);
        });
    }

    /** Whether the source of an element changed after it was sampled. */
    public function changedSinceSampling(stdClass $item): ?bool
    {
        $current = $this->universeRow((string) $item->source_type, (int) $item->source_id);

        return $current === null ? null : hash('sha256', json_encode($this->item($current)['snapshot'], JSON_THROW_ON_ERROR)) !== $item->snapshot_hash;
    }

    /**
     * @param  list<array{source_type: string, source_id: int, reference: string, item_date: string|null, amount_cents: int|null, snapshot: array<string, mixed>}>  $items
     */
    private function storeItems(Organization $organization, int $companyId, int $sampleId, array $items): void
    {
        foreach (array_chunk($items, 100) as $chunk) {
            DB::table('audit_sample_items')->insert(array_map(fn (array $item): array => [
                'organization_id' => $organization->id, 'company_id' => $companyId, 'audit_sample_id' => $sampleId, 'source_type' => $item['source_type'], 'source_id' => $item['source_id'],
                'reference' => mb_substr($item['reference'], 0, 160), 'item_date' => $item['item_date'], 'amount_cents' => $item['amount_cents'], 'snapshot' => json_encode($item['snapshot'], JSON_THROW_ON_ERROR),
                'snapshot_hash' => hash('sha256', json_encode($item['snapshot'], JSON_THROW_ON_ERROR)), 'result' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
    }

    /** @return array{source_type: string, source_id: int, reference: string, item_date: string|null, amount_cents: int|null, snapshot: array<string, mixed>} */
    private function item(stdClass $row): array
    {
        return ['source_type' => (string) $row->source_type, 'source_id' => (int) $row->source_id, 'reference' => (string) $row->reference, 'item_date' => $row->item_date === null ? null : (string) $row->item_date,
            'amount_cents' => $row->amount_cents === null ? null : (int) $row->amount_cents,
            'snapshot' => ['reference' => (string) $row->reference, 'date' => $row->item_date === null ? null : (string) $row->item_date, 'amount_cents' => $row->amount_cents === null ? null : (int) $row->amount_cents, 'status' => (string) $row->status, 'party' => $row->party]];
    }

    private function universeRow(string $sourceType, int $sourceId): ?stdClass
    {
        $universe = match ($sourceType) {
            'purchase_documents' => 'purchases', 'sales_invoices' => 'sales', 'journal_entries' => 'journal_entries', 'bank_transactions' => 'bank_transactions', 'payment_instructions' => 'treasury_payments', default => null,
        };

        return $universe === null ? null : DB::query()->fromSub($this->universe(null, $universe, null, null), 'u')->where('source_id', $sourceId)->first();
    }

    /** Uniform view of a universe: source_type, source_id, reference, item_date, amount_cents, status, party. */
    private function universe(?int $companyId, string $universe, ?string $from, ?string $to): Builder
    {
        $query = match ($universe) {
            'purchases' => DB::table('purchase_documents')->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->whereIn('status', ['approved', 'void'])
                ->selectRaw("'purchase_documents' as source_type, id as source_id, COALESCE(external_reference, 'Compra #' || number) as reference, document_date as item_date, CAST(total_cents AS BIGINT) as amount_cents, status, supplier_name as party"),
            'sales' => DB::table('sales_invoices')->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->where('status', '!=', 'draft')
                ->selectRaw("'sales_invoices' as source_type, id as source_id, 'Venta #' || COALESCE(CAST(number AS TEXT), CAST(id AS TEXT)) || COALESCE(' · ' || fiscal_series || '-' || fiscal_number, '') as reference, issued_on as item_date, CAST(total_cents AS BIGINT) as amount_cents, status, customer_name as party"),
            'journal_entries' => DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->when($companyId !== null, fn (Builder $q) => $q->where('p.company_id', $companyId))->whereIn('e.status', ['posted', 'reversed'])
                ->selectRaw("'journal_entries' as source_type, e.id as source_id, 'Asiento #' || COALESCE(CAST(e.number AS TEXT), CAST(e.id AS TEXT)) || ' · ' || LEFT(e.description, 80) as reference, e.entry_date as item_date, CAST((SELECT COALESCE(SUM(l.debit_cents), 0) FROM journal_lines l WHERE l.journal_entry_id = e.id) AS BIGINT) as amount_cents, e.status, COALESCE(e.source_type, 'manual') as party"),
            'bank_transactions' => DB::table('bank_transactions as t')->join('bank_accounts as b', 'b.id', '=', 't.bank_account_id')->when($companyId !== null, fn (Builder $q) => $q->where('b.company_id', $companyId))
                ->selectRaw("'bank_transactions' as source_type, t.id as source_id, t.reference || ' · ' || LEFT(t.description, 80) as reference, t.transaction_date as item_date, CAST(t.amount_cents AS BIGINT) as amount_cents, t.reconciliation_status as status, b.bank_name || ' ' || t.flow as party"),
            'treasury_payments' => DB::table('payment_instructions')->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->where('status', 'executed')
                ->selectRaw("'payment_instructions' as source_type, id as source_id, COALESCE(execution_reference, 'Pago #' || id) as reference, executed_on as item_date, CAST(amount_cents AS BIGINT) as amount_cents, status, supplier_name as party"),
            default => abort(422),
        };
        $wrapped = DB::query()->fromSub($query, 'universe');
        if ($from !== null && $to !== null) {
            $wrapped->whereBetween('item_date', [$from, $to]);
        }

        return $wrapped;
    }
}
