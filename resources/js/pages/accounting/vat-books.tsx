import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    BookOpen,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Download,
    Eye,
    FileText,
    Lock,
    LockOpen,
    RefreshCcw,
    Scale,
    Search,
    ShoppingCart,
    Tag,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import type { Company } from '@/components/accounting-section';
import { money } from '@/components/accounting-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    invoice,
    purchaseShow,
    vatBooks,
    vatBooksClose,
    vatBooksExport,
    vatBooksReopen,
} from '@/routes/accounting';
import { show as felShow } from '@/routes/accounting/fel';

type Issue = { kind: 'data' | 'accounting'; message: string };

type VatRow = {
    key: string;
    source: 'sales_invoice' | 'purchase_document' | 'fel_document';
    public_id: string;
    date: string;
    document_type: string;
    series: string | null;
    number: string | null;
    authorization: string | null;
    tax_id: string | null;
    name: string;
    status: 'valid' | 'annulled';
    accounting: 'posted' | 'pending' | 'missing' | 'not_applicable';
    taxable_goods_cents: number;
    taxable_services_cents: number;
    exempt_cents: number;
    small_taxpayer_cents: number;
    vat_cents: number;
    total_cents: number;
    issues: Issue[];
};

type Totals = {
    documents: number;
    annulled: number;
    taxable_goods_cents: number;
    taxable_services_cents: number;
    exempt_cents: number;
    small_taxpayer_cents: number;
    vat_cents: number;
    total_cents: number;
};

type Check = {
    key: string;
    label: string;
    ok: boolean;
    detail: string;
    blocking?: boolean;
};

type Book = {
    year: number;
    month: number;
    label: string;
    status: 'open' | 'closed';
    period: {
        closed_at: string | null;
        reopened_at: string | null;
        reopen_reason: string | null;
    };
    sales: VatRow[];
    purchases: VatRow[];
    totals: { sales: Totals; purchases: Totals };
    settlement: {
        sales_vat_cents: number;
        purchase_vat_cents: number;
        previous_credit_cents: number;
        payable_cents: number;
        carry_forward_cents: number;
    };
    ledger: {
        configured: boolean;
        sales_vat_cents: number;
        purchase_vat_cents: number;
        payable_account: string | null;
        recoverable_account: string | null;
        sales_difference_cents: number;
        purchase_difference_cents: number;
    };
    checks: Check[];
    can_close: boolean;
};

type PeriodOption = {
    year: number;
    month: number;
    label: string;
    status: 'open' | 'closed';
};

type BookKey = 'sales' | 'purchases';

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const pageSize = 25;
const input =
    'w-full rounded-lg border border-[#dfe5ef] bg-white px-3 py-2.5 text-xs text-[#26344d]';

const dateLabel = (date: string) => date.split('-').reverse().join('/');

function documentLabel(row: VatRow): string {
    return [row.series, row.number].filter(Boolean).join('-') || 'Sin número';
}

function sourceHref(company: string, row: VatRow): string {
    if (row.source === 'sales_invoice') {
        return invoice.url([company, row.public_id]);
    }

    if (row.source === 'purchase_document') {
        return purchaseShow.url([company, row.public_id]);
    }

    return felShow.url({ company, document: row.public_id });
}

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
}) {
    return (
        <article
            className={`${panel} flex min-h-[104px] min-w-0 items-center gap-4 p-5`}
        >
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div className="min-w-0">
                <p className="text-xs text-slate-600">{label}</p>
                <strong className="block truncate text-2xl font-black text-slate-950">
                    {value}
                </strong>
                <span className="text-[11px] text-slate-500">{detail}</span>
            </div>
        </article>
    );
}

function StatusBadge({ row }: { row: VatRow }) {
    if (row.status === 'annulled') {
        return (
            <span className="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-600">
                Anulado
            </span>
        );
    }

    if (row.issues.length === 0) {
        return (
            <span className="rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700">
                Conciliado
            </span>
        );
    }

    const onlyAccounting = row.issues.every(
        (issue) => issue.kind === 'accounting',
    );

    return (
        <span
            className={`rounded-full px-2.5 py-1 font-semibold ${onlyAccounting ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700'}`}
            title={row.issues.map((issue) => issue.message).join('\n')}
        >
            {onlyAccounting ? 'Pendiente' : 'Observado'}
        </span>
    );
}

