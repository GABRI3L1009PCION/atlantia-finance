import { useSupervisorHref } from '@/components/supervisor/kit';

export { amount } from '@/components/receivables/kit';
export type {
    ReceivablesContext as PayablesContext,
    ReceivablesPermissions as PayablesPermissions,
} from '@/components/receivables/kit';

export type PayablesCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    suppliers_with_balance: number | null;
    open_documents: number | null;
    overdue_documents: number | null;
    total_cents: number | null;
    current_cents: number | null;
    overdue_cents: number | null;
    proposals_waiting_approval: number;
    unapplied_advances: number;
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

export type Payable = {
    id: number;
    public_id: string;
    company_id: number;
    company: string;
    supplier_id: number;
    supplier: string;
    supplier_tax_id: string | null;
    document: string;
    fiscal: string | null;
    issue_date: string;
    due_date: string;
    currency: string;
    exchange_rate: string;
    foreign_original_cents: number | null;
    original_cents: number;
    paid_cents: number;
    credited_cents: number;
    advances_cents: number;
    adjusted_cents: number;
    outstanding_cents: number;
    committed_cents: number;
    status: string;
    status_label: string;
    overdue: boolean;
    overdue_days: number;
    days_to_due: number;
    disputed: boolean;
    in_proposal: boolean;
    destination: string;
    purchase_destination: string;
};

export type SupplierBalance = {
    id: number;
    company_id: number;
    company: string;
    name: string;
    tax_id: string | null;
    payment_terms_days: number | null;
    documents: number;
    total_cents: number;
    overdue_cents: number;
    current_cents: number;
    oldest_due_date: string | null;
    next_due_date: string | null;
    max_overdue_days: number;
    last_payment: { date: string; amount_cents: number } | null;
    advances_cents: number;
    disputed: boolean;
    status: string;
    status_label: string;
    destination: string;
};

export type Proposal = {
    id: number;
    company_id: number;
    company: string;
    reference: string;
    proposed_date: string;
    priority: string;
    status: string;
    status_label: string;
    total_cents: number;
    suppliers_count: number;
    documents_count: number;
    advances_count: number;
    notes: string | null;
    preparer: string | null;
    approver: string | null;
    approved_at: string | null;
    review_note: string | null;
    created_at: string;
    destination: string;
    can_review?: boolean;
};

export type PaymentRow = {
    key: string;
    id: number;
    company_id: number;
    company: string;
    supplier: string;
    kind: string;
    kind_label: string;
    date: string;
    reference: string;
    amount_cents: number;
    account: string;
    status: string;
    status_label: string;
    applied_cents: number | null;
    unapplied_cents: number | null;
    executor: string | null;
    approvers: string[];
    proposal: string | null;
    source: string;
    destination: string;
};

export type Advance = {
    id: number;
    company_id: number;
    company: string;
    supplier_id: number;
    supplier: string;
    origin: string;
    origin_label: string;
    reference: string;
    paid_on: string;
    currency: string;
    amount_cents: number;
    applied_cents: number;
    outstanding_cents: number;
    age_days: number;
    status: string;
    status_label: string;
    payment_instruction_id: number | null;
    applications: {
        id: number;
        document_id: number;
        document: string;
        amount_cents: number;
        applied_on: string;
        reversed: boolean;
    }[];
    destination: string;
};

export type PayableDispute = {
    id: number;
    company_id: number;
    company: string;
    supplier_id: number;
    supplier: string;
    document_id: number;
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

export type CreditNote = {
    id: number;
    company: string;
    supplier: string;
    document_id: number;
    document: string;
    number: string;
    date: string;
    amount_cents: number;
    reason: string;
    status: string;
    destination: string;
};

/** Payables screens keep the selected period like the other panels. */
export const usePayablesHref = useSupervisorHref;

export const companyTone: Record<string, string> = {
    no_activity: 'slate',
    current: 'green',
    with_upcoming_due: 'blue',
    with_overdue: 'amber',
    payment_preparation: 'blue',
    with_unapplied_payments: 'amber',
    with_advances: 'violet',
    with_disputes: 'violet',
    reconciliation_difference: 'red',
    attention_required: 'red',
    error: 'violet',
};
export const documentTone: Record<string, string> = {
    open: 'blue',
    partially_paid: 'amber',
    paid: 'green',
    credited: 'green',
    written_off: 'slate',
    cancelled: 'slate',
};
export const proposalTone: Record<string, string> = {
    draft: 'slate',
    pending_approval: 'amber',
    approved: 'blue',
    rejected: 'red',
    sent_to_treasury: 'violet',
    partially_executed: 'amber',
    completed: 'green',
    cancelled: 'slate',
};
export const paymentTone: Record<string, string> = {
    draft: 'slate',
    pending_approval: 'amber',
    approved: 'blue',
    scheduled: 'blue',
    executed: 'green',
    rejected: 'red',
    failed: 'red',
    cancelled: 'slate',
};
export const advanceTone: Record<string, string> = {
    open: 'amber',
    partially_applied: 'blue',
    applied: 'green',
    cancelled: 'slate',
};
export const priorityLabels: Record<string, string> = {
    low: 'Baja',
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
};

/** "Vence hoy", "Vence en n días" or "n días vencido", from the backend's day counts. */
export function dueLabel(document: Payable): [string, string] {
    if (document.overdue) {
        return [`${document.overdue_days} día(s) vencido`, 'red'];
    }

    if (document.days_to_due === 0) {
        return ['Vence hoy', 'red'];
    }

    return [`Vence en ${document.days_to_due} día(s)`, 'amber'];
}

/** Status filter chips shared by the list screens. */
export function FilterChips({
    options,
    current,
    onSelect,
}: {
    options: [string, string][];
    current: string;
    onSelect: (key: string) => void;
}) {
    return (
        <div className="mb-3 flex flex-wrap gap-1.5">
            {options.map(([key, label]) => (
                <button
                    key={key}
                    type="button"
                    onClick={() => onSelect(key)}
                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${current === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
