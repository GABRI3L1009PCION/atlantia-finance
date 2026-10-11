<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Platform\PlatformLuzmaAssistant;
use App\Services\Platform\SaaSDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/** Control Center home and its Luzma. */
class DashboardController extends ControlCenterController
{
    public function index(Request $request, SaaSDashboardService $dashboard): Response
    {
        $user = $this->staff($request, 'saas.dashboard.view');

        return $this->render($request, 'dashboard', ['dashboard' => $dashboard->data($this->authorization, $user)]);
    }

    public function ask(Request $request, PlatformLuzmaAssistant $luzma): JsonResponse
    {
        $user = $this->staff($request, 'saas.luzma.use');
        $data = $request->validate(['question' => 'required|string|min:3|max:500']);

        return response()->json($luzma->ask($user, (string) $data['question']));
    }
}
