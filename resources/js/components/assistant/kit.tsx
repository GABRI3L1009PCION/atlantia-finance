import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    CircleSlash,
    Clock3,
    XCircle,
} from 'lucide-react';
import { useSupervisorHref } from '@/components/supervisor/kit';

export type AssistantContext = {
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
    modules: { key: string; label: string }[];
    badges: Record<string, number>;
};

export type AssistantPermissions = Record<string, boolean>;

export type Stage = {
    key: string;
    label: string;
    status: string;
    detail: string;
};

export type AssistantCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    accountant: { id: number; name: string } | null;
    period_id: number | null;
    period_name: string | null;
    status: string;
    status_label: string;
    overdue: boolean;
    blocked: boolean;
    due_at: string | null;
    progress: number | null;
    stages: Stage[];
    pending_items: number;
    observations: number;
    next_action: string;
    can_submit: boolean;
    destination: string;
};

export type AssistantTask = {
    id: number;
    company_id: number;
    company_name: string | null;
    period_name: string | null;
    module_label: string;
    title: string;
    priority: string;
    status: string;
    status_label: string;
    due_at: string | null;
    overdue_days: number;
    remaining_days: number | null;
    reviewer_name: string | null;
    source: string;
    destination: string;
    continue_to: string;
    can_start: boolean;
    can_submit: boolean;
};

export type PendingDocument = {
    key: string;
    company: string;
    type: string;
    description: string;
    requested_at: string;
    status: string;
    status_label: string;
    source: string;
    destination: string | null;
};

export type Reconciliation = {
    key: string;
    company: string;
    account: string;
    type: string;
    auxiliary_cents: number | null;
    ledger_cents: number | null;
    difference_cents: number | null;
    status: string;
    status_label: string;
    destination: string | null;
};

/** Assistant screens keep the selected period like the other panels. */
export const useAssistantHref = useSupervisorHref;

export const priorityTone: Record<string, [string, string]> = {
    urgent: ['Urgente', 'red'],
    critical: ['Crítica', 'red'],
    high: ['Alta', 'red'],
    normal: ['Media', 'amber'],
    medium: ['Media', 'amber'],
    low: ['Baja', 'green'],
};

export const taskTone: Record<string, string> = {
    pending: 'amber',
    in_progress: 'blue',
    ready_for_review: 'violet',
    in_review: 'violet',
    changes_requested: 'red',
    blocked: 'red',
    approved: 'green',
    completed: 'green',
};

const stageStyle: Record<
    string,
    { icon: typeof CheckCircle2; className: string; label: string }
> = {
    COMPLETE: {
        icon: CheckCircle2,
        className: 'text-emerald-600',
        label: 'Completo',
    },
    IN_PROGRESS: {
        icon: Clock3,
        className: 'text-blue-600',
        label: 'En proceso',
    },
    PENDING: {
        icon: AlertTriangle,
        className: 'text-amber-500',
        label: 'Pendiente',
    },
    BLOCKED: { icon: XCircle, className: 'text-red-600', label: 'Bloqueado' },
    NOT_APPLICABLE: {
        icon: CircleSlash,
        className: 'text-slate-300',
        label: 'No aplica',
    },
    ERROR: {
        icon: CircleDashed,
        className: 'text-violet-600',
        label: 'No disponible',
    },
};

export function StageIcon({ stage }: { stage: Stage }) {
    const style = stageStyle[stage.status] ?? stageStyle.ERROR;
    const Icon = style.icon;

    return (
        <span
            title={`${stage.label}: ${style.label}. ${stage.detail}`}
            className="inline-flex"
        >
            <Icon
                className={`size-4 ${style.className}`}
                aria-label={`${stage.label}: ${style.label}`}
            />
        </span>
    );
}

export function StageLegend() {
    return (
        <div className="flex flex-wrap gap-3 text-[11px] text-[#526b92]">
            {Object.entries(stageStyle).map(([key, style]) => {
                const Icon = style.icon;

                return (
                    <span key={key} className="inline-flex items-center gap-1">
                        <Icon className={`size-3.5 ${style.className}`} />{' '}
                        {style.label}
                    </span>
                );
            })}
        </div>
    );
}

export function ProgressValue({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-xs text-violet-700">No disponible</span>;
    }

    return (
        <div className="flex items-center gap-1.5">
            <div className="h-2 w-16 overflow-hidden rounded bg-slate-100">
                <div
                    className={`h-full ${value >= 100 ? 'bg-emerald-500' : value >= 60 ? 'bg-emerald-400' : value >= 30 ? 'bg-amber-400' : 'bg-red-500'}`}
                    style={{ width: `${Math.min(100, value)}%` }}
                />
            </div>
            <span className="w-9 font-black">{value}%</span>
        </div>
    );
}

export function taskAction(
    task: AssistantTask,
    action: 'start' | 'submit',
): void {
    router.post(
        `/assistant/tasks/${task.id}/${action}`,
        {},
        { preserveScroll: true },
    );
}
