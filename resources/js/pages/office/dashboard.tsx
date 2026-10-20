import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BriefcaseBusiness,
    Building2,
    ChevronDown,
    ChevronRight,
    FileWarning,
    Mail,
    Plus,
    ShieldAlert,
    ShieldCheck,
    UserCheck,
    UserPlus,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    AssignDialog,
    InviteDialog,
    NewCompanyDialog,
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    priorityLabel,
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
    const [menuOpen, setMenuOpen] = useState(false);
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
            href: '/office/invitations',
        },
    ];
    const teamRows: {
        label: string;
        value: number | null;
        icon: LucideIcon;
        tone: string;
        href: string;
    }[] = [
        {
            label: 'Con alta carga',
            value: data.team_status.high_workload,
            icon: AlertTriangle,
            tone: 'bg-amber-50 text-amber-600',
            href: '/office/team-workload',
        },
        {
            label: 'Sin empresa asignada',
            value: data.team_status.without_company,
            icon: Users,
            tone: 'bg-slate-100 text-slate-600',
            href: '/office/team',
        },
        {
            label: '2FA pendiente',
            value: data.team_status.pending_2fa,
            icon: ShieldAlert,
            tone: 'bg-red-50 text-red-700',
            href: '/office/security',
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
    const visibleActions = quickActions.filter((item) => item.visible);
    const shortcuts: { label: string; icon: LucideIcon; href: string }[] = [
        { label: 'Empresas', icon: Building2, href: '/office/companies' },
        { label: 'Usuarios', icon: Users, href: '/office/users' },
        {
            label: 'Asignar responsables',
            icon: UserCheck,
            href: '/office/assignments',
        },
        { label: 'Auditoría', icon: ShieldCheck, href: '/office/audit' },
    ];
    const open = (href: string) => router.visit(scoped(href, officeQuery));
    const priorities = data.incomplete_assignments.slice(0, 5);

    return (
        <>
            <Head title="Panel Administrador de Oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Administrador de Oficina"
                    subtitle={`${scopeName} · Gestión operativa`}
                    actions={
                        visibleActions.length > 0 && (
                            <div className="relative">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setMenuOpen((value) => !value)
                                    }
                                    aria-expanded={menuOpen}
                                    className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#8a0b0b] px-5 text-sm font-bold text-white shadow-md hover:brightness-110"
                                >
                                    <Plus className="size-4" /> Nueva acción{' '}
                                    <ChevronDown className="size-4" />
                                </button>
                                {menuOpen && (
                                    <>
                                        <button
                                            type="button"
                                            aria-label="Cerrar menú"
                                            className="fixed inset-0 z-10 cursor-default"
                                            onClick={() => setMenuOpen(false)}
                                        />
                                        <ul className="absolute right-0 z-20 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                                            {visibleActions.map(
                                                ({
                                                    label,
                                                    icon: Icon,
                                                    action,
                                                }) => (
                                                    <li key={label}>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setMenuOpen(
                                                                    false,
                                                                );
                                                                action();
                                                            }}
                                                            className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm font-semibold hover:bg-amber-50"
                                                        >
                                                            <Icon className="size-4 text-[#8a0b0b]" />
                                                            {label}
                                                        </button>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </>
                                )}
                            </div>
                        )
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {kpis.map(({ key, label, icon: Icon, tone, href }) => {
                        const comparison = data.comparisons[key];

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => open(href)}
                                className={`${panel} flex items-center gap-4 p-4 text-left transition hover:border-[#c9a36a]`}
                            >
                                <span
                                    className={`grid size-14 shrink-0 place-items-center rounded-full ${kpiTone[tone]}`}
                                >
                                    <Icon className="size-6" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-[#466287]">
                                        {label}
                                    </span>
                                    <span className="block text-3xl font-black text-[#061633]">
                                        {data.metrics[key]}
                                    </span>
                                    <span
                                        className={`block text-xs font-bold ${comparison.tone === 'positive' ? 'text-emerald-700' : comparison.tone === 'negative' ? 'text-red-600' : 'text-[#526b92]'}`}
                                    >
                                        {comparison.direction === 'up'
                                            ? '↑'
                                            : comparison.direction === 'down'
                                              ? '↓'
                                              : '→'}{' '}
                                        {comparison.label}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </section>

                <section className="grid gap-3 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <div id="asignaciones" className={`${panel} min-w-0 p-4`}>
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="flex items-center gap-3 text-xl font-black text-[#061633]">
                                Prioridades de la oficina
                                {data.incomplete_assignments.length > 0 && (
                                    <span className="rounded-full bg-[#fbe9e9] px-3 py-1 text-xs font-bold text-[#8a0b0b]">
                                        {data.incomplete_assignments.length}{' '}
                                        pendiente
                                        {data.incomplete_assignments.length ===
                                        1
                                            ? ''
                                            : 's'}
                                    </span>
                                )}
                            </h2>
                            <Link
                                href={scoped(
                                    '/office/assignments',
                                    officeQuery,
                                )}
                                className="inline-flex items-center gap-1.5 text-sm font-bold text-[#8a0b0b]"
                            >
                                Ver asignaciones{' '}
                                <ArrowRight className="size-4" />
                            </Link>
                        </div>
                        <ul className="divide-y divide-slate-100">
                            {priorities.map((row) => {
                                const [label, tone] = priorityLabel[
                                    row.priority
                                ] ?? ['Media', 'amber'];

                                return (
                                    <li
                                        key={`${row.company_id}-${row.missing_role}`}
                                        className="flex flex-wrap items-center gap-3 py-3"
                                    >
                                        <span className="grid size-11 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-600">
                                            <Building2 className="size-5" />
                                        </span>
                                        <span className="min-w-40 flex-1">
                                            <span className="block font-bold text-[#061633]">
                                                {row.company_name}
                                            </span>
                                            {data.office === null && (
                                                <span className="block text-xs text-[#526b92]">
                                                    {row.office_name}
                                                </span>
                                            )}
                                        </span>
                                        <span
                                            className="min-w-36 text-sm text-[#061633]"
                                            title={row.reason}
                                        >
                                            {row.missing_label}
                                        </span>
                                        <span className={badge(tone as Tone)}>
                                            {label}
                                        </span>
                                        {data.permissions.canAssign ? (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setAssignTarget(row)
                                                }
                                                className="h-10 rounded-xl bg-[#8a0b0b] px-6 text-sm font-bold text-white hover:brightness-110"
                                            >
                                                Asignar
                                            </button>
                                        ) : (
                                            <span className="text-xs text-[#526b92]">
                                                Sin permiso
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                            {priorities.length === 0 && (
                                <li className="rounded-xl bg-emerald-50 p-4 text-center text-sm font-semibold text-emerald-800">
                                    Todas las empresas tienen responsables
                                    configurados.
                                </li>
                            )}
                        </ul>
                    </div>

                    <div id="equipo" className={`${panel} min-w-0 p-4`}>
                        <h2 className="mb-3 text-xl font-black text-[#061633]">
                            Estado del equipo
                        </h2>
                        <div className="space-y-2.5">
                            {teamRows.map(
                                ({ label, value, icon: Icon, tone, href }) => (
                                    <button
                                        key={label}
                                        type="button"
                                        onClick={() => open(href)}
                                        className="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-3 text-left transition hover:border-[#c9a36a]"
                                    >
                                        <span
                                            className={`grid size-10 shrink-0 place-items-center rounded-full ${tone}`}
                                        >
                                            <Icon className="size-5" />
                                        </span>
                                        <span className="flex-1 font-medium text-[#061633]">
                                            {label}
                                        </span>
                                        <span
                                            className={`text-2xl font-black ${tone.split(' ').find((c) => c.startsWith('text-')) ?? ''}`}
                                        >
                                            {value ?? 'N/D'}
                                        </span>
                                        <ChevronRight className="size-4 text-slate-400" />
                                    </button>
                                ),
                            )}
                        </div>
                        <Link
                            href={scoped('/office/team-workload', officeQuery)}
                            className="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-[#8a0b0b]"
                        >
                            Ver carga de trabajo{' '}
                            <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </section>

                <section className="grid gap-3 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <div className={`${panel} min-w-0 p-4`}>
                        <h2 className="mb-3 text-xl font-black text-[#061633]">
                            Accesos rápidos
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {shortcuts.map(({ label, icon: Icon, href }) => (
                                <Link
                                    key={label}
                                    href={scoped(href, officeQuery)}
                                    className="flex flex-col items-center gap-3 rounded-xl border border-slate-200 px-3 py-5 text-center text-sm font-semibold transition hover:border-[#c9a36a]"
                                >
                                    <span className="grid size-14 place-items-center rounded-full bg-[#fbe9e9] text-[#8a0b0b]">
                                        <Icon className="size-7" />
                                    </span>
                                    {label}
                                </Link>
                            ))}
                        </div>
                    </div>

                    <div className={`${panel} min-w-0 p-4`}>
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <h2 className="text-xl font-black text-[#061633]">
                                Actividad reciente
                            </h2>
                            <Link
                                href={scoped('/office/audit', officeQuery)}
                                className="inline-flex items-center gap-1.5 text-sm font-bold text-[#8a0b0b]"
                            >
                                Ver historial <ArrowRight className="size-4" />
                            </Link>
                        </div>
                        <ul className="divide-y divide-slate-100">
                            {data.recent_activity.slice(0, 4).map((event) => {
                                const content = (
                                    <>
                                        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-blue-50 text-blue-700">
                                            <UserCheck className="size-5" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm leading-snug font-semibold text-[#061633]">
                                                {event.description}
                                            </span>
                                            <span className="block text-xs text-[#526b92]">
                                                {formatDate(
                                                    event.created_at,
                                                    true,
                                                )}
                                            </span>
                                        </span>
                                    </>
                                );

                                return (
                                    <li key={event.event_id}>
                                        {event.destination ? (
                                            <Link
                                                href={event.destination}
                                                className="flex items-center gap-3 py-2.5 hover:bg-slate-50"
                                            >
                                                {content}
                                            </Link>
                                        ) : (
                                            <div className="flex items-center gap-3 py-2.5">
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
                </section>

                <section className={`${panel} p-4`}>
                    <h2 className="mb-3 text-xl font-black text-[#061633]">
                        Resumen de la oficina
                    </h2>
                    <ul className="divide-y divide-slate-100">
                        {data.office_summaries.map((office) => (
                            <li
                                key={office.id}
                                className="flex flex-wrap items-center gap-x-6 gap-y-2 py-3"
                            >
                                <span className="flex min-w-56 items-center gap-3">
                                    <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-600">
                                        <Building2 className="size-6" />
                                    </span>
                                    <span>
                                        <span className="block font-bold text-[#061633]">
                                            {office.name}
                                        </span>
                                        <span className="block text-xs text-[#526b92]">
                                            {office.location ?? 'Sin ubicación'}
                                        </span>
                                    </span>
                                </span>
                                <span className="min-w-48 flex-1 text-sm">
                                    <span className="block font-semibold">
                                        {office.responsible ?? (
                                            <span className="text-amber-700">
                                                Sin administrador
                                            </span>
                                        )}
                                    </span>
                                    {office.responsible_role && (
                                        <span className="block text-xs text-[#526b92]">
                                            {office.responsible_role}
                                        </span>
                                    )}
                                </span>
                                <span className="text-xs text-[#526b92]">
                                    {office.users} usuarios · {office.companies}{' '}
                                    empresas
                                    {office.pending_assignments > 0 && (
                                        <b className="text-red-700">
                                            {' '}
                                            · {office.pending_assignments}{' '}
                                            pendientes
                                        </b>
                                    )}
                                </span>
                                <span
                                    className={`inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold ${office.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}
                                >
                                    <span className="size-2.5 rounded-full bg-current" />
                                    {office.status === 'active'
                                        ? 'Activa'
                                        : 'Inactiva'}
                                </span>
                                <Link
                                    href={`/office/offices/${office.id}`}
                                    className="inline-flex items-center gap-1.5 text-sm font-bold text-[#8a0b0b]"
                                >
                                    Ver oficina{' '}
                                    <ArrowRight className="size-4" />
                                </Link>
                            </li>
                        ))}
                    </ul>
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
