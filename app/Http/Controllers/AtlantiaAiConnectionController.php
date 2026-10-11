<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Connection check of Luzma from the company configuration screen (kept at its original route). */
class AtlantiaAiConnectionController extends Controller
{
    public function __construct(
        private WorkspaceContext $workspace,
        private AccountingService $accounting,
    ) {}

    public function __invoke(Request $request, string $company, LuzmaModelProvider $provider): JsonResponse
    {
        $organization = $this->workspace->organization($request->user());
        $this->accounting->company($organization, $request->user(), $company);
        $this->workspace->requireFeature($organization, 'basic_accounting');
        $health = $provider->health();

        if (! $health['gateway']) {
            return response()->json(['status' => 'error', 'message' => 'No se pudo conectar con Luzma.'], 503);
        }
        if (! $health['model_loaded']) {
            return response()->json(['status' => 'error', 'message' => 'Luzma responde, pero el modelo local no está cargado.'], 502);
        }

        return response()->json(['status' => 'ok', 'message' => 'Luzma está disponible y responde correctamente.']);
    }
}
