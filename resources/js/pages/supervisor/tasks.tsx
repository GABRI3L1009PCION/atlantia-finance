import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    priorityLabel,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import { Card, Table, post } from '@/components/supervisor/kit';
import type {
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    assignee_name: string | null;
    assigned_to: number | null;
    priority: string;
    due_at: string | null;
    status_label: string;
    overdue_days: number;
    destination: string;
    module_destination: string | null;
};

export default function SupervisorTasks({
    context,
    tasks,
    filters,
    permissions,
    notice,
}: {
    context: SupervisorContext;
    tasks: Task[];
    filters: Record<string, string>;
    permissions: SupervisorPermissions;
    notice: string | null;
}) {
    const page = usePage();
    const [target, setTarget] = useState<Record<number, string>>({});
    const apply = (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            '/supervisor/tasks',
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, v]) => v !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Tareas pendientes" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Tareas pendientes"
                    subtitle="Trabajo abierto en tus empresas, con responsable, prioridad y fecha límite"
                />
                <Card
                    title={`${tasks.length} tarea(s)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Responsable"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.assigned_to ?? ''}
                                onChange={(event) =>
                                    apply('assigned_to', event.target.value)
                                }
                            >
                                <option value="">Todos los responsables</option>
                                {context.team.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Prioridad"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.priority ?? ''}
                                onChange={(event) =>
                                    apply('priority', event.target.value)
                                }
                            >
                                <option value="">Todas las prioridades</option>
                                <option value="low">Baja</option>
                                <option value="normal">Media</option>
                                <option value="high">Alta</option>
                                <option value="urgent">Urgente</option>
                            </select>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    apply('overdue', filters.overdue ? '' : '1')
                                }
                            >
                                {filters.overdue ? 'Todas' : 'Solo vencidas'}
                            </button>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Tarea',
                            'Empresa',
                            'Responsable',
                            'Prioridad',
                            'Fecha límite',
                            'Estado',
                            'Redistribuir',
                        ]}
                        empty={tasks.length === 0 && 'Sin tareas pendientes.'}
                    >
                        {tasks.map((task) => {
                            const [label, tone] = priorityLabel[
                                task.priority
                            ] ?? [task.priority, 'slate'];

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
                                        {task.company_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.assignee_name ?? (
                                            <span className="text-amber-700">
                                                Sin responsable
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={badge(tone as Tone)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${task.overdue_days > 0 ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(task.due_at)}
                                        {task.overdue_days > 0
                                            ? ` · ${task.overdue_days} día(s) vencida`
                                            : ''}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.status_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {permissions.canReassign && (
                                            <div className="flex gap-1">
                                                <select
                                                    aria-label="Nuevo responsable"
                                                    className="h-8 rounded-lg border border-slate-200 px-1 text-xs"
                                                    value={
                                                        target[task.id] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setTarget({
                                                            ...target,
                                                            [task.id]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        Elegir
                                                    </option>
                                                    {context.team
                                                        .filter(
                                                            (member) =>
                                                                member.id !==
                                                                task.assigned_to,
                                                        )
                                                        .map((member) => (
                                                            <option
                                                                key={member.id}
                                                                value={
                                                                    member.id
                                                                }
                                                            >
                                                                {member.name} (
                                                                {
                                                                    member.utilization
                                                                }
                                                                %)
                                                            </option>
                                                        ))}
                                                </select>
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    disabled={!target[task.id]}
                                                    onClick={() =>
                                                        post(
                                                            `/supervisor/tasks/${task.id}/reassign`,
                                                            {
                                                                assigned_to:
                                                                    target[
                                                                        task.id
                                                                    ],
                                                                reason: 'Redistribución por supervisión',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Mover
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
