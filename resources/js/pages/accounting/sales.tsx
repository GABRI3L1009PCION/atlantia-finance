import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CircleDollarSign,
    Clock3,
    Download,
    FileText,
    Filter,
    Info,
    Plus,
    ReceiptText,
    Search,
    UsersRound,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
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

type Invoice = {
    public_id: string;
    number: number | null;
    customer_name: string;
    status: string;
    due_on: string;
    issued_on: string;
    total_cents: number;
    paid_cents: number;
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
    filters: { search: string; status: string; customer: number | null };
    portfolio: {
        invoiced: number;
        collected: number;
        outstanding: number;
        overdue: number;
    };
    canWrite: boolean;
    today: string;
}) {
    const [panel, setPanel] = useState<'guide' | 'customer' | 'invoice' | null>(
        null,
    );
    const customer = useForm({ name: '', tax_id: '', email: '' });
    const search = useForm({
        search: filters.search,
        status: filters.status,
        customer: filters.customer ? String(filters.customer) : '',
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

    return (
        <AccountingSection
            company={company}
            title="Facturación básica y cobros"
            action={
                <button
                    type="button"
                    onClick={() => setPanel('guide')}
                    className="inline-flex items-center gap-2 rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/20"
                >
                    <Info className="size-4" /> Guía rápida
                </button>
            }
        >
            <section
                id="guia-cobros"
                className="flex gap-4 rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-white p-5 text-sm text-amber-950 shadow-sm"
            >
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <Info className="size-5" />
                </span>
                <div>
                    <p className="font-semibold">
                        Comprobantes internos en GTQ
                    </p>
                    <p className="mt-1 leading-6 text-amber-900/80">
                        No son facturas FEL, no calculan impuestos ni generan
                        asientos automáticos. Los cobros se registran
                        manualmente; esta pantalla no carga tarjetas ni modifica
                        tu suscripción.
                    </p>
                </div>
            </section>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    {
                        label: 'Comprobantes emitidos',
                        value: portfolio.invoiced,
                        icon: ReceiptText,
                        tone: 'text-[#8a1212] bg-rose-50',
                        accent: 'bg-[#8a1212]',
                    },
                    {
                        label: 'Cobrado',
                        value: portfolio.collected,
                        icon: CircleDollarSign,
                        tone: 'text-emerald-700 bg-emerald-50',
                        accent: 'bg-emerald-500',
                    },
                    {
                        label: 'Por cobrar',
                        value: portfolio.outstanding,
                        icon: Clock3,
                        tone: 'text-amber-700 bg-amber-50',
                        accent: 'bg-amber-500',
                    },
                    {
                        label: 'Cartera vencida',
                        value: portfolio.overdue,
                        icon: AlertTriangle,
                        tone: 'text-red-700 bg-red-50',
                        accent: 'bg-red-500',
                    },
                ].map(({ label, value, icon: Icon, tone, accent }) => (
                    <div
                        key={label}
                        className="relative overflow-hidden rounded-2xl border border-[#e9e2e2] bg-white p-5 shadow-[0_8px_24px_rgba(60,20,20,.06)]"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <p className="text-sm font-medium text-slate-500">
                                {label}
                            </p>
                            <span className={`rounded-xl p-2.5 ${tone}`}>
                                <Icon className="size-5" />
                            </span>
                        </div>
                        <p className="mt-4 text-3xl font-semibold tracking-tight text-[#640000]">
                            {money(value)}
                        </p>
                        <span
                            className={`absolute bottom-0 left-0 h-1 w-24 rounded-r-full ${accent}`}
                        />
                    </div>
                ))}
            </div>
            <section className="rounded-2xl border border-[#e9e2e2] bg-white p-6 shadow-[0_8px_24px_rgba(60,20,20,.06)]">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold text-[#142135]">
                            Clientes
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Consulta saldos e historial de cobros por cliente.
                        </p>
                    </div>
                    {canWrite && (
                        <button
                            type="button"
                            onClick={() => setPanel('customer')}
                            className={secondary}
                        >
                            <Plus className="size-4" /> Registrar cliente
                        </button>
                    )}
                </div>
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {customers.map((client) => (
                        <Link
                            key={client.id}
                            href={routes.salesCustomerShow([
                                company.public_id,
                                client.id,
                            ])}
                            className="group mt-5 flex items-center gap-4 rounded-xl border border-slate-200 p-4 transition hover:-translate-y-0.5 hover:border-[#b67e7d] hover:bg-[#fdf8f8] hover:shadow-md"
                        >
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#f5e7e7] text-[#7d0808]">
                                <UsersRound className="size-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <strong>{client.name}</strong>
                                <p className="mt-1 text-sm text-slate-500">
                                    NIT {client.tax_id || 'No indicado'} ·{' '}
                                    {client.invoice_count} comprobantes
                                </p>
                                {client.email && (
                                    <p className="truncate text-sm text-slate-500">
                                        {client.email}
                                    </p>
                                )}
                            </div>
                            <ArrowRight className="size-5 text-slate-400 transition group-hover:translate-x-1 group-hover:text-[#7d0808]" />
                        </Link>
                    ))}
                </div>
                {!customers.length && (
                    <div className="mt-5 flex flex-col items-center rounded-2xl border border-dashed border-[#decaca] bg-[#fdfafa] px-6 py-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm">
                            <UsersRound className="size-7" />
                        </span>
                        <h3 className="mt-4 text-lg font-semibold">
                            Aún no hay clientes
                        </h3>
                        <p className="mt-1 max-w-md text-sm text-slate-500">
                            Registra el primero para crear comprobantes,
                            consultar su historial y controlar los saldos
                            pendientes.
                        </p>
                        {canWrite && (
                            <button
                                type="button"
                                onClick={() => setPanel('customer')}
                                className={`${secondary} mt-4`}
                            >
                                <Plus className="size-4" /> Registrar cliente
                            </button>
                        )}
                    </div>
                )}
            </section>
            <section className="rounded-2xl border border-[#e9e2e2] bg-white p-6 shadow-[0_8px_24px_rgba(60,20,20,.06)]">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold">
                            Comprobantes de la empresa
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Controla borradores, documentos emitidos y su saldo.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
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
                                <Plus className="size-4" /> Nuevo comprobante
                            </button>
                        )}
                    </div>
                </div>
                <form
                    className="mb-5 grid gap-3 rounded-xl bg-[#faf8f7] p-4 md:grid-cols-[1.3fr_.8fr_.9fr_auto]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            routes.sales.url(company.public_id),
                            search.data,
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <label className="text-sm font-medium">
                        <span className="flex items-center gap-2">
                            <Search className="size-4 text-slate-400" /> Buscar
                        </span>
                        <input
                            className={field}
                            value={search.data.search}
                            placeholder="Cliente, NIT o número"
                            onChange={(event) =>
                                search.setData('search', event.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm font-medium">
                        Estado
                        <select
                            className={field}
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
                    <label className="text-sm font-medium">
                        Cliente
                        <select
                            className={field}
                            value={search.data.customer}
                            onChange={(event) =>
                                search.setData('customer', event.target.value)
                            }
                        >
                            <option value="">Todos</option>
                            {customers.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
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
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-[#faf4f4] text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                {[
                                    'Documento / cliente',
                                    'Estado',
                                    'Vencimiento',
                                    'Total',
                                    'Saldo',
                                    '',
                                ].map((h, i) => (
                                    <th key={i} className="p-3">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.map((i) => (
                                <tr
                                    key={i.public_id}
                                    className="border-b border-slate-100"
                                >
                                    <td className="p-3">
                                        <strong>
                                            {i.number
                                                ? `INT-${i.number}`
                                                : 'Borrador'}
                                        </strong>
                                        <p className="mt-1 text-slate-500">
                                            {i.customer_name}
                                        </p>
                                    </td>
                                    <td className="p-3">
                                        <span
                                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(i)}`}
                                        >
                                            {statusLabel(i)}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {i.due_on}
                                        {i.status === 'issued' &&
                                            Number(i.total_cents) >
                                                Number(i.paid_cents) &&
                                            i.due_on < today && (
                                                <span className="ml-2 rounded-full bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">
                                                    Vencido
                                                </span>
                                            )}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {money(i.total_cents)}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {i.status === 'void'
                                            ? '—'
                                            : money(
                                                  Number(i.total_cents) -
                                                      Number(i.paid_cents),
                                              )}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={routes.invoice([
                                                    company.public_id,
                                                    i.public_id,
                                                ])}
                                                className={secondary}
                                            >
                                                Ver detalle
                                            </Link>
                                            <a
                                                href={routes.invoicePdf.url([
                                                    company.public_id,
                                                    i.public_id,
                                                ])}
                                                className={secondary}
                                                title="Descargar comprobante en PDF"
                                            >
                                                <Download className="size-4" />{' '}
                                                PDF
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {!invoices.data.length && (
                    <div className="flex flex-col items-center border-t border-slate-100 px-6 py-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-[#f5e7e7] text-[#7d0808]">
                            <FileText className="size-7" />
                        </span>
                        <h3 className="mt-4 font-semibold">
                            No hay comprobantes registrados
                        </h3>
                        <p className="mt-1 text-sm text-slate-500">
                            Tu primer comprobante empieza con un cliente y sus
                            conceptos.
                        </p>
                        {canWrite && (
                            <button
                                type="button"
                                onClick={() =>
                                    setPanel(
                                        customers.length
                                            ? 'invoice'
                                            : 'customer',
                                    )
                                }
                                className={`${secondary} mt-4`}
                            >
                                <Plus className="size-4" />{' '}
                                {customers.length
                                    ? 'Nuevo comprobante'
                                    : 'Registrar cliente'}
                            </button>
                        )}
                    </div>
                )}
                <Pager page={invoices} />
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
                                                value={draft.data.customer_id}
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
                                        {draft.data.lines.map((line, index) => (
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
                                                        {key === 'description'
                                                            ? 'Concepto'
                                                            : key === 'quantity'
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
                                                            className={field}
                                                            value={line[key]}
                                                            onChange={(e) =>
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
                                                            .length === 1
                                                    }
                                                    className={secondary}
                                                    onClick={() =>
                                                        draft.setData(
                                                            'lines',
                                                            draft.data.lines.filter(
                                                                (_, n) =>
                                                                    n !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Quitar
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                    <Errors errors={draft.errors} />
                                    <div className="mt-4 flex flex-wrap gap-3">
                                        <button
                                            type="button"
                                            className={secondary}
                                            disabled={
                                                draft.data.lines.length >= 50
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
                                        El servidor calculará el total exacto.
                                        Revisa el borrador antes de emitirlo;
                                        únicamente el propietario puede emitir y
                                        anular.
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
                                <h3 className="mt-4 font-semibold">{title}</h3>
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
        </AccountingSection>
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
