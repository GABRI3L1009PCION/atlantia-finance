import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    primaryButton,
    priorityLabel,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import {
    NewTaskDialog,
    TaskManageDialog,
    dueLabel,
    taskStatusTone,
} from '@/components/operations/kit';
import type {
    Context,
    TaskOptions,
    TaskRow,
} from '@/components/operations/kit';

const statusFilters = [
    ['open', 'Abiertas'],
    ['pending', 'Pendientes'],
    ['in_progress', 'En proceso'],
    ['ready_for_review', 'Para revisión'],
    ['changes_requested', 'Con cambios'],
    ['blocked', 'Bloqueadas'],
    ['completed', 'Completadas'],
] as const;

export default function OperationsTasks({
    tasks,
    filters,
    taskOptions,
    permissions,
    notice,
}: {
    context: Context;
    tasks: TaskRow[];
    filters: Record<string, string>;
    taskOptions: TaskOptions;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const page = usePage();
    const [taskOpen, setTaskOpen] = useState(false);
    const [managed, setManaged] = useState<TaskRow | null>(null);
    const path = page.url.split('?')[0];
    const apply = (next: Record<string, string>) => {
        const params = Object.fromEntries(
            Object.entries({
                ...Object.fromEntries(
                    new URL(page.url, 'https://atlantia.local').searchParams,
                ),
                ...next,
            }).filter(([, value]) => value !== ''),
        );
        router.get(path, params, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Tareas" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Tareas"
                    subtitle="Distribución, prioridades y vencimientos del trabajo operativo"
                    actions={
                        permissions.canCreateTasks && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setTaskOpen(true)}
                            >
                                <Plus className="size-4" /> Nueva tarea
                            </button>
                        )
                    }
                />
                <section className={`${panel} space-y-3 p-3`}>
                    <div className="flex flex-wrap items-center gap-2">
                        {statusFilters.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => apply({ status: key })}
                                className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${(filters.status ?? 'open') === key ? 'bg-[#640000] text-white ring-[#640000]' : 'bg-white text-[#395277] ring-slate-200'}`}
                            >
                                {label}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() =>
                                apply({ overdue: filters.overdue ? '' : '1' })
                            }
                            className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${filters.overdue ? 'bg-red-700 text-white ring-red-700' : 'bg-white text-red-700 ring-red-200'}`}
                        >
                            Solo vencidas
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                apply({
                                    unassigned: filters.unassigned ? '' : '1',
                                })
                            }
                            className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${filters.unassigned ? 'bg-amber-600 text-white ring-amber-600' : 'bg-white text-amber-700 ring-amber-200'}`}
                        >
                            Sin responsable
                        </button>
                        <select
                            aria-label="Empresa"
                            className="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                            value={filters.company_id ?? ''}
                            onChange={(event) =>
                                apply({ company_id: event.target.value })
                            }
                        >
                            <option value="">Todas las empresas</option>
                            {taskOptions.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Responsable"
                            className="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                            value={filters.assigned_to ?? ''}
                            onChange={(event) =>
                                apply({ assigned_to: event.target.value })
                            }
                        >
                            <option value="">Todos los responsables</option>
                            {taskOptions.users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Módulo"
                            className="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                            value={filters.module ?? ''}
                            onChange={(event) =>
                                apply({ module: event.target.value })
                            }
                        >
                            <option value="">Todos los módulos</option>
                            {taskOptions.modules.map((module) => (
                                <option key={module.code} value={module.code}>
                                    {module.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Tarea</th>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Período</th>
                                    <th className="px-2 py-2">Módulo</th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2">Revisor</th>
                                    <th className="px-2 py-2">Prioridad</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2">Vencimiento</th>
                                    <th className="px-2 py-2">Origen</th>
                                    <th className="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {tasks.map((task) => {
                                    const [priority, priorityTone] =
                                        priorityLabel[task.priority] ?? [
                                            task.priority,
                                            'slate',
                                        ];
                                    const [due, dueTone] = dueLabel(task);

                                    return (
                                        <tr key={task.id}>
                                            <td className="px-2 py-2 font-bold">
                                                <a
                                                    href={task.destination}
                                                    className="hover:text-[#640000]"
                                                >
                                                    {task.title}
                                                </a>
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.company_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.period_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.module_label}
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.assignee_name ?? (
                                                    <span className="font-bold text-amber-700">
                                                        Sin asignar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.reviewer_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        priorityTone as Tone,
                                                    )}
                                                >
                                                    {priority}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        taskStatusTone[
                                                            task.status
                                                        ] ?? 'slate',
                                                    )}
                                                >
                                                    {task.status_label}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2">
                                                {formatDate(task.due_at)}{' '}
                                                <span
                                                    className={badge(dueTone)}
                                                >
                                                    {due}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2 text-[#526b92]">
                                                {task.source}
                                            </td>
                                            <td className="px-2 py-2">
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        setManaged(task)
                                                    }
                                                >
                                                    Gestionar
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {tasks.length === 0 && (
                                    <EmptyRow
                                        colSpan={11}
                                        text="No hay tareas con estos filtros."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </Shell>
            <NewTaskDialog
                open={taskOpen}
                onClose={() => setTaskOpen(false)}
                options={taskOptions}
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
