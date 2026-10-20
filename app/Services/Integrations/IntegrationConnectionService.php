<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Luzma\LuzmaSwitch;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkDashboardService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Connections an office makes between its firm and outside tools. Each office brings its own address or token, so
 * nothing here depends on an account of Atlantia with those vendors. Secrets are stored encrypted and never returned.
 */
class IntegrationConnectionService
{
    /**
     * Providers that work with what the office pastes in. `fields` drives the connect form of the screen.
     *
     * @var array<string, array{label: string, category: string, description: string, oauth?: bool, builtin?: bool, fields: list<array{key: string, label: string, type: string, secret?: bool, required?: bool, placeholder?: string, options?: list<string>}>}>
     */
    public const PROVIDERS = [
        'slack' => [
            'label' => 'Slack', 'category' => 'Comunicación', 'description' => 'Avisos de tareas, cierres y obligaciones en un canal de Slack.',
            'fields' => [
                ['key' => 'webhook_url', 'label' => 'URL del webhook entrante', 'type' => 'url', 'secret' => true, 'required' => true, 'placeholder' => 'https://hooks.slack.com/services/…'],
                ['key' => 'notify', 'label' => 'Enviar los avisos de la firma al canal', 'type' => 'checkbox'],
            ],
        ],
        'teams' => [
            'label' => 'Microsoft Teams', 'category' => 'Comunicación', 'description' => 'Avisos de la firma en un canal de Teams.',
            'fields' => [
                ['key' => 'webhook_url', 'label' => 'URL del webhook del canal (Workflows o conector)', 'type' => 'url', 'secret' => true, 'required' => true, 'placeholder' => 'https://…webhook.office.com/… o …logic.azure.com/…'],
                ['key' => 'notify', 'label' => 'Enviar los avisos de la firma al canal', 'type' => 'checkbox'],
            ],
        ],
        'hubspot' => [
            'label' => 'HubSpot', 'category' => 'CRM', 'description' => 'Envía a HubSpot los prospectos de la firma como contactos.',
            'fields' => [['key' => 'access_token', 'label' => 'Token de acceso de app privada', 'type' => 'password', 'secret' => true, 'required' => true, 'placeholder' => 'pat-…']],
        ],
        'calendar' => [
            'label' => 'Calendario (Google / Outlook)', 'category' => 'Productividad', 'description' => 'Enlace de calendario privado con vencimientos, tareas y cierres.',
            'fields' => [],
        ],
        'google_drive' => [
            'label' => 'Google Drive', 'category' => 'Almacenamiento', 'description' => 'Respalda informes de la firma en tu Google Drive.', 'oauth' => true,
            'fields' => [
                ['key' => 'client_id', 'label' => 'ID de cliente (de tu app)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Secreto de cliente', 'type' => 'password', 'secret' => true, 'required' => true],
            ],
        ],
        'dropbox' => [
            'label' => 'Dropbox', 'category' => 'Almacenamiento', 'description' => 'Respalda informes de la firma en tu Dropbox.', 'oauth' => true,
            'fields' => [
                ['key' => 'client_id', 'label' => 'ID de cliente (de tu app)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Secreto de cliente', 'type' => 'password', 'secret' => true, 'required' => true],
            ],
        ],
        'gmail' => [
            'label' => 'Gmail / Google Workspace', 'category' => 'Comunicación', 'description' => 'Envía los avisos de la firma desde tu cuenta de Gmail.', 'oauth' => true,
            'fields' => [
                ['key' => 'client_id', 'label' => 'ID de cliente (de tu app)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Secreto de cliente', 'type' => 'password', 'secret' => true, 'required' => true],
                ['key' => 'notify', 'label' => 'Enviar por correo los avisos de la firma a cada persona', 'type' => 'checkbox'],
            ],
        ],
        'outlook' => [
            'label' => 'Microsoft Outlook', 'category' => 'Comunicación', 'description' => 'Envía los avisos de la firma desde tu cuenta de Outlook.', 'oauth' => true,
            'fields' => [
                ['key' => 'client_id', 'label' => 'ID de cliente (de tu app)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Secreto de cliente', 'type' => 'password', 'secret' => true, 'required' => true],
                ['key' => 'notify', 'label' => 'Enviar por correo los avisos de la firma a cada persona', 'type' => 'checkbox'],
            ],
        ],
        'luzma' => [
            'label' => 'Luzma IA', 'category' => 'Inteligencia', 'description' => 'Asistente de análisis con los datos de la firma. Decide si tu equipo puede usarlo.', 'builtin' => true,
            'fields' => [
                ['key' => 'enabled', 'label' => 'Permitir que el equipo de la firma use Luzma IA', 'type' => 'checkbox'],
            ],
        ],
        'smtp' => [
            'label' => 'Correo de la firma (SMTP)', 'category' => 'Comunicación', 'description' => 'Envía los avisos desde el servidor de correo de la firma (Gmail, Outlook u otro).',
            'fields' => [
                ['key' => 'host', 'label' => 'Servidor SMTP', 'type' => 'text', 'required' => true, 'placeholder' => 'smtp.gmail.com'],
                ['key' => 'port', 'label' => 'Puerto', 'type' => 'number', 'required' => true, 'placeholder' => '587'],
                ['key' => 'encryption', 'label' => 'Cifrado', 'type' => 'select', 'options' => ['tls', 'ssl', 'ninguno']],
                ['key' => 'username', 'label' => 'Usuario', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Contraseña o clave de aplicación', 'type' => 'password', 'secret' => true, 'required' => true],
                ['key' => 'from_address', 'label' => 'Correo remitente', 'type' => 'email', 'required' => true],
                ['key' => 'from_name', 'label' => 'Nombre del remitente', 'type' => 'text'],
                ['key' => 'notify', 'label' => 'Enviar por correo los avisos de la firma a cada persona', 'type' => 'checkbox'],
            ],
        ],
    ];

    public function __construct(private WebhookUrlGuard $guard, private AccountingService $accounting, private TenantDatabaseContext $tenancy) {}

    /**
     * @return array<string, array{connected: bool, status: string|null, last_synced_at: string|null, last_error: string|null, values: array<string, mixed>, calendar_url: string|null}>
     */
    public function states(Organization $organization): array
    {
        $rows = DB::table('integration_connections')->where('organization_id', $organization->id)->get()->keyBy('provider');
        $states = [];

        foreach (self::PROVIDERS as $provider => $definition) {
            $row = $rows->get($provider);
            $config = $row ? $this->decode($row->config) : [];
            $values = [];

            foreach ($definition['fields'] as $field) {
                $value = $config[$field['key']] ?? ($field['type'] === 'checkbox' ? $provider === 'luzma' : '');
                $values[$field['key']] = ($field['secret'] ?? false) ? ($value === '' ? '' : '••••'.mb_substr((string) $value, -4)) : $value;
            }

            $states[$provider] = [
                'connected' => $provider === 'luzma' ? $this->luzmaEnabled($organization) : ($row !== null && $row->status === 'connected'),
                'status' => $row->status ?? null,
                'last_synced_at' => $row->last_synced_at ?? null,
                'last_error' => $row->last_error ?? null,
                'values' => $values,
                'calendar_url' => $provider === 'calendar' && $row ? url('/calendar/'.($config['token'] ?? '').'.ics') : null,
                'builtin' => (bool) ($definition['builtin'] ?? false),
                'oauth' => (bool) ($definition['oauth'] ?? false),
                'authorized' => ! empty($config['access_token']),
                'redirect_uri' => ($definition['oauth'] ?? false) ? route('integrations.oauth.callback', $provider) : null,
                'console' => ($definition['oauth'] ?? false) ? (IntegrationOAuthService::PROVIDERS[$provider]['console'] ?? null) : null,
            ];
        }

        return $states;
    }

    /** @param array<string, mixed> $input */
    public function connect(Organization $organization, User $actor, string $provider, array $input): void
    {
        $definition = self::PROVIDERS[$provider] ?? abort(404);
        $existing = DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->first();
        $previous = $existing ? $this->decode($existing->config) : [];
        $config = [];

        foreach ($definition['fields'] as $field) {
            $value = $input[$field['key']] ?? null;
            $isMasked = is_string($value) && str_starts_with($value, '••••');
            $value = ($field['secret'] ?? false) && ($value === null || $value === '' || $isMasked) ? ($previous[$field['key']] ?? null) : $value;

            if (($field['required'] ?? false) && ($value === null || $value === '')) {
                throw ValidationException::withMessages([$field['key'] => 'Este dato es obligatorio.']);
            }

            $config[$field['key']] = $field['type'] === 'checkbox' ? (bool) $value : $value;
        }

        $this->assertValid($provider, $config);
        $oauth = (bool) ($definition['oauth'] ?? false);

        if ($oauth && ($previous['client_id'] ?? null) === ($config['client_id'] ?? null)) {
            $config = [...$config, ...array_intersect_key($previous, array_flip(['access_token', 'refresh_token', 'expires_at', 'authorized_at']))];
        }

        $token = null;

        if ($provider === 'calendar') {
            $token = $previous['token'] ?? Str::random(40);
            $config['token'] = $token;
        }

        $payload = ['config' => Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR)), 'status' => $oauth && empty($config['access_token']) ? 'pending' : 'connected', 'last_error' => null, 'updated_at' => now()];

