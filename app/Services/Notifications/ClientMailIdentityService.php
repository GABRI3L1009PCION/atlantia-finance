<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\ClientReplyVerificationMail;
use App\Mail\CollectionReminderMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Who a message to a firm's clients comes from, the way invoicing services do it: it leaves from Atlantia's own sending
 * address (so it is delivered and not taken for spam) under the name of the company or the firm, and "reply" reaches the
 * firm. The firm only types its address and confirms it owns it with a link; it never configures a mail server.
 *
 * @phpstan-type ClientMailIdentity array{from_name: string, reply_to: string|null, firm: string, company: string|null, phone: string|null, color: string, reply_verified: bool}
 */
class ClientMailIdentityService
{
    /** Hours the confirmation link stays valid. */
    public const VERIFY_HOURS = 48;

    private const DEFAULT_COLOR = '#7A0E1A';

    public function __construct(private AccountingService $accounting, private TenantDatabaseContext $tenancy) {}

    /**
     * The sender of a message to a client: the company's name when it is about a company (its customers know the
     * company, not the firm), the firm's otherwise. Replies go to the firm's confirmed address or, until it confirms
     * one, to the person who sent the message.
     *
     * @return ClientMailIdentity
     */
    public function identity(int $organizationId, ?int $companyId = null, ?int $senderId = null): array
    {
        $profile = $this->profile($organizationId);
        $firm = trim((string) ($profile?->trade_name ?: DB::table('organizations')->where('id', $organizationId)->value('name')));
        $company = $companyId === null ? null : DB::table('companies')->where('id', $companyId)->value('name');
        $verified = $profile?->client_reply_email !== null && $profile->client_reply_email_verified_at !== null;
        $reply = $verified ? (string) $profile->client_reply_email : ($senderId === null ? null : DB::table('users')->where('id', $senderId)->value('email'));
        $color = (string) ($profile->primary_color ?? '');

        return ['from_name' => self::displayName($company === null ? $firm : (string) $company), 'reply_to' => $reply === null ? null : (string) $reply, 'firm' => $firm,
            'company' => $company === null ? null : (string) $company, 'phone' => $profile?->phone === null ? null : (string) $profile->phone,
            'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : self::DEFAULT_COLOR, 'reply_verified' => $verified];
    }

    /**
     * The firm's address for replies and whether it is confirmed.
     *
     * @return array{email: string|null, verified: bool, requested_at: string|null}
     */
    public function status(int $organizationId): array
    {
        $profile = $this->profile($organizationId);

        return ['email' => $profile?->client_reply_email, 'verified' => $profile?->client_reply_email_verified_at !== null,
            'requested_at' => $profile?->client_reply_email_requested_at === null ? null : (string) $profile->client_reply_email_requested_at];
    }

    /** Saves the address and e-mails it the confirmation link; replies keep going to the sender until it is confirmed. */
    public function requestVerification(Organization $organization, User $user, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $this->ensureProfile($organization);
        DB::table('firm_profiles')->where('organization_id', $organization->id)->update(['client_reply_email' => $email, 'client_reply_email_requested_at' => now(),
            'client_reply_email_verified_at' => null, 'updated_by' => $user->id, 'updated_at' => now()]);
        $url = URL::temporarySignedRoute('client-email.verify', now()->addHours(self::VERIFY_HOURS), ['organization' => $organization->id, 'hash' => self::hash($email)]);
        try {
            Mail::to($email)->send(new ClientReplyVerificationMail($this->identity($organization->id)['firm'], $url, self::VERIFY_HOURS));
        } catch (Throwable) {
            throw ValidationException::withMessages(['email' => 'No pudimos enviar el correo de confirmación a '.$email.'. Revisa la dirección e inténtalo de nuevo.']);
        }
        $this->accounting->audit($organization, $user, 'firm.client_email_requested', 'organizations', $organization->id, ['email' => $email]);
    }

    /** Confirms the address whose link was opened; false when the address changed since the link was sent. */
    public function verify(int $organizationId, string $hash): bool
    {
        return (bool) $this->tenancy->runAsSystem(function () use ($organizationId, $hash): bool {
            $profile = $this->profile($organizationId);
            if ($profile?->client_reply_email === null || ! hash_equals(self::hash((string) $profile->client_reply_email), $hash)) {
                return false;
            }
            if ($profile->client_reply_email_verified_at === null) {
                DB::table('firm_profiles')->where('organization_id', $organizationId)->update(['client_reply_email_verified_at' => now(), 'updated_at' => now()]);
            }

            return true;
        });
    }

    public function remove(Organization $organization, User $user): void
    {
        DB::table('firm_profiles')->where('organization_id', $organization->id)->update(['client_reply_email' => null, 'client_reply_email_requested_at' => null,
            'client_reply_email_verified_at' => null, 'updated_by' => $user->id, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'firm.client_email_removed', 'organizations', $organization->id);
    }

    /** Sends a sample collection reminder to the firm's own address, so it sees exactly what its clients receive. */
    public function sendTest(Organization $organization, User $user): string
    {
        $status = $this->status($organization->id);
        if (! $status['verified'] || $status['email'] === null) {
            throw ValidationException::withMessages(['email' => 'Confirma primero el correo de la firma.']);
        }
        $identity = $this->identity($organization->id);
        $body = "Estimado(a) cliente:\n\nEste es un ejemplo del recordatorio de cobro que recibirán sus clientes:\n- FACT A-1001 · vence 15/11/2026 · Q 1,120.00\n\nSaldo total: Q 1,120.00.\n\nSi responde este correo, la respuesta llegará a ".$status['email'].'.';
        try {
            Mail::to($status['email'])->send(new CollectionReminderMail('Correo de prueba de '.$identity['firm'], $body, $identity));
        } catch (Throwable) {
            throw ValidationException::withMessages(['email' => 'No pudimos enviar el correo de prueba. Inténtalo más tarde.']);
        }

        return $status['email'];
    }

    /** A display name that cannot break the From header. */
    public static function displayName(string $name): string
    {
        $clean = trim(preg_replace('/[\r\n"<>\\\\]+/', ' ', $name) ?? '');

        return mb_substr($clean === '' ? 'Atlantia Finance' : $clean, 0, 70);
    }

    private static function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower($email), (string) config('app.key'));
    }

    private function profile(int $organizationId): ?stdClass
    {
        return DB::table('firm_profiles')->where('organization_id', $organizationId)->first(['trade_name', 'phone', 'primary_color', 'client_reply_email', 'client_reply_email_requested_at', 'client_reply_email_verified_at']);
    }

    private function ensureProfile(Organization $organization): void
    {
        DB::table('firm_profiles')->insertOrIgnore(['organization_id' => $organization->id, 'trade_name' => $organization->name, 'created_at' => now(), 'updated_at' => now()]);
    }
}
