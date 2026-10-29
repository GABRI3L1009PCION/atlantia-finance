import { Head } from '@inertiajs/react';
import {
    CheckSquare,
    ClipboardList,
    Clock3,
    Download,
    Lightbulb,
    Target,
    TrendingUp,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Person = {
    user_id: number;
    name: string;
    role: string;
    completed: number;
    on_time: number;
    cycle_days: number;
    open: number;
};

type Props = {
    summary: {
        completed: number;
        cycle_days: number;
        on_time: number;
        open: number;
        returned_rate: number;
        weeks: number;
    };
    weeks: { label: string; completed: number; on_time: number }[];
    by_person: Person[];
    by_office: { name: string; value: number }[];
    by_role: { name: string; value: number }[];
    by_module: { name: string; value: number; on_time: number }[];
    top: Person[];
    support: {
        name: string;
        role: string;
        open: number;
        overdue: number;
        on_time: number | null;
    }[];
    recommendations: {
        tone: 'red' | 'amber' | 'green' | 'blue';
        title: string;
        detail: string;
    }[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const BRAND = '#8a0b0b';
const PALETTE = [
    '#16a34a',
    '#2563eb',
    '#f0a93b',
    '#ee6c3a',
    '#7c3aed',
    '#94a3b8',
];
const TONE = {
    red: 'bg-red-50 text-red-600',
    amber: 'bg-amber-50 text-amber-600',
    green: 'bg-emerald-50 text-emerald-600',
    blue: 'bg-blue-50 text-blue-600',
} as const;

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
    bad?: boolean;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 !p-4`}>
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
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
                    className={`truncate text-xs font-semibold ${bad ? 'text-red-600' : 'text-slate-500'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Title({ children, note }: { children: ReactNode; note?: string }) {
    return (
        <div className="mb-3">
            <h2 className="text-base font-black text-[#061633]">{children}</h2>
            {note && <p className="text-xs text-slate-500">{note}</p>}
        </div>
    );
}

function WeeklyChart({ weeks }: { weeks: Props['weeks'] }) {
    const width = 520;
    const height = 200;
    const left = 34;
    const bottom = 26;
    const slot = (width - left) / Math.max(weeks.length, 1);
    const max = Math.max(1, ...weeks.map((week) => week.completed));
    const bar = (value: number) => (height - bottom - 24) * (value / max);
    const y = (value: number) =>
        10 + (height - bottom - 24) * (1 - Math.min(100, value) / 100);
    const line = weeks
        .map((week, i) => `${left + slot * i + slot / 2},${y(week.on_time)}`)
        .join(' ');

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 50, 100].map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width}
                        y1={y(tick)}
                        y2={y(tick)}
                        stroke="#eee7e3"
                    />
                    <text
                        x={left - 6}
                        y={y(tick)}
                        textAnchor="end"
                        dominantBaseline="central"
                        className="fill-slate-400 text-[10px]"
                    >
                        {tick}%
                    </text>
                </g>
            ))}
            {weeks.map((week, i) => {
                const x = left + slot * i + slot / 2;

                return (
                    <g key={week.label + i}>
                        <rect
                            x={x - slot * 0.28}
                            y={height - bottom - bar(week.completed)}
                            width={slot * 0.56}
                            height={bar(week.completed)}
                            rx="5"
                            fill={BRAND}
                            opacity="0.14"
                        />
                        <text
                            x={x}
                            y={height - 8}
                            textAnchor="middle"
                            className="fill-slate-500 text-[10px]"
                        >
                            {week.label}
                        </text>
                        <text
                            x={x}
                            y={y(week.on_time) - 8}
                            textAnchor="middle"
                            className="fill-[#6b1d1d] text-[10px] font-bold"
                        >
                            {week.on_time}%
                        </text>
                    </g>
                );
            })}
            <polyline
                points={line}
                fill="none"
                stroke={BRAND}
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            {weeks.map((week, i) => (
                <circle
                    key={week.label + i}
                    cx={left + slot * i + slot / 2}
                    cy={y(week.on_time)}
                    r="3.5"
                    fill="#fff"
                    stroke={BRAND}
                    strokeWidth="2"
                />
            ))}
        </svg>
    );
}

