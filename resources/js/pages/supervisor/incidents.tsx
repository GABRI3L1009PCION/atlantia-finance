import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import type {
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Incident = {
    id: number;
    company_name: string | null;
    period_name: string | null;
    type_label: string;
    title: string;
    description: string | null;
    severity: string;
    status: string;
    blocks_period: boolean;
    responsible_id: number | null;
    responsible_name: string | null;
    resolution: string | null;
    created_at: string;
};

const severityLabel: Record<string, [string, string]> = {
    low: ['Baja', 'slate'],
    medium: ['Media', 'blue'],
    high: ['Alta', 'amber'],
    critical: ['Crítica', 'red'],
};
const statusToneOf: Record<string, string> = {
    open: 'red',
    in_progress: 'blue',
    blocked: 'violet',
    resolved: 'green',
    dismissed: 'slate',
};

type IncidentContext = Pick<SupervisorContext, 'companies'> & {
    team?: SupervisorContext['team'];
    assistants?: { id: number; name: string }[];
};

/** Incident list shared by the supervisor and accountant panels; basePath picks whose routes it posts to. */
export default function SupervisorIncidents({
    context,
    incidents,
    types,
    statusLabels,
    permissions,
    notice,
    basePath = '/supervisor',
}: {
    context: IncidentContext;
    incidents: Incident[];
    types: Record<string, string>;
    statusLabels: Record<string, string>;
    permissions: SupervisorPermissions;
    notice: string | null;
    basePath?: string;
}) {
    const people = context.team ?? context.assistants ?? [];
    const [creating, setCreating] = useState(false);
    const [filter, setFilter] = useState('active');
    const form = useForm({
        company_id: context.companies[0]?.id.toString() ?? '',
        type: Object.keys(types)[0] ?? 'accounting',
        severity: 'medium',
        title: '',
        description: '',
        responsible_id: '',
    });
    const visible = incidents.filter((incident) =>
        filter === 'active'
            ? ['open', 'in_progress', 'blocked'].includes(incident.status)
            : filter === '' || incident.status === filter,
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${basePath}/incidents`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('title', 'description');
                setCreating(false);
            },
        });
    };
    const update = (
        incident: Incident,
        status: string,
        responsibleId?: string,
    ) => {
        const resolution = ['resolved', 'dismissed'].includes(status)
            ? window.prompt('Describe la resolución (opcional):')
            : null;
        router.patch(
            `${basePath}/incidents/${incident.id}`,
            {
                status,
                responsible_id: responsibleId ?? incident.responsible_id,
                resolution,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Incidencias" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Incidencias"
                    subtitle="Problemas contables, fiscales, bancarios y documentales de tus empresas"
                    actions={
                        permissions.canManageIncidents && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setCreating(true)}
                            >
                                <Plus className="size-4" /> Registrar incidencia
                            </button>
                        )
                    }
                />
                <Card
                    title={`${visible.length} incidencia(s)`}
                    action={
                        <select
                            aria-label="Estado"
                            className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                        >
                            <option value="active">Activas</option>
                            <option value="">Todas</option>
                            {Object.entries(statusLabels).map(
                                ([code, label]) => (
                                    <option key={code} value={code}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    }
                >
                    <Table
                        headers={[
                            'Incidencia',
                            'Empresa',
                            'Tipo',
                            'Severidad',
                            'Responsable',
                            'Registrada',
                            'Estado',
                            'Acciones',
                        ]}
                        empty={visible.length === 0 && 'Sin incidencias.'}
                    >
                        {visible.map((incident) => {
                            const [severity, tone] = severityLabel[
                                incident.severity
                            ] ?? [incident.severity, 'slate'];

                            return (
                                <tr key={incident.id} className="align-top">
                                    <td className="px-2 py-2">
                                        <p className="font-bold">
                                            {incident.title}
                                        </p>
                                        {incident.description && (
                                            <p className="text-[11px] text-[#526b92]">
                                                {incident.description}
                                            </p>
                                        )}
                                        {incident.resolution && (
                                            <p className="text-[11px] text-emerald-700">
                                                Resolución:{' '}
                                                {incident.resolution}
                                            </p>
                                        )}
                                        {incident.blocks_period && (
                                            <p className="text-[11px] font-bold text-red-700">
                                                Bloquea el cierre
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {incident.company_name}
                                        <p className="text-[11px] text-[#526b92]">
                                            {incident.period_name}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {incident.type_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {severity}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {permissions.canManageIncidents ? (
                                            <select
                                                aria-label="Responsable"
                                                className="h-8 rounded-lg border border-slate-200 px-1 text-xs"
                                                value={
                                                    incident.responsible_id?.toString() ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    update(
                                                        incident,
                                                        incident.status,
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Sin responsable
                                                </option>
                                                {people.map((member) => (
                                                    <option
                                                        key={member.id}
                                                        value={member.id}
                                                    >
                                                        {member.name}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            (incident.responsible_name ??
                                            'Sin responsable')
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(incident.created_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                statusToneOf[incident.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {statusLabels[incident.status] ??
                                                incident.status}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {permissions.canManageIncidents && (
                                            <select
                                                aria-label="Cambiar estado"
                                                className="h-8 rounded-lg border border-slate-200 px-1 text-xs"
                                                value=""
                                                onChange={(event) =>
                                                    event.target.value &&
                                                    update(
                                                        incident,
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Cambiar estado
                                                </option>
                                                {Object.entries(statusLabels)
                                                    .filter(
                                                        ([code]) =>
                                                            code !==
                                                            incident.status,
                                                    )
                                                    .map(([code, label]) => (
                                                        <option
                                                            key={code}
                                                            value={code}
                                                        >
                                                            {label}
                                                        </option>
                                                    ))}
                                            </select>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>

            <Dialog
                open={creating}
                onOpenChange={(value) => !value && setCreating(false)}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Registrar incidencia</DialogTitle>
                        <DialogDescription>
                            Las incidencias críticas bloquean el cierre del
                            período hasta resolverse.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={submit}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        <label className="text-sm font-semibold">
                            Empresa
                            <select
                                className={`${input} mt-1`}
                                value={form.data.company_id}
                                onChange={(event) =>
                                    form.setData(
                                        'company_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">
                            Tipo
                            <select
                                className={`${input} mt-1`}
                                value={form.data.type}
                                onChange={(event) =>
                                    form.setData('type', event.target.value)
                                }
                            >
                                {Object.entries(types).map(([code, label]) => (
                                    <option key={code} value={code}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">
                            Severidad
                            <select
                                className={`${input} mt-1`}
                                value={form.data.severity}
                                onChange={(event) =>
                                    form.setData('severity', event.target.value)
                                }
                            >
                                {Object.entries(severityLabel).map(
                                    ([code, [label]]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">
                            Responsable
                            <select
                                className={`${input} mt-1`}
                                value={form.data.responsible_id}
                                onChange={(event) =>
                                    form.setData(
                                        'responsible_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Sin responsable</option>
                                {people.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-semibold sm:col-span-2">
                            Título
                            <input
                                className={`${input} mt-1`}
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="text-sm font-semibold sm:col-span-2">
                            Descripción
                            <textarea
                                className={`${input} mt-1 h-20 py-2`}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        {Object.values(form.errors).length > 0 && (
                            <p className="text-sm text-red-700 sm:col-span-2">
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                        <DialogFooter className="sm:col-span-2">
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setCreating(false)}
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Registrar
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
