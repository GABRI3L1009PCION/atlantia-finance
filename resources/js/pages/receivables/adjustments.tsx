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
import { amount, useReceivablesHref } from '@/components/receivables/kit';
import type {
    Receivable,
    ReceivablesContext,
    ReceivablesPermissions,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export type Adjustment = {
    id: number;
    company: string;
    invoice_id: number;
    document: string;
    customer: string;
    kind: string;
    kind_label: string;
    amount_cents: number;
    account: string;
    reason: string;
    status: string;
    status_label: string;
    requester: string;
    reviewer: string | null;
    review_note: string | null;
    reviewed_at: string | null;
    created_at: string;
    can_review?: boolean;
};

const tone: Record<string, string> = {
    pending: 'amber',
    approved: 'green',
    rejected: 'red',
};

/** Request form shared by the adjustments screen and the document detail. */
export function AdjustmentForm({
    documents,
    counterAccounts,
    kinds,
    permissions,
    fixedInvoiceId,
}: {
    documents: Receivable[];
    counterAccounts: Record<number, { id: number; label: string }[]>;
    kinds: Record<string, string>;
    permissions: ReceivablesPermissions;
    fixedInvoiceId?: number;
}) {
    const allowedKinds = Object.entries(kinds).filter(([key]) =>
        key === 'write_off' ? permissions.canWriteOff : permissions.canAdjust,
    );
    const form = useForm({
        invoice_id: fixedInvoiceId
            ? String(fixedInvoiceId)
            : documents[0]
              ? String(documents[0].id)
              : '',
        kind: allowedKinds[0]?.[0] ?? 'adjustment',
        amount: '',
        ledger_account_id: '',
        reason: '',
    });
    const document = documents.find(
        (row) => String(row.id) === form.data.invoice_id,
    );
    const accounts = document
        ? (counterAccounts[document.company_id] ?? [])
        : [];

    if (allowedKinds.length === 0) {
        return null;
    }

    return (
        <form
            className="grid gap-2 md:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/receivables/adjustments', {
                    preserveScroll: true,
                    onSuccess: () => form.reset('amount', 'reason'),
                });
            }}
        >
            {!fixedInvoiceId && (
                <label className="grid gap-1 text-xs font-semibold md:col-span-3">
                    Documento
                    <select
                        className={input}
                        value={form.data.invoice_id}
                        onChange={(event) =>
                            form.setData('invoice_id', event.target.value)
                        }
                    >
                        {documents.map((row) => (
                            <option key={row.id} value={row.id}>
                                {row.company} · {row.customer} · {row.document}{' '}
                                · saldo {amount(row.outstanding_cents)}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <label className="grid gap-1 text-xs font-semibold">
                Tipo
                <select
                    className={input}
                    value={form.data.kind}
                    onChange={(event) =>
                        form.setData('kind', event.target.value)
                    }
                >
                    {allowedKinds.map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Monto (Q)
                <input
                    className={input}
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                />
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Cuenta de gasto
                <select
                    className={input}
                    value={form.data.ledger_account_id}
                    onChange={(event) =>
                        form.setData('ledger_account_id', event.target.value)
                    }
                >
                    <option value="">Elegir…</option>
                    {accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1 text-xs font-semibold md:col-span-2">
                Justificación
                <input
                    className={input}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </label>
            <div className="flex items-end">
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing || form.data.invoice_id === ''}
                >
                    Solicitar aprobación
                </button>
            </div>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700 md:col-span-3">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

/** Adjustment rows with approve/reject for authorized reviewers. */
export function AdjustmentsTable({
    adjustments,
}: {
    adjustments: Adjustment[];
}) {
    const [notes, setNotes] = useState<Record<number, string>>({});
    const act = (id: number, action: string) =>
        router.post(
            `/receivables/adjustments/${id}/${action}`,
            { note: notes[id] ?? '' },
            { preserveScroll: true },
        );

    return (
        <Table
            headers={[
                'Solicitado',
                'Documento',
                'Tipo',
                'Monto',
                'Cuenta',
                'Justificación',
                'Estado',
                '',
            ]}
            empty={adjustments.length === 0 && 'No hay ajustes registrados.'}
        >
            {adjustments.map((row) => (
                <tr key={row.id} className="align-top">
                    <td className="px-2 py-2">
                        {formatDate(row.created_at)}
                        <p className="text-[11px] text-[#526b92]">
                            {row.requester}
                        </p>
                    </td>
                    <td className="px-2 py-2 font-bold">
                        {row.document}
                        <p className="text-[11px] font-normal text-[#526b92]">
                            {row.company} · {row.customer}
                        </p>
                    </td>
                    <td className="px-2 py-2">{row.kind_label}</td>
                    <td className="px-2 py-2 font-semibold">
                        {amount(row.amount_cents)}
                    </td>
                    <td className="px-2 py-2">{row.account}</td>
                    <td className="max-w-56 px-2 py-2">
                        {row.reason}
                        {row.review_note && (
                            <p className="text-[11px] text-[#526b92]">
                                Revisión: {row.review_note}
                            </p>
                        )}
                    </td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(tone[row.status] ?? 'slate')}
                        >
                            {row.status_label}
                        </span>
                        {row.reviewer && (
                            <p className="text-[11px] text-[#526b92]">
                                {row.reviewer} · {formatDate(row.reviewed_at)}
                            </p>
                        )}
                    </td>
                    <td className="px-2 py-2">
                        {row.can_review && (
                            <div className="grid min-w-48 gap-1">
                                <input
                                    aria-label="Nota de revisión"
                                    className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                    placeholder="Nota (obligatoria para rechazar)"
                                    value={notes[row.id] ?? ''}
                                    onChange={(event) =>
                                        setNotes({
                                            ...notes,
                                            [row.id]: event.target.value,
                                        })
                                    }
                                />
                                <div className="flex gap-1">
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() => act(row.id, 'approve')}
                                    >
                                        Aprobar
                                    </button>
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        disabled={
                                            (notes[row.id] ?? '').trim()
                                                .length < 5
                                        }
                                        onClick={() => act(row.id, 'reject')}
                                    >
                                        Rechazar
                                    </button>
                                </div>
                            </div>
                        )}
                    </td>
                </tr>
            ))}
        </Table>
    );
}

export default function ReceivablesAdjustments({
    adjustments,
    filters,
    documents,
    counterAccounts,
    kinds,
    permissions,
    notice,
    errors,
}: {
    context: ReceivablesContext;
    adjustments: Adjustment[];
    filters: { status?: string };
    documents: Receivable[];
    counterAccounts: Record<number, { id: number; label: string }[]>;
    kinds: Record<string, string>;
    permissions: ReceivablesPermissions;
    notice: string | null;
    errors: Record<string, string>;
}) {
    const href = useReceivablesHref();
    const status = filters.status ?? 'pending';

    return (
        <>
            <Head title="Ajustes y castigos de cartera" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Ajustes y castigos de cartera"
                    subtitle="Solo un ajuste aprobado por otra persona reduce el saldo; el asiento carga la cuenta de gasto elegida contra la cuenta de control de CxC"
                />
                {errors.adjustment && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {errors.adjustment}
                    </p>
                )}
                {(permissions.canAdjust || permissions.canWriteOff) &&
                    documents.length > 0 && (
                        <Card title="Solicitar ajuste o castigo">
                            <AdjustmentForm
                                documents={documents}
                                counterAccounts={counterAccounts}
                                kinds={kinds}
                                permissions={permissions}
                            />
                        </Card>
                    )}
                <Card title={`${adjustments.length} ajuste(s)`}>
                    <div className="mb-3 flex gap-1.5">
                        {[
                            ['pending', 'Pendientes'],
                            ['approved', 'Aprobados'],
                            ['rejected', 'Rechazados'],
                            ['', 'Todos'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    router.get(
                                        href('/receivables/adjustments'),
                                        {
                                            status: key,
                                        },
                                    )
                                }
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${status === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <AdjustmentsTable adjustments={adjustments} />
                </Card>
            </Shell>
        </>
    );
}
