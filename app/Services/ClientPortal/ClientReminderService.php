<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Mail\ClientReminderMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\ClientMailIdentityService;
use App\Services\Notifications\WhatsAppService;
use App\Services\WorkManagement\WorkNotificationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use stdClass;
use Throwable;

/**
 * Reminders an office sends to its clients by itself before their deadlines: the office's fee invoices, the documents
 * it asked for and the tax due dates of the client's companies. Each office decides how many days before (for example
 * 5 and 1), through which channels (the client's panel, email, WhatsApp) and about what. A reminder reaches each
 * recipient once per deadline, day before it and channel, so the daily run never repeats a message.
 *
 * @phpstan-type ClientReminderSettings array{enabled: bool, days_before: list<int>, channels: array{in_app: bool, email: bool, whatsapp: bool}, kinds: array{fee_invoices: bool, document_requests: bool, tax_obligations: bool}}
 * @phpstan-type ClientReminderItem array{source_type: string, source_id: int, company_id: int, due_on: string, kind: string, title: string, text: string, permission: string, related_type: string}
 */
class ClientReminderService
{
    public const KINDS = [
        'fee_invoices' => 'Facturas de honorarios de la oficina',
        'document_requests' => 'Documentos e información solicitados al cliente',
        'tax_obligations' => 'Vencimientos de impuestos de sus empresas',
    ];

    public const CHANNELS = ['in_app' => 'Notificación en su panel', 'email' => 'Correo electrónico', 'whatsapp' => 'WhatsApp'];

    /** Days before the deadline used until the office chooses its own. */
    public const DEFAULT_DAYS = [5, 1];

    private const MAX_DAYS = 60;

    private const OPEN_REQUESTS = ['pending', 'in_progress', 'more_information_required'];

    private const CLOSED_OBLIGATIONS = ['cancelled', 'completed', 'filed', 'not_applicable'];

    public function __construct(
        private ClientAccessService $access,
        private WorkNotificationService $notifications,
        private ClientMailIdentityService $identities,
        private WhatsAppService $whatsapp,
    ) {}

