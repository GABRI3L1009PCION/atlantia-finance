<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Normative\NormativeLibraryService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Normative Library: shared laws, guides and NIIF summaries, plus the firm's own documents. Read-only for users. */
class NormativeLibraryController extends Controller
{
    public function __construct(private WorkspaceContext $workspace, private NormativeLibraryService $library) {}

    public function index(Request $request): Response
    {
        $organization = $this->workspace->organization($request->user());
        $query = is_string($request->query('q')) ? trim($request->query('q')) : '';

        return Inertia::render('library/index', [
            'documents' => $this->library->catalog($organization->id),
            'categories' => NormativeLibraryService::CATEGORY_LABELS,
            'query' => $query,
            'results' => $query === '' ? [] : $this->library->search($organization->id, $query, 30),
        ]);
    }

    public function show(Request $request, string $code): Response
    {
        $organization = $this->workspace->organization($request->user());
        $path = is_string($request->query('path')) ? $request->query('path') : null;
        $search = is_string($request->query('q')) ? $request->query('q') : null;
        // The reader paginates the whole document in the browser; the path or search only decides where it opens.
        $data = $this->library->read($organization->id, $code, null, null, 5000);
        abort_if($data === null, 404);

        $start = null;
        if ($path !== null && $path !== '') {
            foreach ($data['sections'] as $section) {
                if (str_starts_with((string) $section['path'], $path)) {
                    $start = (string) $section['key'];

                    break;
                }
            }
        } elseif ($search !== null && trim($search) !== '') {
            $start = $this->library->read($organization->id, $code, null, $search, 1)['sections'][0]['key'] ?? null;
        }

        return Inertia::render('library/show', [...$data, 'path' => $path, 'query' => $search, 'start' => $start]);
    }

    public function search(Request $request): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $data = $request->validate(['q' => 'nullable|string|max:200']);

        return response()->json([
            'documents' => $this->library->catalog($organization->id),
            'results' => empty($data['q']) ? [] : $this->library->search($organization->id, (string) $data['q'], 20),
        ]);
    }
}
