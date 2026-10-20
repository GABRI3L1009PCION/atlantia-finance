import { Link } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Landmark,
    Scale,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import type { PublishedReport } from '@/components/client-portal/kit';
import { money } from '@/components/supervisor/kit';

type OpenDocuments = {
    total_cents: number;
    overdue_cents: number;
    count: number;
    overdue_count: number;
    rows: {
        document: string;
        party: string;
        due_date: string;
        outstanding_cents: number;
        overdue: boolean;
        overdue_days: number;
        status_label: string;
    }[];
};

type Finance = {
    as_of: string;
    kpis: {
        sales_month_cents: number;
        sales_year_cents: number;
        purchases_month_cents: number;
        purchases_year_cents: number;
        receivable_cents: number;
        receivable_overdue_cents: number;
        payable_cents: number;
        payable_overdue_cents: number;
    };
    series: {
        label: string;
        month: string;
        sales_cents: number;
        purchases_cents: number;
    }[];
    receivables: OpenDocuments;
    payables: OpenDocuments;
    bank: {
        total_cents: number;
        accounts: {
            name: string;
            bank: string;
            last_four: string;
            currency: string;
            balance_cents: number;
        }[];
    };
    result: {
        income_cents: number;
        expenses_cents: number;
        profit_cents: number;
        assets_cents: number;
        liabilities_cents: number;
        equity_cents: number;
        from: string;
        to: string;
    };
};

