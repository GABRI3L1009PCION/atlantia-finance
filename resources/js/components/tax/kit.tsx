import { useSupervisorHref } from '@/components/supervisor/kit';

export type TaxContext = {
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

export type TaxPermissions = Record<string, boolean>;

export type FiscalStage = {
    key: string;
    label: string;
    status: string;
    detail: string;
};

export type FiscalCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    period: string;
    error: string | null;
    stages: FiscalStage[];
    progress: number | null;
    status: string;
    status_label: string;
    obligations_total: number;
    obligations_completed: number;
    vat_book: FiscalStage | null;
    fel: FiscalStage | null;
    documents: FiscalStage | null;
    incidents: number;
    overdue: boolean;
    blocked: boolean;
    due_on: string | null;
    destination: string;
    company_dashboard: string;
};

export type Obligation = {
    id: number;
    company_id: number;
    company_name: string | null;
    type: string;
    name: string;
    reference_period: string;
    due_on: string;
    internal_due_on: string;
    review_due_on: string;
    days_left: number;
    overdue: boolean;
    internal_overdue: boolean;
    status: string;
    status_label: string;
    assignee_name: string | null;
    reviewer_name: string | null;
    stale: boolean;
    filing_reference: string | null;
    filed_on: string | null;
    last_observation: string | null;
    destination: string;
};

export type FelDocument = {
    id: number;
    company: string;
    document: string;
    type: string;
    issued_on: string;
    receiver: string;
    total_cents: number;
    status: string;
    status_label: string;
    environment: string;
    error_code: string | null;
    authorization: string | null;
    destination: string;
};

export type FiscalDocument = {
    key: string;
    company: string;
    type: string;
    description: string;
    source: string;
    date: string;
    status_label: string;
    destination: string;
};

/** Fiscal screens keep the selected period like the other panels. */
export const useTaxHref = useSupervisorHref;

export const obligationTone: Record<string, string> = {
    pending: 'amber',
    in_progress: 'blue',
    ready_for_review: 'violet',
    changes_requested: 'red',
    approved: 'green',
    completed: 'green',
    not_applicable: 'slate',
    cancelled: 'slate',
};
export const fiscalStatusTone: Record<string, string> = {
    not_started: 'slate',
    in_progress: 'blue',
    with_pending_items: 'amber',
    ready_for_review: 'violet',
    changes_requested: 'red',
    approved: 'green',
    completed: 'green',
    error: 'violet',
};
export const stageBadgeTone: Record<string, string> = {
    COMPLETE: 'green',
    IN_PROGRESS: 'blue',
    PENDING: 'amber',
    BLOCKED: 'red',
    NOT_APPLICABLE: 'slate',
    ERROR: 'violet',
};
export const felTone: Record<string, string> = {
    failed: 'red',
    pending: 'amber',
    processing: 'blue',
    certified: 'green',
    cancelled: 'slate',
    draft: 'slate',
};
