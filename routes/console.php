<?php

use App\Jobs\LuzmaMaintenanceJob;
use App\Models\Organization;
use App\Services\Integrations\WebhookDeliveryService;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\PlatformHealthService;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\UsageMeterService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\OfficeStaffingAlertService;
use Database\Seeders\NormativeLibrarySeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fel:recover')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('luzma:run', function () {
    if (! config('luzma.enabled')) {
        return;
    }
    $ids = app(TenantDatabaseContext::class)->runAsSystem(fn () => Organization::where('status', 'active')->pluck('id'));
    foreach ($ids as $id) {
        LuzmaMaintenanceJob::dispatch((int) $id);
    }
    $this->info('Luzma: '.count($ids).' organización(es) en cola.');
})->purpose('Recordatorios automáticos, entrega de recordatorios e insights de Luzma');

Schedule::command('luzma:run')->everyFiveMinutes()->withoutOverlapping();

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
    $ids = DB::table('organizations')->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->pluck('id');
    foreach ($ids as $id) {
        $usage->snapshot((int) $id);
    }
    $this->info('Consumo registrado de '.count($ids).' organización(es).');
})->purpose('Fotografía diaria del consumo de cada organización');

Schedule::command('usage:snapshot')->dailyAt('01:15')->withoutOverlapping();

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
