import { Head, Link } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    CalendarClock,
    CircleDollarSign,
    FileText,
    Flag,
    Landmark,
    ListChecks,
    MessageSquareWarning,
    Scale,
    Search,
    ShoppingCart,
    Upload,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    ProgressValue,
    StageIcon,
    StageLegend,
    priorityTone,
    taskAction,
    taskTone,
    useAssistantHref,
} from '@/components/assistant/kit';
import type {
    AssistantCompany,
    AssistantContext,
    AssistantPermissions,
    AssistantTask,
    PendingDocument,
    Reconciliation,
} from '@/components/assistant/kit';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    Table,
    money,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';

type Observation = {
    id: number;
    company_name: string;
    related_type_label: string;
    related_label: string | null;
    message: string;
    severity: string;
    severity_label: string;
    status: string;
    status_label: string;
    created_at: string;
};
type CalendarEvent = {
    date: string;
    type: string;
    title: string;
    subtitle: string | null;
    priority: string;
    destination: string | null;
};
type Panel = {
    metrics: Record<string, number | null>;
    status_counts: Record<string, number>;
    companies: AssistantCompany[];
    today_tasks: AssistantTask[];
    observations: Observation[];
    documents: PendingDocument[] | null;
    reconciliations: Reconciliation[] | null;
    calendar: CalendarEvent[];
    quick_actions: { key: string; label: string; module: string }[];
};

const tabs: [string, string, (company: AssistantCompany) => boolean][] = [
    ['all', 'Todas', () => true],
    [
        'in_progress',
        'En proceso',
        (c) => ['not_started', 'in_progress'].includes(c.status),
    ],
    [
        'with_observations',
        'Con observaciones',
        (c) => c.observations > 0 || c.status === 'changes_requested',
    ],
    [
        'in_review',
        'En revisión',
        (c) =>
            ['ready_for_review', 'in_review', 'first_level_approved'].includes(
                c.status,
            ),
    ],
    ['closed', 'Cerradas', (c) => c.status === 'closed'],
];

const actionIcons: Record<string, LucideIcon> = {
    upload_document: Upload,
    new_purchase: ShoppingCart,
    new_sale: FileText,
    receipt: CircleDollarSign,
    payment: WalletCards,
    reconcile: Landmark,
    new_entry: BookOpen,
};
const eventTone: Record<string, string> = {
    task: 'bg-blue-500',
    observation: 'bg-violet-500',
    fiscal: 'bg-amber-500',
    closing: 'bg-red-600',
};

function Metric({
    label,
    value,
    icon: Icon,
    tone,
    href,
}: {
    label: string;
    value: number | null;
    icon: LucideIcon;
    tone: string;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="flex items-center gap-3 rounded-xl border border-[#e7e1dd] bg-white p-3 shadow-sm hover:border-[#640000]/40"
        >
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-full ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <span>
                <span className="block text-xs text-[#526b92]">{label}</span>
                <span className="block text-2xl font-black text-[#061633]">
                    {value === null ? (
                        <span className="text-sm font-semibold text-violet-700">
                            No disponible
                        </span>
                    ) : (
                        value
                    )}
                </span>
            </span>
        </Link>
    );
}