function Kpi({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'text-[#640000]',
}: {
    label: string;
    value: string;
    hint?: string;
    icon: LucideIcon;
    tone?: string;
}) {
    return (
        <div className="rounded-xl border border-[#ebe5e1] bg-white p-3 shadow-sm">
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-bold tracking-wide text-[#526b92] uppercase">
                    {label}
                </p>
                <Icon className={`size-5 ${tone}`} />
            </div>
            <p className="mt-2 text-2xl font-black text-[#061633] tabular-nums">
                {value}
            </p>
            {hint && <p className="mt-0.5 text-xs text-[#526b92]">{hint}</p>}
        </div>
    );
}

function MonthlyBars({ series }: { series: Finance['series'] }) {
    const max = Math.max(
        1,
        ...series.flatMap((row) => [row.sales_cents, row.purchases_cents]),
    );

    return (
        <div>
            <div
                className="flex h-44 items-end gap-1.5"
                role="img"
                aria-label="Ventas y compras de los últimos 12 meses"
            >
                {series.map((row) => (
                    <div
                        key={row.month}
                        className="flex h-full min-w-0 flex-1 flex-col justify-end"
                    >
                        <div className="flex h-full items-end justify-center gap-0.5">
                            <div
                                className="w-1/2 rounded-t bg-[#640000]"
                                style={{
                                    height: `${(row.sales_cents / max) * 100}%`,
                                }}
                                title={`Ventas ${row.label}: ${money(row.sales_cents)}`}
                            />
                            <div
                                className="w-1/2 rounded-t bg-slate-400"
                                style={{
                                    height: `${(row.purchases_cents / max) * 100}%`,
                                }}
                                title={`Compras ${row.label}: ${money(row.purchases_cents)}`}
                            />
                        </div>
                    </div>
                ))}
            </div>
            <div className="mt-1 flex gap-1.5 text-center text-[10px] text-[#526b92]">
                {series.map((row) => (
                    <span key={row.month} className="min-w-0 flex-1">
                        {row.label}
                    </span>
                ))}
            </div>
            <div className="mt-3 flex gap-4 text-xs font-semibold text-[#395277]">
                <span className="inline-flex items-center gap-1.5">
                    <i className="size-2.5 rounded-sm bg-[#640000]" /> Ventas
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <i className="size-2.5 rounded-sm bg-slate-400" /> Compras
                </span>
            </div>
        </div>
    );
}

function OpenList({
    title,
    data,
    partyLabel,
    emptyText,
}: {
    title: string;
    data: OpenDocuments;
    partyLabel: string;
    emptyText: string;
}) {
    return (
        <Card
            title={title}
            action={
                <span className="text-xs font-bold text-[#640000]">
                    {money(data.total_cents)}
                </span>
            }
        >
            <Table
                headers={[partyLabel, 'Documento', 'Vence', 'Saldo']}
                empty={data.rows.length === 0 && emptyText}
            >
                {data.rows.map((row, index) => (
                    <tr key={`${row.document}-${index}`}>
                        <td className="px-2 py-2 font-bold">{row.party}</td>
                        <td className="px-2 py-2">{row.document}</td>
                        <td className="px-2 py-2 whitespace-nowrap">
                            {formatDate(row.due_date)}
                            {row.overdue && (
                                <span className={`ml-2 ${toneBadge('red')}`}>
                                    {row.overdue_days} d vencida
                                </span>
                            )}
                        </td>
                        <td className="px-2 py-2 text-right font-black tabular-nums">
                            {money(row.outstanding_cents)}
                        </td>
                    </tr>
                ))}
            </Table>
            {data.count > data.rows.length && (
                <p className="mt-2 text-xs text-[#526b92]">
                    Mostrando {data.rows.length} de {data.count} documentos
                    abiertos.
                </p>
            )}
        </Card>
    );
}

export default function ClientFinances({
    finance,
    statements,
}: {
    finance: Finance;
    statements: PublishedReport[];
}) {
    const { kpis, result, bank } = finance;

    return (
        <ClientPage
            title="Resumen financiero"
            subtitle={`Cifras de tu empresa al ${formatDate(finance.as_of)}. Son preliminares hasta que tu oficina cierre el período.`}
        >
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi
                    label="Ventas del mes"
                    value={money(kpis.sales_month_cents)}
                    hint={`Del año: ${money(kpis.sales_year_cents)}`}
                    icon={ArrowUpFromLine}
                />
                <Kpi
                    label="Compras del mes"
                    value={money(kpis.purchases_month_cents)}
                    hint={`Del año: ${money(kpis.purchases_year_cents)}`}
                    icon={ArrowDownToLine}
                    tone="text-slate-600"
                />
                <Kpi
                    label="Por cobrar"
                    value={money(kpis.receivable_cents)}
                    hint={
                        kpis.receivable_overdue_cents > 0
                            ? `Vencido: ${money(kpis.receivable_overdue_cents)}`
                            : 'Sin saldos vencidos'
                    }
                    icon={Wallet}
                    tone={
                        kpis.receivable_overdue_cents > 0
                            ? 'text-red-600'
                            : 'text-emerald-600'
                    }
                />
                <Kpi
                    label="Por pagar"
                    value={money(kpis.payable_cents)}
                    hint={
                        kpis.payable_overdue_cents > 0
                            ? `Vencido: ${money(kpis.payable_overdue_cents)}`
                            : 'Sin saldos vencidos'
                    }
                    icon={Scale}
                    tone={
                        kpis.payable_overdue_cents > 0
                            ? 'text-red-600'
                            : 'text-emerald-600'
                    }
                />
            </section>

            <section className="grid gap-3 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <Card title="Ventas y compras, últimos 12 meses">
                    <MonthlyBars series={finance.series} />
                </Card>
                <Card
                    title={`Resultado del año (${formatDate(result.from)} al ${formatDate(result.to)})`}
                >
                    <dl className="space-y-2 text-sm">
                        {[
                            ['Ingresos', result.income_cents],
                            ['Gastos', result.expenses_cents],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="flex items-center justify-between"
                            >
                                <dt className="text-[#395277]">{label}</dt>
                                <dd className="font-bold tabular-nums">
                                    {money(Number(value))}
                                </dd>
                            </div>
                        ))}
                        <div className="flex items-center justify-between border-t border-slate-200 pt-2">
                            <dt className="inline-flex items-center gap-1.5 font-black text-[#061633]">
                                <TrendingUp className="size-4 text-[#640000]" />
                                Utilidad
                            </dt>
                            <dd
                                className={`text-lg font-black tabular-nums ${result.profit_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                            >
                                {money(result.profit_cents)}
                            </dd>
                        </div>
                    </dl>
                    <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        {[
                            ['Activos', result.assets_cents],
                            ['Pasivos', result.liabilities_cents],
                            ['Patrimonio', result.equity_cents],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-lg bg-[#f6f1ee] p-2"
                            >
                                <p className="text-[#526b92]">{label}</p>
                                <p className="mt-0.5 font-black text-[#061633] tabular-nums">
                                    {money(Number(value))}
                                </p>
                            </div>
                        ))}
                    </div>
                </Card>
            </section>

            <section className="grid gap-3 xl:grid-cols-2">
                <OpenList
                    title="Clientes que te deben"
                    data={finance.receivables}
                    partyLabel="Cliente"
                    emptyText="No tienes cobros pendientes."
                />
                <OpenList
                    title="Proveedores por pagar"
                    data={finance.payables}
                    partyLabel="Proveedor"
                    emptyText="No tienes pagos pendientes."
                />
            </section>

            <Card
                title="Bancos"
                action={
                    <span className="inline-flex items-center gap-1.5 text-xs font-bold text-[#640000]">
                        <Landmark className="size-4" />
                        {money(bank.total_cents)}
                    </span>
                }
            >
                <Table
                    headers={[
                        'Cuenta',
                        'Banco',
                        'Terminación',
                        'Saldo contable',
                    ]}
                    empty={
                        bank.accounts.length === 0 &&
                        'Aún no hay cuentas bancarias registradas.'
                    }
                >
                    {bank.accounts.map((account) => (
                        <tr key={`${account.bank}-${account.last_four}`}>
                            <td className="px-2 py-2 font-bold">
                                {account.name}
                            </td>
                            <td className="px-2 py-2">{account.bank}</td>
                            <td className="px-2 py-2">
                                •••• {account.last_four}
                            </td>
                            <td className="px-2 py-2 text-right font-black tabular-nums">
                                {account.currency === 'GTQ'
                                    ? money(account.balance_cents)
                                    : `${account.currency} ${(account.balance_cents / 100).toFixed(2)}`}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            {statements.length > 0 && (
                <Card
                    title="Estados financieros publicados"
                    action={
                        <Link
                            href="/client/reports"
                            className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                        >
                            Ver todos los reportes
                        </Link>
                    }
                >
                    <Table
                        headers={[
                            'Estado',
                            'Período',
                            'Versión',
                            'Publicado',
                            '',
                        ]}
                    >
                        {statements.slice(0, 8).map((report) => (
                            <tr key={report.id}>
                                <td className="px-2 py-2 font-bold">
                                    {report.title}
                                </td>
                                <td className="px-2 py-2">{report.period}</td>
                                <td className="px-2 py-2">v{report.version}</td>
                                <td className="px-2 py-2">
                                    {formatDate(report.published_at)}
                                </td>
                                <td className="px-2 py-2 text-right">
                                    {report.downloadable && (
                                        <a
                                            href={report.destination}
                                            className="font-bold text-[#640000] hover:underline"
                                        >
                                            Descargar
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </ClientPage>
    );
}
