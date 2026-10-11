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
import { amount, useReceivablesHref } from '@/components/receivables/kit';
import type {
    Dispute,
    Receivable,
    ReceivablesContext,
    ReceivablesPermissions,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

const tone: Record<string, string> = {
    open: 'red',
    under_review: 'amber',
    resolved: 'green',
    rejected: 'slate',
    cancelled: 'slate',
};

function DisputeActions({ dispute }: { dispute: Dispute }) {
    const [resolution, setResolution] = useState('');
    const act = (action: string) =>
        router.post(
            `/receivables/disputes/${dispute.id}/${action}`,
            { resolution },
            { preserveScroll: true },
        );

    return (
        <div className="grid min-w-56 gap-1">
            {dispute.status === 'open' && (
                <button
                    type="button"
                    className={secondaryButton}
                    onClick={() => act('under_review')}
                >
                    Poner en revisión
                </button>
            )}
            <input
                aria-label="Resolución"
                className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                placeholder="Resolución (nota de crédito, corrección, pago…)"
                value={resolution}
                onChange={(event) => setResolution(event.target.value)}
            />
            <div className="flex gap-1">
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={resolution.trim().length < 5}
                    onClick={() => act('resolved')}
                >
                    Resolver
                </button>
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={resolution.trim().length < 5}
                    onClick={() => act('rejected')}
                >
                    Rechazar
                </button>
            </div>
        </div>
    );
}

export default function ReceivablesDisputes({
    disputes,
    filters,
    reasons,
    documents,
    permissions,
    notice,
    errors,
}: {
    context: ReceivablesContext;
    disputes: Dispute[];
    filters: { status?: string };
    reasons: Record<string, string>;
    documents: Receivable[];
    permissions: ReceivablesPermissions;
    notice: string | null;
    errors: Record<string, string>;
}) {
    const href = useReceivablesHref();
    const form = useForm({
        invoice_id: '',
        reason: Object.keys(reasons)[0] ?? 'other',
        description: '',
    });
    const available = documents.filter((document) => !document.disputed);

    return (
        <>
            <Head title="Disputas" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Disputas"
                    subtitle="Una disputa marca el documento pero no elimina la deuda: solo una nota de crédito, anulación, pago o ajuste autorizado cambia el saldo"
                />
                {(errors.resolution || errors.status) && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {errors.resolution ?? errors.status}
                    </p>
                )}
                {permissions.canDispute && available.length > 0 && (
                    <Card title="Abrir disputa">
                        <form
                            className="grid gap-2 md:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/receivables/disputes', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('description'),
                                });
                            }}
                        >
                            <select
                                aria-label="Documento"
                                className={input}
                                value={form.data.invoice_id}
                                onChange={(event) =>
                                    form.setData(
                                        'invoice_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Documento…</option>
                                {available.map((document) => (
                                    <option
                                        key={document.id}
                                        value={document.id}
                                    >
                                        {document.company} · {document.customer}{' '}
                                        · {document.document}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Motivo"
                                className={input}
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                            >
                                {Object.entries(reasons).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <input
                                aria-label="Descripción"
                                className={input}
                                placeholder="Qué cuestiona el cliente"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={
                                    form.processing ||
                                    form.data.invoice_id === ''
                                }
                            >
                                Abrir disputa
                            </button>
                            {Object.values(form.errors).length > 0 && (
                                <p className="text-xs text-red-700 md:col-span-4">
                                    {Object.values(form.errors).join(' ')}
                                </p>
                            )}
                        </form>
                    </Card>
                )}
                <Card title={`${disputes.length} disputa(s)`}>
                    <div className="mb-3 flex gap-1.5">
                        {[
                            ['active', 'Activas'],
                            ['all', 'Todas'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    router.get(href('/receivables/disputes'), {
                                        status: key,
                                    })
                                }
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${(filters.status ?? 'active') === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <Table
                        headers={[
                            'Abierta',
                            'Cliente',
                            'Documento',
                            'Saldo',
                            'Motivo',
                            'Descripción',
                            'Responsable',
                            'Estado',
                            '',
                        ]}
                        empty={
                            disputes.length === 0 &&
                            'No existen disputas activas.'
                        }
                    >
                        {disputes.map((dispute) => (
                            <tr key={dispute.id} className="align-top">
                                <td className="px-2 py-2">
                                    {formatDate(dispute.opened_at)}
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {dispute.customer}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {dispute.company}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(dispute.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {dispute.document}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {amount(dispute.outstanding_cents)}
                                </td>
                                <td className="px-2 py-2">{dispute.reason}</td>
                                <td className="max-w-48 px-2 py-2">
                                    {dispute.description}
                                    {dispute.resolution && (
                                        <p className="text-[11px] text-emerald-800">
                                            {dispute.resolution}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {dispute.responsible ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            tone[dispute.status] ?? 'slate',
                                        )}
                                    >
                                        {dispute.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {permissions.canDispute &&
                                        ['open', 'under_review'].includes(
                                            dispute.status,
                                        ) && (
                                            <DisputeActions dispute={dispute} />
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
