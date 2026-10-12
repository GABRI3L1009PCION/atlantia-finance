import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Eye,
    LogOut,
    Pencil,
    RefreshCcw,
    ShieldCheck,
    ShieldOff,
    UserPlus,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import {
    EmptyRow,
    InviteDialog,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    input,
    panel,
    primaryButton,
    scoped,
    secondaryButton,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import type { FormOptions, OfficeScope } from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';

type UserRow = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    is_owner: boolean;
    offices: string[];
    assigned_companies: number;
    status: 'active' | 'inactive';
    two_factor: boolean;
    sessions: number;
    last_access: string | null;
};

type Invitation = {
    public_id: string;
    email: string;
    name: string | null;
    role_name: string;
    office_name: string;
    invited_by: string | null;
    sent_at: string;
    sent_count: number;
    expires_at: string;
    status: string;
};

type UserDetail = {
    id: number;
    name: string;
    email: string;
    two_factor: boolean;
    status: string;
    roles: {
        id: number;
        code: string;
        name: string;
        office_name: string | null;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
    }[];
    assignments: {
        id: number;
        company_name: string;
        role_label: string;
        is_primary: boolean;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
    }[];
    open_tasks: {
        id: number;
        title: string;
        status: string;
        due_at: string | null;
        company_name: string | null;
    }[];
    workload: { utilization_percentage: number; status: string } | null;
    sessions: {
        ip_address: string | null;
        user_agent: string;
        last_activity: string;
    }[];
    activity: {
        id: number;
        action: string;
        occurred_at: string;
        actor_name: string | null;
    }[];
    dependencies: {
        companies: { company_name: string; role_label: string }[];
        open_tasks: number;
        reviewing_tasks: number;
        pending_reviews: number;
        total: number;
    };
    successors: { id: number; name: string }[];
};

const invitationStatus: Record<
    string,
    [string, 'amber' | 'red' | 'green' | 'slate']
> = {
    pending: ['Pendiente', 'amber'],
    expired: ['Vencida', 'red'],
    accepted: ['Aceptada', 'green'],
    canceled: ['Cancelada', 'slate'],
};

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const userSearchText = (user: UserRow): string =>
    [user.name, user.email, user.roles.join(' '), user.offices.join(' ')].join(
        ' ',
    );

const invitationSearchText = (invitation: Invitation): string =>
    [
        invitation.name,
        invitation.email,
        invitation.role_name,
        invitation.office_name,
    ].join(' ');

