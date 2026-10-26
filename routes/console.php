<?php

use App\Jobs\LuzmaMaintenanceJob;
use App\Models\Organization;
use App\Services\Fel\SatPendingAlertService;
use App\Services\Fiscal\BanguatExchangeRateService;
use App\Services\Integrations\WebhookDeliveryService;
use App\Services\Luzma\LuzmaMorningBriefService;
use App\Services\Luzma\LuzmaSwitch;
use App\Services\Notifications\AlertDispatchService;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\LimitAlertService;
use App\Services\Platform\PlatformHealthService;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\UsageMeterService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\CrmAlertService;
use App\Services\WorkManagement\OfficeStaffingAlertService;
use App\Services\WorkManagement\ReminderService;
use App\Services\WorkManagement\TaskSyncService;
use App\Support\PlatformIssuer;
use Database\Seeders\NormativeLibrarySeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fel:recover')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('luzma:run', function () {
    if (! app(LuzmaSwitch::class)->enabled()) {
        return;
    }
    $ids = app(TenantDatabaseContext::class)->runAsSystem(fn () => Organization::where('status', 'active')->pluck('id'));
    foreach ($ids as $id) {
        LuzmaMaintenanceJob::dispatch((int) $id);
    }
    $this->info('Luzma: '.count($ids).' organización(es) en cola.');
})->purpose('Recordatorios automáticos, entrega de recordatorios e insights de Luzma');

Schedule::command('luzma:run')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('luzma:prune', function () {
    // Terms 10.5 and the privacy policy: Luzma's interactions are kept for at most 90 days.
    $cutoff = now()->subDays(90);
    [$messages, $conversations] = app(TenantDatabaseContext::class)->runAsSystem(fn (): array => [
        DB::table('luzma_messages')->where('created_at', '<', $cutoff)->delete(),
        DB::table('luzma_conversations')->where('updated_at', '<', $cutoff)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('luzma_messages')
            ->whereColumn('luzma_messages.luzma_conversation_id', 'luzma_conversations.id'))->delete(),
    ]);
    $this->info("Luzma: {$messages} mensaje(s) y {$conversations} conversación(es) de más de 90 días eliminados.");
})->purpose('Elimina las interacciones con Luzma de más de 90 días');

Schedule::command('luzma:prune')->dailyAt('03:10')->withoutOverlapping()->onOneServer();

// Luzma must not switch itself off: bring the gateway back and keep the model in memory.
Schedule::command('luzma:watchdog')->everyMinute()->withoutOverlapping(5)->runInBackground();

Artisan::command('webhooks:deliver', function (WebhookDeliveryService $deliveries) {
    $sent = $deliveries->deliverDue();
    $pruned = app(TenantDatabaseContext::class)->runAsSystem(fn (): int => DB::table('api_request_logs')->where('created_at', '<', now()->subDays((int) config('integrations.api.log_retention_days')))->delete());
    $this->info("Webhooks: {$sent} entrega(s) procesadas; {$pruned} registro(s) de API depurados.");
})->purpose('Envía los webhooks pendientes o por reintentar y depura el registro antiguo de la API');

Schedule::command('webhooks:deliver')->everyMinute()->withoutOverlapping();

Artisan::command('platform:health', function (PlatformHealthService $health, GlobalConfigurationService $settings) {
    $results = $health->run();
    $days = (int) $settings->get('retention.health_checks_days', 30);
    DB::table('service_health_checks')->where('checked_at', '<', now()->subDays($days))->delete();
    DB::table('job_runs')->where('created_at', '<', now()->subDays((int) $settings->get('retention.job_runs_days', 30)))->delete();
    $down = count(array_filter($results, fn (array $row): bool => $row['status'] === 'down'));
    $this->info('Salud: '.count($results).' servicios verificados, '.$down.' caído(s).');
})->purpose('Chequeos reales de salud de los servicios de la plataforma');

Schedule::command('platform:health')->everyMinute()->withoutOverlapping();

Artisan::command('billing:run', function (SubscriptionService $subscriptions, SaaSBillingService $billing) {
    $renewals = $subscriptions->renewDue();
    $dunning = $billing->runDunning();
    $this->info('Renovaciones: '.json_encode($renewals).' · Cobranza: '.json_encode($dunning));
})->purpose('Renovaciones, fin de pruebas y política de cobranza de las suscripciones SaaS');

Schedule::command('billing:run')->hourly()->withoutOverlapping()->onOneServer();

Artisan::command('support:expire-access', function (SupportAccessService $access) {
    $this->info('Accesos de soporte vencidos: '.$access->expireDue());
})->purpose('Revoca los accesos temporales de soporte vencidos');

Schedule::command('support:expire-access')->everyMinute()->withoutOverlapping();

Artisan::command('usage:snapshot', function (UsageMeterService $usage) {
    $ids = DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->pluck('id');
    foreach ($ids as $id) {
        $usage->snapshot((int) $id);
    }
    $this->info('Consumo registrado de '.count($ids).' organización(es).');
})->purpose('Fotografía diaria del consumo de cada organización');

Schedule::command('usage:snapshot')->dailyAt('01:15')->withoutOverlapping();

Artisan::command('plans:limit-alerts', function (LimitAlertService $alerts) {
    $this->info('Avisos de límite enviados: '.$alerts->run());
})->purpose('Avisa a los dueños de firma al llegar al 80 % y al 95 % de un límite del plan');

