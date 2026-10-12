import { Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { input } from '@/components/office-admin/kit';
import { useSupervisorHref } from '@/components/supervisor/kit';

export { amount } from '@/components/receivables/kit';
export type {
    ReceivablesContext as PayrollContext,
    ReceivablesPermissions as PayrollPermissions,
} from '@/components/receivables/kit';

export type Issue = { level: string; code: string; message: string };

export type PayrollPeriod = {
    id: number;
    public_id: string;
    company_id: number;
    company: string;
    code: string;
    name: string;
    starts_on: string;
    ends_on: string;
    payment_date: string;
    status: string;
    status_label: string;
    needs_recalculation: boolean;
    recalculation_reason: string | null;
    employees_count: number;
    gross_cents: number | null;
    deductions_cents: number | null;
    net_cents: number | null;
    employer_cents: number | null;
    provisions_cents: number | null;
    calculated_at: string | null;
    approved_at: string | null;
    review_note: string | null;
    destination: string;
    can_review?: boolean;
    errors?: number;
    warnings?: number;
};

export type PayrollCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    employees: number;
    incidents: number;
    destination: string;
    period: {
        id: number;
        name: string;
        code: string;
        payment_date: string;
        status: string;
    } | null;
    novelties: number | null;
    pending_novelties: number | null;
    errors: number | null;
    net_cents: number | null;
    status: string;
    status_label: string;
    error: string | null;
};

export type Novelty = {
    id: number;
    period_id: number;
    period: string;
    company: string;
    employee_id: number;
    employee: string;
    concept_code: string;
    concept: string;
    measure: string;
    unit: string | null;
    quantity: number;
    amount_cents: number;
    description: string | null;
    status: string;
    status_label: string;
    review_note: string | null;
    created_at: string;
    editable?: boolean;
};

export type ResultLine = {
    concept_code: string;
    concept: string;
    type: string;
    quantity: number | null;
    rate: number | null;
    amount_cents: number;
    source: string;
};

export type EmployeeResult = {
    id: number;
    employee_id: number;
    employee: string;
    code: string;
    days_worked: number;
    status: string;
    issues: Issue[];
    gross_cents: number | null;
    deductions_cents: number | null;
    net_cents: number | null;
    employer_cents: number | null;
    provisions_cents: number | null;
    isr_detail: Record<string, number | string> | null;
    lines: ResultLine[];
};

export type PaymentOrder = {
    id: number;
    period_id: number;
    period: string;
    company: string;
    employee: string;
    employee_code: string;
    method: string;
    method_label: string;
    amount_cents: number;
    bank_name: string | null;
    bank_account_mask: string | null;
    status: string;
    status_label: string;
    paid_on: string | null;
    reference: string | null;
    failure_reason: string | null;
    payment_instruction_id: number | null;
};

/** Payroll screens keep the selected accounting period like the other panels. */
export const usePayrollHref = useSupervisorHref;

export const periodTone: Record<string, string> = {
    open: 'blue',
    calculated: 'violet',
    in_review: 'amber',
    changes_requested: 'red',
    approved: 'green',
    paid: 'green',
    closed: 'slate',
    cancelled: 'slate',
};

export const companyTone: Record<string, string> = {
    no_employees: 'slate',
    no_period: 'slate',
    open: 'blue',
    recalculate: 'amber',
    with_errors: 'red',
    calculated: 'violet',
    in_review: 'amber',
    changes_requested: 'red',
    approved: 'green',
    paid: 'green',
    closed: 'slate',
    error: 'violet',
};

export const noveltyTone: Record<string, string> = {
    pending: 'amber',
    approved: 'green',
    rejected: 'red',
};

export const orderTone: Record<string, string> = {
    pending: 'amber',
    sent: 'blue',
    paid: 'green',
    failed: 'red',
    cancelled: 'slate',
};

export const resultTone: Record<string, string> = {
    ok: 'green',
    warning: 'amber',
    error: 'red',
};

export const resultLabel: Record<string, string> = {
    ok: 'Correcto',
    warning: 'Con advertencias',
    error: 'Con errores',
};

export function Metric({
    label,
    value,
    icon: Icon,
    tone,
    href,
    hint,
}: {
    label: string;
    value: number | null;
    icon: LucideIcon;
    tone: string;
    href: string;
    hint?: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="flex items-center gap-3 rounded-xl border border-[#e7e1dd] bg-white p-3 shadow-sm hover:border-[#640000]/40"
        >
            <span
                className={`grid size-10 shrink-0 place-items-center rounded-full ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <span className="min-w-0">
                <span className="block text-[11px] leading-tight text-[#526b92]">
                    {label}
                </span>
                <span className="block text-2xl font-black text-[#061633]">
                    {value ?? '—'}
                </span>
                {hint && (
                    <span className="block text-[10px] text-[#526b92]">
                        {hint}
                    </span>
                )}
            </span>
        </Link>
    );
}

export function ViewAll({
    href,
    label = 'Ver todos',
}: {
    href: string;
    label?: string;
}) {
    return (
        <Link
            href={href}
            className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
        >
            {label}
        </Link>
    );
}

/** Selector of the payroll period a screen works on (?period_id=). */
export function PeriodPicker({
    periods,
    periodId,
    path,
    allowAll = false,
}: {
    periods: PayrollPeriod[];
    periodId: number | null;
    path: string;
    allowAll?: boolean;
}) {
    const href = usePayrollHref();

    return (
        <label className="grid min-w-64 gap-1 text-xs font-semibold">
            Nómina
            <select
                className={input}
                value={periodId ?? ''}
                onChange={(event) =>
                    router.get(
                        href(path),
                        event.target.value
                            ? { period_id: event.target.value }
                            : {},
                        { preserveScroll: true },
                    )
                }
            >
                {(allowAll || periods.length === 0) && (
                    <option value="">
                        {periods.length === 0
                            ? 'No hay nóminas disponibles'
                            : 'Todas las nóminas'}
                    </option>
                )}
                {periods.map((period) => (
                    <option key={period.id} value={period.id}>
                        {period.company} · {period.name} · {period.status_label}
                    </option>
                ))}
            </select>
        </label>
    );
}

/** Warnings and errors of a calculation, as the backend reports them. */
export function IssueList({ issues }: { issues: Issue[] }) {
    if (issues.length === 0) {
        return <span className="text-emerald-700">Sin observaciones</span>;
    }

    return (
        <ul className="grid gap-0.5">
            {issues.map((issue, index) => (
                <li
                    key={index}
                    className={
                        issue.level === 'error'
                            ? 'font-semibold text-red-700'
                            : 'text-amber-700'
                    }
                >
                    {issue.message}
                </li>
            ))}
        </ul>
    );
}

export function hidden(value: number | null, format: (v: number) => string) {
    return value === null ? 'Reservado' : format(value);
}
