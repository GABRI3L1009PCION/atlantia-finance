import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Check,
    CircleDollarSign,
    Clock3,
    Download,
    EllipsisVertical,
    ExternalLink,
    FileDown,
    FileText,
    Filter,
    Info,
    Mail,
    Plus,
    ReceiptText,
    Search,
    UserRound,
    UsersRound,
    WalletCards,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type Invoice = {
    id: number;
    public_id: string;
    number: number | null;
    customer_name: string;
    status: string;
    due_on: string;
    issued_on: string;
    total_cents: number;
    paid_cents: number;
    customer_tax_id: string | null;
    customer_email: string | null;
    receipt_count: number;
    lines: {
        position: number;
        description: string;
        quantity: number;
        unit_cents: number;
    }[];
};
type Customer = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
    invoice_count: number;
};
export default function Sales({
    company,
    customers,
    invoices,
    filters,
    portfolio,
    canWrite,
    today,
}: {
    company: Company;
    customers: Customer[];
    invoices: Page<Invoice>;
    filters: {
        search: string;
        status: string;
        customer: number | null;
        from: string | null;
        to: string | null;
    };
    portfolio: {
        invoiced: number;
        collected: number;
        outstanding: number;
        overdue: number;
        documents: number;
        collected_documents: number;
        pending_documents: number;
    };
    canWrite: boolean;
    today: string;
}) {
    const [panel, setPanel] = useState<'guide' | 'customer' | 'invoice' | null>(
        null,
    );
    const [selectedInvoiceId, setSelectedInvoiceId] = useState<string | null>(
        null,
    );
    const customer = useForm({ name: '', tax_id: '', email: '' });
    const search = useForm({
        search: filters.search,
        status: filters.status,
        customer: filters.customer ? String(filters.customer) : '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });
    const draft = useForm({
        request_id: crypto.randomUUID(),
        customer_id: '',
        issued_on: today,
        due_on: '',
        lines: [{ description: '', quantity: '1', unit_price: '' }],
    });

    const statusLabel = (invoice: Invoice) => {
        if (invoice.status === 'void') {
            return 'Anulado';
        }

        if (invoice.status === 'draft') {
            return 'Borrador';
        }

        if (Number(invoice.total_cents) === Number(invoice.paid_cents)) {
            return 'Pagado';
        }

        if (Number(invoice.paid_cents) > 0) {
            return 'Cobro parcial';
        }

        return 'Pendiente';
    };

    const statusClass = (invoice: Invoice) => {
        const status = statusLabel(invoice);

        if (status === 'Pagado') {
            return 'bg-emerald-50 text-emerald-700';
        }

        if (status === 'Anulado') {
            return 'bg-slate-100 text-slate-600';
        }

        if (status === 'Borrador') {
            return 'bg-amber-50 text-amber-700';
        }

        if (status === 'Cobro parcial') {
            return 'bg-blue-50 text-blue-700';
        }

        return 'bg-rose-50 text-rose-700';
    };
    const selectedInvoice =
        invoices.data.find(
            (invoice) => invoice.public_id === selectedInvoiceId,
        ) ??
        invoices.data[0] ??
        null;
    const documentName = (invoice: Invoice) =>
        invoice.number
            ? `INT-${String(invoice.number).padStart(4, '0')}`
            : 'BORRADOR';
    const outstanding = (invoice: Invoice) =>
        invoice.status === 'void'
            ? 0
            : Number(invoice.total_cents) - Number(invoice.paid_cents);

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Ventas" />
            <div className="mx-auto max-w-[1680px] space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Ventas
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestión de clientes, documentos internos y cobros
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setPanel('guide')}
                            className={secondary}
                        >
                            <Info className="size-4" /> Guía rápida
                        </button>
                        {canWrite && (
                            <button
                                type="button"
                                onClick={() => setPanel('customer')}
                                className={secondary}
                            >
                                <UsersRound className="size-4" /> Nuevo cliente
                            </button>
                        )}
                        {canWrite && (
                            <button
                                type="button"
                                onClick={() => setPanel('invoice')}
                                className={primary}
                                disabled={customers.length === 0}
                                title={
                                    customers.length === 0
                                        ? 'Primero registra un cliente'
                                        : undefined
                                }
                            >
                                <Plus className="size-4" /> Nueva venta
                            </button>
                        )}
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {[
                        {
                            label: 'Ventas emitidas',
                            value: money(portfolio.invoiced),
                            hint: 'Documentos internos',
                            icon: WalletCards,
                            tone: 'bg-violet-50 text-violet-700',
                        },
                        {
                            label: 'Documentos',
                            value: portfolio.documents,
                            hint: 'Emitidos',
                            icon: FileText,
                            tone: 'bg-blue-50 text-blue-700',
                        },
                        {
                            label: 'Por cobrar',
                            value: money(portfolio.outstanding),
                            hint: `${portfolio.pending_documents} documentos`,
                            icon: CircleDollarSign,
                            tone: 'bg-amber-50 text-amber-700',
                        },
                        {
                            label: 'Cobrado',
                            value: money(portfolio.collected),
                            hint: `${portfolio.collected_documents} documentos`,
                            icon: Check,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                        {
                            label: 'Cartera vencida',
                            value: money(portfolio.overdue),
                            hint: 'Requiere seguimiento',
                            icon: Clock3,
                            tone: 'bg-rose-50 text-rose-700',
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="flex min-h-28 items-center gap-3 rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-[0_7px_20px_rgba(31,38,55,.05)]"
                        >
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block truncate text-lg text-[#172033]">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </article>
                    ))}
                </section>

                <section className="rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-[0_7px_20px_rgba(31,38,55,.05)]">
                    <form
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-[1.35fr_.85fr_.85fr_.85fr_.85fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                routes.sales.url(company.public_id),
                                search.data,
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <label className="text-[10px] font-medium text-slate-500">
                            Buscar
                            <span className="relative mt-1 block">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    className="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-xs"
                                    value={search.data.search}
                                    placeholder="Documento, cliente o NIT..."
                                    onChange={(event) =>
                                        search.setData(
                                            'search',
                                            event.target.value,
                                        )
                                    }
                                />
                            </span>
                        </label>
                        <label className="text-[10px] font-medium text-slate-500">
                            Desde
                            <input
                                type="date"
                                className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                value={search.data.from}
                                onChange={(event) =>
                                    search.setData('from', event.target.value)
                                }
                            />
                        </label>
                        <label className="text-[10px] font-medium text-slate-500">
                            Hasta
                            <input
                                type="date"
                                min={search.data.from}
                                className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                value={search.data.to}
                                onChange={(event) =>
                                    search.setData('to', event.target.value)
                                }
                            />
                        </label>
                        <label className="text-[10px] font-medium text-slate-500">
                            Cliente
                            <select
                                className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                value={search.data.customer}
                                onChange={(event) =>
                                    search.setData(
                                        'customer',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Todos los clientes</option>
                                {customers.map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-[10px] font-medium text-slate-500">
                            Estado
                            <select
                                className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                value={search.data.status}
                                onChange={(event) =>
                                    search.setData('status', event.target.value)
                                }
                            >
                                <option value="all">Todos</option>
                                <option value="draft">Borradores</option>
                                <option value="pending">Pendientes</option>
                                <option value="partial">Cobro parcial</option>
                                <option value="paid">Pagados</option>
                                <option value="overdue">Vencidos</option>
                                <option value="void">Anulados</option>
                            </select>
                        </label>
                        <div className="flex items-end gap-2">
                            <button className={primary}>
                                <Filter className="size-4" /> Filtrar
                            </button>
                            <Link
                                href={routes.sales(company.public_id)}
                                className={secondary}
                            >
                                Limpiar
                            </Link>
                        </div>
                    </form>
                </section>

                <section className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_410px]">
                    <main className="min-w-0 overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_20px_rgba(31,38,55,.05)]">
                        <nav className="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 pt-2">
                            {[
                                ['all', 'Todos'],
                                ['draft', 'Borradores'],
                                ['pending', 'Por cobrar'],
                                ['partial', 'Cobro parcial'],
                                ['paid', 'Cobrados'],
                                ['overdue', 'Vencidos'],
                                ['void', 'Anulados'],
                            ].map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => {
                                        search.setData('status', value);
                                        router.get(
                                            routes.sales.url(company.public_id),
                                            { ...search.data, status: value },
                                            {
                                                preserveState: true,
                                                replace: true,
                                            },
                                        );
                                    }}
                                    className={`border-b-2 px-4 py-3 text-xs font-medium whitespace-nowrap transition ${search.data.status === value ? 'border-[#8a1630] text-[#8a1630]' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </nav>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[960px] text-left text-xs">
                                <thead className="bg-slate-50 text-[10px] text-slate-500 uppercase">
                                    <tr>
                                        <th className="px-4 py-3">Documento</th>
                                        <th className="px-3 py-3">Fecha</th>
                                        <th className="px-3 py-3">Cliente</th>
                                        <th className="px-3 py-3 text-right">
                                            Total
                                        </th>
                                        <th className="px-3 py-3 text-right">
                                            Cobrado
                                        </th>
                                        <th className="px-3 py-3 text-right">
                                            Saldo
                                        </th>
                                        <th className="px-3 py-3">
                                            Estado documental
                                        </th>
                                        <th className="px-3 py-3">
                                            Estado de cobro
                                        </th>
                                        <th className="px-3 py-3 text-right">
                                            Acción
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.data.map((invoice) => {
                                        const balance = outstanding(invoice);

                                        return (
                                            <tr
                                                key={invoice.public_id}
                                                onClick={() =>
                                                    setSelectedInvoiceId(
                                                        invoice.public_id,
                                                    )
                                                }
                                                className={`cursor-pointer border-t border-slate-100 transition ${selectedInvoice?.public_id === invoice.public_id ? 'bg-[#fff5f5]' : 'hover:bg-slate-50'}`}
                                            >
                                                <td className="px-4 py-3 font-semibold text-[#8a1630]">
                                                    {documentName(invoice)}
                                                </td>
                                                <td className="px-3 py-3 text-slate-600">
                                                    {invoice.issued_on}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <strong className="block font-medium">
                                                        {invoice.customer_name}
                                                    </strong>
                                                    <span className="mt-1 block text-[10px] text-slate-400">
                                                        {invoice.customer_tax_id ||
                                                            'NIT no indicado'}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-3 text-right font-medium">
                                                    {money(invoice.total_cents)}
                                                </td>
                                                <td className="px-3 py-3 text-right text-emerald-700">
                                                    {money(invoice.paid_cents)}
                                                </td>
                                                <td
                                                    className={`px-3 py-3 text-right font-medium ${balance > 0 ? 'text-rose-700' : 'text-slate-500'}`}
                                                >
                                                    {invoice.status === 'void'
                                                        ? '—'
                                                        : money(balance)}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <span
                                                        className={`rounded-full px-2 py-1 text-[9px] font-semibold ${invoice.status === 'issued' ? 'bg-emerald-50 text-emerald-700' : invoice.status === 'draft' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`}
                                                    >
                                                        {invoice.status ===
                                                        'issued'
                                                            ? 'Emitido'
                                                            : invoice.status ===
                                                                'draft'
                                                              ? 'Borrador'
                                                              : 'Anulado'}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <span
                                                        className={`rounded-full px-2 py-1 text-[9px] font-semibold ${statusClass(invoice)}`}
                                                    >
                                                        {statusLabel(invoice)}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    <button
                                                        type="button"
                                                        aria-label={`Seleccionar ${documentName(invoice)}`}
                                                        className="rounded-lg p-2 text-slate-400 hover:bg-white hover:text-[#8a1630]"
                                                    >
                                                        <EllipsisVertical
                                                            size={15}
                                                        />
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {!invoices.data.length && (
                            <div className="flex flex-col items-center border-t border-slate-100 px-6 py-14 text-center">
                                <span className="grid size-14 place-items-center rounded-full bg-[#f5e7e7] text-[#7d0808]">
                                    <FileText className="size-7" />
                                </span>
                                <h3 className="mt-4 font-semibold">
                                    No hay documentos de venta
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">
                                    Ajusta los filtros o registra una nueva
                                    venta.
                                </p>
                            </div>
                        )}
                        <div className="border-t border-slate-100 px-4 py-3">
                            <Pager page={invoices} />
                        </div>
                    </main>

                    <aside className="sticky top-4 overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_9px_24px_rgba(31,38,55,.07)]">
                        {selectedInvoice ? (
                            <>
                                <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-lg font-semibold">
                                                {documentName(selectedInvoice)}
                                            </h2>
                                            <span
                                                className={`rounded-full px-2 py-1 text-[9px] font-semibold ${statusClass(selectedInvoice)}`}
                                            >
                                                {statusLabel(selectedInvoice)}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-[10px] text-slate-400">
                                            Documento interno de venta
                                        </p>
                                    </div>
                                    <a
                                        href={routes.invoicePdf.url([
                                            company.public_id,
                                            selectedInvoice.public_id,
                                        ])}
                                        className="rounded-lg border border-slate-200 p-2 text-slate-500 hover:text-[#8a1630]"
                                        title="Descargar PDF"
                                    >
                                        <FileDown size={16} />
                                    </a>
                                </header>
                                <div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                    <article className="rounded-xl border border-slate-200 p-3">
                                        <div className="flex items-center gap-2">
                                            <span className="grid size-8 place-items-center rounded-full bg-violet-50 text-violet-700">
                                                <UserRound size={15} />
                                            </span>
                                            <div>
                                                <strong className="block text-xs">
                                                    {
                                                        selectedInvoice.customer_name
                                                    }
                                                </strong>
                                                <span className="text-[10px] text-slate-400">
                                                    NIT{' '}
                                                    {selectedInvoice.customer_tax_id ||
                                                        'no indicado'}
                                                </span>
                                            </div>
                                        </div>
                                        {selectedInvoice.customer_email && (
                                            <p className="mt-3 flex items-center gap-2 text-[10px] text-slate-500">
                                                <Mail size={13} />
                                                {selectedInvoice.customer_email}
                                            </p>
                                        )}
                                    </article>
                                    <article className="rounded-xl border border-slate-200 p-3 text-[10px]">
                                        <p className="flex justify-between gap-3">
                                            <span className="text-slate-500">
                                                Emisión
                                            </span>
                                            <strong>
                                                {selectedInvoice.issued_on}
                                            </strong>
                                        </p>
                                        <p className="mt-2 flex justify-between gap-3">
                                            <span className="text-slate-500">
                                                Vencimiento
                                            </span>
                                            <strong
                                                className={
                                                    outstanding(
                                                        selectedInvoice,
                                                    ) > 0 &&
                                                    selectedInvoice.due_on <
                                                        today
                                                        ? 'text-rose-700'
                                                        : ''
                                                }
                                            >
                                                {selectedInvoice.due_on}
                                            </strong>
                                        </p>
                                        <p className="mt-2 flex justify-between gap-3">
                                            <span className="text-slate-500">
                                                Cobros registrados
                                            </span>
                                            <strong>
                                                {selectedInvoice.receipt_count}
                                            </strong>
                                        </p>
                                    </article>
                                </div>
                                <div className="grid grid-cols-2 gap-3 border-y border-slate-100 p-4 text-xs">
                                    <article className="rounded-xl border border-slate-200 p-3">
                                        <p className="text-[10px] text-slate-500">
                                            Total
                                        </p>
                                        <strong className="mt-2 block text-lg">
                                            {money(selectedInvoice.total_cents)}
                                        </strong>
                                        <p className="mt-2 text-[10px] text-slate-400">
                                            Moneda GTQ
                                        </p>
                                    </article>
                                    <article className="rounded-xl border border-slate-200 p-3">
                                        <p className="flex justify-between text-[10px]">
                                            <span className="text-slate-500">
                                                Cobrado
                                            </span>
                                            <strong className="text-emerald-700">
                                                {money(
                                                    selectedInvoice.paid_cents,
                                                )}
                                            </strong>
                                        </p>
                                        <p className="mt-3 flex justify-between text-[10px]">
                                            <span className="text-slate-500">
                                                Saldo
                                            </span>
                                            <strong className="text-rose-700">
                                                {money(
                                                    outstanding(
                                                        selectedInvoice,
                                                    ),
                                                )}
                                            </strong>
                                        </p>
                                        <p className="mt-3 flex justify-between text-[10px]">
                                            <span className="text-slate-500">
                                                Estado
                                            </span>
                                            <strong>
                                                {statusLabel(selectedInvoice)}
                                            </strong>
                                        </p>
                                    </article>
                                </div>
                                <div className="p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h3 className="text-xs font-semibold">
                                            Detalle de la venta
                                        </h3>
                                        <span className="text-[10px] text-slate-400">
                                            {selectedInvoice.lines.length}{' '}
                                            concepto(s)
                                        </span>
                                    </div>
                                    <div className="overflow-hidden rounded-xl border border-slate-200">
                                        <table className="w-full text-[10px]">
                                            <thead className="bg-slate-50 text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-2 text-left">
                                                        Cant.
                                                    </th>
                                                    <th className="px-3 py-2 text-left">
                                                        Descripción
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Total
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {selectedInvoice.lines.map(
                                                    (line) => (
                                                        <tr
                                                            key={line.position}
                                                            className="border-t border-slate-100"
                                                        >
                                                            <td className="px-3 py-2">
                                                                {line.quantity}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {
                                                                    line.description
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2 text-right">
                                                                {money(
                                                                    Number(
                                                                        line.unit_cents,
                                                                    ) *
                                                                        Number(
                                                                            line.quantity,
                                                                        ),
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div className="mt-3 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-[10px] leading-4 text-amber-900">
                                        <Info
                                            size={14}
                                            className="mt-0.5 shrink-0"
                                        />
                                        Control interno: no es factura FEL, no
                                        calcula IVA y no genera asientos
                                        automáticos.
                                    </div>
                                    <div className="mt-4 grid grid-cols-2 gap-2">
                                        <Link
                                            href={routes.invoice([
                                                company.public_id,
                                                selectedInvoice.public_id,
                                            ])}
                                            className={primary}
                                        >
                                            <ExternalLink size={14} /> Abrir
                                            detalle
                                        </Link>
                                        <a
                                            href={routes.invoicePdf.url([
                                                company.public_id,
                                                selectedInvoice.public_id,
                                            ])}
                                            className={secondary}
                                        >
                                            <Download size={14} /> PDF
                                        </a>
                                    </div>
                                </div>
                            </>
                        ) : (
                            <div className="grid min-h-[520px] place-items-center p-8 text-center">
                                <div>
                                    <ReceiptText className="mx-auto text-[#b67e7d]" />
                                    <p className="mt-3 text-sm font-medium">
                                        Selecciona un documento
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Aquí aparecerá el resumen de la venta.
                                    </p>
                                </div>
                            </div>
                        )}
                    </aside>
                </section>
                {canWrite && (
                    <>
                        {panel === 'customer' && (
                            <Modal
                                title="Registrar cliente"
                                subtitle="Crea el expediente comercial para consultar saldos, comprobantes e historial de cobros."
                                onClose={() => setPanel(null)}
                            >
                                <Card title="Datos del cliente">
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            customer.post(
                                                routes.salesCustomer.url(
                                                    company.public_id,
                                                ),
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        customer.reset();
                                                        setPanel(null);
                                                    },
                                                },
                                            );
                                        }}
                                    >
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            <label>
                                                Nombre o razón social
                                                <input
                                                    required
                                                    maxLength={150}
                                                    className={field}
                                                    value={customer.data.name}
                                                    onChange={(e) =>
                                                        customer.setData(
                                                            'name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label>
                                                NIT / identificador (opcional)
                                                <input
                                                    maxLength={30}
                                                    className={field}
                                                    value={customer.data.tax_id}
                                                    onChange={(e) =>
                                                        customer.setData(
                                                            'tax_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label>
                                                Correo (opcional)
                                                <input
                                                    type="email"
                                                    className={field}
                                                    value={customer.data.email}
                                                    onChange={(e) =>
                                                        customer.setData(
                                                            'email',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                        <Errors errors={customer.errors} />
                                        <button
                                            disabled={customer.processing}
                                            className={`${primary} mt-4`}
                                        >
                                            Guardar cliente
                                        </button>
                                    </form>
                                </Card>
                            </Modal>
                        )}
                        {panel === 'invoice' && (
                            <Modal
                                title="Nuevo comprobante interno"
                                subtitle="Prepara conceptos, fechas y cliente. El servidor calculará el total antes de emitir."
                                onClose={() => setPanel(null)}
                                wide
                            >
                                <Card title="Datos del comprobante">
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            draft.post(
                                                routes.salesStore.url(
                                                    company.public_id,
                                                ),
                                            );
                                        }}
                                    >
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            <label>
                                                Cliente
                                                <select
                                                    required
                                                    className={field}
                                                    value={
                                                        draft.data.customer_id
                                                    }
                                                    onChange={(e) =>
                                                        draft.setData(
                                                            'customer_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Seleccionar cliente
                                                    </option>
                                                    {customers.map((c) => (
                                                        <option
                                                            key={c.id}
                                                            value={c.id}
                                                        >
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label>
                                                Fecha de emisión
                                                <input
                                                    required
                                                    type="date"
                                                    className={field}
                                                    value={draft.data.issued_on}
                                                    onChange={(e) =>
                                                        draft.setData(
                                                            'issued_on',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label>
                                                Vencimiento
                                                <input
                                                    required
                                                    type="date"
                                                    min={draft.data.issued_on}
                                                    className={field}
                                                    value={draft.data.due_on}
                                                    onChange={(e) =>
                                                        draft.setData(
                                                            'due_on',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                        <div className="mt-6 space-y-3">
                                            {draft.data.lines.map(
                                                (line, index) => (
                                                    <div
                                                        key={index}
                                                        className="grid items-end gap-3 rounded-xl bg-[#faf8f7] p-4 sm:grid-cols-[1fr_110px_150px_auto]"
                                                    >
                                                        {(
                                                            [
                                                                'description',
                                                                'quantity',
                                                                'unit_price',
                                                            ] as const
                                                        ).map((key) => (
                                                            <label key={key}>
                                                                {key ===
                                                                'description'
                                                                    ? 'Concepto'
                                                                    : key ===
                                                                        'quantity'
                                                                      ? 'Cantidad'
                                                                      : 'Precio unitario Q'}
                                                                <input
                                                                    required
                                                                    type={
                                                                        key ===
                                                                        'description'
                                                                            ? 'text'
                                                                            : 'number'
                                                                    }
                                                                    min={
                                                                        key ===
                                                                        'quantity'
                                                                            ? 1
                                                                            : 0.01
                                                                    }
                                                                    step={
                                                                        key ===
                                                                        'quantity'
                                                                            ? 1
                                                                            : 0.01
                                                                    }
                                                                    maxLength={
                                                                        key ===
                                                                        'description'
                                                                            ? 250
                                                                            : undefined
                                                                    }
                                                                    className={
                                                                        field
                                                                    }
                                                                    value={
                                                                        line[
                                                                            key
                                                                        ]
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        draft.setData(
                                                                            'lines',
                                                                            draft.data.lines.map(
                                                                                (
                                                                                    l,
                                                                                    n,
                                                                                ) =>
                                                                                    n ===
                                                                                    index
                                                                                        ? {
                                                                                              ...l,
                                                                                              [key]: e
                                                                                                  .target
                                                                                                  .value,
                                                                                          }
                                                                                        : l,
                                                                            ),
                                                                        )
                                                                    }
                                                                />
                                                            </label>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            aria-label={`Eliminar concepto ${index + 1}`}
                                                            disabled={
                                                                draft.data.lines
                                                                    .length ===
                                                                1
                                                            }
                                                            className={
                                                                secondary
                                                            }
                                                            onClick={() =>
                                                                draft.setData(
                                                                    'lines',
                                                                    draft.data.lines.filter(
                                                                        (
                                                                            _,
                                                                            n,
                                                                        ) =>
                                                                            n !==
                                                                            index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Quitar
                                                        </button>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                        <Errors errors={draft.errors} />
                                        <div className="mt-4 flex flex-wrap gap-3">
                                            <button
                                                type="button"
                                                className={secondary}
                                                disabled={
                                                    draft.data.lines.length >=
                                                    50
                                                }
                                                onClick={() =>
                                                    draft.setData('lines', [
                                                        ...draft.data.lines,
                                                        {
                                                            description: '',
                                                            quantity: '1',
                                                            unit_price: '',
                                                        },
                                                    ])
                                                }
                                            >
                                                Añadir concepto
                                            </button>
                                            <button
                                                className={primary}
                                                disabled={
                                                    draft.processing ||
                                                    customers.length === 0
                                                }
                                            >
                                                Guardar borrador
                                            </button>
                                        </div>
                                        <p className="mt-3 text-sm text-slate-500">
                                            El servidor calculará el total
                                            exacto. Revisa el borrador antes de
                                            emitirlo; únicamente el propietario
                                            puede emitir y anular.
                                        </p>
                                    </form>
                                </Card>
                            </Modal>
                        )}
                    </>
                )}
                {panel === 'guide' && (
                    <Modal
                        title="Guía rápida de facturación y cobros"
                        subtitle="Flujo recomendado para mantener una cartera clara y trazable."
                        onClose={() => setPanel(null)}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            {[
                                [
                                    '1',
                                    'Registra al cliente',
                                    'Guarda su razón social, NIT y correo de contacto.',
                                ],
                                [
                                    '2',
                                    'Prepara el comprobante',
                                    'Agrega conceptos, cantidades, precios y vencimiento.',
                                ],
                                [
                                    '3',
                                    'Revisa y emite',
                                    'El propietario valida el borrador y asigna su numeración interna.',
                                ],
                                [
                                    '4',
                                    'Registra los cobros',
                                    'Aplica pagos parciales o totales y consulta el saldo pendiente.',
                                ],
                            ].map(([number, title, description]) => (
                                <div
                                    key={number}
                                    className="rounded-2xl border border-[#eadede] bg-[#fdfafa] p-5"
                                >
                                    <span className="flex size-9 items-center justify-center rounded-full bg-[#700000] text-sm font-bold text-white">
                                        {number}
                                    </span>
                                    <h3 className="mt-4 font-semibold">
                                        {title}
                                    </h3>
                                    <p className="mt-1 text-sm leading-6 text-slate-500">
                                        {description}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <div className="mt-5 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <Info className="mt-0.5 size-5 shrink-0" />
                            <p>
                                Estos documentos son comprobantes internos. No
                                sustituyen una factura electrónica FEL.
                            </p>
                        </div>
                    </Modal>
                )}
            </div>
        </div>
    );
}

function Modal({
    title,
    subtitle,
    onClose,
    wide = false,
    children,
}: {
    title: string;
    subtitle: string;
    onClose: () => void;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-[#07101f]/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label={title}
            onMouseDown={(event) => {
                if (event.currentTarget === event.target) {
                    onClose();
                }
            }}
        >
            <div
                className={`max-h-[92vh] w-full overflow-y-auto rounded-3xl bg-[#faf8f7] shadow-2xl ${wide ? 'max-w-6xl' : 'max-w-3xl'}`}
            >
                <header className="sticky top-0 z-10 flex items-start justify-between gap-6 border-b border-[#eadede] bg-white px-6 py-5 sm:px-8">
                    <div>
                        <p className="text-xs font-semibold tracking-[.18em] text-[#8a1212] uppercase">
                            Atlantia Finance
                        </p>
                        <h2 className="mt-1 text-2xl font-semibold text-[#142135]">
                            {title}
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            {subtitle}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-[#b67e7d] hover:text-[#700000]"
                        aria-label="Cerrar"
                    >
                        <X className="size-5" />
                    </button>
                </header>
                <div className="p-4 sm:p-6">{children}</div>
            </div>
        </div>
    );
}
