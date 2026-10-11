import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BriefcaseBusiness,
    Building2,
    ChevronDown,
    FileWarning,
    Mail,
    MapPin,
    RefreshCcw,
    ShieldAlert,
    ShieldCheck,
    UserCheck,
    UserMinus,
    UserPlus,
    UserRoundX,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    AssignDialog,
    EmptyRow,
    InviteDialog,
    NewCompanyDialog,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    chip,
    formatDate,
    panel,
    priorityLabel,
    relativeTime,
    scoped,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import type {
    AssignTarget,
    CompatibleUser,
    FormOptions,
    OfficeScope,
    Tone,
} from '@/components/office-admin/kit';

type Metrics = {
    active_users: number;
    active_companies: number;
    companies_without_accountant: number;
    companies_without_assistant: number;
    pending_assignments: number;
    pending_invitations: number;
};

type Panel = {
    office: { id: number; name: string } | null;
    scope: OfficeScope;
    metrics: Metrics;
    comparisons: Record<
        keyof Metrics,
        {
            label: string;
            direction: 'up' | 'down' | 'flat';
            tone: 'positive' | 'negative' | 'neutral';
        }
    >;
    incomplete_assignments: (AssignTarget & {
        tax_id: string;
        priority: string;
        reason: string;
        compatible_users: CompatibleUser[];
        compatible_users_count: number;
        company_public_id: string;
    })[];
    team_status: {
        active_users: number;
        high_workload: number | null;
        without_company: number;
        pending_2fa: number;
        inactive: number;
    };
    team_workload:
        | {
              user_id: number;
              name: string;
              role_label?: string;
              assigned_companies: number;
              tasks: Record<string, number>;
              utilization_percentage: number;
              status: string;
          }[]
        | null;
    workload_error: string | null;
    invitations: {
        public_id: string;
        email: string;
        name: string | null;
        role_name: string;
        office_name: string;
        sent_at: string;
        status: string;
    }[];
    recent_activity: {
        event_id: number;
        actor: string;
        description: string;
        action: string;
        created_at: string;
        destination: string | null;
    }[];
    office_summaries: {
        id: number;
        name: string;
        location: string | null;
        responsible: string | null;
        responsible_role: string | null;
        users: number;
        companies: number;
        pending_assignments: number;
        status: string;
    }[];
    form_options: FormOptions;
    permissions: Record<string, boolean>;
};

const kpiTone: Record<string, string> = {
    blue: 'bg-blue-100 text-blue-700',
    red: 'bg-red-100 text-red-700',
    amber: 'bg-amber-100 text-amber-700',
    green: 'bg-emerald-100 text-emerald-700',
};

