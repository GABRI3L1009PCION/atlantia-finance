import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeDollarSign,
    Bot,
    Building2,
    ChevronRight,
    Coins,
    Cpu,
    Loader2,
    Send,
    Settings2,
    Siren,
} from 'lucide-react';
import { useState } from 'react';
import {
    Bars,
    Pill,
    Progress,
    Sparkline,
    Table,
    Unavailable,
    ago,
    bytes,
    date,
    healthTone,
    isUnavailable,
    money,
    panel,
    statusTone,
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
type Organization = {
    id: number;
    name: string;
    plan: string | null;
    subscription_status: string | null;
    subscription_label: string;
    organization_status: string;
    organization_label: string;
    renewal_date: string | null;
    destination: string;
};
type Activity = {
    action: string;
    actor: string | null;
    subtitle: string | null;
    at: string;
};
type Risk = {
    organization: string;
    invoice: string;
    amount: number;
    currency: string;
    days_overdue: number;
    subscription_status: string | null;
    subscription_label: string;
    grace_period_remaining: number | null;
    destination: string;
};
type UsageMetric = {
    metric: string;
    label: string;
    value: number;
    limit: number | null;
    percent: number | null;
    unlimited_tenants: boolean;
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
type Metrics = {
    organizations_active: number;
    organizations_active_previous: number;
    trials_active: number;
    subscriptions_active: number;
    past_due_subscriptions: number;
    suspended_organizations: number;
    monthly_recurring_revenue:
        Mrr | { unavailable: true; message: string } | null;
    failed_jobs: number | { unavailable: true; message: string };
    critical_incidents: number;
    support_requests: number;
    service_health:
        | {
              healthy: number;
              total: number;
              checked: number;
              uptime: number | null;
          }
        | { unavailable: true; message: string };
    sparklines: Record<string, number[]>;
};

type Source<T> = T | { unavailable: true; message: string };

type Props = {
    dashboard: {
        metrics: Metrics;
        platform_health: Source<Service[]>;
        attention_items: Source<Attention[]>;
        recent_organizations: Source<Organization[]>;
        recent_activity: Source<Activity[]>;
        luzma_status: Source<LuzmaStatus> | null;
        usage_summary: Source<{
            metrics: UsageMetric[];
            tenants_near_limit: number;
        }>;
        billing_at_risk: Source<Risk[]> | null;
        last_updated_at: string;
    };
};

const severityColor: Record<string, string> = {
    critical: '#ef4444',
    high: '#f97316',
    medium: '#f59e0b',
    low: '#94a3b8',
};

function trend(current: number, previous: number): string {
    if (previous === 0) {
        return current > 0 ? 'nuevo vs. mes anterior' : 'sin cambio';
    }

    const change = ((current - previous) * 100) / previous;

    return `${change >= 0 ? '↑ +' : '↓ '}${change.toFixed(0)}% vs. mes anterior`;
}

function Gauge({
    value,
    label,
    sub,
    color,
}: {
    value: string;
    label: string;
    sub: number | null;
    color: string;
}) {
    const percent = Math.max(0, Math.min(100, sub ?? 0));
    const circumference = 2 * Math.PI * 30;

    return (
        <div className="grid place-items-center text-center">
            <svg width="76" height="76" viewBox="0 0 76 76">
                <circle
                    cx="38"
                    cy="38"
                    r="30"
                    fill="none"
                    stroke="rgba(255,255,255,.07)"
                    strokeWidth="6"
                />
                <circle
                    cx="38"
                    cy="38"
                    r="30"
                    fill="none"
                    stroke={color}
                    strokeWidth="6"
                    strokeLinecap="round"
                    strokeDasharray={`${(percent / 100) * circumference} ${circumference}`}
                    transform="rotate(-90 38 38)"
                />
                <text
                    x="38"
                    y="42"
                    textAnchor="middle"
                    className="fill-white text-[13px] font-bold"
                >
                    {value}
                </text>
            </svg>
            <span className="mt-1 text-[11px] text-slate-400">{label}</span>
        </div>
    );
}

function LuzmaAsk() {
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [answer, setAnswer] = useState<{
        answer: string;
        references: { label: string; href: string }[];
    } | null>(null);
    const ask = async (text: string) => {
        setBusy(true);
        setAnswer(null);

        try {
            const token = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] ?? '',
            );
            const response = await fetch('/control-center/luzma/ask', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify({ question: text }),
            });
            setAnswer(
                response.ok
                    ? await response.json()
                    : {
                          answer: 'Luzma no pudo responder en este momento.',
                          references: [],
                      },
            );
        } catch {
            setAnswer({
                answer: 'Luzma no pudo responder en este momento.',
                references: [],
            });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-1.5">
                {[
                    '¿Qué requiere atención hoy?',
                    '¿Qué servicios están degradados?',
                    '¿Qué tenants tienen pagos vencidos?',
                    '¿Qué jobs fallan más?',
                ].map((suggestion) => (
                    <button
                        key={suggestion}
                        type="button"
                        onClick={() => void ask(suggestion)}
                        className="rounded-full border border-white/10 bg-white/[0.03] px-2.5 py-1 text-[11px] text-slate-300 hover:border-[#8b5cf6]/50 hover:text-white"
                    >
                        {suggestion}
                    </button>
                ))}
            </div>
            <form
                className="flex gap-2"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (question.trim().length > 2) {
                        void ask(question.trim());
                    }
                }}
            >
                <input
                    value={question}
                    onChange={(event) => setQuestion(event.target.value)}
                    placeholder="Pregúntale a Luzma sobre la plataforma…"
                    className="h-9 flex-1 rounded-lg border border-white/10 bg-[#0b0e14] px-3 text-xs text-slate-100 outline-none focus:border-[#8b5cf6]/60"
                />
                <button
                    type="submit"
                    disabled={busy}
                    className="grid size-9 place-items-center rounded-lg bg-[#8b5cf6] text-white disabled:opacity-50"
                    aria-label="Preguntar"
                >
                    {busy ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        <Send className="size-4" />
                    )}
                </button>
            </form>
            {answer && (
                <div className="rounded-lg border border-[#8b5cf6]/25 bg-[#8b5cf6]/10 p-3 text-xs whitespace-pre-line text-slate-200">
                    {answer.answer}
                    <div className="mt-2 flex flex-wrap gap-2">
                        {answer.references.map((reference) => (
                            <Link
                                key={reference.href}
                                href={reference.href}
                                className="font-bold text-[#c4b5fd]"
                            >
                                {reference.label} →
                            </Link>
                        ))}
                    </div>
                </div>
            )}
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
    const organizations = dashboard.recent_organizations;
    const activity = dashboard.recent_activity;
    const luzma = dashboard.luzma_status;
    const usage = dashboard.usage_summary;
    const risk = dashboard.billing_at_risk;

    return (
        <>
            <Head title="Atlantia Control Center" />
            <section className="relative mb-5 overflow-hidden rounded-3xl border border-white/[0.06] bg-[radial-gradient(ellipse_at_top_right,rgba(200,16,46,.35),transparent_55%),linear-gradient(180deg,#10131a,#0a0c11)] p-6">
                <div className="absolute top-0 right-0 h-full w-1/2 bg-[radial-gradient(circle_at_70%_40%,rgba(232,176,75,.15),transparent_60%)]" />
                <div className="relative flex flex-wrap items-center justify-between gap-6">
                    <div>
                        <span className="inline-flex items-center gap-2 rounded-full border border-[#c8102e]/40 bg-[#c8102e]/15 px-3 py-1 text-xs font-semibold text-[#fca5a5]">
                            <AlertTriangle className="size-3.5" /> Vista global
                            de plataforma
                        </span>
                        <h1 className="mt-3 font-serif text-4xl font-bold text-white md:text-5xl">
                            Atlantia{' '}
                            <span className="text-[#e8b04b]">
                                Control Center
                            </span>
                        </h1>
                        <p className="mt-1 text-xl text-slate-300">
                            Panel Superadministrador SaaS
                        </p>
                        <p className="mt-2 max-w-xl text-sm text-slate-400">
                            Monitorea, gestiona y haz crecer todo el ecosistema
                            de Atlantia Finance desde un solo lugar.
                        </p>
                    </div>
                    <div
                        className={`${panel} grid grid-cols-3 gap-5 border-[#c8102e]/25 bg-black/30 px-5 py-4`}
                    >
                        <p className="col-span-3 text-[11px] font-bold tracking-[0.2em] text-[#fca5a5] uppercase">
                            Plataforma SaaS
                        </p>
                        <div>
                            <p className="text-2xl font-black text-white">
                                {m.organizations_active + m.trials_active}
                            </p>
                            <p className="text-[11px] text-slate-400">
                                Organizaciones
                            </p>
                        </div>
                        <div>
                            <p className="text-2xl font-black text-white">
                                {m.subscriptions_active}
                            </p>
                            <p className="text-[11px] text-slate-400">
                                Suscripciones
                            </p>
                        </div>
                        <div>
                            <p className="text-2xl font-black text-white">
                                {isUnavailable(health) || health.uptime === null
                                    ? '—'
                                    : `${health.uptime}%`}
                            </p>
                            <p className="text-[11px] text-slate-400">
                                Uptime (24 h)
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                {[
                    {
                        label: 'Organizaciones activas',
                        value: m.organizations_active,
                        hint: trend(
                            m.organizations_active,
                            m.organizations_active_previous,
                        ),
                        icon: <Building2 className="size-5" />,
                        tone: '#e8b04b',
                        series: m.sparklines.organizations,
                        href: '/control-center/organizations',
                    },
                    {
                        label: 'MRR',
                        value:
                            mrr === null ? (
                                <Unavailable text="Sin permiso" />
                            ) : isUnavailable(mrr) ? (
                                <Unavailable text="MRR no disponible." />
                            ) : (
                                money(mrr.mrr)
                            ),
                        hint:
                            mrr && !isUnavailable(mrr)
                                ? mrr.change_percent === null
                                    ? `ARR ${money(mrr.arr)}`
                                    : `${mrr.change_percent >= 0 ? '↑ +' : '↓ '}${mrr.change_percent}% vs. mes anterior`
                                : '',
                        icon: <Coins className="size-5" />,
                        tone: '#f59e0b',
                        series: m.sparklines.payments,
                        href: '/control-center/billing',
                    },
                    {
                        label: 'Suscripciones activas',
                        value: m.subscriptions_active,
                        hint: `${m.trials_active} en prueba`,
                        icon: <BadgeDollarSign className="size-5" />,
                        tone: '#c084fc',
                        series: m.sparklines.organizations,
                        href: '/control-center/subscriptions',
                    },
                    {
                        label: 'Pagos vencidos',
                        value: m.past_due_subscriptions,
                        hint: `${m.suspended_organizations} suspendida(s)`,
                        icon: <BadgeDollarSign className="size-5" />,
                        tone: '#ef4444',
                        series: m.sparklines.past_due,
                        href: '/control-center/subscriptions?status=past_due',
                    },
                    {
                        label: 'Jobs fallidos',
                        value: isUnavailable(m.failed_jobs) ? (
                            <Unavailable />
                        ) : (
                            m.failed_jobs
                        ),
                        hint: 'últimas 24 h',
                        icon: <Settings2 className="size-5" />,
                        tone: '#ef4444',
                        series: m.sparklines.failed_jobs,
                        href: '/control-center/jobs',
                    },
                    {
                        label: 'Incidentes críticos',
                        value: m.critical_incidents,
                        hint: `${m.support_requests} solicitud(es) de soporte`,
                        icon: <Siren className="size-5" />,
                        tone: '#ef4444',
                        series: m.sparklines.incidents,
                        href: '/control-center/incidents',
                    },
                ].map((kpi) => (
                    <Link
                        key={kpi.label}
                        href={kpi.href}
                        className={`${panel} relative overflow-hidden p-4 transition hover:border-[#c8102e]/40`}
                    >
                        <div className="flex items-start gap-3">
                            <span
                                className="grid size-11 shrink-0 place-items-center rounded-xl"
                                style={{
                                    background: `${kpi.tone}22`,
                                    color: kpi.tone,
                                }}
                            >
                                {kpi.icon}
                            </span>
                            <div>
                                <p className="text-[13px] font-semibold text-slate-300">
                                    {kpi.label}
                                </p>
                                <p className="mt-1 text-[26px] leading-none font-black text-white">
                                    {kpi.value}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 flex items-end justify-between gap-2">
                            <span className="text-[11px] text-slate-400">
                                {kpi.hint}
                            </span>
                            <Bars values={kpi.series ?? []} color={kpi.tone} />
                        </div>
                    </Link>
                ))}
            </section>

            <section className="mt-3 grid gap-3 xl:grid-cols-3">
                <div className={`${panel} p-4`}>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                            <span className="size-2.5 rounded-full bg-[#22c55e]" />{' '}
                            Estado de servicios
                        </h2>
                        <Link
                            href="/control-center/services"
                            className="text-[11px] text-slate-400 hover:text-white"
                        >
                            Última actualización:{' '}
                            {isUnavailable(services)
                                ? '—'
                                : ago(
                                      services
                                          .map((s) => s.last_check)
                                          .filter(Boolean)
                                          .sort()
                                          .reverse()[0] ?? null,
                                  )}{' '}
                            <ChevronRight className="inline size-3" />
                        </Link>
                    </div>
                    {isUnavailable(services) ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            Estado no disponible.
                        </p>
                    ) : (
                        <ul className="divide-y divide-white/[0.04]">
                            {services.map((service) => (
                                <li key={service.key}>
                                    <Link
                                        href={service.destination}
                                        className="flex items-center gap-3 py-2 text-[13px]"
                                    >
                                        <span
                                            className="flex-1 truncate text-slate-200"
                                            title={
                                                service.latest_error ??
                                                undefined
                                            }
                                        >
                                            {service.name}
                                        </span>
                                        <Pill tone={healthTone[service.status]}>
                                            {service.label}
                                        </Pill>
                                        <Sparkline
                                            values={service.history}
                                            color={
                                                service.status === 'healthy'
                                                    ? '#22c55e'
                                                    : service.status ===
                                                        'degraded'
                                                      ? '#f59e0b'
                                                      : '#64748b'
                                            }
                                            width={80}
                                            height={20}
                                        />
                                        <ChevronRight className="size-3.5 text-slate-600" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className={`${panel} p-4`}>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                            <AlertTriangle className="size-4 text-[#ef4444]" />{' '}
                            Requiere atención
                        </h2>
                        {!isUnavailable(attention) && (
                            <span className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-slate-300">
                                Ver todos ({attention.length})
                            </span>
                        )}
                    </div>
                    {isUnavailable(attention) ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            No disponible.
                        </p>
                    ) : attention.length === 0 ? (
                        <p className="py-10 text-center text-sm text-slate-500">
                            Nada requiere atención en este momento.
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {attention.map((item) => (
                                <li key={item.key}>
                                    <Link
                                        href={item.destination}
                                        className="flex items-center gap-3 rounded-xl border border-white/[0.05] bg-white/[0.02] p-3 hover:border-[#c8102e]/40"
                                    >
                                        <span
                                            className="grid size-9 shrink-0 place-items-center rounded-lg"
                                            style={{
                                                background: `${severityColor[item.severity]}22`,
                                                color: severityColor[
                                                    item.severity
                                                ],
                                            }}
                                        >
                                            <AlertTriangle className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-[13px] text-slate-200">
                                                <b
                                                    style={{
                                                        color: severityColor[
                                                            item.severity
                                                        ],
                                                    }}
                                                >
                                                    {item.count}
                                                </b>{' '}
                                                {item.title}
                                            </span>
                                            <span className="block text-[11px] text-slate-500">
                                                {item.subtitle}
                                            </span>
                                        </span>
                                        <ChevronRight className="size-4 text-slate-600" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className={`${panel} p-4`}>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                            <span className="size-2.5 rounded-full bg-[#8b5cf6]" />{' '}
                            Luzma AI
                        </h2>
                        {can('saas.luzma.view') && (
                            <Link
                                href="/control-center/luzma"
                                className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-slate-300 hover:text-white"
                            >
                                Abrir consola →
                            </Link>
                        )}
                    </div>
                    {luzma === null ? (
                        <p className="text-sm text-slate-500">
                            Tu rol no incluye el estado de Luzma.
                        </p>
                    ) : isUnavailable(luzma) ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            Estado no disponible.
                        </p>
                    ) : (
                        <>
                            <div className="flex items-center gap-4">
                                <span className="grid size-20 shrink-0 place-items-center rounded-2xl bg-[radial-gradient(circle,rgba(139,92,246,.45),transparent_70%)]">
                                    <Bot className="size-10 text-[#c4b5fd]" />
                                </span>
                                <div className="grid flex-1 grid-cols-2 gap-2">
                                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-2">
                                        <p className="text-[10px] text-slate-500">
                                            Estado
                                        </p>
                                        <p
                                            className="mt-0.5 text-sm font-bold"
                                            style={{
                                                color:
                                                    luzma.status === 'healthy'
                                                        ? '#4ade80'
                                                        : luzma.status ===
                                                            'degraded'
                                                          ? '#fbbf24'
                                                          : '#f87171',
                                            }}
                                        >
                                            {{
                                                healthy: 'Saludable',
                                                degraded: 'Degradado',
                                                down: 'Caído',
                                                maintenance: 'Deshabilitado',
                                            }[luzma.status] ?? luzma.status}
                                        </p>
                                    </div>
                                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-2">
                                        <p className="text-[10px] text-slate-500">
                                            Modelo
                                        </p>
                                        <p className="mt-0.5 truncate text-sm font-bold text-white">
                                            {luzma.model ?? 'No reportado'}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-3 grid grid-cols-3">
                                <Gauge
                                    value={String(luzma.requests_today)}
                                    label="Consultas hoy"
                                    sub={
                                        luzma.requests_today > 0
                                            ? 100 -
                                              Math.min(
                                                  100,
                                                  (luzma.errors_today * 100) /
                                                      luzma.requests_today,
                                              )
                                            : 0
                                    }
                                    color="#60a5fa"
                                />
                                <Gauge
                                    value={
                                        luzma.average_latency_ms === null
                                            ? '—'
                                            : `${(luzma.average_latency_ms / 1000).toFixed(1)} s`
                                    }
                                    label="Latencia prom."
                                    sub={
                                        luzma.average_latency_ms === null
                                            ? 0
                                            : Math.min(
                                                  100,
                                                  luzma.average_latency_ms /
                                                      300,
                                              )
                                    }
                                    color="#f472b6"
                                />
                                <Gauge
                                    value={
                                        luzma.uptime_24h === null
                                            ? '—'
                                            : `${Math.round(luzma.uptime_24h)}%`
                                    }
                                    label="Uptime 24 h"
                                    sub={luzma.uptime_24h}
                                    color="#e8b04b"
                                />
                            </div>
                        </>
                    )}
                    {can('saas.luzma.use') && (
                        <div className="mt-3 border-t border-white/[0.05] pt-3">
                            <LuzmaAsk />
                        </div>
                    )}
                </div>
            </section>

            <section className="mt-3 grid gap-3 xl:grid-cols-3">
                <div className={`${panel} p-4`}>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                            <Building2 className="size-4 text-[#e8b04b]" />{' '}
                            Organizaciones recientes
                        </h2>
                        <Link
                            href="/control-center/organizations"
                            className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-slate-300 hover:text-white"
                        >
                            Ver todas →
                        </Link>
                    </div>
                    {isUnavailable(organizations) ? (
                        <Unavailable />
                    ) : (
                        <Table
                            headers={[
                                'Organización',
                                'Plan',
                                'Estado',
                                'Renovación',
                            ]}
                            empty={
                                organizations.length === 0 &&
                                'No existen organizaciones registradas.'
                            }
                        >
                            {organizations.map((organization) => (
                                <tr key={organization.id}>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={organization.destination}
                                            className="font-semibold text-slate-100 hover:text-white"
                                        >
                                            {organization.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {organization.plan ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={
                                                statusTone[
                                                    organization.subscription_status ??
                                                        organization.organization_status
                                                ]
                                            }
                                        >
                                            {organization.subscription_status
                                                ? organization.subscription_label
                                                : organization.organization_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {date(organization.renewal_date)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </div>

                <div className={`${panel} p-4`}>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                            <Cpu className="size-4 text-[#60a5fa]" /> Actividad
                            SaaS reciente
                        </h2>
                        {can('saas.audit.view') && (
                            <Link
                                href="/control-center/audit"
                                className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-slate-300 hover:text-white"
                            >
                                Ver todas →
                            </Link>
                        )}
                    </div>
                    {isUnavailable(activity) ? (
                        <Unavailable />
                    ) : activity.length === 0 ? (
                        <p className="py-10 text-center text-sm text-slate-500">
                            Aún no hay actividad registrada.
                        </p>
                    ) : (
                        <ul className="space-y-2.5">
                            {activity.map((event, index) => (
                                <li
                                    key={index}
                                    className="flex items-start gap-3"
                                >
                                    <span className="mt-1 size-2 shrink-0 rounded-full bg-[#e8b04b]" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-mono text-[12px] text-slate-200">
                                            {event.action}
                                        </span>
                                        <span className="block truncate text-[11px] text-slate-500">
                                            {[event.actor, event.subtitle]
                                                .filter(Boolean)
                                                .join(' · ') || 'Sistema'}
                                        </span>
                                    </span>
                                    <span className="shrink-0 text-[11px] text-slate-500">
                                        {ago(event.at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="grid gap-3">
                    <div className={`${panel} p-4`}>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                                <span className="size-2.5 rounded-full bg-[#22c55e]" />{' '}
                                Consumo y límites
                            </h2>
                            <Link
                                href="/control-center/usage"
                                className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-slate-300 hover:text-white"
                            >
                                Ver detalles →
                            </Link>
                        </div>
                        {isUnavailable(usage) ? (
                            <Unavailable />
                        ) : (
                            <ul className="space-y-2.5">
                                {usage.metrics.map((metric) => (
                                    <li
                                        key={metric.metric}
                                        className="grid grid-cols-[110px_1fr_auto] items-center gap-3 text-[12px]"
                                    >
                                        <span className="text-slate-300">
                                            {metric.label}
                                        </span>
                                        <Progress percent={metric.percent} />
                                        <span className="w-28 text-right text-slate-400">
                                            {metric.metric === 'storage_bytes'
                                                ? bytes(metric.value)
                                                : metric.value.toLocaleString(
                                                      'es-GT',
                                                  )}{' '}
                                            /{' '}
                                            {metric.limit === null
                                                ? '∞'
                                                : metric.metric ===
                                                    'storage_bytes'
                                                  ? bytes(metric.limit)
                                                  : metric.limit.toLocaleString(
                                                        'es-GT',
                                                    )}
                                        </span>
                                    </li>
                                ))}
                                <li className="text-[11px] text-slate-500">
                                    {usage.tenants_near_limit} alerta(s) de
                                    organizaciones cerca o por encima de su
                                    límite.
                                </li>
                            </ul>
                        )}
                    </div>
                    <div className={`${panel} p-4`}>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                                <AlertTriangle className="size-4 text-[#ef4444]" />{' '}
                                Facturación en riesgo
                            </h2>
                            {can('saas.billing.view') && (
                                <Link
                                    href="/control-center/billing?status=past_due"
                                    className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-slate-300 hover:text-white"
                                >
                                    Ver todas →
                                </Link>
                            )}
                        </div>
                        {risk === null ? (
                            <p className="text-sm text-slate-500">
                                Tu rol no incluye facturación.
                            </p>
                        ) : isUnavailable(risk) ? (
                            <Unavailable text="Facturación no disponible." />
                        ) : (
                            <Table
                                headers={[
                                    'Organización',
                                    'Monto',
                                    'Días de atraso',
                                    'Estado',
                                ]}
                                empty={
                                    risk.length === 0 &&
                                    'No hay facturas vencidas.'
                                }
                            >
                                {risk.map((row) => (
                                    <tr key={row.invoice}>
                                        <td className="px-2 py-2">
                                            <Link
                                                href={row.destination}
                                                className="font-semibold text-slate-100"
                                            >
                                                {row.organization}
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2 whitespace-nowrap">
                                            {money(row.amount, row.currency)}
                                        </td>
                                        <td className="px-2 py-2 font-bold text-[#f87171]">
                                            {row.days_overdue} días
                                        </td>
                                        <td className="px-2 py-2">
                                            <Pill
                                                tone={
                                                    statusTone[
                                                        row.subscription_status ??
                                                            'past_due'
                                                    ]
                                                }
                                            >
                                                {row.subscription_label}
                                            </Pill>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </div>
                </div>
            </section>
            <p className="mt-3 text-right text-[11px] text-slate-600">
                Datos al {date(dashboard.last_updated_at, true)}
            </p>
        </>
    );
}
