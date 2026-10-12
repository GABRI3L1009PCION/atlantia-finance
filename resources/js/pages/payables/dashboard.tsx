import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Building2,
    ClipboardCheck,
    FileText,
    Link2,
    Send,
    Truck,
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
    amount,
    companyTone,
    dueLabel,
    proposalTone,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    Advance,
    Payable,
    PayablesCompany,
    PayablesContext,
    PaymentRow,
    Proposal,
} from '@/components/payables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Panel = {
    metrics: Record<string, number | null>;
    companies: PayablesCompany[];
    selected_company: PayablesCompany | null;
    due_months: {
        month: string;
        label: string;
        amount_cents: number;
        overdue: boolean;
    }[];
    upcoming: Payable[];
    proposals: Proposal[];
    payments: PaymentRow[];
    advances: Advance[];
    tasks: {
        id: number;
        title: string;
        company_name: string | null;
        due_at: string | null;
        overdue_days: number;
        continue_to: string;
    }[];
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

/** Outstanding by due month of the selected company (overdue in red). */
function DueMonths({ months }: { months: Panel['due_months'] }) {
    const max = Math.max(1, ...months.map((month) => month.amount_cents));

    return (
        <div className="grid gap-1.5">
            {months.map((month) => (
                <div
                    key={month.month}
                    className="grid grid-cols-[4.5rem_minmax(0,1fr)_6.5rem] items-center gap-2 text-xs"
                >
                    <span
                        className={
                            month.overdue
                                ? 'font-bold text-red-700'
                                : 'text-[#526b92]'
                        }
                    >
                        {month.label}
                    </span>
                    <span className="h-4 overflow-hidden rounded bg-slate-100">
                        <span
                            className="block h-full rounded"
                            style={{
                                width: `${(month.amount_cents / max) * 100}%`,
                                backgroundColor: month.overdue
                                    ? '#ef3b3b'
                                    : '#1f4e9c',
                            }}
                        />
                    </span>
                    <span className="text-right font-semibold">
                        {amount(month.amount_cents)}
                    </span>
                </div>
            ))}
        </div>
    );
}

