import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, Eye, Gauge, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    EmptyRow,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    panel,
    scoped,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import type { OfficeScope } from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
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

type Props = {
    officeScope: OfficeScope;
    team: {
        status: { active_users: number; high_workload: number | null };
        workload: Load[] | null;
        workload_error: string | null;
        unassigned_tasks: number;
    };
    permissions: Record<string, boolean>;
};

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const loadSearchText = (load: Load): string =>
    [load.name, load.role_label].join(' ');

const openTasks = (load: Load): number =>
    Object.entries(load.tasks)
        .filter(([key]) => !['overdue', 'completed_month'].includes(key))
        .reduce((sum, [, value]) => sum + value, 0);

export default function TeamWorkload({ officeScope, team }: Props) {
    const officeQuery = useOfficeQuery();
    const scopeName =
        officeScope.offices.find((office) => office.id === officeScope.selected)
            ?.name ?? 'Todas las oficinas';
    const rows = useMemo(() => team.workload ?? [], [team.workload]);
    const [status, setStatus] = useState('');
    const [role, setRole] = useState('');
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
    });
    const names = (list: Load[], limit = 3) =>
        list
            .slice(0, limit)
            .map((row) => row.name)
            .join(', ') +
        (list.length > limit ? ` y ${list.length - limit} más` : '');
    const overloaded = rows.filter((row) => row.status === 'Alta');
    const available = [...rows]
        .filter((row) => row.status !== 'Alta')
        .sort((a, b) => a.utilization_percentage - b.utilization_percentage)
        .slice(0, 3);
    const average = rows.length
        ? Math.round(
              rows.reduce((sum, row) => sum + row.utilization_percentage, 0) /
                  rows.length,
          )
        : 0;

    return (
        <>
            <Head title="Carga de trabajo" />
            <Shell>
                <PageHeader
                    title="Carga de trabajo"
                    subtitle={`Quién tiene demasiado y quién puede recibir más · ${scopeName}`}
                />

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
                    <Link
                        href={scoped('/office/unassigned-tasks', officeQuery)}
                        className="rounded-xl bg-amber-50 p-3 text-amber-700 transition hover:brightness-95"
                    >
                        <ClipboardList className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {team.unassigned_tasks}
                        </p>
                        <p className="text-xs font-semibold">
                            Tareas sin asignar · repartir
                        </p>
                    </Link>
                </section>

                {overloaded.length > 0 && available.length > 0 && (
                    <section className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <p className="font-black">Sugerencia de reparto</p>
                        <p>
                            {names(overloaded)}{' '}
                            {overloaded.length === 1 ? 'tiene' : 'tienen'} alta
                            carga. Con más capacidad:{' '}
                            {available
                                .map(
                                    (row) =>
                                        `${row.name} (${row.utilization_percentage}%)`,
                                )
                                .join(', ')}
                            .
                        </p>
                    </section>
                )}

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            title={`Carga por colaborador (${table.count}${table.count !== table.total ? ` de ${table.total}` : ''})`}
                        />
                        <p className="-mt-1 mb-3 text-xs text-[#526b92]">
                            Carga alta: 85 % o más de uso, o con tareas
                            vencidas. Media: desde 65 %.
                        </p>
                    </div>
                    <TableToolbar
                        search={table.search}
                        onSearch={table.setSearch}
                        placeholder="Buscar por colaborador o rol"
                        pageSize={table.pageSize}
                        onPageSize={table.setPageSize}
                    >
                        <TableFilterSelect
                            label="Toda carga"
                            value={status}
                            onChange={setStatus}
                            options={[
                                { value: 'Alta', label: 'Alta' },
                                { value: 'Media', label: 'Media' },
                                { value: 'Normal', label: 'Normal' },
                            ]}
                        />
                        {roles.length > 1 && (
                            <TableFilterSelect
                                label="Todos los roles"
                                value={role}
                                onChange={setRole}
                                options={roles.map((value) => ({
                                    value,
                                    label: value,
                                }))}
                            />
                        )}
                    </TableToolbar>
                    {team.workload === null ? (
                        <p className="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                            {team.workload_error ??
                                'Carga temporalmente no disponible.'}
                        </p>
                    ) : (
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
                                            Abiertas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Vencidas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Cerradas este mes
                                        </th>
                                        <th className="px-2 py-2">Carga</th>
                                        <th className="px-2 py-2">Estado</th>
                                        <th className="px-2 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {table.pageRows.map((row) => (
                                        <tr key={row.user_id}>
                                            <td className="px-2 py-2 font-bold">
                                                <Link
                                                    href={scoped(
                                                        `/office/users?user=${row.user_id}`,
                                                        officeQuery,
                                                    )}
                                                    className="hover:text-[#640000]"
                                                >
                                                    {row.name}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.role_label}
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {row.assigned_companies}
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {openTasks(row)}
                                            </td>
                                            <td
                                                className={`px-2 py-2 text-right font-black ${(row.tasks.overdue ?? 0) > 0 ? 'text-red-600' : ''}`}
                                            >
                                                {row.tasks.overdue ?? 0}
                                            </td>
                                            <td className="px-2 py-2 text-right">
                                                {row.tasks.completed_month ?? 0}
                                            </td>
                                            <td className="px-2 py-2">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="w-9 font-black">
                                                        {
                                                            row.utilization_percentage
                                                        }
                                                        %
                                                    </span>
                                                    <div className="h-2 w-20 overflow-hidden rounded bg-slate-100">
                                                        <div
                                                            className={`h-full ${row.utilization_percentage >= 85 ? 'bg-red-500' : row.utilization_percentage >= 65 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                                            style={{
                                                                width: `${Math.min(row.utilization_percentage, 100)}%`,
                                                            }}
                                                        />
                                                    </div>
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
                                            <td className="px-2 py-2">
                                                <div className="flex items-center gap-1.5">
                                                    <Link
                                                        href={scoped(
                                                            `/office/users?user=${row.user_id}`,
                                                            officeQuery,
                                                        )}
                                                        className={iconButton}
                                                        title="Ver colaborador"
                                                        aria-label={`Ver a ${row.name}`}
                                                    >
                                                        <Eye className="size-4" />
                                                    </Link>
                                                    <Link
                                                        href={scoped(
                                                            '/office/unassigned-tasks',
                                                            officeQuery,
                                                        )}
                                                        className={iconButton}
                                                        title="Repartir tareas sin asignar"
                                                        aria-label={`Repartir tareas sin asignar a ${row.name}`}
                                                    >
                                                        <ClipboardList className="size-4" />
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.pageRows.length === 0 && (
                                        <EmptyRow
                                            colSpan={9}
                                            text={
                                                table.total === 0
                                                    ? 'No existen usuarios activos en esta oficina.'
                                                    : 'No hay colaboradores que coincidan con la búsqueda o los filtros.'
                                            }
                                        />
                                    )}
                                </tbody>
                            </table>
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
