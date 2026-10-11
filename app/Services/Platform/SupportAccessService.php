<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Formal support access to tenant content: ticket → reason → scopes → duration → approval by someone else → 2FA →
 * temporary session limited to the granted scopes → audit of every view → automatic expiry. Being a superadmin never
 * grants tenant content by itself.
 */
class SupportAccessService
{
    /** Scopes of tenant content a support session can see. */
    public const SCOPES = [
        'structure' => 'Estructura: oficinas, empresas, usuarios y roles',
        'configuration' => 'Configuración: oficinas, parámetros fiscales y FEL (sin secretos)',
        'accounting' => 'Contabilidad: períodos, balance de comprobación y asientos',
        'documents' => 'Documentos: listado de archivos y su estado',
        'integrations' => 'Integraciones: llaves de API (sin secreto), webhooks y entregas',
        'payroll' => 'Nómina: períodos y totales (incluye salarios)',
    ];

    public const STATUS_LABELS = ['requested' => 'Solicitado', 'approved' => 'Aprobado', 'active' => 'Activo', 'expired' => 'Vencido', 'revoked' => 'Revocado', 'rejected' => 'Rechazado'];

    public function __construct(private PlatformAuditService $audit, private GlobalConfigurationService $settings) {}

    /** @param list<string> $scopes */
    public function request(User $actor, int $organizationId, ?int $ticketId, string $reason, array $scopes, int $minutes): int
    {
        DB::table('organizations')->where('id', $organizationId)->firstOrFail();
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, array_keys(self::SCOPES)) !== []) {
            throw ValidationException::withMessages(['scopes' => 'Elige al menos un alcance válido.']);
        }
        $max = (int) $this->settings->get('support.max_access_minutes', 120);
        if ($minutes < 5 || $minutes > $max) {
            throw ValidationException::withMessages(['duration_minutes' => 'La duración debe estar entre 5 y '.$max.' minutos.']);
        }
        if ($ticketId !== null && ! DB::table('support_tickets')->where('id', $ticketId)->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['support_ticket_id' => 'El ticket no pertenece a esa organización.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Describe por qué necesitas acceso (mínimo 10 caracteres).']);
        }
        $id = DB::table('support_access_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'support_ticket_id' => $ticketId,
            'requested_by' => $actor->id, 'reason' => $reason, 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR), 'duration_minutes' => $minutes, 'status' => 'requested',
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($actor, 'support_access.requested', 'support_access_requests', $id, null, ['scopes' => $scopes, 'minutes' => $minutes, 'ticket_id' => $ticketId], $reason, $organizationId);

        return $id;
    }

    /** Approval by someone other than the requester (re-authenticated by the caller). */
    public function decide(User $actor, int $requestId, bool $approve, string $note): void
    {
        DB::transaction(function () use ($actor, $requestId, $approve, $note): void {
            $request = DB::table('support_access_requests')->where('id', $requestId)->lockForUpdate()->first() ?? abort(404);
            if ($request->status !== 'requested') {
                throw ValidationException::withMessages(['status' => 'La solicitud ya fue resuelta.']);
            }
            if ((int) $request->requested_by === $actor->id) {
                throw ValidationException::withMessages(['status' => 'Otra persona debe aprobar tu solicitud de acceso.']);
            }
            DB::table('support_access_requests')->where('id', $requestId)->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $actor->id, 'approved_at' => now(),
                'decision_note' => $note, 'updated_at' => now()]);
            $this->audit->record($actor, $approve ? 'support_access.approved' : 'support_access.rejected', 'support_access_requests', $requestId, ['status' => 'requested'],
                ['status' => $approve ? 'approved' : 'rejected'], $note, (int) $request->organization_id, [], true);
        });
    }

    /** The requester starts the approved session (2FA verified by the caller); it expires on its own. */
    public function start(User $actor, int $requestId): stdClass
    {
        return DB::transaction(function () use ($actor, $requestId): stdClass {
            $request = DB::table('support_access_requests')->where('id', $requestId)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $request->requested_by === $actor->id, 403, 'Solo quien solicitó el acceso puede iniciarlo.');
            if ($request->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'El acceso debe estar aprobado y no iniciado.']);
            }
            DB::table('support_access_requests')->where('id', $requestId)->update(['status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addMinutes((int) $request->duration_minutes), 'updated_at' => now()]);
            $this->audit->record($actor, 'support_access.started', 'support_access_requests', $requestId, null, ['scopes' => json_decode((string) $request->scopes, true),
                'expires_at' => now()->addMinutes((int) $request->duration_minutes)->toIso8601String()], (string) $request->reason, (int) $request->organization_id, [], true);

            return DB::table('support_access_requests')->where('id', $requestId)->first() ?? abort(500);
        });
    }

    public function revoke(User $actor, int $requestId, string $reason): void
    {
        $request = DB::table('support_access_requests')->where('id', $requestId)->first() ?? abort(404);
        if (! in_array($request->status, ['requested', 'approved', 'active'], true)) {
            throw ValidationException::withMessages(['status' => 'El acceso ya terminó.']);
        }
        DB::table('support_access_requests')->where('id', $requestId)->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
        $this->audit->record($actor, 'support_access.ended', 'support_access_requests', $requestId, ['status' => $request->status], ['status' => 'revoked'], $reason, (int) $request->organization_id, [], true);
    }

    /** Expires every active access whose time is up (scheduled every minute; also checked on each use). */
    public function expireDue(): int
    {
        $count = 0;
        foreach (DB::table('support_access_requests')->where('status', 'active')->where('expires_at', '<=', now())->get() as $request) {
            DB::table('support_access_requests')->where('id', $request->id)->update(['status' => 'expired', 'updated_at' => now()]);
            $this->audit->record(null, 'support_access.ended', 'support_access_requests', (int) $request->id, ['status' => 'active'], ['status' => 'expired'], 'Vencimiento automático.', (int) $request->organization_id);
            $count++;
        }
        DB::table('support_access_requests')->where('status', 'approved')->where('approved_at', '<', now()->subDay())->update(['status' => 'expired', 'updated_at' => now()]);

        return $count;
    }

    /**
     * The live session of the staff member on that organization with the scope requested, or a 403. Expired sessions
     * are closed at this point even if the scheduler did not run yet.
     */
    public function session(User $actor, int $requestId, ?string $scope = null): stdClass
    {
        $request = DB::table('support_access_requests')->where('id', $requestId)->first() ?? abort(404);
        abort_unless((int) $request->requested_by === $actor->id, 403, 'Este acceso de soporte pertenece a otra persona.');
        if ($request->status === 'active' && Carbon::parse($request->expires_at)->isPast()) {
            $this->expireDue();
            $request->status = 'expired';
        }
        abort_unless($request->status === 'active', 403, 'El acceso de soporte no está activo (estado: '.(self::STATUS_LABELS[$request->status] ?? $request->status).').');
        if ($scope !== null) {
            abort_unless(in_array($scope, (array) json_decode((string) $request->scopes, true), true), 403, 'El acceso de soporte no incluye «'.(self::SCOPES[$scope] ?? $scope).'».');
        }

        return $request;
    }

    /** @return list<array<string, mixed>> */
    public function list(?int $organizationId = null): array
    {
        $users = DB::table('users')->whereIn('id', DB::table('support_access_requests')->select('requested_by')->union(DB::table('support_access_requests')->whereNotNull('approved_by')->select('approved_by')))->pluck('name', 'id');

        return array_values(DB::table('support_access_requests')->join('organizations', 'organizations.id', '=', 'support_access_requests.organization_id')
            ->leftJoin('support_tickets', 'support_tickets.id', '=', 'support_access_requests.support_ticket_id')
            ->when($organizationId, fn ($q) => $q->where('support_access_requests.organization_id', $organizationId))->orderByDesc('support_access_requests.id')->limit(200)
            ->get(['support_access_requests.*', 'organizations.name as organization', 'support_tickets.code as ticket'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'organization_id' => (int) $row->organization_id, 'organization' => (string) $row->organization, 'ticket' => $row->ticket,
                'requested_by' => (string) ($users[$row->requested_by] ?? '—'), 'requested_by_id' => (int) $row->requested_by, 'approved_by' => $row->approved_by === null ? null : ($users[$row->approved_by] ?? '—'),
                'reason' => (string) $row->reason, 'scopes' => (array) json_decode((string) $row->scopes, true), 'duration_minutes' => (int) $row->duration_minutes,
                'status' => $row->status === 'active' && $row->expires_at !== null && Carbon::parse($row->expires_at)->isPast() ? 'expired' : (string) $row->status,
                'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'decision_note' => $row->decision_note,
                'starts_at' => $row->starts_at === null ? null : Carbon::parse($row->starts_at)->toIso8601String(), 'expires_at' => $row->expires_at === null ? null : Carbon::parse($row->expires_at)->toIso8601String(),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String()])->all());
    }
}
