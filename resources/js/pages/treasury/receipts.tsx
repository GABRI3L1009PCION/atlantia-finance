import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    methodLabel,
    receiptTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    AccountOption,
    Receipt,
    TreasuryContext,
    TreasuryPermissions,
} from '@/components/treasury/kit';

type Invoice = {
    id: number;
    number: string;
    customer: string;
    issued_on: string;
    due_on: string;
    total_cents: number;
    balance_cents: number;
};
type Deposit = {
    id: number;
    account_id: number;
    date: string;
    reference: string;
    amount_cents: number;
};
type Form = {
    accounts: AccountOption[];
    invoices: Invoice[];
    deposits: Deposit[];
};

function ReceiptForm({ companyId, form }: { companyId: number; form: Form }) {
    const [allocations, setAllocations] = useState<Record<number, string>>({});
    const data = useForm({
        company_id: companyId,
        bank_account_id: form.accounts[0] ? String(form.accounts[0].id) : '',
        method: 'transfer',
        amount: '',
        received_on: new Date().toISOString().slice(0, 10),
        reference: '',
        bank_transaction_id: '',
    });
    const applied = Object.values(allocations).reduce(
        (sum, value) => sum + (Number(value) || 0),
        0,
    );
    const remaining = (Number(data.data.amount) || 0) - applied;

    return (
        <form
            className="grid gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                data.transform((values) => ({
                    ...values,
                    bank_account_id: values.bank_account_id || null,
                    bank_transaction_id: values.bank_transaction_id || null,
                    allocations: Object.entries(allocations)
                        .filter(([, value]) => Number(value) > 0)
                        .map(([id, value]) => ({
                            sales_invoice_id: Number(id),
                            amount: value,
                        })),
                }));
                data.post('/treasury/receipts', {
                    preserveScroll: true,
                    onSuccess: () => {
                        data.reset(
                            'amount',
                            'reference',
                            'bank_transaction_id',
                        );
                        setAllocations({});
                    },
                });
            }}
        >
            <div className="grid gap-2 md:grid-cols-3 xl:grid-cols-6">
                <label className="grid gap-1 text-xs font-semibold">
                    Cuenta
                    <select
                        className={input}
                        value={data.data.bank_account_id}
                        onChange={(event) =>
                            data.setData('bank_account_id', event.target.value)
                        }
                    >
                        <option value="">Caja (efectivo)</option>
                        {form.accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Método
                    <select
                        className={input}
                        value={data.data.method}
                        onChange={(event) =>
                            data.setData('method', event.target.value)
                        }
                    >
                        {['transfer', 'deposit', 'check', 'cash', 'card'].map(
                            (method) => (
                                <option key={method} value={method}>
                                    {methodLabel[method]}
                                </option>
                            ),
                        )}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Monto recibido (Q)
                    <input
                        className={input}
                        inputMode="decimal"
                        value={data.data.amount}
                        onChange={(event) =>
                            data.setData('amount', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Fecha
                    <input
                        type="date"
                        className={input}
                        value={data.data.received_on}
                        onChange={(event) =>
                            data.setData('received_on', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Referencia
                    <input
                        className={input}
                        value={data.data.reference}
                        onChange={(event) =>
                            data.setData('reference', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Depósito del banco (opcional)
                    <select
                        className={input}
                        value={data.data.bank_transaction_id}
                        onChange={(event) =>
                            data.setData(
                                'bank_transaction_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">Sin vincular</option>
                        {form.deposits.map((deposit) => (
                            <option key={deposit.id} value={deposit.id}>
                                {formatDate(deposit.date)} · {deposit.reference}{' '}
                                · {amount(deposit.amount_cents)}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <Table
                headers={[
                    'Factura',
                    'Cliente',
                    'Vence',
                    'Total',
                    'Saldo',
                    'Aplicar (Q)',
                ]}
                empty={
                    form.invoices.length === 0 &&
                    'La empresa no tiene facturas emitidas con saldo.'
                }
            >
                {form.invoices.map((invoice) => (
                    <tr key={invoice.id}>
                        <td className="px-2 py-2 font-bold">
                            #{invoice.number}
                        </td>
                        <td className="px-2 py-2">{invoice.customer}</td>
                        <td className="px-2 py-2">
                            {formatDate(invoice.due_on)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(invoice.total_cents)}
                        </td>
                        <td className="px-2 py-2 font-semibold">
                            {amount(invoice.balance_cents)}
                        </td>
                        <td className="px-2 py-2">
                            <input
                                aria-label={`Aplicar a factura ${invoice.number}`}
                                className="h-8 w-28 rounded-md border border-slate-300 px-2 text-xs"
                                inputMode="decimal"
                                value={allocations[invoice.id] ?? ''}
                                onChange={(event) =>
                                    setAllocations({
                                        ...allocations,
                                        [invoice.id]: event.target.value,
                                    })
                                }
                            />
                        </td>
                    </tr>
                ))}
            </Table>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-[#526b92]">
                    Aplicado Q {applied.toFixed(2)} · Sin aplicar{' '}
                    <b className={remaining > 0 ? 'text-amber-700' : ''}>
                        Q {remaining.toFixed(2)}
                    </b>{' '}
                    (un excedente queda visible como saldo sin aplicar, nunca se
                    altera la factura)
                </p>
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={data.processing}
                >
                    Registrar cobro
                </button>
            </div>
            {Object.values(data.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(data.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

function AllocateRow({
    receipt,
    invoices,
}: {
    receipt: Receipt;
    invoices: Invoice[];
}) {
    const [invoice, setInvoice] = useState('');
    const [value, setValue] = useState('');

    return (
        <div className="flex gap-1">
            <select
                aria-label="Factura"
                className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                value={invoice}
                onChange={(event) => setInvoice(event.target.value)}
            >
                <option value="">Factura…</option>
                {invoices.map((row) => (
                    <option key={row.id} value={row.id}>
                        #{row.number} · {row.customer} ·{' '}
                        {amount(row.balance_cents)}
                    </option>
                ))}
            </select>
            <input
                aria-label="Monto a aplicar"
                className="h-8 w-24 rounded-md border border-slate-300 px-2 text-xs"
                inputMode="decimal"
                value={value}
                onChange={(event) => setValue(event.target.value)}
            />
            <button
                type="button"
                className={secondaryButton}
                disabled={invoice === '' || !(Number(value) > 0)}
                onClick={() =>
                    router.post(
                        `/treasury/receipts/${receipt.id}/allocate`,
                        {
                            allocations: [
                                {
                                    sales_invoice_id: Number(invoice),
                                    amount: value,
                                },
                            ],
                        },
                        { preserveScroll: true },
                    )
                }
            >
                Aplicar
            </button>
        </div>
    );
}

export default function TreasuryReceipts({
    context,
    receipts,
    form,
    formCompanyId,
    permissions,
    notice,
}: {
    context: TreasuryContext;
    receipts: Receipt[];
    filters: { status?: string };
    form: Form | null;
    formCompanyId: number | null;
    permissions: TreasuryPermissions;
    notice: string | null;
}) {
    const href = useTreasuryHref();

    return (
        <>
            <Head title="Cobros · Tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Cobros"
                    subtitle="Un cobro puede aplicarse a varias facturas del mismo cliente, parcial o totalmente; los asientos quedan en borrador para revisión contable"
                />
                {permissions.canReceive && form && formCompanyId && (
                    <Card
                        title="Registrar cobro"
                        action={
                            <select
                                aria-label="Empresa"
                                className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                value={formCompanyId}
                                onChange={(event) =>
                                    router.get(
                                        href(
                                            `/treasury/receipts?company_id=${event.target.value}`,
                                        ),
                                    )
                                }
                            >
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                        }
                    >
                        <ReceiptForm
                            key={formCompanyId}
                            companyId={formCompanyId}
                            form={form}
                        />
                    </Card>
                )}
                <Card title={`${receipts.length} cobro(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Cliente',
                            'Cuenta',
                            'Referencia',
                            'Recibido',
                            'Aplicado',
                            'Sin aplicar',
                            'Facturas',
                            'Estado',
                        ]}
                        empty={
                            receipts.length === 0 && 'Sin cobros registrados.'
                        }
                    >
                        {receipts.map((receipt) => (
                            <tr key={receipt.id} className="align-top">
                                <td className="px-2 py-2">
                                    {formatDate(receipt.received_on)}
                                </td>
                                <td className="px-2 py-2">{receipt.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    {receipt.customer}
                                </td>
                                <td className="px-2 py-2">
                                    {receipt.account}
                                    <p className="text-[11px] text-[#526b92]">
                                        {methodLabel[receipt.method] ??
                                            receipt.method}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {receipt.reference}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(receipt.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(receipt.applied_cents)}
                                </td>
                                <td
                                    className={`px-2 py-2 ${receipt.unapplied_cents > 0 ? 'font-bold text-amber-700' : ''}`}
                                >
                                    {amount(receipt.unapplied_cents)}
                                    {receipt.unapplied_cents > 0 &&
                                        permissions.canReceive &&
                                        form &&
                                        receipt.company_id ===
                                            formCompanyId && (
                                            <AllocateRow
                                                receipt={receipt}
                                                invoices={form.invoices}
                                            />
                                        )}
                                </td>
                                <td className="px-2 py-2">
                                    {receipt.invoices
                                        .map(
                                            (invoice) =>
                                                `#${invoice.number} ${amount(invoice.amount_cents)}`,
                                        )
                                        .join(', ') || '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            receiptTone[receipt.status] ??
                                                'slate',
                                        )}
                                    >
                                        {receipt.status_label}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
