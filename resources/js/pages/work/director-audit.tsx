import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardCheck,
    FileText,
    Info,
    Settings,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    kpis: {
        events: number;
        events_change: number | null;
        critical: number;
        observations: number;
        controls_reviewed: number;
        conforming: number | null;
    };
    findings_by_month: {
        label: string;
        critical: number;
        high: number;
        medium: number;
        low: number;
    }[];
    severity: { label: string; value: number; color: string }[];
    controls: { label: string; value: number; color: string }[];
    activity: { who: string; what: string; at: string; level: string }[];
    exceptions: { title: string; severity: string; at: string }[];
    exceptions_total: number;
    high_risk_changes: {
        title: string;
        by: string;
        at: string;
        level: string;
    }[];
    high_risk_total: number;
    unusual_access: { title: string; detail: string; at: string }[];
    pending_reviews: {
        type: string;
        description: string;
        assigned: string | null;
        due: string | null;
        status: string;
    }[];
    office_scores: { name: string; score: number; trend: number }[];
    has_audit_work: boolean;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const LEVEL: Record<string, string> = {
    Crítico: 'bg-red-100 text-red-700',
    Alto: 'bg-orange-100 text-orange-700',
    Medio: 'bg-yellow-100 text-yellow-800',
    Bajo: 'bg-slate-100 text-slate-600',
};
const SEVERITY_COLOR = {
    critical: '#dc2626',
    high: '#f97316',
    medium: '#f0c419',
    low: '#d1d5db',
} as const;
const REVIEW_STATUS: Record<string, { label: string; style: string }> = {
    submitted: { label: 'Pendiente', style: 'bg-rose-50 text-rose-700' },
    requested: { label: 'Pendiente', style: 'bg-rose-50 text-rose-700' },
    pending: { label: 'Pendiente', style: 'bg-rose-50 text-rose-700' },
    in_review: { label: 'En progreso', style: 'bg-blue-50 text-blue-700' },
    changes_requested: {
        label: 'En revisión',
        style: 'bg-amber-50 text-amber-700',
    },
};

function formatDate(value: string | null, time = false): string {
    if (!value) {
        return '—';
    }

    return new Date(
        value.length === 10 ? `${value}T12:00:00` : value,
    ).toLocaleString(
        'es-GT',
        time
            ? {
                  day: '2-digit',
                  month: 'short',
                  hour: '2-digit',
                  minute: '2-digit',
              }
            : { day: '2-digit', month: 'short', year: 'numeric' },
    );
}

function ago(value: string): string {
    const days = Math.floor(
        (Date.now() - new Date(value).getTime()) / 86_400_000,
    );

    return days <= 0 ? 'Hoy' : `Hace ${days} día${days === 1 ? '' : 's'}`;
}

