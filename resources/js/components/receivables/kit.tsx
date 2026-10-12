import { router, useForm } from '@inertiajs/react';
import { input, primaryButton } from '@/components/office-admin/kit';
import { useSupervisorHref } from '@/components/supervisor/kit';

export type ReceivablesContext = {
    periods: {
        key: string;
        name: string;
        starts_on: string;
        ends_on: string;
    }[];
    period: {
        key: string;
        name: string;
        starts_on: string;
        ends_on: string;
    } | null;
    companies: { id: number; name: string }[];
    badges: Record<string, number>;
};

export type ReceivablesPermissions = Record<string, boolean>;

export type ReceivablesCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    customers_with_balance: number | null;
    open_documents: number | null;
    overdue_documents: number | null;
    total_cents: number | null;
    current_cents: number | null;
    overdue_cents: number | null;
    overdue_customers: number | null;
    unapplied_receipts: number;
    incidents: number;
    reconciliation: {
        status: string;
        status_label: string;
        difference_cents: number | null;
    } | null;
    status: string;
    status_label: string;
    error: string | null;
    destination: string;
};

export type Receivable = {
    id: number;
    company_id: number;
    company: string;
    customer_id: number;
    customer: string;
    customer_tax_id: string | null;
    document: string;
    issue_date: string;
    due_date: string;
    currency: string;
    exchange_rate: string;
    foreign_original_cents: number | null;
    original_cents: number;
    applied_cents: number;
    credited_cents: number;
    adjusted_cents: number;
    outstanding_cents: number;
    status: string;
    status_label: string;
    overdue: boolean;
    overdue_days: number;
    days_to_due: number;
    disputed: boolean;
    destination: string;
    invoice_destination: string;
};

export type CustomerBalance = {
    id: number;
    company_id: number;
    company: string;
    name: string;
    tax_id: string | null;
    documents: number;
    total_cents: number;
    overdue_cents: number;
    current_cents: number;
    oldest_due_date: string | null;
    max_overdue_days: number;
    disputed: boolean;
    broken_promise: boolean;
    last_activity: { channel: string; result: string; at: string } | null;
    next_action: {
        action: string | null;
        at: string | null;
        overdue: boolean;
    } | null;
    active_promise: {
        amount_cents: number;
        promised_date: string;
        status: string;
    } | null;
    priority: string;
    priority_reasons: string[];
    status: string;
    status_label: string;
    destination: string;
};

export type Activity = {
    id: number;
    company: string;
    customer_id: number;
    customer: string;
    document: string | null;
    channel: string;
    channel_label: string;
    result: string;
    result_label: string;
    notes: string | null;
    next_action: string | null;
    next_action_at: string | null;
    status: string;
    status_label: string;
    corrects_id: number | null;
    performer: string;
    performed_at: string;
    destination: string;
};

export type PromiseRow = {
    id: number;
    company: string;
    customer_id: number;
    customer: string;
    amount_cents: number;
    promised_date: string;
    fulfilled_cents: number;
    status: string;
    status_label: string;
    documents: string[];
    notes: string | null;
    created_by: string;
    destination: string;
};

export type SharedReceipt = {
    id: number;
    company_id: number;
    company: string;
    customer: string;
    identified: boolean;
    received_on: string;
    amount_cents: number;
    applied_cents: number;
    unapplied_cents: number;
    account: string;
    reference: string;
    status: string;
    status_label: string;
    applications: {
        invoice_id: number;
        document: string;
        amount_cents: number;
    }[];
};

export type Dispute = {
    id: number;
    company: string;
    customer: string;
    invoice_id: number;
    document: string;
    outstanding_cents: number | null;
    reason: string;
    description: string;
    status: string;
    status_label: string;
    responsible: string | null;
    opened_at: string;
    resolution: string | null;
    destination: string;
};

export type CollectionOptions = {
    channels: Record<string, string>;
    results: Record<string, string>;
    reasons: Record<string, string>;
};

/** Receivables screens keep the selected period like the other panels. */
export const useReceivablesHref = useSupervisorHref;

/** Money of a single company; a figure that could not be computed is never shown as zero. */
export function amount(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) {
        return 'No disponible';
    }

    return `Q ${new Intl.NumberFormat('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100)}`;
}

export const companyTone: Record<string, string> = {
    no_activity: 'slate',
    current: 'green',
    with_upcoming_due: 'blue',
    with_overdue: 'amber',
    collection_in_progress: 'blue',
    with_disputes: 'violet',
    with_unapplied_receipts: 'amber',
    reconciliation_difference: 'red',
    attention_required: 'red',
    error: 'violet',
};
export const documentTone: Record<string, string> = {
    open: 'blue',
    partially_paid: 'amber',
    paid: 'green',
    credited: 'green',
    cancelled: 'slate',
};
export const promiseTone: Record<string, string> = {
    pending: 'amber',
    partially_fulfilled: 'blue',
    fulfilled: 'green',
    broken: 'red',
    cancelled: 'slate',
};
export const activityTone: Record<string, string> = {
    open: 'blue',
    overdue: 'red',
    done: 'green',
    superseded: 'slate',
};
export const priorityTone: Record<string, [string, string]> = {
    critical: ['Crítica', 'red'],
    high: ['Alta', 'amber'],
    normal: ['Normal', 'blue'],
    low: ['Baja', 'slate'],
};

