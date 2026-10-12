import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    CalendarCheck2,
    ClipboardList,
    Info,
    Lightbulb,
    Target,
    TrendingUp,
} from 'lucide-react';
import type { ReactNode } from 'react';

type Status = 'met' | 'missed' | 'in_progress' | 'heavy' | 'planned' | 'empty';

type Month = {
    name: string;
    planned: number;
    done: number;
    pending: number;
    rate: number | null;
    phase: 'past' | 'current' | 'future';
    share: number;
    status: Status;
};

type Props = {
    year: number;
    years: number[];
    view: string;
    views: { key: string; label: string }[];
    goal: number;
    months: Month[];
    summary: {
        rate: number | null;
        goal_met: boolean;
        planned: number;
        done: number;
        pending_to_date: number;
        met_months: number;
        risk: 'low' | 'medium' | 'high';
        risk_note: string;
    };
    findings: string[];
    recommendations: string[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const select =
    'h-12 rounded-xl border border-[#e4dbd6] bg-white px-4 text-sm font-bold outline-none focus:border-[#8a0b0b]';
const RISK = {
    low: { label: 'Bajo', color: 'text-emerald-600' },
    medium: { label: 'Medio', color: 'text-amber-600' },
    high: { label: 'Alto', color: 'text-red-600' },
} as const;
const BADGE: Record<Status, { label: string; style: string; icon: string }> = {
    met: {
        label: 'Meta alcanzada',
        style: 'bg-emerald-50 text-emerald-700',
        icon: '✓',
    },
    missed: {
        label: 'Meta no alcanzada',
        style: 'bg-red-50 text-red-700',
        icon: '✕',
    },
    in_progress: {
        label: 'En proceso',
        style: 'bg-blue-50 text-blue-700',
        icon: '↻',
    },
    heavy: {
        label: 'Carga alta',
        style: 'bg-amber-50 text-amber-700',
        icon: '⚠',
    },
    planned: {
        label: 'Planificado',
        style: 'bg-slate-100 text-slate-600',
        icon: '→',
    },
    empty: {
        label: 'Sin trabajo',
        style: 'bg-slate-50 text-slate-400',
        icon: '–',
    },
};

function Kpi({
    icon,
    tone,
    label,
    value,
    hint,
    good,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: ReactNode;
    hint: string;
    good?: boolean | null;
}) {
    return (
        <div className={`${card} flex items-center gap-4 p-5`}>
            <span
                className={`grid size-16 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[#8a0b0b]">
                    {label}
                </p>
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`truncate text-xs font-semibold ${good === null || good === undefined ? 'text-slate-500' : good ? 'text-emerald-600' : 'text-red-600'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Ring({
    value,
    goal,
    muted,
}: {
    value: number | null;
    goal: number;
    muted: boolean;
}) {
    const radius = 42;
    const circumference = 2 * Math.PI * radius;
    const shown = value ?? 0;
    const color = muted
        ? '#cbd5e1'
        : shown >= goal
          ? '#16a34a'
          : shown >= goal - 25
            ? '#f0a93b'
            : '#dc2626';

    return (
        <svg viewBox="0 0 110 110" className="size-28 shrink-0">
            <circle
                cx="55"
                cy="55"
                r={radius}
                fill="none"
                stroke="#eef1f3"
                strokeWidth="12"
            />
            <circle
                cx="55"
                cy="55"
                r={radius}
                fill="none"
                stroke={color}
                strokeWidth="12"
                strokeLinecap="round"
                strokeDasharray={`${(Math.min(100, shown) / 100) * circumference} ${circumference}`}
                transform="rotate(-90 55 55)"
            />
            <text
                x="55"
                y="55"
                textAnchor="middle"
                dominantBaseline="central"
                className="fill-[#061633] text-[20px] font-black"
            >
                {value === null ? '—' : `${value}%`}
            </text>
        </svg>
    );
}

export default function DirectorProjections({
    year,
    years,
    view,
    views,
    goal,
    months,
    summary,
    findings,
    recommendations,
}: Props) {
    const change = (next: { year?: number; view?: string }) =>
        router.get(
            '/office/projections',
            { year: next.year ?? year, view: next.view ?? view },
            { preserveScroll: true, preserveState: false },
        );

    return (
        <>
            <Head title="Proyecciones" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <TrendingUp className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Proyecciones
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Trabajo planificado de cada mes y si la
                                    firma alcanzó su meta de cumplimiento (
                                    {goal}%).
                                </p>
                            </div>
                        </div>
                        <div className="flex gap-3">
                            <label className="text-xs text-slate-500">
                                Año
                                <select
                                    className={`${select} block`}
                                    value={year}
                                    onChange={(e) =>
                                        change({ year: Number(e.target.value) })
                                    }
                                >
                                    {years.map((item) => (
                                        <option key={item}>{item}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-xs text-slate-500">
                                Tipo de trabajo
                                <select
                                    className={`${select} block`}
                                    value={view}
                                    onChange={(e) =>
                                        change({ view: e.target.value })
                                    }
                                >
                                    {views.map((item) => (
                                        <option key={item.key} value={item.key}>
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Kpi
                            icon={<Target className="size-8" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label={`Cumplimiento de la meta (${year})`}
                            value={
                                summary.rate === null ? '—' : `${summary.rate}%`
                            }
                            hint={
                                summary.rate === null
                                    ? 'Aún no hay trabajo vencido'
                                    : summary.goal_met
                                      ? `Meta de ${goal}% alcanzada`
                                      : `Meta de ${goal}% no alcanzada`
                            }
                            good={
                                summary.rate === null ? null : summary.goal_met
                            }
                        />
                        <Kpi
                            icon={<ClipboardList className="size-8" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label="Trabajo planificado del año"
                            value={String(summary.planned)}
                            hint={`${summary.done} piezas completadas`}
                        />
                        <Kpi
                            icon={<CalendarCheck2 className="size-8" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Meses con meta alcanzada"
                            value={String(summary.met_months)}
                            hint={`${summary.pending_to_date} pieza(s) pendientes a la fecha`}
                            good={summary.pending_to_date === 0}
                        />
                        <Kpi
                            icon={<AlertTriangle className="size-8" />}
                            tone="bg-red-50 text-red-500"
                            label="Riesgo proyectado"
                            value={
                                <span className={RISK[summary.risk].color}>
                                    {RISK[summary.risk].label}
                                </span>
                            }
                            hint={summary.risk_note}
                            good={
                                summary.risk === 'low'
                                    ? true
                                    : summary.risk === 'high'
                                      ? false
                                      : null
                            }
                        />
                    </section>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {months.map((month) => (
                            <div key={month.name} className={`${card} p-4`}>
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <h2 className="text-lg font-black">
                                        {month.name}
                                    </h2>
                                    <span
                                        className={`rounded-lg px-2.5 py-1 text-xs font-bold ${BADGE[month.status].style}`}
                                    >
                                        {BADGE[month.status].icon}{' '}
                                        {BADGE[month.status].label}
                                    </span>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Ring
                                        value={
                                            month.phase === 'future'
                                                ? null
                                                : month.rate
                                        }
                                        goal={goal}
                                        muted={
                                            month.phase === 'future' ||
                                            month.planned === 0
                                        }
                                    />
                                    <dl className="min-w-0 space-y-2 text-sm">
                                        <div>
                                            <dt className="text-xs text-slate-500">
                                                Trabajo planificado
                                            </dt>
                                            <dd className="font-black">
                                                {month.planned}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">
                                                {month.phase === 'future'
                                                    ? 'Por realizar'
                                                    : 'Completado'}
                                            </dt>
                                            <dd className="font-black">
                                                {month.phase === 'future'
                                                    ? month.planned
                                                    : month.done}
                                            </dd>
                                        </div>
                                        {month.phase !== 'future' &&
                                            month.pending > 0 && (
                                                <div className="text-xs font-bold text-red-600">
                                                    {month.pending} pendiente(s)
                                                </div>
                                            )}
                                        {month.phase === 'future' &&
                                            month.share > 0 && (
                                                <div className="text-xs text-slate-500">
                                                    {month.share}% del trabajo
                                                    del año
                                                </div>
                                            )}
                                    </dl>
                                </div>
                            </div>
                        ))}
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1fr_1fr_1fr]">
                        <div className={`${card} p-5`}>
                            <h2 className="mb-3 font-black">
                                Resumen del año ({year})
                            </h2>
                            <dl className="divide-y divide-slate-100 text-sm">
                                <div className="flex items-center justify-between py-2.5">
                                    <dt className="text-slate-600">
                                        Trabajo planificado
                                    </dt>
                                    <dd className="font-black">
                                        {summary.planned}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between py-2.5">
                                    <dt className="text-slate-600">
                                        Trabajo completado
                                    </dt>
                                    <dd className="font-black">
                                        {summary.done}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between py-2.5">
                                    <dt className="text-slate-600">
                                        Cumplimiento a la fecha
                                    </dt>
                                    <dd className="font-black">
                                        {summary.rate === null
                                            ? '—'
                                            : `${summary.rate}%`}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between py-2.5">
                                    <dt className="text-slate-600">
                                        Meta de cumplimiento
                                    </dt>
                                    <dd className="font-black">{goal}%</dd>
                                </div>
                            </dl>
                        </div>
                        <div className={`${card} p-5`}>
                            <h2 className="mb-3 font-black">
                                Principales hallazgos
                            </h2>
                            <ul className="space-y-2.5">
                                {findings.map((item) => (
                                    <li
                                        key={item}
                                        className="flex items-start gap-3 rounded-lg bg-slate-50 p-3 text-sm"
                                    >
                                        <BadgeCheck className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className={`${card} p-5`}>
                            <h2 className="mb-3 flex items-center gap-2 font-black">
                                <Lightbulb className="size-5 text-red-600" />{' '}
                                Recomendaciones
                            </h2>
                            <ol className="space-y-2.5">
                                {recommendations.map((item, index) => (
                                    <li
                                        key={item}
                                        className="flex items-start gap-3 text-sm"
                                    >
                                        <span className="grid size-6 shrink-0 place-items-center rounded-full bg-red-700 text-xs font-black text-white">
                                            {index + 1}
                                        </span>
                                        {item}
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>

                    <p className="flex items-start gap-2 text-xs text-slate-500">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        <span>
                            El trabajo de cada mes es lo que vence en él: tareas
                            con fecha límite, obligaciones fiscales y períodos
                            contables que terminan. Un mes alcanza la meta
                            cuando se completó al menos el {goal}% de ese
                            trabajo. Los meses futuros muestran la carga ya
                            planificada.
                        </span>
                    </p>
                </div>
            </main>
        </>
    );
}
