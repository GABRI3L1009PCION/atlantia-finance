import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    CalendarDays,
    Clock,
    Coins,
    CreditCard,
    Download,
    Eye,
    Layers,
    Link2,
    PlusCircle,
    ReceiptText,
    Search,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    Field,
    Notice,
    Pill,
    RowMenu,
    SensitiveAction,
    Unavailable,
    ccButton,
    ccGhost,
    ccInput,
    date,
    isUnavailable,
    money,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Invoice = {
    id: number;
    number: string;
    organization_id: number;
    organization_public_id: string | null;
    organization: string | null;
    status: string;
    status_label: string;
    currency: string;
    subtotal: number;
    discount: number;
    credit_applied: number;
    total: number;
    kind: string;
    period_start: string | null;
    period_end: string | null;
    due_on: string | null;
    days_overdue: number;
    issued_at: string | null;
    paid_at: string | null;
    void_reason: string | null;
    lines: {
        description: string;
        quantity: string;
        unit_amount: string;
        amount: string;
    }[];
};
type Summary =
    | {
          mrr: number;
          arr: number;
          previous_mrr: number;
          change_percent: number | null;
          open: number;
          past_due: number;
          collected_month: number;
          open_invoices: number;
          past_due_invoices: number;
      }
    | { unavailable: true; message: string };

type Credit = {
    id: number;
    organization_id: number;
    organization_public_id: string | null;
    organization: string;
    remaining: string;
    amount: string;
    reason: string;
    expires_at: string | null;
};

const kindLabels: Record<string, string> = {
    recurring: 'Recurrente',
    one_time: 'Cargo único',
    adjustment: 'Ajuste',
};

const card =
    'rounded-2xl border border-slate-200 bg-white shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]';

