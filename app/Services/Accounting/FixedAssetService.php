<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Fixed assets and intangibles: register, monthly depreciation/amortization (straight line or declining balance) with
 * its journal entry, and disposal. Defaults (method, useful life, start month, capitalization minimum) come from the
 * company's accounting policies.
 */
class FixedAssetService
{
    public const CATEGORIES = [
        'buildings' => 'Edificaciones', 'furniture' => 'Mobiliario y equipo de oficina', 'machinery' => 'Maquinaria', 'vehicles' => 'Vehículos',
        'computers' => 'Equipo de cómputo', 'tools' => 'Herramientas', 'intangible' => 'Intangibles (se amortizan)', 'other' => 'Otros bienes',
    ];

    public const METHODS = ['straight_line' => 'Línea recta', 'declining_balance' => 'Saldo decreciente'];

    /** Accounts created on demand, so nobody has to design the chart before registering the first asset. */
    private const ACCOUNTS = [
        'tangible' => ['asset' => ['1401', 'Propiedad, planta y equipo', 'asset'], 'accumulated' => ['1402', 'Depreciación acumulada', 'asset'], 'expense' => ['5206', 'Gasto por depreciación', 'expense']],
        'intangible' => ['asset' => ['1403', 'Activos intangibles', 'asset'], 'accumulated' => ['1404', 'Amortización acumulada', 'asset'], 'expense' => ['5207', 'Gasto por amortización', 'expense']],
    ];

    /** Entries that no longer count against the asset. */
    private const DEAD_ENTRY_STATUSES = ['rejected', 'reversed', 'void', 'voided'];

    public function __construct(private AccountingService $accounting, private AccountingAutomationService $automation, private AccountingPolicyService $policies) {}

