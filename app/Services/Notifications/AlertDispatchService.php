<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\AlertDigestMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Generates the alerts of fiscal obligations, receivables and payables for each person according to their preferences:
 * in the app and/or by email, immediately or as a daily/weekly digest, with extra recipients. Every alert reaches a
 * person once per window and channel, so running it every hour never repeats a message.
 */
class AlertDispatchService
{
    private const DIGEST_HOUR = 7;

    public function __construct(
        private TenantDatabaseContext $tenancy,
        private NotificationPreferenceService $preferences,
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
        private ReceivableService $receivables,
        private PayableService $payables,
    ) {}

    /** @return array{notifications: int, emails: int} */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now('America/Guatemala');
        $totals = ['notifications' => 0, 'emails' => 0];
        $this->tenancy->runAsSystem(function () use ($now, &$totals): void {
            foreach (Organization::query()->where('status', 'active')->get() as $organization) {
                $result = $this->organization($organization, $now);
                $totals['notifications'] += $result['notifications'];
                $totals['emails'] += $result['emails'];
            }
        });

        return $totals;
    }

    /** @return array{notifications: int, emails: int} */
    public function organization(Organization $organization, CarbonInterface $now): array
    {
        $totals = ['notifications' => 0, 'emails' => 0];
        foreach ($organization->users()->wherePivot('status', 'active')->get() as $user) {
            $member = $user->organizations()->where('organizations.id', $organization->id)->first();
            if ($member === null) {
                continue;
            }
            $companies = $this->accounting->companies($member, $user)->get(['companies.id', 'companies.name'])->keyBy('id');
            if ($companies->isEmpty()) {
                continue;
            }
            $result = $this->forUser($organization, $user, $companies->map(fn ($company): string => (string) $company->name)->all(), $now);
            $totals['notifications'] += $result['notifications'];
            $totals['emails'] += $result['emails'];
        }

        return $totals;
    }

    /**
     * @param  array<int|string, string>  $companyNames  company id => name the person can access
     * @return array{notifications: int, emails: int}
     */
    private function forUser(Organization $organization, User $user, array $companyNames, CarbonInterface $now): array
    {
        $result = ['notifications' => 0, 'emails' => 0];
        $emailItems = [];
        $extra = [];
        foreach (array_keys(NotificationPreferenceService::KINDS) as $kind) {
            // Items per company, each with the effective preference of that company.
            $due = [];
            foreach ($companyNames as $companyId => $companyName) {
                $preference = $this->preferences->effective($organization->id, $user->id, $kind, (int) $companyId);
                if ($preference['frequency'] === 'off' || (! $preference['in_app'] && ! $preference['email'])) {
                    continue;
                }
                foreach ($this->items($kind, (int) $companyId, (string) $companyName, $preference['lead_days'], $now) as $item) {
                    $due[] = ['item' => $item, 'preference' => $preference];
                }
            }
            if ($due === []) {
                continue;
            }
            foreach ($this->windows($due, $now) as $group) {
                $summary = $group['items'];
                if ($group['in_app'] && $group['digest']) {
                    if ($this->deliver($organization, $user, $kind, $group['reference'], 'in_app', $group['window'], true)) {
                        $this->notify($organization, $user, $kind, $summary);
                        $result['notifications']++;
                    }
                } elseif ($group['in_app']) {
                    foreach ($summary as $item) {
                        if ($this->deliver($organization, $user, $kind, $item['reference'], 'in_app', $group['window'], true)) {
                            $this->notify($organization, $user, $kind, [$item]);
                            $result['notifications']++;
                        }
                    }
                }
                if ($group['email']) {
                    $fresh = $group['digest'] ? ($this->deliver($organization, $user, $kind, $group['reference'], 'email', $group['window'], true) ? $summary : [])
                        : array_values(array_filter($summary, fn (array $item): bool => $this->deliver($organization, $user, $kind, $item['reference'], 'email', $group['window'], true)));
                    $emailItems = [...$emailItems, ...$fresh];
                    $extra = [...$extra, ...($fresh === [] ? [] : $group['recipients'])];
                }
            }
        }
        if ($emailItems !== []) {
            $result['emails'] += $this->email($user, $emailItems, array_values(array_unique($extra)));
        }

        return $result;
    }

    /**
     * Splits due items into delivery groups by frequency: immediate items go one by one, digests are one message.
     *
     * @param  list<array{item: array{reference: string, kind: string, title: string, detail: string}, preference: array{in_app: bool, email: bool, frequency: string, lead_days: int, recipients: list<string>}}>  $due
     * @return list<array{digest: bool, window: string, in_app: bool, email: bool, recipients: list<string>, items: list<array{reference: string, kind: string, title: string, detail: string}>, reference: string}>
     */
    private function windows(array $due, CarbonInterface $now): array
    {
        $groups = [];
        foreach ($due as $entry) {
            $preference = $entry['preference'];
            $frequency = (string) $preference['frequency'];
            if ($frequency === 'daily' && $now->hour < self::DIGEST_HOUR) {
                continue;
            }
            if ($frequency === 'weekly' && ($now->dayOfWeekIso !== 1 || $now->hour < self::DIGEST_HOUR)) {
                continue;
            }
            $key = $frequency.'|'.(int) $preference['in_app'].(int) $preference['email'].'|'.implode(',', $preference['recipients']);
            $groups[$key] ??= ['digest' => $frequency !== 'immediate', 'window' => $frequency === 'weekly' ? $now->format('o-\WW') : $now->toDateString(), 'in_app' => (bool) $preference['in_app'],
                'email' => (bool) $preference['email'], 'recipients' => $preference['recipients'], 'items' => [], 'reference' => 'digest:'.$key];
            $groups[$key]['items'][] = $entry['item'];
        }

        return array_values($groups);
    }

    /** Records the delivery; false when the same alert already reached this person in the window. */
    private function deliver(Organization $organization, User $user, string $kind, string $reference, string $channel, string $window, bool $wanted): bool
    {
        if (! $wanted) {
            return false;
        }

        return DB::table('alert_deliveries')->insertOrIgnore(['organization_id' => $organization->id, 'user_id' => $user->id, 'kind' => $kind, 'reference' => mb_substr($reference, 0, 120), 'channel' => $channel,
            'window_key' => $window, 'created_at' => now(), 'updated_at' => now()]) > 0;
    }

    /** @param list<array<string, string>> $items */
    private function notify(Organization $organization, User $user, string $kind, array $items): void
    {
        $label = NotificationPreferenceService::KINDS[$kind]['label'];
        $this->notifications->notify($organization->id, $user->id, null, 'alert.'.$kind, count($items) === 1 ? $items[0]['title'] : $label.' ('.count($items).')',
            count($items) === 1 ? $items[0]['detail'] : implode(' · ', array_slice(array_column($items, 'title'), 0, 3)).(count($items) > 3 ? ' y '.(count($items) - 3).' más' : ''));
    }

    /**
     * @param  list<array<string, string>>  $items
     * @param  list<string>  $extra
     */
    private function email(User $user, array $items, array $extra): int
    {
        $sent = 0;
        foreach (array_values(array_unique([$user->email, ...$extra])) as $address) {
            try {
                Mail::to($address)->send(new AlertDigestMail('Alertas de Atlantia Finance ('.count($items).')', (string) $user->name, array_map(fn (array $item): array => ['kind' => $item['kind'], 'title' => $item['title'], 'detail' => $item['detail']], $items)));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    /** @return list<array{reference: string, kind: string, title: string, detail: string}> */
    private function items(string $kind, int $companyId, string $companyName, int $leadDays, CarbonInterface $now): array
    {
        $today = $now->toDateString();
        $items = [];
        if ($kind === 'fiscal_obligation_due') {
            $rows = DB::table('fiscal_obligations')->where('company_id', $companyId)->whereNull('filed_on')->whereNotIn('status', ['cancelled', 'completed', 'filed', 'not_applicable'])
                ->where('due_on', '<=', $now->copy()->addDays($leadDays)->toDateString())->orderBy('due_on')->limit(50)->get(['id', 'name', 'reference_period', 'due_on']);
            foreach ($rows as $row) {
                $late = $row->due_on < $today;
                $items[] = ['reference' => 'obligation:'.$row->id, 'kind' => $kind, 'title' => ($late ? 'Vencida: ' : 'Por vencer: ').$row->name.' '.$row->reference_period.' · '.$companyName,
                    'detail' => 'Vence el '.$row->due_on.($late ? ' (ya venció)' : '').'.'];
            }
        } elseif ($kind === 'receivable_overdue') {
            foreach (array_slice($this->receivables->documents([$companyId], ['status' => 'overdue']), 0, 50) as $row) {
                $items[] = ['reference' => 'ar:'.$row['id'], 'kind' => $kind, 'title' => 'Cobro vencido: '.$row['customer'].' · '.$companyName,
                    'detail' => 'Documento '.$row['document'].' por Q '.number_format($row['outstanding_cents'] / 100, 2).' vencido hace '.$row['overdue_days'].' días.'];
            }
        } else {
            foreach (array_slice($this->payables->documents([$companyId], ['status' => 'upcoming', 'due_within' => $leadDays]), 0, 50) as $row) {
                $items[] = ['reference' => 'ap:'.$row['id'], 'kind' => $kind, 'title' => 'Pago por vencer: '.$row['supplier'].' · '.$companyName,
                    'detail' => 'Documento '.$row['document'].' por Q '.number_format($row['outstanding_cents'] / 100, 2).' vence el '.$row['due_date'].'.'];
            }
        }

        return $items;
    }
}
