import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    Building2,
    CalendarClock,
    ClipboardList,
    FileSearch,
    FileText,
} from 'lucide-react';
import {
    Metric,
    Progress,
    ViewAll,
    engagementTone,
    evidenceTone,
    findingTone,
    procedureColor,
    severityTone,
    useAuditorHref,
} from '@/components/auditor/kit';
import type {
    AuditorContext,
    Engagement,
    EvidenceRequest,
    Finding,
} from '@/components/auditor/kit';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Panel = {
    metrics: Record<
        string,
        number | { code: string; planned_end_date: string } | null
    >;
    active_audits: Engagement[];
    procedure_status: { key: string; label: string; count: number }[];
    recent_findings: Finding[];
    evidence_requests: EvidenceRequest[];
    upcoming_activities: {
        date: string;
        type: string;
        title: string;
        engagement: string;
        company: string;
        priority: string;
        destination: string;
    }[];
    assigned_companies: {
        id: number;
        name: string;
        tax_id: string;
        audits: number;
        active_audits: number;
        last_activity: string | null;
        risk_level: string | null;
        risk_label: string | null;
        status: string;
        status_label: string;
    }[];
};

/** Procedure status donut drawn from the backend counts. */
function Donut({ rows }: { rows: Panel['procedure_status'] }) {
    const total = rows.reduce((sum, row) => sum + row.count, 0);
    const radius = 60;
    const circumference = 2 * Math.PI * radius;
    const segments = rows.reduce<
        { row: (typeof rows)[number]; length: number; offset: number }[]
    >((list, row) => {
        const offset = list.reduce((sum, segment) => sum + segment.length, 0);

        return [
            ...list,
            {
                row,
                length: total === 0 ? 0 : (row.count / total) * circumference,
                offset,
            },
        ];
    }, []);

    return (
        <div className="flex flex-wrap items-center gap-4">
            <svg viewBox="0 0 160 160" className="size-40 shrink-0">
                <circle
                    cx="80"
                    cy="80"
                    r={radius}
                    fill="none"
                    stroke="#eef2f7"
                    strokeWidth="22"
                />
                {segments.map(({ row, length, offset }) =>
                    length > 0 ? (
                        <circle
                            key={row.key}
                            cx="80"
                            cy="80"
                            r={radius}
                            fill="none"
                            stroke={procedureColor[row.key] ?? '#94a3b8'}
                            strokeWidth="22"
                            strokeDasharray={`${length} ${circumference - length}`}
                            strokeDashoffset={-offset}
                            transform="rotate(-90 80 80)"
                        />
                    ) : null,
                )}
                <text
                    x="80"
                    y="78"
                    textAnchor="middle"
                    className="fill-[#061633] text-3xl font-black"
                >
                    {total}
                </text>
                <text
                    x="80"
                    y="98"
                    textAnchor="middle"
                    className="fill-[#526b92] text-xs"
                >
                    Total
                </text>
            </svg>
            <div className="grid min-w-52 flex-1 gap-1.5 text-xs">
                {rows.map((row) => (
                    <p key={row.key} className="flex items-center gap-2">
                        <span
                            className="size-3 rounded-full"
                            style={{
                                backgroundColor:
                                    procedureColor[row.key] ?? '#94a3b8',
                            }}
                        />
                        <span className="flex-1 text-[#526b92]">
                            {row.label}
                        </span>
                        <b className="w-8 text-right">{row.count}</b>
                        <span className="w-10 text-right text-[#526b92]">
                            {total === 0
                                ? 0
                                : Math.round((row.count / total) * 100)}
                            %
                        </span>
                    </p>
                ))}
            </div>
        </div>
    );
}