    /**
     * The office's settings, or the suggested ones (turned off) when it has not configured them.
     *
     * @return ClientReminderSettings
     */
    public function settings(stdClass $office): array
    {
        $stored = is_string($office->client_reminders ?? null) ? json_decode($office->client_reminders, true) : null;
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'days_before' => $this->days($stored['days_before'] ?? self::DEFAULT_DAYS),
            'channels' => [
                'in_app' => (bool) ($stored['channels']['in_app'] ?? true),
                'email' => (bool) ($stored['channels']['email'] ?? true),
                'whatsapp' => (bool) ($stored['channels']['whatsapp'] ?? false),
            ],
            'kinds' => [
                'fee_invoices' => (bool) ($stored['kinds']['fee_invoices'] ?? true),
                'document_requests' => (bool) ($stored['kinds']['document_requests'] ?? true),
                'tax_obligations' => (bool) ($stored['kinds']['tax_obligations'] ?? true),
            ],
        ];
    }

    /**
     * Saves the office's settings.
     *
     * @param  array{enabled: bool, days_before: list<int|string>, channels: array<string, bool>, kinds: array<string, bool>}  $data
     */
    public function save(int $organizationId, int $officeId, array $data): void
    {
        $settings = [
            'enabled' => $data['enabled'],
            'days_before' => $this->days($data['days_before']),
            'channels' => array_map(fn (string $channel): bool => (bool) ($data['channels'][$channel] ?? false), array_combine(array_keys(self::CHANNELS), array_keys(self::CHANNELS))),
            'kinds' => array_map(fn (string $kind): bool => (bool) ($data['kinds'][$kind] ?? false), array_combine(array_keys(self::KINDS), array_keys(self::KINDS))),
        ];
        DB::table('offices')->where('organization_id', $organizationId)->where('id', $officeId)
            ->update(['client_reminders' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    /**
     * What the settings screen shows: the settings, whether WhatsApp can be used and the latest reminders sent.
     *
     * @return array{settings: ClientReminderSettings, whatsapp_available: bool, recent: list<array{company: string, channel: string, recipient: string, status: string, error: string|null, due_on: string, days_before: int, sent_at: string}>}
     */
    public function overview(stdClass $office): array
    {
        $recent = DB::table('client_reminder_deliveries as d')->join('companies', 'companies.id', '=', 'd.company_id')
            ->where('d.organization_id', $office->organization_id)->where('companies.office_id', $office->id)
            ->orderByDesc('d.id')->limit(15)->get(['companies.name', 'd.channel', 'd.recipient', 'd.status', 'd.error', 'd.due_on', 'd.days_before', 'd.created_at']);

        return [
            'settings' => $this->settings($office),
            'whatsapp_available' => $this->whatsapp->configured(),
            'recent' => array_values($recent->map(fn (stdClass $row): array => [
                'company' => (string) $row->name, 'channel' => (string) $row->channel, 'recipient' => (string) $row->recipient, 'status' => (string) $row->status,
                'error' => $row->error === null ? null : (string) $row->error, 'due_on' => (string) $row->due_on, 'days_before' => (int) $row->days_before,
                'sent_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * Sends today's reminders of every office of the organization that turned them on. Returns how many were sent.
     */
    public function sweep(Organization $organization, ?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse(($today ?? now('America/Guatemala'))->toDateString());
        $sent = 0;
        $offices = DB::table('offices')->where('organization_id', $organization->id)->whereNull('deleted_at')->where('status', 'active')->whereNotNull('client_reminders')->get();

        foreach ($offices as $office) {
            $settings = $this->settings($office);
            if (! $settings['enabled'] || $settings['days_before'] === []) {
                continue;
            }
            $companyIds = DB::table('companies')->where('organization_id', $organization->id)->where('office_id', $office->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($companyIds === []) {
                continue;
            }
            $dates = [];
            foreach ($settings['days_before'] as $days) {
                $dates[$today->addDays($days)->toDateString()] = $days;
            }
            foreach ($this->items($settings, $companyIds, array_keys($dates)) as $item) {
                $sent += $this->deliver($organization, $settings, $item, $dates[$item['due_on']]);
            }
        }

        return $sent;
    }

    /**
     * The deadlines that fall on one of the reminder dates.
     *
     * @param  ClientReminderSettings  $settings
     * @param  list<int>  $companyIds
     * @param  list<string>  $dates
     * @return list<ClientReminderItem>
     */
    private function items(array $settings, array $companyIds, array $dates): array
    {
        $items = [];
        if ($settings['kinds']['fee_invoices']) {
            foreach (DB::table('crm_invoices')->whereIn('company_id', $companyIds)->where('status', 'pending')->whereIn('due_on', $dates)->get() as $invoice) {
                $due = $this->date((string) $invoice->due_on);
                $items[] = ['source_type' => 'crm_invoice', 'source_id' => (int) $invoice->id, 'company_id' => (int) $invoice->company_id, 'due_on' => (string) $invoice->due_on,
                    'kind' => 'fee_invoices', 'title' => 'Tu factura '.$invoice->number.' vence el '.$due,
                    'text' => 'La factura de honorarios '.$invoice->number.' ('.$invoice->concept.') por Q'.number_format(((int) $invoice->amount_cents) / 100, 2).' vence el '.$due.'.',
                    'permission' => 'client.account.view', 'related_type' => 'crm_invoices'];
            }
        }
        if ($settings['kinds']['document_requests']) {
            foreach (DB::table('client_requests')->whereIn('company_id', $companyIds)->whereIn('status', self::OPEN_REQUESTS)->whereIn('due_date', $dates)->get() as $request) {
                $due = $this->date((string) $request->due_date);
                $items[] = ['source_type' => 'client_request', 'source_id' => (int) $request->id, 'company_id' => (int) $request->company_id, 'due_on' => (string) $request->due_date,
                    'kind' => 'document_requests', 'title' => 'Pendiente para el '.$due.': '.$request->title,
                    'text' => 'Tu oficina contable necesita «'.$request->title.'» a más tardar el '.$due.'. Puedes enviarlo desde tu portal.',
                    'permission' => 'client.document_requests.view', 'related_type' => 'client_requests'];
            }
        }
        if ($settings['kinds']['tax_obligations']) {
            foreach (DB::table('fiscal_obligations')->whereIn('company_id', $companyIds)->whereNull('filed_on')->whereNotIn('status', self::CLOSED_OBLIGATIONS)->whereIn('due_on', $dates)->get() as $obligation) {
                $due = $this->date((string) $obligation->due_on);
                $items[] = ['source_type' => 'fiscal_obligation', 'source_id' => (int) $obligation->id, 'company_id' => (int) $obligation->company_id, 'due_on' => (string) $obligation->due_on,
                    'kind' => 'tax_obligations', 'title' => $obligation->name.' vence el '.$due,
                    'text' => 'El '.$due.' vence '.$obligation->name.' ('.$obligation->reference_period.'). Ten listos los fondos y la información que tu oficina contable te pidió.',
                    'permission' => 'client.obligations.view', 'related_type' => 'client_obligations'];
            }
        }

        return $items;
    }

    /**
     * Sends one deadline through every channel turned on. Returns how many messages went out.
     *
     * @param  ClientReminderSettings  $settings
     * @param  ClientReminderItem  $item
     */
    private function deliver(Organization $organization, array $settings, array $item, int $daysBefore): int
    {
        $sent = 0;
        $company = DB::table('companies')->where('id', $item['company_id'])->first(['id', 'name', 'phone']);
        if ($company === null) {
            return 0;
        }
        $when = $daysBefore === 0 ? 'hoy' : ($daysBefore === 1 ? 'mañana' : 'en '.$daysBefore.' días');
        $text = 'Recordatorio para '.$company->name.' (vence '.$when.'): '.$item['text'];
        $contacts = array_values(array_filter($this->access->contacts($item['company_id']), function (array $contact) use ($organization, $item): bool {
            $user = User::query()->find($contact['id']);

            return $user instanceof User && $this->access->can($organization, $user, $item['company_id'], $item['permission']);
        }));

        foreach ($contacts as $contact) {
            if ($settings['channels']['in_app'] && $this->claim($organization, $item, $daysBefore, 'in_app', 'user:'.$contact['id'])) {
                $this->notifications->notify($organization->id, $contact['id'], null, 'client.reminder', $item['title'], $text, $item['related_type'], $item['source_id'], ['company_id' => $item['company_id']]);
                $sent++;
            }
            $email = $settings['channels']['email'] ? DB::table('users')->where('id', $contact['id'])->value('email') : null;
            if (is_string($email) && $email !== '' && $this->claim($organization, $item, $daysBefore, 'email', mb_strtolower($email))) {
                $sent += $this->finish($item, $daysBefore, 'email', mb_strtolower($email), function () use ($organization, $item, $email, $text): ?string {
                    Mail::to($email)->send(new ClientReminderMail($item['title'], $text, $this->identities->identity($organization->id)));

                    return null;
                });
            }
        }

        $phone = $this->whatsapp->normalize($company->phone === null ? null : (string) $company->phone);
        if ($settings['channels']['whatsapp'] && $contacts !== [] && $phone !== null && $this->whatsapp->configured() && $this->claim($organization, $item, $daysBefore, 'whatsapp', $phone)) {
            $sent += $this->finish($item, $daysBefore, 'whatsapp', $phone, fn (): ?string => $this->whatsapp->sendTemplate($phone, $text));
        }

        return $sent;
    }

    /**
     * Logs the reminder before sending it; false when it was already sent, so concurrent or repeated runs skip it.
     *
     * @param  ClientReminderItem  $item
     */
    private function claim(Organization $organization, array $item, int $daysBefore, string $channel, string $recipient): bool
    {
        return DB::table('client_reminder_deliveries')->insertOrIgnore([
            'organization_id' => $organization->id, 'company_id' => $item['company_id'], 'source_type' => $item['source_type'], 'source_id' => $item['source_id'],
            'due_on' => $item['due_on'], 'days_before' => $daysBefore, 'channel' => $channel, 'recipient' => $recipient, 'status' => 'sent',
            'created_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    /**
     * Runs the sending and marks the logged reminder as failed when it did not go out. Returns 1 when it was sent.
     *
     * @param  ClientReminderItem  $item
     * @param  callable(): ?string  $send  returns null when sent, or the reason it failed
     */
    private function finish(array $item, int $daysBefore, string $channel, string $recipient, callable $send): int
    {
        try {
            $error = $send();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
        if ($error === null) {
            return 1;
        }
        DB::table('client_reminder_deliveries')->where('source_type', $item['source_type'])->where('source_id', $item['source_id'])->where('due_on', $item['due_on'])
            ->where('days_before', $daysBefore)->where('channel', $channel)->where('recipient', $recipient)
            ->update(['status' => 'failed', 'error' => mb_substr($error, 0, 255), 'updated_at' => now()]);

        return 0;
    }

    /**
     * @param  mixed  $value  the days the office typed
     * @return list<int>
     */
    private function days(mixed $value): array
    {
        $days = array_map('intval', is_array($value) ? $value : []);
        $days = array_values(array_unique(array_filter($days, fn (int $day): bool => $day >= 0 && $day <= self::MAX_DAYS)));
        rsort($days);

        return array_slice($days, 0, 5);
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }
}
