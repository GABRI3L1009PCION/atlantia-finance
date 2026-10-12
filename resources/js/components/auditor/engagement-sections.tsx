import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ActionButton,
    Field,
    evidenceTone,
    findingTone,
    procedureTone,
    reportTone,
    severityTone,
    textarea,
    useAuditorHref,
    amount,
} from '@/components/auditor/kit';
import type {
    EvidenceRequest,
    Finding,
    Paper,
    Procedure,
    Report,
    Response,
    Sample,
} from '@/components/auditor/kit';
import {
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export type Options = {
    areas: Record<string, string>;
    levels: Record<string, string>;
    procedure_types: Record<string, string>;
    control_results: Record<string, string>;
    universes: Record<string, string>;
    methods: Record<string, string>;
    sources: Record<string, string>;
    reconciliations: Record<string, string>;
};

export type Can = Record<string, boolean | number>;

type Person = { id: number; name: string; role?: string };

const labelList = (
    keys: string[] | null | undefined,
    labels: Record<string, string>,
) => (keys ?? []).map((key) => labels[key] ?? key).join(', ') || '—';

export function PlanningSection({
    engagementId,
    engagement,
    plans,
    options,
    can,
}: {
    engagementId: number;
    engagement: {
        objective: string | null;
        criteria: string | null;
        limitations: string | null;
        areas: string[];
        excluded_areas: string[];
        scope_frozen: boolean;
        scope_version: number;
        scope_snapshot: Record<string, unknown> | null;
        risk_level: string;
        planned_start_date: string | null;
        planned_end_date: string | null;
    };
    plans: {
        id: number;
        version: number;
        status: string;
        objective: string;
        methodology: string | null;
        materiality_cents: number | null;
        materiality_basis: string | null;
        areas: string[];
        planned_procedures: string | null;
        created_by: number;
        approved_by: string | null;
        approved_at: string | null;
    }[];
    options: Options;
    can: Can;
}) {
    const scope = useForm({
        objective: engagement.objective ?? '',
        criteria: engagement.criteria ?? '',
        limitations: engagement.limitations ?? '',
        areas: engagement.areas,
        excluded_areas: engagement.excluded_areas,
        planned_start_date: engagement.planned_start_date ?? '',
        planned_end_date: engagement.planned_end_date ?? '',
        reason: '',
    });
    const draft = plans.find(
        (plan) => plan.status === 'draft' || plan.status === 'in_review',
    );
    const plan = useForm({
        objective:
            draft?.objective ??
            plans[0]?.objective ??
            engagement.objective ??
            '',
        methodology: draft?.methodology ?? plans[0]?.methodology ?? '',
        materiality: draft?.materiality_cents
            ? String(draft.materiality_cents / 100)
            : '',
        materiality_basis: draft?.materiality_basis ?? '',
        areas: draft?.areas ?? engagement.areas,
        planned_procedures: draft?.planned_procedures ?? '',
    });
    const editable = Boolean(can.plan) && !engagement.scope_frozen;
    const toggle = (list: string[], key: string, on: boolean) =>
        on ? [...list, key] : list.filter((item) => item !== key);

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            <Card
                title={`Alcance${engagement.scope_frozen ? ` (congelado · versión ${engagement.scope_version})` : ''}`}
            >
                <form
                    className="grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (engagement.scope_frozen) {
                            scope.post(
                                `/work/audit/engagements/${engagementId}/scope`,
                                { preserveScroll: true },
                            );
                        } else {
                            scope.put(
                                `/work/audit/engagements/${engagementId}/planning`,
                                { preserveScroll: true },
                            );
                        }
                    }}
                >
                    {!engagement.scope_frozen && (
                        <>
                            <Field label="Objetivo">
                                <textarea
                                    className={textarea}
                                    value={scope.data.objective}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        scope.setData(
                                            'objective',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Criterios">
                                <textarea
                                    className={textarea}
                                    value={scope.data.criteria}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        scope.setData(
                                            'criteria',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Limitaciones conocidas">
                                <textarea
                                    className={textarea}
                                    value={scope.data.limitations}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        scope.setData(
                                            'limitations',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Inicio planificado">
                                    <input
                                        type="date"
                                        className={input}
                                        value={scope.data.planned_start_date}
                                        disabled={!editable}
                                        onChange={(event) =>
                                            scope.setData(
                                                'planned_start_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Fecha límite">
                                    <input
                                        type="date"
                                        className={input}
                                        value={scope.data.planned_end_date}
                                        disabled={!editable}
                                        onChange={(event) =>
                                            scope.setData(
                                                'planned_end_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                        </>
                    )}
                    {(['areas', 'excluded_areas'] as const).map((key) => (
                        <Field
                            key={key}
                            label={
                                key === 'areas'
                                    ? 'Áreas incluidas'
                                    : 'Áreas excluidas'
                            }
                        >
                            <div className="flex flex-wrap gap-1.5">
                                {Object.entries(options.areas).map(
                                    ([area, label]) => (
                                        <label
                                            key={area}
                                            className="flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-normal"
                                        >
                                            <input
                                                type="checkbox"
                                                disabled={!can.plan}
                                                checked={scope.data[
                                                    key
                                                ].includes(area)}
                                                onChange={(event) =>
                                                    scope.setData(
                                                        key,
                                                        toggle(
                                                            scope.data[key],
                                                            area,
                                                            event.target
                                                                .checked,
                                                        ),
                                                    )
                                                }
                                            />
                                            {label}
                                        </label>
                                    ),
                                )}
                            </div>
                        </Field>
                    ))}
                    {engagement.scope_frozen && (
                        <Field label="Justificación del cambio de alcance (queda auditada)">
                            <textarea
                                className={textarea}
                                value={scope.data.reason}
                                onChange={(event) =>
                                    scope.setData('reason', event.target.value)
                                }
                            />
                        </Field>
                    )}
                    {Boolean(can.plan) && (
                        <div>
                            <button
                                type="submit"
                                className={secondaryButton}
                                disabled={scope.processing}
                            >
                                {engagement.scope_frozen
                                    ? 'Modificar alcance (nueva versión)'
                                    : 'Guardar planificación'}
                            </button>
                        </div>
                    )}
                </form>
            </Card>
            <Card title="Plan de auditoría">
                {editable && (
                    <form
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            plan.post(
                                `/work/audit/engagements/${engagementId}/plan`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Field label="Objetivo del plan">
                            <textarea
                                className={textarea}
                                value={plan.data.objective}
                                onChange={(event) =>
                                    plan.setData(
                                        'objective',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Metodología">
                            <textarea
                                className={textarea}
                                value={plan.data.methodology}
                                onChange={(event) =>
                                    plan.setData(
                                        'methodology',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Materialidad (Q, opcional)">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    className={input}
                                    value={plan.data.materiality}
                                    onChange={(event) =>
                                        plan.setData(
                                            'materiality',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Base de materialidad">
                                <input
                                    className={input}
                                    value={plan.data.materiality_basis}
                                    onChange={(event) =>
                                        plan.setData(
                                            'materiality_basis',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <Field label="Procedimientos planificados">
                            <textarea
                                className={textarea}
                                value={plan.data.planned_procedures}
                                onChange={(event) =>
                                    plan.setData(
                                        'planned_procedures',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={
                                    plan.processing ||
                                    draft?.status === 'in_review'
                                }
                            >
                                Guardar plan
                            </button>
                        </div>
                    </form>
                )}
                <Table
                    headers={[
                        'Versión',
                        'Estado',
                        'Materialidad',
                        'Aprobó',
                        'Acciones',
                    ]}
                    empty={
                        plans.length === 0 && 'Aún no hay plan de auditoría.'
                    }
                >
                    {plans.map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2 font-bold">
                                v{row.version}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        row.status === 'approved'
                                            ? 'green'
                                            : row.status === 'in_review'
                                              ? 'amber'
                                              : 'slate',
                                    )}
                                >
                                    {{
                                        draft: 'Borrador',
                                        in_review: 'En revisión',
                                        approved: 'Aprobado',
                                        superseded: 'Reemplazado',
                                    }[row.status] ?? row.status}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {row.materiality_cents === null
                                    ? '—'
                                    : amount(row.materiality_cents)}
                            </td>
                            <td className="px-2 py-2">
                                {row.approved_by
                                    ? `${row.approved_by} · ${formatDate(row.approved_at)}`
                                    : '—'}
                            </td>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {Boolean(can.plan) &&
                                    row.status === 'draft' &&
                                    !engagement.scope_frozen && (
                                        <ActionButton
                                            url={`/work/audit/plans/${row.id}/submit`}
                                            label="Enviar a revisión"
                                        />
                                    )}
                                {Boolean(can.plan) &&
                                    row.status === 'in_review' &&
                                    row.created_by !== can.user_id && (
                                        <span className="flex gap-1">
                                            <ActionButton
                                                url={`/work/audit/plans/${row.id}/approve`}
                                                label="Aprobar"
                                                tone="primary"
                                            />
                                            <ActionButton
                                                url={`/work/audit/plans/${row.id}/return`}
                                                label="Devolver"
                                                ask="Observación para el autor"
                                            />
                                        </span>
                                    )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </div>
    );
}

export function RisksSection({
    engagementId,
    companyId,
    risks,
    controls,
    assessments,
    options,
    can,
}: {
    engagementId: number;
    companyId: number;
    risks: {
        id: number;
        code: string;
        area: string;
        area_label: string;
        title: string;
        description: string | null;
        risk_level: string;
        risk_label: string;
        control_reference: string | null;
        status: string;
    }[];
    controls: {
        id: number;
        code: string;
        name: string;
        area: string;
        frequency: string | null;
    }[];
    assessments: {
        control_id: number;
        code: string;
        name: string;
        risk_id: number | null;
        design: string;
        existence: string;
        execution: string;
        effectiveness: string;
        conclusion: string | null;
    }[];
    options: Options;
    can: Can;
}) {
    const risk = useForm({
        area: 'accounting',
        title: '',
        description: '',
        likelihood: '',
        impact: '',
        risk_level: 'medium',
        control_reference: '',
    });
    const control = useForm({
        company_id: String(companyId),
        code: '',
        name: '',
        area: 'accounting',
        description: '',
        frequency: '',
    });
    const assessment = useForm({
        audit_control_id: '',
        audit_risk_id: '',
        design: 'not_tested',
        existence: 'not_tested',
        execution: 'not_tested',
        effectiveness: 'not_tested',
        conclusion: '',
    });
    const open = Boolean(can.risks) && !can.locked;

    return (
        <div className="grid gap-3">
            <Card title={`Riesgos (${risks.length})`}>
                <Table
                    headers={[
                        'Código',
                        'Área',
                        'Riesgo',
                        'Nivel',
                        'Control',
                        'Estado',
                    ]}
                    empty={
                        risks.length === 0 && 'Aún no hay riesgos registrados.'
                    }
                >
                    {risks.map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2 font-bold">{row.code}</td>
                            <td className="px-2 py-2">{row.area_label}</td>
                            <td className="px-2 py-2">
                                {row.title}
                                {row.description && (
                                    <p className="text-[11px] text-[#526b92]">
                                        {row.description}
                                    </p>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        severityTone[row.risk_level] ?? 'slate',
                                    )}
                                >
                                    {row.risk_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {row.control_reference ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                {{
                                    identified: 'Identificado',
                                    assessed: 'Evaluado',
                                    mitigated: 'Mitigado',
                                    closed: 'Cerrado',
                                }[row.status] ?? row.status}
                            </td>
                        </tr>
                    ))}
                </Table>
                {open && (
                    <form
                        className="mt-3 grid gap-2 md:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            risk.post(
                                `/work/audit/engagements/${engagementId}/risks`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => risk.reset(),
                                },
                            );
                        }}
                    >
                        <Field label="Área">
                            <select
                                className={input}
                                value={risk.data.area}
                                onChange={(event) =>
                                    risk.setData('area', event.target.value)
                                }
                            >
                                {Object.entries(options.areas).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Riesgo">
                            <input
                                className={input}
                                value={risk.data.title}
                                onChange={(event) =>
                                    risk.setData('title', event.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field label="Nivel (clasificación de auditoría)">
                            <select
                                className={input}
                                value={risk.data.risk_level}
                                onChange={(event) =>
                                    risk.setData(
                                        'risk_level',
                                        event.target.value,
                                    )
                                }
                            >
                                {Object.entries(options.levels).map(
                                    ([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <Field label="Control relacionado">
                            <input
                                className={input}
                                value={risk.data.control_reference}
                                onChange={(event) =>
                                    risk.setData(
                                        'control_reference',
                                        event.target.value,
                                    )
                                }
                                placeholder="CTRL-021"
                            />
                        </Field>
                        <Field label="Descripción" wide>
                            <textarea
                                className={textarea}
                                value={risk.data.description}
                                onChange={(event) =>
                                    risk.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="flex items-end">
                            <button
                                type="submit"
                                className={secondaryButton}
                                disabled={risk.processing}
                            >
                                Registrar riesgo
                            </button>
                        </div>
                    </form>
                )}
            </Card>
            <Card title="Controles y evaluación">
                <Table
                    headers={[
                        'Control',
                        'Diseño',
                        'Existencia',
                        'Ejecución',
                        'Efectividad',
                        'Conclusión',
                    ]}
                    empty={
                        assessments.length === 0 &&
                        'Aún no se evalúan controles en esta auditoría.'
                    }
                >
                    {assessments.map((row) => (
                        <tr key={row.control_id}>
                            <td className="px-2 py-2 font-bold">
                                {row.code} · {row.name}
                            </td>
                            {(
                                [
                                    'design',
                                    'existence',
                                    'execution',
                                    'effectiveness',
                                ] as const
                            ).map((key) => (
                                <td key={key} className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            row[key] === 'effective'
                                                ? 'green'
                                                : row[key] === 'ineffective'
                                                  ? 'red'
                                                  : row[key] ===
                                                      'partially_effective'
                                                    ? 'amber'
                                                    : 'slate',
                                        )}
                                    >
                                        {options.control_results[row[key]]}
                                    </span>
                                </td>
                            ))}
                            <td className="px-2 py-2">
                                {row.conclusion ?? '—'}
                            </td>
                        </tr>
                    ))}
                </Table>
                {Boolean(can.controls) && !can.locked && (
                    <div className="mt-3 grid gap-3 xl:grid-cols-2">
                        <form
                            className="grid gap-2 md:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                assessment.post(
                                    `/work/audit/engagements/${engagementId}/control-assessments`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <Field label="Control">
                                <select
                                    className={input}
                                    value={assessment.data.audit_control_id}
                                    onChange={(event) =>
                                        assessment.setData(
                                            'audit_control_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {controls.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code} · {c.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Riesgo">
                                <select
                                    className={input}
                                    value={assessment.data.audit_risk_id}
                                    onChange={(event) =>
                                        assessment.setData(
                                            'audit_risk_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">—</option>
                                    {risks.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.code} · {r.title}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {(
                                [
                                    'design',
                                    'existence',
                                    'execution',
                                    'effectiveness',
                                ] as const
                            ).map((key) => (
                                <Field
                                    key={key}
                                    label={
                                        {
                                            design: 'Diseño',
                                            existence: 'Existencia',
                                            execution: 'Ejecución',
                                            effectiveness: 'Efectividad',
                                        }[key]
                                    }
                                >
                                    <select
                                        className={input}
                                        value={assessment.data[key]}
                                        onChange={(event) =>
                                            assessment.setData(
                                                key,
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {Object.entries(
                                            options.control_results,
                                        ).map(([k, l]) => (
                                            <option key={k} value={k}>
                                                {l}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            ))}
                            <Field label="Conclusión" wide>
                                <textarea
                                    className={textarea}
                                    value={assessment.data.conclusion}
                                    onChange={(event) =>
                                        assessment.setData(
                                            'conclusion',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div>
                                <button
                                    type="submit"
                                    className={secondaryButton}
                                    disabled={assessment.processing}
                                >
                                    Registrar evaluación
                                </button>
                            </div>
                        </form>
                        <form
                            className="grid gap-2 md:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                control.post('/work/audit/controls', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        control.reset(
                                            'code',
                                            'name',
                                            'description',
                                        ),
                                });
                            }}
                        >
                            <p className="text-xs font-bold md:col-span-2">
                                Registrar control de la empresa
                            </p>
                            <Field label="Código">
                                <input
                                    className={input}
                                    value={control.data.code}
                                    onChange={(event) =>
                                        control.setData(
                                            'code',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Área">
                                <select
                                    className={input}
                                    value={control.data.area}
                                    onChange={(event) =>
                                        control.setData(
                                            'area',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(options.areas).map(
                                        ([k, l]) => (
                                            <option key={k} value={k}>
                                                {l}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                            <Field label="Nombre">
                                <input
                                    className={input}
                                    value={control.data.name}
                                    onChange={(event) =>
                                        control.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Frecuencia">
                                <input
                                    className={input}
                                    value={control.data.frequency}
                                    onChange={(event) =>
                                        control.setData(
                                            'frequency',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Mensual"
                                />
                            </Field>
                            <div>
                                <button
                                    type="submit"
                                    className={secondaryButton}
                                    disabled={control.processing}
                                >
                                    Registrar control
                                </button>
                            </div>
                        </form>
                    </div>
                )}
            </Card>
        </div>
    );
}

export function ProceduresSection({
    engagementId,
    programs,
    procedures,
    risks,
    team,
    options,
    can,
}: {
    engagementId: number;
    programs: {
        id: number;
        area: string;
        area_label: string;
        name: string;
        description: string | null;
    }[];
    procedures: Procedure[];
    risks: { id: number; code: string; title: string }[];
    team: Person[];
    options: Options;
    can: Can;
}) {
    const href = useAuditorHref();
    const program = useForm({ area: 'accounting', name: '', description: '' });
    const procedure = useForm({
        title: '',
        area: 'accounting',
        procedure_type: 'substantive_test',
        objective: '',
        audit_program_id: '',
        audit_risk_id: '',
        assigned_to: '',
        due_date: '',
        weight: '1',
        required: true,
    });
    const open = Boolean(can.execute) && !can.locked;

    return (
        <div className="grid gap-3">
            <Card title={`Programa de auditoría (${programs.length} área(s))`}>
                <div className="flex flex-wrap gap-2">
                    {programs.map((row) => (
                        <span key={row.id} className={toneBadge('blue')}>
                            {row.area_label} · {row.name}
                        </span>
                    ))}
                    {programs.length === 0 && (
                        <p className="text-sm text-slate-500">
                            Agrupa los procedimientos por área o riesgo.
                        </p>
                    )}
                </div>
                {open && (
                    <form
                        className="mt-3 flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            program.post(
                                `/work/audit/engagements/${engagementId}/programs`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => program.reset(),
                                },
                            );
                        }}
                    >
                        <Field label="Área">
                            <select
                                className={input}
                                value={program.data.area}
                                onChange={(event) =>
                                    program.setData('area', event.target.value)
                                }
                            >
                                {Object.entries(options.areas).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Nombre del programa">
                            <input
                                className={input}
                                value={program.data.name}
                                onChange={(event) =>
                                    program.setData('name', event.target.value)
                                }
                                required
                            />
                        </Field>
                        <button type="submit" className={secondaryButton}>
                            Agregar programa
                        </button>
                    </form>
                )}
            </Card>
            <Card title={`Procedimientos (${procedures.length})`}>
                <Table
                    headers={[
                        'Código',
                        'Procedimiento',
                        'Área',
                        'Tipo',
                        'Responsable',
                        'Fecha límite',
                        'Peso',
                        'Muestra',
                        'Evidencia',
                        'Resultado',
                        'Estado',
                    ]}
                    empty={
                        procedures.length === 0 && 'No existen procedimientos.'
                    }
                >
                    {procedures.map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2 font-bold">
                                <Link
                                    href={href(row.destination)}
                                    className="text-blue-700 hover:underline"
                                >
                                    {row.code}
                                </Link>
                            </td>
                            <td className="px-2 py-2">
                                {row.title}
                                {!row.required && (
                                    <p className="text-[11px] text-[#526b92]">
                                        Opcional
                                    </p>
                                )}
                            </td>
                            <td className="px-2 py-2">{row.area_label}</td>
                            <td className="px-2 py-2">{row.type_label}</td>
                            <td className="px-2 py-2">
                                {row.responsible ?? '—'}
                            </td>
                            <td
                                className={`px-2 py-2 ${row.overdue ? 'font-bold text-red-700' : ''}`}
                            >
                                {formatDate(row.due_date)}
                            </td>
                            <td className="px-2 py-2">{row.weight}</td>
                            <td className="px-2 py-2">
                                {row.sample
                                    ? `${row.sample.count}${row.sample.confirmed ? '' : ' (borrador)'}`
                                    : '—'}
                            </td>
                            <td className="px-2 py-2">
                                {row.evidence.pending > 0
                                    ? `${row.evidence.pending} pendiente(s)`
                                    : row.evidence.accepted > 0
                                      ? `${row.evidence.accepted} aceptada(s)`
                                      : '—'}
                            </td>
                            <td className="px-2 py-2">
                                {row.result_label ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        procedureTone[row.status] ?? 'slate',
                                    )}
                                >
                                    {row.status_label}
                                </span>
                            </td>
                        </tr>
                    ))}
                </Table>
                {open && (
                    <form
                        className="mt-3 grid gap-2 md:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            procedure.transform((data) => ({
                                ...data,
                                audit_program_id: data.audit_program_id || null,
                                audit_risk_id: data.audit_risk_id || null,
                                assigned_to: data.assigned_to || null,
                                due_date: data.due_date || null,
                            }));
                            procedure.post(
                                `/work/audit/engagements/${engagementId}/procedures`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        procedure.reset('title', 'objective'),
                                },
                            );
                        }}
                    >
                        <Field label="Procedimiento">
                            <input
                                className={input}
                                value={procedure.data.title}
                                onChange={(event) =>
                                    procedure.setData(
                                        'title',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </Field>
                        <Field label="Área">
                            <select
                                className={input}
                                value={procedure.data.area}
                                onChange={(event) =>
                                    procedure.setData(
                                        'area',
                                        event.target.value,
                                    )
                                }
                            >
                                {Object.entries(options.areas).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Tipo">
                            <select
                                className={input}
                                value={procedure.data.procedure_type}
                                onChange={(event) =>
                                    procedure.setData(
                                        'procedure_type',
                                        event.target.value,
                                    )
                                }
                            >
                                {Object.entries(options.procedure_types).map(
                                    ([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <Field label="Responsable">
                            <select
                                className={input}
                                value={procedure.data.assigned_to}
                                onChange={(event) =>
                                    procedure.setData(
                                        'assigned_to',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Yo</option>
                                {team.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Programa">
                            <select
                                className={input}
                                value={procedure.data.audit_program_id}
                                onChange={(event) =>
                                    procedure.setData(
                                        'audit_program_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">—</option>
                                {programs.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Riesgo">
                            <select
                                className={input}
                                value={procedure.data.audit_risk_id}
                                onChange={(event) =>
                                    procedure.setData(
                                        'audit_risk_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">—</option>
                                {risks.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.code} · {r.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Fecha límite">
                            <input
                                type="date"
                                className={input}
                                value={procedure.data.due_date}
                                onChange={(event) =>
                                    procedure.setData(
                                        'due_date',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Peso en el avance">
                            <input
                                type="number"
                                min="1"
                                max="100"
                                className={input}
                                value={procedure.data.weight}
                                onChange={(event) =>
                                    procedure.setData(
                                        'weight',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Objetivo" wide>
                            <textarea
                                className={textarea}
                                value={procedure.data.objective}
                                onChange={(event) =>
                                    procedure.setData(
                                        'objective',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <label className="flex items-center gap-2 text-xs font-semibold">
                            <input
                                type="checkbox"
                                checked={procedure.data.required}
                                onChange={(event) =>
                                    procedure.setData(
                                        'required',
                                        event.target.checked,
                                    )
                                }
                            />
                            Obligatorio
                        </label>
                        <div>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={procedure.processing}
                            >
                                Crear procedimiento
                            </button>
                        </div>
                    </form>
                )}
            </Card>
        </div>
    );
}

export function SamplesSection({ samples }: { samples: Sample[] }) {
    const href = useAuditorHref();

    return (
        <Card title={`Muestras (${samples.length})`}>
            <Table
                headers={[
                    'Código',
                    'Universo',
                    'Rango',
                    'Método',
                    'Población',
                    'Muestra',
                    'Excepciones',
                    'Estado',
                    '',
                ]}
                empty={
                    samples.length === 0 &&
                    'Las muestras se generan dentro de cada procedimiento.'
                }
            >
                {samples.map((sample) => (
                    <tr key={sample.id}>
                        <td className="px-2 py-2 font-bold">{sample.code}</td>
                        <td className="px-2 py-2">{sample.universe}</td>
                        <td className="px-2 py-2 whitespace-nowrap">
                            {formatDate(sample.universe_from)} –{' '}
                            {formatDate(sample.universe_to)}
                        </td>
                        <td className="px-2 py-2">
                            {sample.method}
                            {sample.note && (
                                <p className="text-[11px] text-[#526b92]">
                                    {sample.note}
                                </p>
                            )}
                        </td>
                        <td className="px-2 py-2">
                            {sample.population_count}
                            {sample.population_cents !== null && (
                                <p className="text-[11px] text-[#526b92]">
                                    {amount(sample.population_cents)}
                                </p>
                            )}
                        </td>
                        <td className="px-2 py-2">{sample.sample_count}</td>
                        <td className="px-2 py-2">
                            {
                                sample.items.filter(
                                    (item) => item.result === 'exception',
                                ).length
                            }
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    sample.status === 'confirmed'
                                        ? 'green'
                                        : 'amber',
                                )}
                            >
                                {sample.status === 'confirmed'
                                    ? `Congelada ${formatDate(sample.confirmed_at)}`
                                    : 'Borrador'}
                            </span>
                        </td>
                        <td className="px-2 py-2">
                            <Link
                                href={href(
                                    `/work/audit/procedures/${sample.procedure_id}`,
                                )}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Abrir
                            </Link>
                        </td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

export function EvidenceSection({
    engagementId,
    evidence,
    procedures,
    findings,
    responsibles,
    can,
    findingId,
}: {
    engagementId: number;
    evidence: EvidenceRequest[];
    procedures: Procedure[];
    findings: Finding[];
    responsibles: Person[];
    can: Can;
    findingId?: number;
}) {
    const form = useForm({
        title: '',
        description: '',
        requested_from_user_id: '',
        due_date: '',
        audit_procedure_id: '',
        audit_finding_id: findingId ? String(findingId) : '',
    });

    return (
        <Card title={`Solicitudes de evidencia (${evidence.length})`}>
            <EvidenceTable
                rows={evidence}
                canReview={Boolean(can.review_evidence)}
            />
            {Boolean(can.request_evidence) && !can.locked && (
                <form
                    className="mt-3 grid gap-2 md:grid-cols-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            audit_procedure_id: data.audit_procedure_id || null,
                            audit_finding_id: data.audit_finding_id || null,
                        }));
                        form.post(
                            `/work/audit/engagements/${engagementId}/evidence`,
                            {
                                preserveScroll: true,
                                onSuccess: () =>
                                    form.reset('title', 'description'),
                            },
                        );
                    }}
                >
                    <Field label="Evidencia solicitada">
                        <input
                            className={input}
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Responsable">
                        <select
                            className={input}
                            value={form.data.requested_from_user_id}
                            onChange={(event) =>
                                form.setData(
                                    'requested_from_user_id',
                                    event.target.value,
                                )
                            }
                            required
                        >
                            <option value="">Selecciona…</option>
                            {responsibles.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Fecha límite">
                        <input
                            type="date"
                            className={input}
                            value={form.data.due_date}
                            onChange={(event) =>
                                form.setData('due_date', event.target.value)
                            }
                            required
                        />
                    </Field>
                    {!findingId && (
                        <Field label="Procedimiento">
                            <select
                                className={input}
                                value={form.data.audit_procedure_id}
                                onChange={(event) =>
                                    form.setData(
                                        'audit_procedure_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">—</option>
                                {procedures.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} · {p.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {!findingId && (
                        <Field label="Hallazgo">
                            <select
                                className={input}
                                value={form.data.audit_finding_id}
                                onChange={(event) =>
                                    form.setData(
                                        'audit_finding_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">—</option>
                                {findings.map((f) => (
                                    <option key={f.id} value={f.id}>
                                        {f.code} · {f.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field label="Descripción" wide>
                        <textarea
                            className={textarea}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <div>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Solicitar evidencia
                        </button>
                    </div>
                </form>
            )}
        </Card>
    );
}

export function EvidenceTable({
    rows,
    canReview,
    focus,
}: {
    rows: EvidenceRequest[];
    canReview: boolean;
    focus?: number | null;
}) {
    return (
        <Table
            headers={[
                'Código',
                'Empresa',
                'Descripción',
                'Solicitada a',
                'Fecha límite',
                'Entrega',
                'Versión',
                'Estado',
                'Acciones',
            ]}
            empty={
                rows.length === 0 &&
                'No existen solicitudes de evidencia pendientes.'
            }
        >
            {rows.map((row) => (
                <tr
                    key={row.id}
                    className={focus === row.id ? 'bg-amber-50' : ''}
                >
                    <td className="px-2 py-2 font-bold">
                        {row.code}
                        <p className="text-[11px] font-normal text-[#526b92]">
                            {row.engagement}
                        </p>
                    </td>
                    <td className="px-2 py-2">{row.company}</td>
                    <td className="px-2 py-2">
                        {row.title}
                        <p className="text-[11px] text-[#526b92]">
                            {row.description}
                        </p>
                    </td>
                    <td className="px-2 py-2">{row.requested_from}</td>
                    <td
                        className={`px-2 py-2 ${row.overdue ? 'font-bold text-red-700' : ''}`}
                    >
                        {formatDate(row.due_date)}
                    </td>
                    <td className="px-2 py-2">
                        {row.submission_note ?? '—'}
                        {row.source_label && (
                            <p className="text-[11px]">
                                {row.source_href ? (
                                    <a
                                        href={row.source_href}
                                        className="text-blue-700 hover:underline"
                                    >
                                        {row.source_label}
                                    </a>
                                ) : (
                                    row.source_label
                                )}
                            </p>
                        )}
                        {row.download && (
                            <p className="text-[11px]">
                                <a
                                    href={row.download}
                                    className="text-blue-700 hover:underline"
                                >
                                    {row.file_name}
                                </a>
                            </p>
                        )}
                        {row.review_note && (
                            <p className="text-[11px] text-amber-700">
                                {row.review_note}
                            </p>
                        )}
                    </td>
                    <td className="px-2 py-2">
                        {row.version > 0 ? `v${row.version}` : '—'}
                        {row.changed_since_audit === true && (
                            <p className="text-[11px] font-bold text-red-700">
                                El origen cambió después de la aceptación
                            </p>
                        )}
                        {row.changed_since_audit === null &&
                            row.status === 'accepted' && (
                                <p className="text-[11px] text-violet-700">
                                    No fue posible comparar con el origen
                                </p>
                            )}
                    </td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(
                                evidenceTone[row.status] ?? 'slate',
                            )}
                        >
                            {row.status_label}
                        </span>
                    </td>
                    <td className="px-2 py-2">
                        {canReview &&
                            ['submitted', 'under_review'].includes(
                                row.status,
                            ) && (
                                <div className="flex flex-wrap gap-1">
                                    {row.status === 'submitted' && (
                                        <ActionButton
                                            url={`/work/audit/evidence/${row.id}/review`}
                                            label="Revisar"
                                        />
                                    )}
                                    <ActionButton
                                        url={`/work/audit/evidence/${row.id}/accept`}
                                        label="Aceptar"
                                        tone="primary"
                                    />
                                    <ActionButton
                                        url={`/work/audit/evidence/${row.id}/more_information`}
                                        label="Más información"
                                        ask="¿Qué información falta?"
                                    />
                                    <ActionButton
                                        url={`/work/audit/evidence/${row.id}/reject`}
                                        label="Rechazar"
                                        ask="Motivo del rechazo"
                                    />
                                </div>
                            )}
                        {canReview &&
                            [
                                'requested',
                                'more_information_required',
                                'rejected',
                            ].includes(row.status) && (
                                <ActionButton
                                    url={`/work/audit/evidence/${row.id}/cancel`}
                                    label="Cancelar"
                                    ask="Motivo de la cancelación"
                                />
                            )}
                    </td>
                </tr>
            ))}
        </Table>
    );
}

export function PapersSection({
    engagementId,
    papers,
    procedures,
    can,
}: {
    engagementId: number;
    papers: Paper[];
    procedures: Procedure[];
    can: Can;
}) {
    const [editing, setEditing] = useState<Paper | null>(null);
    const blank = {
        title: '',
        audit_procedure_id: '',
        objective: '',
        methodology: '',
        work_performed: '',
        results: '',
        conclusion: '',
    };
    const form = useForm(blank);
    const open = Boolean(can.papers) && !can.locked;
    const edit = (paper: Paper | null) => {
        setEditing(paper);
        form.setData(
            paper === null
                ? blank
                : {
                      title: paper.title,
                      audit_procedure_id: paper.procedure_id
                          ? String(paper.procedure_id)
                          : '',
                      objective: paper.objective ?? '',
                      methodology: paper.methodology ?? '',
                      work_performed: paper.work_performed ?? '',
                      results: paper.results ?? '',
                      conclusion: paper.conclusion ?? '',
                  },
        );
    };
    const actions: Record<string, [string, string][]> = {
        draft: [['prepare', 'Marcar preparado']],
        prepared: [['submit', 'Enviar a revisión']],
        in_review: [
            ['review', 'Revisar (aprobar)'],
            ['return', 'Devolver'],
        ],
        reviewed: [
            ['finalize', 'Hacer final'],
            ['new_version', 'Nueva versión'],
        ],
        final: [['new_version', 'Nueva versión']],
    };

    return (
        <Card title="Papeles de trabajo">
            <Table
                headers={[
                    'Código',
                    'Versión',
                    'Título',
                    'Preparó',
                    'Revisó',
                    'Estado',
                    'Acciones',
                ]}
                empty={papers.length === 0 && 'Aún no hay papeles de trabajo.'}
            >
                {papers.map((paper) => (
                    <tr
                        key={paper.id}
                        className={paper.superseded ? 'opacity-60' : ''}
                    >
                        <td className="px-2 py-2 font-bold">{paper.code}</td>
                        <td className="px-2 py-2">
                            v{paper.version}
                            {paper.superseded && ' (reemplazada)'}
                        </td>
                        <td className="px-2 py-2">
                            {paper.title}
                            <p className="text-[11px] text-[#526b92]">
                                {paper.conclusion}
                            </p>
                        </td>
                        <td className="px-2 py-2">{paper.prepared_by}</td>
                        <td className="px-2 py-2">
                            {paper.reviewed_by ?? '—'}
                            {paper.review_note && (
                                <p className="text-[11px] text-[#526b92]">
                                    {paper.review_note}
                                </p>
                            )}
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    paper.status === 'final' ||
                                        paper.status === 'reviewed'
                                        ? 'green'
                                        : paper.status === 'in_review'
                                          ? 'amber'
                                          : 'slate',
                                )}
                            >
                                {paper.status_label}
                            </span>
                        </td>
                        <td className="px-2 py-2">
                            {open && !paper.superseded && (
                                <div className="flex flex-wrap gap-1">
                                    {['draft', 'prepared'].includes(
                                        paper.status,
                                    ) && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            onClick={() => edit(paper)}
                                        >
                                            Editar
                                        </button>
                                    )}
                                    {(actions[paper.status] ?? []).map(
                                        ([action, label]) => (
                                            <ActionButton
                                                key={action}
                                                url={`/work/audit/papers/${paper.id}/${action}`}
                                                label={label}
                                                ask={
                                                    action === 'return' ||
                                                    action === 'new_version'
                                                        ? 'Nota'
                                                        : undefined
                                                }
                                            />
                                        ),
                                    )}
                                </div>
                            )}
                        </td>
                    </tr>
                ))}
            </Table>
            {open && (
                <form
                    className="mt-3 grid gap-2 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            audit_procedure_id: data.audit_procedure_id || null,
                        }));
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => edit(null),
                        };

                        if (editing) {
                            form.put(
                                `/work/audit/engagements/${engagementId}/papers/${editing.id}`,
                                options,
                            );
                        } else {
                            form.post(
                                `/work/audit/engagements/${engagementId}/papers`,
                                options,
                            );
                        }
                    }}
                >
                    <p className="text-xs font-bold md:col-span-2">
                        {editing
                            ? `Editando ${editing.code} v${editing.version}`
                            : 'Nuevo papel de trabajo'}
                    </p>
                    <Field label="Título">
                        <input
                            className={input}
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Procedimiento">
                        <select
                            className={input}
                            value={form.data.audit_procedure_id}
                            onChange={(event) =>
                                form.setData(
                                    'audit_procedure_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">—</option>
                            {procedures.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.code} · {p.title}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {(
                        [
                            'objective',
                            'methodology',
                            'work_performed',
                            'results',
                            'conclusion',
                        ] as const
                    ).map((key) => (
                        <Field
                            key={key}
                            label={
                                {
                                    objective: 'Objetivo',
                                    methodology: 'Metodología',
                                    work_performed: 'Trabajo realizado',
                                    results: 'Resultados',
                                    conclusion: 'Conclusión',
                                }[key]
                            }
                        >
                            <textarea
                                className={textarea}
                                value={form.data[key]}
                                onChange={(event) =>
                                    form.setData(key, event.target.value)
                                }
                            />
                        </Field>
                    ))}
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            {editing ? 'Guardar cambios' : 'Crear papel'}
                        </button>
                        {editing && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => edit(null)}
                            >
                                Cancelar
                            </button>
                        )}
                    </div>
                </form>
            )}
        </Card>
    );
}

export function FindingForm({
    engagementId,
    procedures,
    responsibles,
    options,
    defaults = {},
}: {
    engagementId: number;
    procedures: { id: number; code: string; title: string }[];
    responsibles: Person[];
    options: Pick<Options, 'areas' | 'levels'> & {
        reconciliations?: Record<string, string>;
    };
    defaults?: Record<string, string>;
}) {
    const form = useForm({
        title: '',
        area: 'accounting',
        severity: 'medium',
        criterion: '',
        condition: '',
        cause: '',
        effect_risk: '',
        recommendation: '',
        responsible_user_id: '',
        due_date: '',
        audit_procedure_id: '',
        audit_sample_item_id: '',
        source_type: '',
        source_key: '',
        ...defaults,
    });

    return (
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
                form.post(`/work/audit/engagements/${engagementId}/findings`, {
                    preserveScroll: true,
                });
            }}
        >
            <Field label="Título">
                <input
                    className={input}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    required
                />
            </Field>
            <Field label="Área">
                <select
                    className={input}
                    value={form.data.area}
                    onChange={(event) =>
                        form.setData('area', event.target.value)
                    }
                >
                    {Object.entries(options.areas).map(([k, l]) => (
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
                    onChange={(event) =>
                        form.setData('severity', event.target.value)
                    }
                >
                    {Object.entries(options.levels).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Criterio: ¿qué debería ocurrir?">
                <textarea
                    className={textarea}
                    value={form.data.criterion}
                    onChange={(event) =>
                        form.setData('criterion', event.target.value)
                    }
                />
            </Field>
            <Field label="Condición: ¿qué se encontró?">
                <textarea
                    className={textarea}
                    value={form.data.condition}
                    onChange={(event) =>
                        form.setData('condition', event.target.value)
                    }
                />
            </Field>
            <Field label="Causa: ¿por qué ocurrió?">
                <textarea
                    className={textarea}
                    value={form.data.cause}
                    onChange={(event) =>
                        form.setData('cause', event.target.value)
                    }
                />
            </Field>
            <Field label="Efecto / riesgo">
                <textarea
                    className={textarea}
                    value={form.data.effect_risk}
                    onChange={(event) =>
                        form.setData('effect_risk', event.target.value)
                    }
                />
            </Field>
            <Field label="Recomendación">
                <textarea
                    className={textarea}
                    value={form.data.recommendation}
                    onChange={(event) =>
                        form.setData('recommendation', event.target.value)
                    }
                />
            </Field>
            <div className="grid gap-2">
                <Field label="Responsable operativo">
                    <select
                        className={input}
                        value={form.data.responsible_user_id}
                        onChange={(event) =>
                            form.setData(
                                'responsible_user_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">Selecciona…</option>
                        {responsibles.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Fecha compromiso">
                    <input
                        type="date"
                        className={input}
                        value={form.data.due_date}
                        onChange={(event) =>
                            form.setData('due_date', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field label="Procedimiento">
                <select
                    className={input}
                    value={form.data.audit_procedure_id}
                    onChange={(event) =>
                        form.setData('audit_procedure_id', event.target.value)
                    }
                >
                    <option value="">—</option>
                    {procedures.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.code} · {p.title}
                        </option>
                    ))}
                </select>
            </Field>
            {options.reconciliations && (
                <Field label="Conciliación relacionada (la corrección se comprobará en el origen)">
                    <select
                        className={input}
                        value={form.data.source_key}
                        onChange={(event) => {
                            form.setData((data) => ({
                                ...data,
                                source_key: event.target.value,
                                source_type: event.target.value
                                    ? 'reconciliation'
                                    : '',
                            }));
                        }}
                    >
                        <option value="">Ninguna</option>
                        {Object.entries(options.reconciliations).map(
                            ([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ),
                        )}
                    </select>
                </Field>
            )}
            <div className="flex items-end">
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing}
                >
                    Crear hallazgo (borrador)
                </button>
            </div>
        </form>
    );
}

export function FindingsSection({
    engagementId,
    findings,
    procedures,
    responsibles,
    options,
    can,
    followUp = false,
    responses = [],
}: {
    engagementId: number;
    findings: Finding[];
    procedures: Procedure[];
    responsibles: Person[];
    options: Options;
    can: Can;
    followUp?: boolean;
    responses?: Response[];
}) {
    const href = useAuditorHref();
    const rows = followUp
        ? findings.filter(
              (f) => !['draft', 'cancelled', 'rejected'].includes(f.status),
          )
        : findings;

    return (
        <Card
            title={
                followUp
                    ? 'Seguimiento de hallazgos'
                    : `Hallazgos (${findings.length})`
            }
        >
            <Table
                headers={[
                    'Código',
                    'Título',
                    'Área',
                    'Severidad',
                    'Responsable',
                    'Fecha compromiso',
                    'Días',
                    'Estado',
                    followUp ? 'Última respuesta' : 'Origen',
                ]}
                empty={rows.length === 0 && 'No existen hallazgos.'}
            >
                {rows.map((finding) => {
                    const last = [...responses]
                        .reverse()
                        .find((response) => response.finding_id === finding.id);

                    return (
                        <tr key={finding.id}>
                            <td className="px-2 py-2 font-bold">
                                <Link
                                    href={href(finding.destination)}
                                    className="text-blue-700 hover:underline"
                                >
                                    {finding.code}
                                </Link>
                            </td>
                            <td className="px-2 py-2">{finding.title}</td>
                            <td className="px-2 py-2">{finding.area_label}</td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        severityTone[finding.severity] ??
                                            'slate',
                                    )}
                                >
                                    {finding.severity_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {finding.responsible ?? '—'}
                            </td>
                            <td
                                className={`px-2 py-2 ${finding.overdue ? 'font-bold text-red-700' : ''}`}
                            >
                                {formatDate(finding.due_date)}
                            </td>
                            <td className="px-2 py-2">
                                {finding.days_open ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        findingTone[finding.status] ?? 'slate',
                                    )}
                                >
                                    {finding.status_label}
                                </span>
                            </td>
                            <td className="px-2 py-2 text-[11px]">
                                {followUp
                                    ? last
                                        ? `${last.by}: ${last.response}`
                                        : 'Sin respuesta'
                                    : (finding.source ?? '—')}
                            </td>
                        </tr>
                    );
                })}
            </Table>
            {!followUp && Boolean(can.findings) && !can.locked && (
                <div className="mt-3">
                    <p className="mb-2 text-xs text-[#526b92]">
                        Una excepción no es un hallazgo automático: documenta el
                        hallazgo solo si es relevante.
                    </p>
                    <FindingForm
                        engagementId={engagementId}
                        procedures={procedures}
                        responsibles={responsibles}
                        options={options}
                    />
                </div>
            )}
        </Card>
    );
}

export function ReportSection({
    reports,
    can,
    engagementStatus,
}: {
    reports: Report[];
    can: Can;
    engagementStatus: string;
}) {
    const current = reports[0];
    const content = (current?.content ?? {}) as Record<string, unknown>;
    const form = useForm({
        executive_summary: String(content.executive_summary ?? ''),
        conclusions: String(content.conclusions ?? ''),
        limitations: String(content.limitations ?? ''),
    });
    const [recipients, setRecipients] = useState('');

    if (!current) {
        return (
            <Card title="Informe">
                <p className="py-6 text-center text-sm text-slate-500">
                    El informe preliminar se genera al pasar la auditoría a
                    «Informe preliminar».
                </p>
            </Card>
        );
    }

    const editable =
        Boolean(can.reports) &&
        ['draft', 'in_review', 'awaiting_responses'].includes(current.status);
    const findings = (content.findings ?? []) as {
        code: string;
        title: string;
        severity: string;
        status: string;
        response: {
            acceptance: string;
            response: string;
            action_plan: string | null;
        } | null;
    }[];

    return (
        <div className="grid gap-3">
            <Card
                title={`Informe v${current.version} · ${current.status_label}`}
                action={
                    <a href={current.download} className={secondaryButton}>
                        Descargar PDF
                    </a>
                }
            >
                <form
                    className="grid gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/work/audit/reports/${current.id}`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    {(
                        [
                            'executive_summary',
                            'limitations',
                            'conclusions',
                        ] as const
                    ).map((key) => (
                        <Field
                            key={key}
                            label={
                                {
                                    executive_summary: 'Resumen ejecutivo',
                                    limitations: 'Limitaciones',
                                    conclusions: 'Conclusiones',
                                }[key]
                            }
                        >
                            <textarea
                                className={textarea}
                                value={form.data[key]}
                                disabled={!editable}
                                onChange={(event) =>
                                    form.setData(key, event.target.value)
                                }
                            />
                        </Field>
                    ))}
                    {editable && (
                        <div>
                            <button
                                type="submit"
                                className={secondaryButton}
                                disabled={form.processing}
                            >
                                Guardar secciones
                            </button>
                        </div>
                    )}
                </form>
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    {Boolean(can.final_approve) &&
                        current.status === 'in_review' &&
                        engagementStatus === 'final_review' && (
                            <ActionButton
                                url={`/work/audit/reports/${current.id}/final_approve`}
                                label="Aprobación final"
                                tone="primary"
                            />
                        )}
                    {Boolean(can.issue) && current.status === 'final' && (
                        <>
                            <input
                                className={`${input} w-80`}
                                placeholder="Destinatarios del informe"
                                value={recipients}
                                onChange={(event) =>
                                    setRecipients(event.target.value)
                                }
                            />
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() =>
                                    router.post(
                                        `/work/audit/reports/${current.id}/issue`,
                                        { recipients },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Emitir informe
                            </button>
                        </>
                    )}
                    {Boolean(can.reports) &&
                        ['final', 'issued'].includes(current.status) &&
                        engagementStatus !== 'closed' && (
                            <ActionButton
                                url={`/work/audit/reports/${current.id}/new_version`}
                                label="Nueva versión"
                            />
                        )}
                </div>
            </Card>
            <Card title="Hallazgos y respuestas incluidos">
                <Table
                    headers={[
                        'Código',
                        'Hallazgo',
                        'Severidad',
                        'Estado',
                        'Respuesta de la administración',
                    ]}
                    empty={
                        findings.length === 0 &&
                        'El informe no incluye hallazgos.'
                    }
                >
                    {findings.map((finding) => (
                        <tr key={finding.code}>
                            <td className="px-2 py-2 font-bold">
                                {finding.code}
                            </td>
                            <td className="px-2 py-2">{finding.title}</td>
                            <td className="px-2 py-2">{finding.severity}</td>
                            <td className="px-2 py-2">{finding.status}</td>
                            <td className="px-2 py-2 text-[11px]">
                                {finding.response
                                    ? `${finding.response.acceptance}: ${finding.response.response}`
                                    : 'Pendiente'}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
            <Card title="Versiones">
                <Table
                    headers={[
                        'Versión',
                        'Estado',
                        'Generó',
                        'Aprobó',
                        'Emitió',
                        'Destinatarios',
                        '',
                    ]}
                >
                    {reports.map((report) => (
                        <tr key={report.id}>
                            <td className="px-2 py-2 font-bold">
                                v{report.version}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        reportTone[report.status] ?? 'slate',
                                    )}
                                >
                                    {report.status_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {report.generated_by} ·{' '}
                                {formatDate(report.generated_at)}
                            </td>
                            <td className="px-2 py-2">
                                {report.reviewed_by ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                {report.issued_by
                                    ? `${report.issued_by} · ${formatDate(report.issued_at)}`
                                    : '—'}
                            </td>
                            <td className="px-2 py-2">
                                {report.recipients ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                <a
                                    href={report.download}
                                    className="text-xs font-bold text-[#640000]"
                                >
                                    PDF
                                </a>
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </div>
    );
}

export function ActivitySection({
    activity,
}: {
    activity: { action: string; user: string; occurred_at: string }[];
}) {
    return (
        <Card title="Actividad de la auditoría">
            <Table
                headers={['Fecha', 'Acción', 'Usuario']}
                empty={activity.length === 0 && 'Sin actividad registrada.'}
            >
                {activity.map((event, index) => (
                    <tr key={index}>
                        <td className="px-2 py-2 whitespace-nowrap">
                            {formatDate(event.occurred_at, true)}
                        </td>
                        <td className="px-2 py-2 font-mono text-[11px]">
                            {event.action}
                        </td>
                        <td className="px-2 py-2">{event.user}</td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

export { labelList };
