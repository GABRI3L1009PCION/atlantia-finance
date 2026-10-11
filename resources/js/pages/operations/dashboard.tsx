import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ClipboardList,
    FileText,
    Lock,
    MoreHorizontal,
    Plus,
    RefreshCcw,
    Search,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    chip,
    formatDate,
    panel,
    primaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import {
    NewIncidentDialog,
    NewTaskDialog,
    TaskManageDialog,
    companyStatusTone,
    dueLabel,
    severityLabel,
    incidentStatusLabel,
    useScopedHref,
} from '@/components/operations/kit';
import type {
    Context,
    TaskOptions,
    TaskRow,
} from '@/components/operations/kit';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Company = {
    id: number;
    name: string;
    tax_id: string;
    office_name: string;
    accountant: { id: number; name: string } | null;
    assistant: { id: number; name: string } | null;
    progress: number;
    open_tasks: number;
    status: string;
    status_label: string;
    overdue: boolean;
    blocked: boolean;
    due_at: string | null;
    period_id: number | null;
    period_name: string | null;
    review_status: string | null;
    blocked_tasks: number;
    next_action: string;
    destination: string;
};

type Panel = {
    metrics: Record<string, number>;
    comparisons: Record<
        string,
        { label: string; direction: string; tone: string } | null
    >;
    companies: Company[];
    workload:
        | {
              user_id: number;
              name: string;
              role_label: string;
              assigned_companies: number;
              tasks: Record<string, number>;
              utilization_percentage: number;
              status: string;
          }[]
        | null;
    workload_error: string | null;
    upcoming_tasks: TaskRow[];
    overdue_tasks: TaskRow[];
    reviews: {
        id: number;
        company_name: string;
        reviewer_name: string | null;
        status: string;
        status_label: string;
        submitted_at: string | null;
        destination: string;
    }[];
    closings: Company[];
    incidents: {
        id: number;
        company_name: string | null;
        type_label: string;
        title: string;
        severity: string;
        status: string;
        responsible_name: string | null;
        created_at: string;
    }[];
    calendar: {
        date: string;
        type: string;
        title: string;
        subtitle: string | null;
        destination: string | null;
    }[];
    last_updated_at: string;
};

const tabs = [
    { key: 'all', label: 'Todas' },
    { key: 'in_progress', label: 'En proceso' },
    { key: 'review', label: 'Para revisión' },
    { key: 'changes_requested', label: 'Con observaciones' },
    { key: 'overdue', label: 'Atrasadas' },
] as const;

const eventDot: Record<string, string> = {
    task: 'bg-blue-500',
    fiscal: 'bg-amber-400',
    deadline: 'bg-red-500',
    closing: 'bg-emerald-500',
    review: 'bg-blue-600',
};

