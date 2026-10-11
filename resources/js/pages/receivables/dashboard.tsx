import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Building2,
    CalendarClock,
    FileText,
    ListChecks,
    Link2,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
} from '@/components/office-admin/kit';
import {
    activityTone,
    amount,
    companyTone,
    dueLabel,
    promiseTone,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    Activity,
    PromiseRow,
    Receivable,
    ReceivablesCompany,
    ReceivablesContext,
    SharedReceipt,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Panel = {
    metrics: Record<string, number | null>;
    companies: ReceivablesCompany[];
    selected_company: ReceivablesCompany | null;
    upcoming: Receivable[];
    activities: Activity[];
    unapplied: SharedReceipt[];
    promises: PromiseRow[];
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
            </span>
        </Link>
    );
}

function ViewAll({
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

export default function ReceivablesDashboard({
    context,
    panel,
    notice,
}: {
    context: ReceivablesContext;
    panel: Panel;
    notice: string | null;
}) {
    const href = useReceivablesHref();
    const selected = panel.selected_company;
    const total = selected?.total_cents ?? null;
    const overduePct =
        total && selected?.overdue_cents !== null && total > 0
            ? Math.round(((selected?.overdue_cents ?? 0) / total) * 1000) / 10
            : null;

    return (
        <>
            <Head title="Panel Cuentas por Cobrar" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Cuentas por Cobrar"
                    subtitle={`Control y seguimiento de la cartera de mis empresas · ${context.period?.name ?? 'Sin período'}`}
                />
                <LuzmaInsightsCard module="receivables" />

                <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-8">
                    <Metric
                        label="Empresas asignadas"
                        value={panel.metrics.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/receivables/companies')}
                    />
                    <Metric
                        label="Documentos pendientes"
                        value={panel.metrics.open_documents}
                        icon={FileText}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/receivables/documents')}
                    />
                    <Metric
                        label="Documentos vencidos"
                        value={panel.metrics.overdue_documents}
                        icon={AlertTriangle}
                        tone="bg-red-50 text-red-700"
                        href={href('/receivables/documents?status=overdue')}
                    />
                    <Metric
                        label="Clientes con saldo"
                        value={panel.metrics.customers_with_balance}
                        icon={Users}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/receivables/customers')}
                    />
                    <Metric
                        label="Promesas de pago"
                        value={panel.metrics.active_promises}
                        icon={CalendarClock}
                        tone="bg-red-50 text-[#640000]"
                        href={href('/receivables/promises')}
                    />
                    <Metric
                        label="Cobros sin aplicar"
                        value={panel.metrics.unapplied_receipts}
                        icon={Link2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/receivables/unapplied-receipts')}
                    />
                    <Metric
                        label="Gestiones vencidas"
                        value={panel.metrics.overdue_followups}
                        icon={ListChecks}
                        tone="bg-red-50 text-red-700"
                        href={href('/receivables/collections?status=overdue')}
                    />
                    <Metric
                        label="Incidencias"
                        value={panel.metrics.incidents}
                        icon={AlertCircle}
                        tone="bg-red-50 text-red-700"
                        href={href('/receivables/incidents')}
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <Card
                        title="Cartera por empresa"
                        action={
                            <ViewAll
                                href={href('/receivables/companies')}
                                label="Ver detalle"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Clientes con saldo',
                                'Documentos',
                                'Por vencer',
                                'Vencidos',
                                'Estado',
                            ]}
                            empty={
                                panel.companies.length === 0 &&
                                'No tienes empresas asignadas para cuentas por cobrar.'
                            }
                        >
                            {panel.companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(company.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {company.name}
                                        </Link>
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            NIT {company.tax_id}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.customers_with_balance ??
                                            'No disponible'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.open_documents ??
                                            'No disponible'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(company.current_cents)}
                                    </td>
                                    <td className="px-2 py-2 font-semibold text-red-700">
                                        {amount(company.overdue_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                companyTone[company.status] ??
                                                    'slate',
                                            )}
                                            title={company.error ?? undefined}
                                        >
                                            {company.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <Card
                        title="Resumen de cartera (empresa seleccionada)"
                        action={
                            panel.companies.length > 0 && (
                                <select
                                    aria-label="Empresa"
                                    className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                    value={selected?.id ?? ''}
                                    onChange={(event) =>
                                        router.get(
                                            href('/receivables/dashboard'),
                                            { company_id: event.target.value },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {panel.companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                            )
                        }
                    >
                        {selected === null ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                Sin empresas asignadas.
                            </p>
                        ) : selected.error ? (
                            <p className="py-6 text-center text-sm text-violet-700">
                                {selected.error}
                            </p>
                        ) : total === 0 ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                No existen cuentas por cobrar abiertas.
                            </p>
                        ) : (
                            <div className="grid gap-4 py-2">
                                <div className="grid grid-cols-3 gap-2 text-center">
                                    <div>
                                        <p className="text-xl font-black text-[#061633] 2xl:text-2xl">
                                            {amount(selected.total_cents)}
                                        </p>
                                        <p className="text-xs text-[#526b92]">
                                            Cartera total
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xl font-black text-red-600 2xl:text-2xl">
                                            {amount(selected.overdue_cents)}
                                        </p>
                                        <p className="text-xs text-red-600">
                                            Vencida ({overduePct ?? 0}%)
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xl font-black text-emerald-700 2xl:text-2xl">
                                            {amount(selected.current_cents)}
                                        </p>
                                        <p className="text-xs text-emerald-700">
                                            Por vencer (
                                            {overduePct === null
                                                ? 0
                                                : Math.round(
                                                      (100 - overduePct) * 10,
                                                  ) / 10}
                                            %)
                                        </p>
                                    </div>
                                </div>
                                <div className="flex h-6 overflow-hidden rounded-md text-[11px] font-bold text-white">
                                    {(overduePct ?? 0) > 0 && (
                                        <span
                                            className="grid place-items-center"
                                            style={{
                                                width: `${overduePct}%`,
                                                backgroundColor: '#ef3b3b',
                                            }}
                                        >
                                            {overduePct}%
                                        </span>
                                    )}
                                    {(overduePct ?? 0) < 100 && (
                                        <span
                                            className="grid flex-1 place-items-center"
                                            style={{
                                                backgroundColor: '#15924a',
                                            }}
                                        >
                                            {overduePct === null
                                                ? ''
                                                : `${Math.round((100 - overduePct) * 10) / 10}%`}
                                        </span>
                                    )}
                                </div>
                                {selected.reconciliation && (
                                    <p className="text-xs text-[#526b92]">
                                        CxC vs Mayor:{' '}
                                        <b
                                            className={
                                                selected.reconciliation
                                                    .status === 'matched'
                                                    ? 'text-emerald-700'
                                                    : 'text-red-700'
                                            }
                                        >
                                            {
                                                selected.reconciliation
                                                    .status_label
                                            }
                                        </b>
                                        {selected.reconciliation
                                            .difference_cents
                                            ? ` · ${amount(selected.reconciliation.difference_cents)}`
                                            : ''}
                                    </p>
                                )}
                            </div>
                        )}
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Próximos vencimientos"
                        action={
                            <ViewAll href={href('/receivables/due-dates')} />
                        }
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Cliente',
                                'Documento',
                                'Saldo',
                                'Estado',
                            ]}
                            empty={
                                panel.upcoming.length === 0 &&
                                'No existen documentos por vencer en los próximos 15 días.'
                            }
                        >
                            {panel.upcoming.map((document) => {
                                const [label, tone] = dueLabel(document);

                                return (
                                    <tr key={document.id}>
                                        <td className="px-2 py-2">
                                            {formatDate(document.due_date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {document.customer}
                                            <p className="text-[11px] text-[#526b92]">
                                                {document.company}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2 font-bold">
                                            <Link
                                                href={href(
                                                    document.destination,
                                                )}
                                                className="hover:text-[#640000]"
                                            >
                                                {document.document}
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(document.outstanding_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span className={toneBadge(tone)}>
                                                {label}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                    <Card
                        title="Últimas gestiones de cobranza"
                        action={
                            <ViewAll
                                href={href('/receivables/collections')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Cliente',
                                'Canal',
                                'Resultado',
                                'Próxima acción',
                                'Estado',
                            ]}
                            empty={
                                panel.activities.length === 0 &&
                                'Aún no hay gestiones registradas.'
                            }
                        >
                            {panel.activities.map((activity) => (
                                <tr key={activity.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(activity.performed_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={href(activity.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {activity.customer}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {activity.channel_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {activity.result_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(activity.next_action_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                activityTone[activity.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {activity.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Cobros sin aplicar"
                        action={
                            <ViewAll
                                href={href('/receivables/unapplied-receipts')}
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Cliente',
                                'Sin aplicar',
                                'Referencia',
                                'Cuenta',
                            ]}
                            empty={
                                panel.unapplied.length === 0 &&
                                'No existen cobros pendientes de aplicación.'
                            }
                        >
                            {panel.unapplied.map((receipt) => (
                                <tr key={receipt.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(receipt.received_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.customer}
                                        <p className="text-[11px] text-[#526b92]">
                                            {receipt.company}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2 font-semibold text-amber-700">
                                        {amount(receipt.unapplied_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.reference}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.account}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Promesas de pago"
                        action={
                            <ViewAll
                                href={href('/receivables/promises')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Cliente',
                                'Monto',
                                'Fecha prometida',
                                'Documentos',
                                'Estado',
                            ]}
                            empty={
                                panel.promises.length === 0 &&
                                'No existen promesas de pago activas.'
                            }
                        >
                            {panel.promises.map((promise) => (
                                <tr key={promise.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(promise.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {promise.customer}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(promise.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(promise.promised_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {promise.documents.join(', ') ||
                                            'Saldo general'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                promiseTone[promise.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {promise.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
                <p className="text-[11px] text-[#526b92]">
                    Los montos se presentan por empresa; Atlantia no suma
                    carteras de empresas distintas.
                </p>
            </Shell>
        </>
    );
}