    /** @return array{assets: list<array<string, mixed>>, totals: array<string, int>} */
    public function register(int $companyId): array
    {
        $assets = DB::table('fixed_assets')->where('company_id', $companyId)->orderBy('code')->get();
        $charged = $this->charged($companyId);
        $rows = [];
        $totals = ['cost_cents' => 0, 'accumulated_cents' => 0, 'book_cents' => 0, 'month_cents' => 0];
        $thisMonth = now()->startOfMonth()->toDateString();
        foreach ($assets as $asset) {
            $accumulated = (int) ($charged[$asset->id] ?? 0);
            $schedule = $this->schedule($asset);
            $book = $asset->status === 'disposed' ? 0 : (int) $asset->cost_cents - $accumulated;
            $monthAmount = $asset->status === 'active' ? (int) ($schedule[$thisMonth] ?? 0) : 0;
            $rows[] = ['id' => (int) $asset->id, 'code' => (string) $asset->code, 'name' => (string) $asset->name, 'category' => (string) $asset->category, 'category_label' => self::CATEGORIES[$asset->category] ?? (string) $asset->category,
                'kind_label' => $asset->category === 'intangible' ? 'Amortización' : 'Depreciación', 'acquired_on' => (string) $asset->acquired_on, 'in_service_on' => (string) $asset->in_service_on,
                'starts_on' => $asset->depreciation_starts_on, 'cost_cents' => (int) $asset->cost_cents, 'salvage_cents' => (int) $asset->salvage_cents, 'useful_life_months' => (int) $asset->useful_life_months,
                'method' => (string) $asset->method, 'method_label' => self::METHODS[$asset->method] ?? (string) $asset->method, 'status' => (string) $asset->status, 'cost_center' => $asset->cost_center,
                'accumulated_cents' => $accumulated, 'book_cents' => $book, 'month_cents' => $monthAmount, 'months_charged' => $this->monthsCharged($asset->id),
                'disposed_on' => $asset->disposed_on, 'disposal_reason' => $asset->disposal_reason];
            if ($asset->status !== 'disposed') {
                $totals['cost_cents'] += (int) $asset->cost_cents;
                $totals['accumulated_cents'] += $accumulated;
                $totals['book_cents'] += $book;
                $totals['month_cents'] += $monthAmount;
            }
        }

        return ['assets' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array{code?: string|null, name: string, category: string, acquired_on: string, in_service_on: string, cost: string, salvage?: string|null, useful_life_months?: int|null, method?: string|null, cost_center?: string|null}  $data
     */
    public function create(Organization $organization, User $user, int $companyId, array $data): int
    {
        $category = $data['category'];
        abort_unless(isset(self::CATEGORIES[$category]), 422);
        $cost = $this->accounting->cents($data['cost']);
        $salvage = $this->accounting->cents($data['salvage'] ?? '0');
        $minimum = $this->policies->capitalizationMinimumCents($companyId);
        if ($minimum > 0 && $cost < $minimum) {
            throw ValidationException::withMessages(['cost' => 'La política contable no permite capitalizar compras menores a Q '.number_format($minimum / 100, 2).'; llévala a gasto.']);
        }
        if ($salvage >= $cost) {
            throw ValidationException::withMessages(['salvage' => 'El valor residual debe ser menor que el costo.']);
        }
        if ($data['in_service_on'] < $data['acquired_on']) {
            throw ValidationException::withMessages(['in_service_on' => 'La puesta en servicio no puede ser anterior a la compra.']);
        }
        $life = (int) ($data['useful_life_months'] ?? 0) ?: $this->policies->int($companyId, 'life_'.$category);
        $method = ($data['method'] ?? null) ?: $this->policies->value($companyId, 'depreciation_method');
        abort_unless(isset(self::METHODS[$method]), 422);
        if ($data['cost_center'] ?? null) {
            app(CostCenterService::class)->validateLines($companyId, [['ledger_account_id' => 0, 'cost_center' => mb_strtoupper($data['cost_center'])]]);
        }
        $accounts = $this->ensureAccounts($companyId, $category === 'intangible' ? 'intangible' : 'tangible');
        $start = Carbon::parse($data['in_service_on'])->startOfMonth();
        if ($this->policies->value($companyId, 'depreciation_start') === 'month_after') {
            $start = $start->addMonth();
        }

        return DB::transaction(function () use ($organization, $user, $companyId, $data, $category, $cost, $salvage, $life, $method, $accounts, $start): int {
            $this->accounting->lock($companyId);
            $code = filled($data['code'] ?? null) ? mb_strtoupper(trim((string) $data['code'])) : $this->nextCode($companyId);
            if (DB::table('fixed_assets')->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Ya existe un activo con ese código.']);
            }
            $id = (int) DB::table('fixed_assets')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'code' => $code, 'name' => trim($data['name']), 'category' => $category,
                'acquired_on' => $data['acquired_on'], 'in_service_on' => $data['in_service_on'], 'cost_cents' => $cost, 'salvage_cents' => $salvage, 'useful_life_months' => $life, 'method' => $method,
                'asset_account_id' => $accounts['asset'], 'accumulated_account_id' => $accounts['accumulated'], 'expense_account_id' => $accounts['expense'],
                'cost_center' => filled($data['cost_center'] ?? null) ? mb_strtoupper(trim((string) $data['cost_center'])) : null, 'status' => 'active', 'depreciation_starts_on' => $start->toDateString(),
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'fixed_asset.created', 'fixed_assets', $id, ['code' => $code, 'cost_cents' => $cost, 'life_months' => $life]);

            return $id;
        });
    }

    /**
     * Month by month schedule of an asset (first day of the month => amount).
     *
     * @return array<string, int>
     */
    public function schedule(stdClass $asset): array
    {
        $base = (int) $asset->cost_cents - (int) $asset->salvage_cents;
        $months = (int) $asset->useful_life_months;
        $start = Carbon::parse($asset->depreciation_starts_on ?? $asset->in_service_on)->startOfMonth();
        $schedule = [];
        $book = (int) $asset->cost_cents;
        for ($k = 1; $k <= $months; $k++) {
            $remaining = $book - (int) $asset->salvage_cents;
            if ($asset->method === 'declining_balance') {
                $amount = $k === $months ? $remaining : min($remaining, (int) round($book * 2 / $months));
            } else {
                $amount = $k === $months ? $base - intdiv($base, $months) * ($months - 1) : intdiv($base, $months);
            }
            if ($amount > 0) {
                $schedule[$start->copy()->addMonths($k - 1)->toDateString()] = $amount;
                $book -= $amount;
            }
        }

        return $schedule;
    }

    /**
     * Charges the month to every asset that has not been charged yet, one draft journal entry per asset.
     *
     * @return array{charged: int, amount_cents: int, skipped: int}
     */
    public function depreciate(Organization $organization, User $user, int $companyId, Carbon $month): array
    {
        $first = $month->copy()->startOfMonth();
        $last = $first->copy()->endOfMonth();
        $closed = DB::table('accounting_periods')->where('company_id', $companyId)->whereDate('starts_on', '<=', $last->toDateString())->whereDate('ends_on', '>=', $first->toDateString())
            ->where(fn ($query) => $query->whereNotNull('closed_at')->orWhereIn('status', ['closed', 'reopen_requested', 'reopen_approved']))->exists();
        if ($closed) {
            throw ValidationException::withMessages(['month' => 'El período contable de '.$first->settings(['locale' => 'es'])->translatedFormat('F Y').' está cerrado.']);
        }

        return DB::transaction(function () use ($organization, $user, $companyId, $first, $last): array {
            $this->accounting->lock($companyId);
            $result = ['charged' => 0, 'amount_cents' => 0, 'skipped' => 0];
            $assets = DB::table('fixed_assets')->where('company_id', $companyId)->where('status', 'active')->orderBy('code')->get();
            foreach ($assets as $asset) {
                $amount = (int) ($this->schedule($asset)[$first->toDateString()] ?? 0);
                if ($amount === 0 || DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $asset->id)->where('month_on', $first->toDateString())->exists()) {
                    $result['skipped']++;

                    continue;
                }
                $rowId = (int) DB::table('fixed_asset_depreciations')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'fixed_asset_id' => $asset->id,
                    'month_on' => $first->toDateString(), 'amount_cents' => $amount, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                $label = $asset->category === 'intangible' ? 'Amortización' : 'Depreciación';
                $month = $first->copy()->settings(['locale' => 'es'])->translatedFormat('F Y');
                $entryId = $this->automation->draft($organization, $user, $companyId, 'fixed_asset_depreciation', $rowId, $last->toDateString(), $label.' '.$asset->code.' · '.$month, 'DEP-'.$asset->code.'-'.$first->format('Ym'),
                    [['account_id' => (int) $asset->expense_account_id, 'debit' => $this->decimal($amount), 'credit' => '0', 'description' => $label.' '.$asset->name, ...($asset->cost_center ? ['cost_center' => $asset->cost_center] : [])],
                        ['account_id' => (int) $asset->accumulated_account_id, 'debit' => '0', 'credit' => $this->decimal($amount), 'description' => $label.' acumulada '.$asset->name]], 'fixed_asset.depreciation');
                DB::table('fixed_asset_depreciations')->where('id', $rowId)->update(['journal_entry_id' => $entryId]);
                $result['charged']++;
                $result['amount_cents'] += $amount;
                $this->closeIfFullyDepreciated($asset, $first);
            }
            $this->accounting->audit($organization, $user, 'fixed_asset.depreciated', 'companies', $companyId, ['month' => $first->format('Y-m'), ...$result]);

            return $result;
        });
    }

