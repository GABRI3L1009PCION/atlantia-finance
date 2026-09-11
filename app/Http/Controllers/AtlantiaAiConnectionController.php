<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AtlantiaAiConnectionController extends Controller
{
    public function __construct(
        private WorkspaceContext $workspace,
        private AccountingService $accounting,
    ) {}

    public function __invoke(Request $request, string $company): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $this->accounting->company($organization, $request->user(), $company, true);
        $this->workspace->requireFeature($organization, 'basic_accounting');

        try {
            $response = Http::timeout(5)->get(config('services.atlantia_ai.url').'/health');
        } catch (ConnectionException) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo conectar con Atlantia AI.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Atlantia AI respondió con un error.',
            ], 502);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'Atlantia AI está disponible y responde correctamente.',
        ]);
    }
}
