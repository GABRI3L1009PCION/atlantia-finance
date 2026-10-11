import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    ActivitySection,
    EvidenceSection,
    FindingsSection,
    PapersSection,
    PlanningSection,
    ProceduresSection,
    ReportSection,
    RisksSection,
    SamplesSection,
    labelList,
} from '@/components/auditor/engagement-sections';
import type { Can, Options } from '@/components/auditor/engagement-sections';
import {
    Progress,
    engagementTone,
    severityTone,
    useAuditorHref,
} from '@/components/auditor/kit';
import type {
    AuditorContext,
    Engagement,
    EvidenceRequest,
    Finding,
    Paper,
    Procedure,
    Report,
    Response,
    Sample,
} from '@/components/auditor/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, toneBadge } from '@/components/supervisor/kit';

type Detail = Engagement & {
    objective: string | null;
    criteria: string | null;
    limitations: string | null;
    conclusion: string | null;
    areas: string[];
    excluded_areas: string[];
    scope_version: number;
    scope_snapshot: Record<string, unknown> | null;
    scope_frozen: boolean;
    status_reason: string | null;
    planned_start_date: string | null;
    planned_end_date: string | null;
    actual_start_date: string | null;
    closed_at: string | null;
    reviewer: string | null;
    progress_detail: {
        percent: number;
        planning: boolean;
        procedures_done: number;
        procedures_total: number;
        weight_done: number;
        weight_total: number;
        review: boolean;
        report: boolean;
    };
};

const tabs: [string, string][] = [
    ['summary', 'Resumen'],
    ['planning', 'Planificación'],
    ['risks', 'Riesgos'],
    ['procedures', 'Procedimientos'],
    ['samples', 'Muestras'],
    ['evidence', 'Evidencias'],
    ['papers', 'Papeles de trabajo'],
    ['findings', 'Hallazgos'],
    ['follow_up', 'Seguimiento'],
    ['report', 'Informe'],
    ['activity', 'Actividad'],
];

