import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    ChevronRight,
    Clock3,
    Download,
    FilterX,
    Mail,
    MapPin,
    Phone,
    Search,
    UserPlus,
    UserRound,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Compliance = 'operational' | 'late' | 'review' | 'onboarding';
type Risk = 'low' | 'medium' | 'high';

type PortfolioCompany = {
    id: number;
    name: string;
    tax_id: string;
    office: string;
    accountant: string | null;
    assistant: string | null;
    period_name: string | null;
    progress: number;
    pending_tasks: number;
    overdue_tasks: number;
    overdue_obligations: number;
    compliance: Compliance;
    risk: Risk;
    next_obligation: string | null;
    obligations: {
        name: string;
        due_on: string;
        done: boolean;
        overdue: boolean;
    }[];
    tasks: { title: string; status: string; due_at: string | null }[];
    client_since: string | null;
    contact: {
        name: string | null;
        phone: string | null;
        email: string | null;
        address: string | null;
    };
};

type Props = {
    summary: {
        total: number;
        active: number;
        at_risk: number;
        overdue_obligations: number;
        onboarding: number;
    };
    companies: PortfolioCompany[];
    offices: string[];
    accountants: string[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const control =
    'h-11 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm text-[#2b2f3a] outline-none focus:border-[#8a0b0b]';
const PAGE_SIZE = 10;

const COMPLIANCE: Record<Compliance, { label: string; color: string }> = {
    operational: { label: 'Operativa', color: '#16a34a' },
    late: { label: 'Con atrasos', color: '#dc2626' },
    review: { label: 'En revisión', color: '#2563eb' },
    onboarding: { label: 'En incorporación', color: '#94a3b8' },
};
const RISK: Record<Risk, { label: string; style: string }> = {
    low: { label: 'Bajo', style: 'bg-emerald-50 text-emerald-700' },
    medium: { label: 'Medio', style: 'bg-amber-50 text-amber-700' },
    high: { label: 'Alto', style: 'bg-red-50 text-red-700' },
};

function normalize(value: string): string {
    return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value.length === 10 ? `${value}T12:00:00` : value);

    return date.toLocaleDateString('es-GT', {
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
    hint,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: number;
    hint: string;
}) {
    return (
        <div className={`${card} flex items-center gap-4 p-4`}>
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
                <p className="truncate text-xs font-semibold text-slate-500">
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block min-w-0">
            <span className="mb-1.5 block text-sm font-bold text-[#2b2f3a]">
                {label}
            </span>
            {children}
        </label>
    );
}

function ProgressBar({ value }: { value: number }) {
    const color = value >= 80 ? '#16a34a' : value >= 50 ? '#f0a93b' : '#dc2626';

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-20 overflow-hidden rounded-full bg-slate-100">
                <div
                    className="h-full rounded-full"
                    style={{
                        width: `${Math.min(100, value)}%`,
                        background: color,
                    }}
                />
            </div>
            <span className="text-xs font-bold">{value}%</span>
        </div>
    );
}

const DETAIL_TABS = ['Resumen', 'Tareas'] as const;

function CompanyDetail({
    company,
    onClose,
}: {
    company: PortfolioCompany;
    onClose: () => void;
}) {
    const [tab, setTab] = useState<(typeof DETAIL_TABS)[number]>('Resumen');
    const done = company.obligations.filter((item) => item.done).length;
    const compliance = COMPLIANCE[company.compliance];

    return (
        <aside className={`${card} space-y-4 p-5 xl:sticky xl:top-4`}>
            <div className="flex items-start gap-3">
                <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                    <Building2 className="size-7" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-lg font-black">{company.name}</h2>
                        <span
                            className="rounded-full px-2 py-0.5 text-xs font-bold"
                            style={{
                                color: compliance.color,
                                background: `${compliance.color}1a`,
                            }}
                        >
                            {compliance.label}
                        </span>
                    </div>
                    <p className="text-xs text-slate-600">
                        NIT: {company.tax_id}
                        {company.client_since
                            ? ` · Cliente desde ${formatDate(company.client_since)}`
                            : ''}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                        {company.office}
                    </p>
                </div>
                <button
                    type="button"
                    aria-label="Cerrar detalle"
                    onClick={onClose}
                    className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"
                >
                    <X className="size-5" />
                </button>
            </div>

            <div className="flex gap-5 border-b border-slate-100 text-sm font-bold">
                {DETAIL_TABS.map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setTab(name)}
                        className={`-mb-px border-b-2 pb-2 ${tab === name ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-500'}`}
                    >
                        {name}
                        {name === 'Tareas' ? ` (${company.tasks.length})` : ''}
                    </button>
                ))}
            </div>

            {tab === 'Resumen' && (
                <div className="space-y-4">
                    <section>
                        <h3 className="mb-2 text-sm font-black">
                            Datos de contacto
                        </h3>
                        <ul className="space-y-1.5 text-sm text-slate-700">
                            <li className="flex items-center gap-2">
                                <UserRound className="size-4 text-[#8a0b0b]" />
                                {company.contact.name ??
                                    'Sin contacto registrado'}
                            </li>
                            {company.contact.phone && (
                                <li className="flex items-center gap-2">
                                    <Phone className="size-4 text-[#8a0b0b]" />
                                    {company.contact.phone}
                                </li>
                            )}
                            {company.contact.email && (
                                <li className="flex items-center gap-2">
                                    <Mail className="size-4 text-[#8a0b0b]" />
                                    {company.contact.email}
                                </li>
                            )}
                            {company.contact.address && (
                                <li className="flex items-center gap-2">
                                    <MapPin className="size-4 text-[#8a0b0b]" />
                                    {company.contact.address}
                                </li>
                            )}
                        </ul>
                        <p className="mt-2 text-xs text-slate-500">
                            Contador: {company.accountant ?? 'Sin asignar'} ·
                            Auxiliar: {company.assistant ?? 'Sin asignar'}
                        </p>
                    </section>

                    <section>
                        <h3 className="mb-2 text-sm font-black">
                            Obligaciones fiscales
                        </h3>
                        <ul className="space-y-2">
                            {company.obligations.map((item) => (
                                <li
                                    key={`${item.name}-${item.due_on}`}
                                    className="flex items-center gap-3 text-sm"
                                >
                                    {item.done ? (
                                        <CheckCircle2 className="size-5 shrink-0 text-emerald-500" />
                                    ) : item.overdue ? (
                                        <AlertTriangle className="size-5 shrink-0 text-red-500" />
                                    ) : (
                                        <Clock3 className="size-5 shrink-0 text-amber-500" />
                                    )}
                                    <span className="flex-1 truncate font-bold">
                                        {item.name}
                                    </span>
                                    <span
                                        className={`text-xs font-semibold ${item.overdue ? 'text-red-600' : 'text-slate-500'}`}
                                    >
                                        {item.done
                                            ? 'Cumplida'
                                            : item.overdue
                                              ? `Vencida ${formatDate(item.due_on)}`
                                              : `Vence ${formatDate(item.due_on)}`}
                                    </span>
                                </li>
                            ))}
                            {company.obligations.length === 0 && (
                                <li className="text-sm text-slate-500">
                                    Sin obligaciones generadas.
                                </li>
                            )}
                        </ul>
                    </section>

                    <section className="rounded-xl bg-slate-50 p-3">
                        <h3 className="text-sm font-black">
                            Cumplimiento del período
                        </h3>
                        <div className="mt-1 flex items-center gap-3">
                            <span className="text-3xl font-black text-emerald-600">
                                {company.progress}%
                            </span>
                            <div className="flex-1">
                                <ProgressBar value={company.progress} />
                                <p className="mt-1 text-xs text-slate-500">
                                    {done} de {company.obligations.length}{' '}
                                    obligaciones completadas
                                </p>
                            </div>
                        </div>
                    </section>

                    <section className="flex items-center justify-between rounded-xl bg-slate-50 p-3 text-sm">
                        <span className="font-black">Riesgo de la empresa</span>
                        <span
                            className={`rounded-full px-3 py-1 text-xs font-bold ${RISK[company.risk].style}`}
                        >
                            {RISK[company.risk].label}
                        </span>
                    </section>
                </div>
            )}

            {tab === 'Tareas' && (
                <ul className="space-y-2 text-sm">
                    {company.tasks.map((task) => (
                        <li
                            key={`${task.title}-${task.due_at}`}
                            className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"
                        >
                            <span className="truncate font-bold">
                                {task.title}
                            </span>
                            <span className="shrink-0 text-xs text-slate-500">
                                {formatDate(task.due_at)}
                            </span>
                        </li>
                    ))}
                    {company.tasks.length === 0 && (
                        <li className="text-slate-500">Sin tareas activas.</li>
                    )}
                </ul>
            )}
        </aside>
    );
}