/** "Vence hoy", "Por vencer (n días)" or "n días vencido", from the backend's day counts. */
export function dueLabel(document: Receivable): [string, string] {
    if (document.overdue) {
        return [`${document.overdue_days} día(s) vencido`, 'red'];
    }

    if (document.days_to_due === 0) {
        return ['Vence hoy', 'red'];
    }

    return [`Vence en ${document.days_to_due} día(s)`, 'amber'];
}

/** Registers a collection follow-up (optionally a promise to pay) for a customer. */
export function CollectionForm({
    customers,
    documents = [],
    options,
    defaultCustomerId,
}: {
    customers: { id: number; name: string; company: string }[];
    documents?: { id: number; document: string; customer_id: number }[];
    options: CollectionOptions;
    defaultCustomerId?: number;
}) {
    const form = useForm({
        customer_id: defaultCustomerId
            ? String(defaultCustomerId)
            : customers[0]
              ? String(customers[0].id)
              : '',
        invoice_id: '',
        channel: 'call',
        result: 'client_contacted',
        notes: '',
        next_action: '',
        next_action_at: '',
        promise_amount: '',
        promise_date: '',
    });
    const customerDocuments = documents.filter(
        (document) => String(document.customer_id) === form.data.customer_id,
    );

    return (
        <form
            className="grid gap-2 md:grid-cols-2 xl:grid-cols-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((values) => ({
                    ...values,
                    invoice_id: values.invoice_id || null,
                    next_action: values.next_action || null,
                    next_action_at: values.next_action_at || null,
                    promise_amount: values.promise_amount || null,
                    promise_date: values.promise_date || null,
                }));
                form.post('/receivables/collections', {
                    preserveScroll: true,
                    onSuccess: () =>
                        form.reset(
                            'notes',
                            'next_action',
                            'next_action_at',
                            'promise_amount',
                            'promise_date',
                        ),
                });
            }}
        >
            {!defaultCustomerId && (
                <label className="grid gap-1 text-xs font-semibold">
                    Cliente
                    <select
                        className={input}
                        value={form.data.customer_id}
                        onChange={(event) =>
                            form.setData('customer_id', event.target.value)
                        }
                    >
                        {customers.map((customer) => (
                            <option key={customer.id} value={customer.id}>
                                {customer.company} · {customer.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {customerDocuments.length > 0 && (
                <label className="grid gap-1 text-xs font-semibold">
                    Documento (opcional)
                    <select
                        className={input}
                        value={form.data.invoice_id}
                        onChange={(event) =>
                            form.setData('invoice_id', event.target.value)
                        }
                    >
                        <option value="">Todos</option>
                        {customerDocuments.map((document) => (
                            <option key={document.id} value={document.id}>
                                {document.document}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <label className="grid gap-1 text-xs font-semibold">
                Canal
                <select
                    className={input}
                    value={form.data.channel}
                    onChange={(event) =>
                        form.setData('channel', event.target.value)
                    }
                >
                    {Object.entries(options.channels).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Resultado
                <select
                    className={input}
                    value={form.data.result}
                    onChange={(event) =>
                        form.setData('result', event.target.value)
                    }
                >
                    {Object.entries(options.results).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1 text-xs font-semibold md:col-span-2">
                Notas
                <input
                    className={input}
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Próxima acción
                <input
                    className={input}
                    placeholder="Ej. Llamar para confirmar pago"
                    value={form.data.next_action}
                    onChange={(event) =>
                        form.setData('next_action', event.target.value)
                    }
                />
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Fecha próxima acción
                <input
                    type="datetime-local"
                    className={input}
                    value={form.data.next_action_at}
                    onChange={(event) =>
                        form.setData('next_action_at', event.target.value)
                    }
                />
            </label>
            {form.data.result === 'promise_to_pay' && (
                <>
                    <label className="grid gap-1 text-xs font-semibold">
                        Monto prometido (Q)
                        <input
                            className={input}
                            inputMode="decimal"
                            value={form.data.promise_amount}
                            onChange={(event) =>
                                form.setData(
                                    'promise_amount',
                                    event.target.value,
                                )
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-xs font-semibold">
                        Fecha prometida
                        <input
                            type="date"
                            className={input}
                            value={form.data.promise_date}
                            onChange={(event) =>
                                form.setData('promise_date', event.target.value)
                            }
                        />
                    </label>
                </>
            )}
            <div className="flex items-end">
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing || form.data.customer_id === ''}
                >
                    Registrar gestión
                </button>
            </div>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700 md:col-span-2 xl:col-span-4">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

/** Sends a reminder template to a customer's e-mail. */
export function sendReminder(customerId: number, templateId: number): void {
    router.post(
        '/receivables/reminders',
        { customer_id: customerId, template_id: templateId },
        { preserveScroll: true },
    );
}
