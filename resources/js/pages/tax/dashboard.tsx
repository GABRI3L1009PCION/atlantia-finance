import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpenCheck,
    Building2,
    CalendarClock,
    Clock3,
    FileWarning,
    Flag,
    MessageSquareWarning,
    RefreshCw,
    Search,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    ProgressValue,
    StageIcon,
    StageLegend,
    priorityTone,
} from '@/components/assistant/kit';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    felTone,
    fiscalStatusTone,
    obligationTone,
    stageBadgeTone,
    useTaxHref,
} from '@/components/tax/kit';
import type {
    FelDocument,
    FiscalCompany,
    FiscalDocument,
    Obligation,
    TaxContext,
    TaxPermissions,
} from '@/components/tax/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    module_label: string;
    due_at: string | null;
    overdue_days: number;
    priority: string;
    status: string;
    status_label: string;
    continue_to: string;
};
type CalendarEvent = {
    date: string;
    type: string;
    title: string;
    subtitle: string;
    priority: string;
    destination: string | null;
};
type Panel = {
    metrics: Record<string, number>;
    status_counts: Record<string, number>;
    companies: FiscalCompany[];
    tasks: Task[];
    obligations: Obligation[];
    fel: FelDocument[];
    documents: FiscalDocument[];
    calendar: CalendarEvent[];
};

const tabs: [string, string, (company: FiscalCompany) => boolean][] = [
    ['all', 'Todas', () => true],
    [
        'in_progress',
        'En proceso',
        (c) => ['in_progress', 'not_started'].includes(c.status),
    ],
    [
        'with_pending',
        'Con pendientes',
        (c) => c.status === 'with_pending_items',
    ],
    ['overdue', 'Atrasadas', (c) => c.overdue],
    ['completed', 'Completas', (c) => c.status === 'completed'],
];

const eventTone: Record<string, string> = {
    statutory: 'bg-red-600',
    internal: 'bg-amber-500',
    review: 'bg-violet-500',
    task: 'bg-blue-500',
};

