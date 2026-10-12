<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Google (Drive, Gmail), Microsoft (Outlook) and Dropbox through OAuth 2. Each office registers its own app with the
 * vendor and enters its client id and secret, so Atlantia needs no account of its own with them. Tokens are stored
 * encrypted next to the rest of the connection and refreshed when they expire.
 */
class IntegrationOAuthService
{
    /**
     * @var array<string, array{authorize: string, token: string, scopes: list<string>, extra: array<string, string>, console: string}>
     */
    public const PROVIDERS = [
        'google_drive' => ['authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'scopes' => ['https://www.googleapis.com/auth/drive.file'], 'extra' => ['access_type' => 'offline', 'prompt' => 'consent'], 'console' => 'Google Cloud Console → APIs y servicios → Credenciales → ID de cliente OAuth (aplicación web)'],
        'gmail' => ['authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'scopes' => ['https://www.googleapis.com/auth/gmail.send'], 'extra' => ['access_type' => 'offline', 'prompt' => 'consent'], 'console' => 'Google Cloud Console → APIs y servicios → Credenciales → ID de cliente OAuth (aplicación web)'],
        'outlook' => ['authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'scopes' => ['offline_access', 'User.Read', 'Mail.Send'], 'extra' => ['response_mode' => 'query'], 'console' => 'Azure Portal → Registros de aplicaciones → Nuevo registro (web)'],
        'dropbox' => ['authorize' => 'https://www.dropbox.com/oauth2/authorize', 'token' => 'https://api.dropboxapi.com/oauth2/token', 'scopes' => ['files.content.write', 'account_info.read'], 'extra' => ['token_access_type' => 'offline'], 'console' => 'Dropbox App Console → Create app (Scoped access)'],
    ];

    public function __construct(private IntegrationConnectionService $connections) {}

    public static function isOAuth(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    public function redirectUri(string $provider): string
    {
        return route('integrations.oauth.callback', $provider);
    }

    /** Address the office is sent to so it can authorize Atlantia; the state ties the answer to this office and this person. */
    public function authorizationUrl(Organization $organization, User $actor, string $provider): string
    {
        $config = $this->connections->config($organization, $provider) ?? throw ValidationException::withMessages(['provider' => 'Primero guarda el ID y el secreto de cliente.']);
        $definition = self::PROVIDERS[$provider];
        $state = Crypt::encryptString(json_encode(['org' => $organization->id, 'user' => $actor->id, 'provider' => $provider, 'exp' => now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR));

        return $definition['authorize'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri($provider),
            'scope' => implode(' ', $definition['scopes']),
            'state' => $state,
            ...$definition['extra'],
        ]);
    }

    /** Exchanges the code the vendor sent back for tokens and marks the connection as connected. */
    public function complete(User $actor, string $provider, string $code, string $state): Organization
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            abort(403, 'La autorización no es válida.');
        }

        abort_unless(is_array($payload) && ($payload['provider'] ?? null) === $provider && (int) ($payload['user'] ?? 0) === $actor->id && ($payload['exp'] ?? 0) >= now()->timestamp, 403, 'La autorización expiró o no corresponde a tu sesión.');
        $organization = Organization::query()->findOrFail((int) $payload['org']);
        $config = $this->connections->config($organization, $provider) ?? abort(404);
        $tokens = $this->exchange($provider, $config, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri($provider)]);

        $this->connections->saveConfig($organization, $provider, [...$config, ...$tokens, 'authorized_at' => now()->toIso8601String()], 'connected');
        $this->connections->recordEvent($organization, $provider, 'authorized', true, 'Autorización concedida.');

        return $organization;
    }

    /** Calls the vendor with the stored token and reports who the connection belongs to. */
    public function test(Organization $organization, string $provider): string
    {
        $token = $this->accessToken($organization, $provider);

        return match ($provider) {
            'google_drive' => 'Conectado a Google Drive de '.$this->check(Http::withToken($token)->get('https://www.googleapis.com/drive/v3/about', ['fields' => 'user']), 'user.emailAddress'),
            'gmail' => 'Conectado a Gmail de '.$this->check(Http::withToken($token)->get('https://gmail.googleapis.com/gmail/v1/users/me/profile'), 'emailAddress'),
            'outlook' => 'Conectado a Outlook de '.$this->check(Http::withToken($token)->get('https://graph.microsoft.com/v1.0/me'), 'mail', 'userPrincipalName'),
            'dropbox' => 'Conectado a Dropbox de '.$this->check(Http::withToken($token)->withBody('null', 'application/json')->post('https://api.dropboxapi.com/2/users/get_current_account'), 'email'),
            default => abort(404),
        };
    }

    /** Sends a mail through the connected Gmail or Outlook mailbox. */
    public function sendMail(Organization $organization, string $provider, string $to, string $subject, string $body): void
    {
        $token = $this->accessToken($organization, $provider);

        if ($provider === 'gmail') {
            $raw = "To: {$to}\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}";
            $this->check(Http::withToken($token)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]));

            return;
        }

