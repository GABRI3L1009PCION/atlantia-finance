import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    FileSearch,
    HandCoins,
    Landmark,
    Scale,
    Search,
    Send,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { priorityTone } from '@/components/assistant/kit';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    companyTone,
    movementTone,
    paymentTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    BankAccount,
    CashFlowRow,
    Movement,
    Payment,
    ScheduleRow,
    TreasuryCompany,
    TreasuryContext,
} from '@/components/treasury/kit';

type Task = {
    id: number;
    title: string;
    company_name: string | null;
    due_at: string | null;
    overdue_days: number;
    priority: string;
    status_label: string;
    continue_to: string;
};
type Panel = {
    metrics: Record<string, number>;
    status_counts: Record<string, number>;
    companies: TreasuryCompany[];
    accounts: BankAccount[];
    movements: Movement[];
    payments: Payment[];
    schedule: ScheduleRow[];
    tasks: Task[];
    cash_flow: CashFlowRow[];
};

const tabs: [string, string, (company: TreasuryCompany) => boolean][] = [
    ['all', 'Todas', () => true],
    ['reconciled', 'Conciliadas', (c) => c.status === 'reconciled'],
    ['in_progress', 'En proceso', (c) => c.status === 'in_progress'],
    [
        'with_differences',
        'Con diferencias',
        (c) => c.status === 'with_differences',
    ],
    ['pending', 'Pendientes', (c) => ['pending', 'error'].includes(c.status)],
];

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

function ViewAll({ href }: { href: string }) {
    return (
        <Link
            href={href}
            className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
        >
            Ver todos
        </Link>
    );
}

