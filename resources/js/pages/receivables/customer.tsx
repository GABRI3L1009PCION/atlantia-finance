import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    CollectionForm,
    activityTone,
    amount,
    priorityTone,
    promiseTone,
    sendReminder,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    Activity,
    CollectionOptions,
    CustomerBalance,
    Dispute,
    PromiseRow,
    Receivable,
    ReceivablesContext,
    ReceivablesPermissions,
    SharedReceipt,
} from '@/components/receivables/kit';
import { DocumentsTable } from '@/components/receivables/tables';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Customer = {
    id: number;
    company_id: number;
    company: string;
    name: string;
    tax_id: string | null;
    email: string | null;
    payment_terms_days: number | null;
};
type Template = { id: number; kind: string; kind_label: string; name: string };

export default function ReceivablesCustomer({
    customer,
    summary,
    documents,
    receipts,
    unapplied,
    creditNotes,
    promises,
    activities,
    disputes,
    reminders,
    templates,
    options,
    permissions,
    notice,
}: {
    context: ReceivablesContext;
    customer: Customer;
    summary: CustomerBalance | null;
    documents: Receivable[];
    receipts: {
        id: number;
        date: string;
        reference: string;
        amount_cents: number;
        document: string;
        voided: boolean;
        from_treasury: boolean;
    }[];
    unapplied: SharedReceipt[];
    creditNotes: {
        number: number;
        date: string;
        amount_cents: number;
        reason: string;
        status: string;
        document: string;
    }[];
    promises: PromiseRow[];
    activities: Activity[];
    disputes: Dispute[];
    reminders: {
        id: number;
        kind: string;
        recipient: string;
        status: string;
        status_label: string;
        error: string | null;
        sender: string;
        at: string;
    }[];
    templates: Template[];
    options: CollectionOptions;
    permissions: ReceivablesPermissions;
    notice: string | null;
}) {
    const href = useReceivablesHref();
    const [template, setTemplate] = useState(
        templates.find((row) => row.kind !== 'statement')?.id ?? 0,
    );
    const open = documents.filter((document) => document.outstanding_cents > 0);
    const [priority, tone] = summary
        ? (priorityTone[summary.priority] ?? [summary.priority, 'slate'])
        : ['—', 'slate'];

    return (
        <>
            <Head title={`${customer.name} · CxC`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={customer.name}
                    subtitle={`${customer.company} · NIT ${customer.tax_id ?? 'No indicado'} · ${customer.email ?? 'Sin correo registrado'} · ${customer.payment_terms_days === null ? 'Sin plazo de pago configurado' : `Plazo de pago ${customer.payment_terms_days} días`}`}
                    actions={
                        <Link
                            href={href(
                                `/receivables/statements?customer_id=${customer.id}`,
                            )}
                            className={secondaryButton}
                        >
                            Estado de cuenta
                        </Link>
                    }
                />

                <div className="grid gap-3 md:grid-cols-4">
                    {[
                        ['Saldo total', summary?.total_cents ?? 0, ''],
                        [
                            'Vencido',
                            summary?.overdue_cents ?? 0,
                            'text-red-700',
                        ],
                        [
                            'Por vencer',
                            summary?.current_cents ?? 0,
                            'text-emerald-700',
                        ],
                    ].map(([label, cents, color]) => (
                        <Card key={label as string} title={label as string}>
                            <p className={`text-2xl font-black ${color}`}>
                                {amount(cents as number)}
                            </p>
                        </Card>
                    ))}
                    <Card title="Prioridad de cobranza">
                        <span className={toneBadge(tone)}>{priority}</span>
                        <p className="mt-1 text-xs text-[#526b92]">
                            {summary?.priority_reasons.join(' · ') ||
                                'Sin saldo vencido'}
                        </p>
                    </Card>
                </div>

                {permissions.canCollect && (
                    <Card title="Registrar gestión de cobranza">
                        <CollectionForm
                            customers={[
                                {
                                    id: customer.id,
                                    name: customer.name,
                                    company: customer.company,
                                },
                            ]}
                            documents={open.map((document) => ({
                                id: document.id,
                                document: document.document,
                                customer_id: document.customer_id,
                            }))}
                            options={options}
                            defaultCustomerId={customer.id}
                        />
                    </Card>
                )}

                <Card title="Documentos">
                    <DocumentsTable
                        documents={documents}
                        empty="El cliente no tiene documentos."
                        showCustomer={false}
                    />
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Gestiones">
                        <Table
                            headers={[
                                'Fecha',
                                'Canal',
                                'Resultado',
                                'Notas',
                                'Próxima acción',
                                'Estado',
                            ]}
                            empty={
                                activities.length === 0 &&
                                'Sin gestiones registradas.'
                            }
                        >
                            {activities.map((activity) => (
                                <tr key={activity.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(activity.performed_at)}
                                        <p className="text-[11px] text-[#526b92]">
                                            {activity.performer}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {activity.channel_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {activity.result_label}
                                        {activity.corrects_id && (
                                            <p className="text-[11px] text-[#526b92]">
                                                Corrige la gestión #
                                                {activity.corrects_id}
                                            </p>
                                        )}
                                    </td>
                                    <td className="max-w-48 px-2 py-2">
                                        {activity.notes ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {activity.next_action
                                            ? `${activity.next_action} · ${formatDate(activity.next_action_at)}`
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                activityTone[activity.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {activity.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Promesas de pago">
                        <Table
                            headers={[
                                'Fecha prometida',
                                'Monto',
                                'Cumplido',
                                'Estado',
                                '',
                            ]}
                            empty={promises.length === 0 && 'Sin promesas.'}
                        >
                            {promises.map((promise) => (
                                <tr key={promise.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(promise.promised_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(promise.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(promise.fulfilled_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                promiseTone[promise.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {promise.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {permissions.canPromise &&
                                            [
                                                'pending',
                                                'partially_fulfilled',
                                            ].includes(promise.status) && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() => {
                                                        const reason =
                                                            window.prompt(
                                                                'Motivo de la cancelación',
                                                            );

                                                        if (reason) {
                                                            router.post(
                                                                `/receivables/promises/${promise.id}/cancel`,
                                                                { reason },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    Cancelar
                                                </button>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Cobros aplicados">
                        <Table
                            headers={[
                                'Fecha',
                                'Referencia',
                                'Documento',
                                'Monto',
                            ]}
                            empty={receipts.length === 0 && 'Sin cobros.'}
                        >
                            {receipts.map((receipt) => (
                                <tr
                                    key={receipt.id}
                                    className={
                                        receipt.voided
                                            ? 'text-slate-400 line-through'
                                            : ''
                                    }
                                >
                                    <td className="px-2 py-2">
                                        {formatDate(receipt.date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.reference}
                                        {receipt.from_treasury && (
                                            <span className="ml-1 text-[10px] text-[#526b92]">
                                                (Tesorería)
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.document}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(receipt.amount_cents)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        {unapplied.length > 0 && (
                            <p className="mt-2 text-xs text-amber-800">
                                {unapplied.length} cobro(s) sin aplicar
                                relacionados.{' '}
                                <Link
                                    href={href(
                                        '/receivables/unapplied-receipts',
                                    )}
                                    className="font-bold underline"
                                >
                                    Aplicar
                                </Link>
                            </p>
                        )}
                    </Card>
                    <Card title="Notas de crédito y disputas">
                        <Table
                            headers={['Tipo', 'Documento', 'Detalle', 'Estado']}
                            empty={
                                creditNotes.length + disputes.length === 0 &&
                                'Sin notas de crédito ni disputas.'
                            }
                        >
                            {creditNotes.map((note) => (
                                <tr key={`nc-${note.number}`}>
                                    <td className="px-2 py-2">
                                        NC {note.number}
                                    </td>
                                    <td className="px-2 py-2">
                                        {note.document}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(note.amount_cents)} ·{' '}
                                        {note.reason}
                                    </td>
                                    <td className="px-2 py-2">{note.status}</td>
                                </tr>
                            ))}
                            {disputes.map((dispute) => (
                                <tr key={`d-${dispute.id}`}>
                                    <td className="px-2 py-2">Disputa</td>
                                    <td className="px-2 py-2">
                                        {dispute.document}
                                    </td>
                                    <td className="px-2 py-2">
                                        {dispute.reason} · {dispute.description}
                                    </td>
                                    <td className="px-2 py-2">
                                        {dispute.status_label}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <Card
                    title="Recordatorios enviados"
                    action={
                        permissions.canSend &&
                        templates.length > 0 && (
                            <div className="flex gap-1">
                                <select
                                    aria-label="Plantilla"
                                    className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                    value={template}
                                    onChange={(event) =>
                                        setTemplate(Number(event.target.value))
                                    }
                                >
                                    {templates
                                        .filter(
                                            (row) => row.kind !== 'statement',
                                        )
                                        .map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.kind_label} · {row.name}
                                            </option>
                                        ))}
                                </select>
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    disabled={!customer.email}
                                    title={
                                        customer.email
                                            ? undefined
                                            : 'El cliente no tiene correo en Ventas'
                                    }
                                    onClick={() =>
                                        sendReminder(customer.id, template)
                                    }
                                >
                                    Enviar recordatorio
                                </button>
                                {customer.email && (
                                    <a
                                        className={secondaryButton}
                                        href={`/receivables/reminders/preview?customer_id=${customer.id}&template_id=${template}`}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Vista previa
                                    </a>
                                )}
                            </div>
                        )
                    }
                >
                    <Table
                        headers={[
                            'Fecha',
                            'Tipo',
                            'Destinatario',
                            'Envió',
                            'Estado',
                        ]}
                        empty={
                            reminders.length === 0 &&
                            'No se han enviado recordatorios.'
                        }
                    >
                        {reminders.map((reminder) => (
                            <tr key={reminder.id}>
                                <td className="px-2 py-2">
                                    {formatDate(reminder.at, true)}
                                </td>
                                <td className="px-2 py-2">{reminder.kind}</td>
                                <td className="px-2 py-2">
                                    {reminder.recipient}
                                </td>
                                <td className="px-2 py-2">{reminder.sender}</td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            reminder.status === 'sent'
                                                ? 'green'
                                                : reminder.status === 'failed'
                                                  ? 'red'
                                                  : 'amber',
                                        )}
                                        title={reminder.error ?? undefined}
                                    >
                                        {reminder.status_label}
                                    </span>
                                    {reminder.status === 'failed' &&
                                        permissions.canSend && (
                                            <button
                                                type="button"
                                                className="ml-1 text-xs font-bold text-[#640000] underline"
                                                onClick={() =>
                                                    router.post(
                                                        `/receivables/reminders/${reminder.id}/retry`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Reintentar
                                            </button>
                                        )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
