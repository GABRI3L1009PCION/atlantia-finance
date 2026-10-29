import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CircleDollarSign,
    FileText,
    HandCoins,
    Hourglass,
    Plus,
    ShieldX,
    TrendingUp,
    Users,
    WalletCards,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { shortDate } from '@/components/accounting-ui';
import { ConfirmDialog } from '@/components/office-admin/kit';

type Invoice = {
    id: number;
    number: string;
    company: string;
    concept: string;
    amount_cents: number;
    status: 'paid' | 'pending' | 'overdue' | 'cancelled';
    due_on: string;
    issued_on: string;
};

type Props = {
    month: string;
    month_label: string;
    month_options: { value: string; label: string }[];
    kpis: {
        mrr_cents: number;
        mrr_change: number | null;
        billed_cents: number;
        collected_cents: number;
        collected_rate: number | null;
        pending_cents: number;
        pending_rate: number | null;
        ticket_cents: number;
        clients: number;
    };
    months: { label: string; collected_cents: number; pending_cents: number }[];
    by_office: { name: string; value_cents: number }[];
    by_service: { name: string; value_cents: number }[];
    collection: {
        collected_cents: number;
        pending_cents: number;
        overdue_cents: number;
    };
    top_clients: { name: string; value_cents: number }[];
    late: {
        id: number;
        company: string;
        number: string;
        days: number;
        amount_cents: number;
    }[];
    late_total: number;
    renewals: {
        company: string;
        services: string;
        renews_on: string;
        amount_cents: number;
    }[];
    invoices: Invoice[];
    notices?: {
        id: number;
        company: string;
        invoice_number: string;
        amount_cents: number;
        paid_on: string;
        method: string;
        reference: string;
        reporter: string;
    }[];
    companies: { id: number; name: string }[];
    canManage: boolean;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const field =
    'h-10 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const button =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#8a0b0b] px-4 text-sm font-bold text-white disabled:opacity-60';
const PIE = ['#8a0b0b', '#2563eb', '#f0a93b', '#ee4b4b', '#9ca3af'];
const money = (cents: number) =>
    `Q${Math.round(cents / 100).toLocaleString('es-GT')}`;
const compact = (cents: number) =>
    cents >= 100000 ? `Q${Math.round(cents / 100000)}K` : money(cents);
const formatDate = (value: string) =>
    new Date(`${value}T12:00:00`).toLocaleDateString('es-GT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
const STATUS = {
    paid: { label: 'Pagada', dot: '#16a34a' },
    pending: { label: 'Pendiente', dot: '#f0a93b' },
    overdue: { label: 'Vencida', dot: '#dc2626' },
    cancelled: { label: 'Anulada', dot: '#94a3b8' },
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
    bad?: boolean | null;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 !p-4`}>
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="line-clamp-2 text-xs leading-snug font-bold text-[#6b1d1d]">
                    {label}
                </p>
                <p className="text-2xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`line-clamp-2 text-xs leading-snug font-semibold ${bad === null || bad === undefined ? 'text-slate-500' : bad ? 'text-red-600' : 'text-emerald-600'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Donut({
    rows,
    center,
    label,
}: {
    rows: { name: string; value_cents: number; color?: string }[];
    center: string;
    label: string;
}) {
    const total = rows.reduce((sum, row) => sum + row.value_cents, 0);
    const radius = 50;
    const circumference = 2 * Math.PI * radius;
    const lengths = rows.map((row) =>
        total > 0 ? (row.value_cents / total) * circumference : 0,
    );

    return (
        <div className="flex items-center gap-4">
            <svg viewBox="0 0 130 130" className="size-40 shrink-0">
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
                        stroke={row.color ?? PIE[i % PIE.length]}
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
                    y="63"
                    textAnchor="middle"
                    className="fill-[#061633] text-[15px] font-black"
                >
                    {center}
                </text>
                <text
                    x="65"
                    y="79"
                    textAnchor="middle"
                    className="fill-slate-500 text-[9px]"
                >
                    {label}
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                {rows.map((row, i) => (
                    <li key={row.name} className="flex items-center gap-2">
                        <i
                            className="size-2.5 shrink-0 rounded-full"
                            style={{
                                background: row.color ?? PIE[i % PIE.length],
                            }}
                        />
                        <span className="flex-1 truncate">{row.name}</span>
                        <b>{money(row.value_cents)}</b>
                        <span className="w-10 text-right text-xs text-slate-500">
                            {total
                                ? Math.round((row.value_cents / total) * 100)
                                : 0}
                            %
                        </span>
                    </li>
                ))}
                {rows.length === 0 && (
                    <li className="text-slate-500">Sin datos.</li>
                )}
            </ul>
        </div>
    );
}

function Bars({ rows }: { rows: { name: string; value_cents: number }[] }) {
    const max = Math.max(1, ...rows.map((row) => row.value_cents));
    const total = rows.reduce((sum, row) => sum + row.value_cents, 0);

    return (
        <ul className="space-y-3">
            {rows.map((row, i) => (
                <li
                    key={row.name}
                    className="grid grid-cols-[minmax(0,9rem)_1fr_auto_auto] items-center gap-3 text-sm"
                >
                    <span className="truncate">{row.name}</span>
                    <div className="h-4 overflow-hidden rounded bg-slate-100">
                        <div
                            className="h-full rounded"
                            style={{
                                width: `${(row.value_cents / max) * 100}%`,
                                background:
                                    i === 0
                                        ? '#8a0b0b'
                                        : i < 3
                                          ? '#c0454a'
                                          : '#e8a5a5',
                            }}
                        />
                    </div>
                    <b>{money(row.value_cents)}</b>
                    {total > 0 && (
                        <span className="w-9 text-right text-xs text-slate-500">
                            {Math.round((row.value_cents / total) * 100)}%
                        </span>
                    )}
                </li>
            ))}
            {rows.length === 0 && (
                <li className="text-sm text-slate-500">Sin datos.</li>
            )}
        </ul>
    );
}

function Evolution({ points }: { points: Props['months'] }) {
    const width = 560;
    const height = 220;
    const left = 46;
    const bottom = 26;
    const totals = points.map(
        (point) => point.collected_cents + point.pending_cents,
    );
    const max = Math.max(1, ...totals);
    const slot = (width - left) / Math.max(points.length, 1);
    const y = (value: number) =>
        12 + (height - bottom - 26) * (1 - value / max);

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 0.5, 1].map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width}
                        y1={y(max * tick)}
                        y2={y(max * tick)}
                        stroke="#eee7e3"
                    />
                    <text
                        x={left - 6}
                        y={y(max * tick)}
                        textAnchor="end"
                        dominantBaseline="central"
                        className="fill-slate-400 text-[10px]"
                    >
                        {compact(max * tick)}
                    </text>
                </g>
            ))}
            {points.map((point, i) => {
                const x = left + slot * i + slot / 2;
                const total = point.collected_cents + point.pending_cents;

                return (
                    <g key={point.label + i}>
                        <rect
                            x={x - slot * 0.3}
                            y={y(point.collected_cents)}
                            width={slot * 0.6}
                            height={Math.max(
                                0,
                                height - bottom - y(point.collected_cents),
                            )}
                            fill="#8a0b0b"
                        />
                        <rect
                            x={x - slot * 0.3}
                            y={y(total)}
                            width={slot * 0.6}
                            height={Math.max(
                                0,
                                y(point.collected_cents) - y(total),
                            )}
                            fill="#f1c5c5"
                        />
                        {total > 0 && (
                            <text
                                x={x}
                                y={y(total) - 5}
                                textAnchor="middle"
                                className="fill-[#6b1d1d] text-[10px] font-bold"
                            >
                                {compact(total)}
                            </text>
                        )}
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
        </svg>
    );
}

function ManagePanel({
    month,
    companies,
}: {
    month: string;
    companies: Props['companies'];
}) {
    const generate = useForm({ month });
    const invoice = useForm({
        company_id: '',
        concept: '',
        amount: '',
        issued_on: '',
        due_on: '',
    });

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <form
                onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    generate.post('/office/revenue/generate', {
                        preserveScroll: true,
                    });
                }}
                className={`${card} space-y-3`}
            >
                <h3 className="font-black">Generar cobros del mes</h3>
                <p className="text-sm text-slate-600">
                    Crea un cobro por la cuota mensual de cada contrato vigente
                    en <b>{month}</b>. Si ya existe, no lo duplica.
                </p>
                <button className={button} disabled={generate.processing}>
                    <HandCoins className="size-4" /> Generar cobros
                </button>
            </form>
            <form
                onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    invoice.post('/office/revenue/invoices', {
                        preserveScroll: true,
                        onSuccess: () => invoice.reset(),
                    });
                }}
                className={`${card} space-y-3`}
            >
                <h3 className="font-black">Registrar un cobro</h3>
                <select
                    className={field}
                    value={invoice.data.company_id}
                    onChange={(e) =>
                        invoice.setData('company_id', e.target.value)
                    }
                    required
                >
                    <option value="">Empresa cliente</option>
                    {companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.name}
                        </option>
                    ))}
                </select>
                <input
                    className={field}
                    placeholder="Concepto"
                    value={invoice.data.concept}
                    onChange={(e) => invoice.setData('concept', e.target.value)}
                    required
                />
                <input
                    className={field}
                    type="number"
                    min="0.01"
                    step="0.01"
                    placeholder="Monto (Q)"
                    value={invoice.data.amount}
                    onChange={(e) => invoice.setData('amount', e.target.value)}
                    required
                />
                <div className="grid grid-cols-2 gap-3">
                    <label className="text-xs text-slate-500">
                        Emisión
                        <input
                            className={field}
                            type="date"
                            value={invoice.data.issued_on}
                            onChange={(e) =>
                                invoice.setData('issued_on', e.target.value)
                            }
                            required
                        />
                    </label>
                    <label className="text-xs text-slate-500">
                        Vence
                        <input
                            className={field}
                            type="date"
                            value={invoice.data.due_on}
                            onChange={(e) =>
                                invoice.setData('due_on', e.target.value)
                            }
                            required
                        />
                    </label>
                </div>
                <button className={button} disabled={invoice.processing}>
                    <Plus className="size-4" /> Guardar cobro
                </button>
            </form>
        </div>
    );
}

