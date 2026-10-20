import { useForm } from '@inertiajs/react';
import { BadgeDollarSign, FileClock, ReceiptText, Wallet } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import { input, primaryButton } from '@/components/office-admin/kit';
import { money } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Invoice = {
    id: number;
    number: string;
    concept: string;
    issued_on: string;
    due_on: string;
    paid_on: string | null;
    amount_cents: number;
    paid_cents: number;
    balance_cents: number;
    status: string;
    reported_cents: number;
};

type Notice = {
    id: number;
    invoice_id: number;
    amount_cents: number;
    paid_on: string;
    method: string;
    reference: string;
    note: string | null;
    status: string;
    review_note: string | null;
    created_at: string;
};

type Account = {
    invoices: Invoice[];
    totals: {
        billed_cents: number;
        paid_cents: number;
        balance_cents: number;
        overdue_cents: number;
        open_count: number;
    };
    contract: {
        services: string;
        monthly_fee_cents: number;
        renews_on: string | null;
        status: string;
    } | null;
    notices: Notice[];
    methods: Record<string, string>;
};

const invoiceStatus: Record<string, [string, string]> = {
    pending: ['Pendiente', 'amber'],
    overdue: ['Vencida', 'red'],
    paid: ['Pagada', 'green'],
    void: ['Anulada', 'slate'],
};

const noticeStatus: Record<string, [string, string]> = {
    pending: ['En revisión', 'blue'],
    confirmed: ['Confirmado', 'green'],
    rejected: ['No confirmado', 'red'],
};