    /** Retires the asset: removes its cost and accumulated depreciation and takes the remaining book value to a loss account. */
    public function dispose(Organization $organization, User $user, int $companyId, int $assetId, string $date, string $reason, ?int $lossAccountId): int
    {
        return DB::transaction(function () use ($organization, $user, $companyId, $assetId, $date, $reason, $lossAccountId): int {
            $this->accounting->lock($companyId);
            $asset = DB::table('fixed_assets')->where('company_id', $companyId)->where('id', $assetId)->lockForUpdate()->firstOrFail();
            if ($asset->status === 'disposed') {
                throw ValidationException::withMessages(['asset' => 'El activo ya fue dado de baja.']);
            }
            if ($date < $asset->in_service_on) {
                throw ValidationException::withMessages(['disposed_on' => 'La baja no puede ser anterior a la puesta en servicio.']);
            }
            $accumulated = (int) ($this->charged($companyId)[$asset->id] ?? 0);
            $book = (int) $asset->cost_cents - $accumulated;
            $lines = [];
            if ($accumulated > 0) {
                $lines[] = ['account_id' => (int) $asset->accumulated_account_id, 'debit' => $this->decimal($accumulated), 'credit' => '0', 'description' => 'Baja de depreciación acumulada '.$asset->code];
            }
            if ($book > 0) {
                $loss = $lossAccountId ?? (int) $asset->expense_account_id;
                if (! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $loss)->where('type', 'expense')->exists()) {
                    throw ValidationException::withMessages(['loss_account_id' => 'Elige una cuenta de gasto de esta empresa para la pérdida por baja.']);
                }
                $lines[] = ['account_id' => $loss, 'debit' => $this->decimal($book), 'credit' => '0', 'description' => 'Pérdida por baja '.$asset->code];
            }
            $lines[] = ['account_id' => (int) $asset->asset_account_id, 'debit' => '0', 'credit' => $this->decimal((int) $asset->cost_cents), 'description' => 'Baja de activo '.$asset->code.' · '.$asset->name];
            $entryId = (int) $this->automation->draft($organization, $user, $companyId, 'fixed_asset_disposal', (int) $asset->id, $date, 'Baja de activo '.$asset->code.' · '.$reason, 'BAJA-'.$asset->code, $lines, 'fixed_asset.disposal');
            DB::table('fixed_assets')->where('id', $asset->id)->update(['status' => 'disposed', 'disposed_on' => $date, 'disposal_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'fixed_asset.disposed', 'fixed_assets', (int) $asset->id, ['date' => $date, 'book_cents' => $book]);

            return $entryId;
        });
    }

    /**
     * Future charges of an asset, for the schedule view.
     *
     * @return list<array{month: string, amount_cents: int, accumulated_cents: int, book_cents: int, charged: bool}>
     */
    public function projection(int $companyId, int $assetId): array
    {
        $asset = DB::table('fixed_assets')->where('company_id', $companyId)->where('id', $assetId)->firstOrFail();
        $done = DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $asset->id)->pluck('amount_cents', 'month_on')->mapWithKeys(fn ($amount, $month) => [substr((string) $month, 0, 10) => (int) $amount])->all();
        $accumulated = 0;
        $rows = [];
        foreach ($this->schedule($asset) as $month => $amount) {
            $accumulated += $amount;
            $rows[] = ['month' => $month, 'amount_cents' => $amount, 'accumulated_cents' => $accumulated, 'book_cents' => (int) $asset->cost_cents - $accumulated, 'charged' => isset($done[$month])];
        }

        return $rows;
    }