export default function AuditorEngagement(props: {
    context: AuditorContext;
    engagement: Detail;
    team: { id: number; name: string; role: string }[];
    transitions: { key: string; label: string }[];
    plans: Parameters<typeof PlanningSection>[0]['plans'];
    risks: Parameters<typeof RisksSection>[0]['risks'];
    controls: Parameters<typeof RisksSection>[0]['controls'];
    assessments: Parameters<typeof RisksSection>[0]['assessments'];
    programs: Parameters<typeof ProceduresSection>[0]['programs'];
    procedures: Procedure[];
    samples: Sample[];
    evidence: EvidenceRequest[];
    papers: Paper[];
    findings: Finding[];
    responses: Response[];
    reports: Report[];
    activity: { action: string; user: string; occurred_at: string }[];
    responsibles: { id: number; name: string; role: string }[];
    evidence_contacts: { id: number; name: string; role: string }[];
    options: Options;
    can: Can;
    tab: string;
    notice: string | null;
}) {
    const { engagement: e, can, options } = props;
    const href = useAuditorHref();
    const [tab, setTab] = useState(props.tab);
    const act = (key: string, label: string) => {
        const data: Record<string, string> = {};

        if (['suspend', 'block', 'cancel'].includes(key)) {
            const reason = window.prompt(`Motivo para «${label}»`);

            if (!reason) {
                return;
            }

            data.reason = reason;
        }

        if (key === 'close') {
            const conclusion = window.prompt('Conclusión de la auditoría');

            if (!conclusion) {
                return;
            }

            data.conclusion = conclusion;
        }

        router.post(`/work/audit/engagements/${e.id}/transition/${key}`, data, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={`${e.code} · ${e.title}`} />
            <Shell>
                <Notice message={props.notice} />
                <PageHeader
                    title={`${e.code} · ${e.title}`}
                    subtitle={`${e.company} · ${e.type_label} · Período ${e.period} · Responsable ${e.lead_auditor}${e.reviewer ? ` · Revisor ${e.reviewer}` : ''}`}
                    actions={
                        <span
                            className={toneBadge(
                                engagementTone[e.status] ?? 'slate',
                            )}
                        >
                            {e.status_label}
                        </span>
                    }
                />
                {e.status_reason && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        {e.status_reason}
                    </p>
                )}
                {props.transitions.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {props.transitions.map((t, index) => (
                            <button
                                key={t.key}
                                type="button"
                                className={
                                    index === 0 &&
                                    !['suspend', 'block', 'cancel'].includes(
                                        t.key,
                                    )
                                        ? primaryButton
                                        : secondaryButton
                                }
                                onClick={() => act(t.key, t.label)}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                )}
                <nav className="flex flex-wrap gap-1 border-b border-slate-200">
                    {tabs.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => {
                                setTab(key);
                                window.history.replaceState(
                                    null,
                                    '',
                                    href(
                                        `/work/audit/engagements/${e.id}?tab=${key}`,
                                    ),
                                );
                            }}
                            className={`rounded-t-lg px-3 py-2 text-xs font-bold ${tab === key ? 'border border-slate-200 border-b-white bg-white text-[#640000]' : 'text-[#526b92]'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>

                {tab === 'summary' && (
                    <div className="grid gap-3 xl:grid-cols-3">
                        <Card title="Avance">
                            <Progress value={e.progress} />
                            <ul className="mt-3 grid gap-1 text-xs">
                                <li>
                                    {e.progress_detail.planning ? '✓' : '○'}{' '}
                                    Plan aprobado (10%)
                                </li>
                                <li>
                                    {e.progress_detail.procedures_done}/
                                    {e.progress_detail.procedures_total}{' '}
                                    procedimientos obligatorios · peso{' '}
                                    {e.progress_detail.weight_done}/
                                    {e.progress_detail.weight_total} (70%)
                                </li>
                                <li>
                                    {e.progress_detail.review ? '✓' : '○'}{' '}
                                    Revisión de hallazgos y papeles (10%)
                                </li>
                                <li>
                                    {e.progress_detail.report ? '✓' : '○'}{' '}
                                    Informe emitido (10%)
                                </li>
                            </ul>
                        </Card>
                        <Card title="Alcance">
                            <dl className="grid gap-1 text-xs">
                                <dt className="font-bold">Incluidas</dt>
                                <dd>{labelList(e.areas, options.areas)}</dd>
                                <dt className="font-bold">Excluidas</dt>
                                <dd>
                                    {labelList(e.excluded_areas, options.areas)}
                                </dd>
                                <dt className="font-bold">Versión</dt>
                                <dd>
                                    {e.scope_frozen
                                        ? `Congelado · versión ${e.scope_version}`
                                        : 'En planificación'}
                                </dd>
                                <dt className="font-bold">Objetivo</dt>
                                <dd>{e.objective ?? '—'}</dd>
                            </dl>
                        </Card>
                        <Card title="Indicadores">
                            <dl className="grid grid-cols-2 gap-1 text-xs">
                                <dt>Riesgo</dt>
                                <dd>
                                    <span
                                        className={toneBadge(
                                            severityTone[e.risk_level] ??
                                                'slate',
                                        )}
                                    >
                                        {e.risk_label}
                                    </span>
                                </dd>
                                <dt>Hallazgos</dt>
                                <dd>{e.findings_count}</dd>
                                <dt>Evidencia pendiente</dt>
                                <dd>{e.evidence_pending}</dd>
                                <dt>Inicio real</dt>
                                <dd>{formatDate(e.actual_start_date)}</dd>
                                <dt>Fecha límite</dt>
                                <dd
                                    className={
                                        e.overdue
                                            ? 'font-bold text-red-700'
                                            : ''
                                    }
                                >
                                    {formatDate(e.deadline)}
                                </dd>
                                <dt>Cierre</dt>
                                <dd>{formatDate(e.closed_at)}</dd>
                            </dl>
                            {e.conclusion && (
                                <p className="mt-2 rounded bg-slate-50 p-2 text-xs">
                                    <b>Conclusión:</b> {e.conclusion}
                                </p>
                            )}
                        </Card>
                        <Card title="Equipo" className="xl:col-span-3">
                            <div className="flex flex-wrap gap-2">
                                {props.team.map((member) => (
                                    <span
                                        key={member.id}
                                        className={toneBadge('blue')}
                                    >
                                        {member.name} ·{' '}
                                        {{
                                            lead: 'Responsable',
                                            member: 'Auditor',
                                            reviewer: 'Revisor',
                                        }[member.role] ?? member.role}
                                    </span>
                                ))}
                            </div>
                        </Card>
                    </div>
                )}
                {tab === 'planning' && (
                    <PlanningSection
                        engagementId={e.id}
                        engagement={e}
                        plans={props.plans}
                        options={options}
                        can={can}
                    />
                )}
                {tab === 'risks' && (
                    <RisksSection
                        engagementId={e.id}
                        companyId={e.company_id}
                        risks={props.risks}
                        controls={props.controls}
                        assessments={props.assessments}
                        options={options}
                        can={can}
                    />
                )}
                {tab === 'procedures' && (
                    <ProceduresSection
                        engagementId={e.id}
                        programs={props.programs}
                        procedures={props.procedures}
                        risks={props.risks}
                        team={props.team}
                        options={options}
                        can={can}
                    />
                )}
                {tab === 'samples' && (
                    <SamplesSection samples={props.samples} />
                )}
                {tab === 'evidence' && (
                    <EvidenceSection
                        engagementId={e.id}
                        evidence={props.evidence}
                        procedures={props.procedures}
                        findings={props.findings}
                        responsibles={props.evidence_contacts}
                        can={can}
                    />
                )}
                {tab === 'papers' && (
                    <PapersSection
                        engagementId={e.id}
                        papers={props.papers}
                        procedures={props.procedures}
                        can={can}
                    />
                )}
                {tab === 'findings' && (
                    <FindingsSection
                        engagementId={e.id}
                        findings={props.findings}
                        procedures={props.procedures}
                        responsibles={props.responsibles}
                        options={options}
                        can={can}
                    />
                )}
                {tab === 'follow_up' && (
                    <FindingsSection
                        engagementId={e.id}
                        findings={props.findings}
                        procedures={props.procedures}
                        responsibles={props.responsibles}
                        options={options}
                        can={can}
                        followUp
                        responses={props.responses}
                    />
                )}
                {tab === 'report' && (
                    <ReportSection
                        reports={props.reports}
                        can={can}
                        engagementStatus={e.status}
                    />
                )}
                {tab === 'activity' && (
                    <ActivitySection activity={props.activity} />
                )}
            </Shell>
        </>
    );
}
