import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    Check,
    CircleDollarSign,
    Clock3,
    ExternalLink,
    FileText,
    Search,
    UserRound,
    WalletCards,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import type { Company } from '@/components/accounting-section';
import { money, Pager, primary, secondary } from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type Payment = {
    id: number;
    amount_cents: number | string;
    received_on: string;
    reference: string;
};

type Receivable = {
    id: number;
    public_id: string;
    number: number | null;
    sales_customer_id: number;
    customer_name: string;
    customer_tax_id: string | null;
    customer_email: string | null;
    issued_on: string;
    due_on: string;
    total_cents: number | string;
    paid_cents: number | string;
    outstanding_cents: number | string;
    payments: Payment[];
};

type Summary = {
    outstanding: number;
    current: number;
    overdue: number;
    over_90: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    overdue_documents: number;
    delinquent_customers: number;
    collected_this_month: number;
};

type TopCustomer = {
    sales_customer_id: number;
    customer_name: string;
    outstanding_cents: number | string;
};

const amount = (value: number | string) => Number(value);
const dateValue = (date: string) => Date.parse(`${date}T00:00:00Z`);
const daysBetween = (date: string, today: string) =>
    Math.round((dateValue(date) - dateValue(today)) / 86_400_000);
const documentLabel = (invoice: Receivable) =>
    invoice.number
        ? `INT-${String(invoice.number).padStart(4, '0')}`
        : 'BORRADOR';

function collectionState(invoice: Receivable, today: string): string {
    if (invoice.due_on < today) {
        return 'Vencida';
    }

    return amount(invoice.paid_cents) > 0 ? 'Parcial' : 'Por cobrar';
}

