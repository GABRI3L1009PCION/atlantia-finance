import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Clock3,
    Download,
    FilePlus2,
    Landmark,
    Plus,
    Search,
    Settings2,
    Truck,
    WalletCards,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AccountingSection from '@/components/accounting-section';
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

type Supplier = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
    document_count: number;
};
type Account = { id: number; code: string; name: string; type: string };
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
    aging,
    canWrite,
    isOwner,
    today,
    notice,
}: {
    company: Company;
    documents: Page<Document>;
    suppliers: Supplier[];
    accounts: Account[];
    mapping: Mapping;
    filters: { search: string; status: string; supplier: number | null };
    portfolio: {
        accrued: number;
        paid: number;
        outstanding: number;
        overdue: number;
    };
    aging: Record<string, number>;
    canWrite: boolean;
    isOwner: boolean;
    today: string;
    notice?: string;
}) {
    const [panel, setPanel] = useState<
        'supplier' | 'document' | 'mapping' | null
    >(null);
    const supplier = useForm({ name: '', tax_id: '', email: '', phone: '' });
    const search = useForm({
        search: filters.search,
        status: filters.status,
        supplier: filters.supplier ? String(filters.supplier) : '',
    });
    const draft = useForm({
        request_id: crypto.randomUUID(),
        supplier_id: '',
        document_type: 'purchase',
        external_reference: '',
        document_date: today,
        due_on: '',
        lines: [
            {
                account_id: mapping?.default_expense_account_id
                    ? String(mapping.default_expense_account_id)
                    : '',
                description: '',
                quantity: '1',
                unit_price: '',
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
    const metrics: Array<{
        label: string;
        value: number;
        icon: LucideIcon;
        tone: string;
        accent: string;
    }> = [
        {
            label: 'Compras aprobadas',
            value: portfolio.accrued,
            icon: FilePlus2,
            tone: 'bg-rose-50 text-[#7d0808]',
            accent: 'bg-[#7d0808]',
        },
        {
            label: 'Pagado',
            value: portfolio.paid,
            icon: WalletCards,
            tone: 'bg-emerald-50 text-emerald-700',
            accent: 'bg-emerald-500',
        },
        {
            label: 'Por pagar',
            value: portfolio.outstanding,
            icon: Clock3,
            tone: 'bg-amber-50 text-amber-700',
            accent: 'bg-amber-500',
        },
        {
            label: 'Cartera vencida',
            value: portfolio.overdue,
            icon: AlertTriangle,
            tone: 'bg-red-50 text-red-700',
            accent: 'bg-red-500',
        },
    ];

    return (
        <AccountingSection
            company={company}
            title="Compras, gastos y cuentas por pagar"
            action={
                isOwner ? (
                    <button
                        type="button"
                        onClick={() => setPanel('mapping')}
                        className="inline-flex items-center gap-2 rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-semibold text-white hover:bg-white/20"
                    >
                        <Settings2 className="size-4" /> Configuración contable
                    </button>
                ) : undefined
            }
        >
            {notice && (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
                    {notice}
                </div>
            )}
            <section className="rounded-2xl border border-blue-200 bg-gradient-to-r from-blue-50 to-white p-5 text-sm text-blue-950">
                <strong>Control interno conectado con contabilidad.</strong>{' '}
                Cada compra, gasto y pago crea un asiento borrador trazable; el
                propietario lo revisa antes de contabilizarlo. Los comprobantes
                no sustituyen documentos fiscales.
            </section>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {metrics.map(({ label, value, icon: Icon, tone, accent }) => (
                    <div
                        key={label}
                        className="relative overflow-hidden rounded-2xl border border-[#e9e2e2] bg-white p-5 shadow-[0_8px_24px_rgba(60,20,20,.06)]"
                    >
                        <div className="flex items-start justify-between">
                            <p className="text-sm font-medium text-slate-500">
                                {label}
                            </p>
                            <span className={`rounded-xl p-2.5 ${tone}`}>
                                <Icon className="size-5" />
                            </span>
                        </div>
                        <p className="mt-4 text-3xl font-semibold text-[#640000]">
                            {money(value)}
                        </p>
                        <span
                            className={`absolute bottom-0 left-0 h-1 w-24 ${accent}`}
                        />
                    </div>
                ))}
            </div>

            <Card title="Antigüedad de saldos por pagar">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        ['Por vencer', aging.current],
                        ['1–30 días', aging.days_1_30],
                        ['31–60 días', aging.days_31_60],
                        ['61–90 días', aging.days_61_90],
                        ['Más de 90 días', aging.days_90_plus],
                    ].map(([label, value], index) => (
                        <div
                            key={String(label)}
                            className={`rounded-xl border p-4 ${index === 4 ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-slate-50'}`}
                        >
                            <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                {String(label)}
                            </p>
                            <p className="mt-2 text-xl font-semibold text-[#142135]">
                                {money(Number(value))}
                            </p>
                        </div>
                    ))}
                </div>
            </Card>

            <Card title="Proveedores">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-slate-500">
                        Historial, documentos y saldos separados por proveedor.
                    </p>
                    {canWrite && (
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => setPanel('supplier')}
                        >
                            <Plus className="size-4" /> Registrar proveedor
                        </button>
                    )}
                </div>
                {suppliers.length ? (
                    <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {suppliers.map((item) => (
                            <div
                                key={item.id}
                                className="flex items-center gap-4 rounded-xl border border-slate-200 p-4"
                            >
                                <span className="rounded-xl bg-[#f5e7e7] p-3 text-[#7d0808]">
                                    <Truck className="size-5" />
                                </span>
                                <div className="min-w-0">
                                    <strong>{item.name}</strong>
                                    <p className="text-sm text-slate-500">
                                        NIT {item.tax_id || 'No indicado'} ·{' '}
                                        {item.document_count} documentos
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="mt-5 rounded-xl border border-dashed p-8 text-center text-slate-500">
                        Aún no hay proveedores registrados.
                    </div>
                )}
            </Card>

            <Card title="Documentos de compra y gasto">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            routes.purchases.url(company.public_id),
                            search.data,
                            { preserveState: true },
                        );
                    }}
                    className="grid gap-3 lg:grid-cols-[1fr_190px_220px_auto]"
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
                            placeholder="Proveedor, referencia o número"
                        />
                    </label>
                    <select
                        className={field}
                        value={search.data.status}
                        onChange={(event) =>
                            search.setData('status', event.target.value)
                        }
                    >
                        <option value="all">Todos los estados</option>
                        <option value="draft">Borradores</option>
                        <option value="pending">Pendientes</option>
                        <option value="partial">Pagos parciales</option>
                        <option value="paid">Pagados</option>
                        <option value="overdue">Vencidos</option>
                        <option value="void">Anulados</option>
                    </select>
                    <select
                        className={field}
                        value={search.data.supplier}
                        onChange={(event) =>
                            search.setData('supplier', event.target.value)
                        }
                    >
                        <option value="">Todos los proveedores</option>
                        {suppliers.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                    <button className={secondary}>Aplicar filtros</button>
                </form>
                <div className="mt-4 flex flex-wrap justify-end gap-2">
                    <a
                        className={secondary}
                        href={routes.purchasesExport.url(company.public_id, {
                            query: { format: 'pdf' },
                        })}
                    >
                        <Download className="size-4" /> PDF
                    </a>
                    <a
                        className={secondary}
                        href={routes.purchasesExport.url(company.public_id, {
                            query: { format: 'xlsx' },
                        })}
                    >
                        <Download className="size-4" /> Excel
                    </a>
                    {canWrite && (
                        <button
                            type="button"
                            className={primary}
                            onClick={() => setPanel('document')}
                            disabled={!suppliers.length}
                        >
                            <Plus className="size-4" /> Nueva compra o gasto
                        </button>
                    )}
                </div>
                <div className="mt-5 overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[850px] text-left text-sm">
                        <thead className="bg-[#fbf7f7] text-slate-600">
                            <tr>
                                <th className="p-4">Número</th>
                                <th>Proveedor</th>
                                <th>Fecha / vence</th>
                                <th>Estado</th>
                                <th className="text-right">Total</th>
                                <th className="text-right">Pendiente</th>
                                <th className="p-4 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {documents.data.map((row) => {
                                const [label, tone] = status(row);

                                return (
                                    <tr
                                        key={row.public_id}
                                        className="hover:bg-slate-50"
                                    >
                                        <td className="p-4 font-semibold">
                                            {row.number
                                                ? `CP-${String(row.number).padStart(6, '0')}`
                                                : 'BORRADOR'}
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
                                        <td>
                                            {row.document_date}
                                            <p className="text-xs text-slate-500">
                                                Vence {row.due_on}
                                            </p>
                                        </td>
                                        <td>
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${tone}`}
                                            >
                                                {label}
                                            </span>
                                        </td>
                                        <td className="text-right">
                                            {money(row.total_cents)}
                                        </td>
                                        <td className="text-right font-medium">
                                            {money(
                                                row.status === 'void'
                                                    ? 0
                                                    : Math.max(
                                                          0,
                                                          Number(
                                                              row.total_cents,
                                                          ) -
                                                              Number(
                                                                  row.paid_cents,
                                                              ),
                                                      ),
                                            )}
                                        </td>
                                        <td className="p-4 text-right">
                                            <Link
                                                className="inline-flex items-center gap-1 font-semibold text-[#7d0808]"
                                                href={routes.purchaseShow([
                                                    company.public_id,
                                                    row.public_id,
                                                ])}
                                            >
                                                Ver detalle{' '}
                                                <ArrowRight className="size-4" />
                                            </Link>
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
            </Card>

            {panel === 'supplier' && (
                <Modal title="Registrar proveedor" close={() => setPanel(null)}>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            supplier.post(
                                routes.purchaseSupplier.url(company.public_id),
                                { onSuccess: () => setPanel(null) },
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
                                Referencia del proveedor
                                <input
                                    className={field}
                                    value={draft.data.external_reference}
                                    onChange={(event) =>
                                        draft.setData(
                                            'external_reference',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Factura, recibo u orden"
                                />
                            </label>
                            <span />
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
                            </label>
                        </div>
                        <div className="space-y-3">
                            <h3 className="font-semibold">Detalle</h3>
                            {draft.data.lines.map((line, index) => (
                                <div
                                    key={index}
                                    className="grid gap-3 rounded-xl bg-slate-50 p-4 md:grid-cols-[1fr_1fr_90px_130px_auto]"
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
