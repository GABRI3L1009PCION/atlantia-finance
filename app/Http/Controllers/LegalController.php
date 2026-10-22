<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Platform\LegalDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public legal pages and the acceptance that a firm's owner gives to each new version. */
class LegalController extends Controller
{
    public function __construct(private LegalDocumentService $legal) {}

    public function show(string $slug): Response
    {
        $document = $this->legal->current($slug) ?? abort(404);

        // The terms page carries the privacy policy and the personal data processing document too: one link covers all three.
        $sections = $slug === 'terms' ? array_values(array_filter(array_map(fn (string $key): ?array => $this->legal->current($key), array_keys(LegalDocumentService::DOCUMENTS)))) : [$document];

        return Inertia::render('legal/show', ['document' => $this->present($document), 'sections' => array_map(fn (array $section): array => $this->present($section), $sections),
            'links' => $this->links(), 'signedIn' => auth()->check()]);
    }

    public function accept(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $pending = $this->legal->pendingFor($user);
        if ($pending === []) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('legal/accept', ['documents' => array_map(fn (string $slug): array => $this->present((array) $this->legal->current($slug)) + ['url' => '/'.LegalDocumentService::DOCUMENTS[$slug]['path']], $pending)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $request->validate(['accepted' => ['accepted']], ['accepted.accepted' => 'Marca la casilla para continuar.']);
        $this->legal->acceptCurrent($user, 'login', $request->ip(), $request->userAgent());

        return redirect()->intended(route('dashboard'));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{slug: string, label: string, title: string, body: string, version: int, published_at: string}
     */
    private function present(array $document): array
    {
        return ['slug' => (string) $document['slug'], 'label' => (string) $document['label'], 'title' => (string) $document['title'], 'body' => (string) $document['body'],
            'version' => (int) $document['version'], 'published_at' => (string) $document['published_at']];
    }

    /** @return list<array{label: string, href: string}> */
    private function links(): array
    {
        return array_values(array_map(fn (array $definition): array => ['label' => $definition['label'], 'href' => '/'.$definition['path']], LegalDocumentService::DOCUMENTS));
    }
}
