<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Platform\LegalDocumentService;
use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner of a firm accepts the current terms, privacy policy and data processing document, and accepts them again
 * whenever a new version is published. Until then the only screens available are the documents and the acceptance.
 */
class EnsureLegalAcceptance
{
    private const ALLOWED = ['legal/*', 'terminos', 'privacidad', 'tratamiento-de-datos', 'logout', 'password/change', 'up'];

    public function __construct(private LegalDocumentService $legal, private TenantDatabaseContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $request->is(...self::ALLOWED)) {
            return $next($request);
        }
        if ($this->legal->currentDocuments() === [] || ! $this->isFirmOwner($user) || $this->legal->pendingFor($user) === []) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            abort(403, 'Debes aceptar los documentos legales actualizados antes de continuar.');
        }

        return redirect()->route('legal.accept');
    }

    private function isFirmOwner(User $user): bool
    {
        return $this->tenancy->runAsSystem(fn (): bool => DB::table('organization_user')->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists()
            && ! DB::table('platform_staff')->where('user_id', $user->id)->exists());
    }
}
