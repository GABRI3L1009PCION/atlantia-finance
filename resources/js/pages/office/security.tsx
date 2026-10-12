import { Head, Link, router } from '@inertiajs/react';
import { LogOut, ShieldCheck, ShieldOff } from 'lucide-react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    panel,
    scoped,
    secondaryButton,
    useOfficeQuery,
} from '@/components/office-admin/kit';

type Security = {
    offices: { id: number; name: string; require_two_factor: boolean }[];
    users: {
        id: number;
        name: string;
        email: string;
        roles: string[];
        status: string;
        two_factor: boolean;
        sessions: number;
        last_access: string | null;
    }[];
    summary: {
        with_two_factor: number;
        without_two_factor: number;
        active_sessions: number;
        inactive_users: number;
    };
    events: {
        event_id: number;
        description: string;
        created_at: string;
        destination: string | null;
    }[];
};

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

    return (
        <>
            <Head title="Seguridad de la oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Seguridad"
                    subtitle="Autenticación en dos pasos, sesiones activas y eventos de acceso del equipo"
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

                <section className={`${panel} p-3`}>
                    <SectionHeader title="Política de 2FA por oficina" />
                    <p className="mb-2 text-sm text-[#526b92]">
                        Cuando está activa, quien no haya confirmado 2FA solo
                        podrá entrar a su configuración de seguridad hasta
                        activarla. Los secretos 2FA nunca son visibles para la
                        administración.
                    </p>
                    <div className="space-y-2">
                        {security.offices.map((office) => (
                            <div
                                key={office.id}
                                className="flex items-center justify-between rounded-xl border border-slate-100 p-3"
                            >
                                <span className="font-bold">{office.name}</span>
                                <div className="flex items-center gap-2">
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
                                    {permissions.canManageSecurity && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
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
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {office.require_two_factor
                                                ? 'Desactivar exigencia'
                                                : 'Exigir 2FA'}
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                <section className={`${panel} p-3`}>
                    <SectionHeader title="Usuarios y sesiones" />
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Usuario</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2">2FA</th>
                                    <th className="px-2 py-2 text-right">
                                        Sesiones
                                    </th>
                                    <th className="px-2 py-2">Último acceso</th>
                                    <th className="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {security.users.map((user) => (
                                    <tr key={user.id}>
                                        <td className="px-2 py-2">
                                            <Link
                                                href={scoped(
                                                    `/office/users?user=${user.id}`,
                                                    officeQuery,
                                                )}
                                                className="font-bold hover:text-[#640000]"
                                            >
                                                {user.name}
                                            </Link>
                                            <p className="text-[11px] text-[#526b92]">
                                                {user.email}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2">
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
                                        <td className="px-2 py-2">
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
                                        <td className="px-2 py-2 text-right font-black">
                                            {user.sessions}
                                        </td>
                                        <td className="px-2 py-2">
                                            {user.last_access
                                                ? formatDate(
                                                      user.last_access,
                                                      true,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-2 py-2 text-right">
                                            {permissions.canManageSecurity &&
                                                user.sessions > 0 && (
                                                    <button
                                                        type="button"
                                                        className={
                                                            secondaryButton
                                                        }
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
                                                        <LogOut className="size-3" />{' '}
                                                        Cerrar sesiones
                                                    </button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                                {security.users.length === 0 && (
                                    <EmptyRow
                                        colSpan={6}
                                        text="No existen usuarios en esta oficina."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className={`${panel} p-3`}>
                    <SectionHeader title="Eventos de acceso recientes" />
                    <ul className="divide-y divide-slate-100 text-sm">
                        {security.events.map((event) => (
                            <li
                                key={event.event_id}
                                className="flex justify-between gap-2 py-2"
                            >
                                <span>{event.description}</span>
                                <span className="shrink-0 text-xs text-[#526b92]">
                                    {formatDate(event.created_at, true)}
                                </span>
                            </li>
                        ))}
                        {security.events.length === 0 && (
                            <li className="py-4 text-center text-slate-500">
                                Sin eventos recientes.
                            </li>
                        )}
                    </ul>
                </section>
            </Shell>
        </>
    );
}
