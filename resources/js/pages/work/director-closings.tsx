import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    Check,
    CircleDashed,
    Clock3,
    Download,
    Lock,
    Search,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type StepState = 'done' | 'active' | 'pending' | 'blocked';
type CloseState = 'closed' | 'blocked' | 'progress' | 'pending';

type Closing = {
    id: number;
    name: string;
    tax_id: string;
    office: string;
    period: string | null;
    period_ends_on: string | null;
    accountant: string | null;
    assistant: string | null;
    reviewer: string | null;
    steps: { key: string; label: string; state: StepState }[];
    stage: string;
    progress: number;
    state: CloseState;
    blockers: { title: string; detail: string }[];
    history: { label: string; at: string | null; by: string | null }[];
};

type Props = {
    summary: {
        closed: number;
        pending: number;
        progress: number;
        in_review: number;
        blocked: number;
    };
    steps: string[];
    companies: Closing[];
    offices: string[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const control =
    'h-10 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';

const STATE: Record<CloseState, { label: string; style: string; dot: string }> = {
    closed: { label: 'Cerrado', style: 'bg-emerald-50 text-emerald-700', dot: '#16a34a' },
    progress: { label: 'En proceso', style: 'bg-blue-50 text-blue-700', dot: '#2563eb' },
    pending: { label: 'Pendiente', style: 'bg-amber-50 text-amber-700', dot: '#f0a93b' },
    blocked: { label: 'Bloqueado', style: 'bg-red-50 text-red-700', dot: '#dc2626' },
};
const STAGE_TONE: Record<string, string> = {
    reconciliation: '#16a34a',
    review: '#f0a93b',
    approval: '#2563eb',
    fiscal: '#7c3aed',
    close: '#16a34a',
};
const PAGE_SIZE = 8;

function normalize(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');
}

function formatDate(value: string | null, withTime = false): string {
    if (!value) {
        return '—';
    }

    return new Date(value.length === 10 ? `${value}T12:00:00` : value).toLocaleDateString(
        'es-GT',
        withTime
            ? { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
            : { day: '2-digit', month: 'short', year: 'numeric' },
    );
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
        <div className={`${card} flex min-w-0 items-center gap-3 p-4`}>
            <span className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}>
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[#8a0b0b]">{label}</p>
                <p className="text-3xl leading-tight font-black text-[#061633]">{value}</p>
                <p className={`truncate text-xs font-semibold ${bad ? 'text-red-600' : 'text-slate-500'}`}>
                    {hint}
                </p>
            </div>
        </div>
    );
}

function StepIcon({ state, size = 'size-5' }: { state: StepState; size?: string }) {
    if (state === 'done') {
        return (
            <span className={`grid ${size} place-items-center rounded-full bg-emerald-500 text-white`}>
                <Check className="size-3.5" strokeWidth={3} />
            </span>
        );
    }

    if (state === 'active') {
        return <span className={`${size} rounded-full border-2 border-amber-400 bg-amber-50`} />;
    }

    if (state === 'blocked') {
        return (
            <span className={`grid ${size} place-items-center rounded-full border-2 border-red-500 text-red-600`}>
                <span className="text-[10px] leading-none font-black">!</span>
            </span>
        );
    }

    return <span className={`${size} rounded-full border-2 border-slate-200`} />;
}

function Bar({ value, state }: { value: number; state: CloseState }) {
    const color = state === 'blocked' ? '#dc2626' : value >= 80 ? '#16a34a' : value >= 40 ? '#f0a93b' : '#dc2626';

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-16 overflow-hidden rounded-full bg-slate-100">
                <div className="h-full rounded-full" style={{ width: `${value}%`, background: color }} />
            </div>
            <span className="text-xs font-bold">{value}%</span>
        </div>
    );
}

function Detail({ item, onClose }: { item: Closing; onClose: () => void }) {
    const [tab, setTab] = useState<'Detalle' | 'Historial'>('Detalle');
    const state = STATE[item.state];

    return (
        <aside className={`${card} space-y-4 p-5 xl:sticky xl:top-4`}>
            <div className="flex items-start gap-3">
                <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                    <Building2 className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-black">{item.name}</h2>
                    <p className="text-xs text-slate-500">
                        NIT {item.tax_id} · {item.office}
                    </p>
                </div>
                <span className={`rounded-lg px-2.5 py-1 text-xs font-bold ${state.style}`}>{state.label}</span>
                <button type="button" aria-label="Cerrar detalle" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                    <X className="size-5" />
                </button>
            </div>

            <div className="flex gap-5 border-b border-slate-100 text-sm font-bold">
                {(['Detalle', 'Historial'] as const).map((name) => (
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

            {tab === 'Detalle' && (
                <div className="space-y-4">
                    <section className="rounded-xl border border-slate-100 p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-black">
                                Avance del cierre{item.period ? ` – ${item.period}` : ''}
                            </h3>
                            <b>{item.progress}%</b>
                        </div>
                        <div className="mb-4 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div className="h-full rounded-full bg-emerald-500" style={{ width: `${item.progress}%` }} />
                        </div>
                        <ol className="grid grid-cols-5 gap-1 text-center text-[11px]">
                            {item.steps.map((step) => (
                                <li key={step.key} className="flex flex-col items-center gap-1">
                                    <StepIcon state={step.state} size="size-7" />
                                    <span className="font-semibold leading-tight">{step.label}</span>
                                    <span className="text-slate-400">
                                        {step.state === 'done' ? 'Completado' : step.state === 'active' ? 'En proceso' : step.state === 'blocked' ? 'Bloqueado' : 'Pendiente'}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <section>
                            <h3 className="mb-2 text-sm font-black">Responsables</h3>
                            <ul className="space-y-1.5 text-sm">
                                <li>
                                    <span className="text-slate-500">Contador: </span>
                                    <b>{item.accountant ?? 'Sin asignar'}</b>
                                </li>
                                <li>
                                    <span className="text-slate-500">Auxiliar: </span>
                                    <b>{item.assistant ?? 'Sin asignar'}</b>
                                </li>
                                <li>
                                    <span className="text-slate-500">Revisor: </span>
                                    <b>{item.reviewer ?? 'Sin asignar'}</b>
                                </li>
                            </ul>
                        </section>
                        <section>
                            <h3 className="mb-2 text-sm font-black">Información general</h3>
                            <dl className="space-y-1.5 text-sm">
                                <div className="flex justify-between gap-2">
                                    <dt className="text-slate-500">Período</dt>
                                    <dd className="font-bold">{item.period ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <dt className="text-slate-500">Fin del período</dt>
                                    <dd className="font-bold">{formatDate(item.period_ends_on)}</dd>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <dt className="text-slate-500">Estado</dt>
                                    <dd className="font-bold">{state.label}</dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <section>
                        <h3 className="mb-2 text-sm font-black">Bloqueos pendientes ({item.blockers.length})</h3>
                        <ul className="space-y-2">
                            {item.blockers.map((blocker) => (
                                <li key={blocker.title} className="flex items-start gap-3 rounded-xl bg-red-50/60 p-3">
                                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-red-600" />
                                    <div>
                                        <p className="text-sm font-black">{blocker.title}</p>
                                        <p className="text-xs text-slate-600">{blocker.detail}</p>
                                    </div>
                                </li>
                            ))}
                            {item.blockers.length === 0 && (
                                <li className="text-sm text-slate-500">Sin bloqueos.</li>
                            )}
                        </ul>
                    </section>
                </div>
            )}

            {tab === 'Historial' && (
                <ol className="space-y-3 text-sm">
                    {item.history.map((entry) => (
                        <li key={entry.label} className="flex items-start gap-3">
                            <StepIcon state={entry.at ? 'done' : 'pending'} />
                            <div className="flex-1">
                                <p className="font-bold">{entry.label}</p>
                                {entry.by && <p className="text-xs text-slate-500">{entry.by}</p>}
                            </div>
                            <span className="text-xs text-slate-500">
                                {entry.at ? formatDate(entry.at, true) : 'Pendiente'}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </aside>
    );
}

function exportCsv(rows: Closing[]) {
    const header = ['Empresa', 'Oficina', 'Período', 'Avance %', 'Estado', 'Etapa actual'].join(',');
    const lines = rows.map((item) =>
        [item.name, item.office, item.period ?? 'Sin período', item.progress, STATE[item.state].label, item.steps.find((step) => step.state !== 'done')?.label ?? 'Cerrado']
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const blob = new Blob(['﻿' + [header, ...lines].join('\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'reporte-de-cierres.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function DirectorClosings({ summary, steps, companies, offices }: Props) {
    const [search, setSearch] = useState('');
    const [office, setOffice] = useState('');
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(companies[0]?.id ?? null);
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});

    const filtered = useMemo(() => {
        const term = normalize(search.trim());

        return companies.filter(
            (item) => (term === '' || normalize(item.name).includes(term)) && (office === '' || item.office === office),
        );
    }, [companies, search, office]);
    const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const current = Math.min(page, pages);
    const visible = filtered.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
    const selected = companies.find((item) => item.id === selectedId) ?? null;
    const stageKeys = ['reconciliation', 'review', 'approval', 'fiscal', 'close'];

    return (
        <>
            <Head title="Cierres" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <Clock3 className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">Cierres</h1>
                                <p className="text-sm text-slate-600">
                                    Supervisa el proceso de cierre contable y fiscal de todas las empresas cliente.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => exportCsv(filtered)}
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 py-2 text-sm font-bold text-[#8a0b0b]"
                        >
                            <Download className="size-4" /> Generar reporte de cierres
                        </button>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi icon={<Building2 className="size-7" />} tone="bg-emerald-50 text-emerald-600" label="Empresas cerradas este mes" value={String(summary.closed)} hint={`de ${companies.length} empresas`} />
                        <Kpi icon={<CalendarClock className="size-7" />} tone="bg-orange-50 text-orange-500" label="Pendientes de cierre" value={String(summary.pending)} hint="Aún sin cierre final" />
                        <Kpi icon={<CircleDashed className="size-7" />} tone="bg-cyan-50 text-cyan-600" label="Avance general de cierres" value={`${summary.progress}%`} hint="Promedio de todas las empresas" />
                        <Kpi icon={<ShieldCheck className="size-7" />} tone="bg-blue-50 text-blue-600" label="En revisión o aprobación" value={String(summary.in_review)} hint="Con el cierre ya iniciado" />
                        <Kpi icon={<Lock className="size-7" />} tone="bg-red-50 text-red-600" label="Cierres bloqueados" value={String(summary.blocked)} hint={summary.blocked > 0 ? 'Requieren desbloqueo' : 'Sin bloqueos'} bad={summary.blocked > 0} />
                    </section>

                    <section className={`grid gap-4 ${selected ? 'xl:grid-cols-[minmax(0,1fr)_440px]' : ''}`}>
                        <div className="min-w-0 space-y-4">
                            <div className={`${card} p-5`}>
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                                    <h2 className="text-lg font-black">Avance de cierres por empresa</h2>
                                    <div className="flex flex-wrap gap-2">
                                        <select
                                            className={control}
                                            value={office}
                                            onChange={(event) => {
                                                setOffice(event.target.value);
                                                setPage(1);
                                            }}
                                        >
                                            <option value="">Todas las oficinas</option>
                                            {offices.map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                </option>
                                            ))}
                                        </select>
                                        <label className="relative">
                                            <Search className="absolute top-3 left-3 size-4 text-slate-400" />
                                            <input
                                                className={`${control} w-48 pl-9`}
                                                placeholder="Buscar empresa..."
                                                value={search}
                                                onChange={(event) => {
                                                    setSearch(event.target.value);
                                                    setPage(1);
                                                }}
                                            />
                                        </label>
                                    </div>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[860px] text-sm">
                                        <thead>
                                            <tr className="text-left text-xs text-slate-600">
                                                <th className="px-2 py-2">Empresa</th>
                                                <th className="px-2 py-2">Oficina</th>
                                                <th className="px-2 py-2">Período</th>
                                                {steps.map((step) => (
                                                    <th key={step} className="px-1 py-2 text-center">
                                                        {step}
                                                    </th>
                                                ))}
                                                <th className="px-2 py-2">Avance</th>
                                                <th className="px-2 py-2">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {visible.map((item) => (
                                                <tr
                                                    key={item.id}
                                                    onClick={() => setSelectedId(item.id)}
                                                    className={`cursor-pointer hover:bg-[#fbf5f4] ${selectedId === item.id ? 'bg-blue-50/60' : ''}`}
                                                >
                                                    <td className="px-2 py-3 font-black">{item.name}</td>
                                                    <td className="px-2 py-3 text-slate-600">{item.office}</td>
                                                    <td className="px-2 py-3 text-slate-600">{item.period ?? 'Sin período'}</td>
                                                    {item.steps.map((step) => (
                                                        <td key={step.key} className="px-1 py-3">
                                                            <span className="flex justify-center">
                                                                <StepIcon state={step.state} size="size-6" />
                                                            </span>
                                                        </td>
                                                    ))}
                                                    <td className="px-2 py-3">
                                                        <Bar value={item.progress} state={item.state} />
                                                    </td>
                                                    <td className="px-2 py-3">
                                                        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ${STATE[item.state].style}`}>
                                                            <i className="size-2 rounded-full" style={{ background: STATE[item.state].dot }} />
                                                            {STATE[item.state].label}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                            {visible.length === 0 && (
                                                <tr>
                                                    <td colSpan={10} className="px-3 py-10 text-center text-slate-500">
                                                        No hay empresas con esos criterios.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                                    <span>{filtered.length} empresas</span>
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
                            </div>

                            <div className={`${card} p-5`}>
                                <h2 className="mb-3 text-lg font-black">Pipeline de cierres</h2>
                                <div className="grid gap-3 md:grid-cols-5">
                                    {stageKeys.map((key, index) => {
                                        const items = companies.filter((item) => item.stage === key);
                                        const open = expanded[key] ?? false;
                                        const shown = open ? items : items.slice(0, 2);

                                        return (
                                            <div key={key} className="rounded-xl border border-slate-100 bg-slate-50/60 p-2">
                                                <div className="mb-2 flex items-center justify-between rounded-lg px-2 py-1.5 text-sm font-black" style={{ background: `${STAGE_TONE[key]}1f` }}>
                                                    <span>{steps[index]}</span>
                                                    <span className="rounded-full bg-white px-2 text-xs">{items.length}</span>
                                                </div>
                                                <div className="space-y-2">
                                                    {shown.map((item) => (
                                                        <button
                                                            key={item.id}
                                                            type="button"
                                                            onClick={() => setSelectedId(item.id)}
                                                            className="w-full rounded-lg border border-slate-100 bg-white p-2 text-left"
                                                        >
                                                            <p className="truncate text-xs font-black">{item.name}</p>
                                                            <p className="truncate text-[11px] text-slate-500">
                                                                {item.period ?? 'Sin período'} · {item.office}
                                                            </p>
                                                            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                                                <div className="h-full rounded-full bg-emerald-500" style={{ width: `${item.progress}%` }} />
                                                            </div>
                                                        </button>
                                                    ))}
                                                    {items.length === 0 && <p className="px-1 py-3 text-center text-xs text-slate-400">Sin empresas</p>}
                                                    {items.length > 2 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpanded((value) => ({ ...value, [key]: !open }))}
                                                            className="w-full py-1 text-center text-xs font-bold text-blue-600"
                                                        >
                                                            {open ? 'Ver menos' : `Ver ${items.length - 2} más`}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        {selected && <Detail key={selected.id} item={selected} onClose={() => setSelectedId(null)} />}
                    </section>
                </div>
            </main>
        </>
    );
}
