import { Head, Link, router } from '@inertiajs/react';
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { DirectorOverview } from '@/components/director-overview';
import type { DirectorFirm } from '@/components/director-overview';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EXECUTIVE_HOME } from '@/lib/executive-workspace';

type Tone = 'green' | 'amber' | 'red' | 'blue' | 'slate';

export type ExecutiveDashboardData = {
    summary: {
        active_offices: number;
        active_companies: number;
        periods_in_progress: number;
        ready_for_review: number;
        completed_closings: number;
        overdue_tasks: number;
    };
    comparisons?: Record<
        string,
        {
            label: string;
            direction: 'up' | 'down' | 'flat';
            tone: 'positive' | 'negative' | 'neutral';
        }
    >;
    filters?: { office_id: number | null; period: string | null };
    selectorOptions?: {
        offices: { id: number; name: string }[];
        periods: { key: string; name: string }[];
    };
    attention?: { title: string; detail: string; tone: Tone; href: string }[];
    receivables?: {
        id: number;
        name: string;
        overdue_cents: number | null;
        total_cents: number | null;
        critical_documents: number;
        broken_promises: number;
        incidents: number;
        ledger_status: string;
        ledger_label: string;
        status: string;
        status_label: string;
    }[];
    payables?: {
        id: number;
        name: string;
        overdue_cents: number | null;
        total_cents: number | null;
        critical_documents: number;
        proposals_waiting_approval: number;
        incidents: number;
        ledger_status: string;
        ledger_label: string;
        status: string;
        status_label: string;
    }[];
    offices: {
        id: number;
        name: string;
        companies: number;
        progress: number;
        overdue: number;
        completed_companies?: number;
        responsible?: string;
        health_label?: string;
        health_tone?: Tone;
    }[];
    companies: {
        id: number;
        name: string;
        tax_id: string;
        office_name: string;
        period_name: string | null;
        progress: number;
        pending_tasks: number;
        overdue_tasks?: number;
        is_overdue?: boolean;
        status: string;
        accountant: string | null;
        assistant: string | null;
        href?: string;
    }[];
    tasks: {
        id: number;
        company_name: string | null;
        title: string;
        status: string;
        due_at: string | null;
        is_overdue: boolean;
        remaining_label?: string;
        href?: string;
    }[];
    workload: {
        user_id: number;
        name: string;
        organization_role: string;
        role_label?: string;
        assigned_companies: number;
        tasks: Record<string, number>;
        utilization_percentage?: number;
        status?: string;
    }[];
    productivity?: {
        user_id: number;
        name: string;
        completed: number;
        on_time: number;
        on_time_rate: number;
        observations: number;
        open: number;
        average_cycle_hours: number | null;
    }[];
    firm?: DirectorFirm;
    reports?: {
        key: string;
        title: string;
        description: string;
        href: string;
    }[];
};

const emptyFirm: DirectorFirm = {
    trend: [],
    growth: [],
    new_companies: 0,
    capacity: { limit: null, active: 0, available: null },
};

const panel =
    'rounded-xl border border-[#ebe5e1] bg-white shadow-[0_8px_22px_-18px_rgba(49,11,8,.5)]';
const chip =
    'rounded-full border border-red-100 bg-red-50 px-3 py-1.5 text-xs font-bold text-[#640000]';
const statusLabels: Record<string, string> = {
    not_started: 'Sin iniciar',
    in_progress: 'En proceso',
    ready_for_review: 'Lista para revisión',
    in_review: 'En revisión',
    changes_requested: 'Cambios solicitados',
    ready_to_close: 'Lista para cierre',
    approved: 'Aprobada',
    closed: 'Cerrada',
    blocked: 'Bloqueada',
};
const complianceFilters = [
    { key: 'all', label: 'Todas' },
    { key: 'overdue', label: 'Atrasadas' },
    { key: 'review', label: 'En revisión' },
    { key: 'closed', label: 'Cerradas' },
] as const;

function badge(tone: Tone) {
    const styles: Record<Tone, string> = {
        green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        amber: 'bg-amber-50 text-amber-700 ring-amber-200',
        red: 'bg-red-50 text-red-700 ring-red-200',
        blue: 'bg-blue-50 text-blue-700 ring-blue-200',
        slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    };

    return `inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${styles[tone]}`;
}

function statusTone(status: string): Tone {
    if (['approved', 'closed'].includes(status)) {
        return 'green';
    }

    if (['blocked', 'changes_requested'].includes(status)) {
        return 'red';
    }

    if (['ready_for_review', 'in_review', 'ready_to_close'].includes(status)) {
        return 'blue';
    }

    if (status === 'in_progress') {
        return 'amber';
    }

    return 'slate';
}

