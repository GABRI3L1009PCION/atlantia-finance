import { Head, router, usePage } from '@inertiajs/react';
import { Building2, CalendarDays } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    input,
} from '@/components/office-admin/kit';

export { formatDate, relativeTime } from '@/components/office-admin/kit';
export { Card, Table, toneBadge } from '@/components/supervisor/kit';
export { Metric, ViewAll } from '@/components/payroll/kit';

export type ClientContext = {
    company: { id: number; public_id: string; name: string; tax_id: string };
    companies: { id: number; name: string; tax_id: string }[];
    period: string | null;
    fel_enabled: boolean;
    badges: {
        pending: number;
        document_requests: number;
        approvals: number;
        messages: number;
    };
};

export type ClientPermissions = Record<string, boolean>;

export type PendingItem = {
    id: string;
    type: string;
    type_label: string;
    title: string;
    description: string | null;
    source_label: string;
    due_date: string | null;
    overdue: boolean;
    status: string;
    status_label: string;
    action_label: string;
    destination: string;
};

export type ClientDocument = {
    id: number;
    title: string;
    filename: string;
    category: string;
    category_label: string;
    period: string | null;
    uploaded_at: string;
    uploaded_by: string;
    status: string;
    status_label: string;
    processed_at: string | null;
    review_note: string | null;
    requested_by: string | null;
    request_id: number | null;
    current_version: number;
    versions: {
        version: number;
        file_name: string;
        reason: string | null;
        uploaded_at: string;
        download: string;
    }[];
};

export type ClientRequest = {
    id: number;
    code: string;
    type: string;
    type_label: string;
    title: string;
    description: string | null;
    document_type: string | null;
    period: string | null;
    requested_by: string;
    due_date: string | null;
    overdue: boolean;
    status: string;
    status_label: string;
    response: string | null;
    review_note: string | null;
    responded_at: string | null;
    completed_at: string | null;
    source_label: string | null;
    documents: number;
    created_at: string;
    origin?: string;
};

export type Approval = {
    id: number;
    code: string;
    type: string;
    type_label: string;
    title: string;
    description: string | null;
    requested_by: string;
    due_date: string | null;
    overdue: boolean;
    status: string;
    status_label: string;
    response: string | null;
    responded_at: string | null;
    source_label: string | null;
};

export type PublishedReport = {
    id: number;
    report_type: string;
    title: string;
    period: string;
    version: number;
    published_at: string;
    replaced: boolean;
    downloadable: boolean;
    destination: string;
};

export type Obligation = {
    id: number;
    name: string;
    period: string;
    due_date: string;
    status: string;
    status_label: string;
    documentation: string;
    missing: number;
    filed_on: string | null;
    note: string | null;
};

export type Conversation = {
    id: number;
    subject: string;
    last_message_at: string | null;
    unread: number;
    preview: string | null;
    last_sender: string | null;
    messages: {
        id: number;
        body: string;
        side: string;
        internal: boolean;
        author: string;
        at: string;
    }[];
};

export const requestTone: Record<string, string> = {
    pending: 'red',
    in_progress: 'blue',
    submitted: 'blue',
    under_review: 'blue',
    more_information_required: 'amber',
    completed: 'green',
    cancelled: 'slate',
    expired: 'slate',
};

export const documentTone: Record<string, string> = {
    received: 'blue',
    under_review: 'blue',
    more_information_required: 'amber',
    accepted: 'green',
    processed: 'green',
    rejected: 'red',
    archived: 'slate',
};

export const approvalTone: Record<string, string> = {
    pending: 'red',
    viewed: 'amber',
    approved: 'green',
    rejected: 'slate',
    more_information_required: 'amber',
    expired: 'slate',
    cancelled: 'slate',
};

export const obligationTone: Record<string, string> = {
    in_progress: 'blue',
    information_pending: 'amber',
    completed: 'green',
};

/** Selector of the client company the portal works on; switching clears every company-dependent view. */
export function CompanySwitcher() {
    const page = usePage();
    const context = (page.props as { context?: ClientContext }).context;

    if (!context) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-[#e7e1dd] bg-white px-3 py-2 text-sm shadow-sm">
            <Building2 className="size-4 text-[#640000]" />
            {context.companies.length > 1 ? (
                <select
                    aria-label="Empresa"
                    className={`${input} h-8 w-auto min-w-56 font-bold`}
                    value={context.company.id}
                    onChange={(event) =>
                        router.post('/client/company/switch', {
                            company_id: Number(event.target.value),
                        })
                    }
                >
                    {context.companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.name}
                        </option>
                    ))}
                </select>
            ) : (
                <span className="font-bold text-[#061633]">
                    {context.company.name}
                </span>
            )}
            <span className="text-xs text-[#526b92]">
                NIT {context.company.tax_id}
            </span>
            {context.period && (
                <span className="ml-auto inline-flex items-center gap-1 text-xs font-semibold text-[#526b92]">
                    <CalendarDays className="size-3.5" />
                    {context.period}
                </span>
            )}
        </div>
    );
}

/** Common frame of every client portal screen: company selector, title and server notices. */
export function ClientPage({
    title,
    heading,
    subtitle,
    notice,
    actions,
    children,
}: {
    title: string;
    heading?: string;
    subtitle: string;
    notice?: string | null;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <>
            <Head title={title} />
            <Shell>
                <CompanySwitcher />
                <PageHeader
                    title={heading ?? title}
                    subtitle={subtitle}
                    actions={actions}
                />
                <Notice message={notice ?? null} />
                {children}
            </Shell>
        </>
    );
}

export function useFocusId(key: string): number | null {
    const page = usePage();
    const value = new URL(page.url, 'https://atlantia.local').searchParams.get(
        key,
    );

    return value ? Number(value) : null;
}
