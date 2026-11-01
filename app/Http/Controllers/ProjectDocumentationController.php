<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Platform\ProjectDocumentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Public /documentacion page: the evaluation committee enters the password and reads the project's PDFs. */
class ProjectDocumentationController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    public function __construct(private ProjectDocumentationService $documentation) {}

    public function show(): Response
    {
        $unlocked = $this->documentation->isUnlocked();

        return Inertia::render('project-docs/show', [
            'available' => $this->documentation->hasPassword(),
            'unlocked' => $unlocked,
            'documents' => $unlocked ? $this->documentation->documents() : [],
            'access_hours' => ProjectDocumentationService::ACCESS_HOURS,
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => 'required|string|max:200']);
        $key = 'project-docs:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['password' => 'Demasiados intentos. Vuelve a intentarlo en '.ceil(RateLimiter::availableIn($key) / 60).' minutos.']);
        }
        if (! $this->documentation->unlock((string) $data['password'])) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
        RateLimiter::clear($key);

        return redirect()->route('project-docs.show');
    }

    public function lock(): RedirectResponse
    {
        $this->documentation->lock();

        return redirect()->route('project-docs.show');
    }

    public function file(Request $request, string $document): StreamedResponse|RedirectResponse
    {
        if (! $this->documentation->isUnlocked()) {
            return redirect()->route('project-docs.show');
        }

        return $this->documentation->file($document, $request->boolean('download'));
    }
}
