import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Eye,
    ShieldAlert,
    UserCog,
    UserPlus,
    UserRoundX,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    EmptyRow,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    primaryButton,
    relativeTime,
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

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const memberSearchText = (member: Member): string =>
    [
        member.name,
        member.email,
        member.roles.join(' '),
        member.offices.join(' '),
    ].join(' ');

type Member = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    offices: string[];
    assigned_companies: number;
    status: 'active' | 'inactive';
    two_factor: boolean;
    last_access: string | null;
    utilization_percentage: number | null;
    load_status: string | null;
    open_tasks: number | null;
    overdue_tasks: number | null;
};

type Props = {
    officeScope: OfficeScope;
    team: {
        status: {
            active_users: number;
            high_workload: number | null;
            without_company: number;
            pending_2fa: number;
            inactive: number;
        };
        members: Member[];
    };
    permissions: Record<string, boolean>;
};

type Filter = 'all' | 'high' | 'nocompany' | 'no2fa' | 'inactive';

export default function Team({ officeScope, team, permissions }: Props) {
    const officeQuery = useOfficeQuery();
    const [filter, setFilter] = useState<Filter>('all');
    const [role, setRole] = useState('');
    const [state, setState] = useState('');
    const scopeName =
        officeScope.offices.find((office) => office.id === officeScope.selected)
            ?.name ?? 'Todas las oficinas';

    const cards: {
        key: Filter;
        label: string;
        value: number | null;
        icon: LucideIcon;
        tone: string;
    }[] = [
        {
            key: 'all',
            label: 'Usuarios activos',
            value: team.status.active_users,
            icon: Users,
            tone: 'bg-blue-50 text-blue-700',
        },
        {
            key: 'high',
            label: 'Con alta carga',
            value: team.status.high_workload,
            icon: AlertTriangle,
            tone: 'bg-red-50 text-red-700',
        },
        {
            key: 'nocompany',
            label: 'Sin empresa',
            value: team.status.without_company,
            icon: UserPlus,
            tone: 'bg-amber-50 text-amber-700',
        },
        {
            key: 'no2fa',
            label: 'Con 2FA pendiente',
            value: team.status.pending_2fa,
            icon: ShieldAlert,
            tone: 'bg-blue-50 text-blue-700',
        },
        {
            key: 'inactive',
            label: 'Inactivos',
            value: team.status.inactive,
            icon: UserRoundX,
            tone: 'bg-red-50 text-red-700',
        },
    ];

    const roles = useMemo(
        () =>
            Array.from(
                new Set(team.members.flatMap((member) => member.roles)),
            ).sort(),
        [team.members],
    );
    const predicate = useMemo(
        () => (member: Member) =>
            (filter === 'all' ||
                (filter === 'high' && member.load_status === 'Alta') ||
                (filter === 'nocompany' &&
                    member.status === 'active' &&
                    member.assigned_companies === 0) ||
                (filter === 'no2fa' && !member.two_factor) ||
                (filter === 'inactive' && member.status === 'inactive')) &&
            (role === '' || member.roles.includes(role)) &&
            (state === '' || member.status === state),
        [filter, role, state],
    );
    const table = useTableControls(team.members, {
        searchText: memberSearchText,
        predicate,
    });
    const rows = table.pageRows;

    return (
        <>
            <Head title="Equipo" />
            <Shell>
                <PageHeader
                    title="Equipo"
                    subtitle={`Personas, responsabilidades y seguridad · ${scopeName}`}
                    actions={
                        <>
                            <Link
                                href={scoped(
                                    '/office/team-workload',
                                    officeQuery,
                                )}
                                className="inline-flex h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-bold"
                            >
                                Ver carga de trabajo
                            </Link>
                            {permissions.canInvite && (
                                <Link
                                    href={scoped('/office/users', officeQuery)}
                                    className={primaryButton}
                                >
                                    <UserPlus className="size-4" /> Invitar
                                    usuario
                                </Link>
                            )}
                        </>
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.map(({ key, label, value, icon: Icon, tone }) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() =>
                                setFilter(filter === key ? 'all' : key)
                            }
                            className={`rounded-xl p-3 text-left transition hover:brightness-95 ${tone} ${filter === key ? 'ring-2 ring-[#640000]' : ''}`}
                            aria-pressed={filter === key}
                        >
                            <Icon className="size-5" />
                            <p className="mt-2 text-2xl font-black">
                                {value ?? 'N/D'}
                            </p>
                            <p className="text-xs font-semibold">{label}</p>
                        </button>
                    ))}
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <h2 className="mb-3 text-lg font-black text-[#061633]">
                            Personas ({table.count}
                            {table.count !== table.total
                                ? ` de ${table.total}`
                                : ''}
                            )
                        </h2>
                    </div>
                    <TableToolbar
                        search={table.search}
                        onSearch={table.setSearch}
                        placeholder="Buscar por nombre, correo, rol u oficina"
                        pageSize={table.pageSize}
                        onPageSize={table.setPageSize}
                    >
                        {roles.length > 0 && (
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
                        <TableFilterSelect
                            label="Todos los estados"
                            value={state}
                            onChange={setState}
                            options={[
                                { value: 'active', label: 'Activos' },
                                { value: 'inactive', label: 'Inactivos' },
                            ]}
                        />
                    </TableToolbar>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Colaborador</th>
                                    <th className="px-2 py-2">Rol</th>
                                    <th className="px-2 py-2">Oficina</th>
                                    <th className="px-2 py-2 text-right">
                                        Empresas
                                    </th>
                                    <th className="px-2 py-2 text-right">
                                        Tareas abiertas
                                    </th>
                                    <th className="px-2 py-2">Carga</th>
                                    <th className="px-2 py-2">2FA</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2">Último acceso</th>
                                    <th className="px-2 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((member) => (
                                    <tr key={member.id}>
                                        <td className="px-2 py-2">
                                            <Link
                                                href={scoped(
                                                    `/office/users?user=${member.id}`,
                                                    officeQuery,
                                                )}
                                                className="font-bold text-[#061633] hover:text-[#640000]"
                                            >
                                                {member.name}
                                            </Link>
                                            <p className="text-[11px] text-[#526b92]">
                                                {member.email}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2">
                                            {member.roles.join(', ') || '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {member.offices.join(', ')}
                                        </td>
                                        <td className="px-2 py-2 text-right font-black">
                                            {member.assigned_companies}
                                        </td>
                                        <td className="px-2 py-2 text-right font-black">
                                            {member.open_tasks ?? '—'}
                                            {member.overdue_tasks ? (
                                                <span className="ml-1 font-semibold text-red-600">
                                                    ({member.overdue_tasks}{' '}
                                                    vencidas)
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="px-2 py-2">
                                            {member.utilization_percentage ===
                                            null ? (
                                                '—'
                                            ) : (
                                                <div className="flex items-center gap-1.5">
                                                    <span className="w-9 font-black">
                                                        {
                                                            member.utilization_percentage
                                                        }
                                                        %
                                                    </span>
                                                    <div className="h-2 w-14 overflow-hidden rounded bg-slate-100">
                                                        <div
                                                            className={`h-full ${member.utilization_percentage >= 85 ? 'bg-red-500' : member.utilization_percentage >= 65 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                                            style={{
                                                                width: `${Math.min(member.utilization_percentage, 100)}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={badge(
                                                    member.two_factor
                                                        ? 'green'
                                                        : 'amber',
                                                )}
                                            >
                                                {member.two_factor
                                                    ? 'Activo'
                                                    : 'Pendiente'}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={badge(
                                                    member.status === 'active'
                                                        ? 'green'
                                                        : 'slate',
                                                )}
                                            >
                                                {member.status === 'active'
                                                    ? 'Activo'
                                                    : 'Inactivo'}
                                            </span>
                                        </td>
                                        <td
                                            className="px-2 py-2"
                                            title={
                                                member.last_access
                                                    ? formatDate(
                                                          member.last_access,
                                                          true,
                                                      )
                                                    : undefined
                                            }
                                        >
                                            {member.last_access
                                                ? relativeTime(
                                                      member.last_access,
                                                  )
                                                : 'Sin sesión'}
                                        </td>
                                        <td className="px-2 py-2">
                                            <div className="flex items-center gap-1.5">
                                                <Link
                                                    href={scoped(
                                                        `/office/users?user=${member.id}`,
                                                        officeQuery,
                                                    )}
                                                    className={iconButton}
                                                    title="Ver colaborador"
                                                    aria-label={`Ver a ${member.name}`}
                                                >
                                                    <Eye className="size-4" />
                                                </Link>
                                                {permissions.canManageUsers && (
                                                    <Link
                                                        href={scoped(
                                                            `/office/users?user=${member.id}`,
                                                            officeQuery,
                                                        )}
                                                        className={iconButton}
                                                        title="Administrar rol y acceso"
                                                        aria-label={`Administrar a ${member.name}`}
                                                    >
                                                        <UserCog className="size-4" />
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <EmptyRow
                                        colSpan={10}
                                        text="No hay personas que coincidan con la búsqueda o los filtros."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        page={table.page}
                        pages={table.pages}
                        pageSize={table.pageSize}
                        count={table.count}
                        onPage={table.setPage}
                        noun="personas"
                    />
                </section>
            </Shell>
        </>
    );
}
