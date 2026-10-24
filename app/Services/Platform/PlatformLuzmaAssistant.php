<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\Platform\Billing\BillingMetricsService;
use App\Support\PlatformIssuer;
use App\Support\PlatformStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Luzma inside the Control Center. It knows the platform context, the SaaS role and its permissions, answers only
 * from platform metadata (never tenant content) and never changes the platform: suspending, cancelling, rotating
 * secrets, granting support access or switching global flags always go through the formal, confirmed flows.
 */
class PlatformLuzmaAssistant
{
    /** @var array<string, array{permission: string, keywords: list<string>, label: string}> */
    public const TOOLS = [
        'get_platform_health' => ['permission' => 'saas.services.view', 'keywords' => ['servicio', 'servicios', 'degradad', 'caido', 'salud', 'funcionando', 'estado'], 'label' => 'Estado de servicios'],
        'get_failed_jobs_summary' => ['permission' => 'saas.jobs.view', 'keywords' => ['job', 'jobs', 'cola', 'fallan', 'fallidos'], 'label' => 'Jobs fallidos'],
        'get_billing_risk' => ['permission' => 'saas.billing.view', 'keywords' => ['vencid', 'pago', 'pagos', 'mora', 'gracia', 'cobro'], 'label' => 'Facturación en riesgo'],
        'get_usage_alerts' => ['permission' => 'saas.usage.view', 'keywords' => ['limite', 'limites', 'consumo', 'superaron', 'cerca'], 'label' => 'Límites y consumo'],
        'get_active_incidents' => ['permission' => 'saas.incidents.view', 'keywords' => ['incidente', 'incidentes', 'caida'], 'label' => 'Incidentes activos'],
        'get_support_queue' => ['permission' => 'saas.tickets.view', 'keywords' => ['ticket', 'tickets', 'soporte'], 'label' => 'Cola de soporte'],
        'get_luzma_health' => ['permission' => 'saas.luzma.view', 'keywords' => ['luzma', 'modelo', 'ollama', 'ia'], 'label' => 'Estado de Luzma'],
        'get_subscription_summary' => ['permission' => 'saas.subscriptions.view', 'keywords' => ['suscripcion', 'suscripciones', 'mrr', 'clientes', 'plan', 'planes', 'precio', 'precios', 'organizaciones'], 'label' => 'Suscripciones'],
        'get_attention' => ['permission' => 'saas.dashboard.view', 'keywords' => ['atencion', 'hoy', 'pendiente', 'resumen'], 'label' => 'Requiere atención'],
    ];

    private const FORBIDDEN = ['suspend', 'suspende', 'cancela', 'rota', 'rotar', 'otorga', 'aprueba', 'activa el flag', 'desactiva', 'elimina', 'borra'];

    public function __construct(private PlatformAuthorization $authorization, private SaaSDashboardService $dashboard, private PlatformHealthService $health, private JobMonitoringService $jobs,
        private BillingMetricsService $billing, private LuzmaPlatformService $luzma, private LuzmaModelProvider $model, private PlatformAuditService $audit) {}

