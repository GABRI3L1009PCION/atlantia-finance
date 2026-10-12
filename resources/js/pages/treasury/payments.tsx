import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    methodLabel,
    paymentTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    AccountOption,
    Payment,
    TreasuryContext,
    TreasuryPermissions,
} from '@/components/treasury/kit';

type Document = {
    id: number;
    document: string;
    supplier: string;
    due_on: string;
    total_cents: number;
    available_cents: number;
};
type Form = { accounts: AccountOption[]; documents: Document[] };

function PaymentForm({ companyId, form }: { companyId: number; form: Form }) {
    const [items, setItems] = useState<Record<number, string>>({});
    const data = useForm({
        company_id: companyId,
        bank_account_id: form.accounts[0] ? String(form.accounts[0].id) : '',
        method: 'transfer',
        concept: '',
        priority: 'normal',
        scheduled_on: '',
    });
    const total = Object.values(items).reduce(
        (sum, value) => sum + (Number(value) || 0),
        0,
    );

    return (
        <form
            className="grid gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                data.transform((values) => ({
                    ...values,
                    bank_account_id: values.bank_account_id || null,
                    scheduled_on: values.scheduled_on || null,
                    items: Object.entries(items)
                        .filter(([, value]) => Number(value) > 0)
                        .map(([id, value]) => ({
                            purchase_document_id: Number(id),
                            amount: value,
                        })),
                }));
                data.post('/treasury/payments');
            }}
        >
            <div className="grid gap-2 md:grid-cols-3 xl:grid-cols-5">
                <label className="grid gap-1 text-xs font-semibold">
                    Cuenta de origen
                    <select
                        className={input}
                        value={data.data.bank_account_id}
                        onChange={(event) =>
                            data.setData('bank_account_id', event.target.value)
                        }
                    >
                        <option value="">Definir al ejecutar</option>
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
                        {['transfer', 'check', 'ach', 'cash', 'card'].map(
                            (method) => (
                                <option key={method} value={method}>
                                    {methodLabel[method]}
                                </option>
                            ),
                        )}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Concepto
                    <input
                        className={input}
                        value={data.data.concept}
                        onChange={(event) =>
                            data.setData('concept', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Prioridad
                    <select
                        className={input}
                        value={data.data.priority}
                        onChange={(event) =>
                            data.setData('priority', event.target.value)
                        }
                    >
                        <option value="low">Baja</option>
                        <option value="normal">Normal</option>
                        <option value="high">Alta</option>
                        <option value="urgent">Urgente</option>
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Programar para (opcional)
                    <input
                        type="date"
                        className={input}
                        value={data.data.scheduled_on}
                        onChange={(event) =>
                            data.setData('scheduled_on', event.target.value)
                        }
                    />
                </label>
            </div>
            <Table
                headers={[
                    'Factura',
                    'Proveedor',
                    'Vence',
                    'Total',
                    'Disponible para pagar',
                    'Pagar (Q)',
                ]}
                empty={
                    form.documents.length === 0 &&
                    'La empresa no tiene compras aprobadas con saldo disponible.'
                }
            >
                {form.documents.map((document) => (
                    <tr key={document.id}>
                        <td className="px-2 py-2 font-bold">
                            {document.document}
                        </td>
                        <td className="px-2 py-2">{document.supplier}</td>
                        <td className="px-2 py-2">
                            {formatDate(document.due_on)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(document.total_cents)}
                        </td>
                        <td className="px-2 py-2 font-semibold">
                            {amount(document.available_cents)}
                        </td>
                        <td className="px-2 py-2">
                            <input
                                aria-label={`Pagar ${document.document}`}
                                className="h-8 w-28 rounded-md border border-slate-300 px-2 text-xs"
                                inputMode="decimal"
                                value={items[document.id] ?? ''}
                                onChange={(event) =>
                                    setItems({
                                        ...items,
                                        [document.id]: event.target.value,
                                    })
                                }
                            />
                        </td>
                    </tr>
                ))}
            </Table>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-[#526b92]">
                    Total Q {total.toFixed(2)} · un pago agrupa facturas de un
                    solo proveedor; las aprobaciones requeridas dependen del
                    monto según la política de la oficina.
                </p>
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={data.processing}
                >
                    Preparar pago
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

export default function TreasuryPayments({
    context,
    payments,
    filters,
    statuses,
    form,
    formCompanyId,
    permissions,
    notice,
}: {
    context: TreasuryContext;
    payments: Payment[];
    filters: { status?: string };
    statuses: Record<string, string>;
    form: Form | null;
    formCompanyId: number | null;
    permissions: TreasuryPermissions;
    notice: string | null;
}) {
    const href = useTreasuryHref();
    const status = filters.status ?? '';

    return (
        <>
            <Head title="Pagos · Tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Pagos"
                    subtitle="Tesorería prepara; otra persona aprueba. Atlantia no envía dinero: la ejecución se registra con la referencia y el comprobante del banco"
                />
                {permissions.canPreparePayments && form && formCompanyId && (
                    <Card
                        title="Preparar pago"
                        action={
                            <select
                                aria-label="Empresa"
                                className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                value={formCompanyId}
                                onChange={(event) =>
                                    router.get(
                                        href(
                                            `/treasury/payments?company_id=${event.target.value}`,
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
                        <PaymentForm
                            key={formCompanyId}
                            companyId={formCompanyId}
                            form={form}
                        />
                    </Card>
                )}
                <Card title={`${payments.length} pago(s)`}>
                    <div className="mb-3 flex flex-wrap gap-1.5">
                        {[
                            ['', 'Todos'],
                            ['open', 'Abiertos'],
                            ...Object.entries(statuses),
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    router.get(
                                        href('/treasury/payments'),
                                        key ? { status: key } : {},
                                        { preserveScroll: true },
                                    )
                                }
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${status === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <Table
                        headers={[
                            'Proveedor',
                            'Empresa',
                            'Monto',
                            'Método',
                            'Programado',
                            'Aprobaciones',
                            'Preparó',
                            'Estado',
                        ]}
                        empty={
                            payments.length === 0 &&
                            'Sin pagos con este filtro.'
                        }
                    >
                        {payments.map((payment) => (
                            <tr key={payment.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(payment.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {payment.supplier}
                                    </Link>
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {payment.concept}
                                        {payment.kind === 'advance' &&
                                            ' · Anticipo'}
                                        {payment.proposal &&
                                            ` · ${payment.proposal.reference}`}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{payment.company}</td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(payment.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {methodLabel[payment.method] ??
                                        payment.method}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(payment.scheduled_on)}
                                </td>
                                <td className="px-2 py-2">
                                    {payment.approvals} /{' '}
                                    {payment.required_approvals}
                                </td>
                                <td className="px-2 py-2">
                                    {payment.preparer ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            paymentTone[payment.status] ??
                                                'slate',
                                        )}
                                    >
                                        {payment.status_label}
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