export default function OfficeUsers({
    officeScope,
    users,
    invitations,
    selectedUser,
    formOptions,
    permissions,
    notice,
}: {
    officeScope: OfficeScope;
    users: UserRow[];
    invitations: Invitation[];
    selectedUser: UserDetail | null;
    formOptions: FormOptions;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const officeQuery = useOfficeQuery();
    const [inviteOpen, setInviteOpen] = useState(false);
    const [role, setRole] = useState('');
    const [state, setState] = useState('');
    const [security, setSecurity] = useState('');
    const roles = useMemo(
        () => Array.from(new Set(users.flatMap((user) => user.roles))).sort(),
        [users],
    );
    const userPredicate = useMemo(
        () => (user: UserRow) =>
            (role === '' || user.roles.includes(role)) &&
            (state === '' || user.status === state) &&
            (security === '' ||
                (security === 'with' ? user.two_factor : !user.two_factor)),
        [role, state, security],
    );
    const table = useTableControls(users, {
        searchText: userSearchText,
        predicate: userPredicate,
    });
    const [inviteStatus, setInviteStatus] = useState('');
    const invitePredicate = useMemo(
        () => (invitation: Invitation) =>
            inviteStatus === '' || invitation.status === inviteStatus,
        [inviteStatus],
    );
    const inviteTable = useTableControls(invitations, {
        searchText: invitationSearchText,
        predicate: invitePredicate,
        initialPageSize: 5,
    });

    return (
        <>
            <Head title="Usuarios" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Usuarios"
                    subtitle="Equipo de la oficina, roles, accesos y responsabilidades"
                    actions={
                        permissions.canInvite && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setInviteOpen(true)}
                            >
                                <UserPlus className="size-4" /> Invitar usuario
                            </button>
                        )
                    }
                />

                <div
                    className={`grid gap-3 ${selectedUser ? 'xl:grid-cols-[minmax(0,1fr)_420px]' : ''}`}
                >
                    <section className={`${panel} min-w-0 overflow-hidden`}>
                        <div className="p-3 pb-0">
                            <SectionHeader
                                title={`Equipo (${table.count}${table.count !== table.total ? ` de ${table.total}` : ''})`}
                            />
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
                            <TableFilterSelect
                                label="Todo 2FA"
                                value={security}
                                onChange={setSecurity}
                                options={[
                                    { value: 'with', label: '2FA activo' },
                                    {
                                        value: 'without',
                                        label: '2FA pendiente',
                                    },
                                ]}
                            />
                        </TableToolbar>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-3 py-2">Nombre</th>
                                        <th className="px-3 py-2">Rol</th>
                                        <th className="px-3 py-2">Oficina</th>
                                        <th className="px-3 py-2 text-right">
                                            Empresas
                                        </th>
                                        <th className="px-3 py-2">Estado</th>
                                        <th className="px-3 py-2">2FA</th>
                                        <th className="px-3 py-2">
                                            Último acceso
                                        </th>
                                        <th className="px-3 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {table.pageRows.map((user) => (
                                        <tr
                                            key={user.id}
                                            className={
                                                selectedUser?.id === user.id
                                                    ? 'bg-amber-50/60'
                                                    : ''
                                            }
                                        >
                                            <td className="px-3 py-2">
                                                <p className="font-bold text-[#061633]">
                                                    {user.name}
                                                </p>
                                                <p className="text-[11px] text-[#526b92]">
                                                    {user.email}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                {user.roles.join(', ') ||
                                                    (user.is_owner
                                                        ? 'Propietario'
                                                        : 'Sin rol')}
                                            </td>
                                            <td className="px-3 py-2">
                                                {user.offices.join(', ')}
                                            </td>
                                            <td className="px-3 py-2 text-right font-black">
                                                {user.assigned_companies}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span
                                                    className={badge(
                                                        user.status === 'active'
                                                            ? 'green'
                                                            : 'slate',
                                                    )}
                                                >
                                                    {user.status === 'active'
                                                        ? 'Activo'
                                                        : 'Inactivo'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {user.two_factor ? (
                                                    <ShieldCheck
                                                        className="size-4 text-emerald-600"
                                                        aria-label="2FA activo"
                                                    />
                                                ) : (
                                                    <ShieldOff
                                                        className="size-4 text-amber-600"
                                                        aria-label="2FA pendiente"
                                                    />
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-[#526b92]">
                                                {user.last_access
                                                    ? formatDate(
                                                          user.last_access,
                                                          true,
                                                      )
                                                    : 'Sin sesiones'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex items-center gap-1.5">
                                                    <Link
                                                        href={scoped(
                                                            `/office/users?user=${user.id}`,
                                                            officeQuery,
                                                        )}
                                                        preserveScroll
                                                        className={iconButton}
                                                        title="Ver colaborador"
                                                        aria-label={`Ver a ${user.name}`}
                                                    >
                                                        <Eye className="size-4" />
                                                    </Link>
                                                    {permissions.canManageUsers && (
                                                        <Link
                                                            href={scoped(
                                                                `/office/users?user=${user.id}`,
                                                                officeQuery,
                                                            )}
                                                            preserveScroll
                                                            className={
                                                                iconButton
                                                            }
                                                            title="Editar rol y acceso"
                                                            aria-label={`Editar a ${user.name}`}
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Link>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.pageRows.length === 0 && (
                                        <EmptyRow
                                            colSpan={8}
                                            text={
                                                table.total === 0
                                                    ? 'No existen usuarios en esta oficina.'
                                                    : 'No hay usuarios que coincidan con la búsqueda o los filtros.'
                                            }
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
                            noun="usuarios"
                        />
                    </section>
                    {selectedUser && (
                        <UserPanel
                            user={selectedUser}
                            scope={officeScope}
                            options={formOptions}
                            permissions={permissions}
                            officeQuery={officeQuery}
                        />
                    )}
                </div>

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            id="invitaciones"
                            title={`Invitaciones (${inviteTable.count}${inviteTable.count !== inviteTable.total ? ` de ${inviteTable.total}` : ''})`}
                        />
                    </div>
                    <TableToolbar
                        search={inviteTable.search}
                        onSearch={inviteTable.setSearch}
                        placeholder="Buscar por nombre, correo, rol u oficina"
                        pageSize={inviteTable.pageSize}
                        onPageSize={inviteTable.setPageSize}
                    >
                        <TableFilterSelect
                            label="Todos los estados"
                            value={inviteStatus}
                            onChange={setInviteStatus}
                            options={Object.entries(invitationStatus).map(
                                ([value, [label]]) => ({ value, label }),
                            )}
                        />
                    </TableToolbar>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-3 py-2">Invitado</th>
                                    <th className="px-3 py-2">Rol</th>
                                    <th className="px-3 py-2">Oficina</th>
                                    <th className="px-3 py-2">Enviada</th>
                                    <th className="px-3 py-2">Vence</th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {inviteTable.pageRows.map((invitation) => {
                                    const [label, tone] = invitationStatus[
                                        invitation.status
                                    ] ?? ['—', 'slate'];
                                    const actionable =
                                        ['pending', 'expired'].includes(
                                            invitation.status,
                                        ) && permissions.canInvite;

                                    return (
                                        <tr key={invitation.public_id}>
                                            <td className="px-3 py-2">
                                                <p className="font-bold">
                                                    {invitation.name ??
                                                        invitation.email}
                                                </p>
                                                <p className="text-[11px] text-[#526b92]">
                                                    {invitation.email}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                {invitation.role_name}
                                            </td>
                                            <td className="px-3 py-2">
                                                {invitation.office_name}
                                            </td>
                                            <td className="px-3 py-2">
                                                {formatDate(invitation.sent_at)}{' '}
                                                {invitation.sent_count > 1 && (
                                                    <span className="text-[#526b92]">
                                                        ({invitation.sent_count}{' '}
                                                        envíos)
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {formatDate(
                                                    invitation.expires_at,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className={badge(tone)}>
                                                    {label}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {actionable && (
                                                    <div className="flex items-center gap-1.5">
                                                        <button
                                                            type="button"
                                                            className={
                                                                iconButton
                                                            }
                                                            title="Reenviar invitación"
                                                            aria-label={`Reenviar invitación a ${invitation.email}`}
                                                            onClick={() =>
                                                                router.post(
                                                                    `/office/invitations/${invitation.public_id}/resend`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <RefreshCcw className="size-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className={`${iconButton} text-red-700`}
                                                            title="Cancelar invitación"
                                                            aria-label={`Cancelar invitación de ${invitation.email}`}
                                                            onClick={() =>
                                                                confirm(
                                                                    `¿Cancelar la invitación de ${invitation.email}?`,
                                                                ) &&
                                                                router.post(
                                                                    `/office/invitations/${invitation.public_id}/cancel`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <X className="size-4" />
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {inviteTable.pageRows.length === 0 && (
                                    <EmptyRow
                                        colSpan={7}
                                        text={
                                            inviteTable.total === 0
                                                ? 'No existen invitaciones en esta oficina.'
                                                : 'No hay invitaciones que coincidan con la búsqueda o el filtro.'
                                        }
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        page={inviteTable.page}
                        pages={inviteTable.pages}
                        pageSize={inviteTable.pageSize}
                        count={inviteTable.count}
                        onPage={inviteTable.setPage}
                        noun="invitaciones"
                    />
                </section>
            </Shell>
            <InviteDialog
                open={inviteOpen}
                onClose={() => setInviteOpen(false)}
                scope={officeScope}
                options={formOptions}
            />
        </>
    );
}

function UserPanel({
    user,
    scope,
    options,
    permissions,
    officeQuery,
}: {
    user: UserDetail;
    scope: OfficeScope;
    options: FormOptions;
    permissions: Record<string, boolean>;
    officeQuery: string | null;
}) {
    const isActive = user.status === 'active';
    const roleForm = useForm({
        role_code: options.roles[0]?.code ?? '',
        office_id: (scope.selected ?? scope.offices[0]?.id ?? '').toString(),
    });
    const deactivateForm = useForm({ reassign_to: '', leave_pending: false });

    const changeRole = (event: FormEvent) => {
        event.preventDefault();
        roleForm.post(`/office/users/${user.id}/role`, {
            preserveScroll: true,
        });
    };

    const deactivate = (event: FormEvent) => {
        event.preventDefault();

        if (
            confirm(
                `¿Desactivar a ${user.name}? Se cerrarán sus sesiones y su historial se conserva.`,
            )
        ) {
            deactivateForm.post(`/office/users/${user.id}/deactivate`, {
                preserveScroll: true,
            });
        }
    };

    return (
        <aside className={`${panel} space-y-4 p-4`}>
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 className="text-xl font-black">{user.name}</h2>
                    <p className="text-sm text-[#526b92]">{user.email}</p>
                    <p className="mt-1 flex items-center gap-1 text-xs">
                        {user.two_factor ? (
                            <>
                                <ShieldCheck className="size-3.5 text-emerald-600" />{' '}
                                2FA activo
                            </>
                        ) : (
                            <>
                                <ShieldOff className="size-3.5 text-amber-600" />{' '}
                                Sin 2FA
                            </>
                        )}
                        {user.workload && (
                            <span className="ml-2">
                                · Carga {user.workload.utilization_percentage}%
                                ({user.workload.status})
                            </span>
                        )}
                    </p>
                </div>
                <Link
                    href={scoped('/office/users', officeQuery)}
                    className="text-[#526b92]"
                >
                    <X className="size-5" />
                </Link>
            </div>

            <div>
                <h3 className="mb-1 text-sm font-black">Roles</h3>
                <ul className="space-y-1 text-xs">
                    {user.roles.map((role) => (
                        <li
                            key={role.id}
                            className="flex justify-between gap-2"
                        >
                            <span>
                                {role.name}{' '}
                                {role.office_name && (
                                    <span className="text-[#526b92]">
                                        · {role.office_name}
                                    </span>
                                )}
                            </span>
                            <span
                                className={badge(
                                    role.status === 'active'
                                        ? 'green'
                                        : 'slate',
                                )}
                            >
                                {role.status === 'active'
                                    ? 'Vigente'
                                    : `Hasta ${formatDate(role.ends_at)}`}
                            </span>
                        </li>
                    ))}
                </ul>
                {permissions.canManageUsers && (
                    <form
                        onSubmit={changeRole}
                        className="mt-2 grid grid-cols-[1fr_1fr_auto] gap-1.5"
                    >
                        <select
                            className={input}
                            value={roleForm.data.role_code}
                            onChange={(event) =>
                                roleForm.setData(
                                    'role_code',
                                    event.target.value,
                                )
                            }
                        >
                            {options.roles.map((role) => (
                                <option key={role.code} value={role.code}>
                                    {role.name}
                                </option>
                            ))}
                        </select>
                        <select
                            className={input}
                            value={roleForm.data.office_id}
                            onChange={(event) =>
                                roleForm.setData(
                                    'office_id',
                                    event.target.value,
                                )
                            }
                        >
                            {scope.offices.map((office) => (
                                <option key={office.id} value={office.id}>
                                    {office.name}
                                </option>
                            ))}
                        </select>
                        <button
                            type="submit"
                            className={secondaryButton}
                            disabled={roleForm.processing}
                        >
                            Cambiar
                        </button>
                    </form>
                )}
            </div>

            <div>
                <h3 className="mb-1 text-sm font-black">
                    Empresas asignadas e historial
                </h3>
                <ul className="max-h-48 space-y-1 overflow-y-auto text-xs">
                    {user.assignments.map((assignment) => (
                        <li
                            key={assignment.id}
                            className="flex justify-between gap-2"
                        >
                            <span>
                                {assignment.company_name} ·{' '}
                                {assignment.role_label}
                                {assignment.is_primary ? ' (principal)' : ''}
                            </span>
                            <span className="shrink-0 text-[#526b92]">
                                {assignment.status === 'active'
                                    ? `Desde ${formatDate(assignment.starts_at)}`
                                    : `${formatDate(assignment.starts_at)} → ${formatDate(assignment.ends_at)}`}
                            </span>
                        </li>
                    ))}
                    {user.assignments.length === 0 && (
                        <li className="text-[#526b92]">
                            Sin empresas asignadas.
                        </li>
                    )}
                </ul>
            </div>

            <div>
                <h3 className="mb-1 text-sm font-black">
                    Tareas activas ({user.open_tasks.length})
                </h3>
                <ul className="space-y-1 text-xs">
                    {user.open_tasks.map((task) => (
                        <li key={task.id}>
                            <Link
                                href={`/work/tasks/${task.id}`}
                                className="hover:text-[#640000]"
                            >
                                {task.title}
                            </Link>{' '}
                            <span className="text-[#526b92]">
                                · {task.company_name ?? 'Sin empresa'} · vence{' '}
                                {formatDate(task.due_at)}
                            </span>
                        </li>
                    ))}
                    {user.open_tasks.length === 0 && (
                        <li className="text-[#526b92]">Sin tareas activas.</li>
                    )}
                </ul>
            </div>

            <div>
                <div className="mb-1 flex items-center justify-between">
                    <h3 className="text-sm font-black">
                        Sesiones ({user.sessions.length})
                    </h3>
                    {permissions.canManageSecurity &&
                        user.sessions.length > 0 && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    confirm('¿Cerrar todas sus sesiones?') &&
                                    router.post(
                                        `/office/users/${user.id}/sessions/revoke`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <LogOut className="size-3" /> Cerrar sesiones
                            </button>
                        )}
                </div>
                <ul className="space-y-1 text-xs text-[#526b92]">
                    {user.sessions.map((session, index) => (
                        <li key={index}>
                            {formatDate(session.last_activity, true)} ·{' '}
                            {session.ip_address ?? 'IP desconocida'} ·{' '}
                            {session.user_agent}
                        </li>
                    ))}
                </ul>
            </div>

            <div>
                <h3 className="mb-1 text-sm font-black">Actividad</h3>
                <ul className="space-y-1 text-xs text-[#526b92]">
                    {user.activity.map((event) => (
                        <li key={event.id}>
                            {formatDate(event.occurred_at, true)} ·{' '}
                            {event.action} · {event.actor_name ?? 'Sistema'}
                        </li>
                    ))}
                </ul>
            </div>

            {permissions.canDeactivateUsers &&
                (isActive ? (
                    <form
                        onSubmit={deactivate}
                        className="space-y-2 rounded-xl border border-red-200 bg-red-50/40 p-3"
                    >
                        <h3 className="text-sm font-black text-red-800">
                            Desactivar usuario
                        </h3>
                        {user.dependencies.total > 0 ? (
                            <p className="text-xs text-red-800">
                                Tiene {user.dependencies.companies.length}{' '}
                                empresa(s) asignada(s),{' '}
                                {user.dependencies.open_tasks} tarea(s)
                                activa(s), {user.dependencies.reviewing_tasks}{' '}
                                tarea(s) por revisar y{' '}
                                {user.dependencies.pending_reviews} revisión(es)
                                de cierre. Elige quién recibe el trabajo o
                                déjalo pendiente de reasignación.
                            </p>
                        ) : (
                            <p className="text-xs text-[#526b92]">
                                No tiene empresas, tareas ni revisiones
                                pendientes.
                            </p>
                        )}
                        {user.dependencies.total > 0 && (
                            <>
                                <select
                                    className={input}
                                    value={deactivateForm.data.reassign_to}
                                    onChange={(event) =>
                                        deactivateForm.setData({
                                            reassign_to: event.target.value,
                                            leave_pending: false,
                                        })
                                    }
                                >
                                    <option value="">
                                        Elegir quién recibe el trabajo
                                    </option>
                                    {user.successors.map((successor) => (
                                        <option
                                            key={successor.id}
                                            value={successor.id}
                                        >
                                            {successor.name}
                                        </option>
                                    ))}
                                </select>
                                <label className="flex items-center gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={
                                            deactivateForm.data.leave_pending
                                        }
                                        onChange={(event) =>
                                            deactivateForm.setData({
                                                reassign_to: '',
                                                leave_pending:
                                                    event.target.checked,
                                            })
                                        }
                                    />
                                    Dejar el trabajo pendiente de reasignación
                                </label>
                            </>
                        )}
                        {deactivateForm.errors.reassign_to && (
                            <p className="text-xs text-red-700">
                                {deactivateForm.errors.reassign_to}
                            </p>
                        )}
                        <button
                            type="submit"
                            className="inline-flex h-9 items-center rounded-lg bg-red-700 px-3 text-xs font-bold text-white disabled:opacity-50"
                            disabled={
                                deactivateForm.processing ||
                                (user.dependencies.total > 0 &&
                                    !deactivateForm.data.reassign_to &&
                                    !deactivateForm.data.leave_pending)
                            }
                        >
                            Desactivar y cerrar sesiones
                        </button>
                    </form>
                ) : (
                    <button
                        type="button"
                        className={primaryButton}
                        onClick={() =>
                            router.post(
                                `/office/users/${user.id}/reactivate`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Reactivar usuario
                    </button>
                ))}
        </aside>
    );
}
