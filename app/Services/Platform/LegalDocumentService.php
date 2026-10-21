<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Versioned legal documents: terms, privacy policy and personal data processing. Editing publishes a new version and the
 * previous ones are kept. The owner of a firm accepts every current document, and again whenever a new version appears.
 */
class LegalDocumentService
{
    /** @var array<string, array{label: string, path: string, file: string}> public path, label and the starting text of each document */
    public const DOCUMENTS = [
        'terms' => ['label' => 'Términos y Condiciones', 'path' => 'terminos', 'file' => 'terminos.md'],
        'privacy' => ['label' => 'Política de Privacidad', 'path' => 'privacidad', 'file' => 'privacidad.md'],
        'data' => ['label' => 'Tratamiento de datos personales', 'path' => 'tratamiento-de-datos', 'file' => 'tratamiento-de-datos.md'],
    ];

    private const CACHE_KEY = 'legal-documents.current';

    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuditService $audit) {}

    /** @return array{slug: string, label: string, title: string, body: string, version: int, published_at: string}|null */
    public function current(string $slug): ?array
    {
        return $this->currentDocuments()[$slug] ?? null;
    }

    /** @return array<string, array{id: int, slug: string, label: string, title: string, body: string, version: int, published_at: string}> */
    public function currentDocuments(): array
    {
        /** @var array<string, array{id: int, slug: string, label: string, title: string, body: string, version: int, published_at: string}> */
        return Cache::remember(self::CACHE_KEY, 60, fn (): array => $this->tenancy->runAsSystem(function (): array {
            $documents = [];
            foreach (DB::table('legal_documents')->where('status', 'active')->orderBy('slug')->get() as $row) {
                if (! isset(self::DOCUMENTS[$row->slug])) {
                    continue;
                }
                $documents[(string) $row->slug] = ['id' => (int) $row->id, 'slug' => (string) $row->slug, 'label' => self::DOCUMENTS[$row->slug]['label'], 'title' => (string) $row->title,
                    'body' => (string) $row->body, 'version' => (int) $row->version, 'published_at' => Carbon::parse($row->published_at)->toIso8601String()];
            }

            return $documents;
        }));
    }

    /** @return list<string> slugs of the current documents the user has not accepted yet */
    public function pendingFor(User $user): array
    {
        $current = $this->currentDocuments();
        if ($current === []) {
            return [];
        }
        $accepted = $this->tenancy->runAsSystem(fn (): array => DB::table('legal_acceptances')->where('user_id', $user->id)
            ->whereIn('legal_document_id', array_column($current, 'id'))->pluck('legal_document_id')->map(fn ($id): int => (int) $id)->all());

        return array_keys(array_filter($current, fn (array $document): bool => ! in_array($document['id'], $accepted, true)));
    }

    /** Records the acceptance of every current document by a user (the evidence keeps date, IP and version). */
    public function acceptCurrent(User $user, string $context, ?string $ip = null, ?string $userAgent = null): int
    {
        $count = 0;
        foreach ($this->pendingFor($user) as $slug) {
            $document = $this->currentDocuments()[$slug];
            $count += $this->tenancy->runAsSystem(fn (): int => DB::table('legal_acceptances')->insertOrIgnore(['user_id' => $user->id, 'legal_document_id' => $document['id'], 'slug' => $slug,
                'version' => $document['version'], 'context' => $context, 'ip' => $ip, 'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255), 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        }

        return $count;
    }

    /** Publishes a new version of a document; the previous one is kept as superseded. */
    public function publish(User $actor, string $slug, string $title, string $body): int
    {
        if (! isset(self::DOCUMENTS[$slug])) {
            throw ValidationException::withMessages(['slug' => 'Documento desconocido.']);
        }
        $previous = $this->current($slug);
        $version = $this->tenancy->runAsSystem(fn (): int => DB::transaction(function () use ($actor, $slug, $title, $body): int {
            $version = (int) DB::table('legal_documents')->where('slug', $slug)->max('version') + 1;
            DB::table('legal_documents')->where('slug', $slug)->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('legal_documents')->insert(['slug' => $slug, 'version' => $version, 'title' => $title, 'body' => $body, 'status' => 'active', 'published_at' => now(),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);

            return $version;
        }));
        Cache::forget(self::CACHE_KEY);
        $this->audit->record($actor, 'legal.published', 'legal_documents', $slug, $previous === null ? null : ['version' => $previous['version']], ['version' => $version, 'title' => $title], sensitive: true);

        return $version;
    }

    /**
     * What the Control Center shows: each document with its current text, how many people accepted that version and the history.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        return $this->tenancy->runAsSystem(function (): array {
            $overview = [];
            foreach (self::DOCUMENTS as $slug => $definition) {
                $rows = DB::table('legal_documents')->where('slug', $slug)->orderByDesc('version')->get();
                $current = $rows->firstWhere('status', 'active');
                $overview[] = ['slug' => $slug, 'label' => $definition['label'], 'path' => '/'.$definition['path'],
                    'title' => $current?->title, 'body' => $current?->body, 'version' => $current === null ? null : (int) $current->version,
                    'accepted' => $current === null ? 0 : DB::table('legal_acceptances')->where('legal_document_id', $current->id)->count(),
                    'history' => $rows->map(fn (stdClass $row): array => ['version' => (int) $row->version, 'status' => (string) $row->status, 'published_at' => Carbon::parse($row->published_at)->toIso8601String(), 'accepted' => DB::table('legal_acceptances')->where('legal_document_id', $row->id)->count()])->values()->all()];
            }

            return $overview;
        });
    }

    /** The legal text that ships with the application, used to start each document. */
    public static function starterText(string $slug): string
    {
        $file = resource_path('legal/'.(self::DOCUMENTS[$slug]['file'] ?? abort(404)));

        return trim((string) file_get_contents($file));
    }
}
