<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Project documentation for the evaluation committee: PDFs uploaded from the Control Center and a single access password.
 * Only the password hash is stored (in platform_settings, outside the editable settings list so it never reaches a
 * screen). A visitor who enters it is remembered in the session together with a fingerprint of that hash, so changing
 * the password closes every open access at once.
 */
class ProjectDocumentationService
{
    public const PASSWORD_KEY = 'project_docs.password_hash';

    public const SESSION_KEY = 'project_docs.unlocked';

    public const ACCESS_HOURS = 4;

    /** Below upload_max_filesize (25M) of the production PHP configuration, so the limit is reported by the application. */
    public const MAX_UPLOAD_KB = 24576;

    private const DIRECTORY = 'project-documents';

    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuditService $audit) {}

    public function hasPassword(): bool
    {
        return $this->passwordHash() !== null;
    }

    public function setPassword(User $actor, string $password): void
    {
        $hadPassword = $this->hasPassword();
        $this->tenancy->runAsSystem(fn () => DB::table('platform_settings')->updateOrInsert(['key' => self::PASSWORD_KEY], [
            'group' => 'project_docs', 'value' => json_encode(Hash::make($password)), 'sensitive' => true, 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now(),
        ]));
        $this->audit->record($actor, $hadPassword ? 'project_docs.password_changed' : 'project_docs.password_created', 'platform_settings', self::PASSWORD_KEY, sensitive: true);
    }

    /** Checks the password and, when it matches, opens the documentation for this session. */
    public function unlock(string $password): bool
    {
        $hash = $this->passwordHash();
        if ($hash === null || ! Hash::check($password, $hash)) {
            return false;
        }
        session()->regenerate();
        session()->put(self::SESSION_KEY, ['fingerprint' => $this->fingerprint($hash), 'expires_at' => now()->addHours(self::ACCESS_HOURS)->getTimestamp()]);

        return true;
    }

    public function isUnlocked(): bool
    {
        $hash = $this->passwordHash();
        $access = session()->get(self::SESSION_KEY);
        if ($hash === null || ! is_array($access)) {
            return false;
        }

        return hash_equals($this->fingerprint($hash), (string) ($access['fingerprint'] ?? '')) && (int) ($access['expires_at'] ?? 0) > now()->getTimestamp();
    }

    public function lock(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /** @return list<array{id: string, title: string, description: string|null, file_name: string, size: int, uploaded_at: string}> */
    public function documents(): array
    {
        return $this->tenancy->runAsSystem(fn (): array => DB::table('project_documents')->orderBy('position')->orderBy('id')->get()
            ->map(fn (stdClass $row): array => ['id' => (string) $row->public_id, 'title' => (string) $row->title, 'description' => $row->description === null ? null : (string) $row->description,
                'file_name' => (string) $row->file_name, 'size' => (int) $row->size, 'uploaded_at' => Carbon::parse($row->created_at)->toIso8601String()])->values()->all());
    }

    public function upload(User $actor, UploadedFile $file, string $title, ?string $description): string
    {
        $publicId = (string) Str::uuid();
        $path = (string) $file->storeAs(self::DIRECTORY, $publicId.'.pdf', 'local');
        $this->tenancy->runAsSystem(fn () => DB::table('project_documents')->insert([
            'public_id' => $publicId, 'title' => mb_substr(trim($title), 0, 160), 'description' => $description === null || trim($description) === '' ? null : mb_substr(trim($description), 0, 500),
            'file_path' => $path, 'file_name' => mb_substr(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'documento', 0, 190).'.pdf',
            'size' => (int) $file->getSize(), 'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
            'position' => (int) DB::table('project_documents')->max('position') + 1, 'uploaded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->audit->record($actor, 'project_docs.uploaded', 'project_documents', $publicId, after: ['title' => $title, 'size' => (int) $file->getSize()]);

        return $publicId;
    }

    public function delete(User $actor, string $publicId): void
    {
        $row = $this->find($publicId);
        $this->tenancy->runAsSystem(fn () => DB::table('project_documents')->where('id', $row->id)->delete());
        Storage::disk('local')->delete((string) $row->file_path);
        $this->audit->record($actor, 'project_docs.deleted', 'project_documents', $publicId, before: ['title' => (string) $row->title]);
    }

    /** Serves the PDF inline (the browser opens it) or as a download. */
    public function file(string $publicId, bool $download): StreamedResponse
    {
        $row = $this->find($publicId);
        abort_unless(Storage::disk('local')->exists((string) $row->file_path), 404);
        $disposition = $download ? 'attachment' : 'inline';

        return Storage::disk('local')->response((string) $row->file_path, (string) $row->file_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            // The browser's PDF viewer needs plugins, which the application policy (object-src 'none') would block.
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'X-Robots-Tag' => 'noindex, nofollow',
        ], $disposition);
    }

    private function find(string $publicId): stdClass
    {
        abort_unless(Str::isUuid($publicId), 404);

        return $this->tenancy->runAsSystem(fn (): ?stdClass => DB::table('project_documents')->where('public_id', $publicId)->first()) ?? abort(404);
    }

    private function passwordHash(): ?string
    {
        $value = $this->tenancy->runAsSystem(fn (): mixed => DB::table('platform_settings')->where('key', self::PASSWORD_KEY)->value('value'));
        $hash = $value === null ? null : json_decode((string) $value, true);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function fingerprint(string $hash): string
    {
        return hash_hmac('sha256', $hash, (string) config('app.key'));
    }
}
