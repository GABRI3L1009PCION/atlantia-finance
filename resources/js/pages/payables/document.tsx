import { Head, Link, router, useForm } from '@inertiajs/react';
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
import {
    amount,
    documentTone,
    dueLabel,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    Advance,
    CreditNote,
    Payable,
    PayableDispute,
    PayablesContext,
    PayablesPermissions,
} from '@/components/payables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    PayableAdjustmentForm,
    PayableAdjustmentsTable,
} from '@/pages/payables/adjustments';
import type { PayableAdjustment } from '@/pages/payables/adjustments';
import { ApplyAdvanceForm } from '@/pages/payables/advances';

function DisputeForm({
    document,
    reasons,
}: {
    document: Payable;
    reasons: Record<string, string>;
}) {
    const form = useForm({
        document_id: document.id,
        reason: Object.keys(reasons)[0] ?? 'other',
        description: '',
    });

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/payables/disputes', { preserveScroll: true });
            }}
        >
            <select
                aria-label="Motivo"
                className={input}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            >
                {Object.entries(reasons).map(([key, label]) => (
                    <option key={key} value={key}>
                        {label}
                    </option>
                ))}
            </select>
            <textarea
                className={`${input} h-20 py-2`}
                placeholder="Qué se cuestiona al proveedor…"
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
            />
            <button
                type="submit"
                className={secondaryButton}
                disabled={form.processing}
            >
                Abrir disputa
            </button>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

function CreditNoteRequest({ document }: { document: Payable }) {
    const form = useForm({ amount: '', reason: '' });

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    `/payables/documents/${document.id}/credit-note-request`,
                    { preserveScroll: true, onSuccess: () => form.reset() },
                );
            }}
        >
            <p className="text-xs text-[#526b92]">
                La nota de crédito del proveedor se registra en Compras con el
                documento recibido; aquí se solicita a quien la registra.
            </p>
            <input
                aria-label="Monto"
                className={input}
                inputMode="decimal"
                placeholder="Monto (Q)"
                value={form.data.amount}
                onChange={(event) => form.setData('amount', event.target.value)}
            />
            <input
                aria-label="Motivo"
                className={input}
                placeholder="Motivo"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <button
                type="submit"
                className={secondaryButton}
                disabled={form.processing}
            >
                Solicitar registro de nota de crédito
            </button>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

