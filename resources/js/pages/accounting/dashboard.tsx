import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Banknote,
    Boxes,
    Building2,
    CalendarDays,
    ChartNoAxesCombined,
    CircleDollarSign,
    Clock3,
    FileChartColumn,
    FileText,
    FolderKanban,
    Landmark,
    LibraryBig,
    ReceiptText,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    Users,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { hasPlanModule } from '@/lib/plan-capabilities';
import { reports, sales } from '@/routes/accounting';

type TrendRow = {
    label: string;
    sales_cents: number;
    purchases_cents: number;
    collections_cents: number;
    payments_cents: number;
};

type Metrics = {
    sales_cents: number;
    collections_cents: number;
    purchases_cents: number;
    payments_cents: number;
    receivable_cents: number;
    payable_cents: number;
    overdue_receivable_cents: number;
    overdue_payable_cents: number;
    income_cents: number;
    expense_cents: number;
    profit_cents: number;
    drafts: number;
    trend: TrendRow[];
    receivable_aging: { label: string; amount_cents: number }[];
    recent_invoices: {
        public_id: string;
        number: number | null;
        customer: string;
        date: string;
        total_cents: number;
        status: string;
    }[];
};

type Props = {
    company: {
        public_id: string;
        name: string;
        tax_id: string;
        office_name: string;
    };
    canWrite: boolean;
    metrics: Metrics;
    health: {
        accounts: number;
        open_periods: number;
        customers: number;
        suppliers: number;
        posted_entries: number;
    };
};

const card =
    'rounded-xl border border-[#ebe5e1] bg-white shadow-[0_8px_22px_-18px_rgba(49,11,8,.5)]';
