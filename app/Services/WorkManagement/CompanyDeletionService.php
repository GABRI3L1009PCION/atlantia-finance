<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Mail\CompanyDeletionMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Deleting a client company, with the safety net a firm needs against a mistake or a malicious user: only the owner can
 * do it, with a reason; every row of the company is backed up before anything changes (if the backup fails nothing is
 * deleted); the company goes to a 30-day trash, hidden everywhere by the database itself, from which the owner can
 * restore it; every owner is told at once and reminded a week and a day before it is gone for good.
 */
class CompanyDeletionService
{
    public const TRASH_DAYS = 30;

    public const REASONS = [
        'created_by_mistake' => 'Creada por error',
        'duplicate' => 'Empresa duplicada',
        'contract_ended' => 'El cliente terminó el contrato',
        'other' => 'Otro motivo',
    ];

    /** How deep the backup follows the tables that hang from the company's own rows (journal lines, invoice lines...). */
    private const BACKUP_DEPTH = 4;

    public function __construct(private AccountingService $accounting, private WorkNotificationService $notifications) {}

    public static function isOwner(Organization $organization, User $user): bool
    {
        return DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)
            ->where('role', 'owner')->where('status', 'active')->exists();
    }

    /**
     * What the owner should know before deleting: certified invoices exist at the SAT whatever happens here.
     *
     * @return array{id: int, name: string, tax_id: string, fel_certified: int, journal_entries: int, sales_invoices: int, purchases: int, reasons: array<string, string>, trash_days: int}
     */
    public function preview(Organization $organization, int $companyId): array
    {
        $company = $this->company($organization, $companyId);

        return ['id' => (int) $company->id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id,
            'fel_certified' => DB::table('fel_documents')->where('company_id', $companyId)->where('status', 'certified')->count(),
            'journal_entries' => DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('accounting_periods.company_id', $companyId)->count(),
            'sales_invoices' => DB::table('sales_invoices')->where('company_id', $companyId)->count(),
            'purchases' => DB::table('purchase_documents')->where('company_id', $companyId)->count(),
            'reasons' => self::REASONS, 'trash_days' => self::TRASH_DAYS];
    }

    /** Backs the company up and moves it to the trash. Returns the deletion id. */
    public function delete(Organization $organization, User $owner, int $companyId, string $reason, ?string $note, ?string $ip): int
    {
        if (! self::isOwner($organization, $owner)) {
            throw ValidationException::withMessages(['company' => 'Solo el dueño de la firma puede eliminar una empresa.']);
        }
        if (! array_key_exists($reason, self::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Elige el motivo de la eliminación.']);
        }
        $company = $this->company($organization, $companyId);
        // The backup comes first: if it cannot be written, nothing is deleted.
        try {
            $backup = $this->backup($organization, $company);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['company' => 'No se pudo crear la copia de seguridad de la empresa, así que no se eliminó nada. Inténtalo de nuevo o contacta a soporte.']);
        }
        $now = now();
        $id = DB::table('company_deletions')->insertGetId([
            'organization_id' => $organization->id, 'company_id' => $companyId, 'company_name' => $company->name, 'company_tax_id' => $company->tax_id,
            'reason' => $reason, 'note' => $note === null || trim($note) === '' ? null : trim($note), 'deleted_by' => $owner->id, 'deleted_from_ip' => $ip,
            'deleted_at' => $now, 'purge_after' => $now->copy()->addDays(self::TRASH_DAYS), 'backup_path' => $backup['path'], 'backup_sha256' => $backup['sha256'],
            'backup_bytes' => $backup['bytes'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->accounting->audit($organization, $owner, 'company.deleted', 'companies', $companyId,
            ['name' => $company->name, 'tax_id' => $company->tax_id, 'reason' => self::REASONS[$reason], 'note' => $note, 'ip' => $ip, 'backup' => $backup['path'], 'backup_sha256' => $backup['sha256']], (int) $company->office_id);
        $until = $now->copy()->addDays(self::TRASH_DAYS);
        $this->tellOwners($organization, 'company.deleted', $owner->name.' eliminó la empresa '.$company->name,
            [$owner->name.' eliminó la empresa '.$company->name.' (NIT '.$company->tax_id.') el '.$now->format('d/m/Y').' a las '.$now->format('H:i').'.',
                'Motivo: '.self::REASONS[$reason].($note ? ' — '.trim($note) : '').'.', 'Se guardó una copia de seguridad completa antes de eliminarla.',
                'Puedes restaurarla hasta el '.$until->format('d/m/Y').' desde Clientes → Papelera. Si no fuiste tú, restáurala y cambia tu contraseña.'], $id);

        return $id;
    }

    public function restore(Organization $organization, User $owner, int $deletionId): void
    {
        if (! self::isOwner($organization, $owner)) {
            throw ValidationException::withMessages(['deletion' => 'Solo el dueño de la firma puede restaurar una empresa.']);
        }
        $deletion = DB::table('company_deletions')->where('company_deletions.organization_id', $organization->id)->where('id', $deletionId)->whereNull('restored_at')->first();
        if ($deletion === null) {
            throw ValidationException::withMessages(['deletion' => 'La empresa ya no está en la papelera.']);
        }
        if ($deletion->purged_at !== null) {
            throw ValidationException::withMessages(['deletion' => 'Pasaron los '.self::TRASH_DAYS.' días de la papelera y la empresa se eliminó definitivamente.']);
        }
        DB::table('company_deletions')->where('id', $deletionId)->update(['restored_at' => now(), 'restored_by' => $owner->id, 'updated_at' => now()]);
        $officeId = DB::table('companies')->where('id', $deletion->company_id)->value('office_id');
        $this->accounting->audit($organization, $owner, 'company.restored', 'companies', (int) $deletion->company_id, ['name' => $deletion->company_name, 'deletion_id' => $deletionId], $officeId === null ? null : (int) $officeId);
        $this->tellOwners($organization, 'company.restored', $owner->name.' restauró la empresa '.$deletion->company_name,
            ['La empresa '.$deletion->company_name.' volvió a estar disponible con toda su información.'], $deletionId);
    }

    /**
     * Companies in the trash, the most recent first.
     *
     * @return list<array{id: int, name: string, tax_id: string, reason: string, note: string|null, deleted_by: string, deleted_at: string, purge_after: string, days_left: int}>
     */
    public function trash(Organization $organization): array
    {
        return array_values(DB::table('company_deletions')->join('users', 'users.id', '=', 'company_deletions.deleted_by')
            ->where('company_deletions.organization_id', $organization->id)->whereNull('restored_at')->whereNull('purged_at')->orderByDesc('deleted_at')
            ->get(['company_deletions.*', 'users.name as deleted_by_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->company_name, 'tax_id' => (string) $row->company_tax_id,
                'reason' => self::REASONS[$row->reason] ?? (string) $row->reason, 'note' => $row->note, 'deleted_by' => (string) $row->deleted_by_name,
                'deleted_at' => Carbon::parse($row->deleted_at)->toIso8601String(), 'purge_after' => Carbon::parse($row->purge_after)->toIso8601String(),
                'days_left' => max(0, (int) ceil(now()->diffInHours(Carbon::parse($row->purge_after), false) / 24))])->all());
    }

    /**
     * Daily: reminds the owners a week and a day before the end of the trash, and purges what reached it. A purged company
     * frees its NIT for a new registration; its rows stay hidden, never shown again to the firm.
     *
     * @return array{reminded: int, purged: int}
     */
    public function sweep(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $result = ['reminded' => 0, 'purged' => 0];
        foreach (DB::table('company_deletions')->whereNull('restored_at')->whereNull('purged_at')->get() as $deletion) {
            $organization = Organization::query()->find((int) $deletion->organization_id);
            if ($organization === null) {
                continue;
            }
            $purgeAt = Carbon::parse($deletion->purge_after);
            if ($now->gte($purgeAt)) {
                DB::transaction(function () use ($deletion, $now): void {
                    DB::statement("SELECT set_config('atlantia.include_deleted', 'on', true)");
                    DB::table('companies')->where('id', $deletion->company_id)->update(['tax_id' => Str::limit($deletion->company_tax_id, 18, '').'#E'.$deletion->id, 'updated_at' => $now]);
                    DB::table('company_deletions')->where('id', $deletion->id)->update(['purged_at' => $now, 'updated_at' => $now]);
                });
                $this->tellOwners($organization, 'company.purged', 'La empresa '.$deletion->company_name.' se eliminó definitivamente',
                    ['Pasaron los '.self::TRASH_DAYS.' días de la papelera. La copia de seguridad se conserva por el plazo legal de revisión de la SAT.'], (int) $deletion->id);
                $result['purged']++;

                continue;
            }
            $stage = match (true) {
                $deletion->reminded_day_at === null && $now->gte($purgeAt->copy()->subDay()) => 'day',
                $deletion->reminded_week_at === null && $deletion->reminded_day_at === null && $now->gte($purgeAt->copy()->subDays(7)) => 'week',
                default => null,
            };
            if ($stage === null) {
                continue;
            }
            DB::table('company_deletions')->where('id', $deletion->id)->update([$stage === 'day' ? 'reminded_day_at' : 'reminded_week_at' => $now, 'updated_at' => $now]);
            $when = $stage === 'day' ? 'mañana' : 'en 7 días';
            $this->tellOwners($organization, 'company.deletion_reminder', 'La empresa '.$deletion->company_name.' se eliminará definitivamente '.$when,
                ['Está en la papelera desde el '.Carbon::parse($deletion->deleted_at)->format('d/m/Y').' y el '.$purgeAt->format('d/m/Y').' se eliminará definitivamente.',
                    'Si fue un error, restáurala desde Clientes → Papelera antes de esa fecha.'], (int) $deletion->id);
            $result['reminded']++;
        }

        return $result;
    }

    private function company(Organization $organization, int $companyId): stdClass
    {
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $companyId)->first(['id', 'name', 'tax_id', 'office_id']);
        if ($company === null) {
            throw ValidationException::withMessages(['company' => 'La empresa no existe o ya está en la papelera.']);
        }

        return $company;
    }

    /**
     * Every row of the company: the tables that carry company_id and, following their foreign keys, the tables that hang
     * from them (journal lines, invoice lines, payments...). Written gzipped as JSON lines, one table per line.
     *
     * @return array{path: string, sha256: string, bytes: int}
     */
    private function backup(Organization $organization, stdClass $company): array
    {
        $relative = 'company-deletions/'.$organization->id.'/'.$company->id.'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.jsonl.gz';
        $absolute = Storage::disk('local')->path($relative);
        if (! is_dir(dirname($absolute)) && ! mkdir(dirname($absolute), 0750, true) && ! is_dir(dirname($absolute))) {
            throw new RuntimeException('No se pudo crear la carpeta de respaldos.');
        }
        $file = gzopen($absolute, 'wb9');
        if ($file === false) {
            throw new RuntimeException('No se pudo abrir el archivo de respaldo.');
        }
        $write = function (string $table, array $rows) use ($file): void {
            gzwrite($file, json_encode(['table' => $table, 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        };
        try {
            $write('_manifest', [['organization_id' => $organization->id, 'company_id' => (int) $company->id, 'name' => $company->name, 'tax_id' => $company->tax_id, 'created_at' => now()->toIso8601String(), 'format' => 1]]);
            $write('companies', array_map(fn (stdClass $row): array => (array) $row, DB::table('companies')->where('id', $company->id)->get()->all()));
            $exported = ['companies' => [(int) $company->id]];
            foreach ($this->companyTables() as $table) {
                $rows = DB::table($table)->where('company_id', $company->id)->get()->all();
                $write($table, array_map(fn (stdClass $row): array => (array) $row, $rows));
                $exported[$table] = array_values(array_filter(array_map(fn (stdClass $row): ?int => isset($row->id) ? (int) $row->id : null, $rows)));
            }
            $foreignKeys = $this->foreignKeys();
            for ($depth = 0; $depth < self::BACKUP_DEPTH; $depth++) {
                $found = [];
                foreach ($foreignKeys as [$child, $column, $parent]) {
                    if (isset($exported[$child]) || isset($found[$child]) || ($exported[$parent] ?? []) === []) {
                        continue;
                    }
                    $rows = [];
                    foreach (array_chunk($exported[$parent], 1000) as $ids) {
                        array_push($rows, ...DB::table($child)->whereIn($column, $ids)->get()->all());
                    }
                    $found[$child] = $rows;
                }
                if ($found === []) {
                    break;
                }
                foreach ($found as $table => $rows) {
                    $write($table, array_map(fn (stdClass $row): array => (array) $row, $rows));
                    $exported[$table] = array_values(array_filter(array_map(fn (stdClass $row): ?int => isset($row->id) ? (int) $row->id : null, $rows)));
                }
            }
        } finally {
            gzclose($file);
        }
        $bytes = (int) filesize($absolute);
        if ($bytes === 0) {
            throw new RuntimeException('La copia de seguridad quedó vacía.');
        }

        return ['path' => $relative, 'sha256' => (string) hash_file('sha256', $absolute), 'bytes' => $bytes];
    }

    /** @return list<string> */
    private function companyTables(): array
    {
        return array_values(array_map(fn (stdClass $row): string => (string) $row->table_name, DB::select(
            "SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'company_id' AND table_name NOT IN ('company_deletions') ORDER BY table_name")));
    }

    /** @return list<array{0: string, 1: string, 2: string}> [child table, child column, parent table] of single-column foreign keys to an id. */
    private function foreignKeys(): array
    {
        return array_values(array_map(fn (stdClass $row): array => [(string) $row->child, (string) $row->child_column, (string) $row->parent], DB::select(<<<'SQL'
            SELECT child.relname AS child, attribute.attname AS child_column, parent.relname AS parent
            FROM pg_constraint constraint_row
            JOIN pg_class child ON child.oid = constraint_row.conrelid
            JOIN pg_class parent ON parent.oid = constraint_row.confrelid
            JOIN pg_attribute attribute ON attribute.attrelid = constraint_row.conrelid AND attribute.attnum = constraint_row.conkey[1]
            JOIN pg_namespace namespace ON namespace.oid = child.relnamespace AND namespace.nspname = current_schema()
            WHERE constraint_row.contype = 'f' AND array_length(constraint_row.conkey, 1) = 1
                AND parent.relname NOT IN ('users', 'organizations', 'offices') AND child.relname <> 'company_deletions'
            ORDER BY child.relname
            SQL)));
    }

    /**
     * In-app notice and e-mail to every owner of the firm, including the one who acted: a deletion must never go unseen.
     *
     * @param  list<string>  $lines
     */
    private function tellOwners(Organization $organization, string $type, string $title, array $lines, int $deletionId): void
    {
        $owners = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organization->id)
            ->where('organization_user.role', 'owner')->where('organization_user.status', 'active')->get(['users.id', 'users.email', 'users.name']);
        foreach ($owners as $owner) {
            $this->notifications->notify($organization->id, (int) $owner->id, null, $type, $title, implode(' ', $lines), 'company_deletions', $deletionId, ['key' => $type.':'.$deletionId.':'.now()->toDateString()]);
            try {
                Mail::to((string) $owner->email)->send(new CompanyDeletionMail($title, $lines, (string) $owner->name));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
