import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink, Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
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
    NewTaskDialog,
    TaskManageDialog,
    companyStatusTone,
    dueLabel,
    incidentStatusLabel,
    severityLabel,
    useScopedHref,
} from '@/components/operations/kit';
import type {
    Context,
    TaskOptions,
    TaskRow,
} from '@/components/operations/kit';

type Company = {
    id: number;
    name: string;
    tax_id: string;
    office_name: string;
    accountant: { id: number; name: string } | null;
    assistant: { id: number; name: string } | null;
    progress: number;
    stages: { label: string; total: number; done: number; progress: number }[];
    open_tasks: number;
    overdue_tasks: number;
    status: string;
    status_label: string;
    overdue: boolean;
    blocked: boolean;
    due_at: string | null;
    period_name: string | null;
    next_action: string;
    destination: string;
};

type Detail = Company & {
    tasks: TaskRow[];
    reviews: {
        id: number;
        status_label: string;
        reviewer_name: string | null;
        submitted_at: string | null;
        destination: string;
    }[];
    incidents: {
        id: number;
        title: string;
        severity: string;
        status: string;
        responsible_name: string | null;
    }[];
    closing: {
        blockers: {
            label: string;
            status: string;
            message: string;
            destination: string;
        }[];
    } | null;
    structural: {
        structural: {
            user_id: number;
            name: string;
            role: string;
            is_primary: boolean;
        }[];
    } | null;
};

