import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    priorityLabel,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    period_name: string | null;
    required_role: string;
    priority: string;
    due_at: string | null;
    is_overdue: boolean;
    reason: string;
    candidates: { id: number; name: string }[];
    href: string;
};

export default function UnassignedTasks({
    tasks,
    permissions,
    notice,
}: {
    tasks: Task[];
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const [choice, setChoice] = useState<Record<number, string>>({});

    return (
        <>
            <Head title="Tareas sin asignar" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Tareas sin asignar"
                    subtitle="Trabajo abierto sin responsable o con un responsable que ya no está activo en la oficina"
                />
                <section className={`${panel} p-3`}>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Tarea</th>
                                    <th className="px-2 py-2">
                                        Empresa / período
                                    </th>
                                    <th className="px-2 py-2">Rol requerido</th>
                                    <th className="px-2 py-2">Prioridad</th>
                                    <th className="px-2 py-2">Vencimiento</th>
                                    <th className="px-2 py-2">Motivo</th>
                                    <th className="px-2 py-2">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {tasks.map((task) => {
                                    const [label, tone] = priorityLabel[
                                        task.priority
                                    ] ?? [task.priority, 'slate'];

                                    return (
                                        <tr key={task.id}>
                                            <td className="px-2 py-2 font-bold">
                                                <Link
                                                    href={task.href}
                                                    className="hover:text-[#640000]"
                                                >
                                                    {task.title}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.company_name ??
                                                    'Sin empresa'}
                                                {task.period_name
                                                    ? ` · ${task.period_name}`
                                                    : ''}
                                            </td>
                                            <td className="px-2 py-2">
                                                {task.required_role}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        tone as Tone,
                                                    )}
                                                >
                                                    {label}
                                                </span>
                                            </td>
                                            <td
                                                className={`px-2 py-2 ${task.is_overdue ? 'font-bold text-red-700' : ''}`}
                                            >
                                                {formatDate(task.due_at)}
                                            </td>
                                            <td className="px-2 py-2 text-[#526b92]">
                                                {task.reason}
                                            </td>
                                            <td className="px-2 py-2">
                                                {permissions.canReassignTasks && (
                                                    <div className="flex gap-1.5">
                                                        <select
                                                            aria-label="Responsable"
                                                            className="h-9 rounded-lg border border-slate-300 px-2 text-xs"
                                                            value={
                                                                choice[
                                                                    task.id
                                                                ] ?? ''
                                                            }
                                                            onChange={(event) =>
                                                                setChoice({
                                                                    ...choice,
                                                                    [task.id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                        >
                                                            <option value="">
                                                                Elegir
                                                                colaborador
                                                            </option>
                                                            {task.candidates.map(
                                                                (candidate) => (
                                                                    <option
                                                                        key={
                                                                            candidate.id
                                                                        }
                                                                        value={
                                                                            candidate.id
                                                                        }
                                                                    >
                                                                        {
                                                                            candidate.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            disabled={
                                                                !choice[task.id]
                                                            }
                                                            onClick={() =>
                                                                router.post(
                                                                    `/office/tasks/${task.id}/assign`,
                                                                    {
                                                                        assigned_to:
                                                                            choice[
                                                                                task
                                                                                    .id
                                                                            ],
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Asignar
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {tasks.length === 0 && (
                                    <EmptyRow
                                        colSpan={7}
                                        text="Todas las tareas abiertas tienen un responsable activo."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </Shell>
        </>
    );
}