export default function PayablesDashboard({
    context,
    panel,
    notice,
}: {
    context: PayablesContext;
    panel: Panel;
    notice: string | null;
}) {
    const href = usePayablesHref();
    const selected = panel.selected_company;
    const total = selected?.total_cents ?? null;
    const overduePct =
        total && selected?.overdue_cents !== null && total > 0
            ? Math.round(((selected?.overdue_cents ?? 0) / total) * 1000) / 10
            : null;

    return (
        <>
            <Head title="Panel Cuentas por Pagar" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Cuentas por Pagar"
                    subtitle={`Obligaciones con proveedores de mis empresas · ${context.period?.name ?? 'Sin período'}`}
                />
                <LuzmaInsightsCard module="payables" />

                <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-8">
                    <Metric
                        label="Empresas asignadas"
                        value={panel.metrics.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/payables/companies')}
                    />
                    <Metric
                        label="Documentos por pagar"
                        value={panel.metrics.open_documents}
                        icon={FileText}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/payables/documents')}
                    />
                    <Metric
                        label="Documentos vencidos"
                        value={panel.metrics.overdue_documents}
                        icon={AlertTriangle}
                        tone="bg-red-50 text-red-700"
                        href={href('/payables/overdue')}
                    />
                    <Metric
                        label="Proveedores con saldo"
                        value={panel.metrics.suppliers_with_balance}
                        icon={Truck}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/payables/suppliers')}
                    />
                    <Metric
                        label="Pagos por preparar"
                        value={panel.metrics.payments_to_prepare}
                        icon={Send}
                        tone="bg-red-50 text-[#640000]"
                        href={href('/payables/proposals')}
                    />
                    <Metric
                        label="Pagos por aprobar"
                        value={panel.metrics.payments_waiting_approval}
                        icon={ClipboardCheck}
                        tone="bg-amber-50 text-amber-700"
                        href={href(
                            '/payables/proposals?status=pending_approval',
                        )}
                    />
                    <Metric
                        label="Anticipos sin aplicar"
                        value={panel.metrics.unapplied_advances}
                        icon={Link2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/payables/advances')}
                    />
                    <Metric
                        label="Incidencias"
                        value={panel.metrics.incidents}
                        icon={AlertCircle}
                        tone="bg-red-50 text-red-700"
                        href={href('/payables/incidents')}
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <Card
                        title="Obligaciones por empresa"
                        action={
                            <ViewAll
                                href={href('/payables/companies')}
                                label="Ver detalle"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Proveedores',
                                'Documentos',
                                'Por vencer',
                                'Vencido',
                                'Por aprobar',
                                'Estado',
                            ]}
                            empty={
                                panel.companies.length === 0 &&
                                'No tienes empresas asignadas para cuentas por pagar.'
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
                                        {company.suppliers_with_balance ??
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
                                        {company.proposals_waiting_approval}
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
                        title="Resumen de obligaciones (empresa seleccionada)"
                        action={
                            panel.companies.length > 0 && (
                                <select
                                    aria-label="Empresa"
                                    className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                    value={selected?.id ?? ''}
                                    onChange={(event) =>
                                        router.get(
                                            href('/payables/dashboard'),
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
                                No existen cuentas por pagar abiertas.
                            </p>
                        ) : (
                            <div className="grid gap-4 py-2">
                                <div className="grid grid-cols-3 gap-2 text-center">
                                    <div>
                                        <p className="text-xl font-black text-[#061633] 2xl:text-2xl">
                                            {amount(selected.total_cents)}
                                        </p>
                                        <p className="text-xs text-[#526b92]">
                                            Total por pagar
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xl font-black text-red-600 2xl:text-2xl">
                                            {amount(selected.overdue_cents)}
                                        </p>
                                        <p className="text-xs text-red-600">
                                            Vencido ({overduePct ?? 0}%)
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
                                <div>
                                    <p className="mb-2 text-xs font-bold text-[#061633]">
                                        Obligaciones por mes de vencimiento
                                    </p>
                                    <DueMonths months={panel.due_months} />
                                </div>
                                {selected.reconciliation && (
                                    <p className="text-xs text-[#526b92]">
                                        CxP vs Mayor:{' '}
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
                        action={<ViewAll href={href('/payables/due-dates')} />}
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Proveedor',
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
                                            {document.supplier}
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
                                            {document.in_proposal && (
                                                <span
                                                    className={`ml-1 ${toneBadge('blue')}`}
                                                >
                                                    En propuesta
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                    <Card
                        title="Propuestas de pago"
                        action={
                            <ViewAll
                                href={href('/payables/proposals')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Referencia',
                                'Empresa',
                                'Fecha',
                                'Total',
                                'Estado',
                            ]}
                            empty={
                                panel.proposals.length === 0 &&
                                'Aún no hay propuestas de pago.'
                            }
                        >
                            {panel.proposals.map((proposal) => (
                                <tr key={proposal.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(proposal.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {proposal.reference}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {proposal.company}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(proposal.proposed_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(proposal.total_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                proposalTone[proposal.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {proposal.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-3">
                    <Card
                        title="Últimos pagos"
                        action={<ViewAll href={href('/payables/payments')} />}
                    >
                        <Table
                            headers={['Fecha', 'Proveedor', 'Monto', 'Estado']}
                            empty={
                                panel.payments.length === 0 &&
                                'Aún no hay pagos a proveedores.'
                            }
                        >
                            {panel.payments.map((payment) => (
                                <tr key={payment.key}>
                                    <td className="px-2 py-2">
                                        {formatDate(payment.date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {payment.supplier}
                                        <p className="text-[11px] text-[#526b92]">
                                            {payment.kind_label}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(payment.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {payment.status_label}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Anticipos y saldos a favor"
                        action={<ViewAll href={href('/payables/advances')} />}
                    >
                        <Table
                            headers={['Proveedor', 'Origen', 'Saldo']}
                            empty={
                                panel.advances.length === 0 &&
                                'No existen anticipos pendientes de aplicar.'
                            }
                        >
                            {panel.advances.map((advance) => (
                                <tr key={advance.id}>
                                    <td className="px-2 py-2">
                                        {advance.supplier}
                                        <p className="text-[11px] text-[#526b92]">
                                            {advance.company}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {advance.origin_label}
                                    </td>
                                    <td className="px-2 py-2 font-semibold text-amber-700">
                                        {amount(advance.outstanding_cents)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Mis tareas CxP"
                        action={<ViewAll href={href('/payables/tasks')} />}
                    >
                        <Table
                            headers={['Tarea', 'Vence']}
                            empty={
                                panel.tasks.length === 0 &&
                                'No tienes tareas pendientes.'
                            }
                        >
                            {panel.tasks.map((task) => (
                                <tr key={task.id}>
                                    <td className="px-2 py-2">
                                        <a
                                            href={task.continue_to}
                                            className="font-semibold hover:text-[#640000]"
                                        >
                                            {task.title}
                                        </a>
                                        <p className="text-[11px] text-[#526b92]">
                                            {task.company_name}
                                        </p>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${task.overdue_days > 0 ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(task.due_at)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
                <p className="text-[11px] text-[#526b92]">
                    Los montos se presentan por empresa; Atlantia no suma
                    obligaciones de empresas distintas. Una propuesta de pago no
                    reduce saldos: solo un pago ejecutado, una nota de crédito,
                    un anticipo aplicado o un ajuste aprobado lo hacen.
                </p>
            </Shell>
        </>
    );
}
