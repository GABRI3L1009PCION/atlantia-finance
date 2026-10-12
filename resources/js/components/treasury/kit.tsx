import { useSupervisorHref } from '@/components/supervisor/kit';

export type TreasuryContext = {
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

export type TreasuryPermissions = Record<string, boolean>;

export type TreasuryCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    accounts: number;
    bank_balance_cents: number | null;
    ledger_balance_cents: number | null;
    pending_movements: number | null;
    reconciled_accounts: number | null;
    payments_to_approve: number;
    unapplied_receipts: number;
    incidents: number;
    cutoff: string;
    status: string;
    status_label: string;
    error: string | null;
    destination: string;
    company_dashboard: string;
};

export type BankAccount = {
    id: number;
    company_id: number;
    company: string;
    bank_name: string;
    account_name: string;
    label: string;
    type: string;
    currency: string;
    ledger_account: string;
    bank_balance_cents: number;
    ledger_balance_cents: number;
    difference_cents: number;
    pending_movements: number;
    last_reconciled_on: string | null;
    last_reconciliation: {
        cutoff_date: string;
        status: string;
        status_label: string;
    } | null;
    destination: string;
};

export type Suggestion = {
    type: string;
    id: number;
    label: string;
    date: string;
    amount_cents: number;
    exact_reference: boolean;
};

export type Movement = {
    id: number;
    company_id: number;
    company: string;
    account_id: number;
    account: string;
    date: string;
    reference: string;
    description: string;
    flow: 'inflow' | 'outflow';
    amount_cents: number;
    source: string;
    classification: string | null;
    classification_label: string | null;
    status: string;
    status_label: string;
    matched: { type: string; id: number } | null;
    suggestions?: Suggestion[];
};

export type Payment = {
    id: number;
    company_id: number;
    company: string;
    supplier: string;
    account: string | null;
    amount_cents: number;
    currency: string;
    method: string;
    concept: string;
    kind: string;
    kind_label: string;
    proposal: { id: number; reference: string } | null;
    priority: string;
    status: string;
    status_label: string;
    scheduled_on: string | null;
    required_approvals: number;
    approvals: number;
    approval_history: {
        name: string;
        status: string;
        level: number;
        reason: string | null;
        at: string;
    }[];
    prepared_by: number;
    preparer: string | null;
    executed_on: string | null;
    execution_reference: string | null;
    evidence_name: string | null;
    failure_reason: string | null;
    rejection_reason: string | null;
    items: {
        document_id: number;
        document: string;
        total_cents: number;
        due_on: string;
        amount_cents: number;
    }[];
    destination: string;
};

export type Receipt = {
    id: number;
    company_id: number;
    company: string;
    customer: string;
    account: string;
    method: string;
    received_on: string;
    reference: string;
    amount_cents: number;
    applied_cents: number;
    unapplied_cents: number;
    status: string;
    status_label: string;
    invoices: { number: string; amount_cents: number }[];
};

export type Reconciliation = {
    id: number;
    company_id: number;
    company: string;
    account_id: number;
    account: string;
    cutoff_date: string;
    status: string;
    status_label: string;
    bank_balance_cents: number;
    ledger_balance_cents: number;
    difference_cents: number;
    stale: boolean;
    calculated_at: string | null;
    prepared_by: number;
    preparer: string | null;
    approver: string | null;
    approved_at: string | null;
    review_note: string | null;
    destination: string;
};

export type AccountOption = {
    id: number;
    label: string;
    company_id: number;
    company: string;
};

export type ScheduleRow = {
    date: string;
    kind: string;
    kind_label: string;
    company_id: number;
    company: string;
    party: string;
    amount_cents: number;
    overdue: boolean;
    destination: string;
};

export type CashFlowRow = {
    company_id: number;
    company: string;
    realized_in_cents: number;
    realized_out_cents: number;
    realized_net_cents: number;
    forecast_in_cents: number;
    forecast_out_cents: number;
    forecast_net_cents: number;
};

/** Treasury screens keep the selected period like the other panels. */
export const useTreasuryHref = useSupervisorHref;

/** Money of a single company; a figure that could not be computed is never shown as zero. */
export function amount(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) {
        return 'No disponible';
    }

    return `Q ${new Intl.NumberFormat('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100)}`;
}

export const companyTone: Record<string, string> = {
    reconciled: 'green',
    in_progress: 'blue',
    with_differences: 'red',
    pending: 'amber',
    no_accounts: 'slate',
    error: 'violet',
};
export const movementTone: Record<string, string> = {
    pending: 'amber',
    matched: 'green',
    unmatched: 'red',
};
export const paymentTone: Record<string, string> = {
    pending_approval: 'amber',
    approved: 'blue',
    scheduled: 'violet',
    executed: 'green',
    failed: 'red',
    rejected: 'red',
    cancelled: 'slate',
};
export const receiptTone: Record<string, string> = {
    applied: 'green',
    partially_applied: 'amber',
    unapplied: 'red',
};
export const reconciliationTone: Record<string, string> = {
    in_progress: 'blue',
    with_differences: 'red',
    submitted: 'violet',
    changes_requested: 'amber',
    approved: 'green',
};

export const methodLabel: Record<string, string> = {
    transfer: 'Transferencia',
    deposit: 'Depósito',
    check: 'Cheque',
    cash: 'Efectivo',
    card: 'Tarjeta',
    ach: 'ACH',
};
