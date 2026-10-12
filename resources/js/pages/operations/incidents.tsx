import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Fragment, useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    input,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import {
    NewIncidentDialog,
    incidentStatusLabel,
    severityLabel,
} from '@/components/operations/kit';
import type { Context, TaskOptions } from '@/components/operations/kit';

type Incident = {
    id: number;
    office_id: number;
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
    source: string | null;
    resolution: string | null;
    created_at: string;
};

export default function OperationsIncidents({
    incidents,
    focus,
    taskOptions,
    permissions,
    notice,
}: {
    context: Context;
    incidents: Incident[];
    focus: number | null;
    types: Record<string, string>;
    taskOptions: TaskOptions;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<number | null>(focus);
    const [form, setForm] = useState<{
        status: string;
        responsible_id: string;
        resolution: string;
    }>({ status: 'in_progress', responsible_id: '', resolution: '' });
    const [showClosed, setShowClosed] = useState(false);
    const rows = incidents.filter(
        (incident) =>
            showClosed || !['resolved', 'dismissed'].includes(incident.status),
    );

    return (
        <>
            <Head title="Incidencias" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Incidencias"
                    subtitle="Problemas que bloquean o ponen en riesgo el período de una empresa"
                    actions={
                        permissions.canManageIncidents && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setOpen(true)}
                            >
                                <Plus className="size-4" /> Registrar incidencia
                            </button>
                        )
                    }
                />
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={showClosed}
                        onChange={(event) =>
                            setShowClosed(event.target.checked)
                        }
                    />{' '}
                    Mostrar resueltas y descartadas
                </label>
                <section className={`${panel} p-3`}>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Incidencia</th>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Tipo</th>
                                    <th className="px-2 py-2">Severidad</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2">Creada</th>
                                    <th className="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((incident) => {
                                    const [severity, severityTone] =
                                        severityLabel[incident.severity] ?? [
                                            '—',
                                            'slate',
                                        ];
                                    const [status, statusTone] =
                                        incidentStatusLabel[
                                            incident.status
                                        ] ?? ['—', 'slate'];
                                    const people = taskOptions.users.filter(
                                        (user) =>
                                            user.office_ids.includes(
                                                incident.office_id,
                                            ),
                                    );

                                    return (
                                        <Fragment key={incident.id}>
                                            <tr
                                                className={
                                                    editing === incident.id
                                                        ? 'bg-amber-50/60'
                                                        : ''
                                                }
                                            >
                                                <td className="px-2 py-2">
                                                    <p className="font-bold">
                                                        {incident.title}
                                                    </p>
                                                    {incident.description && (
                                                        <p className="text-[11px] text-[#526b92]">
                                                            {
                                                                incident.description
                                                            }
                                                        </p>
                                                    )}
                                                    {incident.blocks_period && (
                                                        <span
                                                            className={badge(
                                                                'red',
                                                            )}
                                                        >
                                                            Bloquea el período
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {incident.company_name ??
                                                        '—'}
                                                    {incident.period_name
                                                        ? ` · ${incident.period_name}`
                                                        : ''}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {incident.type_label}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <span
                                                        className={badge(
                                                            severityTone as Tone,
                                                        )}
                                                    >
                                                        {severity}
                                                    </span>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <span
                                                        className={badge(
                                                            statusTone as Tone,
                                                        )}
                                                    >
                                                        {status}
                                                    </span>
                                                    {incident.resolution && (
                                                        <p className="text-[11px] text-[#526b92]">
                                                            {
                                                                incident.resolution
                                                            }
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {incident.responsible_name ?? (
                                                        <span className="text-amber-700">
                                                            Sin asignar
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {formatDate(
                                                        incident.created_at,
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {permissions.canManageIncidents &&
                                                        ![
                                                            'resolved',
                                                            'dismissed',
                                                        ].includes(
                                                            incident.status,
                                                        ) && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    secondaryButton
                                                                }
                                                                onClick={() => {
                                                                    setEditing(
                                                                        incident.id,
                                                                    );
                                                                    setForm({
                                                                        status:
                                                                            incident.status ===
                                                                            'open'
                                                                                ? 'in_progress'
                                                                                : incident.status,
                                                                        responsible_id:
                                                                            incident.responsible_id?.toString() ??
                                                                            '',
                                                                        resolution:
                                                                            '',
                                                                    });
                                                                }}
                                                            >
                                                                Gestionar
                                                            </button>
                                                        )}
                                                </td>
                                            </tr>
                                            {editing === incident.id &&
                                                permissions.canManageIncidents &&
                                                ![
                                                    'resolved',
                                                    'dismissed',
                                                ].includes(incident.status) && (
                                                    <tr
                                                        key={`${incident.id}-edit`}
                                                    >
                                                        <td
                                                            colSpan={8}
                                                            className="bg-amber-50/40 px-2 py-2"
                                                        >
                                                            <div className="grid gap-2 md:grid-cols-[160px_220px_1fr_auto]">
                                                                <select
                                                                    className={
                                                                        input
                                                                    }
                                                                    value={
                                                                        form.status
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setForm(
                                                                            {
                                                                                ...form,
                                                                                status: event
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <option value="in_progress">
                                                                        En
                                                                        proceso
                                                                    </option>
                                                                    <option value="blocked">
                                                                        Bloqueada
                                                                    </option>
                                                                    <option value="resolved">
                                                                        Resuelta
                                                                    </option>
                                                                    <option value="dismissed">
                                                                        Descartada
                                                                    </option>
                                                                </select>
                                                                <select
                                                                    className={
                                                                        input
                                                                    }
                                                                    value={
                                                                        form.responsible_id
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setForm(
                                                                            {
                                                                                ...form,
                                                                                responsible_id:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <option value="">
                                                                        Mantener
                                                                        responsable
                                                                    </option>
                                                                    {people.map(
                                                                        (
                                                                            user,
                                                                        ) => (
                                                                            <option
                                                                                key={
                                                                                    user.id
                                                                                }
                                                                                value={
                                                                                    user.id
                                                                                }
                                                                            >
                                                                                {
                                                                                    user.name
                                                                                }
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </select>
                                                                <input
                                                                    className={
                                                                        input
                                                                    }
                                                                    placeholder="Resolución (obligatoria para resolver o descartar)"
                                                                    value={
                                                                        form.resolution
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setForm(
                                                                            {
                                                                                ...form,
                                                                                resolution:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                <button
                                                                    type="button"
                                                                    className={
                                                                        primaryButton
                                                                    }
                                                                    onClick={() =>
                                                                        router.patch(
                                                                            `/operations/incidents/${incident.id}`,
                                                                            {
                                                                                status: form.status,
                                                                                responsible_id:
                                                                                    form.responsible_id ||
                                                                                    null,
                                                                                resolution:
                                                                                    form.resolution ||
                                                                                    null,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                                onSuccess:
                                                                                    () =>
                                                                                        setEditing(
                                                                                            null,
                                                                                        ),
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Guardar
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                        </Fragment>
                                    );
                                })}
                                {rows.length === 0 && (
                                    <EmptyRow
                                        colSpan={8}
                                        text="No existen incidencias abiertas."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </Shell>
            <NewIncidentDialog
                open={open}
                onClose={() => setOpen(false)}
                options={taskOptions}
            />
        </>
    );
}
