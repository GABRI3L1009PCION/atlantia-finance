<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Luzma's morning brief: each working day every person who uses Luzma receives, in their notifications (and the
 * office's chat or mail tools), what is due this week in their companies, their overdue tasks and the alerts still open.
 * Nobody receives an empty brief.
 */
class LuzmaMorningBriefService
{
    private const OPEN_TASKS = ['pending', 'in_progress', 'ready_for_review', 'in_review', 'changes_requested', 'blocked', 'waiting_client', 'waiting_external'];

    public function __construct(
        private LuzmaContextService $contexts,
        private LuzmaInsightService $insights,
        private WorkNotificationService $notifications,
        private LuzmaSwitch $switch,
    ) {}

    /** Sends the brief of every active organization; returns how many were sent. */
    public function sendAll(): int
    {
        if (! $this->switch->enabled()) {
            return 0;
        }
        $sent = 0;
        foreach (Organization::query()->where('status', 'active')->get() as $organization) {
            if (! app(IntegrationConnectionService::class)->luzmaEnabled($organization)) {
                continue;
            }
            $users = User::query()->whereIn('id', DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('status', 'active')->pluck('user_id'))->get();
            foreach ($users as $user) {
                try {
                    $sent += $this->send($organization, $user) ? 1 : 0;
                } catch (Throwable $exception) {
                    // A user without access to Luzma (or to any company) simply gets no brief.
                    Log::debug('luzma.morning_brief.skipped', ['user_id' => $user->id, 'reason' => $exception->getMessage()]);
                }
            }
        }

        return $sent;
    }

    public function send(Organization $organization, User $user): bool
    {
        $context = $this->contexts->resolve($user, ['module' => 'global']);
        if ($context->role === 'client_user' || $context->companyIds === []) {
            return false;
        }
        $today = now($context->timezone)->startOfDay();
        $due = DB::table('fiscal_obligations')->whereIn('company_id', $context->companyIds)->whereNull('filed_on')->whereNull('completed_at')
            ->whereBetween('due_on', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->count();
        $overdueTaxes = DB::table('fiscal_obligations')->whereIn('company_id', $context->companyIds)->whereNull('filed_on')->whereNull('completed_at')->where('due_on', '<', $today->toDateString())->count();
        $tasks = DB::table('work_tasks')->where('assigned_to', $user->id)->whereIn('company_id', $context->companyIds)->whereNull('deleted_at')->whereIn('status', self::OPEN_TASKS)->where('due_at', '<', now())->count();
        $alerts = collect($this->insights->forUser($context, 50));
        $serious = $alerts->whereIn('severity', ['critical', 'high_priority'])->count();
        $lines = array_values(array_filter([
            $overdueTaxes > 0 ? $overdueTaxes.' obligación(es) fiscal(es) ya vencida(s).' : null,
            $due > 0 ? $due.' obligación(es) vence(n) esta semana.' : null,
            $tasks > 0 ? 'Tienes '.$tasks.' tarea(s) vencida(s).' : null,
            $alerts->isNotEmpty() ? $alerts->count().' alerta(s) de Luzma abierta(s)'.($serious > 0 ? ', '.$serious.' importante(s): '.$alerts->whereIn('severity', ['critical', 'high_priority'])->take(2)->pluck('title')->implode('; ') : '').'.' : null,
        ]));
        if ($lines === []) {
            return false;
        }
        $this->notifications->notify($organization->id, $user->id, null, 'luzma.morning_brief', 'Buenos días: esto es lo que Luzma vio para hoy',
            implode("\n", $lines)."\nPregúntame \"¿qué debo hacer hoy?\" y lo vemos juntos.", 'luzma', null, ['due' => $due, 'overdue_taxes' => $overdueTaxes, 'tasks' => $tasks, 'alerts' => $alerts->count()]);

        return true;
    }
}
