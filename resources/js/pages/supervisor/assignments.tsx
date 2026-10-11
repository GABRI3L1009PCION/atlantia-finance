import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    primaryButton,
    priorityLabel,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import { AssignWorkDialog, Card, Table } from '@/components/supervisor/kit';
import type {
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    assignee_name: string | null;
    priority: string;
    due_at: string | null;
    status_label: string;
    destination: string;
};

export default function SupervisorAssignments({
    context,
    recent,
    workTypes,
    permissions,
    notice,
}: {
    context: SupervisorContext;
    recent: Task[];
    workTypes: Record<string, string>;
    permissions: SupervisorPermissions;
    notice: string | null;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Asignación de trabajo" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Asignación de trabajo"
                    subtitle="Asigna compras, ventas, contabilidad, bancos, conciliaciones, declaraciones o reportes a tu equipo"
                    actions={
                        permissions.canAssign && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setOpen(true)}
                            >
                                <Plus className="size-4" /> Asignar trabajo
                            </button>
                        )
                    }
                />
                <Card title="Trabajo asignado recientemente">
                    <Table
                        headers={[
                            'Trabajo',
                            'Empresa',
                            'Responsable',
                            'Prioridad',
                            'Fecha límite',
                            'Estado',
                        ]}
                        empty={
                            recent.length === 0 &&
                            'Aún no has asignado trabajo.'
                        }
                    >
                        {recent.map((task) => {
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
                                        {task.assignee_name ??
                                            'Sin responsable'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={badge(tone as Tone)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(task.due_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.status_label}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
            <AssignWorkDialog
                open={open}
                onClose={() => setOpen(false)}
                context={context}
                workTypes={workTypes}
            />
        </>
    );
}
