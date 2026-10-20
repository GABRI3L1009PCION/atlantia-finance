<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Explanatory notes to the financial statements of a fiscal year. A starter set is generated with the real figures of
 * the company; every note is versioned: editing a published note creates a new draft version and the published one
 * stays valid until the new version is published.
 */
class StatementNoteService
{
    public function __construct(private AccountingService $accounting, private FinancialStatementService $statements, private AccountingPolicyService $policies, private FixedAssetService $assets) {}

    /** @return array{from: string, to: string} */
    public function range(int $companyId, int $fiscalYear): array
    {
        return $this->statements->fiscalRange($companyId, $fiscalYear);
    }

    /** @return list<array<string, mixed>> the current version of every note, with the published version that is in force */
    public function list(int $companyId, int $year): array
    {
        $rows = DB::table('financial_statement_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.created_by')->where('n.company_id', $companyId)->where('n.fiscal_year', $year)->orderBy('n.note_number')->orderByDesc('n.version')
            ->get(['n.*', 'u.name as author'])->groupBy('note_number');
        $notes = [];
        foreach ($rows as $number => $versions) {
            $current = $versions->first();
            $published = $versions->firstWhere('status', 'published');
            $notes[] = ['number' => (int) $number, 'title' => (string) $current->title, 'body' => (string) $current->body, 'version' => (int) $current->version, 'status' => (string) $current->status,
                'author' => $current->author, 'published_version' => $published === null ? null : (int) $published->version, 'published_at' => $published?->published_at,
                'versions' => $versions->map(fn (stdClass $v): array => ['version' => (int) $v->version, 'status' => (string) $v->status, 'at' => (string) $v->created_at])->values()->all()];
        }

        return $notes;
    }

    /** Creates the starter notes of the year (only when it has none) with the figures of the company. */
    public function seed(Organization $organization, User $user, int $companyId, int $year): int
    {
        if (DB::table('financial_statement_notes')->where('company_id', $companyId)->where('fiscal_year', $year)->exists()) {
            throw ValidationException::withMessages(['notes' => 'Este año ya tiene notas. Edítalas o agrega una nueva.']);
        }
        $created = 0;
        foreach ($this->defaults($companyId, $year) as $number => [$title, $body]) {
            $this->insert($organization, $user, $companyId, $year, $number, $title, $body, 1);
            $created++;
        }
        $this->accounting->audit($organization, $user, 'statement_notes.seeded', 'companies', $companyId, ['year' => $year, 'notes' => $created]);

        return $created;
    }