function number(value: number) {
    return new Intl.NumberFormat('es-GT').format(value);
}

function ProgressBar({
    value,
    tone = 'bg-blue-500',
}: {
    value: number;
    tone?: string;
}) {
    return (
        <div className="h-2.5 w-full overflow-hidden rounded-md bg-slate-100">
            <div
                className={`h-full rounded-md ${tone}`}
                style={{ width: `${Math.min(100, Math.max(0, value))}%` }}
            />
        </div>
    );
}

function SectionHeader({
    title,
    action,
}: {
    title: string;
    action?: ReactNode;
}) {
    return (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-lg font-black">{title}</h2>
            {action}
        </div>
    );
}

type RingSegment = { value: number; color: string };

function RingChart({
    segments,
    center,
    caption,
    size = 120,
    stroke = 14,
}: {
    segments: RingSegment[];
    center: ReactNode;
    caption?: string;
    size?: number;
    stroke?: number;
}) {
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = segments.reduce((sum, segment) => sum + segment.value, 0);
    let offset = 0;

    return (
        <div
            className="relative shrink-0"
            style={{ width: size, height: size }}
        >
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className="-rotate-90"
            >
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="#eef1f3"
                    strokeWidth={stroke}
                />
                {total > 0 &&
                    segments.map((segment, index) => {
                        const length = (segment.value / total) * circumference;
                        const circle = (
                            <circle
                                key={index}
                                cx={size / 2}
                                cy={size / 2}
                                r={radius}
                                fill="none"
                                stroke={segment.color}
                                strokeWidth={stroke}
                                strokeDasharray={`${length} ${circumference - length}`}
                                strokeDashoffset={-offset}
                            />
                        );
                        offset += length;

                        return circle;
                    })}
            </svg>
            <div className="absolute inset-0 grid place-items-center text-center">
                <div className="leading-tight">
                    <div className="text-xl font-black text-[#061633]">
                        {center}
                    </div>
                    {caption && (
                        <div className="text-[10px] text-slate-500">
                            {caption}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function TableDialog({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={chip}
            >
                Ver tabla
            </button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-5xl">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                    </DialogHeader>
                    {children}
                </DialogContent>
            </Dialog>
        </>
    );
}

function Legend({
    items,
}: {
    items: { label: string; value: number; color: string }[];
}) {
    return (
        <ul className="space-y-1.5 text-xs">
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-2">
                    <span
                        className="size-2.5 rounded-full"
                        style={{ background: item.color }}
                    />
                    <span className="text-[#395277]">{item.label}</span>
                    <span className="ml-auto pl-4 font-black">
                        {item.value}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function EmptyRow({ colSpan, text }: { colSpan: number; text: string }) {
    return (
        <tr>
            <td
                colSpan={colSpan}
                className="px-2 py-6 text-center text-sm text-slate-500"
            >
                {text}
            </td>
        </tr>
    );
}

export function ExecutiveDashboard({
    dashboard,
}: {
    dashboard: ExecutiveDashboardData;
    notice: string | null;
}) {
    const [complianceFilter, setComplianceFilter] =
        useState<(typeof complianceFilters)[number]['key']>('all');
    const sortedOffices = [...dashboard.offices].sort(
        (a, b) => b.companies - a.companies,
    );
    const productivity = dashboard.productivity ?? [];
    const reports = dashboard.reports ?? [];
    const selectedOffice = dashboard.filters?.office_id?.toString() ?? '';
    const selectedPeriod = dashboard.filters?.period ?? '';
    const periodName = dashboard.selectorOptions?.periods.find(
        (period) => period.key === selectedPeriod,
    )?.name;
    const scopeLabel =
        dashboard.selectorOptions?.offices.find(
            (office) => office.id.toString() === selectedOffice,
        )?.name ?? 'Toda la organización';
    const companies = useMemo(
        () =>
            dashboard.companies.filter((company) => {
                if (complianceFilter === 'overdue') {
                    return company.is_overdue;
                }

                if (complianceFilter === 'review') {
                    return [
                        'ready_for_review',
                        'in_review',
                        'changes_requested',
                    ].includes(company.status);
                }

                if (complianceFilter === 'closed') {
                    return ['approved', 'closed'].includes(company.status);
                }

                return true;
            }),
        [dashboard.companies, complianceFilter],
    );
    const complianceSegments = [
        {
            label: 'Cerradas',
            color: '#12a594',
            test: (status: string) => ['approved', 'closed'].includes(status),
        },
        {
            label: 'En revisión',
            color: '#3b82f6',
            test: (status: string) =>
                ['ready_for_review', 'in_review', 'ready_to_close'].includes(
                    status,
                ),
        },
        {
            label: 'En proceso',
            color: '#f59e0b',
            test: (status: string) => status === 'in_progress',
        },
        {
            label: 'Con cambios o bloqueadas',
            color: '#dc2626',
            test: (status: string) =>
                ['changes_requested', 'blocked'].includes(status),
        },
        {
            label: 'Sin iniciar',
            color: '#cbd5e1',
            test: (status: string) =>
                !statusLabels[status] || status === 'not_started',
        },
    ].map((segment) => ({
        label: segment.label,
        color: segment.color,
        value: dashboard.companies.filter((company) =>
            segment.test(company.status),
        ).length,
    }));
    const overdueCompanies = dashboard.companies.filter(
        (company) => company.is_overdue,
    ).length;
    const updateFilters = (next: { office_id?: string; period?: string }) => {
        const params: Record<string, string> = {};
        const officeId = next.office_id ?? selectedOffice;
        const period = next.period ?? selectedPeriod;

        if (officeId) {
            params.office_id = officeId;
        }

        if (period) {
            params.period = period;
        }

        router.get(EXECUTIVE_HOME, params, {
            preserveScroll: true,
            preserveState: false,
        });
    };

    const receivablesTable = (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs">
                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                    <tr>
                        {[
                            'Empresa',
                            'Cartera',
                            'Vencido',
                            '+90 días',
                            'Promesas incumplidas',
                            'Incidencias',
                            'CxC vs Mayor',
                            'Estado',
                        ].map((header) => (
                            <th
                                key={header}
                                className="px-2 py-2 font-semibold"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {(dashboard.receivables ?? []).map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2 font-bold">{row.name}</td>
                            <td className="px-2 py-2">
                                {row.total_cents === null
                                    ? 'No disponible'
                                    : `Q ${number(row.total_cents / 100)}`}
                            </td>
                            <td className="px-2 py-2 text-red-700">
                                {row.overdue_cents === null
                                    ? 'No disponible'
                                    : `Q ${number(row.overdue_cents / 100)}`}
                            </td>
                            <td className="px-2 py-2">
                                {row.critical_documents}
                            </td>
                            <td className="px-2 py-2">{row.broken_promises}</td>
                            <td className="px-2 py-2">{row.incidents}</td>
                            <td className="px-2 py-2">
                                <span
                                    className={badge(
                                        row.ledger_status === 'matched'
                                            ? 'green'
                                            : row.ledger_status === 'difference'
                                              ? 'red'
                                              : 'slate',
                                    )}
                                >
                                    {row.ledger_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={badge(
                                        [
                                            'reconciliation_difference',
                                            'attention_required',
                                        ].includes(row.status)
                                            ? 'red'
                                            : 'amber',
                                    )}
                                >
                                    {row.status_label}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
    const payablesTable = (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs">
                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                    <tr>
                        {[
                            'Empresa',
                            'Por pagar',
                            'Vencido',
                            '+30 días',
                            'Propuestas por aprobar',
                            'Incidencias',
                            'CxP vs Mayor',
                            'Estado',
                        ].map((header) => (
                            <th
                                key={header}
                                className="px-2 py-2 font-semibold"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {(dashboard.payables ?? []).map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2 font-bold">{row.name}</td>
                            <td className="px-2 py-2">
                                {row.total_cents === null
                                    ? 'No disponible'
                                    : `Q ${number(row.total_cents / 100)}`}
                            </td>
                            <td className="px-2 py-2 text-red-700">
                                {row.overdue_cents === null
                                    ? 'No disponible'
                                    : `Q ${number(row.overdue_cents / 100)}`}
                            </td>
                            <td className="px-2 py-2">
                                {row.critical_documents}
                            </td>
                            <td className="px-2 py-2">
                                {row.proposals_waiting_approval}
                            </td>
                            <td className="px-2 py-2">{row.incidents}</td>
                            <td className="px-2 py-2">
                                <span
                                    className={badge(
                                        row.ledger_status === 'matched'
                                            ? 'green'
                                            : row.ledger_status === 'difference'
                                              ? 'red'
                                              : 'slate',
                                    )}
                                >
                                    {row.ledger_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={badge(
                                        [
                                            'reconciliation_difference',
                                            'attention_required',
                                        ].includes(row.status)
                                            ? 'red'
                                            : 'amber',
                                    )}
                                >
                                    {row.status_label}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );

    return (
        <>
            <Head title="Panel Ejecutivo" />
            <main className="min-h-full flex-1 overflow-x-hidden bg-[#f4f8fb] p-2 text-[#0b1733] sm:p-3">
                <div className="w-full max-w-none space-y-4">
                    <DirectorOverview
                        dashboard={dashboard}
                        firm={dashboard.firm ?? emptyFirm}
                        scopeLabel={scopeLabel}
                        periodName={periodName}
                        receivablesTable={
                            (dashboard.receivables ?? []).length > 0
                                ? receivablesTable
                                : null
                        }
                        payablesTable={
                            (dashboard.payables ?? []).length > 0
                                ? payablesTable
                                : null
                        }
                        filters={
                            <div className="flex flex-wrap items-center gap-2 xl:hidden">
                                <label
                                    className="sr-only"
                                    htmlFor="executive-office"
                                >
                                    Oficina
                                </label>
                                <select
                                    id="executive-office"
                                    className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-[#395277] shadow-sm"
                                    value={selectedOffice}
                                    onChange={(event) =>
                                        updateFilters({
                                            office_id: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">
                                        Toda la organización
                                    </option>
                                    {dashboard.selectorOptions?.offices.map(
                                        (office) => (
                                            <option
                                                key={office.id}
                                                value={office.id}
                                            >
                                                {office.name}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <label
                                    className="sr-only"
                                    htmlFor="executive-period"
                                >
                                    Período
                                </label>
                                <select
                                    id="executive-period"
                                    className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-[#395277] shadow-sm"
                                    value={selectedPeriod}
                                    onChange={(event) =>
                                        updateFilters({
                                            period: event.target.value,
                                        })
                                    }
                                >
                                    {dashboard.selectorOptions?.periods.map(
                                        (period) => (
                                            <option
                                                key={period.key}
                                                value={period.key}
                                            >
                                                {period.name}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                        }
                    />

                    <div className="grid items-stretch gap-3 xl:grid-cols-3">
                        <section
                            id="cumplimiento"
                            className={`${panel} min-w-0 scroll-mt-24 p-3`}
                        >
                            <SectionHeader
                                title="Cumplimiento por empresa"
                                action={
                                    <TableDialog title="Cumplimiento por empresa">
                                        <div className="flex flex-wrap gap-1.5">
                                            {complianceFilters.map((filter) => (
                                                <button
                                                    key={filter.key}
                                                    type="button"
                                                    onClick={() =>
                                                        setComplianceFilter(
                                                            filter.key,
                                                        )
                                                    }
                                                    className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${complianceFilter === filter.key ? 'bg-[#640000] text-white ring-[#640000]' : 'bg-white text-[#395277] ring-slate-200'}`}
                                                >
                                                    {filter.label}
                                                </button>
                                            ))}
                                        </div>
                                        <div className="overflow-x-auto">
                                            <table className="min-w-full text-xs">
                                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                                    <tr>
                                                        <th className="px-2 py-2">
                                                            Empresa
                                                        </th>
                                                        <th className="px-2 py-2">
                                                            Oficina
                                                        </th>
                                                        <th className="px-2 py-2">
                                                            Contador encargado
                                                        </th>
                                                        <th className="px-2 py-2">
                                                            Estado
                                                        </th>
                                                        <th className="w-40 px-2 py-2">
                                                            Avance
                                                        </th>
                                                        <th className="px-2 py-2 text-right">
                                                            Pendientes
                                                        </th>
                                                        <th className="px-2 py-2 text-right">
                                                            Vencidas
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {companies.map(
                                                        (company) => (
                                                            <tr
                                                                key={company.id}
                                                                className={
                                                                    company.is_overdue
                                                                        ? 'bg-red-50/40'
                                                                        : ''
                                                                }
                                                            >
                                                                <td className="px-2 py-2.5">
                                                                    <Link
                                                                        href={
                                                                            company.href ??
                                                                            '/accounting'
                                                                        }
                                                                        className="font-bold text-[#061633] hover:text-[#640000]"
                                                                    >
                                                                        {
                                                                            company.name
                                                                        }
                                                                    </Link>
                                                                    <p className="text-[11px] text-[#526b92]">
                                                                        NIT{' '}
                                                                        {
                                                                            company.tax_id
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {company.period_name ??
                                                                            'Sin período'}
                                                                    </p>
                                                                </td>
                                                                <td className="px-2 py-2.5 text-[#395277]">
                                                                    {
                                                                        company.office_name
                                                                    }
                                                                </td>
                                                                <td className="px-2 py-2.5 text-[#395277]">
                                                                    {company.accountant ?? (
                                                                        <span className="font-bold text-amber-700">
                                                                            Sin
                                                                            asignar
                                                                        </span>
                                                                    )}
                                                                </td>
                                                                <td className="px-2 py-2.5">
                                                                    <span
                                                                        className={badge(
                                                                            statusTone(
                                                                                company.status,
                                                                            ),
                                                                        )}
                                                                    >
                                                                        {statusLabels[
                                                                            company
                                                                                .status
                                                                        ] ??
                                                                            company.status}
                                                                    </span>
                                                                    {company.is_overdue && (
                                                                        <span
                                                                            className={`ml-1 ${badge('red')}`}
                                                                        >
                                                                            Atrasada
                                                                        </span>
                                                                    )}
                                                                </td>
                                                                <td className="px-2 py-2.5">
                                                                    <div className="flex items-center gap-2">
                                                                        <ProgressBar
                                                                            value={
                                                                                company.progress
                                                                            }
                                                                            tone={
                                                                                company.progress >=
                                                                                100
                                                                                    ? 'bg-emerald-500'
                                                                                    : 'bg-blue-500'
                                                                            }
                                                                        />
                                                                        <span className="w-9 text-right font-black">
                                                                            {
                                                                                company.progress
                                                                            }
                                                                            %
                                                                        </span>
                                                                    </div>
                                                                </td>
                                                                <td className="px-2 py-2.5 text-right font-black">
                                                                    {
                                                                        company.pending_tasks
                                                                    }
                                                                </td>
                                                                <td
                                                                    className={`px-2 py-2.5 text-right font-black ${(company.overdue_tasks ?? 0) > 0 ? 'text-red-700' : ''}`}
                                                                >
                                                                    {company.overdue_tasks ??
                                                                        0}
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                    {companies.length === 0 && (
                                                        <EmptyRow
                                                            colSpan={7}
                                                            text="No hay empresas en este filtro."
                                                        />
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </TableDialog>
                                }
                            />
                            <div className="flex flex-wrap items-center justify-center gap-6 py-2">
                                <RingChart
                                    size={130}
                                    stroke={16}
                                    segments={complianceSegments}
                                    center={dashboard.companies.length}
                                    caption="empresas"
                                />
                                <div className="min-w-[220px]">
                                    <Legend items={complianceSegments} />
                                    <p
                                        className={`mt-3 text-xs font-bold ${overdueCompanies > 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                    >
                                        {overdueCompanies > 0
                                            ? `${overdueCompanies} con tareas atrasadas`
                                            : 'Ninguna empresa atrasada'}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section
                            id="equipo"
                            className="grid min-w-0 scroll-mt-24"
                        >
                            <div className={`${panel} min-w-0 p-3`}>
                                <SectionHeader
                                    title="Carga del equipo"
                                    action={
                                        <div className="flex gap-1.5">
                                            <TableDialog title="Carga del equipo">
                                                <div className="overflow-x-auto">
                                                    <table className="min-w-full text-xs">
                                                        <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                                            <tr>
                                                                <th className="px-2 py-2">
                                                                    Colaborador
                                                                </th>
                                                                <th className="px-2 py-2">
                                                                    Rol
                                                                </th>
                                                                <th className="px-2 py-2 text-right">
                                                                    Empresas
                                                                </th>
                                                                <th className="px-2 py-2 text-right">
                                                                    Abiertas
                                                                </th>
                                                                <th className="px-2 py-2 text-right">
                                                                    Vencidas
                                                                </th>
                                                                <th className="px-2 py-2">
                                                                    Utilización
                                                                </th>
                                                                <th className="px-2 py-2">
                                                                    Estado
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-slate-100">
                                                            {dashboard.workload.map(
                                                                (
                                                                    row,
                                                                    index,
                                                                ) => {
                                                                    const utilization =
                                                                        row.utilization_percentage ??
                                                                        0;
                                                                    const open =
                                                                        (row
                                                                            .tasks
                                                                            .pending ??
                                                                            0) +
                                                                        (row
                                                                            .tasks
                                                                            .in_progress ??
                                                                            0) +
                                                                        (row
                                                                            .tasks
                                                                            .ready_for_review ??
                                                                            0) +
                                                                        (row
                                                                            .tasks
                                                                            .in_review ??
                                                                            0) +
                                                                        (row
                                                                            .tasks
                                                                            .changes_requested ??
                                                                            0) +
                                                                        (row
                                                                            .tasks
                                                                            .blocked ??
                                                                            0);
                                                                    const initials =
                                                                        row.name
                                                                            .split(
                                                                                ' ',
                                                                            )
                                                                            .map(
                                                                                (
                                                                                    part,
                                                                                ) =>
                                                                                    part[0],
                                                                            )
                                                                            .slice(
                                                                                0,
                                                                                2,
                                                                            )
                                                                            .join(
                                                                                '',
                                                                            )
                                                                            .toUpperCase();
                                                                    const status =
                                                                        row.status ??
                                                                        'Normal';

                                                                    return (
                                                                        <tr
                                                                            key={
                                                                                row.user_id
                                                                            }
                                                                        >
                                                                            <td className="px-2 py-2">
                                                                                <div className="flex items-center gap-2">
                                                                                    <span
                                                                                        className={`grid size-7 shrink-0 place-items-center rounded-full text-[11px] font-black ${['bg-blue-100 text-blue-700', 'bg-emerald-100 text-emerald-700', 'bg-red-100 text-red-700', 'bg-amber-100 text-amber-700', 'bg-sky-100 text-sky-700'][index % 5]}`}
                                                                                    >
                                                                                        {
                                                                                            initials
                                                                                        }
                                                                                    </span>
                                                                                    <span className="leading-tight font-bold">
                                                                                        {
                                                                                            row.name
                                                                                        }
                                                                                    </span>
                                                                                </div>
                                                                            </td>
                                                                            <td className="px-2 py-2 text-[#395277]">
                                                                                {row.role_label ??
                                                                                    row.organization_role}
                                                                            </td>
                                                                            <td className="px-2 py-2 text-right font-black">
                                                                                {
                                                                                    row.assigned_companies
                                                                                }
                                                                            </td>
                                                                            <td className="px-2 py-2 text-right font-black">
                                                                                {
                                                                                    open
                                                                                }
                                                                            </td>
                                                                            <td
                                                                                className={`px-2 py-2 text-right font-black ${(row.tasks.overdue ?? 0) > 0 ? 'text-red-700' : ''}`}
                                                                            >
                                                                                {row
                                                                                    .tasks
                                                                                    .overdue ??
                                                                                    0}
                                                                            </td>
                                                                            <td className="px-2 py-2">
                                                                                <div className="flex items-center gap-2">
                                                                                    <span className="w-9 font-black">
                                                                                        {
                                                                                            utilization
                                                                                        }

                                                                                        %
                                                                                    </span>
                                                                                    <div className="w-20">
                                                                                        <ProgressBar
                                                                                            value={
                                                                                                utilization
                                                                                            }
                                                                                            tone={
                                                                                                utilization >=
                                                                                                85
                                                                                                    ? 'bg-red-500'
                                                                                                    : utilization >=
                                                                                                        65
                                                                                                      ? 'bg-blue-500'
                                                                                                      : 'bg-emerald-500'
                                                                                            }
                                                                                        />
                                                                                    </div>
                                                                                </div>
                                                                            </td>
                                                                            <td className="px-2 py-2">
                                                                                <span
                                                                                    className={badge(
                                                                                        status ===
                                                                                            'Alta'
                                                                                            ? 'red'
                                                                                            : status ===
                                                                                                'Media'
                                                                                              ? 'amber'
                                                                                              : 'green',
                                                                                    )}
                                                                                >
                                                                                    {
                                                                                        status
                                                                                    }
                                                                                </span>
                                                                            </td>
                                                                        </tr>
                                                                    );
                                                                },
                                                            )}
                                                            {dashboard.workload
                                                                .length ===
                                                                0 && (
                                                                <EmptyRow
                                                                    colSpan={7}
                                                                    text="Sin colaboradores activos en este alcance."
                                                                />
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </TableDialog>
                                            <Link
                                                href="/office/users-roles"
                                                className={chip}
                                            >
                                                Ver equipo
                                            </Link>
                                        </div>
                                    }
                                />
                                {dashboard.workload.length === 0 ? (
                                    <p className="py-6 text-center text-sm text-slate-500">
                                        Sin colaboradores activos en este
                                        alcance.
                                    </p>
                                ) : (
                                    <div className="grid max-h-80 gap-2 overflow-y-auto">
                                        {dashboard.workload.map((row) => {
                                            const utilization =
                                                row.utilization_percentage ?? 0;
                                            const color =
                                                utilization >= 85
                                                    ? '#dc2626'
                                                    : utilization >= 65
                                                      ? '#3b82f6'
                                                      : '#12a594';

                                            return (
                                                <div
                                                    key={row.user_id}
                                                    className="flex items-center gap-3 rounded-xl border border-[#ebe5e1] p-3"
                                                >
                                                    <RingChart
                                                        size={84}
                                                        stroke={10}
                                                        segments={[
                                                            {
                                                                value: Math.min(
                                                                    utilization,
                                                                    100,
                                                                ),
                                                                color,
                                                            },
                                                            {
                                                                value:
                                                                    100 -
                                                                    Math.min(
                                                                        utilization,
                                                                        100,
                                                                    ),
                                                                color: '#eef1f3',
                                                            },
                                                        ]}
                                                        center={`${utilization}%`}
                                                    />
                                                    <div className="min-w-0 text-xs">
                                                        <p className="truncate text-sm font-black">
                                                            {row.name}
                                                        </p>
                                                        <p className="truncate text-[#526b92]">
                                                            {row.role_label ??
                                                                row.organization_role}
                                                        </p>
                                                        <p className="mt-1 text-[#395277]">
                                                            {
                                                                row.assigned_companies
                                                            }{' '}
                                                            empresas ·{' '}
                                                            <span
                                                                className={
                                                                    (row.tasks
                                                                        .overdue ??
                                                                        0) > 0
                                                                        ? 'font-bold text-red-700'
                                                                        : ''
                                                                }
                                                            >
                                                                {row.tasks
                                                                    .overdue ??
                                                                    0}{' '}
                                                                vencidas
                                                            </span>
                                                        </p>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </section>

                        <section
                            id="productividad"
                            className={`${panel} min-w-0 scroll-mt-24 p-3`}
                        >
                            <SectionHeader
                                title="Productividad del período"
                                action={
                                    <div className="flex gap-1.5">
                                        <span className={chip}>
                                            {periodName ?? 'Mes en curso'}
                                        </span>
                                        <TableDialog title="Productividad del período">
                                            <div className="overflow-x-auto">
                                                <table className="min-w-full text-xs">
                                                    <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                                        <tr>
                                                            <th className="px-2 py-2">
                                                                Colaborador
                                                            </th>
                                                            <th className="px-2 py-2 text-right">
                                                                Terminadas
                                                            </th>
                                                            <th className="px-2 py-2">
                                                                Puntualidad
                                                            </th>
                                                            <th className="px-2 py-2 text-right">
                                                                Observaciones
                                                            </th>
                                                            <th className="px-2 py-2 text-right">
                                                                Abiertas
                                                            </th>
                                                            <th className="px-2 py-2 text-right">
                                                                Ciclo promedio
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100">
                                                        {productivity.map(
                                                            (row) => (
                                                                <tr
                                                                    key={
                                                                        row.user_id
                                                                    }
                                                                >
                                                                    <td className="px-2 py-2 font-bold">
                                                                        {
                                                                            row.name
                                                                        }
                                                                    </td>
                                                                    <td className="px-2 py-2 text-right font-black">
                                                                        {
                                                                            row.completed
                                                                        }
                                                                    </td>
                                                                    <td className="px-2 py-2">
                                                                        {row.completed ===
                                                                        0 ? (
                                                                            <span className="text-[#526b92]">
                                                                                Sin
                                                                                tareas
                                                                                terminadas
                                                                            </span>
                                                                        ) : (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="w-9 font-black">
                                                                                    {
                                                                                        row.on_time_rate
                                                                                    }

                                                                                    %
                                                                                </span>
                                                                                <div className="w-20">
                                                                                    <ProgressBar
                                                                                        value={
                                                                                            row.on_time_rate
                                                                                        }
                                                                                        tone={
                                                                                            row.on_time_rate >=
                                                                                            85
                                                                                                ? 'bg-emerald-500'
                                                                                                : row.on_time_rate >=
                                                                                                    60
                                                                                                  ? 'bg-amber-500'
                                                                                                  : 'bg-red-500'
                                                                                        }
                                                                                    />
                                                                                </div>
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className={`px-2 py-2 text-right font-black ${row.observations > 0 ? 'text-amber-700' : ''}`}
                                                                    >
                                                                        {
                                                                            row.observations
                                                                        }
                                                                    </td>
                                                                    <td className="px-2 py-2 text-right font-black">
                                                                        {
                                                                            row.open
                                                                        }
                                                                    </td>
                                                                    <td className="px-2 py-2 text-right text-[#395277]">
                                                                        {row.average_cycle_hours ===
                                                                        null
                                                                            ? 'N/D'
                                                                            : `${row.average_cycle_hours} h`}
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                        {productivity.length ===
                                                            0 && (
                                                            <EmptyRow
                                                                colSpan={6}
                                                                text="Aún no hay tareas asignadas en este alcance."
                                                            />
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </TableDialog>
                                    </div>
                                }
                            />
                            {productivity.length === 0 ? (
                                <p className="py-6 text-center text-sm text-slate-500">
                                    Aún no hay tareas asignadas en este alcance.
                                </p>
                            ) : (
                                <div className="grid max-h-80 gap-2 overflow-y-auto">
                                    {productivity.map((row) => {
                                        const rate =
                                            row.completed === 0
                                                ? 0
                                                : row.on_time_rate;
                                        const color =
                                            rate >= 85
                                                ? '#12a594'
                                                : rate >= 60
                                                  ? '#f59e0b'
                                                  : '#dc2626';

                                        return (
                                            <div
                                                key={row.user_id}
                                                className="flex items-center gap-3 rounded-xl border border-[#ebe5e1] p-3"
                                            >
                                                <RingChart
                                                    size={96}
                                                    stroke={11}
                                                    segments={[
                                                        { value: rate, color },
                                                        {
                                                            value: 100 - rate,
                                                            color: '#eef1f3',
                                                        },
                                                    ]}
                                                    center={
                                                        row.completed === 0
                                                            ? 'N/D'
                                                            : `${rate}%`
                                                    }
                                                    caption="puntualidad"
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-black">
                                                        {row.name}
                                                    </p>
                                                    <p className="text-xs text-[#395277]">
                                                        {row.completed}{' '}
                                                        terminadas · {row.open}{' '}
                                                        abiertas
                                                    </p>
                                                    <p
                                                        className={`text-xs ${row.observations > 0 ? 'font-bold text-amber-700' : 'text-[#526b92]'}`}
                                                    >
                                                        {row.observations}{' '}
                                                        observaciones ·{' '}
                                                        {row.average_cycle_hours ===
                                                        null
                                                            ? 'ciclo N/D'
                                                            : `ciclo ${row.average_cycle_hours} h`}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </section>
                    </div>

                    <section
                        id="reportes"
                        className="grid scroll-mt-24 gap-3 xl:grid-cols-[minmax(0,.9fr)_minmax(0,1.1fr)]"
                    >
                        <div className={`${panel} min-w-0 p-3`}>
                            <SectionHeader
                                title="Reportes ejecutivos"
                                action={
                                    <span className={chip}>{scopeLabel}</span>
                                }
                            />
                            <div className="grid gap-2.5 sm:grid-cols-2">
                                {reports.map((report) => (
                                    <div
                                        key={report.key}
                                        className="flex flex-col justify-between rounded-xl border border-slate-100 p-3 shadow-sm"
                                    >
                                        <div>
                                            <p className="flex items-center gap-2 text-sm font-black text-[#061633]">
                                                <Download className="size-4 text-[#9b5b20]" />
                                                {report.title}
                                            </p>
                                            <p className="mt-1 text-xs text-[#526b92]">
                                                {report.description}
                                            </p>
                                        </div>
                                        <div className="mt-3 flex gap-2">
                                            <a
                                                href={`${report.href}${report.href.includes('?') ? '&' : '?'}format=xlsx`}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-800"
                                            >
                                                <FileSpreadsheet className="size-3.5" />{' '}
                                                Excel
                                            </a>
                                            <a
                                                href={`${report.href}${report.href.includes('?') ? '&' : '?'}format=pdf`}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-bold text-red-800"
                                            >
                                                <FileText className="size-3.5" />{' '}
                                                PDF
                                            </a>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className={`${panel} min-w-0 p-3`}>
                            <SectionHeader
                                title="Resumen de oficinas"
                                action={
                                    <Link
                                        href="/office/offices"
                                        className={chip}
                                    >
                                        Ver todas
                                    </Link>
                                }
                            />
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-xs">
                                    <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                        <tr>
                                            <th className="px-2 py-2">
                                                Oficina
                                            </th>
                                            <th className="px-2 py-2">
                                                Responsable
                                            </th>
                                            <th className="px-2 py-2 text-right">
                                                Empresas
                                            </th>
                                            <th className="px-2 py-2 text-right">
                                                Avance
                                            </th>
                                            <th className="px-2 py-2 text-right">
                                                Atrasadas
                                            </th>
                                            <th className="px-2 py-2">
                                                Estado
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {sortedOffices.map((office) => (
                                            <tr key={office.id}>
                                                <td className="px-2 py-3 leading-tight font-bold">
                                                    <Link
                                                        href={`/office/dashboard?office_id=${office.id}`}
                                                        className="hover:text-[#640000]"
                                                    >
                                                        {office.name}
                                                    </Link>
                                                </td>
                                                <td className="px-2 py-3 text-[#395277]">
                                                    {office.responsible ??
                                                        'Sin asignar'}
                                                </td>
                                                <td className="px-2 py-3 text-right font-black">
                                                    {office.companies}
                                                </td>
                                                <td className="px-2 py-3 text-right font-black">
                                                    {office.progress}%
                                                </td>
                                                <td className="px-2 py-3 text-right font-black text-red-700">
                                                    {office.overdue}
                                                </td>
                                                <td className="px-2 py-3">
                                                    <span
                                                        className={badge(
                                                            office.health_tone ??
                                                                'green',
                                                        )}
                                                    >
                                                        {office.health_label ??
                                                            'Normal'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                        {sortedOffices.length === 0 && (
                                            <EmptyRow
                                                colSpan={6}
                                                text="No hay oficinas activas."
                                            />
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
