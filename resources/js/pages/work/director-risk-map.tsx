import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Download,
    Gauge,
    Info,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Level = 'critical' | 'high' | 'medium' | 'low';

type RiskCompany = {
    id: number;
    name: string;
    tax_id: string;
    office: string;
    accountant: string | null;
    score: number;
    level: Level;
    blocked: boolean;
    main_label: string;
    cause: string;
    action: string;
    deadline: string | null;
    probability: number;
    impact: number;
    scores: { label: string; value: number }[];
    signals: { label: string; value: number | string }[];
};

type Props = {
    summary: {
        critical: number;
        high: number;
        blocked: number;
        overload: number;
        score: number;
    };
    companies: RiskCompany[];
    categories: {
        label: string;
        description: string;
        companies: number;
        value: number;
    }[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';

const LEVEL: Record<Level, { label: string; badge: string; color: string }> = {
    critical: {
        label: 'Crítico',
        badge: 'bg-red-600 text-white',
        color: '#dc2626',
    },
    high: {
        label: 'Alto',
        badge: 'bg-orange-500 text-white',
        color: '#f97316',
    },
    medium: {
        label: 'Medio',
        badge: 'bg-yellow-300 text-yellow-900',
        color: '#eab308',
    },
    low: {
        label: 'Bajo',
        badge: 'bg-emerald-300 text-emerald-900',
        color: '#16a34a',
    },
};
const LABELS = ['Baja', 'Media', 'Alta', 'Muy alta'];
const IMPACT_LABELS = ['Bajo', 'Medio', 'Alto', 'Muy alto'];

/** Background of a matrix cell, from probability x impact (both 1-4). */
function cellColor(probability: number, impact: number): string {
    const value = probability * impact;

    return value >= 12
        ? '#fbd5d5'
        : value >= 8
          ? '#fde3cf'
          : value >= 4
            ? '#fdf3cf'
            : '#d9f0e0';
}

function formatDate(value: string | null): string {
    return value
        ? new Date(`${value}T12:00:00`).toLocaleDateString('es-GT', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}

function Kpi({
    icon,
    label,
    value,
    hint,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <div className="flex min-w-0 items-center gap-3 rounded-2xl border border-red-100 bg-red-50/40 p-4">
            <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-red-100 text-red-600">
                {icon}
            </span>
            <div className="min-w-0">
                <p className="line-clamp-2 text-sm leading-snug font-bold text-red-700">
                    {label}
                </p>
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p className="line-clamp-2 text-xs leading-snug font-semibold text-slate-600">
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Matrix({
    companies,
    selectedId,
    onSelect,
}: {
    companies: RiskCompany[];
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    const cells = [4, 3, 2, 1];

    return (
        <div>
            <div className="grid grid-cols-[auto_1fr] gap-2">
                <div className="grid grid-rows-4 text-right text-xs text-slate-500">
                    {cells.map((impact) => (
                        <span
                            key={impact}
                            className="flex items-center justify-end pr-1"
                        >
                            {IMPACT_LABELS[impact - 1]}
                        </span>
                    ))}
                </div>
                <div className="grid h-72 grid-cols-4 grid-rows-4 overflow-hidden rounded-lg border border-slate-200">
                    {cells.flatMap((impact) =>
                        [1, 2, 3, 4].map((probability) => {
                            const here = companies.filter(
                                (company) =>
                                    company.impact === impact &&
                                    company.probability === probability,
                            );

                            return (
                                <div
                                    key={`${impact}-${probability}`}
                                    className="relative flex flex-wrap content-center items-center justify-center gap-1 border border-white/60 p-1"
                                    style={{
                                        background: cellColor(
                                            probability,
                                            impact,
                                        ),
                                    }}
                                >
                                    {here.map((company) => (
                                        <button
                                            key={company.id}
                                            type="button"
                                            title={`${company.name} · ${LEVEL[company.level].label}`}
                                            onClick={() => onSelect(company.id)}
                                            className={`grid size-6 place-items-center rounded-full border-2 border-white text-[10px] font-black text-white shadow ${selectedId === company.id ? 'ring-2 ring-[#061633]' : ''}`}
                                            style={{
                                                background:
                                                    LEVEL[company.level].color,
                                            }}
                                        >
                                            {company.name.charAt(0)}
                                        </button>
                                    ))}
                                </div>
                            );
                        }),
                    )}
                </div>
            </div>
            <div className="mt-1 grid grid-cols-[auto_1fr] gap-2">
                <span className="w-12" />
                <div className="grid grid-cols-4 text-center text-xs text-slate-500">
                    {LABELS.map((label) => (
                        <span key={label}>{label}</span>
                    ))}
                </div>
            </div>
            <p className="mt-1 text-center text-sm font-bold">Probabilidad</p>
            <div className="mt-2 flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-slate-600">
                {(Object.keys(LEVEL) as Level[]).map((level) => (
                    <span key={level} className="flex items-center gap-1.5">
                        <i
                            className="size-2.5 rounded-full"
                            style={{ background: LEVEL[level].color }}
                        />
                        {LEVEL[level].label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function Detail({ company }: { company: RiskCompany }) {
    const [tab, setTab] = useState<'Resumen' | 'Señales'>('Resumen');

    return (
        <div className={card}>
            <h2 className="mb-3 text-lg font-black">Detalle de la empresa</h2>
            <div className="flex items-center gap-3 rounded-xl bg-red-50 p-3">
                <span className="grid size-11 place-items-center rounded-lg bg-white text-red-600">
                    <AlertTriangle className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate font-black">
                        Riesgo {company.main_label.toLowerCase()} –{' '}
                        {company.name}
                    </p>
                    <p className="truncate text-xs text-slate-600">
                        NIT {company.tax_id} · {company.office}
                    </p>
                </div>
                <span
                    className={`rounded-lg px-3 py-1 text-xs font-bold ${LEVEL[company.level].badge}`}
                >
                    {LEVEL[company.level].label}
                </span>
            </div>

            <div className="mt-3 flex gap-5 border-b border-slate-100 text-sm font-bold">
                {(['Resumen', 'Señales'] as const).map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setTab(name)}
                        className={`-mb-px border-b-2 pb-2 ${tab === name ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-500'}`}
                    >
                        {name}
                    </button>
                ))}
            </div>

            {tab === 'Resumen' && (
                <div className="mt-4 space-y-4 text-sm">
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                        <div>
                            <dt className="text-xs text-slate-500">
                                Categoría principal
                            </dt>
                            <dd className="font-bold">{company.main_label}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">
                                Puntaje de riesgo
                            </dt>
                            <dd className="font-bold">{company.score}/100</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">
                                Probabilidad
                            </dt>
                            <dd className="font-bold">
                                {LABELS[company.probability - 1]}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Impacto</dt>
                            <dd className="font-bold">
                                {IMPACT_LABELS[company.impact - 1]}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">
                                Responsable
                            </dt>
                            <dd className="font-bold">
                                {company.accountant ?? 'Sin asignar'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">
                                Próxima fecha límite
                            </dt>
                            <dd className="font-bold">
                                {formatDate(company.deadline)}
                            </dd>
                        </div>
                    </dl>
                    <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-700">
                        {company.cause}
                    </p>
                    <div>
                        <h3 className="mb-2 font-black">Nivel por categoría</h3>
                        <div className="space-y-2">
                            {company.scores.map((item) => (
                                <div
                                    key={item.label}
                                    className="grid grid-cols-[8.5rem_1fr_2.5rem] items-center gap-2 text-xs"
                                >
                                    <span className="truncate">
                                        {item.label}
                                    </span>
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className="h-full rounded-full"
                                            style={{
                                                width: `${item.value}%`,
                                                background:
                                                    item.value >= 70
                                                        ? '#dc2626'
                                                        : item.value >= 40
                                                          ? '#f97316'
                                                          : item.value >= 25
                                                            ? '#eab308'
                                                            : '#16a34a',
                                            }}
                                        />
                                    </div>
                                    <b className="text-right">{item.value}</b>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div>
                        <h3 className="mb-1 font-black">Acción sugerida</h3>
                        <p className="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-900">
                            {company.action}
                        </p>
                    </div>
                </div>
            )}

            {tab === 'Señales' && (
                <ul className="mt-4 space-y-2 text-sm">
                    {company.signals.map((signal) => (
                        <li
                            key={signal.label}
                            className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                        >
                            <span>{signal.label}</span>
                            <b>{signal.value}</b>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function exportCsv(rows: RiskCompany[]) {
    const header = [
        'Posición',
        'Empresa',
        'Oficina',
        'Categoría principal',
        'Nivel',
        'Puntaje',
        'Causa principal',
        'Responsable',
        'Acción sugerida',
    ].join(',');
    const lines = rows.map((row, index) =>
        [
            index + 1,
            row.name,
            row.office,
            row.main_label,
            LEVEL[row.level].label,
            row.score,
            row.cause,
            row.accountant ?? 'Sin asignar',
            row.action,
        ]
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const blob = new Blob(['﻿' + [header, ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'mapa-de-riesgos.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function DirectorRiskMap({
    summary,
    companies,
    categories,
}: Props) {
    const [selectedId, setSelectedId] = useState<number | null>(
        companies[0]?.id ?? null,
    );
    const [showAll, setShowAll] = useState(false);
    const selected =
        companies.find((company) => company.id === selectedId) ?? null;
    const ranking = showAll ? companies : companies.slice(0, 8);

    return (
        <>
            <Head title="Mapa de riesgos" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <ShieldAlert className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Mapa de riesgos
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Identifica y evalúa los riesgos de tus
                                    empresas cliente.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => exportCsv(companies)}
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 py-2 text-sm font-bold text-[#8a0b0b]"
                        >
                            <Download className="size-4" /> Generar reporte de
                            riesgos
                        </button>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<AlertTriangle className="size-7" />}
                            label="Empresas en nivel crítico"
                            value={String(summary.critical)}
                            hint="Puntaje de 70 o más"
                        />
                        <Kpi
                            icon={<Users className="size-7" />}
                            label="Clientes de alto riesgo"
                            value={String(summary.high)}
                            hint="Nivel alto o crítico"
                        />
                        <Kpi
                            icon={<Ban className="size-7" />}
                            label="Empresas bloqueadas"
                            value={String(summary.blocked)}
                            hint="Con el cierre detenido"
                        />
                        <Kpi
                            icon={<Users className="size-7" />}
                            label="Sobrecarga del equipo"
                            value={`${summary.overload}%`}
                            hint="Personas con 90% o más de carga"
                        />
                        <Kpi
                            icon={<Gauge className="size-7" />}
                            label="Puntaje de riesgo de la firma"
                            value={`${summary.score}/100`}
                            hint="Promedio de todas las empresas"
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.3fr_1fr_1fr]">
                        <div className={card}>
                            <h2 className="mb-3 text-lg font-black">
                                Matriz de riesgos
                            </h2>
                            <Matrix
                                companies={companies}
                                selectedId={selected?.id ?? null}
                                onSelect={setSelectedId}
                            />
                        </div>

                        <div className={card}>
                            <h2 className="mb-3 text-lg font-black">
                                Categorías de riesgo
                            </h2>
                            <ul className="space-y-4">
                                {categories.map((category) => (
                                    <li key={category.label}>
                                        <div className="mb-1 flex items-baseline justify-between gap-2">
                                            <p className="font-black">
                                                {category.label}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {category.companies} empresas
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                                <div
                                                    className="h-full rounded-full"
                                                    style={{
                                                        width: `${category.value}%`,
                                                        background:
                                                            category.value >= 60
                                                                ? '#dc2626'
                                                                : category.value >=
                                                                    30
                                                                  ? '#f97316'
                                                                  : '#16a34a',
                                                    }}
                                                />
                                            </div>
                                            <b className="w-10 text-right text-sm">
                                                {category.value}%
                                            </b>
                                        </div>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {category.description}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {selected ? (
                            <Detail key={selected.id} company={selected} />
                        ) : (
                            <div className={card}>Sin empresas.</div>
                        )}
                    </section>

                    <section className={`${card} overflow-x-auto`}>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-lg font-black">
                                Ranking de empresas por nivel de riesgo
                            </h2>
                            {companies.length > 8 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowAll((value) => !value)
                                    }
                                    className="rounded-lg border border-[#ebe3df] px-3 py-1.5 text-xs font-bold text-[#8a0b0b]"
                                >
                                    {showAll
                                        ? 'Ver menos'
                                        : `Ver todas (${companies.length})`}
                                </button>
                            )}
                        </div>
                        <table className="w-full min-w-[980px] text-sm">
                            <thead>
                                <tr className="text-left text-xs text-slate-600">
                                    <th className="px-2 py-2">#</th>
                                    <th className="px-2 py-2">
                                        Empresa / Oficina
                                    </th>
                                    <th className="px-2 py-2">
                                        Categoría principal
                                    </th>
                                    <th className="px-2 py-2">
                                        Nivel de riesgo
                                    </th>
                                    <th className="px-2 py-2">
                                        Causa principal
                                    </th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2">
                                        Acción sugerida
                                    </th>
                                    <th className="px-2 py-2">Fecha límite</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {ranking.map((company, index) => (
                                    <tr
                                        key={company.id}
                                        onClick={() =>
                                            setSelectedId(company.id)
                                        }
                                        className={`cursor-pointer hover:bg-[#fbf5f4] ${selected?.id === company.id ? 'bg-red-50/60' : ''}`}
                                    >
                                        <td className="px-2 py-3 text-slate-500">
                                            {index + 1}
                                        </td>
                                        <td className="px-2 py-3">
                                            <p className="font-black">
                                                {company.name}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {company.office}
                                            </p>
                                        </td>
                                        <td className="px-2 py-3">
                                            {company.main_label}
                                        </td>
                                        <td className="px-2 py-3">
                                            <span
                                                className={`rounded-lg px-3 py-1 text-xs font-bold ${LEVEL[company.level].badge}`}
                                            >
                                                {LEVEL[company.level].label}
                                            </span>
                                        </td>
                                        <td className="max-w-56 px-2 py-3 text-xs text-slate-600">
                                            {company.cause}
                                        </td>
                                        <td className="px-2 py-3">
                                            {company.accountant ?? (
                                                <span className="text-slate-400">
                                                    Sin asignar
                                                </span>
                                            )}
                                        </td>
                                        <td className="max-w-56 px-2 py-3 text-xs text-slate-600">
                                            {company.action}
                                        </td>
                                        <td className="px-2 py-3">
                                            {formatDate(company.deadline)}
                                        </td>
                                    </tr>
                                ))}
                                {ranking.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-10 text-center text-slate-500"
                                        >
                                            No hay empresas para evaluar.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                        <p className="mt-3 flex items-start gap-2 text-xs text-slate-500">
                            <Info className="mt-0.5 size-4 shrink-0" />
                            El puntaje se calcula automáticamente con señales
                            reales: obligaciones vencidas, tareas bloqueadas o
                            atrasadas, diferencias contra el Mayor, cartera
                            vencida y sobrecarga de quienes llevan la empresa.
                            No es un registro manual.
                        </p>
                    </section>
                </div>
            </main>
        </>
    );
}