    /** Saves a note: updates a draft in place, or opens a new version when the latest one is published. Number null adds a new note. */
    public function save(Organization $organization, User $user, int $companyId, int $year, ?int $number, string $title, string $body): int
    {
        if (trim($title) === '' || trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'La nota necesita título y contenido.']);
        }

        return DB::transaction(function () use ($organization, $user, $companyId, $year, $number, $title, $body): int {
            $this->accounting->lock($companyId);
            if ($number === null) {
                $number = (int) DB::table('financial_statement_notes')->where('company_id', $companyId)->where('fiscal_year', $year)->max('note_number') + 1;
                $this->insert($organization, $user, $companyId, $year, $number, trim($title), trim($body), 1);
            } else {
                $latest = DB::table('financial_statement_notes')->where('company_id', $companyId)->where('fiscal_year', $year)->where('note_number', $number)->orderByDesc('version')->lockForUpdate()->firstOrFail();
                if ($latest->status === 'draft') {
                    DB::table('financial_statement_notes')->where('id', $latest->id)->update(['title' => trim($title), 'body' => trim($body), 'updated_at' => now()]);
                } else {
                    $this->insert($organization, $user, $companyId, $year, $number, trim($title), trim($body), (int) $latest->version + 1);
                }
            }
            $this->accounting->audit($organization, $user, 'statement_note.saved', 'companies', $companyId, ['year' => $year, 'note' => $number]);

            return $number;
        });
    }

    /** Publishes the latest draft; the previously published version becomes superseded. */
    public function publish(Organization $organization, User $user, int $companyId, int $year, int $number): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $year, $number): void {
            $this->accounting->lock($companyId);
            $draft = DB::table('financial_statement_notes')->where('company_id', $companyId)->where('fiscal_year', $year)->where('note_number', $number)->where('status', 'draft')->orderByDesc('version')->lockForUpdate()->first();
            if ($draft === null) {
                throw ValidationException::withMessages(['notes' => 'La nota no tiene cambios por publicar.']);
            }
            DB::table('financial_statement_notes')->where('company_id', $companyId)->where('fiscal_year', $year)->where('note_number', $number)->where('status', 'published')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('financial_statement_notes')->where('id', $draft->id)->update(['status' => 'published', 'published_by' => $user->id, 'published_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'statement_note.published', 'companies', $companyId, ['year' => $year, 'note' => $number, 'version' => $draft->version]);
        });
    }

    /**
     * Rows for the export: the version in force of each note (published when there is one, otherwise the latest draft).
     *
     * @return list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    public function exportRows(int $companyId, int $year): array
    {
        return array_map(fn (array $note): array => ['Nota '.$note['number'], $note['title'], $note['body']], $this->list($companyId, $year));
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function defaults(int $companyId, int $year): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first(['name', 'tax_id', 'currency']);
        $range = $this->range($companyId, $year);
        $set = $this->statements->set($companyId, $range['from'], $range['to']);
        $position = $set['position'];
        $money = fn (int $cents): string => 'Q '.number_format($cents / 100, 2);
        $sum = fn (array $rows, array $prefixes): int => (int) array_sum(array_map(fn (array $row): int => $this->startsWith($row['code'], $prefixes) ? $row['cents'] : 0, $rows));
        $register = $this->assets->register($companyId)['totals'];
        $profile = DB::table('company_fiscal_profiles')->where('company_id', $companyId)->first(['income_tax_regime', 'base_currency']);
        $regime = ['utilities' => 'Régimen sobre las utilidades de actividades lucrativas', 'optional_simplified' => 'Régimen opcional simplificado sobre ingresos', 'exempt' => 'Régimen exento'][$profile->income_tax_regime ?? 'utilities']
            ?? 'Régimen de impuesto sobre la renta configurado para la empresa';
        $methods = AccountingPolicyService::CATALOG['depreciation_method']['options'];

        return [
            1 => ['Información general', "{$company->name}, NIT {$company->tax_id}, es una entidad que lleva su contabilidad en Guatemala. Los presentes estados financieros corresponden al período del {$range['from']} al {$range['to']} y se expresan en {$company->currency}."],
            2 => ['Base de preparación', 'Los estados financieros se han preparado de conformidad con las Normas Internacionales de Información Financiera (NIIF), bajo el principio de negocio en marcha y sobre la base del costo histórico. Se presentan con clasificación de activos y pasivos en corrientes y no corrientes, conforme a la NIC 1.'],
            3 => ['Políticas contables significativas', 'La propiedad, planta y equipo se registra al costo y se deprecia por el método de '.mb_strtolower($methods[$this->policies->value($companyId, 'depreciation_method')] ?? 'línea recta')
                .' a lo largo de su vida útil estimada. Los activos intangibles se amortizan en su vida útil. '.($this->policies->capitalizationMinimumCents($companyId) > 0 ? 'Se capitalizan compras iguales o mayores a '.$money($this->policies->capitalizationMinimumCents($companyId)).'; las menores se llevan a gasto. ' : '')
                .'Los ingresos se reconocen cuando se transfiere el control de los bienes o servicios al cliente.'],
            4 => ['Efectivo y equivalentes de efectivo', 'Al '.$range['to'].' el efectivo y los depósitos bancarios suman '.$money($set['cash_flow']['totals']['cash_close']).'. No existen restricciones sobre su disponibilidad.'],
            5 => ['Cuentas por cobrar', 'Al '.$range['to'].' las cuentas por cobrar a clientes suman '.$money($sum($position['current_assets'], ['1103'])).'. Se presentan a su valor nominal, neto de estimaciones por deterioro cuando corresponde.'],
            6 => ['Propiedades, planta y equipo e intangibles', 'El costo de los activos fijos e intangibles vigentes es de '.$money($register['cost_cents']).', con depreciación y amortización acumulada de '.$money($register['accumulated_cents'])
                .' y un valor en libros de '.$money($register['book_cents']).'. El cargo del mes en curso es de '.$money($register['month_cents']).'.'],
            7 => ['Cuentas por pagar', 'Al '.$range['to'].' las cuentas por pagar a proveedores y otras obligaciones corrientes suman '.$money($position['totals']['current_liabilities']).'. Los pasivos no corrientes suman '.$money($position['totals']['non_current_liabilities']).'.'],
            8 => ['Patrimonio', 'El patrimonio de los accionistas al '.$range['to'].' es de '.$money($position['totals']['equity_with_result']).', del cual el resultado acumulado del período corresponde a '.$money($position['result_cents']).'.'],
            9 => ['Impuesto sobre la renta', 'La empresa tributa bajo el '.$regime.'. La provisión del impuesto se calcula sobre la renta imponible, que resulta de ajustar la utilidad contable por los gastos no deducibles, ingresos exentos y diferencias temporales y permanentes.'],
            10 => ['Hechos posteriores', 'Entre el cierre del período y la fecha de autorización de estos estados financieros no se conocen hechos que requieran ajustes ni revelaciones adicionales.'],
        ];
    }

    private function insert(Organization $organization, User $user, int $companyId, int $year, int $number, string $title, string $body, int $version): void
    {
        DB::table('financial_statement_notes')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'fiscal_year' => $year, 'note_number' => $number, 'title' => mb_substr($title, 0, 150), 'body' => $body,
            'version' => $version, 'status' => 'draft', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<int, string> $prefixes */
    private function startsWith(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
