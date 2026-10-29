import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    FileSearch,
    Lightbulb,
    ShieldCheck,
    TrendingUp,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type State = 'done' | 'overdue' | 'due_soon' | 'pending';

type Obligation = {
    id: number;
    company_id: number;
    company: string;
    office: string;
    type: string;
    type_label: string;
    name: string;
    due_on: string;
    days: number;
    responsible: string | null;
    state: State;
};

type Props = {
    kpis: {
        on_time: number;
        on_time_rate: number;
        total: number;
        overdue: number;
        overdue_rate: number;
        companies_clean: number;
        companies: number;
        in_review: number;
        monthly_rate: number;
    };
    obligations: Obligation[];
    calendar: { date: string; state: State }[];
    offices: { name: string; obligations: number; value: number }[];
    types: { label: string; value: number }[];
    alerts: { tone: 'red' | 'amber'; title: string; detail: string }[];
    suggestions: { title: string; detail: string }[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const PALETTE = [
    '#16a34a',
    '#f0a93b',
    '#2563eb',
    '#7c3aed',
    '#ee6c3a',
    '#94a3b8',
];
const DOT: Record<State, string> = {
    done: '#16a34a',
    pending: '#16a34a',
    due_soon: '#f0a93b',
    overdue: '#dc2626',
};
const PAGE_SIZE = 8;
const WEEKDAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const MONTHS = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

function dateKey(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function formatDate(value: string): string {
    return new Date(`${value}T12:00:00`).toLocaleDateString('es-GT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function Kpi({
    icon,
    tone,
    label,
    value,
    caption,
    rate,
    bad,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: string;
    caption: string;
    rate: string;
    bad?: boolean;
}) {
    return (
        <div className="flex min-w-0 items-center gap-3 rounded-2xl border border-[#ebe3df] bg-white p-4 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]">
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0 flex-1">
                <p
                    className={`line-clamp-2 text-sm leading-snug font-bold ${bad ? 'text-red-600' : 'text-emerald-700'}`}
                >
                    {label}
                </p>
                <div className="flex items-end justify-between gap-2">
                    <p className="text-3xl leading-tight font-black text-[#061633]">
                        {value}
                    </p>
                    <p
                        className={`text-lg font-black ${bad ? 'text-red-600' : 'text-emerald-600'}`}
                    >
                        {rate}
                    </p>
                </div>
                <p className="line-clamp-2 text-xs leading-snug text-slate-500">
                    {caption}
                </p>
            </div>
        </div>
    );
}

function CalendarCard({ calendar }: { calendar: Props['calendar'] }) {
    const today = new Date();
    const [cursor, setCursor] = useState({
        year: today.getFullYear(),
        month: today.getMonth(),
    });
    const byDay = useMemo(() => {
        const map = new Map<string, State[]>();

        calendar.forEach((item) => {
            map.set(item.date, [...(map.get(item.date) ?? []), item.state]);
        });

        return map;
    }, [calendar]);
    const first = new Date(cursor.year, cursor.month, 1).getDay();
    const days = new Date(cursor.year, cursor.month + 1, 0).getDate();
    const cells = [
        ...Array.from({ length: first }, () => null),
        ...Array.from({ length: days }, (_, index) => index + 1),
    ];
    const todayKey = dateKey(
        today.getFullYear(),
        today.getMonth(),
        today.getDate(),
    );
    const counts = { ok: 0, soon: 0, late: 0 };

    calendar.forEach((item) => {
        const [year, month] = item.date.split('-').map(Number);

        if (year === cursor.year && month - 1 === cursor.month) {
            if (item.state === 'overdue') {
                counts.late += 1;
            } else if (item.state === 'due_soon') {
                counts.soon += 1;
            } else {
                counts.ok += 1;
            }
        }
    });

    const move = (delta: number) =>
        setCursor(({ year, month }) => {
            const next = new Date(year, month + delta, 1);

            return { year: next.getFullYear(), month: next.getMonth() };
        });

    return (
        <div className={card}>
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-lg font-black">
                    Calendario de obligaciones
                </h2>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        aria-label="Mes anterior"
                        onClick={() => move(-1)}
                        className="rounded-lg border border-[#ebe3df] p-1.5"
                    >
                        <ChevronLeft className="size-4" />
                    </button>
                    <span className="min-w-36 rounded-lg border border-[#ebe3df] px-3 py-1.5 text-center text-sm font-bold">
                        {MONTHS[cursor.month]} {cursor.year}
                    </span>
                    <button
                        type="button"
                        aria-label="Mes siguiente"
                        onClick={() => move(1)}
                        className="rounded-lg border border-[#ebe3df] p-1.5"
                    >
                        <ChevronRight className="size-4" />
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            setCursor({
                                year: today.getFullYear(),
                                month: today.getMonth(),
                            })
                        }
                        className="rounded-lg border border-[#ebe3df] px-3 py-1.5 text-sm font-bold"
                    >
                        Hoy
                    </button>
                </div>
            </div>
            <div className="grid grid-cols-7 overflow-hidden rounded-xl border border-slate-100 text-center text-sm">
                {WEEKDAYS.map((day) => (
                    <div
                        key={day}
                        className="bg-slate-50 py-2 text-xs font-bold text-slate-600"
                    >
                        {day}
                    </div>
                ))}
                {cells.map((day, index) => {
                    const key = day
                        ? dateKey(cursor.year, cursor.month, day)
                        : '';
                    const states = day ? (byDay.get(key) ?? []) : [];
                    const unique = Array.from(
                        new Set(states.map((s) => DOT[s])),
                    );

                    return (
                        <div
                            key={index}
                            className={`h-14 border-t border-l border-slate-100 py-1.5 ${key === todayKey ? 'bg-red-50' : ''}`}
                        >
                            {day && (
                                <>
                                    <p
                                        className={`font-semibold ${key === todayKey ? 'text-[#8a0b0b]' : 'text-slate-700'}`}
                                    >
                                        {day}
                                    </p>
                                    <div className="mt-1 flex justify-center gap-1">
                                        {unique.map((color) => (
                                            <span
                                                key={color}
                                                className="size-2 rounded-full"
                                                style={{ background: color }}
                                            />
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    );
                })}
            </div>
            <div className="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-1 text-xs text-slate-600">
                <span className="flex items-center gap-1.5">
                    <i className="size-2.5 rounded-full bg-emerald-600" /> Al
                    día ({counts.ok})
                </span>
                <span className="flex items-center gap-1.5">
                    <i className="size-2.5 rounded-full bg-amber-400" /> Por
                    vencer ({counts.soon})
                </span>
                <span className="flex items-center gap-1.5">
                    <i className="size-2.5 rounded-full bg-red-600" /> Vencida (
                    {counts.late})
                </span>
            </div>
        </div>
    );
}

function TypesDonut({ items }: { items: Props['types'] }) {
    const total = items.reduce((sum, item) => sum + item.value, 0);
    const radius = 50;
    const circumference = 2 * Math.PI * radius;
    const lengths = items.map((item) =>
        total > 0 ? (item.value / total) * circumference : 0,
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
                {items.map((item, i) => (
                    <circle
                        key={item.label}
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
                    en total
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                {items.map((item, i) => (
                    <li key={item.label} className="flex items-center gap-2">
                        <span
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ background: PALETTE[i % PALETTE.length] }}
                        />
                        <span className="flex-1 truncate">{item.label}</span>
                        <span className="text-slate-500">
                            {total ? Math.round((item.value / total) * 100) : 0}
                            % ({item.value})
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function stateLabel(item: Obligation): { text: string; style: string } {
    if (item.state === 'overdue') {
        return {
            text: `Vencida (${Math.abs(item.days)} día${Math.abs(item.days) === 1 ? '' : 's'})`,
            style: 'bg-red-100 text-red-700',
        };
    }

    if (item.state === 'due_soon') {
        return {
            text: `Por vencer (${item.days} día${item.days === 1 ? '' : 's'})`,
            style: 'bg-amber-100 text-amber-700',
        };
    }

    return { text: 'Pendiente', style: 'bg-slate-100 text-slate-600' };
}

function priority(item: Obligation): { text: string; style: string } {
    if (item.state === 'overdue' || item.days <= 3) {
        return { text: 'Alta', style: 'bg-red-50 text-red-700' };
    }

    if (item.days <= 10) {
        return { text: 'Media', style: 'bg-amber-50 text-amber-700' };
    }

    return { text: 'Baja', style: 'bg-emerald-50 text-emerald-700' };
}

function exportCsv(rows: Obligation[]) {
    const lines = rows.map((item) =>
        [
            item.company,
            item.office,
            item.type_label,
            item.due_on,
            item.responsible ?? 'Sin asignar',
            stateLabel(item).text,
            priority(item).text,
        ]
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const header = [
        'Empresa',
        'Oficina',
        'Obligación',
        'Vencimiento',
        'Responsable',
        'Estado',
        'Prioridad',
    ].join(',');
    const blob = new Blob(['﻿' + [header, ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'reporte-de-cumplimiento.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function DirectorCompliance({
    kpis,
    obligations,
    calendar,
    offices,
    types,
    alerts,
    suggestions,
}: Props) {
    const [page, setPage] = useState(1);
    const pages = Math.max(1, Math.ceil(obligations.length / PAGE_SIZE));
    const current = Math.min(page, pages);
    const visible = obligations.slice(
        (current - 1) * PAGE_SIZE,
        current * PAGE_SIZE,
    );

    return (
        <>
            <Head title="Cumplimiento" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <ShieldCheck className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Cumplimiento
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Monitorea las obligaciones fiscales de tus
                                    empresas cliente en Guatemala.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => exportCsv(obligations)}
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 py-2 text-sm font-bold text-[#8a0b0b]"
                        >
                            <Download className="size-4" /> Generar reporte de
                            cumplimiento
                        </button>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<CheckCircle2 className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Obligaciones al día"
                            value={String(kpis.on_time)}
                            rate={`${kpis.on_time_rate}%`}
                            caption={`de ${kpis.total} obligaciones`}
                        />
                        <Kpi
                            icon={<Clock3 className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Obligaciones vencidas"
                            value={String(kpis.overdue)}
                            rate={`${kpis.overdue_rate}%`}
                            caption="requieren atención"
                            bad={kpis.overdue > 0}
                        />
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Empresas en cumplimiento total"
                            value={String(kpis.companies_clean)}
                            rate={`${kpis.companies ? Math.round((kpis.companies_clean / kpis.companies) * 100) : 0}%`}
                            caption={`de ${kpis.companies} empresas`}
                        />
                        <Kpi
                            icon={<FileSearch className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Empresas en revisión"
                            value={String(kpis.in_review)}
                            rate={`${kpis.companies ? Math.round((kpis.in_review / kpis.companies) * 100) : 0}%`}
                            caption="con obligaciones en revisión"
                        />
                        <Kpi
                            icon={<TrendingUp className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Cumplimiento del mes"
                            value={`${kpis.monthly_rate}%`}
                            rate=""
                            caption="Obligaciones del mes sin atraso"
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.35fr_1fr_0.9fr]">
                        <div className="space-y-4 xl:col-span-2 xl:grid xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] xl:gap-4 xl:space-y-0">
                            <CalendarCard calendar={calendar} />
                            <div className="space-y-4">
                                <div className={card}>
                                    <h2 className="mb-3 text-lg font-black">
                                        Cumplimiento por oficina
                                    </h2>
                                    <div className="space-y-3">
                                        {offices.map((office) => (
                                            <div
                                                key={office.name}
                                                className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 text-sm"
                                            >
                                                <span className="truncate font-semibold">
                                                    {office.name}
                                                </span>
                                                <div className="h-3 overflow-hidden rounded-full bg-slate-100">
                                                    <div
                                                        className="h-full rounded-full"
                                                        style={{
                                                            width: `${office.value}%`,
                                                            background:
                                                                office.value >=
                                                                85
                                                                    ? '#16a34a'
                                                                    : office.value >=
                                                                        60
                                                                      ? '#f0a93b'
                                                                      : '#dc2626',
                                                        }}
                                                    />
                                                </div>
                                                <b>{office.value}%</b>
                                            </div>
                                        ))}
                                        {offices.length === 0 && (
                                            <p className="text-sm text-slate-500">
                                                Sin oficinas con obligaciones.
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className={card}>
                                    <h2 className="mb-3 text-lg font-black">
                                        Obligaciones por tipo
                                    </h2>
                                    <TypesDonut items={types} />
                                </div>
                            </div>
                        </div>

                        <div className="space-y-4">
                            <div className={card}>
                                <h2 className="mb-3 text-lg font-black">
                                    Alertas y acciones
                                </h2>
                                <ul className="space-y-3">
                                    {alerts.map((alert) => (
                                        <li
                                            key={alert.title}
                                            className={`flex items-start gap-3 rounded-xl border-l-4 p-3 ${alert.tone === 'red' ? 'border-red-500 bg-red-50/60' : 'border-amber-400 bg-amber-50/60'}`}
                                        >
                                            <AlertTriangle
                                                className={`mt-0.5 size-5 shrink-0 ${alert.tone === 'red' ? 'text-red-600' : 'text-amber-500'}`}
                                            />
                                            <div>
                                                <p className="text-sm font-black">
                                                    {alert.title}
                                                </p>
                                                <p className="text-xs text-slate-600">
                                                    {alert.detail}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                    {alerts.length === 0 && (
                                        <li className="flex items-center gap-2 rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-700">
                                            <CheckCircle2 className="size-5" />{' '}
                                            Sin alertas de cumplimiento.
                                        </li>
                                    )}
                                </ul>
                            </div>
                            <div className={card}>
                                <h2 className="mb-3 flex items-center gap-2 text-lg font-black">
                                    <Lightbulb className="size-5 text-amber-500" />
                                    Sugerencias
                                </h2>
                                <ul className="space-y-3">
                                    {suggestions.map((item) => (
                                        <li
                                            key={item.title}
                                            className="flex items-start gap-3"
                                        >
                                            <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-500">
                                                <Lightbulb className="size-5" />
                                            </span>
                                            <div>
                                                <p className="text-sm font-black">
                                                    {item.title}
                                                </p>
                                                <p className="text-xs text-slate-600">
                                                    {item.detail}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                    {suggestions.length === 0 && (
                                        <li className="text-sm text-slate-500">
                                            Nada pendiente por sugerir.
                                        </li>
                                    )}
                                </ul>
                            </div>
                        </div>
                    </section>

                    <section className={`${card} overflow-x-auto`}>
                        <h2 className="mb-3 text-lg font-black">
                            Obligaciones pendientes
                        </h2>
                        <table className="w-full min-w-[820px] text-sm">
                            <thead>
                                <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                    <th className="px-3 py-2">Empresa</th>
                                    <th className="px-3 py-2">Oficina</th>
                                    <th className="px-3 py-2">
                                        Tipo de obligación
                                    </th>
                                    <th className="px-3 py-2">
                                        Fecha de vencimiento
                                    </th>
                                    <th className="px-3 py-2">Responsable</th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2">Prioridad</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {visible.map((item) => {
                                    const state = stateLabel(item);
                                    const level = priority(item);

                                    return (
                                        <tr key={item.id}>
                                            <td className="px-3 py-3 font-black">
                                                {item.company}
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">
                                                {item.office}
                                            </td>
                                            <td className="px-3 py-3">
                                                {item.name}
                                            </td>
                                            <td className="px-3 py-3">
                                                {formatDate(item.due_on)}
                                            </td>
                                            <td className="px-3 py-3">
                                                {item.responsible ?? (
                                                    <span className="text-slate-400">
                                                        Sin asignar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-3">
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-bold ${state.style}`}
                                                >
                                                    {state.text}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <span
                                                    className={`rounded-md px-2.5 py-1 text-xs font-bold ${level.style}`}
                                                >
                                                    {level.text}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {visible.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-3 py-10 text-center text-slate-500"
                                        >
                                            No hay obligaciones pendientes.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                        <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                            <span>
                                {obligations.length} obligaciones pendientes
                            </span>
                            <div className="flex gap-1">
                                {Array.from({ length: pages }, (_, index) => (
                                    <button
                                        key={index}
                                        type="button"
                                        onClick={() => setPage(index + 1)}
                                        className={`size-8 rounded-lg text-sm font-bold ${current === index + 1 ? 'bg-[#8a0b0b] text-white' : 'border border-[#e4dbd6] text-slate-600'}`}
                                    >
                                        {index + 1}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
