import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import {
    CCPage,
    Panel,
    Pill,
    SensitiveAction,
    Table,
    ago,
    date,
    panel,
} from '@/components/control-center/kit';

type Overview = {
    privileged_users: number;
    staff_with_2fa: number;
    staff_without_2fa: {
        id: number;
        name: string;
        email: string;
        role: string;
    }[];
    tenant_users_with_2fa: number;
    tenant_users: number;
    offices_enforcing_2fa: number;
    failed_logins_24h: number;
    suspicious_ips: {
        ip: string;
        attempts: number;
        accounts: number;
        last_at: string;
    }[];
    active_support_access: number;
    pending_support_access: number;
    sensitive_changes_7d: number;
    require_2fa_policy: boolean;
};
type Event = {
    at: string;
    type: string;
    severity: string;
    title: string;
    detail: string;
};
type Session = {
    id: string;
    user_id: number;
    user: string;
    email: string;
    role: string;
    ip: string | null;
    device: string;
    last_activity: string;
    status: string;
};

export default function Security({
    overview,
    events,
    sessions,
    failed_logins,
    can_manage,
}: {
    overview: Overview;
    events: Event[];
    sessions: Session[];
    failed_logins: {
        email: string | null;
        ip: string | null;
        device: string;
        at: string;
    }[];
    can_manage: boolean;
}) {
    const tenantPercent =
        overview.tenant_users === 0
            ? null
            : Math.round(
                  (overview.tenant_users_with_2fa / overview.tenant_users) *
                      100,
              );

    return (
        <>
            <Head title="Centro de seguridad · Control Center" />
            <CCPage
                title="Centro de seguridad"
                subtitle="Usuarios privilegiados, 2FA, sesiones activas, intentos fallidos y cambios sensibles. Las direcciones de correo de los clientes se muestran enmascaradas."
            >
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {[
                        ['Personal SaaS', overview.privileged_users],
                        [
                            'Personal con 2FA',
                            `${overview.staff_with_2fa}/${overview.privileged_users}`,
                        ],
                        [
                            'Usuarios cliente con 2FA',
                            tenantPercent === null ? '—' : `${tenantPercent}%`,
                        ],
                        ['Fallidos 24 h', overview.failed_logins_24h],
                        [
                            'Accesos de soporte activos',
                            overview.active_support_access,
                        ],
                        [
                            'Cambios sensibles 7 d',
                            overview.sensitive_changes_7d,
                        ],
                    ].map(([label, value]) => (
                        <div key={String(label)} className={`${panel} p-4`}>
                            <p className="text-xs text-slate-400">{label}</p>
                            <p className="mt-1 text-2xl font-black text-white">
                                {value}
                            </p>
                        </div>
                    ))}
                </div>
                {overview.staff_without_2fa.length > 0 && (
                    <div className="rounded-xl border border-[#ef4444]/30 bg-[#ef4444]/10 p-3 text-sm text-[#fecaca]">
                        <ShieldAlert className="mr-1 inline size-4" /> Personal
                        SaaS sin 2FA:{' '}
                        {overview.staff_without_2fa
                            .map((u) => `${u.name} (${u.role})`)
                            .join(', ')}
                        .{' '}
                        {overview.require_2fa_policy
                            ? 'La política obliga a configurarlo antes de entrar al Control Center.'
                            : 'La política de 2FA obligatoria está desactivada.'}
                    </div>
                )}
                {overview.pending_support_access > 0 && (
                    <Link
                        href="/control-center/support-access"
                        className="block rounded-xl border border-[#f59e0b]/30 bg-[#f59e0b]/10 p-3 text-sm text-[#fde68a]"
                    >
                        {overview.pending_support_access} solicitud(es) de
                        acceso de soporte esperan aprobación →
                    </Link>
                )}
                <div className="grid gap-3 xl:grid-cols-2">
                    <Panel title="Eventos de seguridad">
                        <Table
                            headers={[
                                'Fecha',
                                'Evento',
                                'Detalle',
                                'Severidad',
                            ]}
                            empty={
                                events.length === 0 && 'Sin eventos recientes.'
                            }
                        >
                            {events.map((event, index) => (
                                <tr key={index}>
                                    <td className="px-2 py-1.5 whitespace-nowrap">
                                        {date(event.at, true)}
                                    </td>
                                    <td className="px-2 py-1.5 font-mono text-white">
                                        {event.title}
                                    </td>
                                    <td className="px-2 py-1.5 text-slate-400">
                                        {event.detail}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        <Pill
                                            tone={
                                                event.severity === 'high'
                                                    ? 'red'
                                                    : 'amber'
                                            }
                                        >
                                            {event.severity === 'high'
                                                ? 'Alta'
                                                : 'Media'}
                                        </Pill>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                    <Panel title="IPs sospechosas (1 h)">
                        <Table
                            headers={['IP', 'Intentos', 'Cuentas', 'Último']}
                            empty={
                                overview.suspicious_ips.length === 0 &&
                                'Sin ráfagas de intentos fallidos.'
                            }
                        >
                            {overview.suspicious_ips.map((ip) => (
                                <tr key={ip.ip}>
                                    <td className="px-2 py-1.5 font-mono text-white">
                                        {ip.ip}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {ip.attempts}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {ip.accounts}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {ago(ip.last_at)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        <h3 className="mt-4 mb-2 text-xs font-bold text-slate-400">
                            Últimos intentos fallidos
                        </h3>
                        <Table
                            headers={['Fecha', 'Correo', 'IP', 'Dispositivo']}
                            empty={
                                failed_logins.length === 0 &&
                                'Sin intentos fallidos.'
                            }
                        >
                            {failed_logins.map((row, index) => (
                                <tr key={index}>
                                    <td className="px-2 py-1.5 whitespace-nowrap">
                                        {date(row.at, true)}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {row.email ?? '—'}
                                    </td>
                                    <td className="px-2 py-1.5 font-mono">
                                        {row.ip ?? '—'}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {row.device}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                </div>
                <Panel title={`Sesiones (${sessions.length})`}>
                    <Table
                        headers={[
                            'Usuario',
                            'Rol',
                            'IP',
                            'Dispositivo',
                            'Última actividad',
                            'Estado',
                            '',
                        ]}
                        empty={sessions.length === 0 && 'Sin sesiones.'}
                    >
                        {sessions.map((session) => (
                            <tr key={session.id}>
                                <td className="px-2 py-1.5">
                                    <span className="text-white">
                                        {session.user}
                                    </span>
                                    <span className="block text-[10px] text-slate-500">
                                        {session.email}
                                    </span>
                                </td>
                                <td className="px-2 py-1.5">
                                    {session.role === 'tenant'
                                        ? 'Cliente'
                                        : session.role}
                                </td>
                                <td className="px-2 py-1.5 font-mono">
                                    {session.ip ?? '—'}
                                </td>
                                <td className="px-2 py-1.5">
                                    {session.device}
                                </td>
                                <td className="px-2 py-1.5">
                                    {ago(session.last_activity)}
                                </td>
                                <td className="px-2 py-1.5">
                                    <Pill
                                        tone={
                                            session.status === 'active'
                                                ? 'green'
                                                : 'slate'
                                        }
                                    >
                                        {session.status === 'active'
                                            ? 'Activa'
                                            : 'Inactiva'}
                                    </Pill>
                                </td>
                                <td className="px-2 py-1.5">
                                    {can_manage && (
                                        <SensitiveAction
                                            danger
                                            url="/control-center/security/sessions/revoke"
                                            data={{ session: session.id }}
                                            label="Revocar"
                                            title={`Revocar sesión de ${session.user}`}
                                            description="La persona deberá iniciar sesión de nuevo en ese dispositivo."
                                        />
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