    /** @return array<int, int> accumulated depreciation per asset, ignoring charges whose entry was rejected or reversed */
    private function charged(int $companyId): array
    {
        return DB::table('fixed_asset_depreciations as d')->leftJoin('journal_entries as e', 'e.id', '=', 'd.journal_entry_id')->where('d.company_id', $companyId)
            ->where(fn ($query) => $query->whereNull('e.id')->orWhereNotIn('e.status', self::DEAD_ENTRY_STATUSES))
            ->groupBy('d.fixed_asset_id')->selectRaw('d.fixed_asset_id, CAST(SUM(d.amount_cents) AS BIGINT) as total')->pluck('total', 'fixed_asset_id')->map(fn ($value): int => (int) $value)->all();
    }

    private function monthsCharged(int $assetId): int
    {
        return DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $assetId)->count();
    }

    private function closeIfFullyDepreciated(stdClass $asset, Carbon $month): void
    {
        $months = array_keys($this->schedule($asset));
        if ($months !== [] && end($months) === $month->toDateString()) {
            DB::table('fixed_assets')->where('id', $asset->id)->update(['status' => 'fully_depreciated', 'updated_at' => now()]);
        }
    }

    private function nextCode(int $companyId): string
    {
        return 'AF-'.str_pad((string) ((int) DB::table('fixed_assets')->where('company_id', $companyId)->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @return array{asset: int, accumulated: int, expense: int} */
    private function ensureAccounts(int $companyId, string $kind): array
    {
        $this->accounting->installDefaultChart($companyId);
        $ids = ['asset' => 0, 'accumulated' => 0, 'expense' => 0];
        foreach (self::ACCOUNTS[$kind] as $role => [$code, $name, $type]) {
            DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true, 'accepts_movements' => true,
                'is_group' => false, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->first(['id', 'type', 'is_active', 'accepts_movements', 'is_group']);
            if ($account === null || $account->type !== $type || ! $account->is_active || ! $account->accepts_movements || $account->is_group) {
                throw ValidationException::withMessages(['accounting' => 'La cuenta '.$code.' ('.$name.') ya existe en el catálogo con otro uso. Ajusta el catálogo para registrar activos.']);
            }
            $ids[$role] = (int) $account->id;
        }

        return $ids;
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
