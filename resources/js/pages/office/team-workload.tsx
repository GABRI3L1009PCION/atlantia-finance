import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    CalendarDays,
    ClipboardList,
    CloudUpload,
    Gauge,
    GripVertical,
    Search,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { DragEvent } from 'react';
import {
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    panel,
    priorityLabel,
    scoped,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import type { OfficeScope } from '@/components/office-admin/kit';
import {
    PAGE_SIZES,
    TablePagination,
    useTableControls,
} from '@/components/table-controls';

type Load = {
    user_id: number;
    name: string;
    role_label?: string;
    assigned_companies: number;
    tasks: Record<string, number>;
    utilization_percentage: number;
    status: string;
};

type UnassignedTask = {
    id: number;
    title: string;
    company_name: string | null;
    period_name: string | null;
    priority: string;
    due_at: string | null;
    is_overdue: boolean;
    reason: string;
    candidates: { id: number; name: string }[];
};

type UserTask = {
    id: number;
    title: string;
    company_name: string | null;
    priority: string;
    status: string;
    due_at: string | null;
    is_overdue: boolean;
};

type Props = {
    officeScope: OfficeScope;
    team: {
        status: { active_users: number; high_workload: number | null };
        workload: Load[] | null;
        workload_error: string | null;
        unassigned_tasks: number;
    };
    unassignedTasks: UnassignedTask[];
    tasksByUser: Record<string, UserTask[]>;
    permissions: Record<string, boolean>;
    notice: string | null;
};

const loadSearchText = (load: Load): string =>
    [load.name, load.role_label].join(' ');

const initials = (name: string): string =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

const statusTone = (status: string): 'red' | 'amber' | 'green' =>
    status === 'Alta' ? 'red' : status === 'Media' ? 'amber' : 'green';

const barColor = (percentage: number): string =>
    percentage >= 85
        ? 'bg-red-500'
        : percentage >= 65
          ? 'bg-amber-500'
          : 'bg-emerald-500';

const priorityBar: Record<string, string> = {
    critical: 'border-l-red-600',
    high: 'border-l-red-400',
    medium: 'border-l-amber-400',
    low: 'border-l-slate-300',
};

const fieldClass =
    'h-10 w-full rounded-lg border border-slate-300 bg-white pr-2 pl-9 text-sm text-[#061633]';

export default function TeamWorkload({
    officeScope,
    team,
    unassignedTasks,
    tasksByUser,
    permissions,
    notice,
}: Props) {
    const officeQuery = useOfficeQuery();
    const scopeName =
        officeScope.offices.find((office) => office.id === officeScope.selected)
            ?.name ?? 'Todas las oficinas';
    const rows = useMemo(() => team.workload ?? [], [team.workload]);
    const [status, setStatus] = useState('');
    const [role, setRole] = useState('');
    const [dragging, setDragging] = useState<UnassignedTask | null>(null);
    const [over, setOver] = useState<number | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const canAssign = permissions.canReassignTasks;
    const roles = useMemo(
        () =>
            Array.from(
                new Set(rows.map((row) => row.role_label ?? '—')),
            ).sort(),
        [rows],
    );
    const predicate = useMemo(
        () => (row: Load) =>
            (status === '' || row.status === status) &&
            (role === '' || (row.role_label ?? '—') === role),
        [status, role],
    );
    const table = useTableControls(rows, {
        searchText: loadSearchText,
        predicate,
        initialPageSize: 5,
    });
    const overloaded = rows.filter((row) => row.status === 'Alta');
    const average = rows.length
        ? Math.round(
              rows.reduce((sum, row) => sum + row.utilization_percentage, 0) /
                  rows.length,
          )
        : 0;

    const assign = (task: UnassignedTask, collaborator: Load) => {
        setDragging(null);
        setOver(null);

        if (!task.candidates.some((c) => c.id === collaborator.user_id)) {
            setProblem(
                `${collaborator.name} no pertenece a la oficina de esta tarea.`,
            );

            return;
        }

        setProblem(null);
        router.post(
            `/office/tasks/${task.id}/assign`,
            { assigned_to: collaborator.user_id },
            { preserveScroll: true },
        );
    };

    const startDrag = (event: DragEvent, task: UnassignedTask) => {
        event.dataTransfer.setData('text/plain', String(task.id));
        event.dataTransfer.effectAllowed = 'move';
        setDragging(task);
    };

    return (
        <>
            <Head title="Carga de trabajo" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Carga de trabajo"
                    subtitle={`Quién tiene demasiado y quién puede recibir más · ${scopeName}`}
                />

                <section className="grid gap-3 rounded-xl border border-slate-200 bg-white p-3 md:grid-cols-[1fr_1.2fr_1fr_auto]">
                    <label className="block text-xs font-semibold text-[#526b92]">
                        Área
                        <span className="relative mt-1 block">
                            <Building2 className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#640000]" />
                            <select
                                value={role}
                                onChange={(event) =>
                                    setRole(event.target.value)
                                }
                                className={`${fieldClass} font-bold`}
                            >
                                <option value="">Todas las áreas</option>
                                {roles.map((value) => (
                                    <option key={value} value={value}>
                                        {value}
                                    </option>
                                ))}
                            </select>
                        </span>
                    </label>
                    <label className="block text-xs font-semibold text-[#526b92]">
                        Buscar colaborador
                        <span className="relative mt-1 block">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                type="search"
                                value={table.search}
                                onChange={(event) =>
                                    table.setSearch(event.target.value)
                                }
                                placeholder="Buscar por nombre o rol…"
                                className={`${fieldClass} font-normal`}
                            />
                        </span>
                    </label>
                    <label className="block text-xs font-semibold text-[#526b92]">
                        Estado de carga
                        <span className="relative mt-1 block">
                            <Activity className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#640000]" />
                            <select
                                value={status}
                                onChange={(event) =>
                                    setStatus(event.target.value)
                                }
                                className={fieldClass}
                            >
                                <option value="">Todos los estados</option>
                                <option value="Alta">Alta</option>
                                <option value="Media">Media</option>
                                <option value="Normal">Disponible</option>
                            </select>
                        </span>
                    </label>
                    <label className="block text-xs font-semibold text-[#526b92]">
                        Ver
                        <select
                            value={table.pageSize}
                            onChange={(event) =>
                                table.setPageSize(Number(event.target.value))
                            }
                            className="mt-1 block h-10 rounded-lg border border-slate-300 bg-white px-2 text-sm text-[#061633]"
                        >
                            {PAGE_SIZES.map((size) => (
                                <option key={size} value={size}>
                                    {size}
                                </option>
                            ))}
                        </select>
                    </label>
                </section>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-xl bg-blue-50 p-3 text-blue-700">
                        <Users className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {team.status.active_users}
                        </p>
                        <p className="text-xs font-semibold">
                            Usuarios activos
                        </p>
                    </div>
                    <div className="rounded-xl bg-red-50 p-3 text-red-700">
                        <AlertTriangle className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {overloaded.length}
                        </p>
                        <p className="text-xs font-semibold">Con alta carga</p>
                    </div>
                    <div className="rounded-xl bg-emerald-50 p-3 text-emerald-700">
                        <Gauge className="size-5" />
                        <p className="mt-2 text-2xl font-black">{average}%</p>
                        <p className="text-xs font-semibold">
                            Carga promedio del equipo
                        </p>
                    </div>
                    <div className="rounded-xl bg-amber-50 p-3 text-amber-700">
                        <ClipboardList className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {unassignedTasks.length}
                        </p>
                        <p className="text-xs font-semibold">
                            Sin asignar · repartir
                        </p>
                    </div>
                </section>

                {problem && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">
                        {problem}
                    </p>
                )}

                <section className={`${panel} p-3`}>
                    <SectionHeader
                        title={`Tareas sin asignar (${unassignedTasks.length})`}
                    />
                    <p className="-mt-1 mb-3 text-xs text-[#526b92]">
                        {canAssign
                            ? 'Arrastra una tarea hacia la columna de un colaborador, o elige uno en la lista de la tarjeta.'
                            : 'Tareas abiertas sin un responsable activo.'}
                    </p>
                    <ul className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                        {unassignedTasks.map((task) => {
                            const [priorityText, tone] = priorityLabel[
                                task.priority
                            ] ?? [task.priority, 'slate'];

                            return (
                                <li
                                    key={task.id}
                                    draggable={canAssign}
                                    onDragStart={(event) =>
                                        startDrag(event, task)
                                    }
                                    onDragEnd={() => {
                                        setDragging(null);
                                        setOver(null);
                                    }}
                                    className={`rounded-xl border border-slate-200 bg-white p-3 shadow-sm ${canAssign ? 'cursor-grab active:cursor-grabbing' : ''} ${dragging?.id === task.id ? 'opacity-40' : ''}`}
                                >
                                    <div className="flex items-start gap-2">
                                        {canAssign && (
                                            <GripVertical className="mt-0.5 size-4 shrink-0 text-slate-400" />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="text-sm font-bold text-[#061633]">
                                                    {task.title}
                                                </p>
                                                <span className={badge(tone)}>
                                                    {priorityText}
                                                </span>
                                            </div>
                                            <p className="text-xs text-[#526b92]">
                                                {task.company_name ??
                                                    'Sin empresa'}
                                                {task.period_name &&
                                                    ` · ${task.period_name}`}
                                            </p>
                                            <p
                                                className={`mt-1 flex items-center gap-1 text-xs ${task.is_overdue ? 'font-bold text-red-700' : 'text-[#526b92]'}`}
                                            >
                                                <CalendarDays className="size-3.5" />
                                                {formatDate(task.due_at)}
                                            </p>
                                            {canAssign && (
                                                <select
                                                    aria-label={`Asignar «${task.title}»`}
                                                    className="mt-2 h-8 w-full rounded-lg border border-slate-300 px-2 text-xs"
                                                    value=""
                                                    onChange={(event) => {
                                                        const target =
                                                            rows.find(
                                                                (row) =>
                                                                    row.user_id ===
                                                                    Number(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    ),
                                                            );

                                                        if (target) {
                                                            assign(
                                                                task,
                                                                target,
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <option value="">
                                                        Asignar a…
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
                                                                {candidate.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                        {unassignedTasks.length === 0 && (
                            <li className="rounded-xl bg-emerald-50 p-4 text-center text-sm font-semibold text-emerald-800 md:col-span-full">
                                Todas las tareas abiertas tienen un responsable
                                activo.
                            </li>
                        )}
                    </ul>
                </section>

                <section className={`${panel} min-w-0 overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            title={`Colaboradores (${table.count}${table.count !== table.total ? ` de ${table.total}` : ''})`}
                        />
                        <p className="-mt-1 mb-3 text-xs text-[#526b92]">
                            Carga alta: 85 % o más de uso, o con tareas
                            vencidas. Media: desde 65 %.
                        </p>
                    </div>

                    {team.workload === null ? (
                        <p className="m-3 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                            {team.workload_error ??
                                'Carga temporalmente no disponible.'}
                        </p>
                    ) : (
                        <div className="grid items-start gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                            {table.pageRows.map((row) => {
                                const tasks = tasksByUser[row.user_id] ?? [];
                                const isTarget =
                                    dragging !== null && over === row.user_id;
                                const eligible =
                                    dragging?.candidates.some(
                                        (c) => c.id === row.user_id,
                                    ) ?? false;

                                return (
                                    <article
                                        key={row.user_id}
                                        onDragOver={(event) => {
                                            if (dragging) {
                                                event.preventDefault();
                                                setOver(row.user_id);
                                            }
                                        }}
                                        onDragLeave={() =>
                                            setOver((current) =>
                                                current === row.user_id
                                                    ? null
                                                    : current,
                                            )
                                        }
                                        onDrop={(event) => {
                                            event.preventDefault();

                                            if (dragging) {
                                                assign(dragging, row);
                                            }
                                        }}
                                        className={`flex min-w-0 flex-col rounded-xl border bg-white p-3 transition ${
                                            isTarget
                                                ? eligible
                                                    ? 'border-2 border-dashed border-[#640000] bg-red-50/60'
                                                    : 'border-2 border-dashed border-slate-400 bg-slate-50'
                                                : 'border-slate-200'
                                        } ${dragging && !eligible ? 'opacity-60' : ''}`}
                                    >
                                        <div className="flex items-start gap-2">
                                            <span
                                                className={`grid size-9 shrink-0 place-items-center rounded-full text-xs font-black ${
                                                    row.status === 'Alta'
                                                        ? 'bg-red-100 text-red-700'
                                                        : row.status === 'Media'
                                                          ? 'bg-amber-100 text-amber-700'
                                                          : 'bg-emerald-100 text-emerald-700'
                                                }`}
                                            >
                                                {initials(row.name)}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <Link
                                                    href={scoped(
                                                        `/office/users?user=${row.user_id}`,
                                                        officeQuery,
                                                    )}
                                                    className="block truncate text-sm font-black text-[#061633] hover:text-[#640000]"
                                                    title={row.name}
                                                >
                                                    {row.name}
                                                </Link>
                                                <p
                                                    className="truncate text-xs text-[#526b92]"
                                                    title={row.role_label}
                                                >
                                                    {row.role_label}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="mt-2 flex items-center justify-between">
                                            <span
                                                className={badge(
                                                    statusTone(row.status),
                                                )}
                                            >
                                                {row.status === 'Normal'
                                                    ? 'Disponible'
                                                    : row.status}
                                            </span>
                                            <span className="text-xs font-black">
                                                {row.utilization_percentage}%
                                            </span>
                                        </div>
                                        <div className="mt-1 h-2 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className={`h-full ${barColor(row.utilization_percentage)}`}
                                                style={{
                                                    width: `${Math.min(row.utilization_percentage, 100)}%`,
                                                }}
                                            />
                                        </div>

                                        <p className="mt-3 flex items-center justify-between border-b border-slate-100 pb-1 text-xs font-bold text-[#061633]">
                                            <span>Tareas</span>
                                            <span className="rounded-full bg-slate-100 px-2 py-0.5">
                                                {tasks.length}
                                                {(row.tasks.overdue ?? 0) >
                                                    0 && (
                                                    <span className="text-red-600">
                                                        {' '}
                                                        · {
                                                            row.tasks.overdue
                                                        }{' '}
                                                        vencidas
                                                    </span>
                                                )}
                                            </span>
                                        </p>

                                        {isTarget && (
                                            <p
                                                className={`mt-2 flex items-center justify-center gap-2 rounded-lg border border-dashed py-2 text-xs font-bold ${eligible ? 'border-[#640000] text-[#640000]' : 'border-slate-400 text-slate-600'}`}
                                            >
                                                {eligible ? (
                                                    <>
                                                        <CloudUpload className="size-4" />{' '}
                                                        Soltar aquí para asignar
                                                    </>
                                                ) : (
                                                    'No pertenece a la oficina de la tarea'
                                                )}
                                            </p>
                                        )}

                                        <ul className="mt-2 max-h-96 space-y-1.5 overflow-y-auto pr-0.5">
                                            {tasks.map((task) => (
                                                <li key={task.id}>
                                                    <Link
                                                        href={`/work/tasks/${task.id}`}
                                                        className={`block rounded-lg border border-l-4 border-slate-200 bg-slate-50 px-2 py-1.5 transition hover:bg-amber-50 ${priorityBar[task.priority] ?? 'border-l-slate-300'}`}
                                                    >
                                                        <span className="line-clamp-2 text-xs font-bold text-[#061633]">
                                                            {task.title}
                                                        </span>
                                                        <span className="block truncate text-[11px] text-[#526b92]">
                                                            {task.company_name ??
                                                                'Sin empresa'}
                                                        </span>
                                                        <span
                                                            className={`flex items-center gap-1 text-[11px] ${task.is_overdue ? 'font-bold text-red-700' : 'text-[#526b92]'}`}
                                                        >
                                                            <CalendarDays className="size-3" />
                                                            {formatDate(
                                                                task.due_at,
                                                            )}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                            {tasks.length === 0 && (
                                                <li className="rounded-lg bg-emerald-50 px-2 py-3 text-center text-xs font-semibold text-emerald-800">
                                                    Sin tareas abiertas
                                                </li>
                                            )}
                                        </ul>
                                    </article>
                                );
                            })}
                            {table.pageRows.length === 0 && (
                                <p className="col-span-full py-8 text-center text-sm text-[#526b92]">
                                    {table.total === 0
                                        ? 'No existen usuarios activos en esta oficina.'
                                        : 'No hay colaboradores que coincidan con la búsqueda o los filtros.'}
                                </p>
                            )}
                        </div>
                    )}
                    <TablePagination
                        page={table.page}
                        pages={table.pages}
                        pageSize={table.pageSize}
                        count={table.count}
                        onPage={table.setPage}
                        noun="colaboradores"
                    />
                </section>
            </Shell>
        </>
    );
}