function Modal({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div className="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <div className={`${panel} w-full max-w-2xl space-y-4 p-5`}>
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="text-slate-500 hover:text-slate-900"
                    >
                        <X className="size-5" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

/** Lines of an invoice and what can be done with it: payment link, manual payment, void or refund. */
function InvoiceDetail({
    invoice,
    canManage,
    canPay,
    onClose,
}: {
    invoice: Invoice;
    canManage: boolean;
    canPay: boolean;
    onClose: () => void;
}) {
    const [reference, setReference] = useState('');
    const [paidOn, setPaidOn] = useState(() =>
        new Date().toISOString().slice(0, 10),
    );
    const payable = ['open', 'past_due'].includes(invoice.status);

    return (
        <Modal
            title={`Factura ${invoice.number} · ${invoice.organization ?? ''}`}
            onClose={onClose}
        >
            <div className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <Pill tone={statusTone[invoice.status]}>
                    {invoice.status_label}
                </Pill>
                <span>{kindLabels[invoice.kind] ?? invoice.kind}</span>
                {invoice.period_start && (
                    <span>
                        · {date(invoice.period_start)} –{' '}
                        {date(invoice.period_end)}
                    </span>
                )}
                <span>· vence {date(invoice.due_on)}</span>
            </div>
            <table className="w-full text-sm">
                <tbody className="divide-y divide-slate-100">
                    {invoice.lines.map((line, index) => (
                        <tr key={index}>
                            <td className="py-1.5 text-slate-700">
                                {line.description} × {Number(line.quantity)}
                            </td>
                            <td className="py-1.5 text-right text-slate-900">
                                {money(Number(line.amount), invoice.currency)}
                            </td>
                        </tr>
                    ))}
                    {invoice.discount + invoice.credit_applied > 0 && (
                        <tr>
                            <td className="py-1.5 text-slate-700">
                                Descuento y crédito
                            </td>
                            <td className="py-1.5 text-right text-slate-900">
                                -
                                {money(
                                    invoice.discount + invoice.credit_applied,
                                    invoice.currency,
                                )}
                            </td>
                        </tr>
                    )}
                    <tr>
                        <td className="py-2 font-bold text-slate-900">Total</td>
                        <td className="py-2 text-right font-bold text-slate-900">
                            {money(invoice.total, invoice.currency)}
                        </td>
                    </tr>
                </tbody>
            </table>
            {invoice.void_reason && (
                <p className="text-sm text-slate-500">
                    Anulada: {invoice.void_reason}
                </p>
            )}
            {canPay && payable && (
                <div className="space-y-2 border-t border-slate-100 pt-3">
                    <p className="text-sm font-bold text-slate-900">Cobro</p>
                    <button
                        type="button"
                        className={ccGhost}
                        onClick={() =>
                            router.post(
                                `/control-center/billing/invoices/${invoice.id}/payment-link`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Link2 className="size-3.5" /> Generar enlace de pago
                    </button>
                    <div className="flex flex-wrap gap-2">
                        <input
                            className={`${ccInput} h-9 flex-1`}
                            placeholder="Referencia (boleta o transferencia)"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                        />
                        <input
                            type="date"
                            className={`${ccInput} h-9 max-w-44`}
                            value={paidOn}
                            max={new Date().toISOString().slice(0, 10)}
                            onChange={(e) => setPaidOn(e.target.value)}
                            aria-label="Fecha de pago"
                        />
                        <button
                            type="button"
                            className={ccButton}
                            disabled={reference.trim() === '' || paidOn === ''}
                            onClick={() =>
                                router.post(
                                    `/control-center/billing/invoices/${invoice.id}/manual-payment`,
                                    { reference, paid_on: paidOn },
                                    {
                                        preserveScroll: true,
                                        onSuccess: onClose,
                                    },
                                )
                            }
                        >
                            Registrar pago manual
                        </button>
                    </div>
                </div>
            )}
            {canManage &&
                (['draft', 'open', 'past_due'].includes(invoice.status) ||
                    invoice.status === 'paid') && (
                    <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                        {['draft', 'open', 'past_due'].includes(
                            invoice.status,
                        ) && (
                            <SensitiveAction
                                danger
                                url={`/control-center/billing/invoices/${invoice.id}/void`}
                                label="Anular"
                                title={`Anular ${invoice.number}`}
                                description="Si se aplicó crédito, se devuelve a la organización."
                            />
                        )}
                        {invoice.status === 'paid' && (
                            <SensitiveAction
                                danger
                                url={`/control-center/billing/invoices/${invoice.id}/refund`}
                                label="Registrar reembolso"
                                title={`Reembolso de ${invoice.number}`}
                                description="Registra que el reembolso se hizo en el proveedor de pagos."
                            />
                        )}
                    </div>
                )}
        </Modal>
    );
}

function GrantCredit({
    organizations,
    onClose,
}: {
    organizations: { id: number; public_id: string; name: string }[];
    onClose: () => void;
}) {
    const form = useForm({
        organization_id: organizations[0]?.public_id ?? '',
        amount: '',
        reason: '',
        expires_at: '',
    });

    return (
        <Modal title="Asignar crédito" onClose={onClose}>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(
                        ({
                            organization_id: organization,
                            expires_at: expires,
                            ...data
                        }) => {
                            void organization;

                            return expires === ''
                                ? data
                                : { ...data, expires_at: expires };
                        },
                    );
                    form.post(
                        `/control-center/organizations/${form.data.organization_id}/credits`,
                        { preserveScroll: true, onSuccess: onClose },
                    );
                }}
            >
                <div className="grid gap-3 md:grid-cols-2">
                    <Field label="Organización" wide>
                        <select
                            className={ccInput}
                            value={form.data.organization_id}
                            onChange={(e) =>
                                form.setData('organization_id', e.target.value)
                            }
                        >
                            {organizations.map((organization) => (
                                <option
                                    key={organization.public_id}
                                    value={organization.public_id}
                                >
                                    {organization.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Monto (Q)">
                        <input
                            type="number"
                            min="0.01"
                            step="0.01"
                            className={ccInput}
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Vence (opcional)">
                        <input
                            type="date"
                            className={ccInput}
                            value={form.data.expires_at}
                            onChange={(e) =>
                                form.setData('expires_at', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Motivo" wide>
                        <input
                            className={ccInput}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            minLength={5}
                            required
                        />
                    </Field>
                </div>
                <p className="text-xs text-slate-500">
                    El crédito se descuenta solo en las próximas facturas de la
                    organización.
                </p>
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-[#b91c1c]">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ccGhost} onClick={onClose}>
                        Cancelar
                    </button>
                    <button
                        className={ccButton}
                        disabled={form.processing || organizations.length === 0}
                    >
                        Asignar crédito
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/** Downloads the invoices on screen as a CSV that Excel opens with accents intact. */
function exportCsv(invoices: Invoice[]): void {
    const quote = (value: string | number | null): string =>
        `"${String(value ?? '').replaceAll('"', '""')}"`;
    const lines = [
        [
            'Número',
            'Organización',
            'Tipo',
            'Inicio del período',
            'Fin del período',
            'Subtotal',
            'Descuento y crédito',
            'Total',
            'Moneda',
            'Vence',
            'Estado',
        ],
        ...invoices.map((invoice) => [
            invoice.number,
            invoice.organization,
            kindLabels[invoice.kind] ?? invoice.kind,
            invoice.period_start?.slice(0, 10) ?? '',
            invoice.period_end?.slice(0, 10) ?? '',
            invoice.subtotal,
            invoice.discount + invoice.credit_applied,
            invoice.total,
            invoice.currency,
            invoice.due_on?.slice(0, 10) ?? '',
            invoice.status_label,
        ]),
    ];
    const csv =
        '﻿' + lines.map((line) => line.map(quote).join(',')).join('\r\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    );
    link.download = `facturas-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function Billing({
    invoices,
    filters,
    statuses,
    summary,
    credits,
    organizations,
    can_manage,
    can_pay,
}: {
    invoices: Invoice[];
    filters: { status?: string; search?: string };
    statuses: Record<string, string>;
    summary: Summary;
    credits: Credit[];
    organizations: { id: number; public_id: string; name: string }[];
    can_manage: boolean;
    can_pay: boolean;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [viewing, setViewing] = useState<Invoice | null>(null);
    const [granting, setGranting] = useState(false);
    const kpis = isUnavailable(summary)
        ? null
        : [
              {
                  label: 'MRR',
                  value: money(summary.mrr),
                  icon: Coins,
                  tile: 'bg-[#7a0010] text-white',
              },
              {
                  label: 'ARR',
                  value: money(summary.arr),
                  icon: BarChart3,
                  tile: 'bg-[#d39a2c] text-white',
              },
              {
                  label: 'Pendiente de cobro',
                  value: money(summary.open),
                  icon: Clock,
                  tile: 'bg-[#7a0010] text-white',
              },
              {
                  label: 'Vencido',
                  value: money(summary.past_due),
                  icon: AlertTriangle,
                  tile:
                      summary.past_due > 0
                          ? 'bg-[#b91c1c] text-white'
                          : 'bg-[#15803d] text-white',
              },
              {
                  label: 'Cobrado este mes',
                  value: money(summary.collected_month),
                  icon: CalendarDays,
                  tile: 'bg-slate-600 text-white',
              },
          ];
    const iconButton =
        'grid size-8 place-items-center rounded-lg border border-[#7a0010]/40 text-[#7a0010] hover:bg-[#7a0010]/5';
    const cell = 'px-3 py-3 whitespace-nowrap';

    return (
        <>
            <Head title="Facturación · Control Center" />
            <div className="space-y-4">
                <header className="border-l-4 border-[#e0a526] pl-5">
                    <h1 className="font-serif text-4xl font-bold text-slate-900">
                        Facturación
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-slate-600">
                        Facturas de Atlantia Systems a sus organizaciones
                        cliente (no confundir con la facturación de las empresas
                        de los clientes). El MRR solo incluye ingresos
                        recurrentes.
                    </p>
                </header>
                <Notice />

                {kpis === null ? (
                    <div className={`${card} p-4`}>
                        <Unavailable text="Facturación no disponible." />
                    </div>
                ) : (
                    <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                        {kpis.map((kpi) => (
                            <div
                                key={kpi.label}
                                className={`${card} flex items-center gap-3 p-4`}
                            >
                                <span
                                    className={`grid size-12 shrink-0 place-items-center rounded-xl ${kpi.tile}`}
                                >
                                    <kpi.icon className="size-6" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-sm text-slate-700">
                                        {kpi.label}
                                    </span>
                                    <span className="block truncate text-xl font-black text-slate-900 min-[1500px]:text-2xl">
                                        {kpi.value}
                                    </span>
                                </span>
                            </div>
                        ))}
                    </section>
                )}

                <section className={`${card} p-4`}>
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <h2 className="flex flex-1 items-center gap-2 font-serif text-2xl font-bold text-slate-900">
                            <ReceiptText className="size-6 text-[#7a0010]" />
                            Facturas
                        </h2>
                        <form
                            className="relative w-full max-w-sm"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(
                                    '/control-center/billing',
                                    { ...filters, search },
                                    { preserveState: true },
                                );
                            }}
                        >
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                className={`${ccInput} pl-9`}
                                placeholder="Buscar por organización o número…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </form>
                        <select
                            className={`${ccInput} max-w-[200px]`}
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get('/control-center/billing', {
                                    ...filters,
                                    status: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Todos los estados</option>
                            {Object.entries(statuses).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={() => exportCsv(invoices)}
                            disabled={invoices.length === 0}
                            className="inline-flex h-10 items-center gap-2 rounded-lg border-2 border-[#7a0010]/70 px-4 text-sm font-bold text-[#7a0010] transition hover:bg-[#7a0010]/5 disabled:opacity-40"
                        >
                            <Download className="size-4" /> Exportar
                        </button>
                    </div>
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="min-w-full text-[13px] text-slate-800">
                            <thead>
                                <tr className="bg-[#7a0010] text-left font-semibold whitespace-nowrap text-white">
                                    {[
                                        'Número',
                                        'Organización',
                                        'Tipo',
                                        'Período',
                                        'Subtotal',
                                        'Desc./Crédito',
                                        'Total',
                                        'Vence',
                                        'Estado',
                                    ].map((header) => (
                                        <th key={header} className="px-3 py-3">
                                            {header}
                                        </th>
                                    ))}
                                    <th className="px-3 py-3 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {invoices.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={10}
                                            className="px-4 py-10 text-center text-slate-500"
                                        >
                                            No hay facturas.
                                        </td>
                                    </tr>
                                )}
                                {invoices.map((invoice) => {
                                    const organizationUrl = `/control-center/organizations/${invoice.organization_public_id}`;

                                    return (
                                        <tr
                                            key={invoice.id}
                                            className="hover:bg-slate-50"
                                        >
                                            <td
                                                className={`${cell} font-mono text-slate-900`}
                                            >
                                                {invoice.number}
                                            </td>
                                            <td className="px-3 py-3">
                                                <Link
                                                    href={`${organizationUrl}?tab=billing`}
                                                    className="font-medium text-slate-900 hover:text-[#7a0010]"
                                                >
                                                    {invoice.organization}
                                                </Link>
                                            </td>
                                            <td className={cell}>
                                                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-700">
                                                    {kindLabels[invoice.kind] ??
                                                        invoice.kind}
                                                </span>
                                            </td>
                                            <td className={cell}>
                                                {invoice.period_start
                                                    ? `${date(invoice.period_start)} – ${date(invoice.period_end)}`
                                                    : '—'}
                                            </td>
                                            <td className={cell}>
                                                {money(
                                                    invoice.subtotal,
                                                    invoice.currency,
                                                )}
                                            </td>
                                            <td className={cell}>
                                                {invoice.discount +
                                                    invoice.credit_applied >
                                                0
                                                    ? `-${money(invoice.discount + invoice.credit_applied, invoice.currency)}`
                                                    : '—'}
                                            </td>
                                            <td
                                                className={`${cell} font-bold text-slate-900`}
                                            >
                                                {money(
                                                    invoice.total,
                                                    invoice.currency,
                                                )}
                                            </td>
                                            <td className={cell}>
                                                {date(invoice.due_on)}
                                                {invoice.days_overdue > 0 && (
                                                    <span className="block text-[10px] text-[#dc2626]">
                                                        {invoice.days_overdue}{' '}
                                                        días de atraso
                                                    </span>
                                                )}
                                            </td>
                                            <td className={cell}>
                                                <Pill
                                                    tone={
                                                        statusTone[
                                                            invoice.status
                                                        ]
                                                    }
                                                >
                                                    {invoice.status_label}
                                                </Pill>
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center justify-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setViewing(invoice)
                                                        }
                                                        className={iconButton}
                                                        aria-label={`Ver ${invoice.number}`}
                                                        title="Detalle, cobro y anulación"
                                                    >
                                                        <Eye className="size-4" />
                                                    </button>
                                                    <RowMenu
                                                        links={[
                                                            {
                                                                label: 'Billing de la organización',
                                                                href: `${organizationUrl}?tab=billing`,
                                                            },
                                                            {
                                                                label: 'Suscripción',
                                                                href: `${organizationUrl}?tab=subscription`,
                                                            },
                                                            {
                                                                label: 'Pagos',
                                                                href: '/control-center/payments',
                                                            },
                                                        ]}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className={`${card} p-4`}>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="flex items-center gap-2 font-serif text-2xl font-bold text-slate-900">
                            <CreditCard className="size-6 text-[#7a0010]" />
                            Créditos disponibles
                        </h2>
                        {can_manage && (
                            <button
                                type="button"
                                onClick={() => setGranting(true)}
                                className="inline-flex h-10 items-center gap-2 rounded-lg border-2 border-[#7a0010]/70 px-4 text-sm font-bold text-[#7a0010] transition hover:bg-[#7a0010]/5"
                            >
                                <PlusCircle className="size-4" /> Asignar
                                crédito
                            </button>
                        )}
                    </div>
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="min-w-full text-[13px] text-slate-800">
                            <thead>
                                <tr className="bg-[#7a0010] text-left font-semibold whitespace-nowrap text-white">
                                    <th className="px-4 py-3">Organización</th>
                                    <th className="px-3 py-3">Motivo</th>
                                    <th className="px-3 py-3">Disponible</th>
                                    <th className="px-3 py-3">Vence</th>
                                    <th className="px-3 py-3 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {credits.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-10">
                                            <span className="flex flex-col items-center text-center">
                                                <Layers className="size-10 text-slate-400" />
                                                <span className="mt-2 text-base font-semibold text-slate-800">
                                                    Sin créditos disponibles
                                                </span>
                                                <span className="text-sm text-slate-500">
                                                    Aún no se han registrado
                                                    créditos para ninguna
                                                    organización.
                                                </span>
                                            </span>
                                        </td>
                                    </tr>
                                )}
                                {credits.map((credit) => (
                                    <tr
                                        key={credit.id}
                                        className="hover:bg-slate-50"
                                    >
                                        <td className="px-4 py-3 font-medium text-slate-900">
                                            {credit.organization}
                                        </td>
                                        <td className="px-3 py-3 text-slate-700">
                                            {credit.reason}
                                        </td>
                                        <td className="px-3 py-3 font-bold whitespace-nowrap text-slate-900">
                                            {money(Number(credit.remaining))}
                                            <span className="block text-[10px] font-normal text-slate-500">
                                                de{' '}
                                                {money(Number(credit.amount))}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            {credit.expires_at
                                                ? date(credit.expires_at)
                                                : 'Sin vencimiento'}
                                        </td>
                                        <td className="px-3 py-3">
                                            <div className="flex justify-center">
                                                <Link
                                                    href={`/control-center/organizations/${credit.organization_public_id}?tab=billing`}
                                                    className={iconButton}
                                                    aria-label="Ver organización"
                                                    title="Billing de la organización"
                                                >
                                                    <Eye className="size-4" />
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            {viewing && (
                <InvoiceDetail
                    key={viewing.id}
                    invoice={viewing}
                    canManage={can_manage}
                    canPay={can_pay}
                    onClose={() => setViewing(null)}
                />
            )}
            {granting && (
                <GrantCredit
                    organizations={organizations}
                    onClose={() => setGranting(false)}
                />
            )}
        </>
    );
}
