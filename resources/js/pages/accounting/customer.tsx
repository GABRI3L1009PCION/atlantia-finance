import { Link, useForm } from '@inertiajs/react';
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
    status: string;
    issued_on: string;
    due_on: string;
    total_cents: number;
    paid_cents: number;
};

type Receipt = {
    id: number;
    invoice_public_id: string;
    invoice_number: number | null;
    amount_cents: number;
    received_on: string;
    reference: string;
    voided_at: string | null;
    void_reason: string | null;
};

export default function Customer({
    company,
    customer,
    invoices,
    receipts,
    summary,
    canWrite,
    today,
}: {
    company: Company;
    customer: {
        id: number;
        name: string;
        tax_id: string | null;
        email: string | null;
    };
    invoices: Page<Invoice>;
    receipts: Page<Receipt>;
    summary: {
        invoiced: number;
        collected: number;
        outstanding: number;
        overdue: number;
    };
    canWrite: boolean;
    today: string;
}) {
    const edit = useForm({
        name: customer.name,
        tax_id: customer.tax_id || '',
        email: customer.email || '',
    });

    return (
        <AccountingSection
            company={company}
            title={`Cliente · ${customer.name}`}
        >
            <p className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Esta ficha administra comprobantes internos y cobros. No emite
                facturas FEL, no calcula impuestos y no contabiliza movimientos
                automáticamente.
            </p>
            <div className="flex flex-wrap gap-3">
                <Link
                    href={routes.sales(company.public_id)}
                    className={secondary}
                >
                    Volver a facturación
                </Link>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    ['Emitido', summary.invoiced],
                    ['Cobrado', summary.collected],
                    ['Saldo pendiente', summary.outstanding],
                    ['Saldo vencido', summary.overdue],
                ].map(([label, value]) => (
                    <div
                        key={String(label)}
                        className="rounded-2xl border border-[#ece3e3] bg-white p-5 shadow-sm"
                    >
                        <p className="text-sm text-slate-500">{label}</p>
                        <p className="mt-2 text-2xl font-semibold text-[#640000]">
                            {money(value)}
                        </p>
                    </div>
                ))}
            </div>
            <Card title="Datos del cliente">
                {canWrite ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            edit.patch(
                                routes.salesCustomerUpdate.url([
                                    company.public_id,
                                    customer.id,
                                ]),
                                { preserveScroll: true },
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
                                    value={edit.data.name}
                                    onChange={(event) =>
                                        edit.setData('name', event.target.value)
                                    }
                                />
                            </label>
                            <label>
                                NIT / identificador
                                <input
                                    maxLength={30}
                                    className={field}
                                    value={edit.data.tax_id}
                                    onChange={(event) =>
                                        edit.setData(
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
                                    value={edit.data.email}
                                    onChange={(event) =>
                                        edit.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        <Errors errors={edit.errors} />
                        <button
                            className={`${primary} mt-4`}
                            disabled={edit.processing}
                        >
                            Guardar cambios
                        </button>
                        <p className="mt-3 text-sm text-slate-500">
                            Los comprobantes anteriores conservan el nombre y
                            NIT con los que fueron creados.
                        </p>
                    </form>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <p>
                            <strong>Nombre:</strong> {customer.name}
                        </p>
                        <p>
                            <strong>NIT:</strong>{' '}
                            {customer.tax_id || 'No indicado'}
                        </p>
                        <p>
                            <strong>Correo:</strong>{' '}
                            {customer.email || 'No indicado'}
                        </p>
                    </div>
                )}
            </Card>
            <Card title="Comprobantes del cliente">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-[#faf4f4]">
                            <tr>
                                <th className="p-3">Documento</th>
                                <th className="p-3">Emisión / vencimiento</th>
                                <th className="p-3">Estado</th>
                                <th className="p-3">Total</th>
                                <th className="p-3">Saldo</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.map((invoice) => {
                                const balance =
                                    Number(invoice.total_cents) -
                                    Number(invoice.paid_cents);
                                const state =
                                    invoice.status === 'void'
                                        ? 'Anulado'
                                        : invoice.status === 'draft'
                                          ? 'Borrador'
                                          : balance === 0
                                            ? 'Pagado'
                                            : invoice.due_on < today
                                              ? 'Vencido'
                                              : Number(invoice.paid_cents) > 0
                                                ? 'Cobro parcial'
                                                : 'Pendiente';

                                return (
                                    <tr
                                        key={invoice.public_id}
                                        className="border-b border-slate-100"
                                    >
                                        <td className="p-3 font-semibold">
                                            {invoice.number
                                                ? `INT-${invoice.number}`
                                                : 'Borrador'}
                                        </td>
                                        <td className="p-3">
                                            {invoice.issued_on} ·{' '}
                                            {invoice.due_on}
                                        </td>
                                        <td
                                            className={`p-3 ${state === 'Vencido' ? 'font-semibold text-red-700' : ''}`}
                                        >
                                            {state}
                                        </td>
                                        <td className="p-3">
                                            {money(invoice.total_cents)}
                                        </td>
                                        <td className="p-3">
                                            {invoice.status === 'void'
                                                ? '—'
                                                : money(balance)}
                                        </td>
                                        <td className="p-3">
                                            <Link
                                                className={secondary}
                                                href={routes.invoice([
                                                    company.public_id,
                                                    invoice.public_id,
                                                ])}
                                            >
                                                Consultar
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                {!invoices.data.length && (
                    <p className="py-5 text-slate-500">
                        Este cliente todavía no tiene comprobantes.
                    </p>
                )}
                <Pager page={invoices} />
            </Card>
            <Card title="Historial de cobros del cliente">
                <div className="space-y-3">
                    {receipts.data.map((receipt) => (
                        <div
                            key={receipt.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"
                        >
                            <div>
                                <p
                                    className={
                                        receipt.voided_at ? 'line-through' : ''
                                    }
                                >
                                    <strong>
                                        {money(receipt.amount_cents)}
                                    </strong>{' '}
                                    · {receipt.received_on}
                                </p>
                                <p className="mt-1 text-sm text-slate-500">
                                    {receipt.reference} ·{' '}
                                    {receipt.invoice_number
                                        ? `INT-${receipt.invoice_number}`
                                        : 'Borrador'}
                                </p>
                                {receipt.voided_at && (
                                    <p className="mt-1 text-sm text-red-700">
                                        Anulado: {receipt.void_reason}
                                    </p>
                                )}
                            </div>
                            <Link
                                className={secondary}
                                href={routes.invoice([
                                    company.public_id,
                                    receipt.invoice_public_id,
                                ])}
                            >
                                Ver comprobante
                            </Link>
                        </div>
                    ))}
                    {!receipts.data.length && (
                        <p className="text-slate-500">
                            Todavía no se han registrado cobros para este
                            cliente.
                        </p>
                    )}
                </div>
                <Pager page={receipts} />
            </Card>
        </AccountingSection>
    );
}