export default function OperationsCompanies({
    context,
    companies,
    detail,
    taskOptions,
    permissions,
    notice,
}: {
    context: Context;
    companies: Company[];
    detail: Detail | null;
    taskOptions: TaskOptions;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const scoped = useScopedHref();
    const [filter, setFilter] = useState('');
    const [taskOpen, setTaskOpen] = useState(false);
    const [managed, setManaged] = useState<TaskRow | null>(null);
    const visible = companies.filter((company) =>
        `${company.name} ${company.tax_id} ${company.status_label}`
            .toLowerCase()
            .includes(filter.toLowerCase()),
    );

    return (
        <>
            <Head title="Empresas en operación" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Empresas"
                    subtitle={`Estado operativo de cada cliente · ${context.period?.name ?? 'Sin período'}`}
                />
                <div
                    className={`grid gap-3 ${detail ? 'xl:grid-cols-[minmax(0,1fr)_460px]' : ''}`}
                >
                    <section className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title={`Empresas (${companies.length})`}
                            action={
                                <input
                                    className={`${input} max-w-60`}
                                    placeholder="Filtrar"
                                    value={filter}
                                    onChange={(event) =>
                                        setFilter(event.target.value)
                                    }
                                />
                            }
                        />
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-2 py-2">Empresa</th>
                                        <th className="px-2 py-2">Contador</th>
                                        <th className="px-2 py-2">Auxiliar</th>
                                        <th className="px-2 py-2">Avance</th>
                                        <th className="px-2 py-2 text-right">
                                            Pendientes
                                        </th>
                                        <th className="px-2 py-2">Estado</th>
                                        <th className="px-2 py-2">
                                            Siguiente paso
                                        </th>
                                        <th className="px-2 py-2">Límite</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {visible.map((company) => (
                                        <tr
                                            key={company.id}
                                            className={
                                                detail?.id === company.id
                                                    ? 'bg-amber-50/60'
                                                    : ''
                                            }
                                        >
                                            <td className="px-2 py-2">
                                                <Link
                                                    href={scoped(
                                                        `/operations/companies?company=${company.id}`,
                                                    )}
                                                    preserveScroll
                                                    className="font-bold hover:text-[#640000]"
                                                >
                                                    {company.name}
                                                </Link>
                                                <p className="text-[11px] text-[#526b92]">
                                                    NIT {company.tax_id} ·{' '}
                                                    {company.office_name}
                                                </p>
                                            </td>
                                            <td className="px-2 py-2">
                                                {company.accountant?.name ?? (
                                                    <span className="text-red-700">
                                                        Sin contador
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {company.assistant?.name ?? (
                                                    <span className="text-amber-700">
                                                        Sin auxiliar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2 font-black">
                                                {company.progress}%
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {company.open_tasks}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        company.blocked
                                                            ? 'red'
                                                            : (companyStatusTone[
                                                                  company.status
                                                              ] ?? 'slate'),
                                                    )}
                                                >
                                                    {company.blocked
                                                        ? 'Bloqueada'
                                                        : company.status_label}
                                                </span>
                                                {company.overdue && (
                                                    <span
                                                        className={`ml-1 ${badge('red')}`}
                                                    >
                                                        Atrasada
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2 text-[#395277]">
                                                {company.next_action}
                                            </td>
                                            <td className="px-2 py-2">
                                                {formatDate(company.due_at)}
                                            </td>
                                        </tr>
                                    ))}
                                    {visible.length === 0 && (
                                        <EmptyRow
                                            colSpan={8}
                                            text="No existen empresas asignadas a esta oficina."
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {detail && (
                        <aside className={`${panel} space-y-4 p-4`}>
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <h2 className="text-xl font-black">
                                        {detail.name}
                                    </h2>
                                    <p className="text-sm text-[#526b92]">
                                        {detail.period_name ?? 'Sin período'} ·
                                        límite {formatDate(detail.due_at)}
                                    </p>
                                    <p className="mt-1">
                                        <span
                                            className={badge(
                                                detail.blocked
                                                    ? 'red'
                                                    : (companyStatusTone[
                                                          detail.status
                                                      ] ?? 'slate'),
                                            )}
                                        >
                                            {detail.blocked
                                                ? 'Bloqueada'
                                                : detail.status_label}
                                        </span>{' '}
                                        <span className="ml-1 text-sm font-bold">
                                            {detail.progress}%
                                        </span>
                                    </p>
                                </div>
                                <Link
                                    href={scoped('/operations/companies')}
                                    className="text-[#526b92]"
                                >
                                    <X className="size-5" />
                                </Link>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <a
                                    href={detail.destination}
                                    className={secondaryButton}
                                >
                                    <ExternalLink className="size-3.5" /> Ver
                                    empresa
                                </a>
                                {permissions.canCreateTasks && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => setTaskOpen(true)}
                                    >
                                        <Plus className="size-3.5" /> Nueva
                                        tarea
                                    </button>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Responsables
                                </h3>
                                <ul className="space-y-0.5 text-xs">
                                    {detail.structural?.structural.map(
                                        (person) => (
                                            <li
                                                key={`${person.user_id}-${person.role}`}
                                            >
                                                {person.name} · {person.role}
                                                {person.is_primary
                                                    ? ' (principal)'
                                                    : ''}
                                            </li>
                                        ),
                                    )}
                                    {(detail.structural?.structural.length ??
                                        0) === 0 && (
                                        <li className="text-red-700">
                                            Sin responsables. El administrador
                                            de oficina debe asignarlos.
                                        </li>
                                    )}
                                </ul>
                                {permissions.canRequestAssignmentChange &&
                                    !permissions.canManageAssignments && (
                                        <RequestChange
                                            companyId={detail.id}
                                            users={taskOptions.users}
                                        />
                                    )}
                            </div>

                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Avance por etapa
                                </h3>
                                {detail.stages.map((stage) => (
                                    <div
                                        key={stage.label}
                                        className="mb-1.5 grid grid-cols-[110px_1fr_40px] items-center gap-2 text-xs"
                                    >
                                        <span>{stage.label}</span>
                                        <div className="h-2 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className="h-full bg-blue-500"
                                                style={{
                                                    width: `${stage.progress}%`,
                                                }}
                                            />
                                        </div>
                                        <span className="text-right font-black">
                                            {stage.progress}%
                                        </span>
                                    </div>
                                ))}
                                {detail.stages.length === 0 && (
                                    <p className="text-xs text-[#526b92]">
                                        Sin tareas en el período; las etapas no
                                        aplican todavía.
                                    </p>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Tareas (
                                    {
                                        detail.tasks.filter(
                                            (task) =>
                                                ![
                                                    'completed',
                                                    'approved',
                                                    'cancelled',
                                                ].includes(task.status),
                                        ).length
                                    }{' '}
                                    abiertas)
                                </h3>
                                <ul className="max-h-56 space-y-1 overflow-y-auto text-xs">
                                    {detail.tasks.map((task) => {
                                        const [label, tone] = dueLabel(task);

                                        return (
                                            <li key={task.id}>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setManaged(task)
                                                    }
                                                    className="flex w-full justify-between gap-2 rounded p-1 text-left hover:bg-slate-50"
                                                >
                                                    <span>
                                                        {task.title}{' '}
                                                        <span className="text-[#526b92]">
                                                            ·{' '}
                                                            {task.assignee_name ??
                                                                'Sin responsable'}{' '}
                                                            ·{' '}
                                                            {task.status_label}
                                                        </span>
                                                    </span>
                                                    <span
                                                        className={badge(tone)}
                                                    >
                                                        {label}
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>

                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Revisiones
                                </h3>
                                {detail.reviews.map((review) => (
                                    <p key={review.id} className="text-xs">
                                        <Link
                                            href={review.destination}
                                            className="font-bold hover:text-[#640000]"
                                        >
                                            {review.status_label}
                                        </Link>{' '}
                                        ·{' '}
                                        {review.reviewer_name ?? 'Sin revisor'}{' '}
                                        · {formatDate(review.submitted_at)}
                                    </p>
                                ))}
                                {detail.reviews.length === 0 && (
                                    <p className="text-xs text-[#526b92]">
                                        Aún no se envía a revisión.
                                    </p>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Incidencias
                                </h3>
                                {detail.incidents.map((incident) => {
                                    const [severity, tone] = severityLabel[
                                        incident.severity
                                    ] ?? ['—', 'slate'];

                                    return (
                                        <p
                                            key={incident.id}
                                            className="text-xs"
                                        >
                                            <span
                                                className={badge(tone as Tone)}
                                            >
                                                {severity}
                                            </span>{' '}
                                            {incident.title} ·{' '}
                                            {
                                                (incidentStatusLabel[
                                                    incident.status
                                                ] ?? [incident.status])[0]
                                            }
                                        </p>
                                    );
                                })}
                                {detail.incidents.length === 0 && (
                                    <p className="text-xs text-[#526b92]">
                                        Sin incidencias.
                                    </p>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Checklist de cierre
                                </h3>
                                {detail.closing?.blockers.map((blocker) => (
                                    <p key={blocker.label} className="text-xs">
                                        <span
                                            className={badge(
                                                blocker.status === 'blocked'
                                                    ? 'red'
                                                    : 'amber',
                                            )}
                                        >
                                            {blocker.status === 'blocked'
                                                ? 'Bloquea'
                                                : 'Advertencia'}
                                        </span>{' '}
                                        {blocker.label}: {blocker.message}
                                    </p>
                                ))}
                                {(detail.closing?.blockers.length ?? 0) ===
                                    0 && (
                                    <p className="text-xs text-emerald-700">
                                        Sin bloqueos en el checklist.
                                    </p>
                                )}
                            </div>
                        </aside>
                    )}
                </div>
            </Shell>
            <NewTaskDialog
                open={taskOpen}
                onClose={() => setTaskOpen(false)}
                options={taskOptions}
                defaultCompanyId={detail?.id}
            />
            <TaskManageDialog
                task={managed}
                users={taskOptions.users}
                permissions={permissions}
                onClose={() => setManaged(null)}
            />
        </>
    );
}

function RequestChange({
    companyId,
    users,
}: {
    companyId: number;
    users: TaskOptions['users'];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        company_id: companyId,
        role: 'accounting_assistant',
        suggested_user_id: '',
        reason: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/operations/assignment-requests', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('reason');
                setOpen(false);
            },
        });
    };

    if (!open) {
        return (
            <button
                type="button"
                className={`${secondaryButton} mt-2`}
                onClick={() => setOpen(true)}
            >
                Solicitar cambio de responsable
            </button>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="mt-2 space-y-1.5 rounded-lg border border-slate-200 p-2"
        >
            <select
                className={input}
                value={form.data.role}
                onChange={(event) => form.setData('role', event.target.value)}
            >
                <option value="accountant_in_charge">Contador encargado</option>
                <option value="accounting_assistant">Auxiliar contable</option>
                <option value="tax_specialist">Encargado fiscal</option>
                <option value="treasury">Tesorería</option>
                <option value="payroll">Nómina</option>
            </select>
            <select
                className={input}
                value={form.data.suggested_user_id}
                onChange={(event) =>
                    form.setData('suggested_user_id', event.target.value)
                }
            >
                <option value="">Sin sugerencia</option>
                {users.map((user) => (
                    <option key={user.id} value={user.id}>
                        {user.name}
                    </option>
                ))}
            </select>
            <textarea
                className={`${input} h-16 py-2`}
                placeholder="Motivo"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            {form.errors.reason && (
                <p className="text-xs text-red-700">{form.errors.reason}</p>
            )}
            <button
                type="submit"
                className={primaryButton}
                disabled={form.processing}
            >
                Enviar al administrador
            </button>
        </form>
    );
}
