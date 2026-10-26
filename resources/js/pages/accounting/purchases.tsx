import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    CheckCircle2,
    CircleDollarSign,
    Clock3,
    Download,
    FileSpreadsheet,
    FileText,
    FilePlus2,
    Landmark,
    MoreHorizontal,
    Paperclip,
    Plus,
    ReceiptText,
    Search,
    Settings2,
    ShoppingCart,
    Upload,
    WalletCards,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import {
    Errors,
    field,
    money,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import {
    FiscalDocumentFields,
    TaxTreatmentSelect,
} from '@/components/fiscal/fiscal-document-fields';
import { InvoiceReader } from '@/components/purchases/invoice-reader';
import type { ReadInvoice } from '@/components/purchases/invoice-reader';
import { statusLabel } from '@/lib/status-labels';
import * as routes from '@/routes/accounting';

type Supplier = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    payment_terms_days: number | null;
    document_count: number;
};
type Account = { id: number; code: string; name: string; type: string };
type DocumentLine = {
    id: number;
    description: string;
    quantity: number;
    unit_cents: number;
    account_code: string;
    account_name: string;
};
type DocumentPayment = {
    id: number;
    public_id: string;
    amount_cents: number;
    paid_on: string;
    reference: string;
};
type DocumentFile = {
    public_id: string;
    original_name: string;
    mime_type: string;
    size_bytes: number;
};
type SourceEntry = {
    entry_public_id: string;
    entry_status: string;
    entry_number: number | null;
};
type Document = {
    public_id: string;
    number: number | null;
    supplier_name: string;
    external_reference: string | null;
    document_type: string;
    status: string;
    document_date: string;
    due_on: string;
    total_cents: number;
    paid_cents: number;
    supplier_tax_id: string | null;
    supplier_email: string | null;
    supplier_phone: string | null;
    lines: DocumentLine[];
    payments: DocumentPayment[];
    files: DocumentFile[];
    source_entries: SourceEntry[];
};
type Mapping = {
    cash_account_id: number;
    receivable_account_id: number;
    revenue_account_id: number;
    payable_account_id: number;
    default_expense_account_id: number;
} | null;

