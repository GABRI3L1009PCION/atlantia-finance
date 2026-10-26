import { Link, router, usePage } from '@inertiajs/react';
import {
    ProgressBar,
    Table,
    statusTone,
    toneBadge,
    useSupervisorHref,
} from '@/components/supervisor/kit';
import type { SupervisedCompany } from '@/components/supervisor/kit';

export type AccountantContext = {
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
    assistants: { id: number; name: string }[];
    badges: Record<string, number>;
};

export type AccountantPermissions = Record<string, boolean>;

export type AccountantCompany = SupervisedCompany & {
    observations: number;
    review_id: number | null;
    review_status: string | null;
    review_href: string;
};

export type Observation = {
    id: number;
    company_id: number;
    company_name: string;
    period_name: string | null;
    review_id: number | null;
    related_type_label: string;
    related_label: string | null;
    related_href: string | null;
    severity: string;
    severity_label: string;
    status: string;
    status_label: string;
    message: string;
    resolution: string | null;
    author_name: string | null;
    created_by: number | null;
    assigned_to: number | null;
    assignee_name: string | null;
    due_at: string | null;
    overdue: boolean;
    created_at: string;
};

export type Entry = {
    id: number;
    public_id: string;
    company_id: number;
    company_name: string;
    date: string;
    description: string;
    reference: string | null;
    kind: string;
    kind_label: string;
    status: string;
    status_label: string;
    created_by: number;
    creator_name: string | null;
    approver_name: string | null;
    amount_cents: number;
    balanced: boolean;
    documents: number;
    destination: string;
};

/** Accountant screens keep the selected period like the supervisor ones. */
export const useAccountantHref = useSupervisorHref;

export const severityTone: Record<string, string> = {
    low: 'slate',
    medium: 'blue',
    high: 'amber',
    critical: 'red',
};
export const observationTone: Record<string, string> = {
    open: 'red',
    in_progress: 'blue',
    ready_for_review: 'amber',
    resolved: 'green',
    reopened: 'violet',
};
export const checkTone: Record<string, string> = {
    PASSED: 'green',
    WARNING: 'amber',
    BLOCKED: 'red',
    NOT_APPLICABLE: 'slate',
    ERROR: 'violet',
};
export const checkLabel: Record<string, string> = {
    PASSED: 'Correcto',
    WARNING: 'Advertencia',
    BLOCKED: 'Bloqueante',
    NOT_APPLICABLE: 'No aplica',
    ERROR: 'Error',
};

export const statusOptions: [string, string][] = [
    ['not_started', 'Sin iniciar'],
    ['in_progress', 'En proceso'],
    ['ready_for_review', 'Para revisión'],
    ['in_review', 'En revisión'],
    ['changes_requested', 'Con observaciones'],
    ['first_level_approved', 'Revisión superior'],
    ['fiscal_pending', 'Fiscal pendiente'],
    ['ready_to_close', 'Lista para cierre'],
    ['closed', 'Cerrada'],
];

export function useFilterNavigation(
    path: string,
): (key: string, value: string) => void {
    const page = usePage();

    return (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            path,
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, current]) => current !== '',
                ),
            ),
            { preserveScroll: true, preserveState: true },
        );
    };
}

export function CompaniesTable({
    companies,
    empty,
}: {
    companies: AccountantCompany[];
    empty: string;
}) {
    const href = useAccountantHref();

    return (
        <Table
            headers={[
                'Empresa',
                'Auxiliar',
                'Período',
                'Avance',
                'Fiscal',
                'Observaciones',
                'Límite',
                'Estado',
                '',
            ]}
            empty={companies.length === 0 && empty}
        >
            {companies.map((company) => (
                <tr key={company.id}>
                    <td className="px-2 py-2">
                        <Link
                            href={href(company.review_href)}
                            className="font-bold hover:text-[#640000]"
                        >
                            {company.name}
                        </Link>
                        <p className="text-[11px] text-[#526b92]">
                            NIT {company.tax_id}
                        </p>
                    </td>
                    <td className="px-2 py-2">
                        {company.assistant?.name ?? (
                            <span className="text-amber-700">Sin auxiliar</span>
                        )}
                    </td>
                    <td className="px-2 py-2">{company.period_name ?? '—'}</td>
                    <td className="px-2 py-2">
                        <ProgressBar value={company.progress} />
                    </td>
                    <td className="px-2 py-2">
                        <span className={toneBadge(company.fiscal_tone)}>
                            {company.fiscal_label}
                        </span>
                    </td>
                    <td
                        className={`px-2 py-2 ${company.observations > 0 ? 'font-bold text-violet-700' : ''}`}
                    >
                        {company.observations}
                    </td>
                    <td
                        className={`px-2 py-2 ${company.overdue ? 'font-bold text-red-700' : ''}`}
                    >
                        {company.due_at
                            ? new Date(company.due_at).toLocaleDateString(
                                  'es-GT',
                              )
                            : '—'}
                    </td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(
                                company.blocked
                                    ? 'red'
                                    : (statusTone[company.status] ?? 'slate'),
                            )}
                        >
                            {company.blocked
                                ? 'Bloqueada'
                                : company.status_label}
                        </span>
                    </td>
                    <td className="px-2 py-2">
                        <div className="flex flex-col items-start gap-1 whitespace-nowrap">
                            <Link
                                href={href(company.review_href)}
                                className="text-xs font-bold text-[#640000] hover:underline"
                            >
                                Revisar
                            </Link>
                            <Link
                                href={`/accounting/companies/${company.public_id}/dashboard`}
                                className="text-xs font-bold text-[#640000] hover:underline"
                            >
                                Abrir contabilidad
                            </Link>
                        </div>
                    </td>
                </tr>
            ))}
        </Table>
    );
}