    /** @return array{answer: string, tool: string|null, facts: list<string>, references: list<array{label: string, href: string}>, source: string} */
    public function ask(User $user, string $question): array
    {
        $normalized = Str::lower(Str::ascii($question));
        foreach (self::FORBIDDEN as $verb) {
            if (str_contains($normalized, $verb)) {
                return ['answer' => 'No puedo cambiar la plataforma desde el chat. Suspender organizaciones, cancelar suscripciones, rotar secretos, aprobar accesos de soporte o cambiar flags globales se hace en su pantalla, con motivo, confirmación y reautenticación.',
                    'tool' => null, 'facts' => [], 'references' => [], 'source' => 'policy'];
            }
        }
        $tool = 'get_attention';
        $best = 0;
        foreach (self::TOOLS as $name => $definition) {
            $score = count(array_filter($definition['keywords'], fn (string $keyword): bool => str_contains($normalized, $keyword)));
            if ($score > $best && $this->authorization->can($user, $definition['permission'])) {
                [$tool, $best] = [$name, $score];
            }
        }
        if (! $this->authorization->can($user, self::TOOLS[$tool]['permission'])) {
            return ['answer' => 'Tu rol SaaS no tiene acceso a esa información.', 'tool' => null, 'facts' => [], 'references' => [], 'source' => 'policy'];
        }
        [$facts, $references] = $this->run($tool);
        $this->audit->record($user, 'luzma.platform_query', 'platform', $tool, null, ['tool' => $tool]);
        $answer = $this->compose($question, $tool, $facts);

        return ['answer' => $answer['text'], 'tool' => $tool, 'facts' => $facts, 'references' => $references, 'source' => $answer['source']];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    public function run(string $tool): array
    {
        return match ($tool) {
            'get_platform_health' => $this->healthFacts(),
            'get_failed_jobs_summary' => $this->jobFacts(),
            'get_billing_risk' => $this->billingFacts(),
            'get_usage_alerts' => $this->usageFacts(),
            'get_active_incidents' => $this->incidentFacts(),
            'get_support_queue' => $this->supportFacts(),
            'get_luzma_health' => $this->luzmaFacts(),
            'get_subscription_summary' => $this->subscriptionFacts(),
            default => $this->attentionFacts(),
        };
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function healthFacts(): array
    {
        $snapshot = $this->health->snapshot();
        $bad = array_values(array_filter($snapshot, fn (array $s): bool => $s['status'] !== 'healthy'));
        $facts = [count($snapshot) - count($bad).' de '.count($snapshot).' servicios operativos.'];
        foreach ($bad as $service) {
            $facts[] = $service['name'].': '.$service['label'].($service['latest_error'] ? ' — '.$service['latest_error'] : '').'.';
        }

        return [$facts, [['label' => 'Servicios', 'href' => '/control-center/services']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function jobFacts(): array
    {
        $groups = $this->jobs->failedGroups();
        $queue = $this->jobs->queueHealth();
        $facts = [$queue['failed_24h'].' jobs fallidos en 24 h · '.$queue['pending'].' en cola · tasa de falla '.($queue['failed_rate'] === null ? 'sin datos' : $queue['failed_rate'].' %').' en la última hora.'];
        usort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        foreach (array_slice($groups, 0, 5) as $group) {
            $facts[] = $group['job'].' falló '.$group['count'].' vez/veces: '.$group['cause'].($group['retryable'] ? ' (se puede reintentar).' : ' (no se reintenta a ciegas).');
        }

        return [$facts, [['label' => 'Jobs y colas', 'href' => '/control-center/jobs']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function billingFacts(): array
    {
        $risk = $this->dashboard->billingAtRisk();
        $receivables = $this->billing->receivables();
        $facts = ['Pendiente de cobro: GTQ '.number_format($receivables['open'] + $receivables['past_due'], 2).' ('.$receivables['past_due_invoices'].' factura(s) vencida(s)).'];
        foreach ($risk as $row) {
            $facts[] = $row['organization'].': factura '.$row['invoice'].' por '.$row['currency'].' '.number_format($row['amount'], 2).', '.$row['days_overdue'].' días de atraso ('.$row['subscription_label'].').';
        }

        return [$facts, [['label' => 'Facturación', 'href' => '/control-center/billing']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function usageFacts(): array
    {
        $facts = [];
        $usage = app(UsageMeterService::class);
        foreach (DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->get(['id', 'name']) as $organization) {
            foreach ($usage->usage((int) $organization->id) as $row) {
                if (in_array($row['status'], ['high', 'at_limit', 'over_limit'], true)) {
                    $facts[] = $organization->name.': '.$row['label'].' '.$row['value'].'/'.$row['limit'].' ('.$row['percent'].' %'.($row['status'] === 'over_limit' ? ', por encima del límite' : '').').';
                }
            }
        }

        return [$facts === [] ? ['Ninguna organización está cerca de sus límites.'] : $facts, [['label' => 'Límites y consumo', 'href' => '/control-center/usage']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function incidentFacts(): array
    {
        $incidents = app(IncidentManagementService::class)->list(true);
        $facts = $incidents === [] ? ['No existen incidentes activos.'] : array_map(fn (array $i): string => $i['code'].' '.$i['title'].' · '.$i['severity_label'].' · '.$i['status_label'].' · '.$i['affected_organizations'].' organización(es) afectada(s).', $incidents);

        return [$facts, [['label' => 'Incidentes', 'href' => '/control-center/incidents']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function supportFacts(): array
    {
        $open = DB::table('support_tickets')->whereNotIn('status', ['resolved', 'closed'])->selectRaw('priority, CAST(COUNT(*) AS BIGINT) as total')->groupBy('priority')->pluck('total', 'priority');
        $facts = [array_sum($open->all()).' ticket(s) abiertos: '.collect(['critical' => 'críticos', 'urgent' => 'urgentes', 'high' => 'altos', 'normal' => 'normales', 'low' => 'bajos'])->map(fn (string $label, string $key): string => ((int) ($open[$key] ?? 0)).' '.$label)->implode(', ').'.',
            DB::table('support_tickets')->whereNotIn('status', ['resolved', 'closed'])->whereNull('assigned_to')->count().' sin asignar.',
            DB::table('support_access_requests')->where('status', 'requested')->count().' solicitud(es) de acceso de soporte esperando aprobación.'];

        return [$facts, [['label' => 'Tickets', 'href' => '/control-center/support/tickets']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function luzmaFacts(): array
    {
        $status = $this->luzma->status();

        return [['Luzma: '.($status['status'] === 'healthy' ? 'saludable' : ($status['status'] === 'maintenance' ? 'deshabilitada' : 'con problemas')).($status['detail'] ? ' — '.$status['detail'] : '').'.',
            'Modelo: '.($status['model'] ?? 'no reportado').' · latencia '.($status['latency_ms'] ?? '—').' ms.',
            'Hoy: '.$status['requests_today'].' respuestas, '.$status['errors_today'].' con error.'], [['label' => 'Estado de Luzma', 'href' => '/control-center/luzma']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function subscriptionFacts(): array
    {
        $mrr = $this->billing->mrr();
        $byStatus = DB::table('subscriptions')->where('provider', '!=', 'internal')->groupBy('status')->selectRaw('status, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'status');
        $facts = ['MRR GTQ '.number_format($mrr['mrr'], 2).' (ARR GTQ '.number_format($mrr['arr'], 2).') de '.$mrr['subscriptions'].' suscripción(es) recurrente(s).',
            $byStatus->isEmpty() ? 'Todavía no hay suscripciones de clientes.' : 'Por estado: '.$byStatus->map(fn ($total, $status): string => (PlatformStatus::SUBSCRIPTION_LABELS[$status] ?? $status).' '.$total)->implode(', ').'.'];

        // The catalog on sale: each active plan with its prices and how many client subscriptions are running on it.
        $running = DB::table('subscriptions')->join('subscription_items', 'subscription_items.subscription_id', '=', 'subscriptions.id')
            ->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->where('subscription_items.item_type', 'base_plan')
            ->where('subscriptions.provider', '!=', 'internal')->whereIn('subscriptions.status', PlatformStatus::SUBSCRIPTION_ACCESS)
            ->groupBy('plan_prices.plan_id')->selectRaw('plan_prices.plan_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'plan_id');
        foreach (DB::table('plans')->where('is_active', true)->orderBy('display_order')->get(['id', 'name']) as $plan) {
            $prices = DB::table('plan_prices')->where('plan_id', $plan->id)->where('is_active', true)->where('currency_code', 'GTQ')->pluck('amount', 'billing_period');
            $facts[] = 'Plan '.$plan->name.': '.(isset($prices['monthly']) ? 'GTQ '.number_format((float) $prices['monthly'], 2).' al mes' : 'sin precio mensual')
                .(isset($prices['annual']) ? ' o GTQ '.number_format((float) $prices['annual'], 2).' al año' : '').' · '.(int) ($running[$plan->id] ?? 0).' suscripción(es) vigente(s).';
        }

        return [$facts, [['label' => 'Suscripciones', 'href' => '/control-center/subscriptions'], ['label' => 'Planes', 'href' => '/control-center/plans']]];
    }

    /** @return array{0: list<string>, 1: list<array{label: string, href: string}>} */
    private function attentionFacts(): array
    {
        $items = $this->dashboard->attention();

        return [$items === [] ? ['Nada requiere atención en este momento.'] : array_map(fn (array $item): string => $item['count'].' '.$item['title'].' — '.$item['subtitle'].'.', $items),
            [['label' => 'Inicio', 'href' => '/control-center']]];
    }

    /**
     * @param  list<string>  $facts
     * @return array{text: string, source: string}
     */
    private function compose(string $question, string $tool, array $facts): array
    {
        $fallback = implode("\n", array_map(fn (string $fact): string => '• '.$fact, $facts));
        // Luzma's own engine (brain) is built for the firms' questions and cannot word platform data: the official facts are
        // the answer then, so the administrator always gets the figures instead of a generic reply.
        if (str_contains((string) ($this->model->health()['model'] ?? ''), 'brain')) {
            return ['text' => 'Esto es lo que veo en Atlantia ('.mb_strtolower(self::TOOLS[$tool]['label']).'):'."\n".$fallback, 'source' => 'facts'];
        }
        try {
            $text = $this->model->answer('Eres Luzma en el Atlantia Control Center (superadministración SaaS). Responde en español, breve, usando solo los datos entregados. No inventes cifras. '
                .'No accedes a datos internos de las organizaciones cliente. No ejecutas cambios: indica la pantalla donde hacerlos.', [['role' => 'user', 'content' => $question."\n\nDatos (".self::TOOLS[$tool]['label']."):\n".$fallback]]);

            return ['text' => $text, 'source' => 'model'];
        } catch (Throwable) {
            return ['text' => $fallback, 'source' => 'facts'];
        }
    }
}