export default function TreasuryDashboard({
    context,
    panel,
    notice,
}: {
    context: TreasuryContext;
    panel: Panel;
    notice: string | null;
}) {
    const href = useTreasuryHref();
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
            <Head title="Panel Tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel Tesorería"
                    subtitle={`Bancos, conciliaciones, cobros, pagos y flujo de caja de mis empresas · ${context.period?.name ?? 'Sin período'}`}
                />
                <LuzmaInsightsCard module="treasury" />

                <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-8">
                    <Metric
                        label="Empresas asignadas"
                        value={panel.metrics.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/treasury/companies')}
                    />
                    <Metric
                        label="Cuentas bancarias"
                        value={panel.metrics.bank_accounts}
                        icon={Landmark}
                        tone="bg-slate-100 text-[#061633]"
                        href={href('/treasury/accounts')}
                    />
                    <Metric
                        label="Movimientos pendientes"
                        value={panel.metrics.pending_movements}
                        icon={FileSearch}
                        tone="bg-amber-50 text-amber-700"
                        href={href('/treasury/movements')}
                    />
                    <Metric
                        label="Sin identificar"
                        value={panel.metrics.unidentified_movements}
                        icon={AlertTriangle}
                        tone="bg-red-50 text-red-700"
                        href={href('/treasury/movements?status=unmatched')}
                    />
                    <Metric
                        label="Conciliaciones pendientes"
                        value={panel.metrics.pending_reconciliations}
                        hint="Al corte del período"
                        icon={Scale}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/treasury/reconciliations?pending=1')}
                    />
                    <Metric
                        label="Pagos por aprobar"
                        value={panel.metrics.payments_to_approve}
                        icon={CheckCircle2}
                        tone="bg-amber-50 text-amber-700"
                        href={href(
                            '/treasury/payments?status=pending_approval',
                        )}
                    />
                    <Metric
                        label="Pagos por ejecutar"
                        value={panel.metrics.payments_to_execute}
                        icon={Send}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/treasury/payments?status=open')}
                    />
                    <Metric
                        label="Cobros sin aplicar"
                        value={panel.metrics.unapplied_receipts}
                        icon={HandCoins}
                        tone="bg-red-50 text-[#640000]"
                        href={href('/treasury/receipts?status=unapplied')}
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,2.3fr)_minmax(0,1fr)]">
                    <Card
                        title="Estado de tesorería por empresa"
                        action={<ViewAll href={href('/treasury/companies')} />}
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
                                'Cuentas',
                                'Saldo bancario',
                                'Saldo contable',
                                'Pendientes',
                                'Pagos por aprobar',
                                'Incidencias',
                                'Estado',
                            ]}
                            empty={
                                companies.length === 0 &&
                                'No tienes empresas asignadas para tesorería.'
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
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            NIT {company.tax_id}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.accounts}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {company.accounts === 0
                                            ? '—'
                                            : amount(
                                                  company.bank_balance_cents,
                                              )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.accounts === 0
                                            ? '—'
                                            : amount(
                                                  company.ledger_balance_cents,
                                              )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.pending_movements ??
                                            'No disponible'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.payments_to_approve}
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
                                                    companyTone[
                                                        company.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {company.status_label}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Los saldos se muestran por empresa; Atlantia no suma
                            dinero de empresas distintas. El saldo contable
                            considera solo asientos contabilizados.
                        </p>
                    </Card>

                    <Card
                        title="Próximos movimientos"
                        action={<ViewAll href={href('/treasury/schedule')} />}
                    >
                        <ul className="space-y-2 text-sm">
                            {panel.schedule.length === 0 && (
                                <li className="py-4 text-center text-slate-500">
                                    Sin pagos ni cobros en los próximos 15 días.
                                </li>
                            )}
                            {panel.schedule.map((row, index) => (
                                <li
                                    key={`${row.kind}-${row.date}-${index}`}
                                    className="flex items-start justify-between gap-2 border-b border-slate-100 pb-2"
                                >
                                    <span className="min-w-0">
                                        <Link
                                            href={href(row.destination)}
                                            className="block truncate font-bold hover:text-[#640000]"
                                        >
                                            {row.party}
                                        </Link>
                                        <span className="text-xs text-[#526b92]">
                                            {row.kind_label} · {row.company} ·{' '}
                                            <span
                                                className={
                                                    row.overdue
                                                        ? 'font-bold text-red-700'
                                                        : ''
                                                }
                                            >
                                                {formatDate(row.date)}
                                            </span>
                                        </span>
                                    </span>
                                    <b
                                        className={`shrink-0 ${row.amount_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                    >
                                        {amount(row.amount_cents)}
                                    </b>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title="Saldos por cuenta"
                        action={<ViewAll href={href('/treasury/accounts')} />}
                    >
                        <Table
                            headers={[
                                'Cuenta',
                                'Empresa',
                                'Saldo bancario',
                                'Saldo contable',
                                'Diferencia',
                                'Última conciliación',
                            ]}
                            empty={
                                panel.accounts.length === 0 &&
                                'Sin cuentas bancarias activas.'
                            }
                        >
                            {panel.accounts.map((account) => (
                                <tr key={account.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {account.label}
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {account.type} · {account.currency}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {account.company}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(account.bank_balance_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(account.ledger_balance_cents)}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${account.difference_cents !== 0 ? 'font-bold text-red-700' : 'text-emerald-700'}`}
                                    >
                                        {amount(account.difference_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {account.last_reconciliation ? (
                                            <span
                                                className={toneBadge(
                                                    account.last_reconciliation
                                                        .status === 'approved'
                                                        ? 'green'
                                                        : 'amber',
                                                )}
                                            >
                                                {formatDate(
                                                    account.last_reconciliation
                                                        .cutoff_date,
                                                )}{' '}
                                                ·{' '}
                                                {
                                                    account.last_reconciliation
                                                        .status_label
                                                }
                                            </span>
                                        ) : (
                                            'Nunca'
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Movimientos por conciliar"
                        action={<ViewAll href={href('/treasury/movements')} />}
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Cuenta',
                                'Referencia',
                                'Monto',
                                'Estado',
                            ]}
                            empty={
                                panel.movements.length === 0 &&
                                'No hay movimientos pendientes.'
                            }
                        >
                            {panel.movements.map((movement) => (
                                <tr key={movement.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(movement.date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {movement.account}
                                        <p className="text-[11px] text-[#526b92]">
                                            {movement.company}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {movement.reference}
                                    </td>
                                    <td
                                        className={`px-2 py-2 font-semibold ${movement.flow === 'inflow' ? 'text-emerald-700' : 'text-red-700'}`}
                                    >
                                        {movement.flow === 'inflow' ? '+' : '−'}
                                        {amount(movement.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                movementTone[movement.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {movement.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-3">
                    <Card
                        title="Pagos en curso"
                        action={<ViewAll href={href('/treasury/payments')} />}
                    >
                        <Table
                            headers={['Proveedor', 'Monto', 'Estado']}
                            empty={
                                panel.payments.length === 0 &&
                                'No hay pagos abiertos.'
                            }
                        >
                            {panel.payments.map((payment) => (
                                <tr key={payment.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(payment.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {payment.supplier}
                                        </Link>
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {payment.company}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(payment.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                paymentTone[payment.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {payment.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Flujo de caja del mes"
                        action={<ViewAll href={href('/treasury/cash-flow')} />}
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Neto real',
                                'Neto proyectado',
                            ]}
                            empty={
                                panel.cash_flow.length === 0 && 'Sin empresas.'
                            }
                        >
                            {panel.cash_flow.map((row) => (
                                <tr key={row.company_id}>
                                    <td className="px-2 py-2 font-bold">
                                        {row.company}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${row.realized_net_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                    >
                                        {amount(row.realized_net_cents)}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${row.forecast_net_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                    >
                                        {amount(row.forecast_net_cents)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Mis tareas"
                        action={<ViewAll href={href('/treasury/tasks')} />}
                    >
                        <Table
                            headers={['Tarea', 'Vence', 'Prioridad', '']}
                            empty={
                                panel.tasks.length === 0 &&
                                'No tienes tareas de tesorería pendientes.'
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
                                            <p className="text-[11px] font-normal text-[#526b92]">
                                                {task.company_name}
                                            </p>
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
                                            <Link
                                                href={href(task.continue_to)}
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                            >
                                                Continuar
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                </div>
            </Shell>
        </>
    );
}