        $this->check(Http::withToken($token)->post('https://graph.microsoft.com/v1.0/me/sendMail', ['message' => ['subject' => $subject, 'body' => ['contentType' => 'Text', 'content' => $body], 'toRecipients' => [['emailAddress' => ['address' => $to]]]]]));
    }

    /** Uploads a file to the connected Drive or Dropbox; returns where it was saved. */
    public function upload(Organization $organization, string $provider, string $name, string $content, string $mime = 'text/csv'): string
    {
        $token = $this->accessToken($organization, $provider);

        if ($provider === 'google_drive') {
            $boundary = 'atlantia'.bin2hex(random_bytes(6));
            $multipart = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode(['name' => $name])."\r\n--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$content}\r\n--{$boundary}--";
            $response = Http::withToken($token)->withBody($multipart, 'multipart/related; boundary='.$boundary)->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
            $this->check($response);

            return 'Google Drive: '.$name;
        }

        $response = Http::withToken($token)->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => '/Atlantia/'.$name, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true])])->withBody($content, 'application/octet-stream')->post('https://content.dropboxapi.com/2/files/upload');
        $this->check($response);

        return 'Dropbox: /Atlantia/'.$name;
    }

    /** A valid access token; it is renewed with the refresh token when it is about to expire. */
    private function accessToken(Organization $organization, string $provider): string
    {
        $config = $this->connections->config($organization, $provider) ?? abort(404);

        if (empty($config['access_token'])) {
            throw new RuntimeException('Falta autorizar la conexión.');
        }

        if (isset($config['expires_at']) && $config['expires_at'] <= now()->addMinute()->timestamp) {
            if (empty($config['refresh_token'])) {
                throw new RuntimeException('La autorización venció; vuelve a autorizar.');
            }

            $tokens = $this->exchange($provider, $config, ['grant_type' => 'refresh_token', 'refresh_token' => $config['refresh_token']]);
            $config = [...$config, ...$tokens, 'refresh_token' => $tokens['refresh_token'] ?? $config['refresh_token']];
            $this->connections->saveConfig($organization, $provider, $config, 'connected');
        }

        return (string) $config['access_token'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $grant
     * @return array{access_token: string, refresh_token?: string, expires_at: int}
     */
    private function exchange(string $provider, array $config, array $grant): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post(self::PROVIDERS[$provider]['token'], [...$grant, 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret']]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw ValidationException::withMessages(['oauth' => 'El proveedor rechazó la autorización ('.($response->json('error_description') ?? $response->json('error') ?? $response->status()).').']);
        }

        $tokens = [
            'access_token' => (string) $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600))->getTimestamp(),
        ];

        if ($response->json('refresh_token')) {
            $tokens['refresh_token'] = (string) $response->json('refresh_token');
        }

        return $tokens;
    }

    private function check(Response $response, string ...$keys): string
    {
        if ($response->failed()) {
            throw new RuntimeException('El proveedor respondió '.$response->status().'.');
        }

        foreach ($keys as $key) {
            if (($value = data_get($response->json(), $key)) !== null) {
                return (string) $value;
            }
        }

        return $keys === [] ? '' : 'tu cuenta';
    }

    public function hasTokens(Organization $organization, string $provider): bool
    {
        return ! empty(($this->connections->config($organization, $provider) ?? [])['access_token']);
    }

    /** Whether the stored credentials belong to a connection the office can still authorize. */
    public function pending(Organization $organization, string $provider): bool
    {
        return DB::table('integration_connections')->where('organization_id', $organization->id)->where('provider', $provider)->where('status', 'pending')->exists();
    }
}
