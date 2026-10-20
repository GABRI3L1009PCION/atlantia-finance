<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * What each person wants to be alerted about: kind of alert, channel (in the app and/or email), frequency (immediate,
 * daily or weekly digest, or off), how many days in advance, and extra email recipients. A preference applies to every
 * company of the person unless a company-specific one exists.
 */
class NotificationPreferenceService
{
    public const KINDS = [
        'fiscal_obligation_due' => ['label' => 'Obligaciones fiscales por vencer o vencidas', 'help' => 'IVA, ISR, retenciones y demás obligaciones del calendario fiscal.'],
        'receivable_overdue' => ['label' => 'Facturas por cobrar vencidas', 'help' => 'Clientes con saldos vencidos en las empresas a tu cargo.'],
        'payable_due' => ['label' => 'Facturas por pagar próximas a vencer', 'help' => 'Pagos a proveedores que vencen dentro de los días de aviso.'],
    ];

    public const FREQUENCIES = ['immediate' => 'Inmediata', 'daily' => 'Resumen diario', 'weekly' => 'Resumen semanal (lunes)', 'off' => 'Desactivada'];

    public const DEFAULT = ['in_app' => true, 'email' => false, 'frequency' => 'daily', 'lead_days' => 3, 'recipients' => ''];

    /**
     * The person's preferences by kind (their general ones) and their company-specific overrides.
     *
     * @return array{general: list<array<string, mixed>>, companies: list<array<string, mixed>>}
     */
    public function forUser(Organization $organization, User $user): array
    {
        $rows = DB::table('notification_preferences')->where('organization_id', $organization->id)->where('user_id', $user->id)->get();
        $general = [];
        foreach (self::KINDS as $kind => $definition) {
            $row = $rows->first(fn ($item): bool => $item->kind === $kind && $item->company_id === null);
            $general[] = ['kind' => $kind, ...$definition, ...$this->present($row)];
        }
        $companies = [];
        foreach ($rows->whereNotNull('company_id') as $row) {
            $companies[] = ['kind' => (string) $row->kind, 'company_id' => (int) $row->company_id, 'company' => (string) DB::table('companies')->where('id', $row->company_id)->value('name'), ...$this->present($row)];
        }

        return ['general' => $general, 'companies' => $companies];
    }

    /** @param array{in_app?: bool, email?: bool, frequency?: string, lead_days?: int, recipients?: string|null} $data */
    public function save(Organization $organization, User $user, string $kind, ?int $companyId, array $data): void
    {
        abort_unless(isset(self::KINDS[$kind]), 422);
        $frequency = (string) ($data['frequency'] ?? self::DEFAULT['frequency']);
        abort_unless(isset(self::FREQUENCIES[$frequency]), 422);
        if ($companyId !== null) {
            abort_unless(DB::table('companies')->where('id', $companyId)->where('organization_id', $organization->id)->exists(), 404);
        }
        $recipients = $this->recipients((string) ($data['recipients'] ?? ''));
        $values = ['organization_id' => $organization->id, 'in_app' => (bool) ($data['in_app'] ?? true), 'email' => (bool) ($data['email'] ?? false), 'frequency' => $frequency,
            'lead_days' => max(0, min(60, (int) ($data['lead_days'] ?? self::DEFAULT['lead_days']))), 'recipients' => $recipients === [] ? null : implode(', ', $recipients), 'updated_at' => now()];
        $existing = DB::table('notification_preferences')->where('user_id', $user->id)->where('kind', $kind)->where(fn ($query) => $companyId === null ? $query->whereNull('company_id') : $query->where('company_id', $companyId))->first();
        if ($existing === null) {
            DB::table('notification_preferences')->insert([...$values, 'user_id' => $user->id, 'company_id' => $companyId, 'kind' => $kind, 'created_at' => now()]);
        } else {
            DB::table('notification_preferences')->where('id', $existing->id)->update($values);
        }
    }

    /**
     * The company-specific preference when there is one, otherwise the general one, otherwise the default.
     *
     * @return array{in_app: bool, email: bool, frequency: string, lead_days: int, recipients: list<string>}
     */
    public function effective(int $organizationId, int $userId, string $kind, int $companyId): array
    {
        $rows = DB::table('notification_preferences')->where('organization_id', $organizationId)->where('user_id', $userId)->where('kind', $kind)->get();
        $row = $rows->first(fn ($item): bool => (int) $item->company_id === $companyId) ?? $rows->first(fn ($item): bool => $item->company_id === null);
        $present = $this->present($row);

        return ['in_app' => $present['in_app'], 'email' => $present['email'], 'frequency' => $present['frequency'], 'lead_days' => $present['lead_days'], 'recipients' => $this->recipients((string) $present['recipients'])];
    }

    /** @return list<string> valid, unique, lower-case addresses from a comma or line separated text */
    public function recipients(string $text): array
    {
        $list = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['recipients' => 'El correo «'.$email.'» no es válido.']);
            }
            $list[mb_strtolower($email)] = mb_strtolower($email);
        }

        return array_values($list);
    }

    /** @return array{in_app: bool, email: bool, frequency: string, lead_days: int, recipients: string} */
    private function present(?stdClass $row): array
    {
        return $row === null ? self::DEFAULT : ['in_app' => (bool) $row->in_app, 'email' => (bool) $row->email, 'frequency' => (string) $row->frequency, 'lead_days' => (int) $row->lead_days, 'recipients' => (string) ($row->recipients ?? '')];
    }
}