function HBars({
    rows,
    unit,
}: {
    rows: { name: string; value: number }[];
    unit?: string;
}) {
    const max = Math.max(1, ...rows.map((row) => row.value));

    return (
        <ul className="space-y-2.5">
            {rows.map((row, i) => (
                <li
                    key={row.name}
                    className="grid grid-cols-[minmax(0,8.5rem)_1fr_auto] items-center gap-3 text-sm"
                >
                    <span className="truncate">{row.name}</span>
                    <div className="h-4 overflow-hidden rounded bg-slate-100">
                        <div
                            className="h-full rounded"
                            style={{
                                width: `${(row.value / max) * 100}%`,
                                background:
                                    i < 2
                                        ? '#16a34a'
                                        : i < 4
                                          ? '#f0a93b'
                                          : '#ee6c3a',
                            }}
                        />
                    </div>
                    <b className="w-12 text-right">
                        {row.value}
                        {unit}
                    </b>
                </li>
            ))}
            {rows.length === 0 && (
                <li className="text-sm text-slate-500">
                    Sin tareas terminadas todavía.
                </li>
            )}
        </ul>
    );
}

function ModuleDonut({ rows }: { rows: Props['by_module'] }) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    const radius = 50;
    const circumference = 2 * Math.PI * radius;
    const lengths = rows.map((row) =>
        total > 0 ? (row.value / total) * circumference : 0,
    );

    return (
        <div className="flex items-center gap-4">
            <svg viewBox="0 0 130 130" className="size-36 shrink-0">
                <circle
                    cx="65"
                    cy="65"
                    r={radius}
                    fill="none"
                    stroke="#eef1f3"
                    strokeWidth="16"
                />
                {rows.map((row, i) => (
                    <circle
                        key={row.name}
                        cx="65"
                        cy="65"
                        r={radius}
                        fill="none"
                        stroke={PALETTE[i % PALETTE.length]}
                        strokeWidth="16"
                        strokeDasharray={`${lengths[i]} ${circumference - lengths[i]}`}
                        strokeDashoffset={
                            -lengths.slice(0, i).reduce((s, v) => s + v, 0)
                        }
                        transform="rotate(-90 65 65)"
                    />
                ))}
                <text
                    x="65"
                    y="64"
                    textAnchor="middle"
                    className="fill-[#061633] text-[22px] font-black"
                >
                    {total}
                </text>
                <text
                    x="65"
                    y="80"
                    textAnchor="middle"
                    className="fill-slate-500 text-[10px]"
                >
                    tareas
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                {rows.map((row, i) => (
                    <li key={row.name} className="flex items-center gap-2">
                        <i
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ background: PALETTE[i % PALETTE.length] }}
                        />
                        <span className="flex-1 truncate">{row.name}</span>
                        <span className="text-slate-500">
                            {total ? Math.round((row.value / total) * 100) : 0}%
                            ({row.value})
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function exportCsv(rows: Person[]) {
    const header = [
        'Colaborador',
        'Rol',
        'Tareas terminadas',
        'A tiempo %',
        'Entrega promedio (días)',
        'Abiertas',
    ].join(',');
    const lines = rows.map((row) =>
        [
            row.name,
            row.role,
            row.completed,
            row.on_time,
            row.cycle_days,
            row.open,
        ]
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const blob = new Blob(['﻿' + [header, ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'productividad.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function DirectorProductivity({
    summary,
    weeks,
    by_person,
    by_office,
    by_role,
    by_module,
    top,
    support,
    recommendations,
}: Props) {
    const [tab, setTab] = useState<'top' | 'support'>('top');
    const lastWeeks = weeks.filter((week) => week.completed > 0);
    const trend =
        lastWeeks.length >= 2
            ? lastWeeks[lastWeeks.length - 1].on_time - lastWeeks[0].on_time
            : 0;

    return (
        <>
            <Head title="Productividad" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <TrendingUp className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Productividad
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Analiza el desempeño de tu equipo e
                                    identifica oportunidades.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => exportCsv(by_person)}
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 py-2 text-sm font-bold text-[#8a0b0b]"
                        >
                            <Download className="size-4" /> Generar reporte de
                            productividad
                        </button>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<CheckSquare className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Tareas completadas"
                            value={String(summary.completed)}
                            hint={`Últimas ${summary.weeks} semanas`}
                        />
                        <Kpi
                            icon={<Clock3 className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Tiempo promedio de entrega"
                            value={`${summary.cycle_days} días`}
                            hint="Desde que se crea hasta que termina"
                        />
                        <Kpi
                            icon={<Target className="size-7" />}
                            tone="bg-green-50 text-green-600"
                            label="Tasa de cumplimiento"
                            value={`${summary.on_time}%`}
                            hint="Terminadas a tiempo"
                            bad={summary.on_time < 70 && summary.completed > 0}
                        />
                        <Kpi
                            icon={<ClipboardList className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Tareas abiertas"
                            value={String(summary.open)}
                            hint="Trabajo en curso"
                        />
                        <Kpi
                            icon={<Undo2 className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Tareas devueltas"
                            value={`${summary.returned_rate}%`}
                            hint="Con cambios solicitados"
                            bad={summary.returned_rate > 20}
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.2fr_1fr_1fr_0.8fr]">
                        <div className={card}>
                            <Title note="Barras: tareas terminadas. Línea: % a tiempo.">
                                Productividad semanal de la firma
                            </Title>
                            <WeeklyChart weeks={weeks} />
                        </div>
                        <div className={card}>
                            <Title note="Tareas terminadas en el período.">
                                Productividad por colaborador
                            </Title>
                            <HBars
                                rows={by_person.map((person) => ({
                                    name: person.name,
                                    value: person.completed,
                                }))}
                            />
                        </div>
                        <div className={card}>
                            <Title note="Tareas terminadas en el período.">
                                Productividad por oficina
                            </Title>
                            <HBars rows={by_office} />
                        </div>
                        <div className={card}>
                            <Title>Detalle de desempeño</Title>
                            <dl className="space-y-3 text-sm">
                                <div className="flex items-baseline justify-between">
                                    <dt className="text-slate-500">
                                        Tareas completadas
                                    </dt>
                                    <dd className="text-xl font-black">
                                        {summary.completed}
                                    </dd>
                                </div>
                                <div className="flex items-baseline justify-between">
                                    <dt className="text-slate-500">
                                        Cumplimiento en tiempo
                                    </dt>
                                    <dd className="text-xl font-black">
                                        {summary.on_time}%
                                    </dd>
                                </div>
                                <div className="flex items-baseline justify-between">
                                    <dt className="text-slate-500">
                                        Entrega promedio
                                    </dt>
                                    <dd className="text-xl font-black">
                                        {summary.cycle_days} días
                                    </dd>
                                </div>
                                <div className="flex items-baseline justify-between">
                                    <dt className="text-slate-500">
                                        Tendencia de puntualidad
                                    </dt>
                                    <dd
                                        className={`text-xl font-black ${trend >= 0 ? 'text-emerald-600' : 'text-red-600'}`}
                                    >
                                        {trend >= 0 ? '+' : ''}
                                        {trend} pts
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-3">
                        <div className={card}>
                            <Title note="Tareas terminadas por rol.">
                                Productividad por rol
                            </Title>
                            <HBars rows={by_role} />
                        </div>
                        <div className={card}>
                            <Title note="Tareas terminadas por tipo de trabajo.">
                                Distribución del trabajo
                            </Title>
                            <ModuleDonut rows={by_module} />
                        </div>
                        <div className={card}>
                            <Title note="% de tareas terminadas a tiempo por tipo.">
                                Eficiencia de procesos clave
                            </Title>
                            <HBars
                                rows={by_module.map((row) => ({
                                    name: row.name,
                                    value: row.on_time,
                                }))}
                                unit="%"
                            />
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.3fr_1.2fr_1fr]">
                        <div className={`${card} overflow-x-auto`}>
                            <div className="mb-3 flex gap-5 border-b border-slate-100 text-sm font-bold">
                                <button
                                    type="button"
                                    onClick={() => setTab('top')}
                                    className={`-mb-px border-b-2 pb-2 ${tab === 'top' ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-500'}`}
                                >
                                    Top performers
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setTab('support')}
                                    className={`-mb-px border-b-2 pb-2 ${tab === 'support' ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-500'}`}
                                >
                                    Necesitan apoyo
                                </button>
                            </div>
                            {tab === 'top' ? (
                                <table className="w-full min-w-[480px] text-sm">
                                    <thead>
                                        <tr className="text-left text-xs text-slate-600">
                                            <th className="py-2">#</th>
                                            <th>Colaborador</th>
                                            <th>Rol</th>
                                            <th className="text-center">
                                                Tareas
                                            </th>
                                            <th className="text-center">
                                                A tiempo
                                            </th>
                                            <th className="text-center">
                                                Entrega
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {top.map((person, index) => (
                                            <tr key={person.user_id}>
                                                <td className="py-2.5 text-slate-500">
                                                    {index + 1}
                                                </td>
                                                <td className="font-black">
                                                    {person.name}
                                                </td>
                                                <td className="text-slate-600">
                                                    {person.role}
                                                </td>
                                                <td className="text-center font-bold">
                                                    {person.completed}
                                                </td>
                                                <td className="text-center font-bold text-emerald-600">
                                                    {person.on_time}%
                                                </td>
                                                <td className="text-center">
                                                    {person.cycle_days} días
                                                </td>
                                            </tr>
                                        ))}
                                        {top.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={6}
                                                    className="py-8 text-center text-slate-500"
                                                >
                                                    Aún no hay tareas
                                                    terminadas.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            ) : (
                                <table className="w-full min-w-[480px] text-sm">
                                    <thead>
                                        <tr className="text-left text-xs text-slate-600">
                                            <th className="py-2">
                                                Colaborador
                                            </th>
                                            <th>Rol</th>
                                            <th className="text-center">
                                                Abiertas
                                            </th>
                                            <th className="text-center">
                                                Vencidas
                                            </th>
                                            <th className="text-center">
                                                A tiempo
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {support.map((person) => (
                                            <tr key={person.name}>
                                                <td className="py-2.5 font-black">
                                                    {person.name}
                                                </td>
                                                <td className="text-slate-600">
                                                    {person.role}
                                                </td>
                                                <td className="text-center font-bold">
                                                    {person.open}
                                                </td>
                                                <td
                                                    className={`text-center font-bold ${person.overdue > 0 ? 'text-red-600' : ''}`}
                                                >
                                                    {person.overdue}
                                                </td>
                                                <td className="text-center">
                                                    {person.on_time === null
                                                        ? '—'
                                                        : `${person.on_time}%`}
                                                </td>
                                            </tr>
                                        ))}
                                        {support.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="py-8 text-center text-slate-500"
                                                >
                                                    Nadie necesita apoyo por
                                                    ahora.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            )}
                        </div>

                        <div className={`${card} overflow-x-auto`}>
                            <Title note="Quienes más trabajo abierto y vencido acumulan.">
                                Usuarios que necesitan apoyo
                            </Title>
                            <table className="w-full min-w-[420px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-600">
                                        <th className="py-2">#</th>
                                        <th>Colaborador</th>
                                        <th className="text-center">
                                            Abiertas
                                        </th>
                                        <th className="text-center">
                                            Vencidas
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {support.map((person, index) => (
                                        <tr key={person.name}>
                                            <td className="py-2.5 text-slate-500">
                                                {index + 1}
                                            </td>
                                            <td className="font-black">
                                                {person.name}
                                            </td>
                                            <td className="text-center font-bold">
                                                {person.open}
                                            </td>
                                            <td
                                                className={`text-center font-bold ${person.overdue > 0 ? 'text-red-600' : ''}`}
                                            >
                                                {person.overdue}
                                            </td>
                                        </tr>
                                    ))}
                                    {support.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="py-8 text-center text-slate-500"
                                            >
                                                Sin trabajo pendiente.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className={card}>
                            <h2 className="mb-3 flex items-center gap-2 text-base font-black">
                                <Lightbulb className="size-5 text-amber-500" />
                                Recomendaciones
                            </h2>
                            <ul className="space-y-3">
                                {recommendations.map((item) => (
                                    <li
                                        key={item.title}
                                        className="flex items-start gap-3"
                                    >
                                        <span
                                            className={`grid size-9 shrink-0 place-items-center rounded-full ${TONE[item.tone]}`}
                                        >
                                            <Lightbulb className="size-5" />
                                        </span>
                                        <div>
                                            <p className="text-sm font-black">
                                                {item.title}
                                            </p>
                                            <p className="text-xs leading-5 text-slate-600">
                                                {item.detail}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
