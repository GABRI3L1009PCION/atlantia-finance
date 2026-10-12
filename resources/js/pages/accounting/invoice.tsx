import { useForm } from '@inertiajs/react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type Receipt = {
    id: number;
    amount_cents: number;
    received_on: string;
    reference: string;
    voided_at: string | null;
    void_reason: string | null;
};
function ReceiptRow({
    receipt: r,
    company,
    invoice,
    owner,
}: {
    receipt: Receipt;
    company: string;
    invoice: string;
    owner: boolean;
}) {
    const form = useForm({ reason: '' });

    return (
        <div className="rounded-xl border border-slate-200 p-4">
            <div className="flex flex-wrap justify-between gap-3">
                <div>
                    <strong>{money(r.amount_cents)}</strong>
                    <p className="mt-1 text-sm text-slate-500">
                        {r.received_on} · {r.reference}
                    </p>
                </div>
                <span
                    className={
                        r.voided_at ? 'text-red-700' : 'text-emerald-700'
                    }
                >
                    {r.voided_at ? 'Anulado' : 'Vigente'}
                </span>
            </div>
            {r.voided_at && (
                <p className="mt-2 text-sm">Motivo: {r.void_reason}</p>
            )}
            {owner && !r.voided_at && (
                <details className="mt-3 text-sm">
                    <summary className="cursor-pointer text-[#640000]">
                        Corregir mediante anulación
                    </summary>
                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                routes.voidReceipt.url([
                                    company,
                                    invoice,
                                    r.id,
                                ]),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <label className="flex-1">
                            Motivo obligatorio
                            <input
                                required
                                maxLength={500}
                                className={field}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                            />
                        </label>
                        <button
                            className={secondary}
                            disabled={form.processing}
                        >
                            Anular cobro
                        </button>
                        <Errors errors={form.errors} />
                    </form>
                </details>
            )}
        </div>
    );
}
type CreditNote = {
    id: number;
    number: number;
    issued_on: string;
    amount_cents: number;
    reason: string;
    status: string;
    void_reason: string | null;
};
function CreditNoteRow({
    note,
    company,
    invoice,
    owner,
}: {
    note: CreditNote;
    company: string;
    invoice: string;
    owner: boolean;
}) {
    const form = useForm({ reason: '' });

    return (
        <div className="rounded-xl border border-slate-200 p-4">
            <div className="flex flex-wrap justify-between gap-3">
                <div>
                    <strong>
                        NC {note.number} · {money(note.amount_cents)}
                    </strong>
                    <p className="mt-1 text-sm text-slate-500">
                        {note.issued_on} · {note.reason}
                    </p>
                </div>
                <span
                    className={
                        note.status === 'void'
                            ? 'text-red-700'
                            : 'text-emerald-700'
                    }
                >
                    {note.status === 'void' ? 'Anulada' : 'Emitida'}
                </span>
            </div>
            {note.void_reason && (
                <p className="mt-2 text-sm">Motivo: {note.void_reason}</p>
            )}
            {owner && note.status === 'issued' && (
                <details className="mt-3 text-sm">
                    <summary className="cursor-pointer text-[#640000]">
                        Anular nota de crédito
                    </summary>
                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                routes.voidCreditNote.url([
                                    company,
                                    invoice,
                                    note.id,
                                ]),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <label className="flex-1">
                            Motivo obligatorio
                            <input
                                required
                                minLength={5}
                                maxLength={500}
                                className={field}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                            />
                        </label>
                        <button
                            className={secondary}
                            disabled={form.processing}
                        >
                            Anular
                        </button>
                        <Errors errors={form.errors} />
                    </form>
                </details>
            )}
        </div>
    );
}
type Props = {
    company: Company;
    invoice: {
        public_id: string;
        number: number | null;
        customer_name: string;
        customer_tax_id: string | null;
        status: string;
        total_cents: number;
        issued_on: string;
        due_on: string;
        void_reason: string | null;
        currency: string;
        exchange_rate: string;
        foreign_total_cents: number | null;
    };
    lines: {
        id: number;
        description: string;
        quantity: number;
        unit_cents: number;
    }[];
    receipts: Receipt[];
    creditNotes: CreditNote[];
    credited: number;
    canCredit: boolean;
    paid: number;
    isOwner: boolean;
    canWrite: boolean;
    today: string;
};
export default function Invoice({
    company,
    invoice: i,
    lines,
    receipts,
    creditNotes,
    credited,
    canCredit,
    paid,
    isOwner,
    canWrite,
    today,
}: Props) {
    const process = useForm({ action: 'issue', reason: '' });
    const cancel = useForm({ action: 'void', reason: '' });
    const receipt = useForm({
        request_id: crypto.randomUUID(),
        amount: '',
        received_on: today,
        reference: '',
    });
    const credit = useForm({ amount: '', issued_on: today, reason: '' });
    const remaining = Number(i.total_cents) - paid - credited;

    return (
        <AccountingSection
            company={company}
            title={`Comprobante interno ${i.number ? `INT-${i.number}` : '· Borrador'}`}
        >
            <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                No es factura FEL. Sin cálculo de impuestos ni contabilización
                automática. Registra los asientos correspondientes en «Libros y
                cuentas».
            </p>
            <Card title={i.customer_name}>
                <div className="flex flex-wrap justify-between gap-4">
                    <p className="text-sm text-slate-500">
                        NIT {i.customer_tax_id || 'No indicado'} · Emisión{' '}
                        {i.issued_on} · Vence {i.due_on}
                    </p>
                    <a
                        className={secondary}
                        href={routes.invoicePdf.url([
                            company.public_id,
                            i.public_id,
                        ])}
                    >
                        Descargar PDF
                    </a>
                </div>
                <p className="mt-3 font-semibold text-[#640000]">
                    {i.status === 'void'
                        ? 'ANULADO'
                        : i.status === 'draft'
                          ? 'BORRADOR — pendiente de aprobación'
                          : remaining === 0
                            ? 'PAGADO'
                            : i.due_on < today
                              ? 'VENCIDO — saldo pendiente'
                              : paid > 0
                                ? 'COBRO PARCIAL'
                                : 'EMITIDO — pendiente de cobro'}
                </p>
                {i.void_reason && (
                    <p className="mt-2">Motivo: {i.void_reason}</p>
                )}
                {i.currency !== 'GTQ' && i.foreign_total_cents !== null && (
                    <p className="mt-2 text-sm text-slate-600">
                        Documento en {i.currency}: {i.currency}{' '}
                        {(i.foreign_total_cents / 100).toLocaleString('es-GT', {
                            minimumFractionDigits: 2,
                        })}{' '}
                        al tipo de cambio {Number(i.exchange_rate)} registrado.
                        Los montos se muestran en GTQ.
                    </p>
                )}
                <div className="mt-5 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-[#faf4f4]">
                            <tr>
                                {[
                                    'Concepto',
                                    'Cantidad',
                                    'Precio unitario',
                                    'Importe',
                                ].map((h) => (
                                    <th key={h} className="p-3">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((l) => (
                                <tr
                                    key={l.id}
                                    className="border-b border-slate-100"
                                >
                                    <td className="p-3">{l.description}</td>
                                    <td className="p-3">{l.quantity}</td>
                                    <td className="p-3">
                                        {money(l.unit_cents)}
                                    </td>
                                    <td className="p-3">
                                        {money(l.quantity * l.unit_cents)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="mt-6 grid gap-4 sm:grid-cols-4">
                    {[
                        ['Total', Number(i.total_cents)],
                        ['Cobrado', paid],
                        ['Notas de crédito y ajustes', credited],
                        [
                            'Saldo pendiente',
                            i.status === 'void' ? 0 : remaining,
                        ],
                    ].map(([label, value]) => (
                        <div
                            key={String(label)}
                            className="rounded-xl bg-[#faf8f7] p-4"
                        >
                            <p className="text-sm text-slate-500">{label}</p>
                            <p className="mt-2 text-2xl font-semibold">
                                {money(value)}
                            </p>
                        </div>
                    ))}
                </div>
                <Errors errors={process.errors} />
                {isOwner && i.status === 'draft' && (
                    <form
                        className="mt-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            process.post(
                                routes.invoiceProcess.url([
                                    company.public_id,
                                    i.public_id,
                                ]),
                            );
                        }}
                    >
                        <p className="mb-3 text-sm text-slate-500">
                            Al emitir, los conceptos e importes quedan fijos. Si
                            detectas errores, anula el borrador y crea uno
                            nuevo.
                        </p>
                        <button
                            className={primary}
                            disabled={process.processing}
                        >
                            Aprobar y emitir comprobante
                        </button>
                    </form>
                )}
            </Card>
            {canWrite && i.status === 'issued' && remaining > 0 && (
                <Card title="Registrar cobro recibido">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            receipt.post(
                                routes.receipt.url([
                                    company.public_id,
                                    i.public_id,
                                ]),
                                {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        receipt.setData({
                                            request_id: crypto.randomUUID(),
                                            amount: '',
                                            received_on: today,
                                            reference: '',
                                        }),
                                },
                            );
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label>
                                Importe Q
                                <input
                                    required
                                    type="number"
                                    min="0.01"
                                    max={remaining / 100}
                                    step="0.01"
                                    className={field}
                                    value={receipt.data.amount}
                                    onChange={(e) =>
                                        receipt.setData(
                                            'amount',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Fecha recibida
                                <input
                                    required
                                    type="date"
                                    min={i.issued_on}
                                    max={today}
                                    className={field}
                                    value={receipt.data.received_on}
                                    onChange={(e) =>
                                        receipt.setData(
                                            'received_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Referencia / medio de cobro
                                <input
                                    required
                                    maxLength={150}
                                    placeholder="Transferencia, referencia 123"
                                    className={field}
                                    value={receipt.data.reference}
                                    onChange={(e) =>
                                        receipt.setData(
                                            'reference',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        <Errors errors={receipt.errors} />
                        <button
                            className={`${primary} mt-4`}
                            disabled={receipt.processing}
                        >
                            Registrar cobro
                        </button>
                    </form>
                </Card>
            )}
            <Card title="Historial de cobros">
                <div className="space-y-3">
                    {receipts.map((r) => (
                        <ReceiptRow
                            key={r.id}
                            receipt={r}
                            company={company.public_id}
                            invoice={i.public_id}
                            owner={isOwner}
                        />
                    ))}
                    {!receipts.length && (
                        <p className="text-slate-500">
                            Todavía no se han registrado cobros.
                        </p>
                    )}
                </div>
            </Card>
            {canCredit && i.status === 'issued' && remaining > 0 && (
                <Card title="Emitir nota de crédito">
                    <p className="mb-3 text-sm text-slate-500">
                        Reduce el saldo por cobrar y revierte ingreso e IVA en
                        proporción; el asiento queda en borrador para revisión.
                    </p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            credit.post(
                                routes.creditNote.url([
                                    company.public_id,
                                    i.public_id,
                                ]),
                                {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        credit.reset('amount', 'reason'),
                                },
                            );
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label>
                                Importe Q
                                <input
                                    required
                                    type="number"
                                    min="0.01"
                                    max={remaining / 100}
                                    step="0.01"
                                    className={field}
                                    value={credit.data.amount}
                                    onChange={(e) =>
                                        credit.setData('amount', e.target.value)
                                    }
                                />
                            </label>
                            <label>
                                Fecha
                                <input
                                    required
                                    type="date"
                                    min={i.issued_on}
                                    max={today}
                                    className={field}
                                    value={credit.data.issued_on}
                                    onChange={(e) =>
                                        credit.setData(
                                            'issued_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Motivo
                                <input
                                    required
                                    minLength={5}
                                    maxLength={500}
                                    className={field}
                                    value={credit.data.reason}
                                    onChange={(e) =>
                                        credit.setData('reason', e.target.value)
                                    }
                                />
                            </label>
                        </div>
                        <Errors errors={credit.errors} />
                        <button
                            className={`${secondary} mt-4`}
                            disabled={credit.processing}
                        >
                            Emitir nota de crédito
                        </button>
                    </form>
                </Card>
            )}
            {creditNotes.length > 0 && (
                <Card title="Notas de crédito">
                    <div className="space-y-3">
                        {creditNotes.map((note) => (
                            <CreditNoteRow
                                key={note.id}
                                note={note}
                                company={company.public_id}
                                invoice={i.public_id}
                                owner={isOwner}
                            />
                        ))}
                    </div>
                </Card>
            )}
            {isOwner && i.status !== 'void' && paid === 0 && credited === 0 && (
                <Card title="Anular comprobante">
                    <p className="mb-3 text-sm text-slate-500">
                        Se conserva el documento y el motivo en el historial.
                        Esta acción no elimina registros.
                    </p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            cancel.post(
                                routes.invoiceProcess.url([
                                    company.public_id,
                                    i.public_id,
                                ]),
                            );
                        }}
                    >
                        <label>
                            Motivo
                            <input
                                required
                                maxLength={500}
                                className={field}
                                value={cancel.data.reason}
                                onChange={(e) =>
                                    cancel.setData('reason', e.target.value)
                                }
                            />
                        </label>
                        <Errors errors={cancel.errors} />
                        <button
                            className={`${secondary} mt-3`}
                            disabled={cancel.processing}
                        >
                            Anular y conservar historial
                        </button>
                    </form>
                </Card>
            )}
        </AccountingSection>
    );
}
