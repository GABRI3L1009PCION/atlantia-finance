import { Head, Link, router, useForm } from '@inertiajs/react';
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
    activityTone,
    amount,
    documentTone,
    dueLabel,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    Activity,
    CollectionOptions,
    Dispute,
    Receivable,
    ReceivablesContext,
    ReceivablesPermissions,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    AdjustmentForm,
    AdjustmentsTable,
} from '@/pages/receivables/adjustments';
import type { Adjustment } from '@/pages/receivables/adjustments';

function DisputeForm({
    document,
    reasons,
}: {
    document: Receivable;
    reasons: Record<string, string>;
}) {
    const form = useForm({
        invoice_id: document.id,
        reason: Object.keys(reasons)[0] ?? 'other',
        description: '',
    });

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/receivables/disputes', { preserveScroll: true });
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
                placeholder="Qué cuestiona el cliente…"
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

function CreditNoteRequest({ document }: { document: Receivable }) {
    const form = useForm({ amount: '', reason: '' });

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    `/receivables/documents/${document.id}/credit-note-request`,
                    { preserveScroll: true, onSuccess: () => form.reset() },
                );
            }}
        >
            <p className="text-xs text-[#526b92]">
                La nota de crédito la emite Ventas desde la factura; aquí solo
                se solicita su evaluación.
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
                Solicitar nota de crédito
            </button>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

export default function ReceivablesDocument({
    document,
    applications,
    creditNotes,
    disputes,
    activities,
    options,
    adjustments,
    counterAccounts,
    adjustmentKinds,
    permissions,
    notice,
}: {
    context: ReceivablesContext;
    document: Receivable;
    applications: {
        id: number;
        void_reason: string | null;
        date: string;
        reference: string;
        amount_cents: number;
        voided: boolean;
        from_treasury: boolean;
    }[];
    creditNotes: {
        number: number;
        date: string;
        amount_cents: number;
        reason: string;
        status: string;
    }[];
    disputes: Dispute[];
    activities: Activity[];
    options: CollectionOptions;
    adjustments: Adjustment[];
    counterAccounts: { id: number; label: string }[];
    adjustmentKinds: Record<string, string>;
    permissions: ReceivablesPermissions;
    notice: string | null;
}) {
    const href = useReceivablesHref();
    const [label, tone] = dueLabel(document);
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
                    subtitle={`${document.company} · ${document.customer} · Emitida ${formatDate(document.issue_date)} · Vence ${formatDate(document.due_date)}${document.currency !== 'GTQ' && document.foreign_original_cents !== null ? ` · Documento en ${document.currency} ${(document.foreign_original_cents / 100).toFixed(2)} a tipo de cambio ${Number(document.exchange_rate)} (montos en GTQ)` : ''}`}
                    actions={
                        <div className="flex items-center gap-2">
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
                            <a
                                href={document.invoice_destination}
                                className={secondaryButton}
                            >
                                Ver factura en Ventas
                            </a>
                        </div>
                    }
                />
                <div className="grid gap-3 md:grid-cols-5">
                    {[
                        ['Monto original', document.original_cents],
                        ['Cobrado', document.applied_cents],
                        ['Notas de crédito', document.credited_cents],
                        ['Ajustes aprobados', document.adjusted_cents],
                        ['Saldo pendiente', document.outstanding_cents],
                    ].map(([title, cents]) => (
                        <Card key={title as string} title={title as string}>
                            <p className="text-2xl font-black">
                                {amount(cents as number)}
                            </p>
                        </Card>
                    ))}
                </div>
                <div className="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid content-start gap-3">
                        <Card title="Cobros aplicados">
                            <Table
                                headers={['Fecha', 'Referencia', 'Monto', '']}
                                empty={
                                    applications.length === 0 &&
                                    'Sin cobros aplicados.'
                                }
                            >
                                {applications.map((row, index) => (
                                    <tr
                                        key={index}
                                        className={
                                            row.voided
                                                ? 'text-slate-400 line-through'
                                                : ''
                                        }
                                    >
                                        <td className="px-2 py-2">
                                            {formatDate(row.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.reference}
                                            {row.from_treasury &&
                                                ' (Tesorería)'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(row.amount_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.voided
                                                ? row.void_reason
                                                : permissions.canUnapply &&
                                                  row.from_treasury && (
                                                      <button
                                                          type="button"
                                                          className={
                                                              secondaryButton
                                                          }
                                                          onClick={() => {
                                                              const reason =
                                                                  window.prompt(
                                                                      'Motivo de la reversión',
                                                                  );

                                                              if (reason) {
                                                                  router.post(
                                                                      `/receivables/applications/${row.id}/unapply`,
                                                                      {
                                                                          reason,
                                                                      },
                                                                      {
                                                                          preserveScroll: true,
                                                                      },
                                                                  );
                                                              }
                                                          }}
                                                      >
                                                          Revertir
                                                      </button>
                                                  )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                        <Card title="Ajustes y castigos">
                            {(permissions.canAdjust ||
                                permissions.canWriteOff) &&
                                document.outstanding_cents > 0 && (
                                    <div className="mb-3">
                                        <AdjustmentForm
                                            documents={[document]}
                                            counterAccounts={{
                                                [document.company_id]:
                                                    counterAccounts,
                                            }}
                                            kinds={adjustmentKinds}
                                            permissions={permissions}
                                            fixedInvoiceId={document.id}
                                        />
                                    </div>
                                )}
                            <AdjustmentsTable adjustments={adjustments} />
                        </Card>
                        <Card title="Notas de crédito">
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
                                    <tr key={note.number}>
                                        <td className="px-2 py-2">
                                            NC {note.number}
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
                                    href={href('/receivables/disputes')}
                                    className="mt-2 inline-block text-xs font-bold text-[#640000]"
                                >
                                    Gestionar disputa
                                </Link>
                            )}
                        </Card>
                        <Card title="Gestiones del cliente">
                            <Table
                                headers={[
                                    'Fecha',
                                    'Canal',
                                    'Resultado',
                                    'Estado',
                                ]}
                                empty={
                                    activities.length === 0 &&
                                    'Sin gestiones para este documento.'
                                }
                            >
                                {activities.map((activity) => (
                                    <tr key={activity.id}>
                                        <td className="px-2 py-2">
                                            {formatDate(activity.performed_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {activity.channel_label}
                                        </td>
                                        <td className="px-2 py-2">
                                            {activity.result_label}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    activityTone[
                                                        activity.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {activity.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    </div>
                    <div className="grid content-start gap-3">
                        <Link
                            href={href(
                                `/receivables/customers/${document.customer_id}`,
                            )}
                            className={primaryButton}
                        >
                            Ir al cliente y registrar gestión
                        </Link>
                        {permissions.canDispute &&
                            !activeDispute &&
                            document.outstanding_cents > 0 && (
                                <Card title="Disputa">
                                    <DisputeForm
                                        document={document}
                                        reasons={options.reasons}
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
