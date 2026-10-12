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
        $data = $this->library->read($organization->id, $code, $path, $search);
        abort_if($data === null, 404);

        return Inertia::render('library/show', [...$data, 'path' => $path, 'query' => $search]);
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