function ReopenDialog({
    company,
    book,
    onClose,
}: {
    company: string;
    book: Book;
    onClose: () => void;
}) {
    const form = useForm({ year: book.year, month: book.month, reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(vatBooksReopen.url(company), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reabrir {book.label}</DialogTitle>
                    <DialogDescription>
                        Los documentos del mes podrán modificarse de nuevo. La
                        reapertura queda registrada en auditoría.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-3" onSubmit={submit}>
                    {form.errors.reason && (
                        <p className="text-xs text-red-700">
                            {form.errors.reason}
                        </p>
                    )}
                    <textarea
                        className={input}
                        rows={3}
                        placeholder="Motivo de la reapertura"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        required
                    />
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            className="rounded-lg border px-4 py-2 text-xs font-semibold"
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button
                            className="inline-flex items-center gap-2 rounded-lg bg-[#74000a] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                            disabled={form.processing}
                        >
                            <LockOpen className="size-4" /> Reabrir mes
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function VatBooks({
    company,
    book,
    periods,
    documentTypes,
    canClose,
    canReopen,
    canExport,
    notice,
}: {
    company: Company;
    book: Book;
    periods: PeriodOption[];
    documentTypes: Record<string, string>;
    canClose: boolean;
    canReopen: boolean;
    canExport: boolean;
    notice: string | null;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [tab, setTab] = useState<BookKey>('sales');
    const [status, setStatus] = useState('all');
    const [type, setType] = useState('all');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [reopening, setReopening] = useState(false);
    const [closing, setClosing] = useState(false);
    const rows = book[tab];
    const totals = book.totals[tab];
    const isSales = tab === 'sales';

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return rows.filter((row) => {
            const matchesStatus =
                status === 'all' ||
                (status === 'annulled' && row.status === 'annulled') ||
                (status === 'issues' && row.issues.length > 0) ||
                (status === 'ok' &&
                    row.status === 'valid' &&
                    row.issues.length === 0);

            return (
                matchesStatus &&
                (type === 'all' || row.document_type === type) &&
                (needle === '' ||
                    `${documentLabel(row)} ${row.name} ${row.tax_id ?? ''}`
                        .toLowerCase()
                        .includes(needle))
            );
        });
    }, [query, rows, status, type]);

    const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    const current = Math.min(page, pages);
    const visible = filtered.slice(
        (current - 1) * pageSize,
        current * pageSize,
    );
    const withIssues =
        book.sales.filter((row) => row.issues.length > 0).length +
        book.purchases.filter((row) => row.issues.length > 0).length;
    const typesInBook = Array.from(
        new Set(rows.map((row) => row.document_type)),
    );

    const goTo = (value: string) => {
        const [year, month] = value.split('-').map(Number);
        router.get(
            vatBooks.url(company.public_id, { query: { year, month } }),
            {},
            { preserveScroll: false },
        );
    };
    const switchTab = (key: BookKey) => {
        setTab(key);
        setPage(1);
        setType('all');
    };
    const close = () =>
        router.post(
            vatBooksClose.url(company.public_id),
            { year: book.year, month: book.month },
            { preserveScroll: true, onFinish: () => setClosing(false) },
        );

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] px-4 py-5 text-[#172033] sm:px-6">
            <Head title={`Libros IVA · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            {company.name} <span className="px-2">›</span>
                            <b>Libros IVA</b>
                        </p>
                        <h1 className="flex flex-wrap items-center gap-3 text-[2rem] leading-none font-black tracking-tight text-[#071029]">
                            Libros IVA
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${book.status === 'closed' ? 'bg-slate-800 text-white' : 'bg-emerald-50 text-emerald-700'}`}
                            >
                                {book.status === 'closed' ? (
                                    <Lock className="size-3" />
                                ) : (
                                    <LockOpen className="size-3" />
                                )}
                                {book.status === 'closed'
                                    ? 'Mes cerrado'
                                    : 'Mes abierto'}
                            </span>
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Libro de Ventas y Servicios · Libro de Compras y
                            Servicios — NIT {company.tax_id}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-2">
                        <label className="space-y-1 text-xs font-medium text-slate-600">
                            <span>Período</span>
                            <select
                                className={`${input} min-w-48`}
                                value={`${book.year}-${book.month}`}
                                onChange={(event) => goTo(event.target.value)}
                            >
                                {periods.map((period) => (
                                    <option
                                        key={`${period.year}-${period.month}`}
                                        value={`${period.year}-${period.month}`}
                                    >
                                        {period.label}
                                        {period.status === 'closed'
                                            ? ' · cerrado'
                                            : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {canExport && (
                            <a
                                href={vatBooksExport.url(company.public_id, {
                                    query: {
                                        year: book.year,
                                        month: book.month,
                                        book: tab,
                                    },
                                })}
                                className="inline-flex h-10 items-center gap-2 rounded-lg border border-[#dfe5ef] bg-white px-4 text-xs font-semibold text-[#14213d] shadow-sm hover:bg-slate-50"
                            >
                                <Download className="size-4" />
                                Exportar libro de{' '}
                                {isSales ? 'ventas' : 'compras'}
                            </a>
                        )}
                    </div>
                </header>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs text-emerald-800">
                        <Check className="size-4" /> {notice}
                    </div>
                )}
                {errors.vat_book && (
                    <div className="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs text-rose-800">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />{' '}
                        {errors.vat_book}
                    </div>
                )}

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={ArrowUp}
                        label="IVA débito (ventas)"
                        value={money(book.settlement.sales_vat_cents)}
                        detail={`${book.totals.sales.documents} documento(s) · ${book.totals.sales.annulled} anulado(s)`}
                        tone="bg-rose-50 text-rose-600"
                    />
                    <Metric
                        icon={ArrowDown}
                        label="IVA crédito (compras)"
                        value={money(book.settlement.purchase_vat_cents)}
                        detail={`${book.totals.purchases.documents} documento(s)`}
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Scale}
                        label={
                            book.settlement.payable_cents > 0
                                ? 'IVA por pagar'
                                : 'Remanente de crédito'
                        }
                        value={money(
                            book.settlement.payable_cents > 0
                                ? book.settlement.payable_cents
                                : book.settlement.carry_forward_cents,
                        )}
                        detail={`Remanente anterior ${money(book.settlement.previous_credit_cents)}`}
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={CircleAlert}
                        label="Documentos con observaciones"
                        value={String(withIssues)}
                        detail="Datos incompletos o asiento pendiente"
                        tone={
                            withIssues > 0
                                ? 'bg-amber-50 text-amber-600'
                                : 'bg-slate-50 text-slate-500'
                        }
                    />
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <nav className="flex border-b">
                        {(
                            [
                                ['sales', 'Libro de Ventas', Tag],
                                ['purchases', 'Libro de Compras', ShoppingCart],
                            ] as const
                        ).map(([key, label, Icon]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => switchTab(key)}
                                className={`flex items-center gap-2 border-b-2 px-6 py-4 text-sm font-semibold sm:px-8 ${tab === key ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                            >
                                <Icon className="size-5" />
                                {label}
                                <span className="rounded-full bg-slate-100 px-2 text-xs text-slate-600">
                                    {book[key].length}
                                </span>
                            </button>
                        ))}
                    </nav>
                    <div className="grid gap-3 border-b p-4 md:grid-cols-[1.5fr_1fr_1fr_auto]">
                        <label className="relative text-xs font-medium text-slate-600">
                            <span className="mb-1 block">Buscar</span>
                            <Search className="absolute bottom-3 left-3 size-4 text-slate-400" />
                            <input
                                value={query}
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    setPage(1);
                                }}
                                placeholder="Serie, número, nombre o NIT"
                                className={`${input} pl-9`}
                            />
                        </label>
                        <label className="text-xs font-medium text-slate-600">
                            <span className="mb-1 block">
                                Tipo de documento
                            </span>
                            <select
                                className={input}
                                value={type}
                                onChange={(event) => {
                                    setType(event.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="all">Todos</option>
                                {typesInBook.map((value) => (
                                    <option key={value} value={value}>
                                        {documentTypes[value] ?? value}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-xs font-medium text-slate-600">
                            <span className="mb-1 block">Estado</span>
                            <select
                                className={input}
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="all">Todos</option>
                                <option value="ok">Conciliados</option>
                                <option value="issues">
                                    Con observaciones
                                </option>
                                <option value="annulled">Anulados</option>
                            </select>
                        </label>
                        <div className="flex items-end">
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    setStatus('all');
                                    setType('all');
                                    setPage(1);
                                }}
                                className="flex h-10 items-center justify-center gap-2 rounded-lg border border-[#dfe5ef] bg-white px-4 text-xs font-semibold text-[#26344d]"
                            >
                                <RefreshCcw className="size-4" />
                                Limpiar
                            </button>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1180px] text-left text-xs">
                            <thead className="bg-slate-50 text-slate-600">
                                <tr>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th>Documento</th>
                                    <th>NIT</th>
                                    <th>{isSales ? 'Cliente' : 'Proveedor'}</th>
                                    <th className="text-right">Bienes</th>
                                    <th className="text-right">Servicios</th>
                                    <th className="text-right">Exento</th>
                                    <th className="text-right">
                                        Peq. contrib.
                                    </th>
                                    <th className="text-right">IVA</th>
                                    <th className="text-right">Total</th>
                                    <th className="pl-4">Estado</th>
                                    <th className="pr-4 text-center">Ver</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visible.map((row) => (
                                    <tr
                                        key={row.key}
                                        className={`hover:bg-rose-50/30 ${row.status === 'annulled' ? 'text-slate-400' : ''}`}
                                    >
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {dateLabel(row.date)}
                                        </td>
                                        <td>
                                            <span className="block font-semibold">
                                                {row.document_type}{' '}
                                                {documentLabel(row)}
                                            </span>
                                            {row.authorization && (
                                                <span className="block max-w-56 truncate text-[10px] text-slate-400">
                                                    {row.authorization}
                                                </span>
                                            )}
                                        </td>
                                        <td>{row.tax_id ?? '—'}</td>
                                        <td className="max-w-64 truncate">
                                            {row.name}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(row.taxable_goods_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(row.taxable_services_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(row.exempt_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(row.small_taxpayer_cents)}
                                        </td>
                                        <td className="text-right font-semibold tabular-nums">
                                            {money(row.vat_cents)}
                                        </td>
                                        <td className="text-right font-semibold tabular-nums">
                                            {money(row.total_cents)}
                                        </td>
                                        <td className="pl-4">
                                            <StatusBadge row={row} />
                                            {row.issues.length > 0 && (
                                                <ul className="mt-1 space-y-0.5 text-[10px] text-slate-500">
                                                    {row.issues.map((issue) => (
                                                        <li key={issue.message}>
                                                            {issue.message}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="pr-4 text-center">
                                            <Link
                                                href={sourceHref(
                                                    company.public_id,
                                                    row,
                                                )}
                                                className="inline-grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-[#74000a]"
                                                aria-label={`Ver documento ${documentLabel(row)}`}
                                            >
                                                <Eye className="size-4" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            {filtered.length > 0 && (
                                <tfoot className="bg-slate-50 font-semibold">
                                    <tr>
                                        <td className="px-4 py-3" colSpan={4}>
                                            Totales del libro (
                                            {totals.documents} documentos)
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(totals.taxable_goods_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(
                                                totals.taxable_services_cents,
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(totals.exempt_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(totals.small_taxpayer_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(totals.vat_cents)}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(totals.total_cents)}
                                        </td>
                                        <td colSpan={2} />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                        {filtered.length === 0 && (
                            <div className="flex flex-col items-center gap-2 p-10 text-center text-sm text-slate-400">
                                <FileText className="size-8" />
                                {rows.length === 0
                                    ? `No hay ${isSales ? 'ventas' : 'compras'} registradas en ${book.label}.`
                                    : 'No hay documentos para estos filtros.'}
                            </div>
                        )}
                    </div>
                    {filtered.length > pageSize && (
                        <footer className="flex flex-wrap items-center justify-end gap-4 border-t p-3 text-xs text-slate-500">
                            <span>
                                Mostrando {(current - 1) * pageSize + 1} -{' '}
                                {Math.min(current * pageSize, filtered.length)}{' '}
                                de {filtered.length}
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setPage(Math.max(1, current - 1))
                                    }
                                    className="grid size-8 place-items-center rounded border disabled:opacity-40"
                                    disabled={current === 1}
                                    aria-label="Página anterior"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <span className="grid h-8 place-items-center px-2">
                                    {current} / {pages}
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setPage(Math.min(pages, current + 1))
                                    }
                                    className="grid size-8 place-items-center rounded border disabled:opacity-40"
                                    disabled={current === pages}
                                    aria-label="Página siguiente"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        </footer>
                    )}
                </section>

                <section className="grid gap-4 xl:grid-cols-3">
                    <article className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 font-bold">
                            <BookOpen className="size-5" />
                            Liquidación de {book.label}
                        </h2>
                        <dl className="space-y-3 text-xs">
                            <div className="flex justify-between">
                                <dt>IVA débito (ventas)</dt>
                                <dd>
                                    {money(book.settlement.sales_vat_cents)}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>(-) IVA crédito (compras)</dt>
                                <dd>
                                    {money(book.settlement.purchase_vat_cents)}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>(-) Remanente del mes anterior</dt>
                                <dd>
                                    {money(
                                        book.settlement.previous_credit_cents,
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between rounded-lg bg-slate-100 p-3 text-sm font-bold">
                                <dt>IVA por pagar</dt>
                                <dd>{money(book.settlement.payable_cents)}</dd>
                            </div>
                            <div className="flex justify-between px-3 text-sm font-semibold text-emerald-700">
                                <dt>Remanente para el mes siguiente</dt>
                                <dd>
                                    {money(book.settlement.carry_forward_cents)}
                                </dd>
                            </div>
                        </dl>
                    </article>

                    <article className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 font-bold">
                            <Scale className="size-5" />
                            Conciliación con el Libro Mayor
                        </h2>
                        {book.ledger.configured ? (
                            <table className="w-full text-xs">
                                <thead className="text-slate-500">
                                    <tr>
                                        <th className="pb-2 text-left" />
                                        <th className="pb-2 text-right">
                                            Libro IVA
                                        </th>
                                        <th className="pb-2 text-right">
                                            Mayor
                                        </th>
                                        <th className="pb-2 text-right">
                                            Diferencia
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-t">
                                        <td className="py-2">
                                            Débito
                                            <span className="block text-[10px] text-slate-400">
                                                {book.ledger.payable_account}
                                            </span>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(
                                                book.settlement.sales_vat_cents,
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(book.ledger.sales_vat_cents)}
                                        </td>
                                        <td
                                            className={`text-right font-semibold tabular-nums ${book.ledger.sales_difference_cents === 0 ? 'text-emerald-700' : 'text-rose-700'}`}
                                        >
                                            {money(
                                                book.ledger
                                                    .sales_difference_cents,
                                            )}
                                        </td>
                                    </tr>
                                    <tr className="border-t">
                                        <td className="py-2">
                                            Crédito
                                            <span className="block text-[10px] text-slate-400">
                                                {
                                                    book.ledger
                                                        .recoverable_account
                                                }
                                            </span>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(
                                                book.settlement
                                                    .purchase_vat_cents,
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {money(
                                                book.ledger.purchase_vat_cents,
                                            )}
                                        </td>
                                        <td
                                            className={`text-right font-semibold tabular-nums ${book.ledger.purchase_difference_cents === 0 ? 'text-emerald-700' : 'text-rose-700'}`}
                                        >
                                            {money(
                                                book.ledger
                                                    .purchase_difference_cents,
                                            )}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        ) : (
                            <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
                                Asigna las cuentas de IVA crédito y débito al
                                impuesto IVA en Parámetros fiscales para
                                conciliar con el Mayor.
                            </p>
                        )}
                        <p className="mt-4 text-[11px] text-slate-500">
                            Solo cuentan los asientos contabilizados con fecha
                            dentro del mes.
                        </p>
                    </article>

                    <article className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 font-bold">
                            <CheckCircle2 className="size-5" />
                            Cierre del mes
                        </h2>
                        <ul className="space-y-3 text-xs">
                            {book.checks.map((check) => (
                                <li key={check.key} className="flex gap-3">
                                    {check.ok ? (
                                        <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />
                                    ) : check.blocking === false ? (
                                        <CircleAlert className="size-4 shrink-0 text-amber-500" />
                                    ) : (
                                        <X className="size-4 shrink-0 text-rose-600" />
                                    )}
                                    <span>
                                        <strong className="block">
                                            {check.label}
                                        </strong>
                                        <small className="text-slate-500">
                                            {check.detail}
                                        </small>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-5 flex flex-wrap gap-2">
                            {book.status === 'open' && canClose && (
                                <button
                                    type="button"
                                    disabled={!book.can_close || closing}
                                    onClick={() => {
                                        setClosing(true);
                                        close();
                                    }}
                                    className="inline-flex items-center gap-2 rounded-lg bg-[#74000a] px-4 py-2.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <Lock className="size-4" /> Cerrar{' '}
                                    {book.label}
                                </button>
                            )}
                            {book.status === 'closed' && canReopen && (
                                <button
                                    type="button"
                                    onClick={() => setReopening(true)}
                                    className="inline-flex items-center gap-2 rounded-lg border border-[#74000a] px-4 py-2.5 text-xs font-semibold text-[#74000a]"
                                >
                                    <LockOpen className="size-4" /> Reabrir mes
                                </button>
                            )}
                        </div>
                        {book.status === 'closed' && (
                            <p className="mt-3 text-[11px] text-slate-500">
                                Cerrado el {book.period.closed_at?.slice(0, 10)}
                                . Los documentos de este mes están bloqueados.
                            </p>
                        )}
                    </article>
                </section>
            </div>
            {reopening && (
                <ReopenDialog
                    company={company.public_id}
                    book={book}
                    onClose={() => setReopening(false)}
                />
            )}
        </main>
    );
}
