<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Versioned platform message templates. Editing creates a new version (the previous one is kept); messages to an
 * organization go to its owners in the app and by email, at most once per dedupe key.
 */
class NotificationTemplateService
{
    /** @var array<string, array{label: string, subject: string, body: string}> */
    public const DEFAULTS = [
        'welcome' => ['label' => 'Bienvenida', 'subject' => 'Bienvenido a {brand}', 'body' => "Hola {name},\n\nTu organización {organization} ya está lista en {brand}. Ingresa y configura tu primera oficina."],
        'invitation' => ['label' => 'Invitación', 'subject' => 'Te invitaron a {organization}', 'body' => "Hola,\n\n{inviter} te invitó a trabajar en {organization} dentro de {brand}."],
        'payment_failed' => ['label' => 'Pago fallido', 'subject' => 'Tu pago de {brand} está pendiente', 'body' => "Hola {name},\n\nTu suscripción de {organization} tiene {days} día(s) de atraso. Regulariza el pago para evitar la suspensión del servicio."],
        'trial_ending' => ['label' => 'Fin de la prueba', 'subject' => 'Tu prueba de {brand} termina pronto', 'body' => "Hola {name},\n\nLa prueba de {organization} termina el {date}. Elige un plan para seguir trabajando sin interrupciones."],
        'subscription_renewal' => ['label' => 'Renovación', 'subject' => 'Renovamos tu suscripción a {brand}', 'body' => "Hola {name},\n\nGeneramos la factura {invoice} por {amount} de la renovación de {organization}."],
        'incident_notification' => ['label' => 'Incidente', 'subject' => '[{brand}] {title}', 'body' => "{message}\n\nEstado: {status}."],
        'support_message' => ['label' => 'Mensaje de soporte', 'subject' => 'Respuesta a tu ticket {ticket}', 'body' => "Hola {name},\n\nRespondimos tu solicitud {ticket}: {message}"],
    ];

    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuditService $audit, private GlobalConfigurationService $settings) {}

    /** @return array{subject: string, body: string, version: int} */
    public function current(string $key): array
    {
        $row = $this->tenancy->runAsSystem(fn () => DB::table('notification_templates')->where('key', $key)->where('status', 'active')->orderByDesc('version')->first());
        if ($row !== null) {
            return ['subject' => (string) $row->subject, 'body' => (string) $row->body, 'version' => (int) $row->version];
        }
        $default = self::DEFAULTS[$key] ?? abort(404);

        return ['subject' => $default['subject'], 'body' => $default['body'], 'version' => 0];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(function (string $key): array {
            $current = $this->current($key);

            return ['key' => $key, 'label' => self::DEFAULTS[$key]['label'], ...$current,
                'history' => DB::table('notification_templates')->where('key', $key)->orderByDesc('version')->limit(10)->get(['version', 'subject', 'status', 'created_at'])->all()];
        }, array_keys(self::DEFAULTS));
    }

    public function update(User $actor, string $key, string $subject, string $body): int
    {
        if (! isset(self::DEFAULTS[$key])) {
            throw ValidationException::withMessages(['key' => 'Plantilla desconocida.']);
        }

        return DB::transaction(function () use ($actor, $key, $subject, $body): int {
            $previous = $this->current($key);
            $version = (int) DB::table('notification_templates')->where('key', $key)->max('version') + 1;
            DB::table('notification_templates')->where('key', $key)->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('notification_templates')->insert(['key' => $key, 'version' => $version, 'subject' => $subject, 'body' => $body, 'status' => 'active', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($actor, 'template.updated', 'notification_templates', $key, ['version' => $previous['version'], 'subject' => $previous['subject']], ['version' => $version, 'subject' => $subject]);

            return $version;
        });
    }

    /**
     * @param  array<string, string|int|float|null>  $variables
     * @return array{subject: string, body: string}
     */
    public function render(string $key, array $variables): array
    {
        $template = $this->current($key);
        $replace = ['{brand}' => (string) $this->settings->get('brand.name', 'Atlantia Finance')];
        foreach ($variables as $name => $value) {
            $replace['{'.$name.'}'] = (string) $value;
        }

        return ['subject' => strtr($template['subject'], $replace), 'body' => strtr($template['body'], $replace)];
    }

    /**
     * Sends a template to the owners of an organization once per dedupe key. Returns whether it was sent now.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    public function notifyOnce(int $organizationId, string $key, string $dedupe, array $variables = []): bool
    {
        if (! Cache::add('platform-notification:'.sha1($dedupe.'|'.$key), true, now()->addDays(45))) {
            return false;
        }
        $this->notifyOrganization($organizationId, $key, $variables);

        return true;
    }

    /** @param array<string, string|int|float|null> $variables */
    public function notifyOrganization(int $organizationId, string $key, array $variables = []): int
    {
        $organization = $this->tenancy->runAsSystem(fn () => DB::table('organizations')->where('id', $organizationId)->first());
        if ($organization === null) {
            return 0;
        }
        $owners = $this->tenancy->runAsSystem(fn () => DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.role', 'owner')->where('organization_user.status', 'active')->get(['users.id', 'users.name', 'users.email']));
        foreach ($owners as $owner) {
            $message = $this->render($key, ['name' => $owner->name, 'organization' => $organization->name, ...$variables]);
            $this->tenancy->runAsSystem(fn () => app(WorkNotificationService::class)->notify($organizationId, (int) $owner->id, null, 'platform', $message['subject'], mb_strimwidth($message['body'], 0, 900, '…')));
            if ((bool) $this->settings->get('mail.notifications_enabled', true)) {
                try {
                    Mail::raw($message['body'], fn ($mail) => $mail->to((string) $owner->email)->subject($message['subject']));
                } catch (Throwable $exception) {
                    Log::warning('Platform email not sent', ['template' => $key, 'organization_id' => $organizationId, 'error' => $exception->getMessage()]);
                }
            }
        }

        return $owners->count();
    }
}