const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value / 100);
const shortDate = (value: string) =>
    new Intl.DateTimeFormat('es-GT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(`${value.slice(0, 10)}T12:00:00`));

const moduleCatalog: Record<
    string,
    { title: string; detail: string; icon: LucideIcon }
> = {
    bancos: {
        title: 'Bancos',
        detail: 'Cuentas bancarias y movimientos financieros',
        icon: Landmark,
    },
    conciliacion: {
        title: 'Conciliación Bancaria',
        detail: 'Comparación de extractos y registros contables',
        icon: SlidersHorizontal,
    },
    reglas: {
        title: 'Reglas Bancarias',
        detail: 'Clasificación controlada de movimientos recurrentes',
        icon: BadgeCheck,
    },
    caja: {
        title: 'Caja',
        detail: 'Entradas, salidas y arqueos de efectivo',
        icon: Banknote,
    },
    inventario: {
        title: 'Inventario',
        detail: 'Productos, existencias y costo de ventas',
        icon: Boxes,
    },
    kardex: {
        title: 'Kardex',
        detail: 'Movimientos y valoración por producto',
        icon: FileText,
    },
    'inventario-fisico': {
        title: 'Inventario Físico',
        detail: 'Conteos y ajustes de existencias',
        icon: Boxes,
    },
    'activos-fijos': {
        title: 'Activos Fijos',
        detail: 'Altas, depreciaciones y bajas',
        icon: Building2,
    },
    impuestos: {
        title: 'Impuestos',
        detail: 'Obligaciones y calendario fiscal',
        icon: CalendarDays,
    },
    nomina: {
        title: 'Nómina',
        detail: 'Colaboradores, períodos y contabilización',
        icon: Users,
    },
    proyectos: {
        title: 'Proyectos',
        detail: 'Costos y rentabilidad por proyecto',
        icon: FolderKanban,
    },
    presupuestos: {
        title: 'Presupuestos',
        detail: 'Planeación y comparación contra ejecución',
        icon: ChartNoAxesCombined,
    },
    normativa: {
        title: 'Biblioteca Normativa',
        detail: 'Referencias fiscales y contables verificadas',
        icon: LibraryBig,
    },
    documentos: {
        title: 'Documentos',
        detail: 'Expediente documental de la empresa',
        icon: FileText,
    },
    cotizaciones: {
        title: 'Cotizaciones',
        detail: 'Propuestas comerciales y conversión a venta',
        icon: ReceiptText,
    },
    fel: {
        title: 'FEL / SAT',
        detail: 'Emisión fiscal electrónica e integración autorizada',
        icon: BadgeCheck,
    },
    'portal-cliente': {
        title: 'Portal Cliente',
        detail: 'Consulta segura de documentos y saldos',
        icon: Users,
    },
    'notas-credito': {
        title: 'Notas de Crédito',
        detail: 'Ajustes documentados sobre ventas emitidas',
        icon: ReceiptText,
    },
    'ordenes-compra': {
        title: 'Órdenes de Compra',
        detail: 'Aprobación y seguimiento de adquisiciones',
        icon: ShoppingCart,
    },
    'libros-iva': {
        title: 'Libros IVA',
        detail: 'Libros de compras y ventas',
        icon: FileChartColumn,
    },
    'cierre-periodo': {
        title: 'Cierre de Período',
        detail: 'Validaciones y bloqueo contable',
        icon: Clock3,
    },
    'estados-cuenta': {
        title: 'Estados de Cuenta',
        detail: 'Saldos y movimientos por tercero',
        icon: CircleDollarSign,
    },
    recordatorios: {
        title: 'Recordatorios',
        detail: 'Alertas operativas y de vencimiento',
        icon: Clock3,
    },
    auditoria: {
        title: 'Auditoría',
        detail: 'Trazabilidad de operaciones sensibles',
        icon: ShieldCheck,
    },
};

function variation(current: number, previous: number): string {
    if (previous === 0) {
        return current === 0 ? 'Sin variación' : 'Nuevo movimiento';
    }

    const rate = ((current - previous) / Math.abs(previous)) * 100;

    return `${rate >= 0 ? '↑' : '↓'} ${Math.abs(rate).toFixed(1)}% vs. mes anterior`;
}

function Kpi({
    label,
    value,
    change,
    icon: Icon,
    tone,
}: {
    label: string;
    value: number;
    change: string;
    icon: LucideIcon;
    tone: string;
}) {
    return (
        <div
            className={`${card} kpi-card p-4 transition hover:-translate-y-0.5 hover:shadow-lg`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="mb-1 truncate text-xs font-medium text-slate-500">
                        {label}
                    </p>
                    <p className="truncate text-xl font-bold text-slate-800">
                        {money(value)}
                    </p>
                    <p
                        className={`mt-1 truncate text-[11px] font-medium ${value < 0 ? 'text-red-500' : 'text-emerald-600'}`}
                    >
                        {change}
                    </p>
                </div>
                <span
                    className={`grid size-10 shrink-0 place-items-center rounded-full ${tone}`}
                >
                    <Icon className="size-[18px]" />
                </span>
            </div>
        </div>
    );
}

function LineChart({ rows }: { rows: TrendRow[] }) {
    const maximum = Math.max(
        1,
        ...rows.flatMap((row) => [row.sales_cents, row.purchases_cents]),
    );
    const coordinates = (key: 'sales_cents' | 'purchases_cents') =>
        rows
            .map(
                (row, index) =>
                    `${6 + index * (88 / Math.max(1, rows.length - 1))},${82 - (row[key] / maximum) * 68}`,
            )
            .join(' ');

    return (
        <div className="relative mt-2 h-[260px]">
            <svg
                viewBox="0 0 100 100"
                className="h-[224px] w-full"
                preserveAspectRatio="none"
                aria-label="Flujo de ingresos y gastos"
            >
                {[14, 31, 48, 65, 82].map((y) => (
                    <line
                        key={y}
                        x1="5"
                        x2="95"
                        y1={y}
                        y2={y}
                        stroke="#edf0f2"
                        strokeWidth=".45"
                    />
                ))}
                <polyline
                    points={coordinates('sales_cents')}
                    fill="none"
                    stroke="#629d3e"
                    strokeWidth="2.25"
                    vectorEffect="non-scaling-stroke"
                />
                <polyline
                    points={coordinates('purchases_cents')}
                    fill="none"
                    stroke="#dc5963"
                    strokeWidth="2.25"
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            <div
                className={`grid text-center text-[10px] text-slate-400`}
                style={{
                    gridTemplateColumns: `repeat(${rows.length}, minmax(0, 1fr))`,
                }}
            >
                {rows.map((row) => (
                    <span key={row.label}>{row.label}</span>
                ))}
            </div>
        </div>
    );
}

function CashChart({ rows }: { rows: TrendRow[] }) {
    const maximum = Math.max(
        1,
        ...rows.flatMap((row) => [row.collections_cents, row.payments_cents]),
    );

    return (
        <div className="mt-5 flex h-[260px] items-end gap-2 border-b border-slate-200 px-2 pb-7">
            {rows.map((row) => (
                <div
                    key={row.label}
                    className="relative flex h-full min-w-0 flex-1 items-end justify-center gap-0.5"
                >
                    <span
                        className="w-2.5 rounded-t-sm bg-[#72a84c]"
                        style={{
                            height: `${Math.max(2, (row.collections_cents / maximum) * 88)}%`,
                        }}
                        title={`Cobros ${money(row.collections_cents)}`}
                    />
                    <span
                        className="w-2.5 rounded-t-sm bg-[#dd5c65]"
                        style={{
                            height: `${Math.max(2, (row.payments_cents / maximum) * 88)}%`,
                        }}
                        title={`Pagos ${money(row.payments_cents)}`}
                    />
                    <span className="absolute -bottom-5 text-[10px] text-slate-400">
                        {row.label}
                    </span>
                </div>
            ))}
        </div>
    );
}

function ModuleStatus({
    moduleKey,
    company,
    planName,
}: {
    moduleKey: string;
    company: Props['company'];
    planName: string;
}) {
    const module = moduleCatalog[moduleKey];

    if (!module) {
        return null;
    }

    const Icon = module.icon;

    return (
        <div className="p-4 sm:p-6">
            <Head title={`${module.title} · ${company.name}`} />
            <div className="mx-auto max-w-7xl space-y-5">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">
                            {module.title}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            {module.detail}
                        </p>
                    </div>
                    <span className="rounded-lg bg-[#f2e7e7] px-3 py-2 text-xs font-semibold text-[#722f37]">
                        Plan {planName}
                    </span>
                </header>
                <section
                    className={`${card} flex min-h-[360px] flex-col items-center justify-center p-8 text-center`}
                >
                    <span className="grid size-16 place-items-center rounded-2xl bg-gradient-to-br from-[#722f37] to-[#9b3a45] text-white shadow-lg">
                        <Icon className="size-7" />
                    </span>
                    <h2 className="mt-5 text-lg font-semibold text-slate-800">
                        Estructura preparada para {module.title}
                    </h2>
                    <p className="mt-2 max-w-xl text-sm leading-6 text-slate-500">
                        La opción forma parte de tu suscripción. Su operación se
                        conectará a tablas, permisos y pruebas multiempresa
                        antes de permitir registros reales.
                    </p>
                    <div className="mt-6 grid w-full max-w-2xl gap-3 sm:grid-cols-3">
                        {[
                            'Aislamiento por empresa',
                            'Permisos por rol',
                            'Trazabilidad de cambios',
                        ].map((item) => (
                            <div
                                key={item}
                                className="rounded-lg border border-[#ebe5e1] bg-[#faf8f7] p-3 text-xs font-medium text-slate-600"
                            >
                                {item}
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </div>
    );
}

export default function CompanyDashboard({ company, metrics }: Props) {
    const page = usePage();
    const navigation = page.props.workspaceNavigation as
        | {
              plan: { name: string; features: string[] } | null;
          }
        | null
        | undefined;
    const planFeatures = navigation?.plan?.features ?? [];
    const moduleKey = new URL(
        page.url,
        typeof window === 'undefined'
            ? 'http://localhost'
            : window.location.origin,
    ).searchParams.get('module');

    if (
        moduleKey &&
        moduleCatalog[moduleKey] &&
        hasPlanModule(planFeatures, moduleKey)
    ) {
        return (
            <ModuleStatus
                moduleKey={moduleKey}
                company={company}
                planName={navigation?.plan?.name ?? 'activo'}
            />
        );
    }

    const previous = metrics.trend.at(-2) ?? {
        sales_cents: 0,
        purchases_cents: 0,
        collections_cents: 0,
        payments_cents: 0,
    };
    const current = metrics.trend.at(-1) ?? previous;
    const cash = metrics.collections_cents - metrics.payments_cents;
    const currentNet = current.sales_cents - current.purchases_cents;
    const previousNet = previous.sales_cents - previous.purchases_cents;
    const statuses: Record<string, { label: string; className: string }> = {
        issued: {
            label: 'Emitida',
            className: 'bg-emerald-50 text-emerald-700',
        },
        draft: { label: 'Borrador', className: 'bg-indigo-50 text-indigo-700' },
        void: { label: 'Anulada', className: 'bg-red-50 text-red-700' },
    };

    return (
        <>
            <Head title={`Inicio · ${company.name}`} />
            <main className="min-h-full flex-1 bg-[#f1f5f9] p-4 text-slate-800 sm:p-6">
                <div className="mx-auto max-w-[1680px]">
                    <section
                        className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6"
                        aria-label="Indicadores principales"
                    >
                        <Kpi
                            label="Ingresos del mes"
                            value={metrics.income_cents}
                            change={variation(
                                current.sales_cents,
                                previous.sales_cents,
                            )}
                            icon={TrendingUp}
                            tone="bg-emerald-100 text-emerald-600"
                        />
                        <Kpi
                            label="Gastos del mes"
                            value={metrics.expense_cents}
                            change={variation(
                                current.purchases_cents,
                                previous.purchases_cents,
                            )}
                            icon={ReceiptText}
                            tone="bg-red-100 text-red-500"
                        />
                        <Kpi
                            label="Utilidad neta"
                            value={metrics.profit_cents}
                            change={variation(currentNet, previousNet)}
                            icon={ChartNoAxesCombined}
                            tone="bg-amber-100 text-amber-600"
                        />
                        <Kpi
                            label="Saldo de caja"
                            value={cash}
                            change={variation(
                                current.collections_cents -
                                    current.payments_cents,
                                previous.collections_cents -
                                    previous.payments_cents,
                            )}
                            icon={Landmark}
                            tone="bg-[#f1e6e7] text-[#722f37]"
                        />
                        <Kpi
                            label="CxC pendiente"
                            value={metrics.receivable_cents}
                            change={`${money(metrics.overdue_receivable_cents)} vencidos`}
                            icon={CircleDollarSign}
                            tone="bg-teal-100 text-teal-600"
                        />
                        <Kpi
                            label="CxP pendiente"
                            value={metrics.payable_cents}
                            change={`${money(metrics.overdue_payable_cents)} vencidos`}
                            icon={WalletCards}
                            tone="bg-orange-100 text-orange-600"
                        />
                    </section>

                    <section className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                        <div className={`${card} p-5`}>
                            <div className="flex items-center justify-between">
                                <h2 className="font-semibold">
                                    Flujo de ingresos vs. gastos
                                </h2>
                                <div className="flex gap-3 text-[10px] text-slate-500">
                                    <span>
                                        <i className="mr-1 inline-block size-2 rounded-full bg-[#629d3e]" />
                                        Ingresos
                                    </span>
                                    <span>
                                        <i className="mr-1 inline-block size-2 rounded-full bg-[#dc5963]" />
                                        Gastos
                                    </span>
                                </div>
                            </div>
                            <LineChart rows={metrics.trend} />
                        </div>
                        <div className={`${card} p-5`}>
                            <div className="flex items-center justify-between">
                                <h2 className="font-semibold">Flujo de caja</h2>
                                <div className="flex gap-3 text-[10px] text-slate-500">
                                    <span>
                                        <i className="mr-1 inline-block size-2 rounded-full bg-[#72a84c]" />
                                        Cobros
                                    </span>
                                    <span>
                                        <i className="mr-1 inline-block size-2 rounded-full bg-[#dd5c65]" />
                                        Pagos
                                    </span>
                                </div>
                            </div>
                            <CashChart rows={metrics.trend} />
                        </div>
                    </section>

                    <section className={`${card} overflow-hidden`}>
                        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                            <h2 className="font-semibold">
                                Facturas recientes
                            </h2>
                            <Link
                                href={sales(company.public_id)}
                                className="text-xs font-medium text-[#722f37] hover:underline"
                            >
                                Ver todas{' '}
                                <ArrowRight className="inline size-3" />
                            </Link>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-left text-sm">
                                <thead className="bg-slate-50 text-xs text-slate-500">
                                    <tr>
                                        <th className="px-5 py-3">Factura</th>
                                        <th>Cliente</th>
                                        <th>Fecha</th>
                                        <th>Monto</th>
                                        <th>Estado</th>
                                        <th className="pr-5 text-right">
                                            Acción
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {metrics.recent_invoices.map((invoice) => {
                                        const status = statuses[
                                            invoice.status
                                        ] ?? {
                                            label: invoice.status,
                                            className:
                                                'bg-slate-100 text-slate-600',
                                        };

                                        return (
                                            <tr
                                                key={invoice.public_id}
                                                className="hover:bg-slate-50"
                                            >
                                                <td className="px-5 py-3 font-semibold text-[#722f37]">
                                                    {invoice.number
                                                        ? `F-${String(invoice.number).padStart(5, '0')}`
                                                        : 'Borrador'}
                                                </td>
                                                <td>{invoice.customer}</td>
                                                <td>
                                                    {shortDate(invoice.date)}
                                                </td>
                                                <td className="font-medium">
                                                    {money(invoice.total_cents)}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.className}`}
                                                    >
                                                        {status.label}
                                                    </span>
                                                </td>
                                                <td className="pr-5 text-right">
                                                    <Link
                                                        href={sales(
                                                            company.public_id,
                                                        )}
                                                        className="text-xs text-[#722f37]"
                                                    >
                                                        Abrir
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            {metrics.recent_invoices.length === 0 && (
                                <div className="flex min-h-40 flex-col items-center justify-center p-8 text-center">
                                    <FileText className="size-8 text-slate-300" />
                                    <p className="mt-3 text-sm font-medium text-slate-600">
                                        Aún no hay facturas
                                    </p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        Las facturas reales de {company.name}{' '}
                                        aparecerán aquí.
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>

                    <footer className="mt-6 flex flex-wrap items-center justify-between gap-2 text-[10px] text-slate-400">
                        <span>
                            © {new Date().getFullYear()} Atlantia Finance
                        </span>
                        <Link
                            href={reports(company.public_id)}
                            className="inline-flex items-center gap-1 hover:text-[#722f37]"
                        >
                            <FileChartColumn className="size-3" /> Reportes
                            financieros
                        </Link>
                    </footer>
                </div>
            </main>
        </>
    );
}
