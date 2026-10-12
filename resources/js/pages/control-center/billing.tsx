import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    SensitiveAction,
    Table,
    Unavailable,
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

export default function Billing({
    invoices,
    filters,
    statuses,
    summary,
    credits,
    can_manage,
    can_pay,
}: {
    invoices: Invoice[];
    filters: { status?: string; search?: string };
    statuses: Record<string, string>;
    summary: Summary;
    credits: {
        id: number;
        organization: string;
        remaining: string;
        amount: string;
        reason: string;
    }[];
    can_manage: boolean;
    can_pay: boolean;
}) {
    const [open, setOpen] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const kpis = isUnavailable(summary)
        ? null
        : [
              ['MRR', money(summary.mrr)],
              ['ARR', money(summary.arr)],
              ['Pendiente de cobro', money(summary.open)],
              ['Vencido', money(summary.past_due)],
              ['Cobrado este mes', money(summary.collected_month)],
          ];

    return (
        <>
            <Head title="Facturación · Control Center" />
            <CCPage
                title="Facturación SaaS"
                subtitle="Facturas de Atlantia Systems a sus organizaciones cliente (no confundir con la facturación de las empresas de los clientes). El MRR solo incluye ingresos recurrentes."
            >
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {kpis === null ? (
                        <div className={`${panel} p-4`}>
                            <Unavailable text="Facturación no disponible." />
                        </div>
                    ) : (
                        kpis.map(([label, value]) => (
                            <div key={label} className={`${panel} p-4`}>
                                <p className="text-xs text-slate-400">
                                    {label}
                                </p>
                                <p className="mt-1 text-2xl font-black text-white">
                                    {value}
                                </p>
                            </div>
                        ))
                    )}
                </div>
                <Panel>
                    <form
                        className="mb-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/billing',
                                { ...filters, search },
                                { preserveState: true },
                            );
                        }}
                    >
                        <input
                            className={`${ccInput} max-w-xs`}
                            placeholder="Organización o número"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
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
                    </form>
                    <Table
                        headers={[
                            'Número',
                            'Organización',
                            'Tipo',
                            'Período',
                            'Subtotal',
                            'Desc./crédito',
                            'Total',
                            'Vence',
                            'Estado',
                            '',
                        ]}
                        empty={invoices.length === 0 && 'No hay facturas.'}
                    >
                        {invoices.map((invoice) => (
                            <Fragment key={invoice.id}>
                                <tr key={invoice.id}>
                                    <td className="px-2 py-2 font-mono text-white">
                                        {invoice.number}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/organizations/${invoice.organization_id}?tab=billing`}
                                            className="text-slate-100"
                                        >
                                            {invoice.organization}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {{
                                            recurring: 'Recurrente',
                                            one_time: 'Cargo único',
                                            adjustment: 'Ajuste',
                                        }[invoice.kind] ?? invoice.kind}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {invoice.period_start
                                            ? `${date(invoice.period_start)} – ${date(invoice.period_end)}`
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(
                                            invoice.subtotal,
                                            invoice.currency,
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {invoice.discount +
                                            invoice.credit_applied >
                                        0
                                            ? `-${money(invoice.discount + invoice.credit_applied, invoice.currency)}`
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2 font-bold text-white">
                                        {money(invoice.total, invoice.currency)}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {date(invoice.due_on)}
                                        {invoice.days_overdue > 0 && (
                                            <span className="block text-[10px] text-[#f87171]">
                                                {invoice.days_overdue} días de
                                                atraso
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[invoice.status]}>
                                            {invoice.status_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        <button
                                            type="button"
                                            className={ccGhost}
                                            onClick={() =>
                                                setOpen(
                                                    open === invoice.id
                                                        ? null
                                                        : invoice.id,
                                                )
                                            }
                                        >
                                            Detalle
                                        </button>
                                    </td>
                                </tr>
                                {open === invoice.id && (
                                    <tr key={`${invoice.id}-d`}>
                                        <td
                                            colSpan={10}
                                            className="bg-black/20 p-3"
                                        >
                                            <ul className="mb-2 space-y-1 text-xs">
                                                {invoice.lines.map(
                                                    (line, index) => (
                                                        <li
                                                            key={index}
                                                            className="flex justify-between text-slate-300"
                                                        >
                                                            <span>
                                                                {
                                                                    line.description
                                                                }{' '}
                                                                ×{' '}
                                                                {Number(
                                                                    line.quantity,
                                                                )}
                                                            </span>
                                                            <span>
                                                                {money(
                                                                    Number(
                                                                        line.amount,
                                                                    ),
                                                                    invoice.currency,
                                                                )}
                                                            </span>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                            {invoice.void_reason && (
                                                <p className="text-xs text-slate-500">
                                                    Anulada:{' '}
                                                    {invoice.void_reason}
                                                </p>
                                            )}
                                            <div className="flex flex-wrap gap-2">
                                                {can_pay &&
                                                    [
                                                        'open',
                                                        'past_due',
                                                    ].includes(
                                                        invoice.status,
                                                    ) && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className={
                                                                    ccGhost
                                                                }
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/control-center/billing/invoices/${invoice.id}/payment-link`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Generar enlace
                                                                de pago
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className={
                                                                    ccGhost
                                                                }
                                                                onClick={() => {
                                                                    const reference =
                                                                        window.prompt(
                                                                            'Referencia del pago (boleta, transferencia)',
                                                                        );
                                                                    const paidOn =
                                                                        reference &&
                                                                        window.prompt(
                                                                            'Fecha de pago (AAAA-MM-DD)',
                                                                            new Date()
                                                                                .toISOString()
                                                                                .slice(
                                                                                    0,
                                                                                    10,
                                                                                ),
                                                                        );

                                                                    if (
                                                                        reference &&
                                                                        paidOn
                                                                    ) {
                                                                        router.post(
                                                                            `/control-center/billing/invoices/${invoice.id}/manual-payment`,
                                                                            {
                                                                                reference,
                                                                                paid_on:
                                                                                    paidOn,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        );
                                                                    }
                                                                }}
                                                            >
                                                                Registrar pago
                                                                manual
                                                            </button>
                                                        </>
                                                    )}
                                                {can_manage &&
                                                    [
                                                        'draft',
                                                        'open',
                                                        'past_due',
                                                    ].includes(
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
                                                {can_manage &&
                                                    invoice.status ===
                                                        'paid' && (
                                                        <SensitiveAction
                                                            danger
                                                            url={`/control-center/billing/invoices/${invoice.id}/refund`}
                                                            label="Registrar reembolso"
                                                            title={`Reembolso de ${invoice.number}`}
                                                            description="Registra que el reembolso se hizo en el proveedor de pagos."
                                                        />
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </Table>
                </Panel>
                <Panel title="Créditos disponibles">
                    <Table
                        headers={['Organización', 'Motivo', 'Disponible']}
                        empty={
                            credits.length === 0 && 'Sin créditos disponibles.'
                        }
                    >
                        {credits.map((credit) => (
                            <tr key={credit.id}>
                                <td className="px-2 py-2">
                                    {credit.organization}
                                </td>
                                <td className="px-2 py-2">{credit.reason}</td>
                                <td className="px-2 py-2">
                                    {money(Number(credit.remaining))} de{' '}
                                    {money(Number(credit.amount))}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
