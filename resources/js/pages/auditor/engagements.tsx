import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Field,
    Progress,
    engagementTone,
    textarea,
    useAuditorHref,
} from '@/components/auditor/kit';
import type {
    AuditorContext,
    AuditorPermissions,
    Engagement,
} from '@/components/auditor/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function AuditorEngagements({
    context,
    engagements,
    filters,
    statuses,
    types,
    areas,
    levels,
    auditors,
    permissions,
    notice,
}: {
    context: AuditorContext;
    engagements: Engagement[];
    filters: { company_id: number | null; status: string };
    statuses: Record<string, string>;
    types: Record<string, string>;
    areas: Record<string, string>;
    levels: Record<string, string>;
    auditors: { id: number; name: string }[];
    permissions: AuditorPermissions;
    notice: string | null;
}) {
    const href = useAuditorHref();
    const [creating, setCreating] = useState(false);
    const form = useForm({
        company_id: '',
        audit_type: 'financial',
        title: '',
        objective: '',
        period_start: '',
        period_end: '',
        lead_auditor_id: '',
        reviewer_id: '',
        risk_level: 'medium',
        planned_start_date: '',
        planned_end_date: '',
        areas: [] as string[],
    });
    const filter = (key: string, value: string) =>
        router.get(
            href('/work/audit/engagements'),
            { ...filters, [key]: value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Mis auditorías" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Mis auditorías"
                    subtitle="Trabajos de auditoría asignados; la creación y asignación la hace un responsable autorizado"
                    actions={
                        permissions.canCreateEngagements && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setCreating(!creating)}
                            >
                                {creating ? 'Cerrar' : 'Nueva auditoría'}
                            </button>
                        )
                    }
                />
                {creating && (
                    <Card title="Crear y asignar auditoría">
                        <form
                            className="grid gap-3 md:grid-cols-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.transform((data) => ({
                                    ...data,
                                    reviewer_id: data.reviewer_id || null,
                                    planned_start_date:
                                        data.planned_start_date || null,
                                    planned_end_date:
                                        data.planned_end_date || null,
                                }));
                                form.post('/work/audit/engagements');
                            }}
                        >
                            <Field label="Empresa">
                                <select
                                    className={input}
                                    value={form.data.company_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'company_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {context.companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Tipo">
                                <select
                                    className={input}
                                    value={form.data.audit_type}
                                    onChange={(event) =>
                                        form.setData(
                                            'audit_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(types).map(
                                        ([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                            <Field label="Nivel de riesgo inicial">
                                <select
                                    className={input}
                                    value={form.data.risk_level}
                                    onChange={(event) =>
                                        form.setData(
                                            'risk_level',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(levels).map(
                                        ([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                            <Field label="Título" wide>
                                <input
                                    className={input}
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Objetivo" wide>
                                <textarea
                                    className={textarea}
                                    value={form.data.objective}
                                    onChange={(event) =>
                                        form.setData(
                                            'objective',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Período auditado desde">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.period_start}
                                    onChange={(event) =>
                                        form.setData(
                                            'period_start',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Período auditado hasta">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.period_end}
                                    onChange={(event) =>
                                        form.setData(
                                            'period_end',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Auditor responsable">
                                <select
                                    className={input}
                                    value={form.data.lead_auditor_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'lead_auditor_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {auditors.map((auditor) => (
                                        <option
                                            key={auditor.id}
                                            value={auditor.id}
                                        >
                                            {auditor.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Revisor (opcional)">
                                <select
                                    className={input}
                                    value={form.data.reviewer_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'reviewer_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Sin revisor</option>
                                    {auditors.map((auditor) => (
                                        <option
                                            key={auditor.id}
                                            value={auditor.id}
                                        >
                                            {auditor.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Inicio planificado">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.planned_start_date}
                                    onChange={(event) =>
                                        form.setData(
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
                                    value={form.data.planned_end_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'planned_end_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Áreas del alcance" wide>
                                <div className="flex flex-wrap gap-2">
                                    {Object.entries(areas).map(
                                        ([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-xs font-normal"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.areas.includes(
                                                        key,
                                                    )}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'areas',
                                                            event.target.checked
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .areas,
                                                                      key,
                                                                  ]
                                                                : form.data.areas.filter(
                                                                      (area) =>
                                                                          area !==
                                                                          key,
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
                            <div>
                                <button
                                    type="submit"
                                    className={primaryButton}
                                    disabled={form.processing}
                                >
                                    Crear auditoría
                                </button>
                            </div>
                        </form>
                    </Card>
                )}
                <Card
                    title={`${engagements.length} auditoría(s)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Empresa"
                                className={`${input} h-8 w-48 text-xs`}
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
                                className={`${input} h-8 w-48 text-xs`}
                                value={filters.status}
                                onChange={(event) =>
                                    filter('status', event.target.value)
                                }
                            >
                                <option value="">Todos</option>
                                <option value="active">Activas</option>
                                {Object.entries(statuses).map(
                                    ([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Código',
                            'Empresa',
                            'Título',
                            'Período',
                            'Tipo',
                            'Responsable',
                            'Avance',
                            'Hallazgos',
                            'Evidencia',
                            'Riesgo',
                            'Estado',
                            'Fecha límite',
                        ]}
                        empty={
                            engagements.length === 0 &&
                            'No tienes auditorías asignadas.'
                        }
                    >
                        {engagements.map((audit) => (
                            <tr key={audit.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(audit.destination)}
                                        className="text-blue-700 hover:underline"
                                    >
                                        {audit.code}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{audit.company}</td>
                                <td className="px-2 py-2">{audit.title}</td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {audit.period}
                                </td>
                                <td className="px-2 py-2">
                                    {audit.type_label}
                                </td>
                                <td className="px-2 py-2">
                                    {audit.lead_auditor}
                                </td>
                                <td className="px-2 py-2">
                                    <Progress value={audit.progress} />
                                </td>
                                <td className="px-2 py-2">
                                    {audit.findings_count}
                                </td>
                                <td className="px-2 py-2">
                                    {audit.evidence_pending}
                                </td>
                                <td className="px-2 py-2">
                                    {audit.risk_label}
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
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
