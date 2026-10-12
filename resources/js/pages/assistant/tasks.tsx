import { Head, router, usePage } from '@inertiajs/react';
import { priorityTone, taskAction, taskTone } from '@/components/assistant/kit';
import type {
    AssistantContext,
    AssistantPermissions,
    AssistantTask,
} from '@/components/assistant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function AssistantTasks({
    context,
    tasks,
    filters,
    permissions,
    notice,
}: {
    context: AssistantContext;
    tasks: AssistantTask[];
    filters: Record<string, string>;
    permissions: AssistantPermissions;
    notice: string | null;
}) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const apply = (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            '/assistant/tasks',
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, current]) => current !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Mis tareas" />
            <Shell>
                <Notice message={notice} />
                {Object.values(errors).length > 0 && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <PageHeader
                    title="Mis tareas"
                    subtitle="La tarea marca responsable, prioridad y vencimiento; el trabajo se hace en el módulo real con “Continuar”"
                />
                <Card
                    title={`${tasks.length} tarea(s)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Empresa"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    apply('company_id', event.target.value)
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
                                aria-label="Prioridad"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.priority ?? ''}
                                onChange={(event) =>
                                    apply('priority', event.target.value)
                                }
                            >
                                <option value="">Todas las prioridades</option>
                                <option value="urgent">Urgente</option>
                                <option value="high">Alta</option>
                                <option value="normal">Media</option>
                                <option value="low">Baja</option>
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
                            'Período',
                            'Módulo',
                            'Prioridad',
                            'Estado',
                            'Vencimiento',
                            'Revisor',
                            'Acción',
                        ]}
                        empty={
                            tasks.length === 0 && 'No tienes tareas pendientes.'
                        }
                    >
                        {tasks.map((task) => {
                            const [priority, tone] = priorityTone[
                                task.priority
                            ] ?? [task.priority, 'slate'];

                            return (
                                <tr key={task.id}>
                                    <td className="px-2 py-2">
                                        <a
                                            href={task.destination}
                                            className="font-bold hover:text-[#640000]"
                                        >
                                            {task.title}
                                        </a>
                                        <p className="text-[11px] text-[#526b92]">
                                            {task.source}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.company_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.period_name ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.module_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {priority}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                taskTone[task.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {task.status_label}
                                        </span>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${task.overdue_days > 0 ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(task.due_at)}
                                        {task.overdue_days > 0
                                            ? ` · vencida ${task.overdue_days} d`
                                            : task.remaining_days !== null &&
                                                task.remaining_days <= 3
                                              ? ` · ${task.remaining_days === 0 ? 'hoy' : `${task.remaining_days} d`}`
                                              : ''}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.reviewer_name ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {task.can_start &&
                                                permissions.canStartTasks && (
                                                    <button
                                                        type="button"
                                                        className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                                        onClick={() =>
                                                            taskAction(
                                                                task,
                                                                'start',
                                                            )
                                                        }
                                                    >
                                                        Iniciar
                                                    </button>
                                                )}
                                            {!task.can_start && (
                                                <a
                                                    href={task.continue_to}
                                                    className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                                >
                                                    Continuar
                                                </a>
                                            )}
                                            {task.can_submit &&
                                                permissions.canSubmitTasks && (
                                                    <button
                                                        type="button"
                                                        className={
                                                            secondaryButton
                                                        }
                                                        onClick={() =>
                                                            taskAction(
                                                                task,
                                                                'submit',
                                                            )
                                                        }
                                                    >
                                                        Enviar a revisión
                                                    </button>
                                                )}
                                        </div>
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