export default function Revenue(props: Props) {
    const {
        month,
        month_label,
        month_options,
        kpis,
        months,
        by_office,
        by_service,
        collection,
        top_clients,
        late,
        late_total,
        renewals,
        invoices,
        canManage,
        notices,
    } = props;
    const [declining, setDeclining] = useState<
        NonNullable<Props['notices']>[number] | null
    >(null);

    return (
        <>
            <Head title="Ingresos de la firma" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <WalletCards className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Ingresos de la firma
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Ingresos, cobranza y rendimiento comercial
                                    de tu firma.
                                </p>
                            </div>
                        </div>
                        <select
                            className="h-12 rounded-xl border border-[#e4dbd6] bg-white px-4 text-sm font-bold"
                            value={month}
                            onChange={(e) =>
                                router.get(
                                    '/office/revenue',
                                    { month: e.target.value },
                                    {
                                        preserveScroll: true,
                                        preserveState: false,
                                    },
                                )
                            }
                        >
                            {month_options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <Kpi
                            icon={<TrendingUp className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Ingreso mensual (MRR)"
                            value={money(kpis.mrr_cents)}
                            hint={
                                kpis.mrr_change === null
                                    ? 'Sin mes anterior'
                                    : `${kpis.mrr_change >= 0 ? '↑' : '↓'} ${Math.abs(kpis.mrr_change)}% vs. mes anterior`
                            }
                            bad={
                                kpis.mrr_change === null
                                    ? null
                                    : kpis.mrr_change < 0
                            }
                        />
                        <Kpi
                            icon={<CircleDollarSign className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Ingresos cobrados (mes)"
                            value={money(kpis.collected_cents)}
                            hint={
                                kpis.collected_rate === null
                                    ? 'Sin cobros del mes'
                                    : `${kpis.collected_rate}% de lo facturado`
                            }
                        />
                        <Kpi
                            icon={<Hourglass className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Ingresos pendientes"
                            value={money(kpis.pending_cents)}
                            hint={
                                kpis.pending_rate === null
                                    ? 'Sin cobros del mes'
                                    : `${kpis.pending_rate}% de lo facturado`
                            }
                            bad={kpis.pending_cents > 0 ? true : null}
                        />
                        <Kpi
                            icon={<Users className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Ticket promedio por cliente"
                            value={money(kpis.ticket_cents)}
                            hint={`${kpis.clients} cliente(s) con contrato`}
                        />
                        <Kpi
                            icon={<FileText className="size-7" />}
                            tone="bg-green-50 text-green-600"
                            label="Facturado en el mes"
                            value={money(kpis.billed_cents)}
                            hint="Cobros emitidos"
                        />
                        <Kpi
                            icon={<CalendarClock className="size-7" />}
                            tone="bg-sky-50 text-sky-600"
                            label="Cuentas en atraso"
                            value={String(late_total)}
                            hint={
                                late_total > 0
                                    ? 'Requieren cobro'
                                    : 'Sin atrasos'
                            }
                            bad={late_total > 0}
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.5fr_1fr_1.1fr]">
                        <div className={card}>
                            <h2 className="font-black">
                                Evolución de ingresos mensuales
                            </h2>
                            <div className="mb-1 flex gap-4 text-xs text-slate-500">
                                <span className="flex items-center gap-1.5">
                                    <i className="size-2.5 rounded-full bg-[#8a0b0b]" />{' '}
                                    Cobrados
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <i className="size-2.5 rounded-full bg-[#f1c5c5]" />{' '}
                                    Pendientes
                                </span>
                            </div>
                            <Evolution points={months} />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Ingresos por oficina
                            </h2>
                            <Donut
                                rows={by_office}
                                center={money(
                                    by_office.reduce(
                                        (s, r) => s + r.value_cents,
                                        0,
                                    ),
                                )}
                                label="total mensual"
                            />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Ingresos por línea de servicio
                            </h2>
                            <Bars rows={by_service} />
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.1fr_1fr_1fr_1fr]">
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Estado de cobranza
                            </h2>
                            <Donut
                                center={money(
                                    collection.collected_cents +
                                        collection.pending_cents +
                                        collection.overdue_cents,
                                )}
                                label={month_label}
                                rows={[
                                    {
                                        name: 'Cobrado',
                                        value_cents: collection.collected_cents,
                                        color: '#16a34a',
                                    },
                                    {
                                        name: 'Pendiente',
                                        value_cents: collection.pending_cents,
                                        color: '#f0a93b',
                                    },
                                    {
                                        name: 'Vencido',
                                        value_cents: collection.overdue_cents,
                                        color: '#dc2626',
                                    },
                                ]}
                            />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Top 5 clientes por ingresos
                            </h2>
                            <Bars rows={top_clients} />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 flex items-center justify-between font-black">
                                Cuentas en atraso{' '}
                                <span className="rounded-full bg-red-50 px-2 text-xs text-red-600">
                                    {late_total}
                                </span>
                            </h2>
                            <ul className="space-y-2.5">
                                {late.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <AlertTriangle className="size-5 shrink-0 text-red-500" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-bold">
                                                {item.company}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {item.number}
                                            </p>
                                        </div>
                                        <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs font-bold text-red-700">
                                            {item.days} días
                                        </span>
                                        <b>{money(item.amount_cents)}</b>
                                        {canManage && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.patch(
                                                        `/office/revenue/invoices/${item.id}/paid`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                className="rounded-lg border border-[#e4dbd6] px-2 py-1 text-xs font-bold text-[#8a0b0b]"
                                            >
                                                Cobrada
                                            </button>
                                        )}
                                    </li>
                                ))}
                                {late.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        No hay cuentas en atraso.
                                    </li>
                                )}
                            </ul>
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Próximas renovaciones
                            </h2>
                            <ul className="space-y-2.5">
                                {renewals.map((item) => (
                                    <li
                                        key={`${item.company}-${item.renews_on}`}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <CalendarClock className="size-5 shrink-0 text-[#8a0b0b]" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-bold">
                                                {item.company}
                                            </p>
                                            <p className="truncate text-xs text-slate-500">
                                                {item.services}
                                            </p>
                                        </div>
                                        <span className="text-xs text-slate-500">
                                            {formatDate(item.renews_on)}
                                        </span>
                                        <b>{money(item.amount_cents)}</b>
                                    </li>
                                ))}
                                {renewals.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        Sin renovaciones próximas.
                                    </li>
                                )}
                            </ul>
                        </div>
                    </section>

                    {(notices ?? []).length > 0 && (
                        <section className={`${card} overflow-x-auto`}>
                            <h2 className="mb-1 font-black">
                                Pagos informados por clientes (
                                {(notices ?? []).length})
                            </h2>
                            <p className="mb-3 text-xs text-slate-500">
                                Confirma el pago cuando lo veas reflejado en tu
                                banco; el cobro se marca como pagado al
                                completar su monto.
                            </p>
                            <table className="w-full min-w-[760px] text-sm">
                                <thead>
                                    <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                        <th className="px-3 py-2">Empresa</th>
                                        <th className="px-3 py-2"># Cobro</th>
                                        <th className="px-3 py-2">Monto</th>
                                        <th className="px-3 py-2">Fecha</th>
                                        <th className="px-3 py-2">Forma</th>
                                        <th className="px-3 py-2">
                                            Referencia
                                        </th>
                                        <th className="px-3 py-2">Informó</th>
                                        {canManage && <th />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {(notices ?? []).map((notice) => (
                                        <tr key={notice.id}>
                                            <td className="px-3 py-2 font-bold">
                                                {notice.company}
                                            </td>
                                            <td className="px-3 py-2">
                                                {notice.invoice_number}
                                            </td>
                                            <td className="px-3 py-2 font-black tabular-nums">
                                                {money(notice.amount_cents)}
                                            </td>
                                            <td className="px-3 py-2">
                                                {shortDate(notice.paid_on)}
                                            </td>
                                            <td className="px-3 py-2">
                                                {notice.method}
                                            </td>
                                            <td className="px-3 py-2">
                                                {notice.reference}
                                            </td>
                                            <td className="px-3 py-2">
                                                {notice.reporter}
                                            </td>
                                            {canManage && (
                                                <td className="space-x-2 px-3 py-2 text-right whitespace-nowrap">
                                                    <button
                                                        type="button"
                                                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white"
                                                        onClick={() =>
                                                            router.post(
                                                                `/office/revenue/notices/${notice.id}/confirm`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Confirmar
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-bold text-red-700"
                                                        onClick={() =>
                                                            setDeclining(notice)
                                                        }
                                                    >
                                                        No confirmar
                                                    </button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    )}

                    <section className={`${card} overflow-x-auto`}>
                        <h2 className="mb-3 font-black">Cobros recientes</h2>
                        <table className="w-full min-w-[760px] text-sm">
                            <thead>
                                <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                    <th className="px-3 py-2"># Cobro</th>
                                    <th className="px-3 py-2">
                                        Empresa cliente
                                    </th>
                                    <th className="px-3 py-2">Concepto</th>
                                    <th className="px-3 py-2">Monto</th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2">Vencimiento</th>
                                    <th className="px-3 py-2">Emisión</th>
                                    {canManage && <th />}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {invoices.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-3 py-3 font-bold">
                                            {item.number}
                                        </td>
                                        <td className="px-3 py-3">
                                            {item.company}
                                        </td>
                                        <td className="px-3 py-3 text-slate-600">
                                            {item.concept}
                                        </td>
                                        <td className="px-3 py-3 font-bold">
                                            {money(item.amount_cents)}
                                        </td>
                                        <td className="px-3 py-3">
                                            <span className="flex items-center gap-1.5 font-semibold">
                                                <i
                                                    className="size-2.5 rounded-full"
                                                    style={{
                                                        background:
                                                            STATUS[item.status]
                                                                .dot,
                                                    }}
                                                />
                                                {STATUS[item.status].label}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3">
                                            {formatDate(item.due_on)}
                                        </td>
                                        <td className="px-3 py-3">
                                            {formatDate(item.issued_on)}
                                        </td>
                                        {canManage && (
                                            <td className="px-3 py-3">
                                                {item.status !== 'paid' &&
                                                    item.status !==
                                                        'cancelled' && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.patch(
                                                                    `/office/revenue/invoices/${item.id}/paid`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                            className="rounded-lg border border-[#e4dbd6] px-2 py-1 text-xs font-bold text-[#8a0b0b]"
                                                        >
                                                            Marcar cobrada
                                                        </button>
                                                    )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                                {invoices.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-10 text-center text-slate-500"
                                        >
                                            Aún no hay cobros registrados.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                        <p className="mt-3 text-xs text-slate-500">
                            Control interno de cobranza de la firma. No
                            sustituye a la factura electrónica (FEL) que emite
                            la firma a sus clientes.
                        </p>
                    </section>

                    {canManage && (
                        <section>
                            <h2 className="mb-3 text-lg font-black">
                                Gestión de cobros
                            </h2>
                            <ManagePanel
                                month={month}
                                companies={props.companies}
                            />
                        </section>
                    )}
                </div>
            </main>

            <ConfirmDialog
                open={declining !== null}
                tone="danger"
                icon={<ShieldX className="size-7" />}
                title="No confirmar este pago"
                description={
                    <>
                        El pago de <strong>{declining?.company}</strong> por{' '}
                        <strong>{money(declining?.amount_cents ?? 0)}</strong>{' '}
                        (ref. {declining?.reference}) quedará como no confirmado
                        y el cliente verá tu motivo.
                    </>
                }
                noteLabel="Motivo (lo verá el cliente)"
                noteRequired
                confirmLabel="No confirmar pago"
                onConfirm={(note) => {
                    if (declining) {
                        router.post(
                            `/office/revenue/notices/${declining.id}/reject`,
                            { note },
                            { preserveScroll: true },
                        );
                    }

                    setDeclining(null);
                }}
                onClose={() => setDeclining(null)}
            />
        </>
    );
}
