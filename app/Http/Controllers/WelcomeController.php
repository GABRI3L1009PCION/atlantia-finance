<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Platform\PublicPlanCatalog;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Public sales page. The plans come from the real catalog; if it cannot be read, the page says so instead of showing zero prices. */
class WelcomeController extends Controller
{
    public function __invoke(PublicPlanCatalog $catalog): Response
    {
        try {
            $plans = $catalog->plans();
        } catch (Throwable $exception) {
            Log::warning('Sales page plans unavailable', ['error' => $exception->getMessage()]);
            $plans = null;
        }

        return Inertia::render('welcome', ['plans' => $plans]);
    }
}
