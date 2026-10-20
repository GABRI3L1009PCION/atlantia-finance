<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Hours the team spends on each client company. They feed the profitability of the firm: hours against what the contract
 * budgeted, and their internal cost against the fee. A person records only their own time, on companies they can reach.
 */
class TimeTrackingService
{
    public const ACTIVITIES = ['registro' => 'Registro y clasificación', 'conciliacion' => 'Conciliación', 'fiscal' => 'Obligaciones fiscales', 'revision' => 'Revisión', 'cierre' => 'Cierre', 'cliente' => 'Atención al cliente', 'otro' => 'Otro'];

    /** Days back a person may still record or fix their own hours. */
    public const WINDOW_DAYS = 45;

    public function __construct(private AccountingService $accounting) {}

    /**
     * @param  array{date: string, hours: string, activity?: string|null, note?: string|null}  $data
     */
    public function record(Organization $organization, User $user, stdClass $company, array $data): int
    {
        $minutes = $this->minutes($data['hours']);
        if ($data['date'] > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'No puedes registrar horas de una fecha futura.']);
        }
        if ($data['date'] < now()->subDays(self::WINDOW_DAYS)->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Solo puedes registrar horas de los últimos '.self::WINDOW_DAYS.' días.']);
        }

        return DB::transaction(function () use ($organization, $user, $company, $data, $minutes): int {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $already = (int) DB::table('time_entries')->where('user_id', $user->id)->where('work_date', $data['date'])->sum('minutes');
            if ($already + $minutes > 1440) {
                throw ValidationException::withMessages(['hours' => 'Con esto superas las 24 horas de ese día.']);
            }
            $cost = (int) DB::table('offices')->where('id', $company->office_id)->value('hourly_cost_cents');

            return (int) DB::table('time_entries')->insertGetId(['organization_id' => $organization->id, 'office_id' => $company->office_id, 'company_id' => $company->id, 'user_id' => $user->id, 'work_date' => $data['date'],
                'minutes' => $minutes, 'activity' => filled($data['activity'] ?? null) ? $data['activity'] : null, 'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
                'cost_per_hour_cents' => $cost, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function remove(User $user, int $entryId, bool $anyone = false): void
    {
        $entry = DB::table('time_entries')->where('id', $entryId)->first();
        if ($entry === null || (! $anyone && (int) $entry->user_id !== $user->id)) {
            abort(404);
        }
        if (! $anyone && $entry->work_date < now()->subDays(self::WINDOW_DAYS)->toDateString()) {
            throw ValidationException::withMessages(['entry' => 'Ese registro ya no se puede eliminar.']);
        }
        DB::table('time_entries')->where('id', $entryId)->delete();
    }

    /**
     * The person's own entries in a range, with their companies, and the total.
     *
     * @return array{entries: list<array<string, mixed>>, total_minutes: int}
     */
    public function mine(User $user, string $from, string $to): array
    {
        $rows = DB::table('time_entries as t')->join('companies as c', 'c.id', '=', 't.company_id')->where('t.user_id', $user->id)->whereBetween('t.work_date', [$from, $to])
            ->orderByDesc('t.work_date')->orderByDesc('t.id')->get(['t.id', 't.work_date', 't.minutes', 't.activity', 't.note', 'c.name as company']);

        return ['entries' => array_values($rows->map(fn (object $row): array => ['id' => (int) $row->id, 'date' => (string) $row->work_date, 'minutes' => (int) $row->minutes, 'activity' => $row->activity === null ? null : (self::ACTIVITIES[$row->activity] ?? $row->activity),
            'note' => $row->note, 'company' => (string) $row->company])->all()), 'total_minutes' => (int) $rows->sum('minutes')];
    }

    /**
     * Companies the person can record hours on: the ones they reach in accounting.
     *
     * @return list<array{id: int, public_id: string, name: string}>
     */
    public function companies(Organization $organization, User $user): array
    {
        return array_values($this->accounting->companies($organization, $user)->orderBy('companies.name')->get(['companies.id', 'companies.public_id', 'companies.name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'name' => (string) $row->name])->all());
    }

    private function minutes(string $hours): int
    {
        $clean = str_replace(',', '.', trim($hours));
        if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $clean)) {
            throw ValidationException::withMessages(['hours' => 'Escribe las horas con hasta 2 decimales, por ejemplo 1.5.']);
        }
        $minutes = (int) round((float) $clean * 60);
        if ($minutes < 1 || $minutes > 960) {
            throw ValidationException::withMessages(['hours' => 'Cada registro debe estar entre 1 minuto y 16 horas.']);
        }

        return $minutes;
    }
}
