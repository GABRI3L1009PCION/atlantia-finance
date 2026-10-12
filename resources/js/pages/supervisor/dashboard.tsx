import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    CalendarClock,
    ClipboardCheck,
    FileText,
    FolderOpen,
    Landmark,
    Plus,
    Search,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    primaryButton,
    priorityLabel,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import {
    AssignWorkDialog,
    Card,
    ProgressBar,
    Table,
    statusTone,
    toneBadge,
    useSupervisorHref,
} from '@/components/supervisor/kit';
import type {
    SupervisedCompany,
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';

type Panel = {
    metrics: Record<string, number>;
    status_chart: {
        key: string;
        label: string;
        value: number;
        color: string;
    }[];
    team:
        | {
              user_id: number;
              name: string;
              role_label: string;
              supervised_companies: number;
              open_tasks: number;
              tasks: Record<string, number>;
              utilization_percentage: number;
          }[]
        | null;
    team_error: string | null;
    obligations: {
        key: string;
        company_name: string;
        name: string;
        period: string;
        due_on: string;
        days_left: number;
        responsible: string | null;
        status: string;
        status_label: string;
        tone: string;
    }[];
    alerts: {
        key: string;
        category: string;
        title: string;
        detail: string;
        company: string | null;
        date: string | null;
        priority: string;
        destination: string;
    }[];
    documents: {
        type: string;
        received: number;
        processed: number;
        pending: number;
        rejected: number;
    }[];
    tasks: {
        id: number;
        title: string;
        company_name: string | null;
        assignee_name: string | null;
        priority: string;
        due_at: string | null;
        status_label: string;
        overdue_days: number;
        destination: string;
    }[];
    companies: SupervisedCompany[];
};

const WORK_TYPES = {
    purchases: 'Compras',
    sales: 'Ventas',
    accounting: 'Contabilidad',
    banking: 'Bancos',
    reconciliation: 'Conciliación',
    receivables: 'Cuentas por cobrar',
    payables: 'Cuentas por pagar',
    tax: 'Declaraciones',
    reports: 'Reportes',
    documents: 'Documentos',
};

function Donut({ data }: { data: Panel['status_chart'] }) {
    const total = data.reduce((sum, item) => sum + item.value, 0);
    let offset = 0;

    return (
        <svg viewBox="0 0 42 42" className="size-40 shrink-0">
            <circle
                cx="21"
                cy="21"
                r="15.9"
                fill="transparent"
                stroke="#eef2f7"
                strokeWidth="6"
            />
            {total > 0 &&
                data
                    .filter((item) => item.value > 0)
                    .map((item) => {
                        const length = (item.value / total) * 100;
                        const circle = (
                            <circle
                                key={item.key}
                                cx="21"
                                cy="21"
                                r="15.9"
                                fill="transparent"
                                stroke={item.color}
                                strokeWidth="6"
                                strokeDasharray={`${length} ${100 - length}`}
                                strokeDashoffset={25 - offset}
                            />
                        );
                        offset += length;

                        return circle;
                    })}
            <text
                x="21"
                y="21"
                textAnchor="middle"
                className="fill-[#061633] text-[8px] font-black"
            >
                {total}
            </text>
            <text
                x="21"
                y="26.5"
                textAnchor="middle"
                className="fill-[#526b92] text-[3px] font-semibold"
            >
                Empresas
            </text>
        </svg>
    );
}

export default function SupervisorDashboard({
    context,
    panel: data,
    permissions,
    filters,
    notice,
}: {
    context: SupervisorContext;
    panel: Panel;
    permissions: SupervisorPermissions;
    filters: Record<string, string>;
    notice: string | null;
}) {
    const page = usePage();
    const href = useSupervisorHref();
    const [assignOpen, setAssignOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [pageNumber, setPageNumber] = useState(1);
    const [sort, setSort] = useState<'name' | 'progress'>('name');
    const kpis: {
        key: string;
        label: string;
        icon: LucideIcon;
        tone: string;
        link: string;
        hint?: string;
    }[] = [
        {
            key: 'companies',
            label: 'Empresas asignadas',
            icon: Building2,
            tone: 'bg-blue-100 text-blue-700',
            link: '/supervisor/companies',
        },
        {
            key: 'pending_periods',
            label: 'Períodos pendientes',
            icon: FileText,
            tone: 'bg-emerald-100 text-emerald-700',
            link: '/supervisor/companies',
        },
        {
            key: 'in_review',
            label: 'En revisión por aprobar',
            icon: ClipboardCheck,
            tone: 'bg-amber-100 text-amber-700',
            link: '/supervisor/reviews',
        },
        {
            key: 'upcoming_declarations',
            label: 'Declaraciones próximas',
            icon: CalendarClock,
            tone: 'bg-violet-100 text-violet-700',
            link: '/supervisor/fiscal/obligations',
            hint: 'vencen en ≤ 7 días',
        },
        {
            key: 'missing_documents',
            label: 'Documentos faltantes',
            icon: FolderOpen,
            tone: 'bg-red-100 text-red-700',
            link: '/supervisor/operations/documents',
            hint: 'requieren seguimiento',
        },
        {
            key: 'pending_reconciliations',
            label: 'Conciliaciones pendientes',
            icon: Landmark,
            tone: 'bg-teal-100 text-teal-700',
            link: '/supervisor/operations/banks',
        },
    ];
    const secondary = [
        ['overdue_tasks', 'Tareas vencidas'],
        ['open_incidents', 'Incidencias abiertas'],
        ['closed_periods', 'Períodos completados'],
        ['pending_reviews', 'Revisiones pendientes'],
        ['corrections', 'Correcciones solicitadas'],
        ['up_to_date', 'Empresas al día'],
    ] as const;
    const companies = useMemo(() => {
        const rows = data.companies.filter((company) =>
            `${company.name} ${company.tax_id}`
                .toLowerCase()
                .includes(search.toLowerCase()),
        );

        return [...rows].sort((a, b) =>
            sort === 'name'
                ? a.name.localeCompare(b.name)
                : b.progress - a.progress,
        );
    }, [data.companies, search, sort]);
    const pages = Math.max(1, Math.ceil(companies.length / 10));
    const visible = companies.slice((pageNumber - 1) * 10, pageNumber * 10);
    const applyFilter = (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            '/supervisor/dashboard',
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, v]) => v !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };
    const maxDocuments = Math.max(
        1,
        ...data.documents.map((row) => row.received),
    );

    return (
        <>
            <Head title="Dashboard Supervisor Contable" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Dashboard Supervisor Contable"
                    subtitle={`Resumen general de tus empresas y trabajos contables · ${context.period?.name ?? 'Sin período'}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Empresa"
                                className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    applyFilter(
                                        'company_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Todas las empresas</option>
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Responsable"
                                className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                value={filters.responsible_id ?? ''}
                                onChange={(event) =>
                                    applyFilter(
                                        'responsible_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Todos los responsables</option>
                                {context.team.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Estado"
                                className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    applyFilter('status', event.target.value)
                                }
                            >
                                <option value="">Todos los estados</option>
                                <option value="in_progress">En proceso</option>
                                <option value="ready_for_review">
                                    En revisión
                                </option>
                                <option value="changes_requested">
                                    Corrección requerida
                                </option>
                                <option value="fiscal_pending">
                                    Fiscal pendiente
                                </option>
                                <option value="ready_to_close">
                                    Lista para cierre
                                </option>
                                <option value="closed">Cerrado</option>
                                <option value="blocked">Bloqueado</option>
                            </select>
                        </div>
                    }
                />
                <LuzmaInsightsCard />

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    {kpis.map(
                        ({ key, label, icon: Icon, tone, link, hint }) => (
                            <Link
                                key={key}
                                href={href(link)}
                                className={`${panel} flex items-center gap-3 p-3 transition hover:border-[#c9a36a]`}
                            >
                                <span
                                    className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
                                >
                                    <Icon className="size-6" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-3xl font-black text-[#061633]">
                                        {data.metrics[key]}
                                    </span>
                                    <span className="block text-xs font-semibold text-[#466287]">
                                        {label}
                                    </span>
                                    {hint && (
                                        <span className="block text-[11px] font-bold text-red-600">
                                            {hint}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        ),
                    )}
                </section>
                <section className="flex flex-wrap gap-2">
                    {secondary.map(([key, label]) => (
                        <span
                            key={key}
                            className={badge(
                                key === 'overdue_tasks' ||
                                    key === 'open_incidents' ||
                                    key === 'corrections'
                                    ? data.metrics[key] > 0
                                        ? 'red'
                                        : 'green'
                                    : 'slate',
                            )}
                        >
                            {label}: {data.metrics[key]}
                        </span>
                    ))}
                </section>

                <section className="grid gap-3 xl:grid-cols-3">
                    <Card title="Estado de empresas">
                        <div className="flex flex-wrap items-center gap-4">
                            <Donut data={data.status_chart} />
                            <ul className="min-w-40 flex-1 space-y-2 text-sm">
                                {data.status_chart.map((item) => (
                                    <li
                                        key={item.key}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <span className="flex items-center gap-2">
                                            <span
                                                className="size-3 rounded-full"
                                                style={{
                                                    backgroundColor: item.color,
                                                }}
                                            />
                                            {item.label}
                                        </span>
                                        <span className="font-black">
                                            {item.value}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Card>
                    <Card
                        title="Carga de trabajo del equipo"
                        action={
                            <Link
                                href={href('/supervisor/team')}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Ver equipo
                            </Link>
                        }
                    >
                        {data.team === null ? (
                            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                                {data.team_error}
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {data.team.map((member) => (
                                    <li key={member.user_id}>
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="font-bold">
                                                {member.name}
                                            </span>
                                            <span
                                                className={`font-black ${member.utilization_percentage >= 85 ? 'text-red-700' : ''}`}
                                            >
                                                {member.utilization_percentage}%
                                            </span>
                                        </div>
                                        <div className="mt-1 h-2.5 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className={`h-full ${member.utilization_percentage >= 85 ? 'bg-red-500' : member.utilization_percentage >= 65 ? 'bg-amber-400' : 'bg-blue-500'}`}
                                                style={{
                                                    width: `${member.utilization_percentage}%`,
                                                }}
                                            />
                                        </div>
                                        <p className="text-[11px] text-[#526b92]">
                                            {member.role_label} · Empresas:{' '}
                                            {member.supervised_companies} ·
                                            Pendientes: {member.open_tasks} ·
                                            Vencidas: {member.tasks.overdue}
                                        </p>
                                    </li>
                                ))}
                                {data.team.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        No hay colaboradores asignados a tus
                                        empresas.
                                    </li>
                                )}
                            </ul>
                        )}
                    </Card>
                    <Card
                        title="Próximos vencimientos fiscales"
                        action={
                            <Link
                                href={href('/supervisor/fiscal/obligations')}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Ver todos
                            </Link>
                        }
                    >
                        <ul className="space-y-2">
                            {data.obligations.map((row) => (
                                <li
                                    key={row.key}
                                    className="flex items-center gap-3"
                                >
                                    <span className="grid w-12 shrink-0 place-items-center rounded-lg border border-red-100 bg-red-50 py-1 leading-none text-[#640000]">
                                        <span className="text-lg font-black">
                                            {new Date(
                                                `${row.due_on}T00:00:00`,
                                            ).getDate()}
                                        </span>
                                        <span className="text-[10px] font-bold uppercase">
                                            {new Intl.DateTimeFormat('es-GT', {
                                                month: 'short',
                                            }).format(
                                                new Date(
                                                    `${row.due_on}T00:00:00`,
                                                ),
                                            )}
                                        </span>
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-bold">
                                            {row.company_name}
                                        </span>
                                        <span className="block truncate text-xs text-[#526b92]">
                                            {row.name} – {row.period}
                                        </span>
                                    </span>
                                    <span className={toneBadge(row.tone)}>
                                        {row.days_left < 0
                                            ? `${Math.abs(row.days_left)} días vencida`
                                            : `${row.days_left} días`}
                                    </span>
                                </li>
                            ))}
                            {data.obligations.length === 0 && (
                                <li className="text-sm text-slate-500">
                                    Sin obligaciones pendientes en los próximos
                                    45 días.
                                </li>
                            )}
                        </ul>
                    </Card>
                </section>

                <section className="grid gap-3 xl:grid-cols-3">
                    <Card
                        title="Alertas recientes"
                        action={
                            <Link
                                href={href('/supervisor/alerts')}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <ul className="space-y-2">
                            {data.alerts.map((alert) => (
                                <li key={alert.key}>
                                    <Link
                                        href={alert.destination}
                                        className="flex items-start gap-2 rounded-lg p-1 hover:bg-slate-50"
                                    >
                                        <AlertTriangle
                                            className={`mt-0.5 size-5 shrink-0 ${alert.priority === 'high' ? 'text-red-600' : 'text-amber-500'}`}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-bold">
                                                {alert.company ??
                                                    alert.category}
                                            </span>
                                            <span className="block text-xs text-[#526b92]">
                                                {alert.title} · {alert.detail}
                                            </span>
                                        </span>
                                        {alert.date && (
                                            <span className="shrink-0 text-[11px] text-[#526b92]">
                                                {relativeTime(alert.date)}
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                            {data.alerts.length === 0 && (
                                <li className="text-sm text-slate-500">
                                    Sin alertas: todo al día.
                                </li>
                            )}
                        </ul>
                    </Card>
                    <Card
                        title={`Documentos recibidos (${context.period?.name ?? 'mes'})`}
                        action={
                            <Link
                                href={href('/supervisor/operations/documents')}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Ver todos
                            </Link>
                        }
                    >
                        <ul className="space-y-3">
                            {data.documents.map((row) => (
                                <li
                                    key={row.type}
                                    className="grid grid-cols-[1fr_120px_36px] items-center gap-2 text-sm"
                                >
                                    <span
                                        title={`Procesados ${row.processed} · Pendientes ${row.pending} · Rechazados ${row.rejected}`}
                                    >
                                        {row.type}
                                    </span>
                                    <div className="h-3 overflow-hidden rounded bg-slate-100">
                                        <div
                                            className="h-full bg-blue-500"
                                            style={{
                                                width: `${(row.received / maxDocuments) * 100}%`,
                                            }}
                                        />
                                    </div>
                                    <span className="text-right font-black">
                                        {row.received}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Pasa el cursor sobre cada tipo para ver procesados,
                            pendientes y rechazados.
                        </p>
                    </Card>
                    <Card
                        title="Tareas pendientes"
                        action={
                            <Link
                                href={href('/supervisor/tasks')}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <ul className="space-y-2">
                            {data.tasks.map((task) => {
                                const [label, tone] = priorityLabel[
                                    task.priority
                                ] ?? [task.priority, 'slate'];

                                return (
                                    <li
                                        key={task.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <a
                                            href={task.destination}
                                            className="min-w-0 flex-1"
                                        >
                                            <span className="block truncate font-bold">
                                                {task.title}
                                            </span>
                                            <span className="block truncate text-xs text-[#526b92]">
                                                {task.company_name} ·{' '}
                                                {task.assignee_name ??
                                                    'Sin responsable'}
                                            </span>
                                        </a>
                                        <span className={badge(tone as Tone)}>
                                            {label}
                                        </span>
                                        <span
                                            className={`w-12 text-right text-xs ${task.overdue_days > 0 ? 'font-bold text-red-700' : ''}`}
                                        >
                                            {task.due_at
                                                ? formatDate(task.due_at).slice(
                                                      0,
                                                      5,
                                                  )
                                                : '—'}
                                        </span>
                                    </li>
                                );
                            })}
                            {data.tasks.length === 0 && (
                                <li className="text-sm text-slate-500">
                                    Sin tareas pendientes.
                                </li>
                            )}
                        </ul>
                    </Card>
                </section>

                <Card
                    title="Empresas asignadas"
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <label className="relative">
                                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    className="h-9 rounded-lg border border-slate-200 pl-8 text-sm"
                                    placeholder="Buscar empresa..."
                                    value={search}
                                    onChange={(event) => {
                                        setSearch(event.target.value);
                                        setPageNumber(1);
                                    }}
                                />
                            </label>
                            <select
                                aria-label="Ordenar"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={sort}
                                onChange={(event) =>
                                    setSort(
                                        event.target.value as
                                            'name' | 'progress',
                                    )
                                }
                            >
                                <option value="name">Ordenar por nombre</option>
                                <option value="progress">
                                    Ordenar por progreso
                                </option>
                            </select>
                            {permissions.canAssign && (
                                <button
                                    type="button"
                                    className={primaryButton}
                                    onClick={() => setAssignOpen(true)}
                                >
                                    <Plus className="size-4" /> Asignar trabajo
                                </button>
                            )}
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Empresa',
                            'NIT',
                            'Responsable',
                            'Período',
                            'Contabilidad',
                            'Fiscal',
                            'Documentos',
                            'Progreso',
                            'Estado',
                            'Acciones',
                        ]}
                        empty={
                            visible.length === 0 &&
                            'No tienes empresas asignadas con estos filtros.'
                        }
                    >
                        {visible.map((company) => (
                            <tr key={company.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(company.expediente)}
                                        className="hover:text-[#640000]"
                                    >
                                        {company.name}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{company.tax_id}</td>
                                <td className="px-2 py-2">
                                    {company.accountant?.name ?? (
                                        <span className="text-red-700">
                                            Sin asignar
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {company.period_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <ProgressBar
                                        value={company.accounting_progress}
                                    />
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            company.fiscal_tone,
                                        )}
                                    >
                                        {company.fiscal_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {company.documents_done} /{' '}
                                    {company.documents_total}
                                </td>
                                <td className="px-2 py-2">
                                    <ProgressBar value={company.progress} />
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            company.blocked
                                                ? 'red'
                                                : (statusTone[company.status] ??
                                                      'slate'),
                                        )}
                                    >
                                        {company.blocked
                                            ? 'Bloqueado'
                                            : company.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex gap-1">
                                        <Link
                                            href={href(company.expediente)}
                                            className={secondaryButton}
                                            title="Ver expediente"
                                        >
                                            <FileText className="size-3.5" />
                                        </Link>
                                        <Link
                                            href={href('/supervisor/reviews')}
                                            className={secondaryButton}
                                            title="Revisar"
                                        >
                                            <ClipboardCheck className="size-3.5" />
                                        </Link>
                                        <Link
                                            href={href(
                                                `${company.expediente}?tab=reports`,
                                            )}
                                            className={secondaryButton}
                                            title="Ver reportes"
                                        >
                                            <BarChart3 className="size-3.5" />
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                    {pages > 1 && (
                        <div className="mt-2 flex items-center justify-end gap-2 text-xs">
                            <button
                                type="button"
                                className={secondaryButton}
                                disabled={pageNumber === 1}
                                onClick={() => setPageNumber(pageNumber - 1)}
                            >
                                Anterior
                            </button>
                            <span>
                                Página {pageNumber} de {pages}
                            </span>
                            <button
                                type="button"
                                className={secondaryButton}
                                disabled={pageNumber === pages}
                                onClick={() => setPageNumber(pageNumber + 1)}
                            >
                                Siguiente
                            </button>
                        </div>
                    )}
                </Card>
            </Shell>
            <AssignWorkDialog
                open={assignOpen}
                onClose={() => setAssignOpen(false)}
                context={context}
                workTypes={WORK_TYPES}
            />
        </>
    );
}