function Kpi({
    icon,
    tone,
    label,
    value,
    hint,
    bad,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: string;
    hint: string;
    bad?: boolean | null;
}) {
    return (
        <div className={`${card} flex items-center gap-4 !p-4`}>
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[#6b1d1d]">
                    {label}
                </p>
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`truncate text-xs font-semibold ${bad === null || bad === undefined ? 'text-slate-500' : bad ? 'text-red-600' : 'text-emerald-600'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Donut({
    rows,
    label,
}: {
    rows: { label: string; value: number; color: string }[];
    label: string;
}) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    const radius = 54;
    const circumference = 2 * Math.PI * radius;
    const lengths = rows.map((row) =>
        total > 0 ? (row.value / total) * circumference : 0,
    );

    return (
        <div className="flex items-center gap-5">
            <svg viewBox="0 0 140 140" className="size-40 shrink-0">
                <circle
                    cx="70"
                    cy="70"
                    r={radius}
                    fill="none"
                    stroke="#eef1f3"
                    strokeWidth="18"
                />
                {rows.map((row, i) => (
                    <circle
                        key={row.label}
                        cx="70"
                        cy="70"
                        r={radius}
                        fill="none"
                        stroke={row.color}
                        strokeWidth="18"
                        strokeDasharray={`${lengths[i]} ${circumference - lengths[i]}`}
                        strokeDashoffset={
                            -lengths.slice(0, i).reduce((s, v) => s + v, 0)
                        }
                        transform="rotate(-90 70 70)"
                    />
                ))}
                <text
                    x="70"
                    y="68"
                    textAnchor="middle"
                    className="fill-[#061633] text-[26px] font-black"
                >
                    {total}
                </text>
                <text
                    x="70"
                    y="84"
                    textAnchor="middle"
                    className="fill-slate-500 text-[10px]"
                >
                    {label}
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-2 text-sm">
                {rows.map((row) => (
                    <li key={row.label} className="flex items-center gap-2">
                        <i
                            className="size-3 shrink-0 rounded-full"
                            style={{ background: row.color }}
                        />
                        <span className="flex-1 truncate">{row.label}</span>
                        <b>{row.value}</b>
                        <span className="w-14 text-right text-xs text-slate-500">
                            (
                            {total
                                ? Math.round((row.value / total) * 1000) / 10
                                : 0}
                            %)
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Stacked({ months }: { months: Props['findings_by_month'] }) {
    const totals = months.map(
        (month) => month.critical + month.high + month.medium + month.low,
    );
    const max = Math.max(1, ...totals);

    return (
        <div>
            <div className="flex h-48 items-end gap-4 border-b border-slate-100 px-2">
                {months.map((month, i) => (
                    <div
                        key={month.label + i}
                        className="flex flex-1 flex-col items-center justify-end"
                    >
                        <div
                            className="flex w-full max-w-12 flex-col-reverse overflow-hidden rounded-t"
                            style={{ height: `${(totals[i] / max) * 160}px` }}
                        >
                            {(
                                ['critical', 'high', 'medium', 'low'] as const
                            ).map((key) => (
                                <div
                                    key={key}
                                    style={{
                                        flex: month[key],
                                        background: SEVERITY_COLOR[key],
                                    }}
                                />
                            ))}
                        </div>
                        <span className="mt-1 text-xs text-slate-500">
                            {month.label}
                        </span>
                    </div>
                ))}
            </div>
            <div className="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
                {(
                    [
                        ['critical', 'Críticos'],
                        ['high', 'Altos'],
                        ['medium', 'Medios'],
                        ['low', 'Bajos'],
                    ] as const
                ).map(([key, label]) => (
                    <span key={key} className="flex items-center gap-1.5">
                        <i
                            className="size-2.5 rounded-sm"
                            style={{ background: SEVERITY_COLOR[key] }}
                        />
                        {label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
            {children}
        </p>
    );
}

export default function DirectorAudit(props: Props) {
    const {
        kpis,
        findings_by_month,
        severity,
        controls,
        activity,
        exceptions,
        exceptions_total,
        high_risk_changes,
        high_risk_total,
        unusual_access,
        pending_reviews,
        office_scores,
        has_audit_work,
    } = props;

    return (
        <>
            <Head title="Auditoría" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <ShieldCheck className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Auditoría
                            </h1>
                            <p className="text-sm text-slate-600">
                                Trazabilidad, control y gobierno de la firma en
                                un solo lugar.
                            </p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<FileText className="size-7" />}
                            tone="bg-rose-50 text-[#8a0b0b]"
                            label="Eventos auditables"
                            value={kpis.events.toLocaleString('es-GT')}
                            hint={
                                kpis.events_change === null
                                    ? 'Este mes'
                                    : `${kpis.events_change >= 0 ? '↑' : '↓'} ${Math.abs(kpis.events_change)}% vs. mes anterior`
                            }
                        />
                        <Kpi
                            icon={<AlertTriangle className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Incidencias críticas"
                            value={String(kpis.critical)}
                            hint="Hallazgos críticos abiertos"
                            bad={kpis.critical > 0}
                        />
                        <Kpi
                            icon={<ClipboardCheck className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Observaciones abiertas"
                            value={String(kpis.observations)}
                            hint="Hallazgos sin cerrar"
                            bad={kpis.observations > 0 ? true : null}
                        />
                        <Kpi
                            icon={<Settings className="size-7" />}
                            tone="bg-slate-100 text-slate-600"
                            label="Controles revisados"
                            value={String(kpis.controls_reviewed)}
                            hint="Con una evaluación registrada"
                        />
                        <Kpi
                            icon={<ShieldCheck className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Procesos conformes"
                            value={
                                kpis.conforming === null
                                    ? '—'
                                    : `${kpis.conforming}%`
                            }
                            hint="Controles evaluados como efectivos"
                        />
                    </section>

                    {!has_audit_work && (
                        <p className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <Info className="mt-0.5 size-4 shrink-0" />
                            Todavía no hay hallazgos ni controles evaluados: el
                            Auditor los registra en su propio panel y aquí
                            aparecerán. Los eventos, cambios y accesos sí se
                            muestran desde ahora.
                        </p>
                    )}

                    <section className="grid gap-4 xl:grid-cols-[1.4fr_1fr_1fr]">
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Hallazgos de auditoría por mes
                            </h2>
                            <Stacked months={findings_by_month} />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Hallazgos abiertos por severidad
                            </h2>
                            <Donut rows={severity} label="en total" />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Estado de controles de la firma
                            </h2>
                            <Donut rows={controls} label="controles" />
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1fr_1fr_1fr]">
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Actividad reciente de auditoría
                            </h2>
                            <ul className="space-y-3">
                                {activity.map((item, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#f6e6e4] text-xs font-black text-[#8a0b0b]">
                                            <UserRound className="size-4" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-bold">
                                                {item.who}
                                            </p>
                                            <p className="truncate text-xs text-slate-500">
                                                {item.what}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <span
                                                className={`rounded-md px-2 py-0.5 text-xs font-bold ${LEVEL[item.level] ?? LEVEL.Bajo}`}
                                            >
                                                {item.level}
                                            </span>
                                            <p className="mt-0.5 text-[11px] text-slate-400">
                                                {formatDate(item.at, true)}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                                {activity.length === 0 && (
                                    <li>
                                        <Empty>Sin actividad registrada.</Empty>
                                    </li>
                                )}
                            </ul>
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 flex items-center justify-between font-black">
                                Excepciones de control{' '}
                                <span className="rounded-full bg-red-50 px-2 text-xs text-red-600">
                                    {exceptions_total}
                                </span>
                            </h2>
                            <ul className="space-y-3">
                                {exceptions.map((item, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <AlertTriangle
                                            className={`size-6 shrink-0 ${item.severity === 'critical' ? 'text-red-600' : 'text-orange-500'}`}
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-bold">
                                                {item.title}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                Hallazgo{' '}
                                                {item.severity === 'critical'
                                                    ? 'crítico'
                                                    : 'alto'}
                                            </p>
                                        </div>
                                        <span className="text-xs text-slate-400">
                                            {ago(item.at)}
                                        </span>
                                    </li>
                                ))}
                                {exceptions.length === 0 && (
                                    <li>
                                        <Empty>
                                            No hay excepciones de control
                                            abiertas.
                                        </Empty>
                                    </li>
                                )}
                            </ul>
                        </div>
                        <div className="space-y-4">
                            <div className={card}>
                                <h2 className="mb-3 flex items-center justify-between font-black">
                                    Cambios de alto riesgo{' '}
                                    <span className="rounded-full bg-slate-100 px-2 text-xs">
                                        {high_risk_total}
                                    </span>
                                </h2>
                                <ul className="space-y-3">
                                    {high_risk_changes.map((item, index) => (
                                        <li
                                            key={index}
                                            className="flex items-center gap-3 text-sm"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-bold">
                                                    {item.title}
                                                </p>
                                                <p className="truncate text-xs text-slate-500">
                                                    {item.by} ·{' '}
                                                    {formatDate(item.at, true)}
                                                </p>
                                            </div>
                                            <span
                                                className={`rounded-md px-2 py-0.5 text-xs font-bold ${LEVEL[item.level] ?? LEVEL.Bajo}`}
                                            >
                                                {item.level}
                                            </span>
                                        </li>
                                    ))}
                                    {high_risk_changes.length === 0 && (
                                        <li>
                                            <Empty>
                                                Sin cambios sensibles en 30
                                                días.
                                            </Empty>
                                        </li>
                                    )}
                                </ul>
                            </div>
                            <div className={card}>
                                <h2 className="mb-3 font-black">
                                    Accesos inusuales
                                </h2>
                                <ul className="space-y-3">
                                    {unusual_access.map((item, index) => (
                                        <li
                                            key={index}
                                            className="flex items-center gap-3 text-sm"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-bold">
                                                    {item.title}
                                                </p>
                                                <p className="truncate text-xs text-slate-500">
                                                    {item.detail}
                                                </p>
                                            </div>
                                            <span className="text-xs text-slate-400">
                                                {formatDate(item.at, true)}
                                            </span>
                                        </li>
                                    ))}
                                    {unusual_access.length === 0 && (
                                        <li>
                                            <Empty>
                                                Sin intentos fallidos repetidos
                                                esta semana.
                                            </Empty>
                                        </li>
                                    )}
                                </ul>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                        <div className={`${card} overflow-x-auto`}>
                            <h2 className="mb-3 font-black">
                                Revisiones pendientes
                            </h2>
                            <table className="w-full min-w-[520px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-500">
                                        <th className="py-1">Tipo</th>
                                        <th>Descripción</th>
                                        <th>Asignado a</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pending_reviews.map((item, index) => (
                                        <tr key={index}>
                                            <td className="py-2.5 font-bold">
                                                {item.type}
                                            </td>
                                            <td className="text-slate-600">
                                                {item.description}
                                            </td>
                                            <td>{item.assigned ?? '—'}</td>
                                            <td>{formatDate(item.due)}</td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-bold ${REVIEW_STATUS[item.status]?.style ?? 'bg-slate-100 text-slate-600'}`}
                                                >
                                                    {REVIEW_STATUS[item.status]
                                                        ?.label ?? item.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                    {pending_reviews.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="py-8 text-center text-slate-500"
                                            >
                                                No hay revisiones pendientes.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Puntaje de auditoría por oficina
                            </h2>
                            <ul className="space-y-3">
                                {office_scores.map((office) => (
                                    <li
                                        key={office.name}
                                        className="grid grid-cols-[minmax(0,8rem)_3rem_1fr_3.5rem] items-center gap-3 text-sm"
                                    >
                                        <span className="truncate">
                                            {office.name}
                                        </span>
                                        <b>{office.score}%</b>
                                        <div className="h-3 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className="h-full rounded"
                                                style={{
                                                    width: `${office.score}%`,
                                                    background:
                                                        office.score >= 90
                                                            ? '#16a34a'
                                                            : office.score >= 75
                                                              ? '#f0a93b'
                                                              : '#dc2626',
                                                }}
                                            />
                                        </div>
                                        <span
                                            className={`text-right text-xs font-bold ${office.trend >= 0 ? 'text-emerald-600' : 'text-red-600'}`}
                                        >
                                            {office.trend >= 0 ? '↑' : '↓'}{' '}
                                            {Math.abs(office.trend)}%
                                        </span>
                                    </li>
                                ))}
                                {office_scores.length === 0 && (
                                    <li>
                                        <Empty>Sin oficinas.</Empty>
                                    </li>
                                )}
                            </ul>
                            <p className="mt-3 text-xs text-slate-500">
                                100% menos el peso de los hallazgos abiertos de
                                cada oficina (crítico 10, alto 5, medio 2, bajo
                                1).
                            </p>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
