<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Traceability and control of the firm for the director: audit findings, controls, sensitive changes and access. Read only. */
class AuditOverviewService
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    private const SEVERITY_WEIGHT = ['critical' => 10, 'high' => 5, 'medium' => 2, 'low' => 1];

    /** Action prefixes that change who can do what or how the firm is configured. */
    private const SENSITIVE = ['office.' => 'Alto', 'role' => 'Crítico', 'permission' => 'Crítico', 'user.deactivated' => 'Alto', 'users.' => 'Alto', 'integration' => 'Alto', 'fiscal' => 'Alto', 'company.moved' => 'Alto', 'audit.external_access' => 'Crítico', 'security' => 'Crítico', 'api_token' => 'Crítico', 'webhook' => 'Alto'];

    public function __construct(private WorkDashboardService $dashboard) {}

    /** @return array<string, mixed> */
    public function payload(Organization $organization, User $user): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive');
        $offices = collect($executive['offices'])->keyBy('id');
        $companyOffice = DB::table('companies')->whereIn('office_id', $offices->keys()->all())->pluck('office_id', 'id');
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth();
        $previousStart = $monthStart->subMonth();

        $findings = DB::table('audit_findings')->where('organization_id', $organization->id)->get(['id', 'title', 'severity', 'company_id', 'opened_at', 'closed_at', 'status', 'due_date', 'created_at']);
        $open = $findings->whereNull('closed_at');
        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id);
        $eventsNow = (int) (clone $logs)->where('audit_logs.occurred_at', '>=', $monthStart)->count();
        $eventsPrevious = (int) (clone $logs)->whereBetween('audit_logs.occurred_at', [$previousStart, $monthStart])->count();

        $assessments = DB::table('audit_control_assessments')->where('organization_id', $organization->id)->orderByDesc('assessed_at')->get(['audit_control_id', 'effectiveness', 'assessed_at']);
        $latest = $assessments->unique('audit_control_id');
        $controlBuckets = [
            ['label' => 'Conforme', 'value' => $latest->where('effectiveness', 'effective')->count(), 'color' => '#16a34a'],
            ['label' => 'Con observación', 'value' => $latest->where('effectiveness', 'partially_effective')->count(), 'color' => '#f0c419'],
            ['label' => 'En revisión', 'value' => $latest->whereIn('effectiveness', ['not_tested', 'not_applicable'])->count(), 'color' => '#f97316'],
            ['label' => 'No conforme', 'value' => $latest->where('effectiveness', 'ineffective')->count(), 'color' => '#dc2626'],
        ];

        $months = collect(range(5, 0))->map(fn (int $back) => $monthStart->subMonths($back));
        $stacked = $months->map(function (CarbonImmutable $month) use ($findings): array {
            $slice = $findings->filter(fn (object $row): bool => substr((string) $row->opened_at, 0, 7) === $month->format('Y-m'));

            return ['label' => self::MONTHS[$month->month - 1], 'critical' => $slice->where('severity', 'critical')->count(), 'high' => $slice->where('severity', 'high')->count(), 'medium' => $slice->where('severity', 'medium')->count(), 'low' => $slice->where('severity', 'low')->count()];
        })->values();

        $score = function (Collection $rows, ?CarbonImmutable $asOf = null): int {
            $rows = $asOf === null ? $rows->whereNull('closed_at') : $rows->filter(fn (object $row): bool => $row->opened_at <= $asOf->toDateTimeString() && ($row->closed_at === null || $row->closed_at > $asOf->toDateTimeString()));

            return max(0, 100 - (int) $rows->sum(fn (object $row): int => self::SEVERITY_WEIGHT[$row->severity] ?? 1));
        };

        $recent = (clone $logs)->orderByDesc('audit_logs.occurred_at')->limit(5)->get(['audit_logs.action', 'audit_logs.occurred_at', 'users.name as user_name']);
        $sensitive = (clone $logs)->where('audit_logs.occurred_at', '>=', $now->subDays(30))->orderByDesc('audit_logs.occurred_at')->limit(300)->get(['audit_logs.action', 'audit_logs.occurred_at', 'users.name as user_name'])
            ->map(fn (object $row): ?array => ($level = $this->sensitivity((string) $row->action)) === null ? null : ['title' => $this->label((string) $row->action), 'by' => $row->user_name ?? 'Sistema', 'at' => $row->occurred_at, 'level' => $level])
            ->filter()->values();
        $failed = DB::table('login_attempts')->where('succeeded', false)->where('created_at', '>=', $now->subDays(7))->selectRaw('email, CAST(COUNT(*) AS BIGINT) as total, MAX(created_at) as last_at, MAX(ip) as ip')->groupBy('email')->havingRaw('COUNT(*) >= 3')->orderByDesc('total')->limit(6)->get();

        $evidence = DB::table('audit_evidence_requests')->leftJoin('users', 'users.id', '=', 'audit_evidence_requests.requested_from_user_id')->where('audit_evidence_requests.organization_id', $organization->id)
            ->whereIn('audit_evidence_requests.status', ['requested', 'pending', 'submitted'])->orderBy('audit_evidence_requests.due_date')->limit(6)
            ->get(['audit_evidence_requests.title', 'audit_evidence_requests.due_date', 'audit_evidence_requests.status', 'users.name as assigned']);
        $reviews = DB::table('company_period_reviews')->join('companies', 'companies.id', '=', 'company_period_reviews.company_id')->leftJoin('users', 'users.id', '=', 'company_period_reviews.reviewer_id')
            ->where('company_period_reviews.organization_id', $organization->id)->whereNull('company_period_reviews.deleted_at')->whereIn('company_period_reviews.status', ['submitted', 'in_review', 'changes_requested'])
            ->orderByDesc('company_period_reviews.submitted_at')->limit(6)->get(['companies.name as company', 'company_period_reviews.status', 'users.name as assigned', 'company_period_reviews.submitted_at']);

        return [
            'kpis' => [
                'events' => $eventsNow,
                'events_change' => $eventsPrevious > 0 ? (int) round(($eventsNow - $eventsPrevious) / $eventsPrevious * 100) : null,
                'critical' => $open->where('severity', 'critical')->count(),
                'observations' => $open->count(),
                'controls_reviewed' => $latest->count(),
                'conforming' => $latest->isEmpty() ? null : (int) round($latest->where('effectiveness', 'effective')->count() / $latest->count() * 1000) / 10,
            ],
            'findings_by_month' => $stacked->all(),
            'severity' => collect(['critical' => ['Críticos', '#dc2626'], 'high' => ['Altos', '#f97316'], 'medium' => ['Medios', '#f0c419'], 'low' => ['Bajos', '#9ca3af']])
                ->map(fn (array $meta, string $key): array => ['label' => $meta[0], 'value' => $open->where('severity', $key)->count(), 'color' => $meta[1]])->values()->all(),
            'controls' => $controlBuckets,
            'activity' => $recent->map(fn (object $row): array => ['who' => $row->user_name ?? 'Sistema', 'what' => $this->label((string) $row->action), 'at' => $row->occurred_at, 'level' => $this->sensitivity((string) $row->action) ?? 'Bajo'])->all(),
            'exceptions' => $open->whereIn('severity', ['critical', 'high'])->sortByDesc('opened_at')->take(5)->map(fn (object $row): array => ['title' => $row->title, 'severity' => $row->severity, 'at' => $row->opened_at])->values()->all(),
            'exceptions_total' => $open->whereIn('severity', ['critical', 'high'])->count(),
            'high_risk_changes' => $sensitive->take(4)->all(),
            'high_risk_total' => $sensitive->count(),
            'unusual_access' => $failed->map(fn (object $row): array => ['title' => 'Múltiples intentos fallidos de acceso', 'detail' => $row->email.' · '.$row->total.' intentos'.($row->ip ? ' · IP '.$row->ip : ''), 'at' => $row->last_at])->all(),
            'pending_reviews' => $reviews->map(fn (object $row): array => ['type' => 'Revisión de cierre', 'description' => $row->company, 'assigned' => $row->assigned, 'due' => substr((string) $row->submitted_at, 0, 10), 'status' => $row->status])
                ->concat($evidence->map(fn (object $row): array => ['type' => 'Solicitud de evidencia', 'description' => $row->title, 'assigned' => $row->assigned, 'due' => $row->due_date, 'status' => $row->status]))->take(8)->values()->all(),
            'office_scores' => $offices->map(function (array $office, int $id) use ($findings, $companyOffice, $score, $now): array {
                $mine = $findings->filter(fn (object $row): bool => ($companyOffice[$row->company_id] ?? null) === $id);
                $current = $score($mine);

                return ['name' => $office['name'], 'score' => $current, 'trend' => $current - $score($mine, $now->startOfMonth()->subSecond())];
            })->sortByDesc('score')->values()->all(),
            'has_audit_work' => $findings->isNotEmpty() || $assessments->isNotEmpty(),
        ];
    }

    private function sensitivity(string $action): ?string
    {
        foreach (self::SENSITIVE as $prefix => $level) {
            if (str_contains($action, rtrim($prefix, '.'))) {
                return $level;
            }
        }

        return null;
    }

    private function label(string $action): string
    {
        $known = [
            'office.created' => 'Creó una oficina', 'office.updated' => 'Modificó una oficina', 'company.created' => 'Creó una empresa', 'executive_report.exported' => 'Exportó un reporte ejecutivo',
            'training.course_created' => 'Registró un curso', 'crm.contract_saved' => 'Guardó un contrato', 'revenue.invoice_created' => 'Registró un cobro', 'revenue.invoice_paid' => 'Registró un pago',
        ];

        return $known[$action] ?? ucfirst(str_replace(['.', '_'], [' · ', ' '], $action));
    }
}
