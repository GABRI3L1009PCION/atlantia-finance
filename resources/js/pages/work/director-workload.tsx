import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    ClipboardList,
    Clock3,
    Download,
    Lightbulb,
    PieChart,
    Users,
    UsersRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Person = {
    user_id: number;
    name: string;
    role: string;
    companies: number;
    pending: number;
    overdue: number;
    due_today: number;
    due_week: number;
    load: number;
};

type Props = {
    summary: {
        active: number;
        overdue: number;
        overloaded: number;
        overloaded_rate: number;
        capacity: number;
        average_age: number;
    };
    people: Person[];
    roles: string[];
    by_office: { name: string; total: number; overdue: number }[];
    by_role: { role: string; tasks: number }[];
    recommendations: {
        tone: 'red' | 'amber' | 'blue' | 'green';
        title: string;
        detail: string;
    }[];
    reassign: {
        title: string;
        company: string | null;
        assigned: string;
        due_at: string;
        overdue: boolean;
    }[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const control =
    'h-10 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const PALETTE = [
    '#2563eb',
    '#ee6c3a',
    '#f0a93b',
    '#4f46e5',
    '#7c3aed',
    '#ec7fa1',
    '#16a34a',
];

function loadColor(value: number): string {
    return value >= 100
        ? '#dc2626'
        : value >= 90
          ? '#ee6c3a'
          : value >= 75
            ? '#f0a93b'
            : '#16a34a';
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
    bad?: boolean;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 !p-4`}>
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
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

function OfficeBars({ rows }: { rows: Props['by_office'] }) {
    const max = Math.max(1, ...rows.map((row) => row.total));

    return (
        <div>
            <div className="flex h-56 items-end gap-5 border-b border-slate-100 px-2">
                {rows.map((row) => (
                    <div
                        key={row.name}
                        className="flex flex-1 flex-col items-center justify-end"
                    >
                        <div className="flex h-44 items-end gap-1.5">
                            <div className="flex flex-col items-center justify-end">
                                <span className="mb-1 text-xs font-bold">
                                    {row.total}
                                </span>
                                <div
                                    className="w-9 rounded-t-md bg-[#8a0b0b]"
                                    style={{
                                        height: `${(row.total / max) * 150}px`,
                                    }}
                                />
                            </div>
                            <div className="flex flex-col items-center justify-end">
                                <span className="mb-1 text-xs font-bold">
                                    {row.overdue}
                                </span>
                                <div
                                    className="w-6 rounded-t-md bg-pink-200"
                                    style={{
                                        height: `${(row.overdue / max) * 150}px`,
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                ))}
                {rows.length === 0 && (
                    <p className="m-auto text-sm text-slate-500">
                        Sin tareas abiertas.
                    </p>
                )}
            </div>
            <div className="mt-1 flex gap-5 px-2">
                {rows.map((row) => (
                    <p
                        key={row.name}
                        className="flex-1 truncate text-center text-xs text-slate-600"
                    >
                        {row.name}
                    </p>
                ))}
            </div>
        </div>
    );
}

function RoleDonut({ rows }: { rows: Props['by_role'] }) {
    const total = rows.reduce((sum, row) => sum + row.tasks, 0);
    const radius = 54;
    const circumference = 2 * Math.PI * radius;
    const lengths = rows.map((row) =>
        total > 0 ? (row.tasks / total) * circumference : 0,
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
                        key={row.role}
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
                    tareas
                </text>
            </svg>
            <table className="min-w-0 flex-1 text-sm">
                <thead>
                    <tr className="text-xs text-slate-500">
                        <th />
                        <th className="text-right font-semibold">%</th>
                        <th className="text-right font-semibold">Tareas</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={row.role}>
                            <td className="py-1">
                                <span className="flex items-center gap-2">
                                    <i
                                        className="size-2.5 shrink-0 rounded-full"
                                        style={{
                                            background:
                                                PALETTE[i % PALETTE.length],
                                        }}
                                    />
                                    <span className="truncate">{row.role}</span>
                                </span>
                            </td>
                            <td className="text-right font-bold">
                                {total
                                    ? Math.round((row.tasks / total) * 100)
                                    : 0}
                                %
                            </td>
                            <td className="text-right font-bold">
                                {row.tasks}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function formatDue(value: string, overdue: boolean): string {
    if (overdue) {
        return 'Vencida';
    }

    const days = Math.ceil(
        (new Date(value).getTime() - Date.now()) / 86_400_000,
    );

    return days <= 0
        ? 'Vence hoy'
        : days === 1
          ? 'Vence mañana'
          : `Vence en ${days} días`;
}

function exportCsv(rows: Person[]) {
    const header = [
        'Colaborador',
        'Rol',
        'Empresas',
        'Tareas abiertas',
        'Vencidas',
        'Vencen hoy',
        'Vencen esta semana',
        'Utilización %',
    ].join(',');
    const lines = rows.map((row) =>
        [
            row.name,
            row.role,
            row.companies,
            row.pending,
            row.overdue,
            row.due_today,
            row.due_week,
            row.load,
        ]
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const blob = new Blob(['﻿' + [header, ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'carga-de-trabajo.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

const TONE = {
    red: 'bg-red-50 text-red-600',
    amber: 'bg-amber-50 text-amber-600',
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-emerald-50 text-emerald-600',
} as const;

export default function DirectorWorkload({
    summary,
    people,
    roles,
    by_office,
    by_role,
    recommendations,
    reassign,
}: Props) {
    const [role, setRole] = useState('');
    const visible = useMemo(
        () => people.filter((person) => role === '' || person.role === role),
        [people, role],
    );

    return (
        <>
            <Head title="Carga de trabajo" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <UsersRound className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Carga de trabajo
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Visualiza la distribución de tareas del
                                    equipo.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => exportCsv(visible)}
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 py-2 text-sm font-bold text-[#8a0b0b]"
                        >
                            <Download className="size-4" /> Exportar reporte
                        </button>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<ClipboardList className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Tareas activas"
                            value={String(summary.active)}
                            hint="Abiertas en este momento"
                        />
                        <Kpi
                            icon={<AlertTriangle className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Tareas vencidas"
                            value={String(summary.overdue)}
                            hint={
                                summary.overdue > 0
                                    ? 'Requieren seguimiento'
                                    : 'Sin vencidas'
                            }
                            bad={summary.overdue > 0}
                        />
                        <Kpi
                            icon={<Users className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Colaboradores sobrecargados"
                            value={String(summary.overloaded)}
                            hint={`${summary.overloaded_rate}% del equipo`}
                            bad={summary.overloaded > 0}
                        />
                        <Kpi
                            icon={<PieChart className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Capacidad disponible"
                            value={`${summary.capacity}%`}
                            hint="Según la utilización promedio"
                        />
                        <Kpi
                            icon={<Clock3 className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Antigüedad promedio"
                            value={`${summary.average_age} días`}
                            hint="De las tareas abiertas"
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.7fr_1fr]">
                        <div className={`${card} min-w-0`}>
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-lg font-black">
                                    Carga de trabajo por colaborador
                                </h2>
                                <select
                                    className={control}
                                    value={role}
                                    onChange={(event) =>
                                        setRole(event.target.value)
                                    }
                                >
                                    <option value="">Todos los roles</option>
                                    {roles.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-sm">
                                    <thead>
                                        <tr className="text-left text-xs text-slate-600">
                                            <th className="px-2 py-2">
                                                Colaborador
                                            </th>
                                            <th className="px-2 py-2">Rol</th>
                                            <th className="px-2 py-2">
                                                Carga actual
                                            </th>
                                            <th className="px-2 py-2 text-center">
                                                Empresas
                                            </th>
                                            <th className="px-2 py-2 text-center">
                                                Tareas pendientes
                                            </th>
                                            <th className="px-2 py-2 text-center">
                                                Vencen hoy
                                            </th>
                                            <th className="px-2 py-2 text-center">
                                                Vencen esta semana
                                            </th>
                                            <th className="px-2 py-2 text-center">
                                                Utilización
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {visible.map((person) => (
                                            <tr key={person.user_id}>
                                                <td className="px-2 py-3 font-black">
                                                    {person.name}
                                                </td>
                                                <td className="px-2 py-3 text-slate-600">
                                                    {person.role}
                                                </td>
                                                <td className="px-2 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className="w-10 text-xs font-bold"
                                                            style={{
                                                                color: loadColor(
                                                                    person.load,
                                                                ),
                                                            }}
                                                        >
                                                            {person.load}%
                                                        </span>
                                                        <div className="h-2.5 w-32 overflow-hidden rounded-full bg-slate-100">
                                                            <div
                                                                className="h-full rounded-full"
                                                                style={{
                                                                    width: `${Math.min(100, person.load)}%`,
                                                                    background:
                                                                        loadColor(
                                                                            person.load,
                                                                        ),
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-2 py-3 text-center">
                                                    {person.companies}
                                                </td>
                                                <td className="px-2 py-3 text-center">
                                                    {person.pending}
                                                </td>
                                                <td
                                                    className={`px-2 py-3 text-center font-bold ${person.due_today > 0 ? 'text-red-600' : ''}`}
                                                >
                                                    {person.due_today}
                                                </td>
                                                <td className="px-2 py-3 text-center">
                                                    {person.due_week}
                                                </td>
                                                <td
                                                    className="px-2 py-3 text-center font-black"
                                                    style={{
                                                        color: loadColor(
                                                            person.load,
                                                        ),
                                                    }}
                                                >
                                                    {person.load}%
                                                </td>
                                            </tr>
                                        ))}
                                        {visible.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={8}
                                                    className="px-3 py-10 text-center text-slate-500"
                                                >
                                                    No hay colaboradores con ese
                                                    rol.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="space-y-4">
                            <div className={card}>
                                <div className="mb-3 flex items-center justify-between">
                                    <h2 className="flex items-center gap-2 text-lg font-black">
                                        <Lightbulb className="size-5 text-amber-500" />
                                        Recomendaciones de balanceo
                                    </h2>
                                    <span className="rounded-full bg-red-50 px-2.5 py-1 text-xs font-bold text-red-600">
                                        {recommendations.length} acciones
                                    </span>
                                </div>
                                <ul className="space-y-2.5">
                                    {recommendations.map((item) => (
                                        <li
                                            key={item.title}
                                            className="flex items-start gap-3 rounded-xl border border-slate-100 p-3"
                                        >
                                            <span
                                                className={`grid size-10 shrink-0 place-items-center rounded-full ${TONE[item.tone]}`}
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
                            <div className={card}>
                                <div className="mb-3 flex items-center justify-between">
                                    <h2 className="flex items-center gap-2 text-lg font-black">
                                        <CalendarClock className="size-5 text-red-600" />
                                        Tareas sugeridas para reasignar
                                    </h2>
                                    <span className="rounded-full bg-red-50 px-2.5 py-1 text-xs font-bold text-red-600">
                                        {reassign.length} tareas
                                    </span>
                                </div>
                                <ul className="divide-y divide-slate-100 text-sm">
                                    {reassign.map((task, index) => (
                                        <li
                                            key={`${task.title}-${index}`}
                                            className="flex items-center justify-between gap-3 py-2.5"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-bold">
                                                    {task.title}
                                                </p>
                                                <p className="truncate text-xs text-slate-500">
                                                    {task.company ?? 'Firma'} ·{' '}
                                                    {task.assigned}
                                                </p>
                                            </div>
                                            <span
                                                className={`shrink-0 rounded-md px-2 py-1 text-xs font-bold ${task.overdue ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'}`}
                                            >
                                                {formatDue(
                                                    task.due_at,
                                                    task.overdue,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                    {reassign.length === 0 && (
                                        <li className="py-3 text-slate-500">
                                            No hay tareas urgentes en
                                            colaboradores sobrecargados.
                                        </li>
                                    )}
                                </ul>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-2">
                        <div className={card}>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="text-lg font-black">
                                    Distribución de tareas por oficina
                                </h2>
                                <div className="flex gap-4 text-xs text-slate-600">
                                    <span className="flex items-center gap-1.5">
                                        <i className="size-2.5 rounded-full bg-[#8a0b0b]" />{' '}
                                        Tareas totales
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <i className="size-2.5 rounded-full bg-pink-200" />{' '}
                                        Tareas vencidas
                                    </span>
                                </div>
                            </div>
                            <OfficeBars rows={by_office} />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 text-lg font-black">
                                Distribución de tareas por rol
                            </h2>
                            <RoleDonut rows={by_role} />
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
