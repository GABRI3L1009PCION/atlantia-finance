import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ActivitySection,
    EvidenceSection,
} from '@/components/auditor/engagement-sections';
import {
    ActionButton,
    Field,
    findingTone,
    severityTone,
    textarea,
    useAuditorHref,
} from '@/components/auditor/kit';
import type {
    AuditorContext,
    EvidenceRequest,
    Finding,
    Response,
} from '@/components/auditor/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, toneBadge } from '@/components/supervisor/kit';

const kindLabel: Record<string, string> = {
    response: 'Respuesta',
    correction: 'Corrección reportada',
    management: 'Respuesta de la administración',
    verification: 'Verificación del auditor',
};

export default function AuditorFinding({
    finding: f,
    responses,
    evidence,
    responsibles,
    levels,
    areas,
    activity,
    can,
    notice,
}: {
    context: AuditorContext;
    finding: Finding & { source_problem: string | null };
    responses: Response[];
    evidence: EvidenceRequest[];
    responsibles: { id: number; name: string }[];
    levels: Record<string, string>;
    areas: Record<string, string>;
    activity: { action: string; user: string; occurred_at: string }[];
    can: Record<string, boolean>;
    notice: string | null;
}) {
    const href = useAuditorHref();
    const draft = f.status === 'draft';
    const form = useForm({
        title: f.title,
        area: f.area,
        severity: f.severity,
        criterion: f.criterion ?? '',
        condition: f.condition ?? '',
        cause: f.cause ?? '',
        effect_risk: f.effect_risk ?? '',
        recommendation: f.recommendation ?? '',
        responsible_user_id: f.responsible_id ? String(f.responsible_id) : '',
        due_date: f.due_date ?? '',
    });
    const [note, setNote] = useState('');
    const editable =
        can.update &&
        [
            'draft',
            'open',
            'response_requested',
            'remediation_in_progress',
            'ready_for_verification',
        ].includes(f.status);

    return (
        <>
            <Head title={`${f.code} · ${f.title}`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={`${f.code} · ${f.title}`}
                    subtitle={`${f.engagement} · ${f.company} · ${f.area_label}${f.source ? ` · ${f.source}` : ''}`}
                    actions={
                        <>
                            <span
                                className={toneBadge(
                                    severityTone[f.severity] ?? 'slate',
                                )}
                            >
                                {f.severity_label}
                            </span>
                            <span
                                className={toneBadge(
                                    findingTone[f.status] ?? 'slate',
                                )}
                            >
                                {f.status_label}
                            </span>
                            <Link
                                href={href(
                                    `/work/audit/engagements/${f.engagement_id}?tab=findings`,
                                )}
                                className={secondaryButton}
                            >
                                Ver auditoría
                            </Link>
                        </>
                    }
                />
                <Card title="Documentación del hallazgo">
                    <form
                        className="grid gap-2 md:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) =>
                                Object.fromEntries(
                                    Object.entries(data).filter(
                                        ([, value]) => value !== '',
                                    ),
                                ),
                            );
                            form.put(`/work/audit/findings/${f.id}`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Field label="Título">
                            <input
                                className={input}
                                value={form.data.title}
                                disabled={!editable || !draft}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Área">
                            <select
                                className={input}
                                value={form.data.area}
                                disabled={!editable || !draft}
                                onChange={(event) =>
                                    form.setData('area', event.target.value)
                                }
                            >
                                {Object.entries(areas).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Severidad">
                            <select
                                className={input}
                                value={form.data.severity}
                                disabled={!editable}
                                onChange={(event) =>
                                    form.setData('severity', event.target.value)
                                }
                            >
                                {Object.entries(levels).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        {(
                            [
                                'criterion',
                                'condition',
                                'cause',
                                'effect_risk',
                                'recommendation',
                            ] as const
                        ).map((key) => (
                            <Field
                                key={key}
                                label={
                                    {
                                        criterion: 'Criterio',
                                        condition: 'Condición',
                                        cause: 'Causa',
                                        effect_risk: 'Efecto / riesgo',
                                        recommendation: 'Recomendación',
                                    }[key]
                                }
                            >
                                <textarea
                                    className={textarea}
                                    value={form.data[key]}
                                    disabled={
                                        !editable ||
                                        (!draft && key !== 'recommendation')
                                    }
                                    onChange={(event) =>
                                        form.setData(key, event.target.value)
                                    }
                                />
                            </Field>
                        ))}
                        <div className="grid gap-2">
                            <Field label="Responsable">
                                <select
                                    className={input}
                                    value={form.data.responsible_user_id}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        form.setData(
                                            'responsible_user_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Selecciona…</option>
                                    {responsibles.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Fecha compromiso">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.due_date}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        form.setData(
                                            'due_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        {editable && (
                            <div className="flex flex-wrap items-end gap-2 md:col-span-3">
                                <button
                                    type="submit"
                                    className={secondaryButton}
                                    disabled={form.processing}
                                >
                                    Guardar
                                </button>
                                {draft && (
                                    <ActionButton
                                        url={`/work/audit/findings/${f.id}/issue`}
                                        label="Emitir y solicitar respuesta"
                                        tone="primary"
                                    />
                                )}
                                {[
                                    'draft',
                                    'open',
                                    'response_requested',
                                ].includes(f.status) && (
                                    <ActionButton
                                        url={`/work/audit/findings/${f.id}/cancel`}
                                        label="Cancelar hallazgo"
                                        ask="Justificación"
                                    />
                                )}
                            </div>
                        )}
                    </form>
                </Card>

                {f.status === 'ready_for_verification' && can.verify && (
                    <Card title="Verificar corrección">
                        {f.source_problem && (
                            <p className="mb-2 rounded border border-red-200 bg-red-50 p-2 text-sm text-red-800">
                                {f.source_problem}
                            </p>
                        )}
                        <Field label="¿Qué revisaste? (origen, conciliación, asientos, documentos)">
                            <textarea
                                className={textarea}
                                value={note}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                            />
                        </Field>
                        <div className="mt-2 flex gap-2">
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() =>
                                    router.post(
                                        `/work/audit/findings/${f.id}/verify`,
                                        { valid: true, note },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Corrección válida
                            </button>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    router.post(
                                        `/work/audit/findings/${f.id}/verify`,
                                        { valid: false, note },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Corrección insuficiente
                            </button>
                        </div>
                    </Card>
                )}
                {f.status === 'verified' && can.close && (
                    <div>
                        <ActionButton
                            url={`/work/audit/findings/${f.id}/close`}
                            label="Cerrar hallazgo verificado"
                            tone="primary"
                        />
                    </div>
                )}
                {can.accept_risk &&
                    [
                        'open',
                        'response_requested',
                        'remediation_in_progress',
                    ].includes(f.status) && (
                        <div>
                            <ActionButton
                                url={`/work/audit/findings/${f.id}/accept_risk`}
                                label="Aceptar el riesgo (administración)"
                                ask="Justificación detallada de la aceptación del riesgo"
                            />
                        </div>
                    )}
                {f.accepted_risk_reason && (
                    <p className="rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm">
                        <b>Riesgo aceptado:</b> {f.accepted_risk_reason}
                    </p>
                )}

                <Card title="Respuestas y verificaciones">
                    <ol className="grid gap-2">
                        {responses.map((r) => (
                            <li
                                key={r.id}
                                className="rounded-lg border border-slate-200 p-2 text-sm"
                            >
                                <p className="text-[11px] text-[#526b92]">
                                    {kindLabel[r.kind] ?? r.kind} · {r.by} ·{' '}
                                    {formatDate(r.at, true)}
                                    {r.acceptance && ` · ${r.acceptance}`}
                                </p>
                                <p>{r.response}</p>
                                {r.action_plan && (
                                    <p className="text-xs">
                                        <b>Plan de acción:</b> {r.action_plan}
                                        {r.target_date &&
                                            ` · fecha compromiso ${formatDate(r.target_date)}`}
                                    </p>
                                )}
                            </li>
                        ))}
                        {responses.length === 0 && (
                            <li className="text-sm text-slate-500">
                                Sin respuestas todavía.
                            </li>
                        )}
                    </ol>
                </Card>
                <EvidenceSection
                    engagementId={f.engagement_id}
                    evidence={evidence}
                    procedures={[]}
                    findings={[]}
                    responsibles={responsibles}
                    can={{
                        ...can,
                        review_evidence: can.verify,
                        locked: !can.request_evidence,
                    }}
                    findingId={f.id}
                />
                <ActivitySection activity={activity} />
            </Shell>
        </>
    );
}