function Metric({
    label,
    value,
    hint,
    icon: Icon,
    tone,
    href,
}: {
    label: string;
    value: number;
    hint?: string;
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
                className={`grid size-10 shrink-0 place-items-center rounded-full ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <span className="min-w-0">
                <span className="block text-[11px] leading-tight text-[#526b92]">
                    {label}
                </span>
                <span className="block text-2xl font-black text-[#061633]">
                    {value}
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

export default function TaxDashboard({
    context,
    panel,
    permissions,
    notice,
}: {
    context: TaxContext;
    panel: Panel;
    permissions: TaxPermissions;
    notice: string | null;
}) {
    const href = useTaxHref();
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
            <Head title="Panel Encargado Fiscal" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Encargado Fiscal"
                    subtitle={`Control, preparación y cumplimiento de las obligaciones fiscales de mis empresas · ${context.period?.name ?? 'Sin período'}`}
                    actions={
                        permissions.canGenerate && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    router.post(
                                        '/tax/sync',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw className="size-4" />
                                Actualizar obligaciones
                            </button>
                        )
                    }
                />
                <LuzmaInsightsCard module="tax" />

                <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-8">
                    <Metric
                        label="Empresas asignadas"
                        value={panel.metrics.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/tax/companies')}
                    />
                    <Metric
                        label="Obligaciones pendientes"
                        value={panel.metrics.pending_obligations}
                        icon={FileWarning}
                        tone="bg-red-50 text-red-700"
                        href={href('/tax/obligations')}
                    />
                    <Metric
                        label="Próximas a vencer"
                        value={panel.metrics.upcoming_obligations}
                        hint="En los próximos 15 días"
                        icon={Clock3}
                        tone="bg-amber-50 text-amber-700"
                        href={href('/tax/calendar')}
                    />
                    <Metric
                        label="Libros IVA pendientes"
                        value={panel.metrics.pending_vat_books}
                        icon={BookOpenCheck}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/tax/vat-books')}
                    />
                    <Metric
                        label="Incidencias FEL"
                        value={panel.metrics.fel_incidents}
                        icon={AlertTriangle}
                        tone="bg-red-50 text-red-700"
                        href={href('/tax/fel')}
                    />
                    <Metric
                        label="Documentos fiscales faltantes"
                        value={panel.metrics.missing_documents}
                        icon={FileWarning}
                        tone="bg-amber-50 text-amber-700"
                        href={href('/tax/documents')}
                    />
                    <Metric
                        label="Observaciones"
                        value={panel.metrics.open_observations}
                        hint="Pendientes de mi atención"
                        icon={MessageSquareWarning}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/tax/observations')}
                    />
                    <Metric
                        label="Obligaciones vencidas"
                        value={panel.metrics.overdue_obligations}
                        icon={Flag}
                        tone="bg-red-50 text-[#640000]"
                        href={href('/tax/obligations?overdue=1')}
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,2.3fr)_minmax(0,1fr)]">
                    <Card
                        title="Estado fiscal de mis empresas"
                        action={
                            <Link
                                href={href('/tax/companies')}
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
                                'Período',
                                'Obligaciones',
                                'Libros IVA',
                                'FEL',
                                'Documentos',
                                'Incidencias',
                                'Estado',
                                'Vencimiento',
                            ]}
                            empty={
                                companies.length === 0 &&
                                'No tienes empresas asignadas para gestión fiscal.'
                            }
                        >
                            {companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(company.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {company.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.tax_id}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.period}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {company.obligations_completed} /{' '}
                                        {company.obligations_total}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.vat_book ? (
                                            <span
                                                className={toneBadge(
                                                    stageBadgeTone[
                                                        company.vat_book.status
                                                    ] ?? 'slate',
                                                )}
                                                title={company.vat_book.detail}
                                            >
                                                {company.vat_book.status ===
                                                'COMPLETE'
                                                    ? 'Completo'
                                                    : company.vat_book
                                                            .status ===
                                                        'NOT_APPLICABLE'
                                                      ? 'No aplica'
                                                      : company.vat_book
                                                              .status ===
                                                          'ERROR'
                                                        ? 'No disponible'
                                                        : 'Pendiente'}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {company.fel ? (
                                            <StageIcon stage={company.fel} />
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.documents ? (
                                            <span
                                                className={toneBadge(
                                                    stageBadgeTone[
                                                        company.documents.status
                                                    ] ?? 'slate',
                                                )}
                                                title={company.documents.detail}
                                            >
                                                {company.documents.status ===
                                                'COMPLETE'
                                                    ? 'Completo'
                                                    : company.documents.detail}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${company.incidents > 0 ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {company.incidents}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.error ? (
                                            <span className="text-xs text-violet-700">
                                                {company.error}
                                            </span>
                                        ) : (
                                            <span
                                                className={toneBadge(
                                                    company.overdue
                                                        ? 'red'
                                                        : (fiscalStatusTone[
                                                              company.status
                                                          ] ?? 'slate'),
                                                )}
                                            >
                                                {company.overdue
                                                    ? 'Atrasada'
                                                    : company.status_label}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(company.due_on)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <Card
                        title="Calendario fiscal"
                        action={
                            <Link
                                href={href('/tax/calendar')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver calendario
                            </Link>
                        }
                    >
                        <ul className="space-y-2 text-sm">
                            {panel.calendar.length === 0 && (
                                <li className="py-4 text-center text-slate-500">
                                    Sin vencimientos en los próximos 15 días.
                                </li>
                            )}
                            {panel.calendar.map((event, index) => {
                                const date = new Date(
                                    `${event.date.slice(0, 10)}T12:00:00`,
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
                                                    <Link
                                                        href={href(
                                                            event.destination,
                                                        )}
                                                        className="hover:text-[#640000]"
                                                    >
                                                        {event.title}
                                                    </Link>
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

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Mis tareas fiscales"
                        action={
                            <Link
                                href={href('/tax/tasks')}
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
                                'Vencimiento',
                                'Prioridad',
                                'Estado',
                                '',
                            ]}
                            empty={
                                panel.tasks.length === 0 &&
                                'No tienes tareas fiscales pendientes.'
                            }
                        >
                            {panel.tasks.map((task) => {
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
                                        <td
                                            className={`px-2 py-2 ${task.overdue_days > 0 ? 'font-bold text-red-700' : ''}`}
                                        >
                                            {formatDate(task.due_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span className={toneBadge(tone)}>
                                                {priority}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            {task.status_label}
                                        </td>
                                        <td className="px-2 py-2">
                                            <a
                                                href={task.continue_to}
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                            >
                                                Continuar
                                            </a>
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                    <Card
                        title="Incidencias FEL"
                        action={
                            <Link
                                href={href('/tax/fel')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Documento',
                                'Empresa',
                                'Tipo',
                                'Estado',
                                'Fecha',
                                '',
                            ]}
                            empty={
                                panel.fel.length === 0 &&
                                'No existen incidencias FEL que requieran atención.'
                            }
                        >
                            {panel.fel.map((document) => (
                                <tr key={document.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {document.document}
                                    </td>
                                    <td className="px-2 py-2">
                                        {document.company}
                                    </td>
                                    <td className="px-2 py-2">
                                        {document.type}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                felTone[document.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {document.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(document.issued_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <a
                                            href={document.destination}
                                            className={secondaryButton}
                                        >
                                            Ver
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-3">
                    <Card
                        title="Obligaciones fiscales pendientes"
                        action={
                            <Link
                                href={href('/tax/obligations')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Obligación',
                                'Empresa',
                                'Período',
                                'Vence',
                                'Estado',
                            ]}
                            empty={
                                panel.obligations.length === 0 &&
                                'No existen obligaciones pendientes.'
                            }
                        >
                            {panel.obligations.map((obligation) => (
                                <tr key={obligation.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(obligation.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {obligation.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {obligation.company_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {obligation.reference_period}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${obligation.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(obligation.due_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                obligation.overdue
                                                    ? 'red'
                                                    : (obligationTone[
                                                          obligation.status
                                                      ] ?? 'slate'),
                                            )}
                                        >
                                            {obligation.overdue
                                                ? 'Vencida'
                                                : obligation.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Documentos fiscales pendientes"
                        action={
                            <Link
                                href={href('/tax/documents')}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Ver todas
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Tipo',
                                'Descripción',
                                'Estado',
                            ]}
                            empty={
                                panel.documents.length === 0 &&
                                'No existen documentos fiscales pendientes.'
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
                                        className="max-w-40 truncate px-2 py-2"
                                        title={document.description}
                                    >
                                        {document.description}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge('amber')}>
                                            {document.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Avance fiscal por empresa"
                        action={<StageLegend />}
                    >
                        <Table
                            headers={[
                                'Empresa',
                                ...(panel.companies[0]?.stages.map(
                                    (stage) => stage.label,
                                ) ?? []),
                                'Avance',
                            ]}
                            empty={
                                panel.companies.length === 0 && 'Sin empresas.'
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
                                            className="px-1 py-2 text-center"
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
                    <CalendarClock className="size-3.5" /> Las fechas salen de
                    la configuración fiscal de cada empresa y de los plazos
                    internos de la oficina.
                </p>
            </Shell>
        </>
    );
}
