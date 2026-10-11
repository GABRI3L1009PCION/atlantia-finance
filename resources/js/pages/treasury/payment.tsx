import { Head, router, useForm, usePage } from '@inertiajs/react';
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
import { amount, methodLabel, paymentTone } from '@/components/treasury/kit';
import type { Payment, TreasuryContext } from '@/components/treasury/kit';

function ExecuteForm({
    payment,
    bankAccounts,
}: {
    payment: Payment;
    bankAccounts: { id: number; label: string }[];
}) {
    const form = useForm<{
        executed_on: string;
        reference: string;
        proof: File | null;
        bank_account_id: string;
    }>({
        executed_on: new Date().toISOString().slice(0, 10),
        reference: '',
        proof: null,
        bank_account_id: bankAccounts[0] ? String(bankAccounts[0].id) : '',
    });

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/treasury/payments/${payment.id}/execute`, {
                    forceFormData: true,
                    preserveScroll: true,
                });
            }}
        >
            <p className="text-xs text-[#526b92]">
                Registra la ejecución real hecha en la banca del cliente.
                Atlantia no envía dinero.
            </p>
            {payment.account === null && (
                <label className="grid gap-1 text-xs font-semibold">
                    Cuenta bancaria de origen
                    <select
                        className={input}
                        value={form.data.bank_account_id}
                        onChange={(event) =>
                            form.setData('bank_account_id', event.target.value)
                        }
                    >
                        {bankAccounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.label}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <label className="grid gap-1 text-xs font-semibold">
                Fecha de ejecución
                <input
                    type="date"
                    className={input}
                    value={form.data.executed_on}
                    onChange={(event) =>
                        form.setData('executed_on', event.target.value)
                    }
                />
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Referencia bancaria
                <input
                    className={input}
                    value={form.data.reference}
                    onChange={(event) =>
                        form.setData('reference', event.target.value)
                    }
                />
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Comprobante (PDF o imagen)
                <input
                    type="file"
                    accept=".pdf,.png,.jpg,.jpeg"
                    className="text-xs"
                    onChange={(event) =>
                        form.setData('proof', event.target.files?.[0] ?? null)
                    }
                />
            </label>
            <button
                type="submit"
                className={primaryButton}
                disabled={form.processing}
            >
                Registrar ejecución
            </button>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

export default function TreasuryPaymentDetail({
    payment,
    history,
    bankAccounts,
    can,
    notice,
}: {
    context: TreasuryContext;
    payment: Payment;
    history: { action: string; user: string; occurred_at: string }[];
    bankAccounts: { id: number; label: string }[];
    can: { approve: boolean; execute: boolean; manage: boolean };
    notice: string | null;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [reason, setReason] = useState('');
    const [schedule, setSchedule] = useState(payment.scheduled_on ?? '');
    const act = (action: string, data: Record<string, string> = {}) =>
        router.post(`/treasury/payments/${payment.id}/${action}`, data, {
            preserveScroll: true,
        });
    const open = ['pending_approval', 'approved', 'scheduled'].includes(
        payment.status,
    );
    const executable = ['approved', 'scheduled'].includes(payment.status);

    return (
        <>
            <Head title={`Pago a ${payment.supplier}`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={`Pago a ${payment.supplier}`}
                    subtitle={`${payment.company} · ${amount(payment.amount_cents)} · ${methodLabel[payment.method] ?? payment.method} · ${payment.account ?? 'Cuenta por definir'}`}
                    actions={
                        <span
                            className={toneBadge(
                                paymentTone[payment.status] ?? 'slate',
                            )}
                        >
                            {payment.status_label}
                        </span>
                    }
                />
                {(errors.status ||
                    errors.executed_on ||
                    errors.bank_account_id) && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {errors.status ??
                            errors.executed_on ??
                            errors.bank_account_id}
                    </p>
                )}
                {(payment.failure_reason || payment.rejection_reason) && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        {payment.rejection_reason
                            ? `Rechazado: ${payment.rejection_reason}`
                            : `Motivo: ${payment.failure_reason}`}
                    </p>
                )}

                <div className="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid gap-3">
                        <Card
                            title={
                                payment.kind === 'advance'
                                    ? 'Anticipo a proveedor'
                                    : 'Facturas incluidas'
                            }
                        >
                            {payment.kind === 'advance' && (
                                <p className="mb-2 text-sm text-[#526b92]">
                                    Pago sin factura: al ejecutarse queda como
                                    anticipo del proveedor en Cuentas por pagar
                                    y se aplica después a sus facturas reales.
                                </p>
                            )}
                            {payment.proposal && (
                                <p className="mb-2 text-xs font-semibold text-[#061633]">
                                    Origen: propuesta de pago{' '}
                                    {payment.proposal.reference} (aprobada en
                                    Cuentas por pagar)
                                </p>
                            )}
                            <Table
                                headers={['Factura', 'Vence', 'Total', 'Pago']}
                                empty={
                                    payment.items.length === 0 &&
                                    'Sin facturas: anticipo.'
                                }
                            >
                                {payment.items.map((item) => (
                                    <tr key={item.document_id}>
                                        <td className="px-2 py-2 font-bold">
                                            {item.document}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(item.due_on)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(item.total_cents)}
                                        </td>
                                        <td className="px-2 py-2 font-semibold">
                                            {amount(item.amount_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                            <p className="mt-2 text-xs text-[#526b92]">
                                Concepto: {payment.concept} · Preparó{' '}
                                {payment.preparer ?? '—'} · Programado{' '}
                                {formatDate(payment.scheduled_on)}
                            </p>
                        </Card>
                        <Card
                            title={`Aprobaciones ${payment.approvals} / ${payment.required_approvals}`}
                        >
                            <ul className="space-y-1 text-sm">
                                {payment.approval_history.length === 0 && (
                                    <li className="text-slate-500">
                                        Aún sin aprobaciones.
                                    </li>
                                )}
                                {payment.approval_history.map(
                                    (approval, index) => (
                                        <li key={index}>
                                            <b>{approval.name}</b> ·{' '}
                                            {approval.status === 'approved'
                                                ? `Aprobó (nivel ${approval.level})`
                                                : 'Rechazó'}{' '}
                                            · {formatDate(approval.at)}
                                            {approval.reason &&
                                                ` · ${approval.reason}`}
                                        </li>
                                    ),
                                )}
                            </ul>
                        </Card>
                        {payment.status === 'executed' && (
                            <Card title="Ejecución">
                                <p className="text-sm">
                                    Ejecutado el{' '}
                                    {formatDate(payment.executed_on)} ·
                                    Referencia {payment.execution_reference}
                                </p>
                                {payment.evidence_name && (
                                    <a
                                        href={`/treasury/payments/${payment.id}/evidence`}
                                        className={`${secondaryButton} mt-2`}
                                    >
                                        Descargar comprobante
                                    </a>
                                )}
                            </Card>
                        )}
                        <Card title="Historial">
                            <ul className="space-y-1 text-xs">
                                {history.map((event, index) => (
                                    <li key={index}>
                                        <b>{event.user}</b> · {event.action} ·{' '}
                                        {formatDate(event.occurred_at)}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </div>

                    <div className="grid content-start gap-3">
                        {can.approve && (
                            <Card title="Aprobar">
                                <div className="grid gap-2">
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() => act('approve')}
                                    >
                                        Aprobar pago
                                    </button>
                                    <textarea
                                        className={`${input} h-20 py-2`}
                                        placeholder="Motivo del rechazo…"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        disabled={reason.trim().length < 5}
                                        onClick={() =>
                                            act('reject', { reason })
                                        }
                                    >
                                        Rechazar
                                    </button>
                                </div>
                            </Card>
                        )}
                        {can.execute && executable && (
                            <Card title="Ejecución">
                                <ExecuteForm
                                    payment={payment}
                                    bankAccounts={bankAccounts}
                                />
                            </Card>
                        )}
                        {can.manage && open && (
                            <Card title="Gestionar">
                                <div className="grid gap-2">
                                    <div className="flex gap-1">
                                        <input
                                            type="date"
                                            aria-label="Fecha programada"
                                            className={input}
                                            value={schedule}
                                            onChange={(event) =>
                                                setSchedule(event.target.value)
                                            }
                                        />
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            disabled={schedule === ''}
                                            onClick={() =>
                                                act('schedule', {
                                                    scheduled_on: schedule,
                                                })
                                            }
                                        >
                                            Programar
                                        </button>
                                    </div>
                                    <textarea
                                        className={`${input} h-16 py-2`}
                                        placeholder="Motivo (fallo o cancelación)…"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                    <div className="flex gap-1">
                                        {executable && can.execute && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                disabled={
                                                    reason.trim().length < 5
                                                }
                                                onClick={() =>
                                                    act('fail', { reason })
                                                }
                                            >
                                                Marcar fallido
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            disabled={reason.trim().length < 5}
                                            onClick={() =>
                                                act('cancel', { reason })
                                            }
                                        >
                                            Cancelar pago
                                        </button>
                                    </div>
                                </div>
                            </Card>
                        )}
                    </div>
                </div>
            </Shell>
        </>
    );
}