export default function OperationsDashboard({
    context,
    panel: data,
    taskOptions,
    permissions,
    notice,
}: {
    context: Context;
    panel: Panel;
    taskOptions: TaskOptions;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const scoped = useScopedHref();
    const [tab, setTab] = useState<(typeof tabs)[number]['key']>('all');
    const [search, setSearch] = useState('');
    const [taskOpen, setTaskOpen] = useState(false);
    const [incidentOpen, setIncidentOpen] = useState(false);
    const [managed, setManaged] = useState<TaskRow | null>(null);
    const matches = (company: Company, key: string) =>
        key === 'all' ||
        (key === 'in_progress' &&
            ['in_progress', 'not_started'].includes(company.status)) ||
        (key === 'review' &&
            ['ready_for_review', 'in_review'].includes(company.status)) ||
        (key === 'changes_requested' &&
            company.status === 'changes_requested') ||
        (key === 'overdue' && company.overdue);
    const companies = useMemo(
        () =>
            data.companies.filter(
                (company) =>
                    matches(company, tab) &&
                    `${company.name} ${company.tax_id}`
                        .toLowerCase()
                        .includes(search.toLowerCase()),
            ),
        [data.companies, tab, search],
    );
    const kpis: {
        key: string;
        label: string;
        icon: LucideIcon;
        tone: string;
        href: string;
    }[] = [
        {
            key: 'companies_in_progress',
            label: 'Empresas en proceso',
            icon: ClipboardList,
            tone: 'bg-blue-100 text-blue-700',
            href: '/operations/companies',
        },
        {
            key: 'ready_for_review',
            label: 'Listas para revisión',
            icon: CheckCircle2,
            tone: 'bg-emerald-100 text-emerald-700',
            href: '/operations/reviews',
        },
        {
            key: 'changes_requested',
            label: 'Con observaciones',
            icon: TriangleAlert,
            tone: 'bg-amber-100 text-amber-700',
            href: '/operations/reviews',
        },
        {
            key: 'overdue_companies',
            label: 'Atrasadas',
            icon: AlertTriangle,
            tone: 'bg-red-100 text-red-700',
            href: '/operations/closings',
        },
        {
            key: 'overdue_tasks',
            label: 'Tareas vencidas',
            icon: CalendarClock,
            tone: 'bg-violet-100 text-violet-700',
            href: '/operations/tasks?overdue=1',
        },
        {
            key: 'pending_closings',
            label: 'Cierres pendientes',
            icon: Lock,
            tone: 'bg-red-100 text-red-700',
            href: '/operations/closings',
        },
        {
            key: 'blocked_companies',
            label: 'Bloqueadas',
            icon: Ban,
            tone: 'bg-slate-100 text-slate-700',
            href: '/operations/incidents',
        },
        {
            key: 'pending_documents',
            label: 'Documentos pendientes',
            icon: FileText,
            tone: 'bg-blue-100 text-blue-700',
            href: '/operations/tasks?module=documents',
        },
    ];
    const count = (key: string) =>
        data.companies.filter((company) => matches(company, key)).length;

    return (
        <>
            <Head title="Panel Gerente / Coordinador Contable" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Gerente / Coordinador Contable"
                    subtitle={`Dirección y seguimiento del trabajo contable de la oficina · ${context.period?.name ?? 'Sin período'}`}
                    actions={
                        permissions.canCreateTasks && (
                            <div className="flex">
                                <button
                                    type="button"
                                    onClick={() => setTaskOpen(true)}
                                    className={`${primaryButton} rounded-r-none`}
                                >
                                    <Plus className="size-4" />
                                    Nueva tarea
                                </button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            aria-label="Más acciones"
                                            className={`${primaryButton} rounded-l-none border-l border-white/20 px-2.5`}
                                        >
                                            <ChevronDown className="size-4" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-60 rounded-xl p-2"
                                    >
                                        {permissions.canManageIncidents && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setIncidentOpen(true)
                                                }
                                            >
                                                Registrar incidencia
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.post(
                                                    scoped(
                                                        '/operations/sync-work',
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RefreshCcw className="size-4" />{' '}
                                            Detectar trabajo pendiente
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        )
                    }
                />
                <LuzmaInsightsCard module="operations" />

                <section className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_300px]">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {kpis.map(({ key, label, icon: Icon, tone, href }) => {
                            const comparison = data.comparisons[key];

                            return (
                                <Link
                                    key={key}
                                    href={scoped(href)}
                                    className={`${panel} p-3 transition hover:border-[#c9a36a]`}
                                >
                                    <div className="flex items-center gap-3">
                                        <span
                                            className={`grid size-11 shrink-0 place-items-center rounded-full ${tone}`}
                                        >
                                            <Icon className="size-5" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="truncate text-xs font-semibold text-[#466287]">
                                                {label}
                                            </p>
                                            <p className="text-2xl font-black text-[#061633]">
                                                {data.metrics[key]}
                                            </p>
                                        </div>
                                    </div>
                                    <p
                                        className={`mt-2 text-xs font-bold ${comparison?.tone === 'positive' ? 'text-emerald-700' : comparison?.tone === 'negative' ? 'text-red-600' : 'text-[#526b92]'}`}
                                    >
                                        {comparison
                                            ? `${comparison.direction === 'up' ? '↑' : comparison.direction === 'down' ? '↓' : '→'} ${comparison.label}`
                                            : 'Datos al momento'}
                                    </p>
                                </Link>
                            );
                        })}
                    </div>
                    <div className={`${panel} p-3`}>
                        <SectionHeader
                            title="Mi calendario"
                            action={
                                <Link
                                    href={scoped('/operations/calendar')}
                                    className={chip}
                                >
                                    Ver calendario
                                </Link>
                            }
                        />
                        <ul className="space-y-2">
                            {data.calendar.map((event, index) => (
                                <li key={index}>
                                    <Link
                                        href={
                                            event.destination ??
                                            scoped('/operations/calendar')
                                        }
                                        className="flex items-center gap-3 rounded-lg p-1 hover:bg-slate-50"
                                    >
                                        <span className="grid w-11 shrink-0 place-items-center rounded-lg border border-slate-200 py-1 text-center leading-none">
                                            <span className="text-base font-black">
                                                {new Date(event.date).getDate()}
                                            </span>
                                            <span className="text-[10px] font-bold text-[#526b92] uppercase">
                                                {new Intl.DateTimeFormat(
                                                    'es-GT',
                                                    { month: 'short' },
                                                ).format(new Date(event.date))}
                                            </span>
                                        </span>
                                        <span
                                            className={`size-2.5 shrink-0 rounded-full ${eventDot[event.type] ?? 'bg-slate-400'}`}
                                        />
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-bold">
                                                {event.title}
                                            </span>
                                            <span className="block truncate text-xs text-[#526b92]">
                                                {event.subtitle}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            ))}
                            {data.calendar.length === 0 && (
                                <li className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">
                                    Sin eventos en los próximos 7 días.
                                </li>
                            )}
                        </ul>
                    </div>
                </section>

                <section className="grid gap-3 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)_300px]">
                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Estado de empresas"
                            action={
                                <Link
                                    href={scoped('/operations/companies')}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <div className="mb-2 flex flex-wrap gap-1.5">
                            {tabs.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => setTab(item.key)}
                                    className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${tab === item.key ? 'bg-[#061633] text-white ring-[#061633]' : 'bg-white text-[#395277] ring-slate-200'}`}
                                >
                                    {item.label}{' '}
                                    <span
                                        className={`rounded-full px-1.5 ${tab === item.key ? 'bg-white/20' : 'bg-slate-100'}`}
                                    >
                                        {count(item.key)}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <label className="relative mb-2 block">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                className="h-9 w-full rounded-lg border border-slate-200 pl-9 text-sm"
                                placeholder="Buscar empresa..."
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </label>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-2 py-2">Empresa</th>
                                        <th className="px-2 py-2">Contador</th>
                                        <th className="px-2 py-2">Auxiliar</th>
                                        <th className="px-2 py-2">Avance</th>
                                        <th className="px-2 py-2 text-right">
                                            Pendientes
                                        </th>
                                        <th className="px-2 py-2">Estado</th>
                                        <th className="px-2 py-2">
                                            Vencimiento
                                        </th>
                                        <th className="px-2 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {companies.map((company) => (
                                        <tr key={company.id}>
                                            <td className="px-2 py-2">
                                                <Link
                                                    href={scoped(
                                                        `/operations/companies?company=${company.id}`,
                                                    )}
                                                    className="font-bold text-[#061633] hover:text-[#640000]"
                                                >
                                                    {company.name}
                                                </Link>
                                                <p className="text-[11px] text-[#526b92]">
                                                    NIT {company.tax_id}
                                                </p>
                                            </td>
                                            <td className="px-2 py-2">
                                                {company.accountant?.name ?? (
                                                    <span className="text-red-700">
                                                        Sin contador
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {company.assistant?.name ?? (
                                                    <span className="text-amber-700">
                                                        Sin auxiliar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="w-8 font-black">
                                                        {company.progress}%
                                                    </span>
                                                    <div className="h-2 w-14 overflow-hidden rounded bg-slate-100">
                                                        <div
                                                            className={`h-full ${company.progress >= 100 ? 'bg-emerald-500' : company.progress >= 60 ? 'bg-amber-400' : 'bg-blue-500'}`}
                                                            style={{
                                                                width: `${company.progress}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-2 py-2 text-right font-black">
                                                {company.open_tasks}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        company.blocked
                                                            ? 'red'
                                                            : (companyStatusTone[
                                                                  company.status
                                                              ] ?? 'slate'),
                                                    )}
                                                >
                                                    {company.blocked
                                                        ? 'Bloqueada'
                                                        : company.status_label}
                                                </span>
                                                {company.overdue && (
                                                    <span
                                                        className={`ml-1 ${badge('red')}`}
                                                    >
                                                        Atrasada
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {formatDate(company.due_at)}
                                            </td>
                                            <td className="px-2 py-2">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <button
                                                            type="button"
                                                            aria-label="Acciones"
                                                            className="rounded p-1 hover:bg-slate-100"
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                        align="end"
                                                        className="w-56 rounded-xl p-2"
                                                    >
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                router.visit(
                                                                    scoped(
                                                                        `/operations/companies?company=${company.id}`,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Detalle operativo
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                router.visit(
                                                                    company.destination,
                                                                )
                                                            }
                                                        >
                                                            Ver empresa
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                router.visit(
                                                                    scoped(
                                                                        `/operations/tasks?company_id=${company.id}`,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Tareas de la empresa
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </td>
                                        </tr>
                                    ))}
                                    {companies.length === 0 && (
                                        <EmptyRow
                                            colSpan={8}
                                            text="No existen empresas en este filtro."
                                        />
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="carga" className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Carga de trabajo del equipo"
                            action={
                                <Link
                                    href={scoped('/operations/team')}
                                    className={chip}
                                >
                                    Ver equipo
                                </Link>
                            }
                        />
                        {data.workload === null ? (
                            <p className="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                                {data.workload_error}
                            </p>
                        ) : (
                            <table className="min-w-full text-xs">
                                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                    <tr>
                                        <th className="px-2 py-2">
                                            Colaborador
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Empresas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Tareas
                                        </th>
                                        <th className="px-2 py-2 text-right">
                                            Vencidas
                                        </th>
                                        <th className="px-2 py-2">Carga</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.workload.map((row) => {
                                        const open =
                                            (row.tasks.pending ?? 0) +
                                            (row.tasks.in_progress ?? 0) +
                                            (row.tasks.changes_requested ?? 0) +
                                            (row.tasks.blocked ?? 0) +
                                            (row.tasks.ready_for_review ?? 0) +
                                            (row.tasks.in_review ?? 0);

                                        return (
                                            <tr key={row.user_id}>
                                                <td className="px-2 py-2">
                                                    <Link
                                                        href={scoped(
                                                            `/operations/team?user=${row.user_id}`,
                                                        )}
                                                        className="font-bold hover:text-[#640000]"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                    <p className="text-[11px] text-[#526b92]">
                                                        {row.role_label}
                                                    </p>
                                                </td>
                                                <td className="px-2 py-2 text-right font-black">
                                                    {row.assigned_companies}
                                                </td>
                                                <td className="px-2 py-2 text-right font-black">
                                                    {open}
                                                </td>
                                                <td
                                                    className={`px-2 py-2 text-right font-black ${(row.tasks.overdue ?? 0) > 0 ? 'text-red-700' : ''}`}
                                                >
                                                    {row.tasks.overdue ?? 0}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex items-center gap-1.5">
                                                        <div className="h-2 w-14 overflow-hidden rounded bg-slate-100">
                                                            <div
                                                                className={`h-full ${row.utilization_percentage >= 85 ? 'bg-red-500' : row.utilization_percentage >= 65 ? 'bg-amber-400' : 'bg-emerald-500'}`}
                                                                style={{
                                                                    width: `${row.utilization_percentage}%`,
                                                                }}
                                                            />
                                                        </div>
                                                        <span className="font-black">
                                                            {
                                                                row.utilization_percentage
                                                            }
                                                            %
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {data.workload.length === 0 && (
                                        <EmptyRow
                                            colSpan={5}
                                            text="No hay colaboradores activos en la oficina."
                                        />
                                    )}
                                </tbody>
                            </table>
                        )}
                        <Link
                            href={scoped('/operations/team#carga')}
                            className="mt-2 inline-block text-xs font-bold text-[#1d4ed8]"
                        >
                            Ver análisis completo de carga de trabajo →
                        </Link>
                    </div>

                    <div className={`${panel} p-3`}>
                        <SectionHeader
                            title="Tareas próximas a vencer"
                            action={
                                <Link
                                    href={scoped('/operations/tasks')}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <ul className="space-y-2">
                            {data.upcoming_tasks.map((task) => {
                                const [label, tone] = dueLabel(task);

                                return (
                                    <li key={task.id}>
                                        <button
                                            type="button"
                                            onClick={() => setManaged(task)}
                                            className="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-100 p-2 text-left hover:border-[#c9a36a]"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-bold">
                                                    {task.title}
                                                </span>
                                                <span className="block truncate text-xs text-[#526b92]">
                                                    {task.company_name ??
                                                        'Sin empresa'}{' '}
                                                    ·{' '}
                                                    {task.assignee_name ??
                                                        'Sin responsable'}
                                                </span>
                                            </span>
                                            <span className={badge(tone)}>
                                                {label}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                            {data.upcoming_tasks.length === 0 && (
                                <li className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">
                                    Nada vence en los próximos 3 días.
                                </li>
                            )}
                        </ul>
                    </div>
                </section>

                <section className="grid gap-3 xl:grid-cols-3">
                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Tareas vencidas"
                            action={
                                <Link
                                    href={scoped('/operations/tasks?overdue=1')}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Tarea</th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2 text-right">
                                        Días
                                    </th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.overdue_tasks.map((task) => (
                                    <tr key={task.id}>
                                        <td className="px-2 py-2 font-semibold">
                                            {task.company_name ?? '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {task.title}
                                        </td>
                                        <td className="px-2 py-2">
                                            {task.assignee_name ?? (
                                                <span className="text-amber-700">
                                                    Sin asignar
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2 text-right">
                                            <span className={badge('red')}>
                                                {task.overdue_days}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            <button
                                                type="button"
                                                aria-label="Gestionar"
                                                onClick={() => setManaged(task)}
                                                className="rounded p-1 hover:bg-slate-100"
                                            >
                                                <MoreHorizontal className="size-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {data.overdue_tasks.length === 0 && (
                                    <EmptyRow
                                        colSpan={5}
                                        text="No existen tareas vencidas."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Revisiones en curso"
                            action={
                                <Link
                                    href={scoped('/operations/reviews')}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Contador</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2">Enviada</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.reviews.map((review) => (
                                    <tr key={review.id}>
                                        <td className="px-2 py-2 font-semibold">
                                            <Link
                                                href={review.destination}
                                                className="hover:text-[#640000]"
                                            >
                                                {review.company_name}
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2">
                                            {review.reviewer_name ?? (
                                                <span className="text-amber-700">
                                                    Sin revisor
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={badge(
                                                    companyStatusTone[
                                                        review.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {review.status_label}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(review.submitted_at)}
                                        </td>
                                    </tr>
                                ))}
                                {data.reviews.length === 0 && (
                                    <EmptyRow
                                        colSpan={4}
                                        text="No hay revisiones pendientes."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className={`${panel} min-w-0 p-3`}>
                        <SectionHeader
                            title="Cierres del período"
                            action={
                                <Link
                                    href={scoped('/operations/closings')}
                                    className={chip}
                                >
                                    Ver todas
                                </Link>
                            }
                        />
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2 text-right">
                                        Avance
                                    </th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2 text-right">
                                        Bloqueos
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.closings.map((company) => (
                                    <tr key={company.id}>
                                        <td className="px-2 py-2 font-semibold">
                                            {company.name}
                                        </td>
                                        <td className="px-2 py-2 text-right font-black">
                                            {company.progress}%
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={badge(
                                                    companyStatusTone[
                                                        company.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {company.status_label}
                                            </span>
                                        </td>
                                        <td
                                            className={`px-2 py-2 text-right font-black ${company.blocked_tasks > 0 ? 'text-red-700' : ''}`}
                                        >
                                            {company.blocked_tasks}
                                        </td>
                                    </tr>
                                ))}
                                {data.closings.length === 0 && (
                                    <EmptyRow
                                        colSpan={4}
                                        text="No existen cierres pendientes."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className={`${panel} p-3`}>
                    <SectionHeader
                        title="Incidencias y bloqueos"
                        action={
                            <Link
                                href={scoped('/operations/incidents')}
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
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Tipo</th>
                                    <th className="px-2 py-2">Descripción</th>
                                    <th className="px-2 py-2">Prioridad</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2">Fecha</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.incidents.map((incident) => {
                                    const [severity, severityTone] =
                                        severityLabel[incident.severity] ?? [
                                            '—',
                                            'slate',
                                        ];
                                    const [status, statusTone] =
                                        incidentStatusLabel[
                                            incident.status
                                        ] ?? ['—', 'slate'];

                                    return (
                                        <tr key={incident.id}>
                                            <td className="px-2 py-2 font-semibold">
                                                {incident.company_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {incident.type_label}
                                            </td>
                                            <td className="px-2 py-2">
                                                <Link
                                                    href={scoped(
                                                        `/operations/incidents?incident=${incident.id}`,
                                                    )}
                                                    className="hover:text-[#640000]"
                                                >
                                                    {incident.title}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        severityTone as Tone,
                                                    )}
                                                >
                                                    {severity}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={badge(
                                                        statusTone as Tone,
                                                    )}
                                                >
                                                    {status}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2">
                                                {incident.responsible_name ?? (
                                                    <span className="text-amber-700">
                                                        Sin asignar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {formatDate(
                                                    incident.created_at,
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {data.incidents.length === 0 && (
                                    <EmptyRow
                                        colSpan={7}
                                        text="No existen incidencias abiertas."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-right text-[11px] text-[#526b92]">
                        Actualizado {formatDate(data.last_updated_at, true)}
                    </p>
                </section>
            </Shell>

            <NewTaskDialog
                open={taskOpen}
                onClose={() => setTaskOpen(false)}
                options={taskOptions}
            />
            <NewIncidentDialog
                open={incidentOpen}
                onClose={() => setIncidentOpen(false)}
                options={taskOptions}
            />
            <TaskManageDialog
                task={managed}
                users={taskOptions.users}
                permissions={permissions}
                onClose={() => setManaged(null)}
            />
        </>
    );
}
