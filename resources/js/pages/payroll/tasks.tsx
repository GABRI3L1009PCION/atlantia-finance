import { Head, router } from '@inertiajs/react';
import { priorityTone, taskTone } from '@/components/assistant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
} from '@/components/office-admin/kit';
import type {
    PayrollContext,
    PayrollPermissions,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    period_name: string | null;
    priority: string;
    status: string;
    status_label: string;
    due_at: string | null;
    overdue_days: number;
    source: string;
    continue_to: string;
    can_start: boolean;
};

export default function PayrollTasks({
    tasks,
    permissions,
    notice,
}: {
    context: PayrollContext;
    tasks: Task[];
    permissions: PayrollPermissions;
    notice: string | null;
}) {
    return (
        <>
            <Head title="Mis tareas de nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Mis tareas"
                    subtitle="Las tareas automáticas (novedades por revisar, recálculos, observaciones, aprobaciones, órdenes por enviar) se completan solas al resolver su origen"
                />
                <Card title={`${tasks.length} tarea(s)`}>
                    <Table
                        headers={[
                            'Tarea',
                            'Empresa',
                            'Prioridad',
                            'Estado',
                            'Vencimiento',
                            'Acción',
                        ]}
                        empty={
                            tasks.length === 0 &&
                            'No tienes tareas de nómina pendientes.'
                        }
                    >
                        {tasks.map((task) => {
                            const [priority, tone] = priorityTone[
                                task.priority
                            ] ?? [task.priority, 'slate'];

                            return (
                                <tr key={task.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {task.title}
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {task.source}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.company_name}
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
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.can_start &&
                                        permissions.canStartTasks ? (
                                            <button
                                                type="button"
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                                onClick={() =>
                                                    router.post(
                                                        `/payroll/tasks/${task.id}/start`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Iniciar
                                            </button>
                                        ) : (
                                            <a
                                                href={task.continue_to}
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                            >
                                                Continuar
                                            </a>
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