export default function AuditorDashboard({
    context,
    panel,
    filters,
    statuses,
    notice,
}: {
    context: AuditorContext;
    panel: Panel;
    filters: { company_id: number | null; status: string };
    statuses: Record<string, string>;
    notice: string | null;
}) {
    const href = useAuditorHref();
    const m = panel.metrics as Record<string, number>;
    const next = panel.metrics.next_deadline as {
        code: string;
        planned_end_date: string;
    } | null;
    const filter = (key: string, value: string) =>
        router.get(
            href('/work/audit'),
            { ...filters, [key]: value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Inicio Auditor" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Inicio Auditor"
                    subtitle="Vista general de tus auditorías, avances, hallazgos y actividades pendientes."
                    actions={
                        <>
                            <span className="flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs">
                                <CalendarClock className="size-4 text-[#526b92]" />
                                Hoy, {formatDate(new Date().toISOString())}
                            </span>
                            <select
                                aria-label="Empresa"
                                className={`${input} w-52`}
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    filter('company_id', event.target.value)
                                }
                            >
                                <option value="">Todas las empresas</option>
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Estado"
                                className={`${input} w-48`}
                                value={filters.status}
                                onChange={(event) =>
                                    filter('status', event.target.value)
                                }
                            >
                                <option value="">
                                    Todos los estados activos
                                </option>
                                {Object.entries(statuses).map(
                                    ([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </>
                    }
                />
                <LuzmaInsightsCard module="audit_desk" />

                <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
                    <Metric
                        label="Empresas asignadas"
                        value={m.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/work/audit/engagements')}
                        hint={`${m.in_final_review} en revisión final`}
                    />
                    <Metric
                        label="Auditorías activas"
                        value={m.active_audits}
                        icon={ClipboardList}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/work/audit/engagements?status=active')}
                        hint={`${m.near_deadline_audits} próxima(s) a vencer`}
                    />
                    <Metric
                        label="Procedimientos pendientes"
                        value={m.pending_procedures}
                        icon={FileText}
                        tone="bg-amber-50 text-amber-700"
                        href={href('/work/audit/procedures')}
                        hint={`${m.procedures_waiting_evidence} en espera de evidencia`}
                    />
                    <Metric
                        label="Solicitudes de evidencia"
                        value={m.pending_evidence_requests}
                        icon={FileSearch}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/work/audit/evidence')}
                        hint={`${m.overdue_evidence_requests} vencida(s)`}
                    />
                    <Metric
                        label="Hallazgos abiertos"
                        value={m.open_findings}
                        icon={AlertTriangle}
                        tone="bg-red-50 text-red-700"
                        href={href('/work/audit/findings')}
                        hint={`${m.critical_findings} crítico(s)`}
                    />
                    <Metric
                        label="Correcciones por verificar"
                        value={m.corrections_to_verify}
                        icon={BadgeCheck}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/work/audit/findings?tab=to_verify')}
                        hint={`${m.overdue_verifications} vencida(s)`}
                    />
                    <Metric
                        label="Auditoría próxima a vencer"
                        value={m.near_deadline_audits}
                        icon={CalendarClock}
                        tone="bg-slate-100 text-slate-700"
                        href={href('/work/audit/calendar')}
                        hint={
                            next
                                ? `${next.code} · ${formatDate(next.planned_end_date)}`
                                : 'Sin fechas próximas'
                        }
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.9fr)_minmax(0,1fr)]">
                    <Card
                        title="Auditorías activas"
                        action={
                            <ViewAll
                                href={href('/work/audit/engagements')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Código',
                                'Empresa',
                                'Período',
                                'Tipo',
                                'Avance',
                                'Hallazgos',
                                'Evidencia',
                                'Estado',
                                'Fecha límite',
                                'Acciones',
                            ]}
                            empty={
                                panel.active_audits.length === 0 &&
                                'No tienes auditorías asignadas.'
                            }
                        >
                            {panel.active_audits.map((audit) => (
                                <tr key={audit.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(audit.destination)}
                                            className="text-blue-700 hover:underline"
                                        >
                                            {audit.code}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {audit.company}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {audit.period}
                                    </td>
                                    <td className="px-2 py-2">
                                        {audit.type_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Progress value={audit.progress} />
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                audit.findings_count > 0
                                                    ? 'red'
                                                    : 'slate',
                                            )}
                                        >
                                            {audit.findings_count}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                audit.evidence_pending > 0
                                                    ? 'amber'
                                                    : 'slate',
                                            )}
                                        >
                                            {audit.evidence_pending}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                engagementTone[audit.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {audit.status_label}
                                        </span>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${audit.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(audit.deadline)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={href(audit.destination)}
                                            className="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-bold"
                                        >
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Estado de procedimientos">
                        <Donut rows={panel.procedure_status} />
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Hallazgos recientes"
                        action={
                            <ViewAll
                                href={href('/work/audit/findings?tab=all')}
                                label="Ver todos"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Código',
                                'Empresa',
                                'Área',
                                'Título',
                                'Severidad',
                                'Estado',
                                'Fecha',
                            ]}
                            empty={
                                panel.recent_findings.length === 0 &&
                                'No existen hallazgos abiertos.'
                            }
                        >
                            {panel.recent_findings.map((finding) => (
                                <tr key={finding.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(finding.destination)}
                                            className="text-blue-700 hover:underline"
                                        >
                                            {finding.code}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {finding.company}
                                    </td>
                                    <td className="px-2 py-2">
                                        {finding.area_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {finding.title}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                severityTone[
                                                    finding.severity
                                                ] ?? 'slate',
                                            )}
                                        >
                                            {finding.severity_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                findingTone[finding.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {finding.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(finding.created_at)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Solicitudes de evidencia"
                        action={
                            <ViewAll
                                href={href('/work/audit/evidence')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Código',
                                'Empresa',
                                'Descripción',
                                'Estado',
                                'Fecha límite',
                            ]}
                            empty={
                                panel.evidence_requests.length === 0 &&
                                'No existen solicitudes de evidencia pendientes.'
                            }
                        >
                            {panel.evidence_requests.map((request) => (
                                <tr key={request.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(request.destination)}
                                            className="text-blue-700 hover:underline"
                                        >
                                            {request.code}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {request.company}
                                    </td>
                                    <td className="px-2 py-2">
                                        {request.title}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                evidenceTone[request.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {request.status_label}
                                        </span>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${request.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(request.due_date)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Mis próximas actividades"
                        action={
                            <ViewAll
                                href={href('/work/audit/calendar')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Actividad',
                                'Auditoría',
                                'Empresa',
                                'Prioridad',
                                'Acciones',
                            ]}
                            empty={
                                panel.upcoming_activities.length === 0 &&
                                'No tienes actividades próximas.'
                            }
                        >
                            {panel.upcoming_activities.map(
                                (activity, index) => (
                                    <tr key={index}>
                                        <td
                                            className={`px-2 py-2 whitespace-nowrap ${activity.date < new Date().toISOString().slice(0, 10) ? 'font-bold text-red-700' : ''}`}
                                        >
                                            {formatDate(activity.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {activity.title}
                                        </td>
                                        <td className="px-2 py-2">
                                            {activity.engagement}
                                        </td>
                                        <td className="px-2 py-2">
                                            {activity.company}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    activity.priority === 'high'
                                                        ? 'red'
                                                        : 'amber',
                                                )}
                                            >
                                                {activity.priority === 'high'
                                                    ? 'Alta'
                                                    : 'Media'}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            <Link
                                                href={href(
                                                    activity.destination,
                                                )}
                                                className="rounded-md border border-blue-300 px-2 py-1 text-[11px] font-bold text-blue-700"
                                            >
                                                Abrir
                                            </Link>
                                        </td>
                                    </tr>
                                ),
                            )}
                        </Table>
                    </Card>
                    <Card
                        title="Empresas asignadas"
                        action={
                            <ViewAll
                                href={href('/work/audit/engagements')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Auditorías',
                                'Última actividad',
                                'Nivel de riesgo',
                                'Estado general',
                            ]}
                            empty={
                                panel.assigned_companies.length === 0 &&
                                'No tienes empresas asignadas para auditoría.'
                            }
                        >
                            {panel.assigned_companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-2 py-2 font-semibold">
                                        {company.name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.audits}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(company.last_activity)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.risk_label ? (
                                            <span
                                                className={toneBadge(
                                                    severityTone[
                                                        company.risk_level ?? ''
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {company.risk_label}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                engagementTone[
                                                    company.status
                                                ] ?? 'slate',
                                            )}
                                        >
                                            {company.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
                <p className="text-[11px] text-[#526b92]">
                    El auditor observa, verifica, documenta y reporta; las
                    correcciones se hacen en los módulos de origen y el auditor
                    las verifica. Los avances y vencimientos provienen del
                    servidor.
                </p>
            </Shell>
        </>
    );
}
