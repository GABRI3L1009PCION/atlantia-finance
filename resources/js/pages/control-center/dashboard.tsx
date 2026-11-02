import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Bot,
    Building2,
    CalendarClock,
    ChevronRight,
    Coins,
    ExternalLink,
    Layers,
    Monitor,
    PlusCircle,
    Server,
    Settings,
    UserPlus,
    Zap,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Pill,
    ago,
    healthTone,
    isUnavailable,
    money,
    useCan,
} from '@/components/control-center/kit';

type Service = {
    key: string;
    name: string;
    status: string;
    label: string;
    latency: number | null;
    uptime: number | null;
    last_check: string | null;
    latest_error: string | null;
    history: number[];
    destination: string;
};
type Attention = {
    key: string;
    severity: string;
    count: number;
    title: string;
    subtitle: string;
    destination: string;
};
type LuzmaStatus = {
    status: string;
    model: string | null;
    latency_ms: number | null;
    detail: string | null;
    requests_today: number;
    errors_today: number;
    average_latency_ms: number | null;
    uptime_24h: number | null;
    gpu: string | null;
    vram: string | null;
};
type Mrr = {
    mrr: number;
    arr: number;
    currency: string;
    subscriptions: number;
    previous_mrr: number;
    change_percent: number | null;
};
type Source<T> = T | { unavailable: true; message: string };
type Metrics = {
    organizations_active: number;
    organizations_active_previous: number;
    trials_active: number;
    subscriptions_active: number;
    past_due_subscriptions: number;
    suspended_organizations: number;
    monthly_recurring_revenue: Source<Mrr> | null;
    failed_jobs: Source<number>;
    critical_incidents: number;
    support_requests: number;
    service_health: Source<{
        healthy: number;
        total: number;
        checked: number;
        uptime: number | null;
    }>;
    sparklines: Record<string, number[]>;
};

type Props = {
    dashboard: {
        metrics: Metrics;
        platform_health: Source<Service[]>;
        attention_items: Source<Attention[]>;
        luzma_status: Source<LuzmaStatus> | null;
        last_updated_at: string;
    };
};

const MAROON = '#7a0010';
const GOLD = '#e0a526';
const VISIBLE_SERVICES = 5;

const luzmaLabel: Record<string, { text: string; color: string }> = {
    healthy: { text: 'Saludable', color: 'text-[#16a34a]' },
    degraded: { text: 'Degradada', color: 'text-amber-600' },
    down: { text: 'Caída', color: 'text-red-600' },
    disabled: { text: 'Apagada', color: 'text-slate-500' },
};

/** A white card with the maroon title bar of the design. */
function Card({
    title,
    icon,
    action,
    children,
}: {
    title: string;
    icon: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]">
            <header className="flex items-center gap-2.5 bg-[#6b0000] px-4 py-2.5 text-white">
                {icon}
                <h2 className="flex-1 truncate text-[15px] font-bold">
                    {title}
                </h2>
                {action}
            </header>
            <div className="min-h-0 flex-1 p-4">{children}</div>
        </section>
    );
}

/** Share of services that are healthy, drawn as a gold ring with the rest in maroon. */
function Compliance({ services }: { services: Service[] }) {
    const total = services.length;
    const healthy = services.filter(
        (service) => service.status === 'healthy',
    ).length;
    const operative = total === 0 ? 0 : Math.round((healthy * 100) / total);
    const radius = 52;
    const circumference = 2 * Math.PI * radius;

    if (total === 0) {
        return (
            <p className="py-10 text-center text-sm text-slate-500">
                Aún no hay chequeos de servicios.
            </p>
        );
    }

    return (
        <div className="flex h-full items-center justify-center gap-6">
            <div className="relative size-36 shrink-0">
                <svg viewBox="0 0 128 128" className="size-full -rotate-90">
                    <circle
                        cx="64"
                        cy="64"
                        r={radius}
                        fill="none"
                        stroke={MAROON}
                        strokeWidth="14"
                    />
                    <circle
                        cx="64"
                        cy="64"
                        r={radius}
                        fill="none"
                        stroke={GOLD}
                        strokeWidth="14"
                        strokeDasharray={`${(circumference * operative) / 100} ${circumference}`}
                    />
                </svg>
                <span className="absolute inset-0 grid place-items-center text-3xl font-black text-slate-900">
                    {operative}%
                </span>
            </div>
            <ul className="space-y-4 text-sm">
                <li className="flex items-start gap-3">
                    <span
                        className="mt-0.5 size-4 rounded"
                        style={{ background: GOLD }}
                    />
                    <span>
                        <span className="block font-semibold text-slate-800">
                            Operativo
                        </span>
                        <span className="text-lg text-slate-700">
                            {operative}%
                        </span>
                    </span>
                </li>
                <li className="flex items-start gap-3">
                    <span
                        className="mt-0.5 size-4 rounded"
                        style={{ background: MAROON }}
                    />
                    <span>
                        <span className="block font-semibold text-slate-800">
                            Con incidencias
                        </span>
                        <span className="text-lg text-slate-700">
                            {100 - operative}%
                        </span>
                    </span>
                </li>
            </ul>
        </div>
    );
}