function ReportPaymentDialog({
    invoice,
    methods,
    onClose,
}: {
    invoice: Invoice | null;
    methods: Record<string, string>;
    onClose: () => void;
}) {
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({
        invoice_id: 0,
        amount: '',
        paid_on: today,
        method: 'transfer',
        reference: '',
        note: '',
    });

    const available = invoice
        ? (invoice.balance_cents - invoice.reported_cents) / 100
        : 0;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            invoice_id: invoice?.id ?? 0,
        }));
        form.post('/client/account/payments', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    }

    return (
        <Dialog
            open={invoice !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>Informar un pago</DialogTitle>
                <DialogDescription>
                    {invoice &&
                        `Cobro ${invoice.number} · saldo disponible para informar ${money(Math.round(available * 100))}. Tu oficina lo confirmará cuando lo vea reflejado en su banco.`}
                </DialogDescription>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Monto (Q)
                        <input
                            className={`${input} mt-1`}
                            inputMode="decimal"
                            required
                            placeholder={available.toFixed(2)}
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                        {form.errors.amount && (
                            <span className="text-[11px] font-normal text-red-700 normal-case">
                                {form.errors.amount}
                            </span>
                        )}
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Fecha del pago
                        <input
                            type="date"
                            className={`${input} mt-1`}
                            max={today}
                            required
                            value={form.data.paid_on}
                            onChange={(event) =>
                                form.setData('paid_on', event.target.value)
                            }
                        />
                        {form.errors.paid_on && (
                            <span className="text-[11px] font-normal text-red-700 normal-case">
                                {form.errors.paid_on}
                            </span>
                        )}
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Forma de pago
                        <select
                            className={`${input} mt-1`}
                            value={form.data.method}
                            onChange={(event) =>
                                form.setData('method', event.target.value)
                            }
                        >
                            {Object.entries(methods).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Referencia o boleta
                        <input
                            className={`${input} mt-1`}
                            required
                            minLength={3}
                            maxLength={120}
                            value={form.data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                        />
                        {form.errors.reference && (
                            <span className="text-[11px] font-normal text-red-700 normal-case">
                                {form.errors.reference}
                            </span>
                        )}
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase sm:col-span-2">
                        Nota (opcional)
                        <textarea
                            className="mt-1 min-h-16 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm normal-case outline-none focus:border-[#8a5b32]"
                            maxLength={500}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </label>
                    {form.errors.invoice_id && (
                        <p className="text-sm font-semibold text-red-700 sm:col-span-2">
                            {form.errors.invoice_id}
                        </p>
                    )}
                    <div className="flex justify-end gap-2 border-t border-slate-100 pt-3 sm:col-span-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-bold"
                        >
                            Cancelar
                        </button>
                        <button
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Informar pago
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ClientAccount({
    account,
    permissions,
}: {
    account: Account;
    permissions: Record<string, boolean>;
}) {
    const [reporting, setReporting] = useState<Invoice | null>(null);
    const canReport = permissions['client.account.report_payment'] === true;
    const { totals } = account;

    return (
        <ClientPage
            title="Cuenta con tu oficina"
            subtitle="Los honorarios que tu oficina contable te cobra, lo que has pagado y lo que está pendiente"
        >
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    [
                        'Total cobrado',
                        totals.billed_cents,
                        ReceiptText,
                        'text-[#640000]',
                    ],
                    [
                        'Pagado',
                        totals.paid_cents,
                        BadgeDollarSign,
                        'text-emerald-600',
                    ],
                    [
                        'Saldo pendiente',
                        totals.balance_cents,
                        Wallet,
                        'text-amber-600',
                    ],
                    [
                        'Vencido',
                        totals.overdue_cents,
                        FileClock,
                        totals.overdue_cents > 0
                            ? 'text-red-600'
                            : 'text-slate-400',
                    ],
                ].map(([label, value, Icon, tone]) => {
                    const IconComponent = Icon as typeof Wallet;

                    return (
                        <div
                            key={String(label)}
                            className="rounded-xl border border-[#ebe5e1] bg-white p-3 shadow-sm"
                        >
                            <div className="flex items-center justify-between">
                                <p className="text-xs font-bold tracking-wide text-[#526b92] uppercase">
                                    {String(label)}
                                </p>
                                <IconComponent
                                    className={`size-5 ${String(tone)}`}
                                />
                            </div>
                            <p className="mt-2 text-2xl font-black text-[#061633] tabular-nums">
                                {money(Number(value))}
                            </p>
                        </div>
                    );
                })}
            </section>

            {account.contract && (
                <Card title="Tu contrato de servicios">
                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-xs font-bold text-[#526b92] uppercase">
                                Servicios
                            </dt>
                            <dd className="font-bold">
                                {account.contract.services}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-bold text-[#526b92] uppercase">
                                Honorario mensual
                            </dt>
                            <dd className="font-black tabular-nums">
                                {money(account.contract.monthly_fee_cents)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-bold text-[#526b92] uppercase">
                                Renovación
                            </dt>
                            <dd className="font-bold">
                                {formatDate(account.contract.renews_on)}
                            </dd>
                        </div>
                    </dl>
                </Card>
            )}

            <Card title={`Cobros (${account.invoices.length})`}>
                <Table
                    headers={[
                        'Cobro',
                        'Concepto',
                        'Emitido',
                        'Vence',
                        'Monto',
                        'Saldo',
                        'Estado',
                        '',
                    ]}
                    empty={
                        account.invoices.length === 0 &&
                        'Tu oficina aún no te ha registrado cobros.'
                    }
                >
                    {account.invoices.map((invoice) => {
                        const [label, tone] = invoiceStatus[invoice.status] ?? [
                            invoice.status,
                            'slate',
                        ];
                        const canPay =
                            canReport &&
                            ['pending', 'overdue'].includes(invoice.status) &&
                            invoice.balance_cents - invoice.reported_cents > 0;

                        return (
                            <tr key={invoice.id}>
                                <td className="px-2 py-2 font-bold whitespace-nowrap">
                                    {invoice.number}
                                </td>
                                <td className="px-2 py-2">{invoice.concept}</td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(invoice.issued_on)}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(invoice.due_on)}
                                </td>
                                <td className="px-2 py-2 text-right tabular-nums">
                                    {money(invoice.amount_cents)}
                                </td>
                                <td className="px-2 py-2 text-right font-black tabular-nums">
                                    {money(invoice.balance_cents)}
                                    {invoice.reported_cents > 0 && (
                                        <span className="block text-[11px] font-normal text-blue-700">
                                            {money(invoice.reported_cents)} en
                                            revisión
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <span className={toneBadge(tone)}>
                                        {label}
                                    </span>
                                </td>
                                <td className="px-2 py-2 text-right">
                                    {canPay && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setReporting(invoice)
                                            }
                                            className="rounded-lg bg-[#640000] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#7a0a0a]"
                                        >
                                            Informar pago
                                        </button>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>

            {account.notices.length > 0 && (
                <Card title="Pagos que has informado">
                    <Table
                        headers={[
                            'Fecha',
                            'Cobro',
                            'Monto',
                            'Forma',
                            'Referencia',
                            'Estado',
                            'Respuesta de tu oficina',
                        ]}
                    >
                        {account.notices.map((notice) => {
                            const [label, tone] = noticeStatus[
                                notice.status
                            ] ?? [notice.status, 'slate'];
                            const invoice = account.invoices.find(
                                (row) => row.id === notice.invoice_id,
                            );

                            return (
                                <tr key={notice.id}>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {formatDate(notice.paid_on)}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {invoice?.number ?? '—'}
                                    </td>
                                    <td className="px-2 py-2 text-right tabular-nums">
                                        {money(notice.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {notice.method}
                                    </td>
                                    <td className="px-2 py-2">
                                        {notice.reference}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {notice.review_note ?? '—'}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            )}

            <ReportPaymentDialog
                invoice={reporting}
                methods={account.methods}
                onClose={() => setReporting(null)}
            />
        </ClientPage>
    );
}
