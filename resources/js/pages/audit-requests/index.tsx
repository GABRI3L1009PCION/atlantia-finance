import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Field,
    evidenceTone,
    findingTone,
    severityTone,
    textarea,
} from '@/components/auditor/kit';
import type { EvidenceRequest, Finding } from '@/components/auditor/kit';
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

type FindingRow = Finding & {
    awaiting_management: boolean;
    responses: {
        kind: string;
        response: string;
        action_plan: string | null;
        acceptance: string | null;
        target_date: string | null;
        at: string;
    }[];
};

function EvidenceItem({
    request,
    sources,
    focused,
}: {
    request: EvidenceRequest;
    sources: Record<string, string>;
    focused: boolean;
}) {
    const form = useForm<{
        note: string;
        file: File | null;
        source_type: string;
        source_id: string;
    }>({ note: '', file: null, source_type: '', source_id: '' });
    const open = [
        'requested',
        'more_information_required',
        'rejected',
    ].includes(request.status);

    return (
        <Card
            title={`${request.code} · ${request.title}`}
            className={focused ? 'ring-2 ring-amber-300' : ''}
            action={
                <span
                    className={toneBadge(
                        evidenceTone[request.status] ?? 'slate',
                    )}
                >
                    {request.status_label}
                </span>
            }
        >
            <p className="text-sm">{request.description}</p>
            <p className="mt-1 text-xs text-[#526b92]">
                {request.company} · auditoría {request.engagement} · vence{' '}
                <b className={request.overdue ? 'text-red-700' : ''}>
                    {formatDate(request.due_date)}
                </b>
            </p>
            {request.review_note && (
                <p className="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs">
                    <b>Observación del auditor:</b> {request.review_note}
                </p>
            )}
            {request.submission_note && (
                <p className="mt-2 text-xs">
                    <b>Tu entrega (v{request.version}):</b>{' '}
                    {request.submission_note}{' '}
                    {request.file_name && `· ${request.file_name}`}{' '}
                    {request.source_label && `· ${request.source_label}`}
                </p>
            )}
            {open && (
                <form
                    className="mt-3 grid gap-2 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            `/work/audit-requests/evidence/${request.id}`,
                            { forceFormData: true, preserveScroll: true },
                        );
                    }}
                >
                    <Field label="Nota para el auditor" wide>
                        <textarea
                            className={textarea}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Archivo (PDF, imagen, Excel, Word, XML, ZIP)">
                        <input
                            type="file"
                            className={input}
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="O documento existente">
                            <select
                                className={input}
                                value={form.data.source_type}
                                onChange={(event) =>
                                    form.setData(
                                        'source_type',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">—</option>
                                {Object.entries(sources).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Número interno">
                            <input
                                className={input}
                                value={form.data.source_id}
                                onChange={(event) =>
                                    form.setData(
                                        'source_id',
                                        event.target.value,
                                    )
                                }
                                inputMode="numeric"
                            />
                        </Field>
                    </div>
                    <div>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Enviar evidencia
                        </button>
                    </div>
                </form>
            )}
        </Card>
    );
}