export default function ControlCenterDashboard({ dashboard }: Props) {
    const can = useCan();
    const m = dashboard.metrics;
    const mrr = m.monthly_recurring_revenue;
    const health = m.service_health;
    const services = dashboard.platform_health;
    const attention = dashboard.attention_items;
    const luzma = dashboard.luzma_status;
    const pending = isUnavailable(attention)
        ? 0
        : attention.reduce((sum, item) => sum + Math.max(1, item.count), 0);
    const lastCheck = isUnavailable(services)
        ? null
        : (services
              .map((service) => service.last_check)
              .filter((value): value is string => value !== null)
              .sort()
              .reverse()[0] ?? null);

    const kpis = [
        {
            label: 'Organizaciones activas',
            value: m.organizations_active,
            icon: Building2,
            tile: MAROON,
            href: '/control-center/organizations',
        },
        {
            label: 'MRR',
            value: mrr === null || isUnavailable(mrr) ? '—' : money(mrr.mrr),
            icon: Coins,
            tile: GOLD,
            href: '/control-center/billing',
        },
        {
            label: 'Suscripciones activas',
            value: m.subscriptions_active,
            icon: Layers,
            tile: MAROON,
            href: '/control-center/subscriptions',
        },
        {
            label: 'Pagos vencidos',
            value: m.past_due_subscriptions,
            icon: CalendarClock,
            tile: MAROON,
            href: '/control-center/subscriptions?status=past_due',
        },
        {
            label: 'Jobs fallidos',
            value: isUnavailable(m.failed_jobs) ? '—' : m.failed_jobs,
            icon: Settings,
            tile: MAROON,
            href: '/control-center/jobs',
        },
        {
            label: 'Incidentes críticos',
            value: m.critical_incidents,
            icon: AlertTriangle,
            tile: MAROON,
            href: '/control-center/incidents',
        },
    ];

    const shortcuts = [
        {
            label: 'Nueva organización',
            icon: PlusCircle,
            href: '/control-center/organizations',
            permission: 'saas.organizations.view',
            primary: true,
        },
        {
            label: 'Crear usuario',
            icon: UserPlus,
            href: '/control-center/saas-users',
            permission: 'saas.users.view',
        },
        {
            label: 'Asignar plan',
            icon: Layers,
            href: '/control-center/subscriptions',
            permission: 'saas.subscriptions.view',
        },
        {
            label: 'Ver reportes',
            icon: BarChart3,
            href: '/control-center/usage',
            permission: 'saas.usage.view',
        },
    ].filter((shortcut) => can(shortcut.permission));

    return (
        <>
            <Head title="Atlantia Control Center" />
            <div className="space-y-4">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0 border-l-4 border-[#e0a526] pl-5">
                        <div className="flex flex-wrap items-center gap-3">
                            <p className="text-[13px] font-bold tracking-[0.06em] text-slate-800 uppercase">
                                Vista global de la plataforma
                            </p>
                            {pending > 0 && (
                                <Link
                                    href={
                                        isUnavailable(attention)
                                            ? '/control-center'
                                            : (attention[0]?.destination ??
                                              '/control-center')
                                    }
                                    className="inline-flex items-center gap-1.5 rounded-full bg-[#c8102e]/10 px-2.5 py-0.5 text-xs font-semibold text-[#b91c1c] ring-1 ring-[#c8102e]/25 hover:bg-[#c8102e]/15"
                                >
                                    <AlertTriangle className="size-3.5" />
                                    {pending} asunto
                                    {pending === 1 ? '' : 's'} requiere
                                    {pending === 1 ? '' : 'n'} atención
                                    <ChevronRight className="size-3.5" />
                                </Link>
                            )}
                        </div>
                        <h1 className="mt-1 font-serif text-4xl font-bold text-slate-900 min-[1500px]:text-5xl">
                            Atlantia{' '}
                            <span className="text-[#7a0010]">
                                Control Center
                            </span>
                        </h1>
                        <p className="mt-1 text-xl font-semibold text-slate-800">
                            Panel Superadministrador SaaS
                        </p>
                        <p className="mt-1 text-sm text-slate-600">
                            Monitorea, gestiona y haz crecer todo el ecosistema
                            de Atlantia Finance desde un solo lugar.
                        </p>
                    </div>
                    <div className="shrink-0 rounded-2xl border border-slate-200 bg-white px-5 py-3 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]">
                        <p className="text-[13px] font-bold tracking-[0.12em] text-slate-800 uppercase">
                            Plataforma SaaS
                        </p>
                        <div className="mt-2 grid grid-cols-3 divide-x divide-slate-200 text-center">
                            {[
                                {
                                    icon: Building2,
                                    value:
                                        m.organizations_active +
                                        m.trials_active,
                                    label: 'Organizaciones',
                                },
                                {
                                    icon: Layers,
                                    value: m.subscriptions_active,
                                    label: 'Suscripciones',
                                },
                                {
                                    icon: Monitor,
                                    value:
                                        isUnavailable(health) ||
                                        health.uptime === null
                                            ? '—'
                                            : `${health.uptime}%`,
                                    label: 'Uptime (24 h)',
                                },
                            ].map((stat) => (
                                <div
                                    key={stat.label}
                                    className="px-3 min-[1500px]:px-5"
                                >
                                    <stat.icon className="mx-auto size-7 text-[#7a0010]" />
                                    <p className="mt-1 text-2xl font-black text-slate-900">
                                        {stat.value}
                                    </p>
                                    <p className="text-xs text-slate-600">
                                        {stat.label}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {kpis.map((kpi) => (
                        <Link
                            key={kpi.label}
                            href={kpi.href}
                            className="flex items-center gap-2.5 rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)] transition hover:-translate-y-0.5 hover:border-[#7a0010]/30"
                        >
                            <span
                                className="grid size-11 shrink-0 place-items-center rounded-xl text-white"
                                style={{ background: kpi.tile }}
                            >
                                <kpi.icon className="size-[22px]" />
                            </span>
                            <span className="h-10 w-0.5 shrink-0 rounded bg-[#e0a526]" />
                            <span className="min-w-0">
                                <span className="block text-[13px] leading-tight font-semibold text-slate-700">
                                    {kpi.label}
                                </span>
                                <span className="block text-lg leading-tight font-black whitespace-nowrap text-slate-900 min-[1440px]:text-xl min-[1700px]:text-2xl">
                                    {kpi.value}
                                </span>
                            </span>
                        </Link>
                    ))}
                </section>

                <section className="grid gap-3 lg:grid-cols-3">
                    <Card
                        title="Estado de servicios"
                        icon={<Server className="size-5" />}
                        action={
                            <Link
                                href="/control-center/services"
                                className="text-[11px] text-white/80 hover:text-white"
                            >
                                <span className="hidden min-[1500px]:inline">
                                    Última actualización:{' '}
                                </span>
                                {ago(lastCheck)}
                            </Link>
                        }
                    >
                        {isUnavailable(services) ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                Estado no disponible.
                            </p>
                        ) : (
                            <>
                                <ul className="divide-y divide-slate-100">
                                    {services
                                        .slice(0, VISIBLE_SERVICES)
                                        .map((service) => (
                                            <li key={service.key}>
                                                <Link
                                                    href={service.destination}
                                                    className="flex items-center gap-3 py-1.5 text-sm"
                                                    title={
                                                        service.latest_error ??
                                                        undefined
                                                    }
                                                >
                                                    <span className="size-1.5 rounded-full bg-slate-800" />
                                                    <span className="flex-1 truncate font-medium text-slate-800">
                                                        {service.name}
                                                    </span>
                                                    <Pill
                                                        tone={
                                                            healthTone[
                                                                service.status
                                                            ]
                                                        }
                                                    >
                                                        {service.label}
                                                    </Pill>
                                                    <ChevronRight className="size-4 text-slate-500" />
                                                </Link>
                                            </li>
                                        ))}
                                </ul>
                                {services.length > VISIBLE_SERVICES && (
                                    <Link
                                        href="/control-center/services"
                                        className="mt-1 inline-block text-xs font-semibold text-[#7a0010] hover:underline"
                                    >
                                        Ver los {services.length} servicios
                                    </Link>
                                )}
                            </>
                        )}
                    </Card>

                    <Card
                        title="Cumplimiento del sistema"
                        icon={<BarChart3 className="size-5" />}
                    >
                        {isUnavailable(services) ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                No disponible.
                            </p>
                        ) : (
                            <Compliance services={services} />
                        )}
                    </Card>

                    <Card
                        title="Luzma AI"
                        icon={<Bot className="size-5" />}
                        action={
                            <Link
                                href="/control-center/luzma"
                                className="inline-flex items-center gap-1.5 rounded-lg bg-[#e0a526] px-3 py-1 text-xs font-bold text-[#3b0000] hover:brightness-105"
                            >
                                Abrir consola{' '}
                                <ExternalLink className="size-3.5" />
                            </Link>
                        }
                    >
                        {luzma === null ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                Tu rol no incluye el estado de Luzma.
                            </p>
                        ) : isUnavailable(luzma) ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                Estado de Luzma no disponible.
                            </p>
                        ) : (
                            <div className="flex h-full items-center gap-5">
                                <span className="grid size-16 shrink-0 place-items-center rounded-2xl bg-[#7a0010]/10 text-[#7a0010] min-[1500px]:size-20">
                                    <Bot className="size-9 min-[1500px]:size-10" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="grid grid-cols-2">
                                        <div className="pr-4">
                                            <p className="text-sm text-slate-600">
                                                Estado
                                            </p>
                                            <p
                                                className={`text-xl font-bold ${(luzmaLabel[luzma.status] ?? luzmaLabel.disabled).color}`}
                                            >
                                                {
                                                    (
                                                        luzmaLabel[
                                                            luzma.status
                                                        ] ?? {
                                                            text: luzma.status,
                                                        }
                                                    ).text
                                                }
                                            </p>
                                        </div>
                                        <div className="min-w-0 border-l border-slate-200 pl-4">
                                            <p className="text-sm text-slate-600">
                                                Modelo
                                            </p>
                                            <p className="text-base leading-tight font-bold break-words text-slate-900 min-[1500px]:text-xl">
                                                {luzma.model ?? '—'}
                                            </p>
                                        </div>
                                    </div>
                                    <p className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
                                        {luzma.requests_today} consultas hoy ·{' '}
                                        {luzma.errors_today} errores
                                    </p>
                                </div>
                            </div>
                        )}
                    </Card>
                </section>

                {shortcuts.length > 0 && (
                    <section className="flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-3.5 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]">
                        <h2 className="flex items-center gap-2 text-xl font-bold text-slate-900">
                            <Zap className="size-6 text-[#e0a526]" /> Accesos
                            rápidos
                        </h2>
                        <span className="hidden h-8 w-0.5 rounded bg-[#e0a526] md:block" />
                        <div className="grid flex-1 grid-cols-2 gap-3 lg:grid-cols-4">
                            {shortcuts.map((shortcut) => (
                                <Link
                                    key={shortcut.label}
                                    href={shortcut.href}
                                    className={`inline-flex h-11 items-center justify-center gap-2 rounded-xl border-2 px-3 text-sm font-bold whitespace-nowrap transition ${shortcut.primary ? 'border-[#7a0010] bg-[#7a0010] text-white hover:bg-[#8f0b20]' : 'border-[#7a0010]/70 bg-white text-[#7a0010] hover:bg-[#7a0010]/5'}`}
                                >
                                    <shortcut.icon className="size-5" />
                                    {shortcut.label}
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