function stateClass(state: string): string {
    if (state === 'Vencida') {
        return 'bg-rose-50 text-rose-700';
    }

    if (state === 'Parcial') {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-emerald-50 text-emerald-700';
}

export default function Receivables({
    company,
    receivables,
    filters,
    summary,
    topCustomers,
    today,
    canWrite,
}: {
    company: Company;
    receivables: Page<Receivable>;
    filters: { search: string; status: string; age: string; due_to: string };
    summary: Summary;
    topCustomers: TopCustomer[];
    today: string;
    canWrite: boolean;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(
        receivables.data[0]?.id ?? null,
    );
    const search = useForm({
        search: filters.search ?? '',
        status: filters.status ?? 'all',
        age: filters.age ?? 'all',
        due_to: filters.due_to ?? '',
    });
    const selected = useMemo(
        () =>
            receivables.data.find((row) => row.id === selectedId) ??
            receivables.data[0] ??
            null,
        [receivables.data, selectedId],
    );
    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        router.get(
            routes.receivables.url(company.public_id),
            {
                search: search.data.search || undefined,
                status:
                    search.data.status === 'all'
                        ? undefined
                        : search.data.status,
                age: search.data.age === 'all' ? undefined : search.data.age,
                due_to: search.data.due_to || undefined,
            },
            { preserveState: true, replace: true },
        );
    };
    const share = (value: number) =>
        summary.outstanding > 0
            ? Math.round((value / summary.outstanding) * 1000) / 10
            : 0;
    const maxCustomer = Math.max(
        1,
        ...topCustomers.map((customer) => amount(customer.outstanding_cents)),
    );

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Cuentas por Cobrar" />
            <div className="mx-auto max-w-[1700px] space-y-4">
                <header className="flex items-center gap-4">
                    <span className="grid size-11 place-items-center rounded-xl bg-[#8f1f30] text-white shadow-lg shadow-[#8f1f30]/20">
                        <FileText className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Cuentas por Cobrar
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestión de cartera, cobros y seguimiento de clientes
                        </p>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {[
                        {
                            label: 'Saldo total por cobrar',
                            value: money(summary.outstanding),
                            hint: 'Cartera pendiente',
                            icon: CircleDollarSign,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                        {
                            label: 'Por vencer',
                            value: money(summary.current),
                            hint: `${share(summary.current)}% del total`,
                            icon: Clock3,
                            tone: 'bg-blue-50 text-blue-700',
                        },
                        {
                            label: 'Vencido',
                            value: money(summary.overdue),
                            hint: `${share(summary.overdue)}% del total`,
                            icon: AlertTriangle,
                            tone: 'bg-orange-50 text-orange-700',
                        },
                        {
                            label: '+90 días vencido',
                            value: money(summary.over_90),
                            hint: `${share(summary.over_90)}% del total`,
                            icon: Clock3,
                            tone: 'bg-rose-50 text-rose-700',
                        },
                        {
                            label: 'Facturas vencidas',
                            value: summary.overdue_documents,
                            hint: `${summary.delinquent_customers} clientes morosos`,
                            icon: FileText,
                            tone: 'bg-violet-50 text-violet-700',
                        },
                        {
                            label: 'Cobrado este mes',
                            value: money(summary.collected_this_month),
                            hint: 'Cobros no anulados',
                            icon: WalletCards,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="min-h-28 rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-[0_7px_20px_rgba(31,38,55,.05)]"
                        >
                            <div className="flex items-center gap-3">
                                <span
                                    className={`grid size-10 shrink-0 place-items-center rounded-full ${metric.tone}`}
                                >
                                    <metric.icon className="size-5" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-[10px] font-medium text-slate-500">
                                        {metric.label}
                                    </p>
                                    <strong className="mt-1 block truncate text-base">
                                        {metric.value}
                                    </strong>
                                </div>
                            </div>
                            <p className="mt-3 text-[10px] text-slate-400">
                                {metric.hint}
                            </p>
                        </article>
                    ))}
                </section>

                <section className="grid gap-4 xl:grid-cols-2">
                    <AgingChart summary={summary} />
                    <article className="rounded-2xl border border-[#e4e8ef] bg-white p-5 shadow-[0_7px_20px_rgba(31,38,55,.05)]">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold">
                                Cartera pendiente por cliente (Top 5)
                            </h2>
                            <span className="text-[10px] text-slate-400">
                                Saldo actual
                            </span>
                        </div>
                        <div className="mt-5 space-y-4">
                            {topCustomers.map((customer) => (
                                <div
                                    key={customer.sales_customer_id}
                                    className="grid grid-cols-[120px_1fr_auto] items-center gap-3 text-[10px]"
                                >
                                    <span className="truncate font-medium">
                                        {customer.customer_name}
                                    </span>
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className="h-full rounded-full bg-gradient-to-r from-[#8f1f30] to-[#d94c4c]"
                                            style={{
                                                width: `${Math.max(4, (amount(customer.outstanding_cents) / maxCustomer) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                    <strong>
                                        {money(customer.outstanding_cents)}
                                    </strong>
                                </div>
                            ))}
                            {!topCustomers.length && (
                                <p className="py-8 text-center text-sm text-slate-400">
                                    No hay cartera pendiente.
                                </p>
                            )}
                        </div>
                    </article>
                </section>

                <div className="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_490px]">
                    <section className="min-w-0 overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
                        <form
                            className="grid gap-3 border-b border-[#e8ebf1] p-4 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_160px_160px_180px_110px]"
                            onSubmit={submit}
                        >
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    className="h-10 w-full rounded-xl border border-[#dfe3ea] pr-3 pl-10 text-sm outline-none focus:border-[#8f1f30]"
                                    value={search.data.search}
                                    onChange={(event) =>
                                        search.setData(
                                            'search',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Factura, cliente o NIT..."
                                    aria-label="Buscar cuenta por cobrar"
                                />
                            </label>
                            <select
                                className="h-10 rounded-xl border border-[#dfe3ea] px-3 text-sm"
                                value={search.data.status}
                                onChange={(event) =>
                                    search.setData('status', event.target.value)
                                }
                                aria-label="Estado de cobro"
                            >
                                <option value="all">Todos los estados</option>
                                <option value="pending">Por cobrar</option>
                                <option value="partial">Pago parcial</option>
                                <option value="overdue">Vencidas</option>
                            </select>
                            <select
                                className="h-10 rounded-xl border border-[#dfe3ea] px-3 text-sm"
                                value={search.data.age}
                                onChange={(event) =>
                                    search.setData('age', event.target.value)
                                }
                                aria-label="Antigüedad del saldo"
                            >
                                <option value="all">Toda antigüedad</option>
                                <option value="current">Por vencer</option>
                                <option value="1_30">1–30 días</option>
                                <option value="31_60">31–60 días</option>
                                <option value="61_90">61–90 días</option>
                                <option value="over_90">Más de 90 días</option>
                            </select>
                            <label className="relative">
                                <CalendarDays className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    type="date"
                                    value={search.data.due_to}
                                    onChange={(event) =>
                                        search.setData(
                                            'due_to',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-xl border border-[#dfe3ea] pr-3 pl-10 text-sm"
                                    aria-label="Vencimiento hasta"
                                />
                            </label>
                            <button className={primary}>Filtrar</button>
                        </form>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-left text-xs">
                                <thead className="bg-[#fbfcfd] text-[10px] tracking-wide text-slate-500 uppercase">
                                    <tr>
                                        <th className="p-4">Documento</th>
                                        <th className="p-4">Cliente</th>
                                        <th className="p-4">Emisión</th>
                                        <th className="p-4">Vencimiento</th>
                                        <th className="p-4 text-right">
                                            Original
                                        </th>
                                        <th className="p-4 text-right">
                                            Cobrado
                                        </th>
                                        <th className="p-4 text-right">
                                            Saldo
                                        </th>
                                        <th className="p-4">Antigüedad</th>
                                        <th className="p-4">Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {receivables.data.map((invoice) => {
                                        const state = collectionState(
                                            invoice,
                                            today,
                                        );
                                        const days = daysBetween(
                                            invoice.due_on,
                                            today,
                                        );

                                        return (
                                            <tr
                                                key={invoice.id}
                                                onClick={() =>
                                                    setSelectedId(invoice.id)
                                                }
                                                className={`cursor-pointer border-t border-[#edf0f4] transition hover:bg-[#fbf7f7] ${selected?.id === invoice.id ? 'bg-[#fdf5f5]' : ''}`}
                                            >
                                                <td className="p-4 font-semibold text-[#8f1f30]">
                                                    {documentLabel(invoice)}
                                                </td>
                                                <td className="p-4">
                                                    <p className="font-medium">
                                                        {invoice.customer_name}
                                                    </p>
                                                    <p className="mt-0.5 text-[10px] text-slate-400">
                                                        {invoice.customer_tax_id ||
                                                            'C/F'}
                                                    </p>
                                                </td>
                                                <td className="p-4">
                                                    {invoice.issued_on}
                                                </td>
                                                <td className="p-4">
                                                    {invoice.due_on}
                                                </td>
                                                <td className="p-4 text-right">
                                                    {money(invoice.total_cents)}
                                                </td>
                                                <td className="p-4 text-right text-emerald-700">
                                                    {money(invoice.paid_cents)}
                                                </td>
                                                <td className="p-4 text-right font-semibold">
                                                    {money(
                                                        invoice.outstanding_cents,
                                                    )}
                                                </td>
                                                <td
                                                    className={`p-4 font-medium ${days < 0 ? 'text-rose-700' : ''}`}
                                                >
                                                    {days < 0
                                                        ? `${Math.abs(days)} días vencida`
                                                        : `Vence en ${days} días`}
                                                </td>
                                                <td className="p-4">
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${stateClass(state)}`}
                                                    >
                                                        {state}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {!receivables.data.length && (
                            <div className="grid min-h-52 place-items-center p-8 text-center">
                                <div>
                                    <Check className="mx-auto size-10 text-emerald-400" />
                                    <p className="mt-3 font-semibold">
                                        No hay saldos pendientes
                                    </p>
                                    <p className="mt-1 text-sm text-slate-500">
                                        La cartera está al día con estos
                                        filtros.
                                    </p>
                                </div>
                            </div>
                        )}
                        <div className="px-4 pb-4">
                            <Pager page={receivables} />
                        </div>
                    </section>

                    <ReceivablePanel
                        company={company}
                        invoice={selected}
                        today={today}
                        canWrite={canWrite}
                    />
                </div>
            </div>
        </div>
    );
}

function AgingChart({ summary }: { summary: Summary }) {
    const buckets = [
        ['Por vencer', summary.current, '#3b82f6'],
        ['1–30 días', summary.days_1_30, '#2dd4bf'],
        ['31–60 días', summary.days_31_60, '#f5c451'],
        ['61–90 días', summary.days_61_90, '#f97316'],
        ['+90 días', summary.over_90, '#ef4444'],
    ] as const;
    let cursor = 0;
    const stops = buckets.map(([, value, color]) => {
        const start = cursor;
        cursor +=
            summary.outstanding > 0 ? (value / summary.outstanding) * 100 : 0;

        return `${color} ${start}% ${cursor}%`;
    });

    return (
        <article className="rounded-2xl border border-[#e4e8ef] bg-white p-5 shadow-[0_7px_20px_rgba(31,38,55,.05)]">
            <h2 className="text-sm font-semibold">Antigüedad de saldos</h2>
            <div className="mt-4 grid items-center gap-5 sm:grid-cols-[180px_1fr]">
                <div
                    className="relative mx-auto grid size-40 place-items-center rounded-full"
                    style={{
                        background:
                            summary.outstanding > 0
                                ? `conic-gradient(${stops.join(', ')})`
                                : '#e8edf3',
                    }}
                >
                    <div className="grid size-24 place-items-center rounded-full bg-white text-center shadow-inner">
                        <div>
                            <strong className="text-sm">
                                {money(summary.outstanding)}
                            </strong>
                            <p className="text-[9px] text-slate-400">Total</p>
                        </div>
                    </div>
                </div>
                <div className="space-y-3">
                    {buckets.map(([label, value, color]) => (
                        <div
                            key={label}
                            className="grid grid-cols-[1fr_auto_auto] items-center gap-3 text-[10px]"
                        >
                            <span className="flex items-center gap-2">
                                <i
                                    className="size-2 rounded-full"
                                    style={{ backgroundColor: color }}
                                />
                                {label}
                            </span>
                            <strong>{money(value)}</strong>
                            <span className="w-10 text-right text-slate-400">
                                {summary.outstanding
                                    ? `${Math.round((value / summary.outstanding) * 1000) / 10}%`
                                    : '0%'}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </article>
    );
}

function ReceivablePanel({
    company,
    invoice,
    today,
    canWrite,
}: {
    company: Company;
    invoice: Receivable | null;
    today: string;
    canWrite: boolean;
}) {
    if (!invoice) {
        return (
            <aside className="grid min-h-96 place-items-center rounded-2xl border border-[#e4e8ef] bg-white p-8 text-center">
                <div>
                    <FileText className="mx-auto size-11 text-slate-300" />
                    <p className="mt-3 font-semibold">
                        Selecciona un documento
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        Aquí aparecerá el resumen de la cuenta por cobrar.
                    </p>
                </div>
            </aside>
        );
    }

    const state = collectionState(invoice, today);
    const days = daysBetween(invoice.due_on, today);
    const paid = amount(invoice.paid_cents);

    return (
        <aside className="overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
            <header className="border-b border-[#e7eaf0] p-5">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h2 className="text-lg font-semibold">
                                {documentLabel(invoice)}
                            </h2>
                            <span
                                className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${stateClass(state)}`}
                            >
                                {state}
                            </span>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            {invoice.customer_name} · NIT{' '}
                            {invoice.customer_tax_id || 'C/F'}
                        </p>
                    </div>
                    <X className="size-4 text-slate-300" />
                </div>
                <div className="mt-5 grid grid-cols-3 divide-x divide-slate-100 rounded-xl border border-slate-100 p-3 text-center">
                    <div>
                        <p className="text-[9px] text-slate-400">Original</p>
                        <strong className="mt-1 block text-xs">
                            {money(invoice.total_cents)}
                        </strong>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Cobrado</p>
                        <strong className="mt-1 block text-xs text-emerald-700">
                            {money(paid)}
                        </strong>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Saldo</p>
                        <strong className="mt-1 block text-xs text-rose-700">
                            {money(invoice.outstanding_cents)}
                        </strong>
                    </div>
                </div>
            </header>
            <div className="space-y-4 p-4">
                <section className="grid grid-cols-2 gap-3 rounded-xl border border-[#e7eaf0] p-4 text-xs">
                    <div>
                        <p className="text-[9px] text-slate-400">Emisión</p>
                        <p className="mt-1 font-semibold">
                            {invoice.issued_on}
                        </p>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Vencimiento</p>
                        <p
                            className={`mt-1 font-semibold ${days < 0 ? 'text-rose-700' : ''}`}
                        >
                            {invoice.due_on}
                        </p>
                        <p className="mt-1 text-[9px] text-slate-400">
                            {days < 0
                                ? `${Math.abs(days)} días vencida`
                                : `Vence en ${days} días`}
                        </p>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Condición</p>
                        <p className="mt-1 font-semibold">
                            {paid > 0 ? 'Pago parcial' : 'Crédito'}
                        </p>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Correo</p>
                        <p className="mt-1 truncate font-semibold">
                            {invoice.customer_email || 'No registrado'}
                        </p>
                    </div>
                </section>

                <section className="rounded-xl border border-[#e7eaf0]">
                    <div className="border-b border-[#e7eaf0] px-4 py-3">
                        <h3 className="text-xs font-semibold">
                            Últimos cobros aplicados
                        </h3>
                    </div>
                    <div className="divide-y divide-[#edf0f4]">
                        {invoice.payments.map((payment) => (
                            <div
                                key={payment.id}
                                className="grid grid-cols-[90px_1fr_auto] gap-3 px-4 py-3 text-[10px]"
                            >
                                <span>{payment.received_on}</span>
                                <span className="truncate text-slate-500">
                                    {payment.reference}
                                </span>
                                <strong className="text-emerald-700">
                                    {money(payment.amount_cents)}
                                </strong>
                            </div>
                        ))}
                        {!invoice.payments.length && (
                            <p className="px-4 py-6 text-center text-xs text-slate-400">
                                Todavía no hay cobros aplicados.
                            </p>
                        )}
                    </div>
                </section>

                <div className="grid gap-2 sm:grid-cols-2">
                    {canWrite && (
                        <Link
                            href={routes.invoice([
                                company.public_id,
                                invoice.public_id,
                            ])}
                            className={primary}
                        >
                            <CircleDollarSign className="size-4" /> Registrar
                            cobro
                        </Link>
                    )}
                    <Link
                        href={routes.salesCustomerShow([
                            company.public_id,
                            invoice.sales_customer_id,
                        ])}
                        className={secondary}
                    >
                        <UserRound className="size-4" /> Ver cliente
                    </Link>
                    <Link
                        href={routes.invoice([
                            company.public_id,
                            invoice.public_id,
                        ])}
                        className={secondary}
                    >
                        <ExternalLink className="size-4" /> Ver comprobante
                    </Link>
                </div>
            </div>
        </aside>
    );
}