export default function OfficeAdminDashboard({
    panel: data,
    notice,
}: {
    panel: Panel;
    notice: string | null;
}) {
    const officeQuery = useOfficeQuery();
    const [assignTarget, setAssignTarget] = useState<AssignTarget | null>(null);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [companyOpen, setCompanyOpen] = useState(false);
    const scopeName = data.office?.name ?? 'Todas las oficinas';
    const kpis: {
        key: keyof Metrics;
        label: string;
        icon: LucideIcon;
        tone: string;
        href: string;
    }[] = [
        {
            key: 'active_users',
            label: 'Usuarios activos',
            icon: Users,
            tone: 'blue',
            href: '/office/users',
        },
        {
            key: 'active_companies',
            label: 'Empresas activas',
            icon: BriefcaseBusiness,
            tone: 'blue',
            href: '/office/companies',
        },
        {
            key: 'companies_without_accountant',
            label: 'Empresas sin contador',
            icon: UserRoundX,
            tone: 'red',
            href: '/office/assignments',
        },
        {
            key: 'companies_without_assistant',
            label: 'Empresas sin auxiliar',
            icon: UserMinus,
            tone: 'amber',
            href: '/office/assignments',
        },
        {
            key: 'pending_assignments',
            label: 'Asignaciones pendientes',
            icon: FileWarning,
            tone: 'red',
            href: '/office/assignments',
        },
        {
            key: 'pending_invitations',
            label: 'Invitaciones pendientes',
            icon: Mail,
            tone: 'blue',
            href: '/office/users#invitaciones',
        },
    ];
    const teamCards: {
        label: string;
        value: number | null;
        icon: LucideIcon;
        tone: string;
        href: string;
    }[] = [
        {
            label: 'Usuarios con alta carga',
            value: data.team_status.high_workload,
            icon: AlertTriangle,
            tone: 'bg-red-50 text-red-700',
            href: '/office/team-workload',
        },
        {
            label: 'Usuarios sin empresa',
            value: data.team_status.without_company,
            icon: UserPlus,
            tone: 'bg-amber-50 text-amber-700',
            href: '/office/users',
        },
        {
            label: 'Usuarios con 2FA pendiente',
            value: data.team_status.pending_2fa,
            icon: ShieldAlert,
            tone: 'bg-blue-50 text-blue-700',
            href: '/office/security',
        },
        {
            label: 'Usuarios inactivos',
            value: data.team_status.inactive,
            icon: UserRoundX,
            tone: 'bg-red-50 text-red-700',
            href: '/office/users',
        },
    ];
    const quickActions: {
        label: string;
        icon: LucideIcon;
        visible: boolean;
        action: () => void;
    }[] = [
        {
            label: 'Nueva oficina',
            icon: Building2,
            visible: data.permissions.canCreateOffice,
            action: () => router.visit('/office/offices'),
        },
        {
            label: 'Nueva empresa',
            icon: BriefcaseBusiness,
            visible: data.permissions.canCreateCompany,
            action: () => setCompanyOpen(true),
        },
        {
            label: 'Invitar usuario',
            icon: UserPlus,
            visible: data.permissions.canInvite,
            action: () => setInviteOpen(true),
        },
        {
            label: 'Asignar responsable',
            icon: UserCheck,
            visible:
                data.permissions.canAssign &&
                data.incomplete_assignments.length > 0,
            action: () => setAssignTarget(data.incomplete_assignments[0]),
        },
        {
            label: 'Ver auditoría',
            icon: ShieldCheck,
            visible: data.permissions.canViewAudit,
            action: () => router.visit(scoped('/office/audit', officeQuery)),
        },
    ];
    const open = (href: string) => {
        if (href.startsWith('#')) {
            document
                .getElementById(href.slice(1))
                ?.scrollIntoView({ behavior: 'smooth' });

            return;
        }

        router.visit(scoped(href, officeQuery));
    };

    return (
        <>
            <Head title="Panel Administrador de Oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Administrador de Oficina"
                    subtitle={`Gestión operativa y administrativa · ${scopeName}`}
                    actions={
                        data.permissions.canInvite && (
                            <button
                                type="button"
                                onClick={() => setInviteOpen(true)}
                                className="inline-flex h-11 items-center gap-2 rounded-xl bg-gradient-to-r from-[#d9a441] to-[#e8bd62] px-5 text-sm font-black text-[#3b2400] shadow-[0_10px_24px_-14px_#8c5e00]"
                            >
                                <UserPlus className="size-4" /> Invitar usuario{' '}
                                <ChevronDown className="size-4" />
                            </button>
                        )
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    {kpis.map(({ key, label, icon: Icon, tone, href }) => {
                        const comparison = data.comparisons[key];

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => open(href)}
                                className={`${panel} p-3 text-left transition hover:border-[#c9a36a]`}
                            >
                                <div className="flex items-center gap-3">
                                    <span
                                        className={`grid size-11 shrink-0 place-items-center rounded-full ${kpiTone[tone]}`}
                                    >
                                        <Icon className="size-5" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="truncate text-xs font-semibold text-[#466287]">
                                            {label}
                                        </p>
                                        <p className="text-2xl font-black text-[#061633]">
                                            {data.metrics[key]}
                                        </p>
                                    </div>
                                </div>
                                <p
                                    className={`mt-2 text-xs font-bold ${comparison.tone === 'positive' ? 'text-emerald-700' : comparison.tone === 'negative' ? 'text-red-600' : 'text-[#526b92]'}`}
                                >
                                    {comparison.direction === 'up'
                                        ? '↑'
                                        : comparison.direction === 'down'
                                          ? '↓'
                                          : '→'}{' '}
                                    {comparison.label}
                                </p>
                            </button>
                        );
                    })}
                </section>

                <section className="grid gap-3 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,.8fr)_minmax(0,1.1fr)]">
                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            id="asignaciones"
                            title="Asignaciones incompletas"
                            action={
                                <Link
                                    href={scoped(
                                        '/office/assignments',
                                        officeQuery,
                                    )}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-2 py-2">Empresa</th>
                                        <th className="px-2 py-2">Falta</th>
                                        <th className="px-2 py-2">Prioridad</th>
                                        <th className="px-2 py-2">Acción</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.incomplete_assignments.map((row) => {
                                        const [label, tone] = priorityLabel[
                                            row.priority
                                        ] ?? ['Media', 'amber'];

                                        return (
                                            <tr
                                                key={`${row.company_id}-${row.missing_role}`}
                                            >
                                                <td className="px-2 py-2">
                                                    <p className="font-bold text-[#061633]">
                                                        {row.company_name}
                                                    </p>
                                                    <p className="text-[11px] text-[#526b92]">
                                                        NIT {row.tax_id}
                                                        {data.office === null
                                                            ? ` · ${row.office_name}`
                                                            : ''}
                                                    </p>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <p className="font-semibold">
                                                        {row.missing_label}
                                                    </p>
                                                    <p
                                                        className="text-[11px] text-[#526b92]"
                                                        title={row.reason}
                                                    >
                                                        {row.reason}
                                                    </p>
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
                                                <td className="px-2 py-2">
                                                    {data.permissions
                                                        .canAssign ? (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setAssignTarget(
                                                                    row,
                                                                )
                                                            }
                                                            className="rounded-lg border border-[#d9a441] px-3 py-1.5 font-bold text-[#8a5b00] hover:bg-amber-50"
                                                        >
                                                            Asignar
                                                            <span className="ml-1 font-normal text-[#8a6d3b]">
                                                                (
                                                                {
                                                                    row.compatible_users_count
                                                                }
                                                                )
                                                            </span>
                                                        </button>
                                                    ) : (
                                                        <span className="text-[#526b92]">
                                                            Sin permiso
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {data.incomplete_assignments.length ===
                                        0 && (
                                        <EmptyRow
                                            colSpan={4}
                                            text="Todas las empresas tienen responsables configurados."
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        id="equipo"
                        className={`${panel} min-w-0 scroll-mt-24 p-3`}
                    >
                        <SectionHeader
                            title="Estado del equipo"
                            action={
                                <Link
                                    href={scoped('/office/team', officeQuery)}
                                    className={chip}
                                >
                                    Ver detalle
                                </Link>
                            }
                        />
                        <div className="grid grid-cols-2 gap-2.5">
                            {teamCards.map(
                                ({ label, value, icon: Icon, tone, href }) => (
                                    <button
                                        key={label}
                                        type="button"
                                        onClick={() => open(href)}
                                        className={`rounded-xl p-3 text-left transition hover:brightness-95 ${tone}`}
                                    >
                                        <div className="flex items-center justify-between">
                                            <Icon className="size-5" />
                                            <ArrowRight className="size-4 opacity-60" />
                                        </div>
                                        <p className="mt-2 text-2xl font-black">
                                            {value ?? 'N/D'}
                                        </p>
                                        <p className="text-xs leading-tight font-semibold">
                                            {label}
                                        </p>
                                    </button>
                                ),
                            )}
                        </div>
                        <p className="mt-2 text-xs text-[#526b92]">
                            {data.team_status.active_users} usuario(s) activos
                            en {scopeName}.
                        </p>
                    </div>

                    <div
                        id="carga"
                        className={`${panel} min-w-0 scroll-mt-24 p-3`}
                    >
                        <SectionHeader
                            title="Carga del equipo"
                            action={
                                <Link
                                    href={scoped(
                                        '/office/team-workload',
                                        officeQuery,
                                    )}
                                    className={chip}
                                >
                                    Ver carga
                                </Link>
                            }
                        />
                        {data.team_workload === null ? (
                            <p className="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                                {data.workload_error ??
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
                                                Tareas
                                            </th>
                                            <th className="px-2 py-2">Carga</th>
                                            <th className="px-2 py-2">
                                                Estado
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {data.team_workload.map((row) => {
                                            const open = Object.entries(
                                                row.tasks,
                                            )
                                                .filter(
                                                    ([key]) =>
                                                        ![
                                                            'overdue',
                                                            'completed_month',
                                                        ].includes(key),
                                                )
                                                .reduce(
                                                    (sum, [, value]) =>
                                                        sum + value,
                                                    0,
                                                );

                                            return (
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
                                                    <td className="px-2 py-2 text-[#395277]">
                                                        {row.role_label}
                                                    </td>
                                                    <td className="px-2 py-2 text-right font-black">
                                                        {row.assigned_companies}
                                                    </td>
                                                    <td className="px-2 py-2 text-right font-black">
                                                        {open}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="w-8 font-black">
                                                                {
                                                                    row.utilization_percentage
                                                                }
                                                                %
                                                            </span>
                                                            <div className="h-2 w-14 overflow-hidden rounded bg-slate-100">
                                                                <div
                                                                    className={`h-full ${row.utilization_percentage >= 85 ? 'bg-red-500' : row.utilization_percentage >= 65 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                                                    style={{
                                                                        width: `${row.utilization_percentage}%`,
                                                                    }}
                                                                />
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <span
                                                            className={badge(
                                                                row.status ===
                                                                    'Alta'
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
                                            );
                                        })}
                                        {data.team_workload.length === 0 && (
                                            <EmptyRow
                                                colSpan={6}
                                                text="No existen usuarios activos en esta oficina."
                                            />
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </section>

                <section className="grid gap-3 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)_minmax(220px,.5fr)]">
                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Invitaciones pendientes"
                            action={
                                <Link
                                    href={scoped(
                                        '/office/users#invitaciones',
                                        officeQuery,
                                    )}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-2 py-2">
                                            Correo electrónico
                                        </th>
                                        <th className="px-2 py-2">Rol</th>
                                        <th className="px-2 py-2">Oficina</th>
                                        <th className="px-2 py-2">Enviado</th>
                                        <th className="px-2 py-2">Estado</th>
                                        <th className="px-2 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.invitations.map((invitation) => (
                                        <tr key={invitation.public_id}>
                                            <td className="px-2 py-2">
                                                {invitation.email}
                                            </td>
                                            <td className="px-2 py-2">
                                                {invitation.role_name}
                                            </td>
                                            <td className="px-2 py-2">
                                                {invitation.office_name}
                                            </td>
                                            <td className="px-2 py-2">
                                                {formatDate(invitation.sent_at)}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        invitation.status ===
                                                            'expired'
                                                            ? 'red'
                                                            : 'amber',
                                                    )}
                                                >
                                                    {invitation.status ===
                                                    'expired'
                                                        ? 'Vencida'
                                                        : 'Pendiente'}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2">
                                                {data.permissions.canInvite && (
                                                    <div className="flex gap-1.5">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/office/invitations/${invitation.public_id}/resend`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2 py-1 font-bold"
                                                        >
                                                            <RefreshCcw className="size-3" />{' '}
                                                            Reenviar
                                                        </button>
                                                        <button
                                                            type="button"
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
                                                            className="inline-flex items-center gap-1 rounded-lg border border-red-200 px-2 py-1 font-bold text-red-700"
                                                        >
                                                            <X className="size-3" />{' '}
                                                            Cancelar
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {data.invitations.length === 0 && (
                                        <EmptyRow
                                            colSpan={6}
                                            text="No existen invitaciones pendientes."
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Actividad administrativa reciente"
                            action={
                                <Link
                                    href={scoped('/office/audit', officeQuery)}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <ul className="space-y-2.5">
                            {data.recent_activity.map((event) => {
                                const content = (
                                    <>
                                        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-blue-50 text-blue-700">
                                            <Users className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1 text-sm leading-snug text-[#061633]">
                                            {event.description}
                                        </span>
                                        <span className="shrink-0 text-[11px] text-[#526b92]">
                                            {relativeTime(event.created_at)}
                                        </span>
                                    </>
                                );

                                return (
                                    <li key={event.event_id}>
                                        {event.destination ? (
                                            <Link
                                                href={event.destination}
                                                className="flex items-start gap-2.5 rounded-lg p-1 hover:bg-slate-50"
                                            >
                                                {content}
                                            </Link>
                                        ) : (
                                            <div className="flex items-start gap-2.5 p-1">
                                                {content}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                            {data.recent_activity.length === 0 && (
                                <li className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">
                                    No hay actividad administrativa reciente.
                                </li>
                            )}
                        </ul>
                    </div>

                    <div className={`${panel} min-w-0 p-3`}>
                        <h2 className="mb-3 text-lg font-black">
                            Acciones rápidas
                        </h2>
                        <div className="space-y-2">
                            {quickActions
                                .filter((item) => item.visible)
                                .map(({ label, icon: Icon, action }) => (
                                    <button
                                        key={label}
                                        type="button"
                                        onClick={action}
                                        className="flex w-full items-center justify-between rounded-xl border border-slate-200 px-3 py-2.5 text-left text-sm font-bold hover:border-[#c9a36a]"
                                    >
                                        <span className="flex items-center gap-2">
                                            <Icon className="size-4 text-[#365173]" />
                                            {label}
                                        </span>
                                        <ArrowRight className="size-4 text-[#526b92]" />
                                    </button>
                                ))}
                        </div>
                    </div>
                </section>

                <section className={`${panel} p-3`}>
                    <SectionHeader
                        title="Resumen de oficinas"
                        action={
                            <Link href="/office/offices" className={chip}>
                                Ver todas
                            </Link>
                        }
                    />
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Oficina</th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2 text-right">
                                        Usuarios
                                    </th>
                                    <th className="px-2 py-2 text-right">
                                        Empresas
                                    </th>
                                    <th className="px-2 py-2 text-right">
                                        Asignaciones pendientes
                                    </th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.office_summaries.map((office) => (
                                    <tr key={office.id}>
                                        <td className="px-2 py-2.5">
                                            <p className="flex items-center gap-1.5 font-bold">
                                                <MapPin className="size-3.5 text-[#b0661b]" />
                                                {office.name}
                                            </p>
                                            <p className="pl-5 text-[11px] text-[#526b92]">
                                                {office.location ??
                                                    'Sin ubicación'}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2.5">
                                            <p className="font-semibold">
                                                {office.responsible ?? (
                                                    <span className="text-amber-700">
                                                        Sin administrador
                                                    </span>
                                                )}
                                            </p>
                                            {office.responsible_role && (
                                                <p className="text-[11px] text-[#526b92]">
                                                    {office.responsible_role}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-2 py-2.5 text-right font-black">
                                            {office.users}
                                        </td>
                                        <td className="px-2 py-2.5 text-right font-black">
                                            {office.companies}
                                        </td>
                                        <td
                                            className={`px-2 py-2.5 text-right font-black ${office.pending_assignments > 0 ? 'text-red-700' : ''}`}
                                        >
                                            {office.pending_assignments}
                                        </td>
                                        <td className="px-2 py-2.5">
                                            <span
                                                className={badge(
                                                    office.status === 'active'
                                                        ? 'green'
                                                        : 'slate',
                                                )}
                                            >
                                                {office.status === 'active'
                                                    ? 'Activa'
                                                    : 'Inactiva'}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2.5 text-right">
                                            <Link
                                                href={`/office/offices/${office.id}`}
                                                className="font-bold text-[#640000]"
                                            >
                                                Detalle
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </Shell>

            <AssignDialog
                target={assignTarget}
                onClose={() => setAssignTarget(null)}
            />
            <InviteDialog
                open={inviteOpen}
                onClose={() => setInviteOpen(false)}
                scope={data.scope}
                options={data.form_options}
            />
            <NewCompanyDialog
                open={companyOpen}
                onClose={() => setCompanyOpen(false)}
                scope={data.scope}
            />
        </>
    );
}