function exportCsv(rows: PortfolioCompany[]) {
    const header = [
        'Empresa',
        'NIT',
        'Oficina',
        'Responsable contable',
        'Cumplimiento',
        'Avance %',
        'Riesgo',
        'Próxima obligación',
    ];
    const lines = rows.map((company) =>
        [
            company.name,
            company.tax_id,
            company.office,
            company.accountant ?? 'Sin asignar',
            COMPLIANCE[company.compliance].label,
            company.progress,
            RISK[company.risk].label,
            company.next_obligation ?? '',
        ]
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const blob = new Blob(['﻿' + [header.join(','), ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'empresas-cliente.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function DirectorCompanies({
    summary,
    companies,
    offices,
    accountants,
}: Props) {
    const [search, setSearch] = useState('');
    const [office, setOffice] = useState('');
    const [accountant, setAccountant] = useState('');
    const [compliance, setCompliance] = useState('');
    const [risk, setRisk] = useState('');
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(
        companies[0]?.id ?? null,
    );

    const filtered = useMemo(() => {
        const term = normalize(search.trim());

        return companies.filter(
            (company) =>
                (term === '' ||
                    normalize(`${company.name} ${company.tax_id}`).includes(
                        term,
                    )) &&
                (office === '' || company.office === office) &&
                (accountant === '' ||
                    (accountant === '__none'
                        ? company.accountant === null
                        : company.accountant === accountant)) &&
                (compliance === '' || company.compliance === compliance) &&
                (risk === '' || company.risk === risk),
        );
    }, [companies, search, office, accountant, compliance, risk]);
    const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const current = Math.min(page, pages);
    const visible = filtered.slice(
        (current - 1) * PAGE_SIZE,
        current * PAGE_SIZE,
    );
    const selected =
        companies.find((company) => company.id === selectedId) ?? null;
    const pct = (value: number) =>
        summary.total ? Math.round((value / summary.total) * 100) : 0;
    const clear = () => {
        setSearch('');
        setOffice('');
        setAccountant('');
        setCompliance('');
        setRisk('');
        setPage(1);
    };
    const onFilter = (apply: () => void) => {
        apply();
        setPage(1);
    };

    return (
        <>
            <Head title="Empresas cliente" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <Building2 className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Empresas cliente
                            </h1>
                            <p className="text-sm text-slate-600">
                                Consulta el estado, cumplimiento y próximas
                                obligaciones de tu cartera.
                            </p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label="Total de empresas"
                            value={summary.total}
                            hint="En toda la firma"
                        />
                        <Kpi
                            icon={<CheckCircle2 className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Empresas activas"
                            value={summary.active}
                            hint={`${pct(summary.active)}% del total`}
                        />
                        <Kpi
                            icon={<AlertTriangle className="size-7" />}
                            tone="bg-amber-50 text-amber-600"
                            label="En riesgo"
                            value={summary.at_risk}
                            hint={`${pct(summary.at_risk)}% del total`}
                        />
                        <Kpi
                            icon={<Clock3 className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Con obligaciones vencidas"
                            value={summary.overdue_obligations}
                            hint={`${pct(summary.overdue_obligations)}% del total`}
                        />
                        <Kpi
                            icon={<UserPlus className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="En incorporación"
                            value={summary.onboarding}
                            hint={`${pct(summary.onboarding)}% del total`}
                        />
                    </section>

                    <section
                        className={`grid gap-4 ${selected ? 'xl:grid-cols-[minmax(0,1fr)_400px]' : ''}`}
                    >
                        <div className="min-w-0 space-y-4">
                            <div className={`${card} p-5`}>
                                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                                    <Field label="Buscar empresa">
                                        <span className="relative block">
                                            <Search className="absolute top-3.5 left-3 size-4 text-slate-400" />
                                            <input
                                                className={`${control} pl-9`}
                                                placeholder="Nombre, NIT o palabra clave..."
                                                value={search}
                                                onChange={(event) =>
                                                    onFilter(() =>
                                                        setSearch(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                            />
                                        </span>
                                    </Field>
                                    <Field label="Oficina">
                                        <select
                                            className={control}
                                            value={office}
                                            onChange={(event) =>
                                                onFilter(() =>
                                                    setOffice(
                                                        event.target.value,
                                                    ),
                                                )
                                            }
                                        >
                                            <option value="">
                                                Todas las oficinas
                                            </option>
                                            {offices.map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field label="Responsable contable">
                                        <select
                                            className={control}
                                            value={accountant}
                                            onChange={(event) =>
                                                onFilter(() =>
                                                    setAccountant(
                                                        event.target.value,
                                                    ),
                                                )
                                            }
                                        >
                                            <option value="">
                                                Todos los responsables
                                            </option>
                                            <option value="__none">
                                                Sin asignar
                                            </option>
                                            {accountants.map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field label="Estado de cumplimiento">
                                        <select
                                            className={control}
                                            value={compliance}
                                            onChange={(event) =>
                                                onFilter(() =>
                                                    setCompliance(
                                                        event.target.value,
                                                    ),
                                                )
                                            }
                                        >
                                            <option value="">Todos</option>
                                            {(
                                                Object.keys(
                                                    COMPLIANCE,
                                                ) as Compliance[]
                                            ).map((key) => (
                                                <option key={key} value={key}>
                                                    {COMPLIANCE[key].label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field label="Nivel de riesgo">
                                        <select
                                            className={control}
                                            value={risk}
                                            onChange={(event) =>
                                                onFilter(() =>
                                                    setRisk(event.target.value),
                                                )
                                            }
                                        >
                                            <option value="">Todos</option>
                                            {(Object.keys(RISK) as Risk[]).map(
                                                (key) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {RISK[key].label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                </div>
                                <div className="mt-4 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={clear}
                                        className="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold text-[#8a0b0b] hover:bg-red-50"
                                    >
                                        <FilterX className="size-4" /> Limpiar
                                        filtros
                                    </button>
                                </div>
                            </div>

                            <div className={`${card} p-5`}>
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <h2 className="text-lg font-black">
                                        Empresas cliente ({filtered.length})
                                    </h2>
                                    <button
                                        type="button"
                                        onClick={() => exportCsv(filtered)}
                                        className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] px-4 py-2 text-sm font-bold"
                                    >
                                        <Download className="size-4" /> Exportar
                                    </button>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[900px] text-sm">
                                        <thead>
                                            <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                                <th className="px-3 py-2">
                                                    Empresa
                                                </th>
                                                <th className="px-3 py-2">
                                                    NIT
                                                </th>
                                                <th className="px-3 py-2">
                                                    Oficina
                                                </th>
                                                <th className="px-3 py-2">
                                                    Responsable contable
                                                </th>
                                                <th className="px-3 py-2">
                                                    Cumplimiento
                                                </th>
                                                <th className="px-3 py-2">
                                                    Avance período
                                                </th>
                                                <th className="px-3 py-2">
                                                    Riesgo
                                                </th>
                                                <th className="px-3 py-2">
                                                    Próxima obligación
                                                </th>
                                                <th className="w-8" />
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {visible.map((company) => (
                                                <tr
                                                    key={company.id}
                                                    onClick={() =>
                                                        setSelectedId(
                                                            company.id,
                                                        )
                                                    }
                                                    className={`cursor-pointer transition hover:bg-[#fbf5f4] ${selectedId === company.id ? 'bg-red-50/70' : ''}`}
                                                >
                                                    <td className="px-3 py-3 font-black">
                                                        {company.name}
                                                    </td>
                                                    <td className="px-3 py-3 text-slate-600">
                                                        {company.tax_id}
                                                    </td>
                                                    <td className="px-3 py-3 text-slate-600">
                                                        {company.office}
                                                    </td>
                                                    <td className="px-3 py-3">
                                                        {company.accountant ?? (
                                                            <span className="text-slate-400">
                                                                Sin asignar
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-3">
                                                        <span className="flex items-center gap-2 font-semibold">
                                                            <span
                                                                className="size-2.5 rounded-full"
                                                                style={{
                                                                    background:
                                                                        COMPLIANCE[
                                                                            company
                                                                                .compliance
                                                                        ].color,
                                                                }}
                                                            />
                                                            {
                                                                COMPLIANCE[
                                                                    company
                                                                        .compliance
                                                                ].label
                                                            }
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-3">
                                                        <ProgressBar
                                                            value={
                                                                company.progress
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-3 py-3">
                                                        <span
                                                            className={`rounded-full px-3 py-1 text-xs font-bold ${RISK[company.risk].style}`}
                                                        >
                                                            {
                                                                RISK[
                                                                    company.risk
                                                                ].label
                                                            }
                                                        </span>
                                                    </td>
                                                    <td
                                                        className={`px-3 py-3 font-semibold ${company.overdue_obligations > 0 ? 'text-red-600' : ''}`}
                                                    >
                                                        {formatDate(
                                                            company.next_obligation,
                                                        )}
                                                    </td>
                                                    <td className="px-2">
                                                        <ChevronRight className="size-4 text-slate-400" />
                                                    </td>
                                                </tr>
                                            ))}
                                            {visible.length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={9}
                                                        className="px-3 py-10 text-center text-slate-500"
                                                    >
                                                        No hay empresas con esos
                                                        criterios.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                                    <span>
                                        Mostrando{' '}
                                        {filtered.length === 0
                                            ? 0
                                            : (current - 1) * PAGE_SIZE +
                                              1}{' '}
                                        a{' '}
                                        {Math.min(
                                            current * PAGE_SIZE,
                                            filtered.length,
                                        )}{' '}
                                        de {filtered.length} empresas
                                    </span>
                                    <div className="flex items-center gap-1">
                                        {Array.from(
                                            { length: pages },
                                            (_, index) => (
                                                <button
                                                    key={index}
                                                    type="button"
                                                    onClick={() =>
                                                        setPage(index + 1)
                                                    }
                                                    className={`size-8 rounded-lg text-sm font-bold ${current === index + 1 ? 'bg-[#8a0b0b] text-white' : 'border border-[#e4dbd6] text-slate-600'}`}
                                                >
                                                    {index + 1}
                                                </button>
                                            ),
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {selected && (
                            <CompanyDetail
                                key={selected.id}
                                company={selected}
                                onClose={() => setSelectedId(null)}
                            />
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}
