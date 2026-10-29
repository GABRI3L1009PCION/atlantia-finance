import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    ChevronDown,
    CircleDollarSign,
    Clock3,
    Download,
    EllipsisVertical,
    FileText,
    Filter,
    Landmark,
    Plus,
    Search,
    Upload,
    UserRound,
    WalletCards,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import type { Company } from '@/components/accounting-section';
import {
    field,
    money,
    Pager,
    primary,
    secondary,
    shortDate,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type SupplierOption = { id: number; name: string };
type Payment = {
    id: number;
    amount_cents: number;
    paid_on: string;
    reference: string;
};
type SourceEntry = {
    public_id: string;
    number: number | null;
    status: string;
} | null;
type Payable = {
    id: number;
    public_id: string;
    purchase_supplier_id: number;
    number: number | null;
    external_reference: string | null;
    document_type: string;
    status: string;
    document_date: string;
    due_on: string;
    total_cents: number | string;
    paid_cents: number | string;
    outstanding_cents: number | string;
    supplier_name: string;
    supplier_tax_id: string | null;
    supplier_email: string | null;
    supplier_phone: string | null;
    approved_at: string | null;
    payments: Payment[];
    source_entry: SourceEntry;
};
type Summary = {
    outstanding: number;
    current: number;
    overdue: number;
    upcoming: number;
    paid_this_month: number;
    pending_approval: number;
    all_count: number;
    current_count: number;
    overdue_count: number;
    partial_count: number;
    paid_count: number;
};

const numeric = (value: number | string) => Number(value);
const documentLabel = (row: Payable) =>
    row.external_reference ||
    (row.number ? `CP-${String(row.number).padStart(6, '0')}` : 'BORRADOR');
const dayDifference = (date: string, today: string) =>
    Math.ceil(
        (new Date(`${date}T00:00:00`).getTime() -
            new Date(`${today}T00:00:00`).getTime()) /
            86400000,
    );
const creditDays = (row: Payable) =>
    Math.max(
        0,
        Math.round(
            (new Date(`${row.due_on}T00:00:00`).getTime() -
                new Date(`${row.document_date}T00:00:00`).getTime()) /
                86400000,
        ),
    );

function payableState(row: Payable, today: string): string {
    if (row.status === 'draft') {
        return 'Pendiente aprobación';
    }

    if (numeric(row.outstanding_cents) <= 0) {
        return 'Pagada';
    }

    if (row.due_on < today) {
        return 'Vencida';
    }

    if (numeric(row.paid_cents) > 0) {
        return 'Parcial';
    }

    return 'Por pagar';
}

function stateClass(state: string): string {
    if (state === 'Vencida') {
        return 'bg-rose-50 text-rose-700';
    }

    if (state === 'Parcial' || state === 'Pendiente aprobación') {
        return 'bg-amber-50 text-amber-700';
    }

    if (state === 'Pagada') {
        return 'bg-emerald-50 text-emerald-700';
    }

    return 'bg-blue-50 text-blue-700';
}

export default function Payables({
    company,
    payables,
    suppliers,
    filters,
    summary,
    today,
    canWrite,
}: {
    company: Company;
    payables: Page<Payable>;
    suppliers: SupplierOption[];
    filters: {
        search: string;
        supplier: number | null;
        status: string;
        age: string;
        due_to: string;
    };
    summary: Summary;
    today: string;
    canWrite: boolean;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(
        payables.data[0]?.id ?? null,
    );
    const selected = useMemo(
        () =>
            payables.data.find((item) => item.id === selectedId) ??
            payables.data[0] ??
            null,
        [payables.data, selectedId],
    );
    const search = useForm({
        search: filters.search ?? '',
        supplier: filters.supplier ? String(filters.supplier) : '',
        status: filters.status ?? 'all',
        age: filters.age ?? 'all',
        due_to: filters.due_to ?? '',
    });

    const visit = (status = search.data.status) =>
        router.get(
            routes.payables.url(company.public_id),
            {
                search: search.data.search || undefined,
                supplier: search.data.supplier || undefined,
                status: status === 'all' ? undefined : status,
                age: search.data.age === 'all' ? undefined : search.data.age,
                due_to: search.data.due_to || undefined,
            },
            { preserveState: true, replace: true },
        );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit();
    };
    const tabs = [
        ['all', 'Todas', summary.all_count],
        ['current', 'Por vencer', summary.current_count],
        ['overdue', 'Vencidas', summary.overdue_count],
        ['partial', 'Parciales', summary.partial_count],
        ['draft', 'Pendientes aprobación', summary.pending_approval],
        ['paid', 'Pagadas', summary.paid_count],
    ];

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Cuentas por Pagar" />
            <div className="mx-auto max-w-[1680px] space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid size-12 place-items-center rounded-2xl bg-[#7d0808] text-white shadow-lg shadow-red-950/15">
                            <WalletCards className="size-6" />
                        </span>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Cuentas por Pagar
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Control y gestión de obligaciones con
                                proveedores
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {selected &&
                        selected.status === 'approved' &&
                        numeric(selected.outstanding_cents) > 0 ? (
                            <Link
                                className={primary}
                                href={routes.purchaseShow([
                                    company.public_id,
                                    selected.public_id,
                                ])}
                            >
                                <Plus className="size-4" /> Registrar pago
                            </Link>
                        ) : (
                            <button type="button" className={primary} disabled>
                                <Plus className="size-4" /> Registrar pago
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            disabled
                            title="La programación de pagos requiere el módulo bancario"
                        >
                            <CalendarClock className="size-4" /> Programar pago
                        </button>
                        <button
                            type="button"
                            className={secondary}
                            disabled
                            title="La importación masiva requiere una plantilla validada"
                        >
                            <Upload className="size-4" /> Importar
                        </button>
                        <a
                            className={secondary}
                            href={routes.purchasesExport.url(
                                company.public_id,
                                { query: { format: 'xlsx' } },
                            )}
                        >
                            <Download className="size-4" /> Exportar
                        </a>
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => window.print()}
                        >
                            Más acciones <ChevronDown className="size-4" />
                        </button>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {[
                        {
                            label: 'Saldo total por pagar',
                            value: money(summary.outstanding),
                            hint: 'Obligaciones vigentes',
                            icon: WalletCards,
                            tone: 'bg-blue-50 text-blue-700',
                        },
                        {
                            label: 'Por vencer',
                            value: money(summary.current),
                            hint: `${summary.outstanding ? Math.round((summary.current / summary.outstanding) * 1000) / 10 : 0}% del total`,
                            icon: CalendarDays,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                        {
                            label: 'Vencido',
                            value: money(summary.overdue),
                            hint: `${summary.outstanding ? Math.round((summary.overdue / summary.outstanding) * 1000) / 10 : 0}% del total`,
                            icon: AlertTriangle,
                            tone: 'bg-rose-50 text-rose-700',
                        },
                        {
                            label: 'Próximos 7 días',
                            value: money(summary.upcoming),
                            hint: 'Vencimientos próximos',
                            icon: CalendarClock,
                            tone: 'bg-violet-50 text-violet-700',
                        },
                        {
                            label: 'Pagado este mes',
                            value: money(summary.paid_this_month),
                            hint: 'Pagos vigentes',
                            icon: CircleDollarSign,
                            tone: 'bg-orange-50 text-orange-700',
                        },
                        {
                            label: 'Pendientes aprobación',
                            value: summary.pending_approval,
                            hint: 'Documentos borrador',
                            icon: FileText,
                            tone: 'bg-cyan-50 text-cyan-700',
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="flex min-h-28 flex-col items-start gap-2 rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-[0_7px_20px_rgba(31,38,55,.05)]"
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
                                <strong className="mt-1 block text-lg whitespace-nowrap tabular-nums">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </article>
                    ))}
                </section>

                <div className="grid items-start gap-4 2xl:grid-cols-[minmax(0,1fr)_480px]">
                    <section className="min-w-0 overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
                        <form
                            className="grid gap-3 border-b p-4 lg:grid-cols-[minmax(220px,1fr)_170px_150px_150px_170px_110px]"
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
                                    placeholder="Factura, proveedor, NIT..."
                                />
                            </label>
                            <select
                                className={field}
                                value={search.data.supplier}
                                onChange={(event) =>
                                    search.setData(
                                        'supplier',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Todos los proveedores</option>
                                {suppliers.map((supplier) => (
                                    <option
                                        key={supplier.id}
                                        value={supplier.id}
                                    >
                                        {supplier.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                className={field}
                                value={search.data.status}
                                onChange={(event) =>
                                    search.setData('status', event.target.value)
                                }
                            >
                                <option value="all">Todos los estados</option>
                                <option value="current">Por vencer</option>
                                <option value="partial">Parcial</option>
                                <option value="overdue">Vencida</option>
                                <option value="paid">Pagada</option>
                                <option value="draft">
                                    Pendiente aprobación
                                </option>
                            </select>
                            <select
                                className={field}
                                value={search.data.age}
                                onChange={(event) =>
                                    search.setData('age', event.target.value)
                                }
                            >
                                <option value="all">Toda antigüedad</option>
                                <option value="current">Por vencer</option>
                                <option value="1_30">1–30 días</option>
                                <option value="31_60">31–60 días</option>
                                <option value="61_90">61–90 días</option>
                                <option value="over_90">+90 días</option>
                            </select>
                            <input
                                type="date"
                                className={field}
                                value={search.data.due_to}
                                onChange={(event) =>
                                    search.setData('due_to', event.target.value)
                                }
                                aria-label="Vencimiento hasta"
                            />
                            <button className={primary}>
                                <Filter className="size-4" /> Filtrar
                            </button>
                        </form>
                        <nav className="flex gap-1 overflow-x-auto border-b px-3 pt-2">
                            {tabs.map(([value, label, count]) => (
                                <button
                                    key={String(value)}
                                    type="button"
                                    onClick={() => {
                                        search.setData('status', String(value));
                                        visit(String(value));
                                    }}
                                    className={`border-b-2 px-4 py-3 text-xs font-semibold whitespace-nowrap ${search.data.status === value ? 'border-[#8f1f30] text-[#8f1f30]' : 'border-transparent text-slate-500'}`}
                                >
                                    {label}{' '}
                                    <span className="ml-1 rounded-full bg-slate-100 px-2 py-0.5">
                                        {count}
                                    </span>
                                </button>
                            ))}
                        </nav>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1080px] text-left text-xs">
                                <thead className="bg-[#fbfcfd] text-[10px] tracking-wide text-slate-500 uppercase">
                                    <tr>
                                        <th className="p-4">Documento</th>
                                        <th className="p-4">Proveedor</th>
                                        <th className="p-4">Fecha</th>
                                        <th className="p-4">Vencimiento</th>
                                        <th className="p-4">Condición</th>
                                        <th className="p-4 text-right">
                                            Original
                                        </th>
                                        <th className="p-4 text-right">
                                            Pagado
                                        </th>
                                        <th className="p-4 text-right">
                                            Saldo
                                        </th>
                                        <th className="p-4">Antigüedad</th>
                                        <th className="p-4">Estado</th>
                                        <th className="p-4" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {payables.data.map((row) => {
                                        const state = payableState(row, today);
                                        const days = dayDifference(
                                            row.due_on,
                                            today,
                                        );

                                        return (
                                            <tr
                                                key={row.id}
                                                onClick={() =>
                                                    setSelectedId(row.id)
                                                }
                                                className={`cursor-pointer border-t border-[#edf0f4] hover:bg-[#fbf7f7] ${selected?.id === row.id ? 'bg-[#fdf5f5]' : ''}`}
                                            >
                                                <td className="p-4">
                                                    <b className="text-[#8f1f30]">
                                                        {documentLabel(row)}
                                                    </b>
                                                    <p className="text-[9px] text-slate-400">
                                                        {row.number
                                                            ? `CP-${String(row.number).padStart(6, '0')}`
                                                            : 'Sin contabilizar'}
                                                    </p>
                                                </td>
                                                <td className="p-4">
                                                    <b>{row.supplier_name}</b>
                                                    <p className="text-[9px] text-slate-400">
                                                        NIT:{' '}
                                                        {row.supplier_tax_id ||
                                                            'C/F'}
                                                    </p>
                                                </td>
                                                <td className="p-4">
                                                    {shortDate(
                                                        row.document_date,
                                                    )}
                                                </td>
                                                <td
                                                    className={`p-4 ${days < 0 ? 'text-rose-700' : 'text-emerald-700'}`}
                                                >
                                                    {shortDate(row.due_on)}
                                                    <p className="text-[9px]">
                                                        ({Math.abs(days)} días)
                                                    </p>
                                                </td>
                                                <td className="p-4">
                                                    {creditDays(row)
                                                        ? `Crédito ${creditDays(row)} días`
                                                        : 'Contado'}
                                                </td>
                                                <td className="p-4 text-right">
                                                    {money(row.total_cents)}
                                                </td>
                                                <td className="p-4 text-right text-emerald-700">
                                                    {money(row.paid_cents)}
                                                </td>
                                                <td className="p-4 text-right font-semibold text-rose-700">
                                                    {money(
                                                        row.outstanding_cents,
                                                    )}
                                                </td>
                                                <td className="p-4">
                                                    {days >= 0
                                                        ? `Por vencer (${days} días)`
                                                        : `${Math.abs(days)} días vencido`}
                                                </td>
                                                <td className="p-4">
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${stateClass(state)}`}
                                                    >
                                                        {state}
                                                    </span>
                                                </td>
                                                <td className="p-4">
                                                    <EllipsisVertical className="size-4 text-slate-400" />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {!payables.data.length && (
                            <div className="grid min-h-60 place-items-center p-8 text-center">
                                <div>
                                    <WalletCards className="mx-auto size-10 text-slate-300" />
                                    <p className="mt-3 font-semibold">
                                        No hay obligaciones con estos filtros
                                    </p>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Registra una compra o modifica los
                                        filtros.
                                    </p>
                                </div>
                            </div>
                        )}
                        <div className="px-4 pb-4">
                            <Pager page={payables} />
                        </div>
                    </section>
                    <PayablePanel
                        company={company}
                        payable={selected}
                        today={today}
                        canWrite={canWrite}
                    />
                </div>
            </div>
        </div>
    );
}

function PayablePanel({
    company,
    payable,
    today,
    canWrite,
}: {
    company: Company;
    payable: Payable | null;
    today: string;
    canWrite: boolean;
}) {
    if (!payable) {
        return (
            <aside className="grid min-h-96 place-items-center rounded-2xl border bg-white p-8 text-center">
                <div>
                    <WalletCards className="mx-auto size-11 text-slate-300" />
                    <p className="mt-3 font-semibold">
                        Selecciona una cuenta por pagar
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        El detalle de la obligación aparecerá aquí.
                    </p>
                </div>
            </aside>
        );
    }

    const state = payableState(payable, today);
    const days = dayDifference(payable.due_on, today);
    const balance = numeric(payable.outstanding_cents);
    const aging =
        days >= 0
            ? ['Por vencer', balance, 'current']
            : Math.abs(days) <= 30
              ? ['1–30 días', balance, '1_30']
              : Math.abs(days) <= 60
                ? ['31–60 días', balance, '31_60']
                : Math.abs(days) <= 90
                  ? ['61–90 días', balance, '61_90']
                  : ['+90 días', balance, 'over_90'];

    return (
        <aside className="overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
            <header className="border-b p-5">
                <div className="flex items-start justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="text-lg font-semibold">
                                {documentLabel(payable)}
                            </h2>
                            <span
                                className={`rounded-full px-2 py-1 text-[9px] font-semibold ${stateClass(state)}`}
                            >
                                {state}
                            </span>
                        </div>
                        <div className="mt-3 flex items-center gap-3">
                            <span className="grid size-9 place-items-center rounded-full bg-[#8f1f30] text-[10px] font-bold text-white">
                                {payable.supplier_name
                                    .slice(0, 2)
                                    .toUpperCase()}
                            </span>
                            <div>
                                <p className="text-sm font-semibold">
                                    {payable.supplier_name}
                                </p>
                                <p className="text-[10px] text-slate-400">
                                    NIT: {payable.supplier_tax_id || 'C/F'}
                                </p>
                            </div>
                        </div>
                    </div>
                    <Link
                        className={secondary}
                        href={routes.suppliers.url(company.public_id, {
                            query: { search: payable.supplier_name },
                        })}
                    >
                        <UserRound className="size-3" /> Proveedor
                    </Link>
                </div>
                <div className="mt-5 grid grid-cols-4 divide-x rounded-xl border py-3 text-center">
                    <div>
                        <p className="text-[9px] text-slate-400">Original</p>
                        <b className="text-xs">{money(payable.total_cents)}</b>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Pagado</p>
                        <b className="text-xs text-emerald-700">
                            {money(payable.paid_cents)}
                        </b>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Saldo</p>
                        <b className="text-xs text-rose-700">
                            {money(balance)}
                        </b>
                    </div>
                    <div>
                        <p className="text-[9px] text-slate-400">Vencimiento</p>
                        <b className="text-xs">{shortDate(payable.due_on)}</b>
                    </div>
                </div>
                <div className="mt-5 flex gap-5 overflow-x-auto border-b text-[10px] font-semibold text-slate-500">
                    <span className="border-b-2 border-[#8f1f30] pb-3 text-[#8f1f30]">
                        Resumen
                    </span>
                    <span>Pagos ({payable.payments.length})</span>
                    <span>Programación</span>
                    <span>Documentos</span>
                    <span>Asiento</span>
                </div>
            </header>
            <div className="space-y-4 p-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <section className="rounded-xl border p-4 text-xs">
                        <h3 className="mb-3 font-semibold">
                            Información general
                        </h3>
                        <dl className="space-y-2 text-slate-600">
                            <div className="flex justify-between">
                                <dt>Documento origen</dt>
                                <dd className="font-semibold">
                                    {documentLabel(payable)}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Fecha documento</dt>
                                <dd>{shortDate(payable.document_date)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Fecha contabilización</dt>
                                <dd>
                                    {payable.approved_at?.slice(0, 10) ||
                                        'Pendiente'}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Condición</dt>
                                <dd>
                                    {creditDays(payable)
                                        ? `Crédito ${creditDays(payable)} días`
                                        : 'Contado'}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Moneda</dt>
                                <dd>GTQ</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Estado contable</dt>
                                <dd className="font-semibold">
                                    {payable.source_entry?.status ||
                                        'Sin asiento'}
                                </dd>
                            </div>
                        </dl>
                    </section>
                    <section className="rounded-xl border p-4 text-xs">
                        <h3 className="mb-3 font-semibold">
                            Antigüedad del saldo
                        </h3>
                        <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                className={`h-full ${days >= 0 ? 'bg-emerald-500' : 'bg-rose-500'}`}
                                style={{ width: balance > 0 ? '100%' : '0%' }}
                            />
                        </div>
                        <div className="mt-4 flex justify-between">
                            <span>{String(aging[0])}</span>
                            <b>{money(Number(aging[1]))}</b>
                        </div>
                        <div className="mt-3 flex justify-between border-t pt-3">
                            <span>Saldo total</span>
                            <b className="text-rose-700">{money(balance)}</b>
                        </div>
                    </section>
                </div>
                <section className="rounded-xl border p-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-xs font-semibold">
                                Próximo vencimiento
                            </p>
                            <p className="mt-2 text-sm font-semibold">
                                {shortDate(payable.due_on)}
                            </p>
                            <p
                                className={`text-[10px] ${days < 0 ? 'text-rose-600' : 'text-emerald-600'}`}
                            >
                                {days >= 0
                                    ? `${days} días restantes`
                                    : `${Math.abs(days)} días vencido`}
                            </p>
                        </div>
                        <b>{money(balance)}</b>
                    </div>
                </section>
                <section className="rounded-xl border">
                    <div className="border-b px-4 py-3">
                        <h3 className="text-xs font-semibold">
                            Pagos aplicados
                        </h3>
                    </div>
                    {payable.payments.map((payment) => (
                        <div
                            key={payment.id}
                            className="grid grid-cols-3 gap-2 border-b px-4 py-3 text-[10px]"
                        >
                            <span>{shortDate(payment.paid_on)}</span>
                            <span>{payment.reference}</span>
                            <b className="text-right text-emerald-700">
                                {money(payment.amount_cents)}
                            </b>
                        </div>
                    ))}
                    {!payable.payments.length && (
                        <p className="p-4 text-center text-xs text-slate-400">
                            Sin pagos aplicados.
                        </p>
                    )}
                </section>
                <section className="rounded-xl border p-4 text-xs">
                    <h3 className="font-semibold">Notas</h3>
                    <p className="mt-2 text-slate-400">Sin notas agregadas.</p>
                </section>
                <div className="grid gap-2 sm:grid-cols-3">
                    {canWrite &&
                    payable.status === 'approved' &&
                    balance > 0 ? (
                        <Link
                            className={primary}
                            href={routes.purchaseShow([
                                company.public_id,
                                payable.public_id,
                            ])}
                        >
                            <Plus className="size-4" /> Registrar pago
                        </Link>
                    ) : (
                        <Link
                            className={secondary}
                            href={routes.purchaseShow([
                                company.public_id,
                                payable.public_id,
                            ])}
                        >
                            Revisar documento
                        </Link>
                    )}
                    <button type="button" disabled className={secondary}>
                        Nota de crédito
                    </button>
                    <button type="button" disabled className={secondary}>
                        Aplicar anticipo
                    </button>
                    <Link
                        className={secondary}
                        href={routes.purchaseShow([
                            company.public_id,
                            payable.public_id,
                        ])}
                    >
                        Ver compra
                    </Link>
                    <Link
                        className={secondary}
                        href={routes.suppliers.url(company.public_id, {
                            query: { search: payable.supplier_name },
                        })}
                    >
                        Ver proveedor
                    </Link>
                    {payable.source_entry ? (
                        <Link
                            className={secondary}
                            href={`${routes.show.url(company.public_id, {
                                query: {
                                    entry: payable.source_entry.public_id,
                                },
                            })}#libro-diario`}
                        >
                            <Landmark className="size-4" /> Ver asiento
                        </Link>
                    ) : (
                        <button type="button" disabled className={secondary}>
                            <Clock3 className="size-4" /> Sin asiento
                        </button>
                    )}
                </div>
            </div>
        </aside>
    );
}
