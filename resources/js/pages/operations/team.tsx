import { Head, Link, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    panel,
    secondaryButton,
} from '@/components/office-admin/kit';
import { dueLabel, useScopedHref } from '@/components/operations/kit';
import type { Context, TaskRow, TeamUser } from '@/components/operations/kit';

type Member = {
    user_id: number;
    name: string;
    role_label: string;
    assigned_companies: number;
    tasks: Record<string, number>;
    utilization_percentage: number;
    status: string;
    pending_reviews: number;
};

type Detail = Member & {
    tasks: TaskRow[];
    companies: string[];
    reviews: {
        id: number;
        company_name: string;
        status_label: string;
        destination: string;
    }[];
};

const openCount = (tasks: Record<string, number>) =>
    (tasks.pending ?? 0) +
    (tasks.in_progress ?? 0) +
    (tasks.ready_for_review ?? 0) +
    (tasks.in_review ?? 0) +
    (tasks.changes_requested ?? 0) +
    (tasks.blocked ?? 0);

export default function OperationsTeam({
    team,
    member,
    assignable,
    permissions,
    notice,
}: {
    context: Context;
    team: Member[];
    member: Detail | null;
    assignable: TeamUser[];
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const scoped = useScopedHref();
    const [selected, setSelected] = useState<number[]>([]);
    const [target, setTarget] = useState('');
    const moveTasks = () => {
        selected.forEach((taskId) =>
            router.post(
                `/operations/tasks/${taskId}/reassign`,
                {
                    assigned_to: target,
                    reason: `Redistribución de carga desde ${member?.name}`,
                },
                { preserveScroll: true },
            ),
        );
        setSelected([]);
    };

    return (
        <>
            <Head title="Mi equipo" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Mi equipo"
                    subtitle="Utilización estimada según tareas abiertas, vencidas y revisiones; no es una medida de desempeño"
                />
                <div
                    className={`grid gap-3 ${member ? 'xl:grid-cols-[minmax(0,1fr)_440px]' : ''}`}
                >
                    <section
                        id="carga"
                        className={`${panel} min-w-0 scroll-mt-24 p-3`}
                    >
                        <SectionHeader title="Carga de trabajo" />
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-2 py-2">
                                            Colaborador
                                        </th>
                                        <th className="px-2 py-2">Rol</th>
                                        <th className="px-2 py-2 text-right">
                                            Empresas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Tareas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Vencidas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Revisiones
                                        </th>
                                        <th className="px-2 py-2">Carga</th>
                                        <th className="px-2 py-2">Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {team.map((row) => (
                                        <tr
                                            key={row.user_id}
                                            className={
                                                member?.user_id === row.user_id
                                                    ? 'bg-amber-50/60'
                                                    : ''
                                            }
                                        >
                                            <td className="px-2 py-2 font-bold">
                                                <Link
                                                    href={scoped(
                                                        `/operations/team?user=${row.user_id}`,
                                                    )}
                                                    preserveScroll
                                                    className="hover:text-[#640000]"
                                                >
                                                    {row.name}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2 text-[#395277]">
                                                {row.role_label}
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {row.assigned_companies}
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {openCount(row.tasks)}
                                            </td>
                                            <td
                                                className={`px-2 py-2 text-right font-black ${(row.tasks.overdue ?? 0) > 0 ? 'text-red-700' : ''}`}
                                            >
                                                {row.tasks.overdue ?? 0}
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {row.pending_reviews}
                                            </td>
                                            <td className="px-2 py-2">
                                                <div className="flex items-center gap-1.5">
                                                    <div className="h-2 w-20 overflow-hidden rounded bg-slate-100">
                                                        <div
                                                            className={`h-full ${row.utilization_percentage >= 85 ? 'bg-red-500' : row.utilization_percentage >= 65 ? 'bg-amber-400' : 'bg-emerald-500'}`}
                                                            style={{
                                                                width: `${row.utilization_percentage}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="font-black">
                                                        {
                                                            row.utilization_percentage
                                                        }
                                                        %
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        row.status === 'Alta'
                                                            ? 'red'
                                                            : row.status ===
                                                                'Media'
                                                              ? 'amber'
                                                              : 'green',
                                                    )}
                                                >
                                                    {row.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                    {team.length === 0 && (
                                        <EmptyRow
                                            colSpan={8}
                                            text="No hay colaboradores activos en la oficina."
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {member && (
                        <aside className={`${panel} space-y-3 p-4`}>
                            <div className="flex items-start justify-between">
                                <div>
                                    <h2 className="text-xl font-black">
                                        {member.name}
                                    </h2>
                                    <p className="text-sm text-[#526b92]">
                                        {member.role_label} · carga{' '}
                                        {member.utilization_percentage}%
                                    </p>
                                </div>
                                <Link
                                    href={scoped('/operations/team')}
                                    className="text-[#526b92]"
                                >
                                    <X className="size-5" />
                                </Link>
                            </div>
                            <div>
                                <h3 className="text-sm font-black">
                                    Empresas asignadas
                                </h3>
                                <p className="text-xs text-[#395277]">
                                    {member.companies.join(', ') ||
                                        'Sin empresas asignadas.'}
                                </p>
                            </div>
                            <div>
                                <h3 className="text-sm font-black">
                                    Revisiones a su cargo
                                </h3>
                                {member.reviews.map((review) => (
                                    <p key={review.id} className="text-xs">
                                        <Link
                                            href={review.destination}
                                            className="hover:text-[#640000]"
                                        >
                                            {review.company_name}
                                        </Link>{' '}
                                        · {review.status_label}
                                    </p>
                                ))}
                                {member.reviews.length === 0 && (
                                    <p className="text-xs text-[#526b92]">
                                        Sin revisiones pendientes.
                                    </p>
                                )}
                            </div>
                            <div>
                                <h3 className="mb-1 text-sm font-black">
                                    Tareas abiertas ({member.tasks.length})
                                </h3>
                                <ul className="max-h-72 space-y-1 overflow-y-auto">
                                    {member.tasks.map((task) => {
                                        const [label, tone] = dueLabel(task);

                                        return (
                                            <li
                                                key={task.id}
                                                className="flex items-center gap-2 text-xs"
                                            >
                                                {permissions.canReassignTasks && (
                                                    <input
                                                        type="checkbox"
                                                        aria-label="Seleccionar"
                                                        checked={selected.includes(
                                                            task.id,
                                                        )}
                                                        onChange={(event) =>
                                                            setSelected(
                                                                event.target
                                                                    .checked
                                                                    ? [
                                                                          ...selected,
                                                                          task.id,
                                                                      ]
                                                                    : selected.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              task.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                )}
                                                <a
                                                    href={task.destination}
                                                    className="min-w-0 flex-1 truncate hover:text-[#640000]"
                                                >
                                                    {task.title}{' '}
                                                    <span className="text-[#526b92]">
                                                        ·{' '}
                                                        {task.company_name ??
                                                            '—'}{' '}
                                                        ·{' '}
                                                        {formatDate(
                                                            task.due_at,
                                                        )}
                                                    </span>
                                                </a>
                                                <span className={badge(tone)}>
                                                    {label}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                            {permissions.canReassignTasks &&
                                selected.length > 0 && (
                                    <div className="flex gap-2 rounded-lg border border-slate-200 p-2">
                                        <select
                                            aria-label="Nuevo responsable"
                                            className="h-9 flex-1 rounded-lg border border-slate-300 px-2 text-xs"
                                            value={target}
                                            onChange={(event) =>
                                                setTarget(event.target.value)
                                            }
                                        >
                                            <option value="">
                                                Mover {selected.length} tarea(s)
                                                a...
                                            </option>
                                            {assignable
                                                .filter(
                                                    (user) =>
                                                        user.id !==
                                                        member.user_id,
                                                )
                                                .map((user) => (
                                                    <option
                                                        key={user.id}
                                                        value={user.id}
                                                    >
                                                        {user.name} ·{' '}
                                                        {user.open_tasks}{' '}
                                                        abiertas
                                                    </option>
                                                ))}
                                        </select>
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            disabled={!target}
                                            onClick={moveTasks}
                                        >
                                            Redistribuir
                                        </button>
                                    </div>
                                )}
                        </aside>
                    )}
                </div>
            </Shell>
        </>
    );
}