function FindingItem({
    finding,
    acceptance,
    focused,
}: {
    finding: FindingRow;
    acceptance: Record<string, string>;
    focused: boolean;
}) {
    const [mode, setMode] = useState<
        'response' | 'correction' | 'management' | null
    >(null);
    const form = useForm({
        kind: 'response',
        response: '',
        action_plan: '',
        target_date: '',
    });
    const management = useForm({
        acceptance: 'accepted',
        response: '',
        action_plan: '',
        responsible_name: '',
        target_date: '',
    });
    const answerable = [
        'open',
        'response_requested',
        'remediation_in_progress',
    ].includes(finding.status);

    return (
        <Card
            title={`${finding.code} · ${finding.title}`}
            className={focused ? 'ring-2 ring-amber-300' : ''}
            action={
                <span className="flex gap-1">
                    <span
                        className={toneBadge(
                            severityTone[finding.severity] ?? 'slate',
                        )}
                    >
                        {finding.severity_label}
                    </span>
                    <span
                        className={toneBadge(
                            findingTone[finding.status] ?? 'slate',
                        )}
                    >
                        {finding.status_label}
                    </span>
                </span>
            }
        >
            <dl className="grid gap-1 text-sm md:grid-cols-2">
                <div>
                    <dt className="text-xs font-bold">Criterio</dt>
                    <dd>{finding.criterion}</dd>
                </div>
                <div>
                    <dt className="text-xs font-bold">Condición</dt>
                    <dd>{finding.condition}</dd>
                </div>
                <div>
                    <dt className="text-xs font-bold">Efecto / riesgo</dt>
                    <dd>{finding.effect_risk}</dd>
                </div>
                <div>
                    <dt className="text-xs font-bold">Recomendación</dt>
                    <dd>{finding.recommendation}</dd>
                </div>
            </dl>
            <p className="mt-1 text-xs text-[#526b92]">
                {finding.company} · fecha compromiso{' '}
                <b className={finding.overdue ? 'text-red-700' : ''}>
                    {formatDate(finding.due_date)}
                </b>
                {finding.source && ` · se verificará en ${finding.source}`}
            </p>
            {finding.verification_note && (
                <p className="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs">
                    <b>Nota del auditor:</b> {finding.verification_note}
                </p>
            )}
            <ul className="mt-2 grid gap-1 text-xs">
                {finding.responses.map((r, i) => (
                    <li key={i} className="rounded bg-slate-50 p-1.5">
                        {formatDate(r.at)} · {r.response}
                        {r.action_plan && ` · plan: ${r.action_plan}`}
                        {r.acceptance && ` · ${r.acceptance}`}
                    </li>
                ))}
            </ul>
            <div className="mt-3 flex flex-wrap gap-2">
                {answerable && (
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={() => {
                            setMode('response');
                            form.setData('kind', 'response');
                        }}
                    >
                        Responder con plan de acción
                    </button>
                )}
                {answerable && (
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={() => {
                            setMode('correction');
                            form.setData('kind', 'correction');
                        }}
                    >
                        Reportar corrección realizada
                    </button>
                )}
                {finding.awaiting_management && (
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={() => setMode('management')}
                    >
                        Responder al informe
                    </button>
                )}
            </div>
            {(mode === 'response' || mode === 'correction') && (
                <form
                    className="mt-3 grid gap-2 md:grid-cols-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            `/work/audit-requests/findings/${finding.id}/respond`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setMode(null),
                            },
                        );
                    }}
                >
                    <Field
                        label={
                            mode === 'correction'
                                ? 'Corrección realizada en el módulo de origen'
                                : 'Respuesta'
                        }
                        wide
                    >
                        <textarea
                            className={textarea}
                            value={form.data.response}
                            onChange={(event) =>
                                form.setData('response', event.target.value)
                            }
                            required
                        />
                    </Field>
                    {mode === 'response' && (
                        <>
                            <Field label="Plan de acción">
                                <textarea
                                    className={textarea}
                                    value={form.data.action_plan}
                                    onChange={(event) =>
                                        form.setData(
                                            'action_plan',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Fecha compromiso">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.target_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'target_date',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                        </>
                    )}
                    {mode === 'correction' && (
                        <p className="text-xs text-[#526b92] md:col-span-2">
                            La corrección se hace en el módulo correspondiente
                            (Compras, Bancos, Contabilidad…). El auditor la
                            verificará antes de cerrar el hallazgo.
                        </p>
                    )}
                    <div>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Enviar
                        </button>
                    </div>
                </form>
            )}
            {mode === 'management' && (
                <form
                    className="mt-3 grid gap-2 md:grid-cols-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        management.post(
                            `/work/audit-requests/findings/${finding.id}/management`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setMode(null),
                            },
                        );
                    }}
                >
                    <Field label="Posición">
                        <select
                            className={input}
                            value={management.data.acceptance}
                            onChange={(event) =>
                                management.setData(
                                    'acceptance',
                                    event.target.value,
                                )
                            }
                        >
                            {Object.entries(acceptance).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Responsable de la acción">
                        <input
                            className={input}
                            value={management.data.responsible_name}
                            onChange={(event) =>
                                management.setData(
                                    'responsible_name',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Fecha compromiso">
                        <input
                            type="date"
                            className={input}
                            value={management.data.target_date}
                            onChange={(event) =>
                                management.setData(
                                    'target_date',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Respuesta">
                        <textarea
                            className={textarea}
                            value={management.data.response}
                            onChange={(event) =>
                                management.setData(
                                    'response',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </Field>
                    <Field label="Plan de acción">
                        <textarea
                            className={textarea}
                            value={management.data.action_plan}
                            onChange={(event) =>
                                management.setData(
                                    'action_plan',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <div className="flex items-end">
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={management.processing}
                        >
                            Registrar respuesta
                        </button>
                    </div>
                </form>
            )}
        </Card>
    );
}

export default function AuditRequests({
    evidence,
    findings,
    sources,
    acceptance,
    focus,
    notice,
}: {
    evidence: EvidenceRequest[];
    findings: FindingRow[];
    sources: Record<string, string>;
    acceptance: Record<string, string>;
    areas: Record<string, string>;
    focus: { evidence: number | null; finding: number | null };
    notice: string | null;
}) {
    return (
        <>
            <Head title="Solicitudes de auditoría" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Solicitudes de auditoría"
                    subtitle="Evidencia que te solicitaron y hallazgos a tu cargo; el auditor documenta, tú respondes y corriges en el módulo de origen"
                />
                <h2 className="text-lg font-black text-[#061633]">
                    Evidencia solicitada ({evidence.length})
                </h2>
                {evidence.length === 0 && (
                    <p className="text-sm text-slate-500">
                        No tienes solicitudes de evidencia.
                    </p>
                )}
                {evidence.map((request) => (
                    <EvidenceItem
                        key={request.id}
                        request={request}
                        sources={sources}
                        focused={focus.evidence === request.id}
                    />
                ))}
                <h2 className="text-lg font-black text-[#061633]">
                    Hallazgos a tu cargo ({findings.length})
                </h2>
                {findings.length === 0 && (
                    <p className="text-sm text-slate-500">
                        No tienes hallazgos asignados.
                    </p>
                )}
                {findings.map((finding) => (
                    <FindingItem
                        key={finding.id}
                        finding={finding}
                        acceptance={acceptance}
                        focused={focus.finding === finding.id}
                    />
                ))}
            </Shell>
        </>
    );
}