export default function AssistantDashboard({
    context,
    panel,
    permissions,
    notice,
}: {
    context: AssistantContext;
    panel: Panel;
    permissions: AssistantPermissions;
    notice: string | null;
}) {
    const href = useAssistantHref();
    const [tab, setTab] = useState('all');
    const [search, setSearch] = useState('');
    const companies = useMemo(() => {
        const predicate =
            tabs.find(([key]) => key === tab)?.[2] ?? (() => true);

        return panel.companies.filter(
            (company) =>
                predicate(company) &&
                `${company.name} ${company.tax_id}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
        );
    }, [panel.companies, search, tab]);

    return (
        <>
            <Head title="Panel Auxiliar Contable" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Auxiliar Contable"
                    subtitle={`Mi trabajo diario, documentos, operaciones y avance de mis empresas · ${context.period?.name ?? 'Sin período'}`}
                />
                <LuzmaInsightsCard />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <Metric
                        label="Empresas asignadas"
                        value={panel.metrics.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/assistant/companies')}
                    />
                    <Metric
                        label="Tareas pendientes"
                        value={panel.metrics.pending_tasks}
                        icon={ListChecks}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/assistant/tasks')}
                    />
                    <Metric
                        label="Documentos pendientes"
                        value={panel.metrics.pending_documents}
                        icon={FileText}
                        tone="bg-red-50 text-red-700"
                        href={href('/assistant/documents')}
                    />
                    <Metric
                        label="Conciliaciones pendientes"
                        value={panel.metrics.pending_reconciliations}
                        icon={Scale}
                        tone="bg-amber-50 text-amber-700"
                        href={href('/assistant/reconciliations')}
                    />
                    <Metric
                        label="Observaciones recibidas"
                        value={panel.metrics.received_observations}
                        icon={MessageSquareWarning}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/assistant/observations')}
                    />
                    <Metric
                        label="Tareas vencidas"
                        value={panel.metrics.overdue_tasks}
                        icon={Flag}
                        tone="bg-red-50 text-[#640000]"
                        href={href('/assistant/tasks?overdue=1')}
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)]">
                    <Card
                        title="Mis empresas"
                        action={
                            <Link
                                href={href('/assistant/companies')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <div className="mb-3 flex flex-wrap gap-1.5">
                            {tabs.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${tab === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                                >
                                    {label}{' '}
                                    <span
                                        className={`ml-1 rounded-full px-1.5 ${key === 'all' ? 'bg-[#061633] text-white' : 'bg-red-50 text-red-700'}`}
                                    >
                                        {panel.status_counts[key] ?? 0}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <div className="relative mb-3">
                            <Search className="absolute top-2.5 left-3 size-4 text-slate-400" />
                            <input
                                aria-label="Buscar empresa"
                                className="h-9 w-full rounded-lg border border-slate-200 pr-3 pl-9 text-sm"
                                placeholder="Buscar empresa..."
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </div>
                        <Table
                            headers={[
                                'Empresa',
                                'NIT',
                                'Contador',
                                'Avance',
                                'Pendientes',
                                'Observaciones',
                                'Estado',
                                'Vencimiento',
                                '',
                            ]}
                            empty={
                                companies.length === 0 &&
                                'No tienes empresas asignadas.'
                            }
                        >
                            {companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <a
                                            href={company.destination}
                                            className="hover:text-[#640000]"
                                        >
                                            {company.name}
                                        </a>
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.tax_id}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.accountant?.name ?? (
                                            <span className="text-amber-700">
                                                Sin asignar
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        <ProgressValue
                                            value={company.progress}
                                        />
                                    </td>
                                    <td
                                        className={`px-2 py-2 font-bold ${company.pending_items > 0 ? 'text-red-700' : ''}`}
                                    >
                                        {company.pending_items}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.observations}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                company.blocked
                                                    ? 'red'
                                                    : (statusTone[
                                                          company.status
                                                      ] ?? 'slate'),
                                            )}
                                        >
                                            {company.blocked
                                                ? 'Bloqueada'
                                                : company.status_label}
                                        </span>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${company.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(company.due_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.can_submit &&
                                        permissions.canSubmitReview ? (
                                            <Link
                                                href={href(
                                                    `/assistant/companies/${company.id}/submit`,
                                                )}
                                                className="text-xs font-bold text-[#640000] hover:underline"
                                            >
                                                Enviar a revisión
                                            </Link>
                                        ) : (
                                            <span className="text-[11px] text-[#526b92]">
                                                {company.next_action}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <div className="grid content-start gap-3">
                        <Card title="Acciones rápidas">
                            {panel.quick_actions.length === 0 ? (
                                <p className="text-sm text-[#526b92]">
                                    Tu rol no tiene acciones de registro
                                    habilitadas.
                                </p>
                            ) : (
                                <div className="grid grid-cols-4 gap-2">
                                    {panel.quick_actions.map((action) => {
                                        const Icon =
                                            actionIcons[action.key] ?? FileText;

                                        return (
                                            <Link
                                                key={action.key}
                                                href={href(
                                                    `/assistant/modules/${action.module}`,
                                                )}
                                                className="flex flex-col items-center gap-1 rounded-lg p-2 text-center text-[11px] font-semibold text-[#061633] hover:bg-slate-50"
                                            >
                                                <span className="grid size-10 place-items-center rounded-full bg-emerald-50 text-emerald-700">
                                                    <Icon className="size-5" />
                                                </span>
                                                {action.label}
                                            </Link>
                                        );
                                    })}
                                    <Link
                                        href={href('/assistant/companies')}
                                        className="flex flex-col items-center gap-1 rounded-lg p-2 text-center text-[11px] font-semibold text-[#061633] hover:bg-slate-50"
                                    >
                                        <span className="grid size-10 place-items-center rounded-full bg-blue-50 text-blue-700">
                                            <Search className="size-5" />
                                        </span>
                                        Ver empresa
                                    </Link>
                                </div>
                            )}
                        </Card>
                        <Card
                            title="Mi calendario"
                            action={
                                <Link
                                    href={href('/assistant/calendar')}
                                    className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                                >
                                    Ver calendario
                                </Link>
                            }
                        >
                            <ul className="space-y-2 text-sm">
                                {panel.calendar.length === 0 && (
                                    <li className="py-4 text-center text-slate-500">
                                        Sin vencimientos en los próximos días.
                                    </li>
                                )}
                                {panel.calendar.map((event, index) => {
                                    const date = new Date(
                                        event.date.length === 10
                                            ? `${event.date}T12:00:00`
                                            : event.date,
                                    );

                                    return (
                                        <li
                                            key={`${event.date}-${index}`}
                                            className="flex items-start gap-3"
                                        >
                                            <span className="grid w-11 shrink-0 rounded-lg border border-slate-200 py-1 text-center leading-tight">
                                                <b>{date.getDate()}</b>
                                                <span className="text-[10px] uppercase">
                                                    {date.toLocaleDateString(
                                                        'es-GT',
                                                        { month: 'short' },
                                                    )}
                                                </span>
                                            </span>
                                            <span
                                                className={`mt-1.5 size-2.5 shrink-0 rounded-full ${eventTone[event.type] ?? 'bg-slate-400'}`}
                                            />
                                            <span className="min-w-0">
                                                <b className="block truncate">
                                                    {event.destination ? (
                                                        <a
                                                            href={
                                                                event.destination
                                                            }
                                                            className="hover:text-[#640000]"
                                                        >
                                                            {event.title}
                                                        </a>
                                                    ) : (
                                                        event.title
                                                    )}
                                                </b>
                                                <span className="text-xs text-[#526b92]">
                                                    {event.subtitle}
                                                </span>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </Card>
                    </div>
                </div>

                <Card
                    title="Tareas próximas"
                    action={
                        <Link
                            href={href('/assistant/tasks')}
                            className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                        >
                            Ver todas
                        </Link>
                    }
                >
                    <Table
                        headers={[
                            'Tarea',
                            'Empresa',
                            'Tipo',
                            'Vencimiento',
                            'Prioridad',
                            'Estado',
                            'Acción',
                        ]}
                        empty={
                            panel.today_tasks.length === 0 &&
                            'No tienes tareas pendientes.'
                        }
                    >
                        {panel.today_tasks.map((task) => {
                            const [priority, tone] = priorityTone[
                                task.priority
                            ] ?? [task.priority, 'slate'];

                            return (
                                <tr key={task.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {task.title}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.company_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.module_label}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${task.overdue_days > 0 ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(task.due_at)}
                                        {task.overdue_days > 0
                                            ? ` · vencida ${task.overdue_days} d`
                                            : ''}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {priority}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                taskTone[task.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {task.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {task.can_start &&
                                        permissions.canStartTasks ? (
                                            <button
                                                type="button"
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                                onClick={() =>
                                                    taskAction(task, 'start')
                                                }
                                            >
                                                Iniciar
                                            </button>
                                        ) : (
                                            <a
                                                href={task.continue_to}
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                            >
                                                Continuar
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Observaciones recibidas"
                        action={
                            <Link
                                href={href('/assistant/observations')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Registro',
                                'Observación',
                                'Prioridad',
                                'Fecha',
                                'Estado',
                                '',
                            ]}
                            empty={
                                panel.observations.length === 0 &&
                                'No tienes observaciones pendientes.'
                            }
                        >
                            {panel.observations.map((observation) => {
                                const [priority, tone] = priorityTone[
                                    observation.severity
                                ] ?? [observation.severity_label, 'slate'];

                                return (
                                    <tr key={observation.id}>
                                        <td className="px-2 py-2 font-bold">
                                            {observation.company_name}
                                        </td>
                                        <td className="px-2 py-2">
                                            {observation.related_type_label}{' '}
                                            {observation.related_label}
                                        </td>
                                        <td
                                            className="max-w-xs truncate px-2 py-2"
                                            title={observation.message}
                                        >
                                            {observation.message}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span className={toneBadge(tone)}>
                                                {priority}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(observation.created_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    observation.status ===
                                                        'reopened'
                                                        ? 'red'
                                                        : 'amber',
                                                )}
                                            >
                                                {observation.status_label}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            <Link
                                                href={href(
                                                    `/assistant/observations?observation=${observation.id}`,
                                                )}
                                                className={secondaryButton}
                                            >
                                                Ver
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                    <Card
                        title="Documentos pendientes"
                        action={
                            <Link
                                href={href('/assistant/documents')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        {panel.documents === null ? (
                            <p className="text-sm text-violet-700">
                                No disponible: no fue posible verificar los
                                documentos.
                            </p>
                        ) : (
                            <Table
                                headers={[
                                    'Empresa',
                                    'Tipo',
                                    'Descripción',
                                    'Fecha',
                                    'Estado',
                                ]}
                                empty={
                                    panel.documents.length === 0 &&
                                    'No existen documentos pendientes.'
                                }
                            >
                                {panel.documents.map((document) => (
                                    <tr key={document.key}>
                                        <td className="px-2 py-2 font-bold">
                                            {document.company}
                                        </td>
                                        <td className="px-2 py-2">
                                            {document.type}
                                        </td>
                                        <td
                                            className="max-w-xs truncate px-2 py-2"
                                            title={document.description}
                                        >
                                            {document.description}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(document.requested_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    document.status ===
                                                        'requested'
                                                        ? 'red'
                                                        : 'amber',
                                                )}
                                            >
                                                {document.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Conciliaciones pendientes"
                        action={
                            <Link
                                href={href('/assistant/reconciliations')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        {panel.reconciliations === null ? (
                            <p className="text-sm text-violet-700">
                                No fue posible verificar las conciliaciones.
                            </p>
                        ) : (
                            <Table
                                headers={[
                                    'Empresa',
                                    'Cuenta',
                                    'Tipo',
                                    'Saldo banco / auxiliar',
                                    'Saldo libro',
                                    'Diferencia',
                                    'Estado',
                                ]}
                                empty={
                                    panel.reconciliations.length === 0 &&
                                    'No tienes conciliaciones pendientes.'
                                }
                            >
                                {panel.reconciliations.map((row) => (
                                    <tr key={row.key}>
                                        <td className="px-2 py-2 font-bold">
                                            {row.company}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.destination ? (
                                                <a
                                                    href={row.destination}
                                                    className="hover:text-[#640000]"
                                                >
                                                    {row.account}
                                                </a>
                                            ) : (
                                                row.account
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.type}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.auxiliary_cents === null
                                                ? 'No disponible'
                                                : money(row.auxiliary_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.ledger_cents === null
                                                ? 'No disponible'
                                                : money(row.ledger_cents)}
                                        </td>
                                        <td
                                            className={`px-2 py-2 font-bold ${row.difference_cents ? 'text-red-700' : ''}`}
                                        >
                                            {row.difference_cents === null
                                                ? '—'
                                                : money(row.difference_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    row.status === 'error'
                                                        ? 'violet'
                                                        : row.status ===
                                                            'difference'
                                                          ? 'red'
                                                          : 'amber',
                                                )}
                                            >
                                                {row.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </Card>
                    <Card title="Avance por empresa" action={<StageLegend />}>
                        <Table
                            headers={[
                                'Empresa',
                                ...(panel.companies[0]?.stages.map(
                                    (stage) => stage.label,
                                ) ?? []),
                                'Avance',
                            ]}
                            empty={
                                panel.companies.length === 0 &&
                                'No tienes empresas asignadas.'
                            }
                        >
                            {panel.companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {company.name}
                                    </td>
                                    {company.stages.map((stage) => (
                                        <td
                                            key={stage.key}
                                            className="px-2 py-2 text-center"
                                        >
                                            <StageIcon stage={stage} />
                                        </td>
                                    ))}
                                    <td className="px-2 py-2">
                                        <ProgressValue
                                            value={company.progress}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
                <p className="flex items-center gap-1 text-[11px] text-[#526b92]">
                    <CalendarClock className="size-3.5" /> El avance se calcula
                    por etapa con datos reales; las etapas que la empresa no usa
                    no penalizan.
                </p>
            </Shell>
        </>
    );
}