function Modal({
    title,
    close,
    children,
}: {
    title: string;
    close: () => void;
    children: ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-[#08111f]/65 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
        >
            <div className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-3xl border border-white/50 bg-white shadow-2xl">
                <div className="sticky top-0 z-10 flex items-center justify-between border-b bg-white/95 px-6 py-5 backdrop-blur">
                    <h2 className="text-xl font-semibold text-[#142135]">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-xl p-2 hover:bg-slate-100"
                        aria-label="Cerrar"
                    >
                        <X className="size-5" />
                    </button>
                </div>
                <div className="p-6">{children}</div>
            </div>
        </div>
    );
}

export default function Purchases({
    company,
    documents,
    suppliers,
    accounts,
    mapping,
    filters,
    portfolio,
    canWrite,
    canReadInvoices,
    isOwner,
    today,
    notice,
}: {
    company: Company;
    documents: Page<Document>;
    suppliers: Supplier[];
    accounts: Account[];
    mapping: Mapping;
    filters: {
        search: string;
        status: string;
        supplier: number | null;
        document_type: string;
        from: string;
        to: string;
    };
    portfolio: {
        accrued: number;
        paid: number;
        outstanding: number;
        overdue: number;
        documents: number;
        paid_documents: number;
        overdue_documents: number;
        pending_accounting: number;
    };
    canWrite: boolean;
    canReadInvoices: boolean;
    isOwner: boolean;
    today: string;
    notice?: string;
}) {
    const [panel, setPanel] = useState<
        'supplier' | 'document' | 'mapping' | null
    >(null);
    // After registering the supplier of a read invoice, the purchase form comes back with that supplier selected.
    const [supplierForInvoice, setSupplierForInvoice] = useState(false);
    const [selectedId, setSelectedId] = useState<string>(
        documents.data[0]?.public_id ?? '',
    );
    const supplier = useForm({
        name: '',
        tax_id: '',
        email: '',
        phone: '',
        payment_terms_days: '',
    });
    const search = useForm({
        search: filters.search,
        status: filters.status,
        supplier: filters.supplier ? String(filters.supplier) : '',
        document_type: filters.document_type,
        from: filters.from,
        to: filters.to,
    });
    const draft = useForm({
        request_id: crypto.randomUUID(),
        supplier_id: '',
        document_type: 'purchase',
        external_reference: '',
        document_date: today,
        due_on: '',
        currency: 'GTQ',
        fiscal_document_type: 'FACT',
        fiscal_series: '',
        fiscal_number: '',
        fiscal_authorization: '',
        lines: [
            {
                account_id: mapping?.default_expense_account_id
                    ? String(mapping.default_expense_account_id)
                    : '',
                description: '',
                quantity: '1',
                unit_price: '',
                tax_treatment: 'goods',
            },
        ],
    });
    const mapForm = useForm({
        cash_account_id: String(mapping?.cash_account_id ?? ''),
        receivable_account_id: String(mapping?.receivable_account_id ?? ''),
        revenue_account_id: String(mapping?.revenue_account_id ?? ''),
        payable_account_id: String(mapping?.payable_account_id ?? ''),
        default_expense_account_id: String(
            mapping?.default_expense_account_id ?? '',
        ),
    });
    const nitKey = (value: string | null) =>
        (value ?? '')
            .toUpperCase()
            .replace(/[^0-9K]/g, '')
            .replace(/^0+/, '');
    const applyInvoice = (invoice: ReadInvoice) => {
        const expense = mapping?.default_expense_account_id
            ? String(mapping.default_expense_account_id)
            : '';
        draft.setData((current) => ({
            ...current,
            supplier_id: invoice.supplier.id
                ? String(invoice.supplier.id)
                : current.supplier_id,
            document_date: invoice.document_date ?? current.document_date,
            currency: invoice.currency,
            fiscal_document_type:
                invoice.fiscal_document_type ?? current.fiscal_document_type,
            fiscal_series: invoice.fiscal_series ?? '',
            fiscal_number: invoice.fiscal_number ?? '',
            fiscal_authorization: invoice.fiscal_authorization ?? '',
            lines:
                invoice.lines.length > 0
                    ? invoice.lines.map((line, index) => ({
                          account_id:
                              current.lines[index]?.account_id || expense,
                          description: line.description,
                          quantity: line.quantity,
                          unit_price: line.unit_price,
                          tax_treatment: line.tax_treatment,
                      }))
                    : current.lines,
        }));
    };
    const accountLabel = (id: number) => {
        const account = accounts.find((item) => item.id === id);

        return account ? `${account.code} · ${account.name}` : 'Sin configurar';
    };
    const status = (row: Document) => {
        if (row.status === 'void') {
            return ['Anulado', 'bg-slate-100 text-slate-600'];
        }

        if (row.status === 'draft') {
            return ['Borrador', 'bg-amber-50 text-amber-700'];
        }

        if (Number(row.paid_cents) >= Number(row.total_cents)) {
            return ['Pagado', 'bg-emerald-50 text-emerald-700'];
        }

        if (Number(row.paid_cents) > 0) {
            return ['Pago parcial', 'bg-blue-50 text-blue-700'];
        }

        if (row.due_on < today) {
            return ['Vencido', 'bg-red-50 text-red-700'];
        }

        return ['Pendiente', 'bg-rose-50 text-rose-700'];
    };
    const selected = selectedId
        ? (documents.data.find((item) => item.public_id === selectedId) ??
          documents.data[0] ??
          null)
        : null;
    const outstanding = (row: Document) =>
        row.status === 'void'
            ? 0
            : Math.max(0, Number(row.total_cents) - Number(row.paid_cents));
    const documentNumber = (row: Document) =>
        row.external_reference ||
        (row.number ? `CP-${String(row.number).padStart(6, '0')}` : 'BORRADOR');
    const metrics: Array<{
        label: string;
        value: string;
        note: string;
        icon: LucideIcon;
        tone: string;
    }> = [
        {
            label: 'Compras del período',
            value: money(portfolio.accrued),
            note: 'Documentos aprobados',
            icon: ShoppingCart,
            tone: 'bg-emerald-50 text-emerald-700',
        },
        {
            label: 'Documentos',
            value: String(portfolio.documents),
            note: 'Compras contabilizadas',
            icon: FileText,
            tone: 'bg-blue-50 text-blue-700',
        },
        {
            label: 'Por pagar',
            value: money(portfolio.outstanding),
            note: 'Saldo pendiente',
            icon: WalletCards,
            tone: 'bg-orange-50 text-orange-700',
        },
        {
            label: 'Pagadas',
            value: money(portfolio.paid),
            note: `${portfolio.paid_documents} documentos`,
            icon: CheckCircle2,
            tone: 'bg-violet-50 text-violet-700',
        },
        {
            label: 'Vencidas',
            value: money(portfolio.overdue),
            note: `${portfolio.overdue_documents} documentos`,
            icon: AlertTriangle,
            tone: 'bg-red-50 text-red-700',
        },
        {
            label: 'Pendientes de contabilizar',
            value: String(portfolio.pending_accounting),
            note: 'Requieren revisión',
            icon: ReceiptText,
            tone: 'bg-amber-50 text-amber-700',
        },
    ];

    return (
        <AccountingSection company={company} title="Compras" compact>
            {notice && (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
                    {notice}
                </div>
            )}
            <header className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-4">
                    <span className="grid size-12 place-items-center rounded-2xl bg-[#7d0808] text-white shadow-lg shadow-red-950/15">
                        <ShoppingCart className="size-6" />
                    </span>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-[#121d35]">
                            Compras
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestión y contabilización de facturas y gastos de
                            proveedores
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canWrite && (
                        <button
                            type="button"
                            className={primary}
                            onClick={() => setPanel('document')}
                            disabled={!suppliers.length}
                        >
                            <Plus className="size-4" /> Nueva compra
                        </button>
                    )}
                    {canWrite && (
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => setPanel('supplier')}
                        >
                            <Building2 className="size-4" /> Proveedor
                        </button>
                    )}
                    <button
                        type="button"
                        className={secondary}
                        disabled
                        title="La importación masiva se habilitará con su plantilla validada"
                    >
                        <Upload className="size-4" /> Importar
                    </button>
                    <a
                        className={secondary}
                        href={routes.purchasesExport.url(company.public_id, {
                            query: { format: 'xlsx' },
                        })}
                    >
                        <FileSpreadsheet className="size-4 text-emerald-700" />{' '}
                        Excel
                    </a>
                    <a
                        className={secondary}
                        href={routes.purchasesExport.url(company.public_id, {
                            query: { format: 'pdf' },
                        })}
                    >
                        <Download className="size-4 text-red-700" /> PDF
                    </a>
                    {isOwner && (
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => setPanel('mapping')}
                        >
                            <Settings2 className="size-4" /> Más acciones
                        </button>
                    )}
                </div>
            </header>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                {metrics.map(({ label, value, note, icon: Icon, tone }) => (
                    <div
                        key={label}
                        className="rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,.05)]"
                    >
                        <div className="flex items-start gap-3">
                            <span className={`rounded-full p-3 ${tone}`}>
                                <Icon className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-medium text-slate-600">
                                    {label}
                                </p>
                                <p className="mt-1 truncate text-xl font-bold text-[#121d35]">
                                    {value}
                                </p>
                                <p className="mt-2 text-[11px] text-slate-500">
                                    {note}
                                </p>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(390px,.8fr)]">
                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,.05)]">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                routes.purchases.url(company.public_id),
                                search.data,
                                { preserveState: true },
                            );
                        }}
                        className="grid gap-3 border-b border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-6"
                    >
                        <label className="relative">
                            <span className="sr-only">Buscar</span>
                            <Search className="absolute top-4 left-3 size-4 text-slate-400" />
                            <input
                                className={`${field} pl-10`}
                                value={search.data.search}
                                onChange={(event) =>
                                    search.setData('search', event.target.value)
                                }
                                placeholder="Documento, proveedor, NIT..."
                            />
                        </label>
                        <label>
                            <span className="mb-1 block text-[11px] font-medium text-slate-500">
                                Desde
                            </span>
                            <input
                                type="date"
                                className={field}
                                value={search.data.from}
                                onChange={(event) =>
                                    search.setData('from', event.target.value)
                                }
                            />
                        </label>
                        <label>
                            <span className="mb-1 block text-[11px] font-medium text-slate-500">
                                Hasta
                            </span>
                            <input
                                type="date"
                                className={field}
                                value={search.data.to}
                                onChange={(event) =>
                                    search.setData('to', event.target.value)
                                }
                            />
                        </label>
                        <select
                            className={field}
                            value={search.data.supplier}
                            onChange={(event) =>
                                search.setData('supplier', event.target.value)
                            }
                        >
                            <option value="">Proveedor: todos</option>
                            {suppliers.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                        <select
                            className={field}
                            value={search.data.document_type}
                            onChange={(event) =>
                                search.setData(
                                    'document_type',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="all">Tipo: todos</option>
                            <option value="purchase">Compra</option>
                            <option value="expense">Gasto</option>
                        </select>
                        <select
                            className={field}
                            value={search.data.status}
                            onChange={(event) =>
                                search.setData('status', event.target.value)
                            }
                        >
                            <option value="all">Pago: todos</option>
                            <option value="draft">Borradores</option>
                            <option value="pending">Pendientes</option>
                            <option value="partial">Pagos parciales</option>
                            <option value="paid">Pagados</option>
                            <option value="overdue">Vencidos</option>
                            <option value="void">Anulados</option>
                        </select>
                        <button className={`${secondary} xl:col-start-6`}>
                            Aplicar filtros
                        </button>
                    </form>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[850px] text-left text-sm">
                            <thead className="bg-[#fbf7f7] text-slate-600">
                                <tr>
                                    <th className="p-4">Documento</th>
                                    <th>Proveedor</th>
                                    <th>Fecha</th>
                                    <th className="text-right">Total</th>
                                    <th className="text-right">Pagado</th>
                                    <th className="text-right">Pendiente</th>
                                    <th>Estado</th>
                                    <th className="p-4 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {documents.data.map((row) => {
                                    const [label, tone] = status(row);

                                    return (
                                        <tr
                                            key={row.public_id}
                                            onClick={() =>
                                                setSelectedId(row.public_id)
                                            }
                                            className={`cursor-pointer transition ${selected?.public_id === row.public_id ? 'bg-[#fbefef]' : 'hover:bg-slate-50'}`}
                                        >
                                            <td className="p-4 font-semibold text-[#7d0808]">
                                                {documentNumber(row)}
                                            </td>
                                            <td>
                                                <p className="font-medium">
                                                    {row.supplier_name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {row.external_reference ||
                                                        'Sin referencia externa'}
                                                </p>
                                            </td>
                                            <td>{row.document_date}</td>
                                            <td className="text-right">
                                                {money(row.total_cents)}
                                            </td>
                                            <td className="text-right text-emerald-700">
                                                {money(row.paid_cents)}
                                            </td>
                                            <td className="text-right font-medium">
                                                {money(outstanding(row))}
                                            </td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${tone}`}
                                                >
                                                    {label}
                                                </span>
                                            </td>
                                            <td className="p-4 text-right">
                                                <button
                                                    type="button"
                                                    aria-label="Seleccionar documento"
                                                    className="rounded-lg p-2 hover:bg-white"
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        {!documents.data.length && (
                            <div className="p-10 text-center text-slate-500">
                                No hay documentos con estos filtros.
                            </div>
                        )}
                    </div>
                    <Pager page={documents} />
                </section>

                <aside className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,.05)]">
                    {selected ? (
                        <div>
                            <div className="border-b border-slate-200 p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="text-xl font-bold text-[#121d35]">
                                                {documentNumber(selected)}
                                            </h2>
                                            <span
                                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${status(selected)[1]}`}
                                            >
                                                {status(selected)[0]}
                                            </span>
                                        </div>
                                        <p className="mt-1 font-semibold text-[#7d0808]">
                                            {selected.supplier_name}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            NIT:{' '}
                                            {selected.supplier_tax_id ||
                                                'No registrado'}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"
                                        aria-label="Cerrar selección"
                                        onClick={() => setSelectedId('')}
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                                <div className="mt-5 grid grid-cols-3 divide-x rounded-xl border border-slate-200 py-3 text-center">
                                    <div>
                                        <p className="text-[11px] text-slate-500">
                                            Total
                                        </p>
                                        <strong>
                                            {money(selected.total_cents)}
                                        </strong>
                                    </div>
                                    <div>
                                        <p className="text-[11px] text-slate-500">
                                            Pagado
                                        </p>
                                        <strong className="text-emerald-700">
                                            {money(selected.paid_cents)}
                                        </strong>
                                    </div>
                                    <div>
                                        <p className="text-[11px] text-slate-500">
                                            Saldo
                                        </p>
                                        <strong className="text-red-700">
                                            {money(outstanding(selected))}
                                        </strong>
                                    </div>
                                </div>
                                <div className="mt-4 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                                    <div>
                                        <CalendarDays className="mb-1 size-4 text-[#7d0808]" />
                                        <span className="text-slate-500">
                                            Emisión
                                        </span>
                                        <p className="font-semibold">
                                            {selected.document_date}
                                        </p>
                                    </div>
                                    <div>
                                        <Clock3 className="mb-1 size-4 text-[#7d0808]" />
                                        <span className="text-slate-500">
                                            Vencimiento
                                        </span>
                                        <p className="font-semibold">
                                            {selected.due_on}
                                        </p>
                                    </div>
                                    <div>
                                        <ReceiptText className="mb-1 size-4 text-[#7d0808]" />
                                        <span className="text-slate-500">
                                            Condición
                                        </span>
                                        <p className="font-semibold">Crédito</p>
                                    </div>
                                    <div>
                                        <CircleDollarSign className="mb-1 size-4 text-[#7d0808]" />
                                        <span className="text-slate-500">
                                            Moneda
                                        </span>
                                        <p className="font-semibold">GTQ</p>
                                    </div>
                                </div>
                            </div>
                            <div className="flex gap-5 overflow-x-auto border-b px-5 text-xs font-semibold text-slate-500">
                                <span className="border-b-2 border-[#7d0808] py-3 text-[#7d0808]">
                                    Resumen
                                </span>
                                <span className="py-3">Detalle</span>
                                <span className="py-3">
                                    Pagos ({selected.payments.length})
                                </span>
                                <span className="py-3">
                                    Documentos ({selected.files.length})
                                </span>
                                <span className="py-3">Asiento</span>
                            </div>
                            <div className="space-y-4 p-5">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="rounded-xl border border-slate-200 p-4 text-xs">
                                        <h3 className="mb-3 font-bold text-[#121d35]">
                                            Información general
                                        </h3>
                                        <dl className="space-y-2 text-slate-600">
                                            <div className="flex justify-between gap-3">
                                                <dt>Tipo</dt>
                                                <dd className="font-semibold">
                                                    {selected.document_type ===
                                                    'purchase'
                                                        ? 'Factura de compra'
                                                        : 'Gasto'}
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <dt>Referencia</dt>
                                                <dd className="font-semibold">
                                                    {selected.external_reference ||
                                                        '—'}
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <dt>Correo</dt>
                                                <dd className="truncate font-semibold">
                                                    {selected.supplier_email ||
                                                        '—'}
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <dt>Teléfono</dt>
                                                <dd className="font-semibold">
                                                    {selected.supplier_phone ||
                                                        '—'}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                    <div className="rounded-xl border border-slate-200 p-4 text-xs">
                                        <h3 className="mb-3 font-bold text-[#121d35]">
                                            Distribución contable
                                        </h3>
                                        <div className="space-y-2">
                                            {selected.lines
                                                .slice(0, 4)
                                                .map((line) => (
                                                    <div
                                                        key={line.id}
                                                        className="flex justify-between gap-3 border-b border-slate-100 pb-2"
                                                    >
                                                        <span>
                                                            <b>
                                                                {
                                                                    line.account_code
                                                                }
                                                            </b>
                                                            <br />
                                                            {line.account_name}
                                                        </span>
                                                        <b>
                                                            {money(
                                                                line.quantity *
                                                                    line.unit_cents,
                                                            )}
                                                        </b>
                                                    </div>
                                                ))}
                                            {!selected.lines.length && (
                                                <p className="text-slate-500">
                                                    Sin líneas registradas.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="rounded-xl border border-slate-200 p-4 text-xs">
                                    <h3 className="mb-3 font-bold text-[#121d35]">
                                        Pagos aplicados
                                    </h3>
                                    {selected.payments.length ? (
                                        selected.payments
                                            .slice(0, 3)
                                            .map((payment) => (
                                                <div
                                                    key={payment.id}
                                                    className="grid grid-cols-3 gap-2 border-b border-slate-100 py-2"
                                                >
                                                    <span>
                                                        {payment.paid_on}
                                                    </span>
                                                    <span>
                                                        {payment.reference}
                                                    </span>
                                                    <b className="text-right text-emerald-700">
                                                        {money(
                                                            payment.amount_cents,
                                                        )}
                                                    </b>
                                                </div>
                                            ))
                                    ) : (
                                        <p className="text-slate-500">
                                            Todavía no hay pagos aplicados.
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <h3 className="mb-2 text-xs font-bold text-[#121d35]">
                                        Documentos adjuntos
                                    </h3>
                                    <div className="flex flex-wrap gap-2">
                                        {selected.files.map((file) => (
                                            <a
                                                key={file.public_id}
                                                href={routes.purchaseFileDownload.url(
                                                    {
                                                        company:
                                                            company.public_id,
                                                        document:
                                                            selected.public_id,
                                                        file: file.public_id,
                                                    },
                                                )}
                                                className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold hover:border-[#7d0808]"
                                            >
                                                <Paperclip className="size-4 text-[#7d0808]" />
                                                {file.original_name}
                                            </a>
                                        ))}
                                        {!selected.files.length && (
                                            <span className="text-xs text-slate-500">
                                                Sin archivos adjuntos.
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <Link
                                        href={routes.purchaseShow([
                                            company.public_id,
                                            selected.public_id,
                                        ])}
                                        className={primary}
                                    >
                                        <WalletCards className="size-4" />{' '}
                                        Gestionar compra
                                    </Link>
                                    <a
                                        href={routes.purchasePdf.url([
                                            company.public_id,
                                            selected.public_id,
                                        ])}
                                        className={secondary}
                                    >
                                        <Download className="size-4" />{' '}
                                        Comprobante PDF
                                    </a>
                                </div>
                                {selected.source_entries[0] && (
                                    <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                                        Asiento contable:{' '}
                                        <b>
                                            {selected.source_entries[0]
                                                .entry_number
                                                ? `#${selected.source_entries[0].entry_number}`
                                                : 'borrador'}
                                        </b>{' '}
                                        ·{' '}
                                        {statusLabel(
                                            selected.source_entries[0]
                                                .entry_status,
                                        )}
                                    </p>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="grid min-h-[460px] place-items-center p-8 text-center text-slate-500">
                            <div>
                                <FilePlus2 className="mx-auto mb-3 size-10 text-[#b77a7a]" />
                                <p className="font-semibold text-[#121d35]">
                                    Selecciona un documento
                                </p>
                                <p className="mt-1 text-sm">
                                    Aquí aparecerá el resumen de la compra.
                                </p>
                            </div>
                        </div>
                    )}
                </aside>
            </div>

            {panel === 'supplier' && (
                <Modal title="Registrar proveedor" close={() => setPanel(null)}>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            supplier.post(
                                routes.purchaseSupplier.url(company.public_id),
                                {
                                    onSuccess: (page) => {
                                        if (!supplierForInvoice) {
                                            setPanel(null);

                                            return;
                                        }

                                        const created = (
                                            page.props.suppliers as Supplier[]
                                        ).find(
                                            (item) =>
                                                nitKey(item.tax_id) ===
                                                nitKey(supplier.data.tax_id),
                                        );

                                        if (created) {
                                            draft.setData(
                                                'supplier_id',
                                                String(created.id),
                                            );
                                        }

                                        setSupplierForInvoice(false);
                                        setPanel('document');
                                    },
                                },
                            );
                        }}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        <label>
                            Nombre o razón social
                            <input
                                className={field}
                                value={supplier.data.name}
                                onChange={(event) =>
                                    supplier.setData('name', event.target.value)
                                }
                            />
                        </label>
                        <label>
                            NIT / identificador fiscal
                            <input
                                className={field}
                                value={supplier.data.tax_id}
                                onChange={(event) =>
                                    supplier.setData(
                                        'tax_id',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <label>
                            Correo
                            <input
                                type="email"
                                className={field}
                                value={supplier.data.email}
                                onChange={(event) =>
                                    supplier.setData(
                                        'email',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <label>
                            Teléfono
                            <input
                                className={field}
                                value={supplier.data.phone}
                                onChange={(event) =>
                                    supplier.setData(
                                        'phone',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <label>
                            Plazo de pago (días, opcional)
                            <input
                                type="number"
                                min={0}
                                max={365}
                                className={field}
                                value={supplier.data.payment_terms_days}
                                onChange={(event) =>
                                    supplier.setData(
                                        'payment_terms_days',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <div className="sm:col-span-2">
                            <Errors errors={supplier.errors} />
                            <button
                                className={primary}
                                disabled={supplier.processing}
                            >
                                Guardar proveedor
                            </button>
                        </div>
                    </form>
                </Modal>
            )}

            {panel === 'document' && (
                <Modal
                    title="Nueva compra o gasto"
                    close={() => setPanel(null)}
                >
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            draft.post(
                                routes.purchaseStore.url(company.public_id),
                            );
                        }}
                        className="space-y-5"
                    >
                        {canReadInvoices && (
                            <InvoiceReader
                                url={routes.purchaseReadInvoice.url(
                                    company.public_id,
                                )}
                                onRead={applyInvoice}
                                onRegisterSupplier={(found) => {
                                    supplier.setData((current) => ({
                                        ...current,
                                        name: found.name ?? '',
                                        tax_id: found.tax_id ?? '',
                                    }));
                                    setSupplierForInvoice(true);
                                    setPanel('supplier');
                                }}
                            />
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label>
                                Proveedor
                                <select
                                    className={field}
                                    value={draft.data.supplier_id}
                                    onChange={(event) =>
                                        draft.setData(
                                            'supplier_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Seleccionar</option>
                                    {suppliers.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Tipo
                                <select
                                    className={field}
                                    value={draft.data.document_type}
                                    onChange={(event) =>
                                        draft.setData(
                                            'document_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="purchase">Compra</option>
                                    <option value="expense">Gasto</option>
                                </select>
                            </label>
                            <label>
                                Referencia interna (opcional)
                                <input
                                    className={field}
                                    value={draft.data.external_reference}
                                    onChange={(event) =>
                                        draft.setData(
                                            'external_reference',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Orden de compra o recibo"
                                />
                            </label>
                            <span />
                            <FiscalDocumentFields
                                data={draft.data}
                                field={field}
                                onChange={(key, value) =>
                                    draft.setData(key, value)
                                }
                                hint="Los precios incluyen IVA. El sistema separa el IVA crédito fiscal por línea; las facturas FPEQ no generan crédito. La factura especial (FESP) la emite tu empresa al comprarle a una persona sin factura: al pagarla se retienen el IVA y el ISR."
                            />
                            <label>
                                Fecha del documento
                                <input
                                    type="date"
                                    className={field}
                                    value={draft.data.document_date}
                                    onChange={(event) =>
                                        draft.setData(
                                            'document_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Fecha de vencimiento
                                <input
                                    type="date"
                                    className={field}
                                    value={draft.data.due_on}
                                    onChange={(event) =>
                                        draft.setData(
                                            'due_on',
                                            event.target.value,
                                        )
                                    }
                                />
                                <small className="text-xs text-slate-500">
                                    {(() => {
                                        const terms = suppliers.find(
                                            (item) =>
                                                String(item.id) ===
                                                draft.data.supplier_id,
                                        )?.payment_terms_days;

                                        return terms === null ||
                                            terms === undefined
                                            ? 'Obligatoria si el proveedor no tiene plazo configurado.'
                                            : `Si la dejas vacía se calcula con el plazo del proveedor (${terms} días).`;
                                    })()}
                                </small>
                            </label>
                            <label>
                                Moneda
                                <select
                                    className={field}
                                    value={draft.data.currency}
                                    onChange={(event) =>
                                        draft.setData(
                                            'currency',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="GTQ">GTQ (quetzales)</option>
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                </select>
                                {draft.data.currency !== 'GTQ' && (
                                    <small className="text-xs text-slate-500">
                                        Los precios se ingresan en{' '}
                                        {draft.data.currency} y se convierten a
                                        GTQ con el tipo de cambio registrado
                                        para la fecha del documento.
                                    </small>
                                )}
                            </label>
                        </div>
                        <div className="space-y-3">
                            <h3 className="font-semibold">Detalle</h3>
                            {draft.data.lines.map((line, index) => (
                                <div
                                    key={index}
                                    className="grid gap-3 rounded-xl bg-slate-50 p-4 md:grid-cols-[1fr_1fr_110px_90px_130px_auto]"
                                >
                                    <select
                                        className={field}
                                        value={line.account_id}
                                        onChange={(event) =>
                                            draft.setData(
                                                'lines',
                                                draft.data.lines.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  account_id:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    >
                                        <option value="">
                                            Cuenta de activo o gasto
                                        </option>
                                        {accounts
                                            .filter((item) =>
                                                ['asset', 'expense'].includes(
                                                    item.type,
                                                ),
                                            )
                                            .map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.code} · {item.name}
                                                </option>
                                            ))}
                                    </select>
                                    <input
                                        className={field}
                                        value={line.description}
                                        onChange={(event) =>
                                            draft.setData(
                                                'lines',
                                                draft.data.lines.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  description:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                        placeholder="Concepto"
                                    />
                                    <TaxTreatmentSelect
                                        field={field}
                                        value={line.tax_treatment}
                                        onChange={(value) =>
                                            draft.setData(
                                                'lines',
                                                draft.data.lines.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  tax_treatment:
                                                                      value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <input
                                        type="number"
                                        min="1"
                                        className={field}
                                        value={line.quantity}
                                        onChange={(event) =>
                                            draft.setData(
                                                'lines',
                                                draft.data.lines.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  quantity:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <input
                                        inputMode="decimal"
                                        className={field}
                                        value={line.unit_price}
                                        onChange={(event) =>
                                            draft.setData(
                                                'lines',
                                                draft.data.lines.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  unit_price:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                        placeholder="0.00"
                                    />
                                    <button
                                        type="button"
                                        className="self-end rounded-xl p-3 text-red-600 hover:bg-red-50"
                                        onClick={() =>
                                            draft.setData(
                                                'lines',
                                                draft.data.lines.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                        disabled={draft.data.lines.length === 1}
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            className={secondary}
                            onClick={() =>
                                draft.setData('lines', [
                                    ...draft.data.lines,
                                    {
                                        account_id: String(
                                            mapping?.default_expense_account_id ??
                                                '',
                                        ),
                                        description: '',
                                        quantity: '1',
                                        unit_price: '',
                                        tax_treatment: 'goods',
                                    },
                                ])
                            }
                        >
                            <Plus className="size-4" /> Agregar línea
                        </button>
                        <Errors errors={draft.errors} />
                        <button className={primary} disabled={draft.processing}>
                            Crear borrador y adjuntar respaldo
                        </button>
                    </form>
                </Modal>
            )}

            {panel === 'mapping' && (
                <Modal
                    title="Mapeo para asientos automáticos"
                    close={() => setPanel(null)}
                >
                    <p className="mb-5 text-sm text-slate-500">
                        Atlantia usa estas cuentas para preparar asientos de
                        ventas, cobros, compras y pagos. Solo el propietario
                        puede modificarlas.
                    </p>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            mapForm.patch(
                                routes.accountingMapping.url(company.public_id),
                                { onSuccess: () => setPanel(null) },
                            );
                        }}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {[
                            ['cash_account_id', 'Caja o bancos'],
                            ['receivable_account_id', 'Cuentas por cobrar'],
                            ['revenue_account_id', 'Ingresos'],
                            ['payable_account_id', 'Cuentas por pagar'],
                            [
                                'default_expense_account_id',
                                'Gasto predeterminado',
                            ],
                        ].map(([key, label]) => (
                            <label key={key}>
                                {label}
                                <select
                                    className={field}
                                    value={
                                        mapForm.data[
                                            key as keyof typeof mapForm.data
                                        ]
                                    }
                                    onChange={(event) =>
                                        mapForm.setData(
                                            key as keyof typeof mapForm.data,
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Seleccionar cuenta</option>
                                    {accounts.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.code} · {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                        <div className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600 sm:col-span-2">
                            <Landmark className="mr-2 inline size-4" />
                            Configuración actual:{' '}
                            {mapping
                                ? accountLabel(mapping.payable_account_id)
                                : 'pendiente'}{' '}
                            para cuentas por pagar.
                        </div>
                        <div className="sm:col-span-2">
                            <Errors errors={mapForm.errors} />
                            <button
                                className={primary}
                                disabled={mapForm.processing}
                            >
                                Guardar configuración
                            </button>
                        </div>
                    </form>
                </Modal>
            )}
        </AccountingSection>
    );
}
