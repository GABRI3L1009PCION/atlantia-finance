import { Head } from '@inertiajs/react';
import { priorityTone, taskTone } from '@/components/assistant/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    priority: string;
    status: string;
    status_label: string;
    due_at: string | null;
    overdue_days: number;
    continue_to: string;
};

export default function AuditorTasks({
    tasks,
}: {
    context: AuditorContext;
    tasks: Task[];
}) {
    return (
        <>
            <Head title="Mis tareas de auditoría" />
            <Shell>
                <PageHeader
                    title="Mis tareas"
                    subtitle="Planificar, ejecutar procedimientos, revisar evidencia y verificar correcciones; «Continuar» abre el origen real"
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
                            'No tienes tareas de auditoría pendientes.'
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
                                        <a
                                            href={task.continue_to}
                                            className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                        >
                                            Continuar
                                        </a>
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
