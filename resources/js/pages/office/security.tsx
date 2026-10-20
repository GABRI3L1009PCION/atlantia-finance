import { Head, Link, router } from '@inertiajs/react';
import { Eye, History, LogOut, ShieldCheck, ShieldOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    panel,
    primaryButton,
    scoped,
    secondaryButton,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type SecurityUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    status: string;
    two_factor: boolean;
    sessions: number;
    last_access: string | null;
};

type SecurityEvent = {
    event_id: number;
    description: string;
    created_at: string;
    destination: string | null;
};

type Security = {
    offices: { id: number; name: string; require_two_factor: boolean }[];
    users: SecurityUser[];
    summary: {
        with_two_factor: number;
        without_two_factor: number;
        active_sessions: number;
        inactive_users: number;
    };
    events: SecurityEvent[];
};

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const userSearchText = (user: SecurityUser): string =>
    [user.name, user.email, user.roles.join(' ')].join(' ');

export default function OfficeSecurity({
    security,
    permissions,
    notice,
}: {
    security: Security;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const officeQuery = useOfficeQuery();
    const cards = [
        ['Con 2FA activo', security.summary.with_two_factor],
        ['Sin 2FA', security.summary.without_two_factor],
        ['Sesiones abiertas', security.summary.active_sessions],
        ['Usuarios inactivos', security.summary.inactive_users],
    ] as const;

    const [dialog, setDialog] = useState<'policy' | 'events' | null>(null);
    const [twoFactor, setTwoFactor] = useState('');
    const [state, setState] = useState('');
    const [sessions, setSessions] = useState('');
    const userPredicate = useMemo(
        () => (user: SecurityUser) =>
            (twoFactor === '' ||
                (twoFactor === 'with' ? user.two_factor : !user.two_factor)) &&
            (state === '' || user.status === state) &&
            (sessions === '' ||
                (sessions === 'open'
                    ? user.sessions > 0
                    : user.sessions === 0)),
        [twoFactor, state, sessions],
    );
    const users = useTableControls(security.users, {
        searchText: userSearchText,
        predicate: userPredicate,
    });
    const events = useTableControls(security.events, {
        searchText: (event: SecurityEvent) => event.description,
        initialPageSize: 5,
    });
    const offices = useTableControls(security.offices, {
        searchText: (office: Security['offices'][number]) => office.name,
        initialPageSize: 5,
    });

    return (
        <>
            <Head title="Seguridad de la oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Seguridad"
                    subtitle="Autenticación en dos pasos, sesiones activas y eventos de acceso del equipo"
                    actions={
                        <>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setDialog('events')}
                            >
                                <History className="size-4" /> Eventos de acceso
                            </button>
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setDialog('policy')}
                            >
                                <ShieldCheck className="size-4" /> Política de
                                2FA
                            </button>
                        </>
                    }
                />
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {cards.map(([label, value]) => (
                        <div key={label} className={`${panel} p-3`}>
                            <p className="text-xs font-semibold text-[#466287]">
                                {label}
                            </p>
                            <p className="text-2xl font-black">{value}</p>
                        </div>
                    ))}
                </section>

                <Dialog
                    open={dialog === 'policy'}
                    onOpenChange={(open) => !open && setDialog(null)}
                >
                    <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-3xl">
                        <DialogTitle>Política de 2FA por oficina</DialogTitle>
                        <DialogDescription>
                            Cuando está activa, quien no haya confirmado 2FA
                            solo podrá entrar a su configuración de seguridad
                            hasta activarla. Los secretos 2FA nunca son visibles
                            para la administración.
                        </DialogDescription>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-3 py-2">Oficina</th>
                                        <th className="px-3 py-2">Política</th>
                                        <th className="px-3 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {offices.pageRows.map((office) => (
                                        <tr key={office.id}>
                                            <td className="px-3 py-2 font-bold text-[#061633]">
                                                {office.name}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span
                                                    className={badge(
                                                        office.require_two_factor
                                                            ? 'green'
                                                            : 'slate',
                                                    )}
                                                >
                                                    {office.require_two_factor
                                                        ? '2FA obligatorio'
                                                        : '2FA opcional'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {permissions.canManageSecurity && (
                                                    <button
                                                        type="button"
                                                        className={iconButton}
                                                        title={
                                                            office.require_two_factor
                                                                ? 'Dejar de exigir 2FA'
                                                                : 'Exigir 2FA'
                                                        }
                                                        aria-label={
                                                            office.require_two_factor
                                                                ? `Dejar de exigir 2FA en ${office.name}`
                                                                : `Exigir 2FA en ${office.name}`
                                                        }
                                                        onClick={() =>
                                                            confirm(
                                                                office.require_two_factor
                                                                    ? '¿Dejar de exigir 2FA en esta oficina?'
                                                                    : '¿Exigir 2FA a todo el equipo de esta oficina?',
                                                            ) &&
                                                            router.patch(
                                                                `/office/offices/${office.id}/security`,
                                                                {
                                                                    require_two_factor:
                                                                        !office.require_two_factor,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {office.require_two_factor ? (
                                                            <ShieldOff className="size-4" />
                                                        ) : (
                                                            <ShieldCheck className="size-4" />
                                                        )}
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {offices.pageRows.length === 0 && (
                                        <EmptyRow
                                            colSpan={3}
                                            text="No hay oficinas en este alcance."
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {offices.pages > 1 && (
                            <TablePagination
                                page={offices.page}
                                pages={offices.pages}
                                pageSize={offices.pageSize}
                                count={offices.count}
                                onPage={offices.setPage}
                                noun="oficinas"
                            />
                        )}
                    </DialogContent>
                </Dialog>

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            title={`Usuarios y sesiones (${users.count}${users.count !== users.total ? ` de ${users.total}` : ''})`}
                        />
                    </div>
                    <TableToolbar
                        search={users.search}
                        onSearch={users.setSearch}
                        placeholder="Buscar por nombre, correo o rol"
                        pageSize={users.pageSize}
                        onPageSize={users.setPageSize}
                    >
                        <TableFilterSelect
                            label="Todo 2FA"
                            value={twoFactor}
                            onChange={setTwoFactor}
                            options={[
                                { value: 'with', label: '2FA activo' },
                                { value: 'without', label: '2FA pendiente' },
                            ]}
                        />
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
                            label="Todas las sesiones"
                            value={sessions}
                            onChange={setSessions}
                            options={[
                                { value: 'open', label: 'Con sesión abierta' },
                                { value: 'none', label: 'Sin sesión' },
                            ]}
                        />
                    </TableToolbar>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-3 py-2">Usuario</th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2">2FA</th>
                                    <th className="px-3 py-2 text-right">
                                        Sesiones
                                    </th>
                                    <th className="px-3 py-2">Último acceso</th>
                                    <th className="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {users.pageRows.map((user) => (
                                    <tr key={user.id}>
                                        <td className="px-3 py-2">
                                            <p className="font-bold text-[#061633]">
                                                {user.name}
                                            </p>
                                            <p className="text-[11px] text-[#526b92]">
                                                {user.email}
                                            </p>
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
                                                <span className="flex items-center gap-1 text-emerald-700">
                                                    <ShieldCheck className="size-4" />{' '}
                                                    Activo
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1 text-amber-700">
                                                    <ShieldOff className="size-4" />{' '}
                                                    Pendiente
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right font-black">
                                            {user.sessions}
                                        </td>
                                        <td className="px-3 py-2 text-[#526b92]">
                                            {user.last_access
                                                ? formatDate(
                                                      user.last_access,
                                                      true,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1.5">
                                                <Link
                                                    href={scoped(
                                                        `/office/users?user=${user.id}`,
                                                        officeQuery,
                                                    )}
                                                    className={iconButton}
                                                    title="Ver colaborador"
                                                    aria-label={`Ver a ${user.name}`}
                                                >
                                                    <Eye className="size-4" />
                                                </Link>
                                                {permissions.canManageSecurity &&
                                                    user.sessions > 0 && (
                                                        <button
                                                            type="button"
                                                            className={`${iconButton} text-red-700`}
                                                            title="Cerrar sesiones"
                                                            aria-label={`Cerrar las sesiones de ${user.name}`}
                                                            onClick={() =>
                                                                confirm(
                                                                    `¿Cerrar las sesiones de ${user.name}?`,
                                                                ) &&
                                                                router.post(
                                                                    `/office/users/${user.id}/sessions/revoke`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <LogOut className="size-4" />
                                                        </button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {users.pageRows.length === 0 && (
                                    <EmptyRow
                                        colSpan={6}
                                        text={
                                            users.total === 0
                                                ? 'No existen usuarios en esta oficina.'
                                                : 'No hay usuarios que coincidan con la búsqueda o los filtros.'
                                        }
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        page={users.page}
                        pages={users.pages}
                        pageSize={users.pageSize}
                        count={users.count}
                        onPage={users.setPage}
                        noun="usuarios"
                    />
                </section>

                <Dialog
                    open={dialog === 'events'}
                    onOpenChange={(open) => !open && setDialog(null)}
                >
                    <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-4xl">
                        <DialogTitle>Eventos de acceso recientes</DialogTitle>
                        <DialogDescription>
                            Inicios de sesión y cambios de seguridad del equipo.
                        </DialogDescription>
                        <TableToolbar
                            search={events.search}
                            onSearch={events.setSearch}
                            placeholder="Buscar en los eventos"
                            pageSize={events.pageSize}
                            onPageSize={events.setPageSize}
                        />
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-3 py-2">Fecha</th>
                                        <th className="px-3 py-2">Evento</th>
                                        <th className="px-3 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {events.pageRows.map((event) => (
                                        <tr key={event.event_id}>
                                            <td className="px-3 py-2 whitespace-nowrap text-[#526b92]">
                                                {formatDate(
                                                    event.created_at,
                                                    true,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {event.description}
                                            </td>
                                            <td className="px-3 py-2">
                                                {event.destination && (
                                                    <Link
                                                        href={event.destination}
                                                        className={iconButton}
                                                        title="Abrir el registro"
                                                        aria-label="Abrir el registro del evento"
                                                    >
                                                        <Eye className="size-4" />
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {events.pageRows.length === 0 && (
                                        <EmptyRow
                                            colSpan={3}
                                            text={
                                                events.total === 0
                                                    ? 'Sin eventos recientes.'
                                                    : 'No hay eventos que coincidan con la búsqueda.'
                                            }
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <TablePagination
                            page={events.page}
                            pages={events.pages}
                            pageSize={events.pageSize}
                            count={events.count}
                            onPage={events.setPage}
                            noun="eventos"
                        />
                    </DialogContent>
                </Dialog>
            </Shell>
        </>
    );
}
