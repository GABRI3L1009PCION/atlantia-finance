import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    Building2,
    CheckCircle2,
    ClipboardX,
    Download,
    Gauge,
    Lightbulb,
    TrendingUp,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';

type OfficeRow = {
    name: string;
    companies: number;
    closed: number;
    progress: number;
    overdue: number;
    load: number;
    users: number;
};

type Props = {
    kpis: {
        compliance: number;
        closing_rate: number;
        companies: number;
        average_load: number;
        on_time: number | null;
        overdue_tasks: number;
        quarter_growth: number;
        quarter_new: number;
    };
    months: string[];
    firm_trend: { label: string; value: number }[];
    office_trend: { name: string; values: number[] }[];
    office_mix: { name: string; value: number }[];
    task_columns: string[];
    task_heatmap: { name: string; values: number[] }[];
    office_table: OfficeRow[];
    team: { name: string; completed: number; on_time_rate: number }[];
    insights: {
        tone: 'red' | 'amber' | 'green';
        title: string;
        detail: string;
    }[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const BRAND = '#8a0b0b';
const PALETTE = [
    '#8a0b0b',
    '#2563eb',
    '#f0a93b',
    '#16a34a',
    '#7c3aed',
    '#0ea5e9',
    '#ee6c3a',
];

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
        <div className="flex min-w-0 items-center gap-3 rounded-2xl border border-[#ebe3df] bg-white p-4 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]">
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-xs font-bold text-[#8a0b0b]">
                    {label}
                </p>
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`truncate text-xs font-semibold ${bad ? 'text-red-600' : 'text-emerald-700'}`}
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

function TrendBars({ points }: { points: { label: string; value: number }[] }) {
    const width = 520;
    const height = 220;
    const left = 36;
    const bottom = 26;
    const slot = (width - left) / Math.max(points.length, 1);
    const y = (value: number) =>
        10 + (height - bottom - 20) * (1 - Math.min(100, value) / 100);
    const line = points
        .map((point, i) => `${left + slot * i + slot / 2},${y(point.value)}`)
        .join(' ');

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 25, 50, 75, 100].map((tick) => (
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
            {points.map((point, i) => {
                const x = left + slot * i + slot / 2;

                return (
                    <g key={point.label + i}>
                        <rect
                            x={x - slot * 0.3}
                            y={y(point.value)}
                            width={slot * 0.6}
                            height={Math.max(
                                0,
                                height - bottom - y(point.value),
                            )}
                            rx="6"
                            fill={BRAND}
                            opacity="0.14"
                        />
                        <text
                            x={x}
                            y={y(point.value) - 8}
                            textAnchor="middle"
                            className="fill-[#6b1d1d] text-[10px] font-bold"
                        >
                            {point.value}%
                        </text>
                        <text
                            x={x}
                            y={height - 8}
                            textAnchor="middle"
                            className="fill-slate-500 text-[10px]"
                        >
                            {point.label}
                        </text>
                    </g>
                );
            })}
            <polyline
                points={line}
                fill="none"
                stroke={BRAND}
                strokeWidth="2.5"
            />
            {points.map((point, i) => (
                <circle
                    key={point.label + i}
                    cx={left + slot * i + slot / 2}
                    cy={y(point.value)}
                    r="3.5"
                    fill="#fff"
                    stroke={BRAND}
                    strokeWidth="2"
                />
            ))}
        </svg>
    );
}

function MultiLine({
    months,
    series,
}: {
    months: string[];
    series: { name: string; values: number[] }[];
}) {
    const width = 520;
    const height = 220;
    const left = 36;
    const bottom = 26;
    const slot = (width - left) / Math.max(months.length, 1);
    const y = (value: number) =>
        10 + (height - bottom - 20) * (1 - Math.min(100, value) / 100);

    return (
        <div>
            <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
                {[0, 25, 50, 75, 100].map((tick) => (
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
                {months.map((month, i) => (
                    <text
                        key={month + i}
                        x={left + slot * i + slot / 2}
                        y={height - 8}
                        textAnchor="middle"
                        className="fill-slate-500 text-[10px]"
                    >
                        {month}
                    </text>
                ))}
                {series.map((item, index) => (
                    <g key={item.name}>
                        <polyline
                            points={item.values
                                .map(
                                    (value, i) =>
                                        `${left + slot * i + slot / 2},${y(value)}`,
                                )
                                .join(' ')}
                            fill="none"
                            stroke={PALETTE[index % PALETTE.length]}
                            strokeWidth="2"
                        />
                        {item.values.map((value, i) => (
                            <circle
                                key={i}
                                cx={left + slot * i + slot / 2}
                                cy={y(value)}
                                r="3"
                                fill={PALETTE[index % PALETTE.length]}
                            />
                        ))}
                    </g>
                ))}
            </svg>
            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                {series.map((item, index) => (
                    <span key={item.name} className="flex items-center gap-1.5">
                        <span
                            className="size-2.5 rounded-full"
                            style={{
                                background: PALETTE[index % PALETTE.length],
                            }}
                        />
                        {item.name}
                    </span>
                ))}
            </div>
        </div>
    );
}

function Mix({ items }: { items: { name: string; value: number }[] }) {
    const total = items.reduce((sum, item) => sum + item.value, 0);
    const radius = 54;
    const circumference = 2 * Math.PI * radius;
    const lengths = items.map((item) =>
        total > 0 ? (item.value / total) * circumference : 0,
    );

    return (
        <div className="flex items-center gap-4">
            <svg viewBox="0 0 140 140" className="size-36 shrink-0">
                <circle
                    cx="70"
                    cy="70"
                    r={radius}
                    fill="none"
                    stroke="#eef1f3"
                    strokeWidth="18"
                />
                {items.map((item, i) => (
                    <circle
                        key={item.name}
                        cx="70"
                        cy="70"
                        r={radius}
                        fill="none"
                        stroke={PALETTE[i % PALETTE.length]}
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
                    className="fill-[#061633] text-[24px] font-black"
                >
                    {total}
                </text>
                <text
                    x="70"
                    y="84"
                    textAnchor="middle"
                    className="fill-slate-500 text-[10px]"
                >
                    empresas
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                {items.map((item, i) => (
                    <li key={item.name} className="flex items-center gap-2">
                        <span
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ background: PALETTE[i % PALETTE.length] }}
                        />
                        <span className="flex-1 truncate">{item.name}</span>
                        <b>
                            {total ? Math.round((item.value / total) * 100) : 0}
                            %
                        </b>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Heatmap({
    columns,
    rows,
}: {
    columns: string[];
    rows: { name: string; values: number[] }[];
}) {
    const max = Math.max(1, ...rows.flatMap((row) => row.values));

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-xs">
                <thead>
                    <tr>
                        <th />
                        {columns.map((column) => (
                            <th
                                key={column}
                                className="px-1 pb-2 font-semibold text-slate-500"
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.name}>
                            <td className="max-w-32 truncate pr-2 text-slate-600">
                                {row.name}
                            </td>
                            {row.values.map((value, i) => (
                                <td key={i} className="p-0.5">
                                    <div
                                        className="grid h-9 place-items-center rounded-md font-bold"
                                        style={{
                                            background: `rgba(138, 11, 11, ${0.06 + (value / max) * 0.8})`,
                                            color:
                                                value / max > 0.45
                                                    ? '#fff'
                                                    : '#6b1d1d',
                                        }}
                                    >
                                        {value}
                                    </div>
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function OfficeBars({ rows }: { rows: OfficeRow[] }) {
    const max = Math.max(1, ...rows.map((row) => row.companies));

    return (
        <div className="space-y-3">
            {rows.map((row) => (
                <div key={row.name}>
                    <div className="mb-1 flex justify-between text-xs">
                        <span className="truncate font-bold">{row.name}</span>
                        <span className="text-slate-500">
                            {row.closed} cerradas de {row.companies}
                        </span>
                    </div>
                    <div className="relative h-3 overflow-hidden rounded-full bg-slate-100">
                        <div
                            className="absolute inset-y-0 left-0 rounded-full bg-slate-300"
                            style={{ width: `${(row.companies / max) * 100}%` }}
                        />
                        <div
                            className="absolute inset-y-0 left-0 rounded-full"
                            style={{
                                width: `${(row.closed / max) * 100}%`,
                                background: BRAND,
                            }}
                        />
                    </div>
                </div>
            ))}
            <div className="flex gap-4 text-xs text-slate-500">
                <span className="flex items-center gap-1.5">
                    <i className="size-2.5 rounded-full bg-slate-300" />{' '}
                    Empresas
                </span>
                <span className="flex items-center gap-1.5">
                    <i
                        className="size-2.5 rounded-full"
                        style={{ background: BRAND }}
                    />{' '}
                    Cerradas
                </span>
            </div>
        </div>
    );
}

const TONE = {
    red: { icon: AlertTriangle, style: 'bg-red-50 text-red-600' },
    amber: { icon: Users, style: 'bg-amber-50 text-amber-600' },
    green: { icon: TrendingUp, style: 'bg-emerald-50 text-emerald-600' },
} as const;

export default function DirectorIndicators({
    kpis,
    months,
    firm_trend,
    office_trend,
    office_mix,
    task_columns,
    task_heatmap,
    office_table,
    team,
    insights,
}: Props) {
    return (
        <>
            <Head title="Indicadores" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <Gauge className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Indicadores
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Métricas clave de desempeño de la firma.
                                </p>
                            </div>
                        </div>
                        <a
                            href="/office/executive-dashboard/export/offices?format=pdf"
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 py-2 text-sm font-bold text-[#8a0b0b]"
                        >
                            <Download className="size-4" /> Generar reporte
                            ejecutivo
                        </a>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <Kpi
                            icon={<CheckCircle2 className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Cumplimiento promedio"
                            value={`${kpis.compliance}%`}
                            hint="Avance del período"
                        />
                        <Kpi
                            icon={<BadgeCheck className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Cierres del mes"
                            value={`${kpis.closing_rate}%`}
                            hint="Empresas con cierre"
                            bad={kpis.closing_rate === 0}
                        />
                        <Kpi
                            icon={<Users className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Carga promedio del equipo"
                            value={`${kpis.average_load}%`}
                            hint={
                                kpis.average_load >= 85
                                    ? 'Carga alta'
                                    : 'Carga saludable'
                            }
                            bad={kpis.average_load >= 85}
                        />
                        <Kpi
                            icon={<ClipboardX className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Tareas vencidas"
                            value={String(kpis.overdue_tasks)}
                            hint={
                                kpis.overdue_tasks > 0
                                    ? 'Requieren seguimiento'
                                    : 'Sin vencidas'
                            }
                            bad={kpis.overdue_tasks > 0}
                        />
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label="Empresas cliente"
                            value={String(kpis.companies)}
                            hint={`+${kpis.quarter_new} en el trimestre`}
                        />
                        <Kpi
                            icon={<TrendingUp className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Crecimiento trimestral"
                            value={`${kpis.quarter_growth >= 0 ? '+' : ''}${kpis.quarter_growth}%`}
                            hint={
                                kpis.on_time === null
                                    ? 'Cartera de empresas'
                                    : `Puntualidad ${kpis.on_time}%`
                            }
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-3">
                        <div className={card}>
                            <Title note="Empresas con un período cerrado cada mes.">
                                Evolución del cumplimiento de la firma
                            </Title>
                            <TrendBars points={firm_trend} />
                        </div>
                        <div className={card}>
                            <Title note="Cierres por mes en cada oficina.">
                                Cumplimiento por oficina
                            </Title>
                            <MultiLine months={months} series={office_trend} />
                        </div>
                        <div className={card}>
                            <Title note="Reparto de empresas cliente por oficina.">
                                Distribución de la cartera
                            </Title>
                            <Mix items={office_mix} />
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-3">
                        <div className={card}>
                            <Title note="Tareas abiertas y completadas por oficina.">
                                Carga de trabajo por estado de tarea
                            </Title>
                            <Heatmap
                                columns={task_columns}
                                rows={task_heatmap}
                            />
                        </div>
                        <div className={card}>
                            <Title note="Empresas de cada oficina y cuántas ya cerraron.">
                                Avance de cierres por oficina
                            </Title>
                            <OfficeBars rows={office_table} />
                        </div>
                        <div className={card}>
                            <Title note="Tareas terminadas a tiempo por colaborador.">
                                Puntualidad del equipo
                            </Title>
                            <ul className="space-y-3">
                                {team.map((member) => (
                                    <li key={member.name}>
                                        <div className="mb-1 flex justify-between text-sm">
                                            <span className="font-bold">
                                                {member.name}
                                            </span>
                                            <span className="text-slate-500">
                                                {member.completed} terminadas ·{' '}
                                                <b className="text-[#061633]">
                                                    {member.on_time_rate}%
                                                </b>
                                            </span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className="h-full rounded-full bg-emerald-500"
                                                style={{
                                                    width: `${member.on_time_rate}%`,
                                                }}
                                            />
                                        </div>
                                    </li>
                                ))}
                                {team.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        Aún no hay tareas terminadas en el
                                        período.
                                    </li>
                                )}
                            </ul>
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.2fr_1fr]">
                        <div className={`${card} overflow-x-auto`}>
                            <Title>Indicadores clave por oficina</Title>
                            <table className="w-full min-w-[560px] text-sm">
                                <thead>
                                    <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                        <th className="px-3 py-2">Oficina</th>
                                        <th className="px-3 py-2 text-center">
                                            Empresas
                                        </th>
                                        <th className="px-3 py-2 text-center">
                                            Cerradas
                                        </th>
                                        <th className="px-3 py-2 text-center">
                                            Cumplimiento
                                        </th>
                                        <th className="px-3 py-2 text-center">
                                            Atrasadas
                                        </th>
                                        <th className="px-3 py-2 text-center">
                                            Carga equipo
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {office_table.map((row) => (
                                        <tr key={row.name}>
                                            <td className="px-3 py-3 font-bold">
                                                {row.name}
                                            </td>
                                            <td className="px-3 py-3 text-center">
                                                {row.companies}
                                            </td>
                                            <td className="px-3 py-3 text-center">
                                                {row.closed}
                                            </td>
                                            <td className="px-3 py-3 text-center font-bold text-emerald-600">
                                                {row.progress}%
                                            </td>
                                            <td
                                                className={`px-3 py-3 text-center font-bold ${row.overdue > 0 ? 'text-red-600' : ''}`}
                                            >
                                                {row.overdue}
                                            </td>
                                            <td
                                                className={`px-3 py-3 text-center font-bold ${row.load >= 85 ? 'text-red-600' : ''}`}
                                            >
                                                {row.load}%
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className={card}>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-base font-black">
                                    <Lightbulb className="size-5 text-amber-500" />
                                    Hallazgos y recomendaciones clave
                                </h2>
                                <Link
                                    href="/office/executive-dashboard"
                                    className="rounded-lg border border-[#ebe3df] px-3 py-1 text-xs font-bold text-[#8a0b0b]"
                                >
                                    Ver panel
                                </Link>
                            </div>
                            <ul className="space-y-3">
                                {insights.map((item) => {
                                    const tone = TONE[item.tone];
                                    const Icon = tone.icon;

                                    return (
                                        <li
                                            key={item.title}
                                            className="flex items-start gap-3 rounded-xl border border-slate-100 p-3"
                                        >
                                            <span
                                                className={`grid size-10 shrink-0 place-items-center rounded-full ${tone.style}`}
                                            >
                                                <Icon className="size-5" />
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
                                    );
                                })}
                            </ul>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
