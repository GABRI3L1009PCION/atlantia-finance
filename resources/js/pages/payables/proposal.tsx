import { Head, Link, router } from '@inertiajs/react';
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
    priorityLabels,
    proposalTone,
    usePayablesHref,
} from '@/components/payables/kit';
import type { PayablesContext, Proposal } from '@/components/payables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Item = {
    id: number;
    kind: string;
    supplier: string;
    document_id: number | null;
    document: string;
    due_on: string | null;
    amount_cents: number;
    priority: string;
    reason: string | null;
    payment_id: number | null;
    payment_status: string | null;
    execution_reference: string | null;
};

const actionLabels: Record<string, string> = {
    'payment_proposal.created': 'Creada',
    'payment_proposal.submitted': 'Enviada a aprobación',
    'payment_proposal.approved': 'Aprobada',
    'payment_proposal.rejected': 'Rechazada',
    'payment_proposal.cancelled': 'Cancelada',
    'payment_proposal.sent_to_treasury': 'Enviada a Tesorería',
};

export default function PayablesProposal({
    proposal,
    items,
    history,
    can,
    notice,
    errors,
}: {
    context: PayablesContext;
    proposal: Proposal;
    items: Item[];
    history: { action: string; user: string; occurred_at: string }[];
    can: { submit: boolean; review: boolean; send: boolean; cancel: boolean };
    notice: string | null;
    errors: Record<string, string>;
}) {
    const href = usePayablesHref();
    const [note, setNote] = useState('');
    const act = (action: string, data: Record<string, string> = {}) =>
        router.post(`/payables/proposals/${proposal.id}/${action}`, data, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title={`Propuesta ${proposal.reference}`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={`Propuesta ${proposal.reference}`}
                    subtitle={`${proposal.company} · pago propuesto para ${formatDate(proposal.proposed_date)} · prioridad ${priorityLabels[proposal.priority] ?? proposal.priority} · preparó ${proposal.preparer ?? '—'}`}
                    actions={
                        <span
                            className={toneBadge(
                                proposalTone[proposal.status] ?? 'slate',
                            )}
                        >
                            {proposal.status_label}
                        </span>
                    }
                />
                {Object.values(errors).length > 0 && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {Object.values(errors).join(' ')}
                    </p>
                )}
                <div className="grid gap-3 md:grid-cols-4">
                    <Card title="Total propuesto">
                        <p className="text-2xl font-black">
                            {amount(proposal.total_cents)}
                        </p>
                    </Card>
                    <Card title="Proveedores">
                        <p className="text-2xl font-black">
                            {proposal.suppliers_count}
                        </p>
                    </Card>
                    <Card title="Documentos / anticipos">
                        <p className="text-2xl font-black">
                            {proposal.documents_count} /{' '}
                            {proposal.advances_count}
                        </p>
                    </Card>
                    <Card title="Aprobación">
                        <p className="text-sm font-bold">
                            {proposal.approver
                                ? `${proposal.approver} · ${formatDate(proposal.approved_at)}`
                                : 'Pendiente'}
                        </p>
                        {proposal.review_note && (
                            <p className="text-xs text-[#526b92]">
                                {proposal.review_note}
                            </p>
                        )}
                    </Card>
                </div>
                <div className="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Card title="Líneas de la propuesta">
                        <Table
                            headers={[
                                'Proveedor',
                                'Documento',
                                'Vence',
                                'Monto',
                                'Pago en Tesorería',
                            ]}
                            empty={items.length === 0 && 'Sin líneas.'}
                        >
                            {items.map((item) => (
                                <tr key={item.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {item.supplier}
                                    </td>
                                    <td className="px-2 py-2">
                                        {item.document_id ? (
                                            <Link
                                                href={href(
                                                    `/payables/documents/${item.document_id}`,
                                                )}
                                                className="hover:text-[#640000]"
                                            >
                                                {item.document}
                                            </Link>
                                        ) : (
                                            <span
                                                className={toneBadge('violet')}
                                            >
                                                {item.document}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(item.due_on)}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(item.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {item.payment_id ? (
                                            <a
                                                href={`/treasury/payments/${item.payment_id}`}
                                                className="font-bold text-[#640000]"
                                            >
                                                TES-{item.payment_id} ·{' '}
                                                {item.payment_status}
                                            </a>
                                        ) : (
                                            '—'
                                        )}
                                        {item.execution_reference && (
                                            <p className="text-[11px] text-[#526b92]">
                                                Ref. {item.execution_reference}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        {proposal.notes && (
                            <p className="mt-2 text-xs text-[#526b92]">
                                Notas: {proposal.notes}
                            </p>
                        )}
                    </Card>
                    <div className="grid content-start gap-3">
                        {(can.submit ||
                            can.review ||
                            can.send ||
                            can.cancel) && (
                            <Card title="Acciones">
                                <div className="grid gap-2">
                                    {can.submit && (
                                        <button
                                            type="button"
                                            className={primaryButton}
                                            onClick={() => act('submit')}
                                        >
                                            Enviar a aprobación
                                        </button>
                                    )}
                                    {can.send && (
                                        <button
                                            type="button"
                                            className={primaryButton}
                                            onClick={() => act('send')}
                                        >
                                            Enviar a Tesorería
                                        </button>
                                    )}
                                    {(can.review || can.cancel) && (
                                        <textarea
                                            aria-label="Nota"
                                            className={`${input} h-16 py-2`}
                                            placeholder={
                                                can.review
                                                    ? 'Nota de revisión (obligatoria para rechazar)'
                                                    : 'Motivo de cancelación'
                                            }
                                            value={note}
                                            onChange={(event) =>
                                                setNote(event.target.value)
                                            }
                                        />
                                    )}
                                    {can.review && (
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                className={primaryButton}
                                                onClick={() =>
                                                    act('approve', { note })
                                                }
                                            >
                                                Aprobar
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                disabled={
                                                    note.trim().length < 5
                                                }
                                                onClick={() =>
                                                    act('reject', { note })
                                                }
                                            >
                                                Rechazar
                                            </button>
                                        </div>
                                    )}
                                    {can.cancel && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            disabled={note.trim().length < 5}
                                            onClick={() =>
                                                act('cancel', { note })
                                            }
                                        >
                                            Cancelar propuesta
                                        </button>
                                    )}
                                </div>
                            </Card>
                        )}
                        <Card title="Historial">
                            <ul className="grid gap-1.5 text-xs">
                                {history.map((event, index) => (
                                    <li key={index}>
                                        <b>
                                            {actionLabels[event.action] ??
                                                event.action}
                                        </b>{' '}
                                        · {event.user} ·{' '}
                                        {formatDate(event.occurred_at)}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </div>
                </div>
            </Shell>
        </>
    );
}