Schedule::command('plans:limit-alerts')->dailyAt('01:45')->withoutOverlapping()->onOneServer();

Artisan::command('normative:sync', function () {
    $this->call('db:seed', ['--class' => NormativeLibrarySeeder::class, '--force' => true]);
})->purpose('Carga o actualiza la Biblioteca Normativa compartida desde database/data/normative');

Schedule::command('backup:create')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => (bool) config('backup.enabled'));

Artisan::command('office:staffing-alerts', function (OfficeStaffingAlertService $alerts) {
    $notified = app(TenantDatabaseContext::class)->runAsSystem(function () use ($alerts): int {
        $total = 0;

        foreach (Organization::where('status', 'active')->get() as $organization) {
            $total += $alerts->sweep($organization);
        }

        return $total;
    });
    $this->info('Avisos de empresas sin responsable: '.$notified);
})->purpose('Avisa a los administradores de oficina de empresas sin contador o auxiliar');

Schedule::command('office:staffing-alerts')->dailyAt('08:00')->withoutOverlapping()->onOneServer();

Artisan::command('crm:alerts', function (CrmAlertService $alerts) {
    $notified = app(TenantDatabaseContext::class)->runAsSystem(function () use ($alerts): int {
        $total = 0;

        foreach (Organization::where('status', 'active')->get() as $organization) {
            $total += $alerts->sweep($organization);
        }

        return $total;
    });
    $this->info('Alertas comerciales enviadas: '.$notified);
})->purpose('Envía como notificaciones las alertas comerciales: renovaciones, clientes en riesgo, sin contrato y onboarding');

Schedule::command('crm:alerts')->dailyAt('08:15')->withoutOverlapping()->onOneServer();

Artisan::command('fel:sat-alerts', function (SatPendingAlertService $alerts) {
    $notified = app(TenantDatabaseContext::class)->runAsSystem(function () use ($alerts): int {
        $total = 0;

        foreach (Organization::where('status', 'active')->get() as $organization) {
            $total += $alerts->sweep($organization);
        }

        return $total;
    });
    $this->info('Avisos de facturas sin emitir en la SAT: '.$notified);
})->purpose('Avisa al contador de las facturas preparadas que siguen sin emitirse en el portal de la SAT');

Schedule::command('fel:sat-alerts')->dailyAt('08:30')->withoutOverlapping()->onOneServer();

Artisan::command('tasks:remind', function (ReminderService $reminders) {
    $sent = app(TenantDatabaseContext::class)->runAsSystem(function () use ($reminders): int {
        $total = 0;

        foreach (Organization::where('status', 'active')->get() as $organization) {
            $total += $reminders->sweep($organization);
        }

        return $total;
    });
    $this->info('Recordatorios de tareas enviados: '.$sent);
})->purpose('Recuerda las tareas según su estado: por vencer, vencidas, en espera del cliente y revisiones pendientes');

Schedule::command('tasks:remind')->dailyAt('07:45')->withoutOverlapping()->onOneServer();

Artisan::command('tasks:sync', function (TaskSyncService $sync) {
    $changed = app(TenantDatabaseContext::class)->runAsSystem(function () use ($sync): int {
        $total = 0;

        foreach (Organization::where('status', 'active')->get() as $organization) {
            $total += $sync->sweep($organization);
        }

        return $total;
    });
    $this->info('Tareas alineadas con su origen: '.$changed);
})->purpose('Alinea cada tarea con el objeto de negocio que coordina (cobranza, pagos, nómina, conciliación, cierre, solicitudes)');

Schedule::command('tasks:sync')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Artisan::command('alerts:dispatch', function (AlertDispatchService $alerts) {
    $result = $alerts->run();
    $this->info('Alertas enviadas: '.$result['notifications'].' notificaciones, '.$result['emails'].' correos');
})->purpose('Envía las alertas de vencimientos fiscales, cobros y pagos según las preferencias de cada persona');

Schedule::command('alerts:dispatch')->hourly()->withoutOverlapping()->onOneServer();

Artisan::command('exchange-rates:sync {--days=7 : Días hacia atrás que se revisan}', function (BanguatExchangeRateService $banguat) {
    $result = $banguat->sync(Carbon::today()->subDays(max(0, (int) $this->option('days'))), Carbon::today());
    foreach ($result['currencies'] as $currency => $outcome) {
        is_int($outcome) ? $this->info("{$currency}: {$outcome} tipo(s) de cambio nuevo(s) o corregido(s).") : $this->error("{$currency}: {$outcome}");
    }

    return collect($result['currencies'])->every(fn ($outcome): bool => is_int($outcome)) ? 0 : 1;
})->purpose('Descarga del Banco de Guatemala los tipos de cambio oficiales del dólar y del euro');

Schedule::command('exchange-rates:sync')->twiceDailyAt(7, 17, 5)->withoutOverlapping()->onOneServer();

Artisan::command('luzma:morning-brief', function (TenantDatabaseContext $tenancy, LuzmaMorningBriefService $brief) {
    $sent = $tenancy->runAsSystem(fn (): int => $brief->sendAll());
    $this->info("Avisos de la mañana enviados: {$sent}.");
})->purpose('Envía a cada persona el aviso de la mañana de Luzma: impuestos de la semana, tareas vencidas y alertas abiertas');

Schedule::command('luzma:morning-brief')->weekdays()->at('07:20')->withoutOverlapping()->onOneServer();
