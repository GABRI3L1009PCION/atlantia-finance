import { router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { useSupervisorHref } from '@/components/supervisor/kit';

export { amount } from '@/components/receivables/kit';
export { Metric, ViewAll } from '@/components/payroll/kit';
export type {
    ReceivablesContext as AuditorContext,
    ReceivablesPermissions as AuditorPermissions,
} from '@/components/receivables/kit';

export type Engagement = {
    id: number;
    public_id: string;
    code: string;
    company_id: number;
    company: string;
    type: string;
    type_label: string;
    title: string;
    period_start: string;
    period_end: string;
    period: string;
    progress: number;
    findings_count: number;
    evidence_pending: number;
    risk_level: string;
    risk_label: string;
    status: string;
    status_label: string;
    deadline: string | null;
    overdue: boolean;
    lead_auditor: string;
    destination: string;
};

export type Procedure = {
    id: number;
    engagement_id: number;
    engagement: string;
    company: string;
    code: string;
    title: string;
    area: string;
    area_label: string;
    type: string;
    type_label: string;
    objective: string | null;
    description: string | null;
    assigned_to: number | null;
    responsible: string | null;
    due_date: string | null;
    overdue: boolean;
    weight: number;
    required: boolean;
    status: string;
    status_label: string;
    result: string | null;
    result_label: string | null;
    conclusion: string | null;
    status_reason: string | null;
    sample: {
        count: number;
        confirmed: boolean;
        pending: number;
        exceptions: number;
    } | null;
    evidence: { pending: number; accepted: number };
    destination: string;
};

export type EvidenceRequest = {
    id: number;
    code: string;
    engagement_id: number;
    engagement: string;
    company: string;
    procedure_id: number | null;
    finding_id: number | null;
    title: string;
    description: string;
    requested_from_id: number;
    requested_from: string;
    requested_at: string;
    due_date: string;
    overdue: boolean;
    status: string;
    status_label: string;
    submission_note: string | null;
    submitted_at: string | null;
    source_label: string | null;
    source_href: string | null;
    file_name: string | null;
    download: string | null;
    review_note: string | null;
    version: number;
    changed_since_audit: boolean | null;
    destination: string;
};

export type Finding = {
    id: number;
    code: string;
    engagement_id: number;
    procedure_id: number | null;
    engagement: string;
    company: string;
    area: string;
    area_label: string;
    title: string;
    severity: string;
    severity_label: string;
    status: string;
    status_label: string;
    criterion: string | null;
    condition: string | null;
    cause: string | null;
    effect_risk: string | null;
    recommendation: string | null;
    responsible_id: number | null;
    responsible: string | null;
    due_date: string | null;
    overdue: boolean;
    days_open: number | null;
    created_at: string;
    source: string | null;
    verification_note: string | null;
    accepted_risk_reason: string | null;
    destination: string;
};

export type SampleItem = {
    id: number;
    source_type: string;
    source_id: number;
    reference: string;
    date: string | null;
    amount_cents: number | null;
    result: string;
    result_label: string;
    note: string | null;
    changed: boolean | null;
    trace: string;
};

export type Sample = {
    id: number;
    code: string;
    procedure_id: number;
    universe: string;
    universe_from: string;
    universe_to: string;
    method: string;
    note: string | null;
    seed: number | null;
    population_count: number;
    population_cents: number | null;
    sample_count: number;
    status: string;
    confirmed_at: string | null;
    confirmed_by: string | null;
    items: SampleItem[];
};

export type Paper = {
    id: number;
    code: string;
    version: number;
    title: string;
    procedure_id: number | null;
    objective: string | null;
    methodology: string | null;
    work_performed: string | null;
    results: string | null;
    conclusion: string | null;
    references: { type: string; label: string; href?: string | null }[];
    status: string;
    status_label: string;
    prepared_by: string;
    reviewed_by: string | null;
    review_note: string | null;
    superseded: boolean;
    updated_at: string;
};

export type Response = {
    id: number;
    finding_id: number;
    kind: string;
    response: string;
    action_plan: string | null;
    acceptance: string | null;
    responsible_name: string | null;
    target_date: string | null;
    by: string;
    at: string;
};

export type Report = {
    id: number;
    version: number;
    status: string;
    status_label: string;
    content: Record<string, unknown> | null;
    generated_by: string;
    generated_at: string;
    reviewed_by: string | null;
    issued_by: string | null;
    issued_at: string | null;
    recipients: string | null;
    download: string;
    engagement?: string;
    engagement_id?: number;
    company?: string;
};

export const useAuditorHref = useSupervisorHref;

export const engagementTone: Record<string, string> = {
    draft: 'slate',
    planning: 'amber',
    in_progress: 'blue',
    evidence_collection: 'blue',
    findings_review: 'violet',
    report_draft: 'violet',
    management_response: 'amber',
    final_review: 'green',
    completed: 'green',
    closed: 'slate',
    blocked: 'red',
    suspended: 'red',
    cancelled: 'slate',
};

export const procedureTone: Record<string, string> = {
    not_started: 'slate',
    in_progress: 'blue',
    waiting_evidence: 'violet',
    exception_found: 'red',
    completed: 'green',
    blocked: 'red',
    not_applicable: 'slate',
};

export const procedureColor: Record<string, string> = {
    completed: '#15924a',
    in_progress: '#1f6fe0',
    not_started: '#f0a51b',
    waiting_evidence: '#7c4ddb',
    exception_found: '#ef3b3b',
    blocked: '#b91c1c',
    not_applicable: '#94a3b8',
};

export const evidenceTone: Record<string, string> = {
    requested: 'red',
    submitted: 'blue',
    under_review: 'blue',
    accepted: 'green',
    rejected: 'red',
    more_information_required: 'amber',
    cancelled: 'slate',
};

export const findingTone: Record<string, string> = {
    draft: 'slate',
    open: 'red',
    response_requested: 'red',
    remediation_in_progress: 'amber',
    ready_for_verification: 'blue',
    verified: 'green',
    closed: 'green',
    accepted_risk: 'violet',
    rejected: 'slate',
    cancelled: 'slate',
};

export const severityTone: Record<string, string> = {
    low: 'green',
    medium: 'amber',
    high: 'red',
    critical: 'red',
};

export const reportTone: Record<string, string> = {
    draft: 'slate',
    in_review: 'amber',
    awaiting_responses: 'amber',
    final: 'blue',
    issued: 'green',
    archived: 'slate',
};

/** Progress bar whose value comes from the backend (AuditProgressService). */
export function Progress({ value }: { value: number }) {
    const color =
        value >= 80
            ? 'bg-emerald-500'
            : value >= 50
              ? 'bg-blue-500'
              : value >= 30
                ? 'bg-amber-400'
                : 'bg-red-500';

    return (
        <div className="flex items-center gap-1.5">
            <span className="w-9 text-right font-bold">{value}%</span>
            <div className="h-2 w-16 overflow-hidden rounded bg-slate-100">
                <div
                    className={`h-full ${color}`}
                    style={{ width: `${value}%` }}
                />
            </div>
        </div>
    );
}

/** Company and period selector of the read-only review screens (the backend clamps it to the user's access). */
export function ReviewFilter({
    path,
    companyId,
    from,
    to,
    extra,
}: {
    path: string;
    companyId: number | null;
    from?: string;
    to?: string;
    extra?: Record<string, string | number | null | undefined>;
}) {
    const page = usePage();
    const href = useAuditorHref();
    const companies =
        (
            page.props as {
                context?: { companies?: { id: number; name: string }[] };
            }
        ).context?.companies ?? [];
    const [form, setForm] = useState({
        company_id: companyId ? String(companyId) : '',
        from: from ?? '',
        to: to ?? '',
    });

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(href(path), {
                    ...Object.fromEntries(
                        Object.entries({ ...extra, ...form }).filter(
                            ([, value]) =>
                                value !== '' &&
                                value !== null &&
                                value !== undefined,
                        ),
                    ),
                });
            }}
        >
            <label className="grid min-w-56 gap-1 text-xs font-semibold">
                Empresa
                <select
                    className={input}
                    value={form.company_id}
                    onChange={(event) =>
                        setForm({ ...form, company_id: event.target.value })
                    }
                >
                    {companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.name}
                        </option>
                    ))}
                </select>
            </label>
            {from !== undefined && (
                <label className="grid gap-1 text-xs font-semibold">
                    Desde
                    <input
                        type="date"
                        className={input}
                        value={form.from}
                        onChange={(event) =>
                            setForm({ ...form, from: event.target.value })
                        }
                    />
                </label>
            )}
            {to !== undefined && (
                <label className="grid gap-1 text-xs font-semibold">
                    {from === undefined ? 'Corte' : 'Hasta'}
                    <input
                        type="date"
                        className={input}
                        value={form.to}
                        onChange={(event) =>
                            setForm({ ...form, to: event.target.value })
                        }
                    />
                </label>
            )}
            <button type="submit" className={secondaryButton}>
                Consultar
            </button>
        </form>
    );
}

/** Small action button that posts to the server (optionally asking for a note first). */
export function ActionButton({
    url,
    label,
    data = {},
    ask,
    tone = 'secondary',
    method = 'post',
}: {
    url: string;
    label: ReactNode;
    data?: Record<string, unknown>;
    ask?: string;
    tone?: 'primary' | 'secondary';
    method?: 'post' | 'delete';
}) {
    return (
        <button
            type="button"
            className={tone === 'primary' ? primaryButton : secondaryButton}
            onClick={() => {
                let payload = data;

                if (ask) {
                    const answer = window.prompt(ask);

                    if (answer === null) {
                        return;
                    }

                    payload = { ...data, note: answer, reason: answer };
                }

                if (method === 'delete') {
                    router.delete(url, { preserveScroll: true });
                } else {
                    router.post(url, payload as never, {
                        preserveScroll: true,
                    });
                }
            }}
        >
            {label}
        </button>
    );
}

export function Field({
    label,
    children,
    wide = false,
}: {
    label: string;
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <label
            className={`grid gap-1 text-xs font-semibold ${wide ? 'md:col-span-2 xl:col-span-3' : ''}`}
        >
            {label}
            {children}
        </label>
    );
}

export const textarea =
    'min-h-20 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15';