export default function PayablesDocument({
    document,
    payments,
    advances,
    creditNotes,
    proposals,
    disputes,
    adjustments,
    counterAccounts,
    adjustmentKinds,
    reasons,
    advancesAvailable,
    permissions,
    notice,
}: {
    context: PayablesContext;
    document: Payable;
    payments: {
        id: number;
        date: string;
        reference: string;
        amount_cents: number;
        status: string;
        active: boolean;
        from_treasury: boolean;
        void_reason: string | null;
    }[];
    advances: {
        id: number;
        reference: string;
        origin: string;
        amount_cents: number;
        date: string;
        reversed: boolean;
    }[];
    creditNotes: CreditNote[];
    proposals: {
        id: number;
        reference: string;
        status_label: string;
        proposed_date: string;
        amount_cents: number;
    }[];
    disputes: PayableDispute[];
    adjustments: PayableAdjustment[];
    counterAccounts: { id: number; label: string }[];
    adjustmentKinds: Record<string, string>;
    reasons: Record<string, string>;
    advancesAvailable: Advance[];
    permissions: PayablesPermissions;
    notice: string | null;
}) {
    const href = usePayablesHref();
    const [label, tone] = dueLabel(document);
    const [applying, setApplying] = useState<Advance | null>(null);
    const activeDispute = disputes.some((dispute) =>
        ['open', 'under_review'].includes(dispute.status),
    );

    return (
        <>
            <Head title={document.document} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={document.document}
                    subtitle={`${document.company} · ${document.supplier}${document.fiscal ? ` · ${document.fiscal}` : ''} · Emitido ${formatDate(document.issue_date)} · Vence ${formatDate(document.due_date)}${document.currency !== 'GTQ' && document.foreign_original_cents !== null ? ` · Documento en ${document.currency} ${(document.foreign_original_cents / 100).toFixed(2)} a tipo de cambio ${Number(document.exchange_rate)} (montos en GTQ)` : ''}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                className={toneBadge(
                                    documentTone[document.status] ?? 'slate',
                                )}
                            >
                                {document.status_label}
                            </span>
                            {document.outstanding_cents > 0 && (
                                <span className={toneBadge(tone)}>{label}</span>
                            )}
                            {document.committed_cents > 0 && (
                                <span className={toneBadge('amber')}>
                                    {amount(document.committed_cents)} en
                                    Tesorería
                                </span>
                            )}
                            <a
                                href={document.purchase_destination}
                                className={secondaryButton}
                            >
                                Ver compra
                            </a>
                        </div>
                    }
                />
                <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {[
                        ['Monto original', document.original_cents],
                        ['Pagado', document.paid_cents],
                        ['Notas de crédito', document.credited_cents],
                        ['Anticipos aplicados', document.advances_cents],
                        ['Ajustes aprobados', document.adjusted_cents],
                        ['Saldo pendiente', document.outstanding_cents],
                    ].map(([title, cents]) => (
                        <Card key={title as string} title={title as string}>
                            <p className="text-xl font-black">
                                {amount(cents as number)}
                            </p>
                        </Card>
                    ))}
                </div>
                <div className="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid content-start gap-3">
                        <Card title="Pagos">
                            <Table
                                headers={[
                                    'Fecha',
                                    'Referencia',
                                    'Monto',
                                    'Estado',
                                    '',
                                ]}
                                empty={payments.length === 0 && 'Sin pagos.'}
                            >
                                {payments.map((payment) => (
                                    <tr
                                        key={payment.id}
                                        className={
                                            payment.active
                                                ? ''
                                                : 'text-slate-400'
                                        }
                                    >
                                        <td className="px-2 py-2">
                                            {formatDate(payment.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {payment.reference}
                                            {payment.from_treasury &&
                                                ' (Tesorería)'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(payment.amount_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {payment.status}
                                            {payment.void_reason && (
                                                <p className="text-[11px]">
                                                    {payment.void_reason}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {payment.active &&
                                                payment.from_treasury &&
                                                permissions.canUnapply && (
                                                    <button
                                                        type="button"
                                                        className={
                                                            secondaryButton
                                                        }
                                                        onClick={() => {
                                                            const reason =
                                                                window.prompt(
                                                                    'Motivo para desaplicar (el dinero queda como saldo sin aplicar del proveedor)',
                                                                );

                                                            if (reason) {
                                                                router.post(
                                                                    `/payables/payments/${payment.id}/unapply`,
                                                                    { reason },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Desaplicar
                                                    </button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                        <Card title="Anticipos aplicados">
                            <Table
                                headers={[
                                    'Fecha',
                                    'Origen',
                                    'Referencia',
                                    'Monto',
                                ]}
                                empty={
                                    advances.length === 0 &&
                                    'Sin anticipos aplicados.'
                                }
                            >
                                {advances.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            row.reversed
                                                ? 'text-slate-400 line-through'
                                                : ''
                                        }
                                    >
                                        <td className="px-2 py-2">
                                            {formatDate(row.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.origin}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.reference}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(row.amount_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                        <Card title="Notas de crédito del proveedor">
                            <Table
                                headers={[
                                    'Nota',
                                    'Fecha',
                                    'Monto',
                                    'Motivo',
                                    'Estado',
                                ]}
                                empty={
                                    creditNotes.length === 0 &&
                                    'Sin notas de crédito.'
                                }
                            >
                                {creditNotes.map((note) => (
                                    <tr key={note.id}>
                                        <td className="px-2 py-2">
                                            {note.number}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(note.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(note.amount_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {note.reason}
                                        </td>
                                        <td className="px-2 py-2">
                                            {note.status}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                        <Card title="Propuestas de pago">
                            <Table
                                headers={[
                                    'Referencia',
                                    'Fecha',
                                    'Monto',
                                    'Estado',
                                ]}
                                empty={
                                    proposals.length === 0 &&
                                    'El documento no está en propuestas.'
                                }
                            >
                                {proposals.map((proposal) => (
                                    <tr key={proposal.id}>
                                        <td className="px-2 py-2 font-bold">
                                            <Link
                                                href={href(
                                                    `/payables/proposals/${proposal.id}`,
                                                )}
                                                className="hover:text-[#640000]"
                                            >
                                                {proposal.reference}
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(proposal.proposed_date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(proposal.amount_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {proposal.status_label}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                        <Card title="Ajustes y condonaciones">
                            {(permissions.canAdjust ||
                                permissions.canWriteOff) &&
                                document.outstanding_cents > 0 && (
                                    <div className="mb-3">
                                        <PayableAdjustmentForm
                                            documents={[document]}
                                            counterAccounts={{
                                                [document.company_id]:
                                                    counterAccounts,
                                            }}
                                            kinds={adjustmentKinds}
                                            permissions={permissions}
                                            fixedDocumentId={document.id}
                                        />
                                    </div>
                                )}
                            <PayableAdjustmentsTable
                                adjustments={adjustments}
                            />
                        </Card>
                        <Card title="Disputas">
                            <Table
                                headers={[
                                    'Abierta',
                                    'Motivo',
                                    'Descripción',
                                    'Estado',
                                    'Resolución',
                                ]}
                                empty={disputes.length === 0 && 'Sin disputas.'}
                            >
                                {disputes.map((dispute) => (
                                    <tr key={dispute.id}>
                                        <td className="px-2 py-2">
                                            {formatDate(dispute.opened_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {dispute.reason}
                                        </td>
                                        <td className="px-2 py-2">
                                            {dispute.description}
                                        </td>
                                        <td className="px-2 py-2">
                                            {dispute.status_label}
                                        </td>
                                        <td className="px-2 py-2">
                                            {dispute.resolution ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                            {activeDispute && (
                                <Link
                                    href={href('/payables/disputes')}
                                    className="mt-2 inline-block text-xs font-bold text-[#640000]"
                                >
                                    Gestionar disputa
                                </Link>
                            )}
                        </Card>
                    </div>
                    <div className="grid content-start gap-3">
                        {permissions.canPropose &&
                            document.outstanding_cents -
                                document.committed_cents >
                                0 && (
                                <Link
                                    href={href(
                                        `/payables/proposals?company_id=${document.company_id}&document=${document.id}`,
                                    )}
                                    className={primaryButton}
                                >
                                    Incluir en propuesta de pago
                                </Link>
                            )}
                        <Link
                            href={href(
                                `/payables/suppliers/${document.supplier_id}`,
                            )}
                            className={secondaryButton}
                        >
                            Ver proveedor y estado de cuenta
                        </Link>
                        {permissions.canApplyAdvances &&
                            document.outstanding_cents > 0 &&
                            advancesAvailable.length > 0 && (
                                <Card title="Saldos a favor del proveedor">
                                    {applying ? (
                                        <ApplyAdvanceForm
                                            advance={applying}
                                            documents={[document]}
                                            onDone={() => setApplying(null)}
                                        />
                                    ) : (
                                        <ul className="grid gap-1 text-sm">
                                            {advancesAvailable.map(
                                                (advance) => (
                                                    <li
                                                        key={advance.id}
                                                        className="flex items-center justify-between gap-2"
                                                    >
                                                        <span>
                                                            {
                                                                advance.origin_label
                                                            }{' '}
                                                            {advance.reference}{' '}
                                                            ·{' '}
                                                            {amount(
                                                                advance.outstanding_cents,
                                                            )}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() =>
                                                                setApplying(
                                                                    advance,
                                                                )
                                                            }
                                                        >
                                                            Aplicar
                                                        </button>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </Card>
                            )}
                        {permissions.canDispute &&
                            !activeDispute &&
                            document.outstanding_cents > 0 && (
                                <Card title="Disputa">
                                    <DisputeForm
                                        document={document}
                                        reasons={reasons}
                                    />
                                </Card>
                            )}
                        {document.outstanding_cents > 0 && (
                            <Card title="Nota de crédito">
                                <CreditNoteRequest document={document} />
                            </Card>
                        )}
                    </div>
                </div>
            </Shell>
        </>
    );
}