        if ($token !== null) {
            $payload['public_token_hash'] = hash('sha256', $token);
        }

        if ($existing) {
            DB::table('integration_connections')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('integration_connections')->insert([...$payload, 'organization_id' => $organization->id, 'provider' => $provider, 'created_by' => $actor->id, 'created_at' => now()]);
        }

        $this->accounting->audit($organization, $actor, 'integration.connected', 'integration_connections', (int) DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->value('id'), ['provider' => $provider]);
    }

    /** Sends a real test to the tool; records the outcome and returns a message for the screen. */
    public function test(Organization $organization, User $actor, string $provider): string
    {
        $config = $this->config($organization, $provider) ?? abort(404);

        try {
            $message = match ($provider) {
                'slack', 'teams' => $this->postToChat($provider, $config, 'Atlantia Finance', 'La conexión con '.$organization->name.' funciona. Aquí llegarán los avisos de la firma.'),
                'hubspot' => $this->testHubspot($config),
                'smtp' => $this->sendTestMail($organization, $config, $actor),
                'calendar' => 'El enlace de calendario está activo.',
                'google_drive', 'dropbox', 'gmail', 'outlook' => app(IntegrationOAuthService::class)->test($organization, $provider),
                default => abort(404),
            };
            $this->record($organization, $provider, 'test', true, $message);
        } catch (Throwable $exception) {
            $this->record($organization, $provider, 'test', false, mb_substr($exception->getMessage(), 0, 280));
            DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->update(['status' => 'error', 'last_error' => mb_substr($exception->getMessage(), 0, 280), 'updated_at' => now()]);

            throw ValidationException::withMessages(['test' => 'La prueba falló: '.mb_substr($exception->getMessage(), 0, 200)]);
        }

        DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->update(['status' => 'connected', 'last_error' => null, 'last_synced_at' => now(), 'updated_at' => now()]);

        return $message;
    }

    public function disconnect(Organization $organization, User $actor, string $provider): void
    {
        $id = DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->value('id');
        abort_if($id === null, 404);
        DB::table('integration_connections')->where('id', $id)->delete();
        $this->record($organization, $provider, 'disconnected', true, 'Integración desconectada.');
        $this->accounting->audit($organization, $actor, 'integration.disconnected', 'integration_connections', (int) $id, ['provider' => $provider]);
    }

    /** Renews the private calendar link; the old one stops working. */
    public function regenerateCalendar(Organization $organization, User $actor): void
    {
        abort_unless(DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', 'calendar')->exists(), 404);
        $config = $this->config($organization, 'calendar') ?? [];
        $config['token'] = Str::random(40);
        DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', 'calendar')
            ->update(['config' => Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR)), 'public_token_hash' => hash('sha256', $config['token']), 'updated_at' => now()]);
        $this->record($organization, 'calendar', 'regenerated', true, 'Se generó un enlace nuevo.');
    }

    /** Pushes the firm's open prospects to HubSpot as contacts (matched by email). */
    public function syncHubspot(Organization $organization, User $actor): string
    {
        $config = $this->config($organization, 'hubspot') ?? abort(404);
        $inputs = DB::table('crm_prospects')->where('organization_id', $organization->id)->whereNotIn('stage', ['lost'])->whereNotNull('contact_email')->get(['name', 'contact_name', 'contact_email', 'contact_phone'])
            ->map(fn (object $row): array => ['id' => $row->contact_email, 'idProperty' => 'email', 'properties' => array_filter(['email' => $row->contact_email, 'firstname' => $row->contact_name ?? $row->name, 'company' => $row->name, 'phone' => $row->contact_phone])])->values()->all();

        if ($inputs === []) {
            throw ValidationException::withMessages(['sync' => 'No hay prospectos con correo para enviar a HubSpot.']);
        }

        $response = Http::withToken((string) $config['access_token'])->acceptJson()->timeout(15)->post('https://api.hubapi.com/crm/v3/objects/contacts/batch/upsert', ['inputs' => $inputs]);

        if ($response->failed()) {
            $this->record($organization, 'hubspot', 'sync', false, 'HubSpot respondió '.$response->status().'.');

            throw ValidationException::withMessages(['sync' => 'HubSpot rechazó la sincronización ('.$response->status().').']);
        }

        $this->record($organization, 'hubspot', 'sync', true, count($inputs).' contacto(s) enviados.');
        DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', 'hubspot')->update(['last_synced_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'integration.hubspot_synced', 'integration_connections', 0, ['contacts' => count($inputs)]);

        return count($inputs).' contacto(s) enviados a HubSpot.';
    }

    /** Sends an office notice to the connected chat channels and, if enabled, by the firm's own mail. Never throws. */
    public function broadcast(int $organizationId, ?int $recipientId, string $title, ?string $body): void
    {
        try {
            $this->tenancy->runAsSystem(function () use ($organizationId, $recipientId, $title, $body): void {
                foreach (DB::table('integration_connections')->where('organization_id', $organizationId)->where('status', 'connected')->whereIn('provider', ['slack', 'teams', 'smtp', 'gmail', 'outlook'])->get() as $row) {
                    $config = $this->decode($row->config);

                    if (! ($config['notify'] ?? false)) {
                        continue;
                    }

                    try {
                        if (in_array($row->provider, ['gmail', 'outlook'], true)) {
                            $email = $recipientId ? DB::table('users')->where('id', $recipientId)->value('email') : null;

                            if ($email) {
                                app(IntegrationOAuthService::class)->sendMail(Organization::query()->findOrFail($organizationId), $row->provider, $email, $title, (string) ($body ?: $title));
                            }
                        } elseif ($row->provider === 'smtp') {
                            $email = $recipientId ? DB::table('users')->where('id', $recipientId)->value('email') : null;

                            if ($email) {
                                $this->mailer($config)->raw(($body ?: $title), fn ($message) => $message->to($email)->subject($title));
                            }
                        } else {
                            $this->postToChat($row->provider, $config, $title, (string) $body);
                        }

                        $this->record($organizationId, $row->provider, 'notice', true, mb_substr($title, 0, 200));
                    } catch (Throwable $exception) {
                        $this->record($organizationId, $row->provider, 'notice', false, mb_substr($exception->getMessage(), 0, 280));
                    }
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Luzma runs when the platform has it on and the office has not switched it off. */
    public function luzmaEnabled(Organization|int $organization): bool
    {
        return app(LuzmaSwitch::class)->enabled() && (($this->config($organization, 'luzma') ?? [])['enabled'] ?? true) !== false;
    }

    /** @param array<string, mixed> $config */
    public function saveConfig(Organization $organization, string $provider, array $config, string $status): void
    {
        DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)
            ->update(['config' => Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR)), 'status' => $status, 'last_error' => null, 'updated_at' => now()]);
    }

    public function recordEvent(Organization|int $organization, string $provider, string $event, bool $ok, ?string $message): void
    {
        $this->record($organization, $provider, $event, $ok, $message);
    }

    /** Saves a CSV with the state of every client company in the connected Drive or Dropbox. */
    public function backup(Organization $organization, User $actor, string $provider): string
    {
        abort_unless(in_array($provider, ['google_drive', 'dropbox'], true), 404);
        $executive = app(WorkDashboardService::class)->executiveSnapshot($organization, $actor);
        $rows = [['Empresa', 'NIT', 'Oficina', 'Período', 'Avance %', 'Tareas pendientes', 'Tareas vencidas']];

        foreach ($executive['companies'] as $company) {
            $rows[] = [$company['name'], $company['tax_id'], $company['office_name'], $company['period_name'] ?? 'Sin período', $company['progress'], $company['pending_tasks'], $company['overdue_tasks'] ?? 0];
        }

        $csv = "\xEF\xBB\xBF".implode("\n", array_map(fn (array $row): string => implode(',', array_map(fn ($cell): string => '"'.str_replace('"', '""', (string) $cell).'"', $row)), $rows));

        try {
            $where = app(IntegrationOAuthService::class)->upload($organization, $provider, 'estado-de-empresas-'.now()->format('Ymd-His').'.csv', $csv);
        } catch (Throwable $exception) {
            $this->record($organization, $provider, 'backup', false, mb_substr($exception->getMessage(), 0, 280));

            throw ValidationException::withMessages(['backup' => 'No se pudo guardar el informe: '.mb_substr($exception->getMessage(), 0, 200)]);
        }

        $this->record($organization, $provider, 'backup', true, 'Informe guardado en '.$where);
        DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->update(['last_synced_at' => now(), 'updated_at' => now()]);

        return 'Informe guardado en '.$where.'.';
    }

    /** @return list<array{provider: string, event: string, ok: bool, message: string|null, at: string}> */
    public function recentEvents(Organization $organization): array
    {
        return array_values(DB::table('integration_events')->where('organization_id', $organization->id)->orderByDesc('id')->limit(12)->get()
            ->map(fn (object $row): array => ['provider' => $row->provider, 'event' => $row->event, 'ok' => (bool) $row->ok, 'message' => $row->message, 'at' => $row->created_at])->all());
    }

    /** @return array<string, mixed>|null */
    public function config(Organization|int $organization, string $provider): ?array
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $row = DB::table('integration_connections')->where('organization_id', $id)->where('provider', $provider)->first();

        return $row ? $this->decode($row->config) : null;
    }

    /** @param array<string, mixed> $config */
    private function assertValid(string $provider, array $config): void
    {
        if (in_array($provider, ['slack', 'teams'], true)) {
            $host = strtolower((string) parse_url((string) $config['webhook_url'], PHP_URL_HOST));
            $allowed = $provider === 'slack' ? ['hooks.slack.com'] : ['webhook.office.com', 'logic.azure.com', 'powerplatform.com'];

            if (! collect($allowed)->contains(fn (string $suffix): bool => $host === $suffix || str_ends_with($host, '.'.$suffix))) {
                throw ValidationException::withMessages(['webhook_url' => 'La URL no corresponde a un webhook de '.self::PROVIDERS[$provider]['label'].'.']);
            }

            if (($problem = $this->guard->problem((string) $config['webhook_url'])) !== null) {
                throw ValidationException::withMessages(['webhook_url' => $problem]);
            }
        }

        if ($provider === 'smtp') {
            if (! ctype_digit((string) $config['port']) || (int) $config['port'] < 1 || (int) $config['port'] > 65535) {
                throw ValidationException::withMessages(['port' => 'El puerto no es válido.']);
            }

            if (! filter_var($config['from_address'], FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['from_address' => 'El correo remitente no es válido.']);
            }

            if (($problem = $this->guard->problem('https://'.$config['host'])) !== null) {
                throw ValidationException::withMessages(['host' => 'El servidor no es válido: '.$problem]);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function postToChat(string $provider, array $config, string $title, string $text): string
    {
        $url = (string) $config['webhook_url'];

        if (($problem = $this->guard->problem($url)) !== null) {
            throw new \RuntimeException($problem);
        }

        $message = trim($title.($text !== '' ? "\n".$text : ''));
        $payload = match (true) {
            $provider === 'slack' => ['text' => $message],
            str_contains($url, 'logic.azure.com') || str_contains($url, 'powerplatform.com') => ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => [['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'wrap' => true], ['type' => 'TextBlock', 'text' => $text, 'wrap' => true]]]]]],
            default => ['@type' => 'MessageCard', '@context' => 'http://schema.org/extensions', 'summary' => $title, 'title' => $title, 'text' => $text],
        };
        $response = Http::timeout(8)->withoutRedirecting()->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(self::PROVIDERS[$provider]['label'].' respondió '.$response->status().'.');
        }

        return 'Mensaje enviado a '.self::PROVIDERS[$provider]['label'].'.';
    }

    /** @param array<string, mixed> $config */
    private function testHubspot(array $config): string
    {
        $response = Http::withToken((string) $config['access_token'])->acceptJson()->timeout(10)->get('https://api.hubapi.com/crm/v3/objects/contacts', ['limit' => 1]);

        if ($response->failed()) {
            throw new \RuntimeException('HubSpot respondió '.$response->status().'; revisa el token y sus permisos.');
        }

        return 'Conexión con HubSpot verificada.';
    }

    /** @param array<string, mixed> $config */
    private function sendTestMail(Organization $organization, array $config, User $actor): string
    {
        $this->mailer($config)->raw('La conexión de correo de '.$organization->name.' con Atlantia Finance funciona.', fn ($message) => $message->to($actor->email)->subject('Prueba de correo de Atlantia Finance'));

        return 'Correo de prueba enviado a '.$actor->email.'.';
    }

    /** @param array<string, mixed> $config */
    private function mailer(array $config): Mailer
    {
        Config::set('mail.mailers.office_smtp', [
            'transport' => 'smtp',
            'host' => $config['host'],
            'port' => (int) $config['port'],
            'encryption' => ($config['encryption'] ?? 'tls') === 'ninguno' ? null : ($config['encryption'] ?? 'tls'),
            'username' => $config['username'],
            'password' => $config['password'],
            'timeout' => 10,
        ]);
        Config::set('mail.from', ['address' => $config['from_address'], 'name' => $config['from_name'] ?? config('app.name')]);
        Mail::purge('office_smtp');

        return Mail::mailer('office_smtp');
    }

    private function record(Organization|int $organization, string $provider, string $event, bool $ok, ?string $message): void
    {
        DB::table('integration_events')->insert(['organization_id' => $organization instanceof Organization ? $organization->id : $organization, 'provider' => $provider, 'event' => $event, 'ok' => $ok, 'message' => $message, 'created_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function decode(string $encrypted): array
    {
        return (array) json_decode(Crypt::decryptString($encrypted), true);
    }
}
