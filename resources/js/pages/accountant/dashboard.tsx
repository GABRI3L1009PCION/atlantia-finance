import { Head, Link } from '@inertiajs/react';
import {
    BadgeCheck,
    Building2,
    ClipboardCheck,
    Hourglass,
    LockKeyhole,
    MessageSquareWarning,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    CompaniesTable,
    observationTone,
    severityTone,
    statusOptions,
    useAccountantHref,
    useFilterNavigation,
} from '@/components/accountant/kit';
import type {
    AccountantCompany,
    AccountantContext,
    Observation,
} from '@/components/accountant/kit';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
} from '@/components/office-admin/kit';
import {
    Card,
    Table,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';

type Review = {
    id: number;
    company_id: number;
    company_name: string;
    period_name: string;
    submitted_by: string | null;
    status: string;
    status_label: string;
    submitted_at: string | null;
    waiting_hours: number | null;
    review_href: string;
};
type Obligation = {
    key: string;
    company_name: string;
    name: string;
    period: string;
    due_on: string;
    days_left: number;
    status_label: string;
    tone: string;
};
type Panel = {
    metrics: Record<string, number>;
    companies: AccountantCompany[];
    review_queue: Review[];
    observations: Observation[];
    pending_entries: number | null;
    obligations: Obligation[];
};

export default function AccountantDashboard({
    context,
    panel,
    filters,
    notice,
}: {
    context: AccountantContext;
    panel: Panel;
    filters: Record<string, string>;
    notice: string | null;
}) {
    const href = useAccountantHref();
    const apply = useFilterNavigation('/accountant/dashboard');
    const [search, setSearch] = useState(filters.search ?? '');
    const kpis: [string, number, LucideIcon, string, string][] = [
        [
            'Empresas asignadas',
            panel.metrics.assigned,
            Building2,
            'bg-blue-50 text-blue-700',
            '/accountant/companies',
        ],
        [
            'En proceso',
            panel.metrics.in_progress,
            Hourglass,
            'bg-slate-100 text-slate-700',
            '/accountant/companies?status=in_progress',
        ],
        [
            'Listas para mi revisión',
            panel.metrics.ready_for_my_review,
            ClipboardCheck,
            'bg-amber-50 text-amber-700',
            '/accountant/reviews',
        ],
        [
            'Con observaciones',
            panel.metrics.with_observations,
            MessageSquareWarning,
            'bg-violet-50 text-violet-700',
            '/accountant/observations',
        ],
        [
            'Listas para revisión superior',
            panel.metrics.ready_for_upper_review,
            BadgeCheck,
            'bg-emerald-50 text-emerald-700',
            '/accountant/companies?status=first_level_approved',
        ],
        [
            'Cierres pendientes',
            panel.metrics.pending_closings,
            LockKeyhole,
            'bg-red-50 text-[#640000]',
            '/accountant/closings',
        ],
    ];

    return (
        <>
            <Head title="Dashboard Contador Encargado" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Dashboard Contador Encargado"
                    subtitle={`Tus empresas a cargo, su revisión y cierre · ${context.period?.name ?? 'Sin período'}`}
                />
                <LuzmaInsightsCard />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {kpis.map(([label, value, Icon, tone, target]) => (
                        <Link
                            key={label}
                            href={href(target)}
                            className="flex items-center gap-3 rounded-xl border border-[#e7e1dd] bg-white p-3 shadow-sm hover:border-[#640000]/40"
                        >
                            <span
                                className={`grid size-10 shrink-0 place-items-center rounded-lg ${tone}`}
                            >
                                <Icon className="size-5" />
                            </span>
                            <span>
                                <span className="block text-2xl font-black text-[#061633]">
                                    {value}
                                </span>
                                <span className="block text-xs text-[#526b92]">
                                    {label}
                                </span>
                            </span>
                        </Link>
                    ))}
                </section>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Cola de revisión"
                        action={
                            <Link
                                href={href('/accountant/reviews')}
                                className="text-xs font-bold text-[#640000] hover:underline"
                            >
                                Ver todo
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Período',
                                'Envió',
                                'Esperando',
                                'Estado',
                            ]}
                            empty={
                                panel.review_queue.length === 0 &&
                                'Nada pendiente de tu revisión.'
                            }
                        >
                            {panel.review_queue.map((review) => (
                                <tr key={review.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(review.review_href)}
                                            className="hover:text-[#640000]"
                                        >
                                            {review.company_name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {review.period_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {review.submitted_by ?? '—'}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${(review.waiting_hours ?? 0) > 48 ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {review.waiting_hours === null
                                            ? '—'
                                            : `${review.waiting_hours} h`}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                statusTone[review.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {review.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Observaciones abiertas"
                        action={
                            <Link
                                href={href('/accountant/observations')}
                                className="text-xs font-bold text-[#640000] hover:underline"
                            >
                                Ver todo
                            </Link>
                        }
                    >
                        <ul className="divide-y divide-slate-100 text-sm">
                            {panel.observations.length === 0 && (
                                <li className="py-6 text-center text-slate-500">
                                    Sin observaciones abiertas.
                                </li>
                            )}
                            {panel.observations.map((observation) => (
                                <li
                                    key={observation.id}
                                    className="flex items-start justify-between gap-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-bold">
                                            {observation.company_name} ·{' '}
                                            {observation.related_type_label}
                                            {observation.related_label
                                                ? ` ${observation.related_label}`
                                                : ''}
                                        </p>
                                        <p className="text-xs text-[#526b92]">
                                            {observation.message}
                                        </p>
                                        <p className="text-[11px] text-[#526b92]">
                                            Responsable:{' '}
                                            {observation.assignee_name ??
                                                'Sin asignar'}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <span
                                            className={toneBadge(
                                                severityTone[
                                                    observation.severity
                                                ] ?? 'slate',
                                            )}
                                        >
                                            {observation.severity_label}
                                        </span>
                                        <span
                                            className={toneBadge(
                                                observationTone[
                                                    observation.status
                                                ] ?? 'slate',
                                            )}
                                        >
                                            {observation.status_label}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-[2fr_1fr]">
                    <Card
                        title="Mis empresas"
                        action={
                            <form
                                className="flex flex-wrap gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    apply('search', search);
                                }}
                            >
                                <input
                                    aria-label="Buscar"
                                    className="h-9 rounded-lg border border-slate-200 px-3 text-sm"
                                    placeholder="Empresa o NIT"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                <select
                                    aria-label="Estado"
                                    className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                    value={filters.status ?? ''}
                                    onChange={(event) =>
                                        apply('status', event.target.value)
                                    }
                                >
                                    <option value="">Todos los estados</option>
                                    {statusOptions.map(([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    aria-label="Auxiliar"
                                    className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                    value={filters.assistant_id ?? ''}
                                    onChange={(event) =>
                                        apply(
                                            'assistant_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">
                                        Todos los auxiliares
                                    </option>
                                    {context.assistants.map((assistant) => (
                                        <option
                                            key={assistant.id}
                                            value={assistant.id}
                                        >
                                            {assistant.name}
                                        </option>
                                    ))}
                                </select>
                            </form>
                        }
                    >
                        <CompaniesTable
                            companies={panel.companies}
                            empty="No tienes empresas a cargo con esos filtros."
                        />
                    </Card>
                    <div className="grid content-start gap-3">
                        <Card title="Pólizas pendientes">
                            {panel.pending_entries === null ? (
                                <p className="text-sm text-red-700">
                                    No fue posible calcular las pólizas
                                    pendientes.
                                </p>
                            ) : (
                                <Link
                                    href={href('/accountant/entries')}
                                    className="block"
                                >
                                    <span className="text-3xl font-black text-[#061633]">
                                        {panel.pending_entries}
                                    </span>
                                    <span className="ml-2 text-sm text-[#526b92]">
                                        por revisar, aprobar o contabilizar
                                    </span>
                                </Link>
                            )}
                        </Card>
                        <Card title="Próximos vencimientos">
                            <ul className="divide-y divide-slate-100 text-sm">
                                {panel.obligations.length === 0 && (
                                    <li className="py-4 text-center text-slate-500">
                                        Sin vencimientos en 15 días.
                                    </li>
                                )}
                                {panel.obligations.map((row) => (
                                    <li
                                        key={row.key}
                                        className="flex items-center justify-between gap-2 py-2"
                                    >
                                        <span>
                                            <b>{row.name}</b>
                                            <span className="block text-xs text-[#526b92]">
                                                {row.company_name} ·{' '}
                                                {row.period}
                                            </span>
                                        </span>
                                        <span className="text-right text-xs">
                                            <span
                                                className={toneBadge(row.tone)}
                                            >
                                                {row.status_label}
                                            </span>
                                            <span className="mt-1 block">
                                                {formatDate(row.due_on)}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </div>
                </div>
            </Shell>
        </>
    );
}
