import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Bot,
    BadgeCheck,
    Banknote,
    Boxes,
    Building2,
    CalendarDays,
    CalendarRange,
    Check,
    CircleAlert,
    ChartNoAxesCombined,
    CircleDollarSign,
    Clock3,
    FileChartColumn,
    FileText,
    FolderKanban,
    Landmark,
    LibraryBig,
    ReceiptText,
    Settings2,
    Sparkles,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    Users,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import OperationalModuleCenter from '@/components/operational-module-center';
import { hasPlanModule } from '@/lib/plan-capabilities';
import ConfigurationWorkspace from '@/pages/accounting/configuration-workspace';
import NormativeLibraryWorkspace from '@/pages/accounting/normative-library-workspace';
import { companyDashboard, reports, sales } from '@/routes/accounting';

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
    cash_cents: number;
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
    recent: {
        kind: string;
        title: string;
        detail: string;
        date: string;
        company_id: string;
    }[];
    period: {
        name: string;
        starts_on: string;
        ends_on: string;
        status: 'open' | 'closed';
        closed_at: string | null;
    } | null;
    attention: {
        label: string;
        detail: string;
        tone: 'success' | 'warning' | 'danger';
        module: string;
    }[];
    fiscal_obligations: {
        label: string;
        due: string;
        status: string;
        module: string;
    }[];
    period_close: {
        label: string;
        status: 'open' | 'closed';
        progress: number;
        pending: string[];
    } | null;
    ai_context: {
        suggestions: string[];
    };
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
    'usuarios-roles': {
        title: 'Usuarios y Roles',
        detail: 'Control de acceso, permisos y seguridad',
        icon: Users,
    },
    configuracion: {
        title: 'Configuración',
        detail: 'Parámetros generales, módulos e integraciones',
        icon: Settings2,
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
    href,
    tooltip,
}: {
    label: string;
    value: number;
    change: string;
    icon: LucideIcon;
    tone: string;
    href?: string;
    tooltip?: string;
}) {
    const body = (
        <div
            className={`${card} kpi-card p-4 transition hover:-translate-y-0.5 hover:shadow-lg`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="mb-1 truncate text-xs font-medium text-slate-500">
                        {label}
                    </p>
                    <p className="text-[1.18rem] leading-tight font-bold text-slate-800">
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

    if (href) {
        return (
            <Link href={href} title={tooltip} className="block">
                {body}
            </Link>
        );
    }

    return body;
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
    canWrite,
}: {
    moduleKey: string;
    company: Props['company'];
    planName: string;
    canWrite: boolean;
}) {
    const module = moduleCatalog[moduleKey];

    if (!module) {
        return null;
    }

    // The shared legal catalog; the company's internal norms keep their own approval workflow below it.
    if (moduleKey === 'normativa') {
        return (
            <NormativeLibraryWorkspace company={company}>
                <section className="space-y-2">
                    <h2 className="text-sm font-black text-[#061633]">
                        Normas internas de {company.name}
                    </h2>
                    <p className="text-xs text-[#526b92]">
                        Políticas y normas propias de la empresa. Se redactan,
                        revisan, aprueban y publican; una norma publicada solo
                        se sustituye o archiva, nunca se edita.
                    </p>
                    <OperationalModuleCenter
                        company={company}
                        module={moduleKey}
                        title="Normas internas"
                        canWrite={canWrite}
                    />
                </section>
            </NormativeLibraryWorkspace>
        );
    }

    if (moduleKey === 'configuracion') {
        return <ConfigurationWorkspace company={company} />;
    }

    return (
        <main className="min-h-full bg-[#f6f8fb] p-3 sm:p-5">
            <Head title={`${module.title} · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs text-slate-500">
                                Inicio <span className="px-2">›</span>{' '}
                                {module.title}
                            </p>
                            <h1 className="mt-2 text-3xl font-bold text-[#172033]">
                                {module.title}
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                {module.detail}
                            </p>
                        </div>
                        <span className="rounded-lg bg-[#f2e7e7] px-3 py-2 text-xs font-semibold text-[#722f37]">
                            Plan {planName}
                        </span>
                    </div>
                </header>
                <OperationalModuleCenter
                    company={company}
                    module={moduleKey}
                    title={module.title}
                    canWrite={canWrite}
                    defaultOpen
                />
            </div>
        </main>
    );
}

export default function CompanyDashboard({
    company,
    metrics,
    canWrite,
}: Props) {
    const page = usePage();
    const navigation = page.props.workspaceNavigation as
        | {
              plan: { name: string; features: string[] } | null;
          }
        | null
        | undefined;
    const planFeatures = navigation?.plan?.features ?? [];
    const currentUrl = new URL(
        page.url,
        typeof window === 'undefined'
            ? 'http://localhost'
            : window.location.origin,
    );
    const moduleKey = currentUrl.searchParams.get('module');
    const searchTerm = (currentUrl.searchParams.get('q') ?? '')
        .trim()
        .toLocaleLowerCase();

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
                canWrite={canWrite}
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
    const currentNet = current.sales_cents - current.purchases_cents;
    const previousNet = previous.sales_cents - previous.purchases_cents;
    const moduleUrl = (module: string) =>
        companyDashboard.url(company.public_id, { query: { module } });
    const searchResults = (() => {
        if (!searchTerm) {
            return [];
        }

        const matches = (...values: Array<number | string | null>) =>
            values.some((value) =>
                String(value ?? '')
                    .toLocaleLowerCase()
                    .includes(searchTerm),
            );

        return [
            ...metrics.attention
                .filter((item) => matches(item.label, item.detail))
                .map((item) => ({
                    key: `attention-${item.module}-${item.label}`,
                    title: item.label,
                    detail: item.detail,
                    href: moduleUrl(item.module),
                    badge: 'Atención',
                })),
            ...metrics.fiscal_obligations
                .filter((item) => matches(item.label, item.status, item.due))
                .map((item) => ({
                    key: `fiscal-${item.label}-${item.due}`,
                    title: item.label,
                    detail: `Vence ${shortDate(item.due)} · ${item.status}`,
                    href: moduleUrl(item.module),
                    badge: 'Fiscal',
                })),
            ...metrics.recent
                .filter((item) => matches(item.title, item.detail, item.kind))
                .map((item) => ({
                    key: `recent-${item.kind}-${item.title}-${item.date}`,
                    title: item.title,
                    detail: item.detail,
                    href: companyDashboard.url(item.company_id),
                    badge: 'Actividad',
                })),
            ...metrics.recent_invoices
                .filter((invoice) =>
                    matches(
                        invoice.public_id,
                        invoice.number,
                        invoice.customer,
                        invoice.status,
                    ),
                )
                .map((invoice) => ({
                    key: `invoice-${invoice.public_id}`,
                    title: `Factura ${invoice.number ?? invoice.public_id}`,
                    detail: `${invoice.customer} · ${money(invoice.total_cents)}`,
                    href: sales.url(company.public_id, {
                        query: {
                            search:
                                invoice.number?.toString() ?? invoice.public_id,
                        },
                    }),
                    badge: 'Factura',
                })),
        ].slice(0, 8);
    })();
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
                            href={sales.url(company.public_id)}
                            tooltip={`Ingresos registrados durante ${metrics.period?.name ?? 'el período actual'}.`}
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
                            href={reports.url(company.public_id)}
                            tooltip="Gastos contabilizados del período actual."
                        />
                        <Kpi
                            label={
                                metrics.profit_cents < 0
                                    ? 'Pérdida neta'
                                    : 'Utilidad neta'
                            }
                            value={metrics.profit_cents}
                            change={variation(currentNet, previousNet)}
                            icon={ChartNoAxesCombined}
                            tone="bg-amber-100 text-amber-600"
                            href={reports.url(company.public_id)}
                            tooltip="Resultado del período derivado de cuentas contables."
                        />
                        <Kpi
                            label="Disponibilidad"
                            value={metrics.cash_cents}
                            change={variation(
                                metrics.collections_cents -
                                    metrics.payments_cents,
                                previous.collections_cents -
                                    previous.payments_cents,
                            )}
                            icon={Landmark}
                            tone="bg-[#f1e6e7] text-[#722f37]"
                            href={moduleUrl('bancos')}
                            tooltip="Saldo disponible en bancos activos de la empresa."
                        />
                        <Kpi
                            label="CxC pendiente"
                            value={metrics.receivable_cents}
                            change={`${money(metrics.overdue_receivable_cents)} vencidos`}
                            icon={CircleDollarSign}
                            tone="bg-teal-100 text-teal-600"
                            href={moduleUrl('estados-cuenta')}
                            tooltip="Saldo pendiente de cuentas por cobrar activas."
                        />
                        <Kpi
                            label="CxP pendiente"
                            value={metrics.payable_cents}
                            change={`${money(metrics.overdue_payable_cents)} vencidos`}
                            icon={WalletCards}
                            tone="bg-orange-100 text-orange-600"
                            href={moduleUrl('estados-cuenta')}
                            tooltip="Saldo pendiente de cuentas por pagar activas."
                        />
                    </section>

                    {searchTerm && (
                        <section className={`${card} mb-6 overflow-hidden`}>
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                                <div>
                                    <h2 className="font-semibold">
                                        Resultados de búsqueda
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Coincidencias para “{searchTerm}” en la
                                        empresa activa.
                                    </p>
                                </div>
                                <Link
                                    href={companyDashboard.url(
                                        company.public_id,
                                    )}
                                    className="text-xs font-semibold text-[#722f37] hover:underline"
                                >
                                    Limpiar
                                </Link>
                            </div>
                            <div className="divide-y divide-slate-100">
                                {searchResults.map((item) => (
                                    <Link
                                        key={item.key}
                                        href={item.href}
                                        className="flex items-center justify-between gap-4 px-5 py-3 text-sm transition hover:bg-slate-50"
                                    >
                                        <span className="min-w-0">
                                            <strong className="block truncate text-slate-800">
                                                {item.title}
                                            </strong>
                                            <small className="block truncate text-slate-500">
                                                {item.detail}
                                            </small>
                                        </span>
                                        <span className="shrink-0 rounded-full bg-rose-50 px-3 py-1 text-[11px] font-semibold text-[#722f37]">
                                            {item.badge}
                                        </span>
                                    </Link>
                                ))}
                                {searchResults.length === 0 && (
                                    <div className="px-5 py-8 text-center text-sm text-slate-400">
                                        No hay coincidencias en los datos
                                        cargados del dashboard.
                                    </div>
                                )}
                            </div>
                        </section>
                    )}

                    <section className="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-[1.1fr_.9fr_.9fr]">
                        <div className={`${card} p-5`}>
                            <div className="flex items-center justify-between gap-3">
                                <h2 className="font-semibold">
                                    Requiere atención
                                </h2>
                                <span className="rounded-full bg-rose-50 px-3 py-1 text-[10px] font-semibold text-[#722f37]">
                                    Datos reales
                                </span>
                            </div>
                            <div className="mt-4 space-y-2">
                                {metrics.attention.map((item) => (
                                    <Link
                                        key={`${item.module}-${item.label}`}
                                        href={moduleUrl(item.module)}
                                        className="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2 text-sm transition hover:bg-slate-50"
                                    >
                                        <span
                                            className={`grid size-7 place-items-center rounded-full ${
                                                item.tone === 'danger'
                                                    ? 'bg-red-50 text-red-600'
                                                    : item.tone === 'warning'
                                                      ? 'bg-amber-50 text-amber-600'
                                                      : 'bg-emerald-50 text-emerald-600'
                                            }`}
                                        >
                                            {item.tone === 'success' ? (
                                                <Check className="size-4" />
                                            ) : (
                                                <CircleAlert className="size-4" />
                                            )}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <strong className="block truncate text-xs">
                                                {item.label}
                                            </strong>
                                            <small className="block truncate text-[11px] text-slate-500">
                                                {item.detail}
                                            </small>
                                        </span>
                                        <ArrowRight className="size-4 text-slate-400" />
                                    </Link>
                                ))}
                            </div>
                        </div>

                        <Link
                            href={moduleUrl('cierre-periodo')}
                            className={`${card} block p-5 transition hover:-translate-y-0.5 hover:shadow-lg`}
                        >
                            <h2 className="flex items-center gap-2 font-semibold">
                                <CalendarRange className="size-5 text-[#722f37]" />
                                Período contable
                            </h2>
                            <div className="mt-4">
                                <p className="text-2xl font-bold text-slate-900">
                                    {metrics.period?.name ?? 'Sin período'}
                                </p>
                                <p className="mt-1 flex items-center gap-2 text-sm text-slate-500">
                                    <span
                                        className={`size-2 rounded-full ${metrics.period?.status === 'closed' ? 'bg-slate-500' : 'bg-emerald-500'}`}
                                    />
                                    {metrics.period?.status === 'closed'
                                        ? 'Cerrado'
                                        : 'Abierto'}
                                </p>
                            </div>
                            {metrics.period_close && (
                                <div className="mt-4">
                                    <div className="flex justify-between text-xs">
                                        <span>Cierre</span>
                                        <strong>
                                            {metrics.period_close.progress}%
                                        </strong>
                                    </div>
                                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className="h-full rounded-full bg-[#722f37]"
                                            style={{
                                                width: `${metrics.period_close.progress}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            )}
                        </Link>

                        <div className={`${card} p-5`}>
                            <h2 className="flex items-center gap-2 font-semibold">
                                <Sparkles className="size-5 text-[#c7972d]" />
                                Luzma
                            </h2>
                            <div className="mt-4 space-y-2">
                                {metrics.ai_context.suggestions
                                    .slice(0, 3)
                                    .map((suggestion) => (
                                        <p
                                            key={suggestion}
                                            className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"
                                        >
                                            {suggestion}
                                        </p>
                                    ))}
                            </div>
                            <button
                                type="button"
                                className="mt-4 inline-flex items-center gap-2 rounded-lg border border-[#eadfda] px-3 py-2 text-xs font-semibold text-[#722f37]"
                            >
                                <Bot className="size-4" />
                                Preguntar a Atlantia
                            </button>
                        </div>
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

                    <section className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                        <div className={`${card} overflow-hidden`}>
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                                <h2 className="font-semibold">
                                    Obligaciones y vencimientos
                                </h2>
                                <Link
                                    href={moduleUrl('impuestos')}
                                    className="text-xs font-medium text-[#722f37] hover:underline"
                                >
                                    Ver fiscal{' '}
                                    <ArrowRight className="inline size-3" />
                                </Link>
                            </div>
                            <div className="divide-y divide-slate-100">
                                {metrics.fiscal_obligations.map((item) => (
                                    <Link
                                        key={`${item.label}-${item.due}`}
                                        href={moduleUrl(item.module)}
                                        className="flex items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-slate-50"
                                    >
                                        <span>
                                            <strong className="block text-slate-800">
                                                {item.label}
                                            </strong>
                                            <small className="text-slate-500">
                                                Vence: {shortDate(item.due)}
                                            </small>
                                        </span>
                                        <span className="rounded-full bg-amber-50 px-3 py-1 text-[11px] font-semibold text-amber-700">
                                            {item.status}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </div>

                        <div className={`${card} overflow-hidden`}>
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                                <h2 className="font-semibold">
                                    Actividad reciente
                                </h2>
                                <Link
                                    href={moduleUrl('auditoria')}
                                    className="text-xs font-medium text-[#722f37] hover:underline"
                                >
                                    Ver auditoría{' '}
                                    <ArrowRight className="inline size-3" />
                                </Link>
                            </div>
                            <div className="divide-y divide-slate-100">
                                {metrics.recent.map((item) => (
                                    <Link
                                        key={`${item.kind}-${item.title}-${item.date}`}
                                        href={companyDashboard.url(
                                            item.company_id,
                                        )}
                                        className="flex items-center gap-3 px-5 py-3 text-sm hover:bg-slate-50"
                                    >
                                        <span className="grid size-8 shrink-0 place-items-center rounded-full bg-rose-50 text-[#722f37]">
                                            <Clock3 className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <strong className="block truncate text-slate-800">
                                                {item.title}
                                            </strong>
                                            <small className="block truncate text-slate-500">
                                                {item.detail}
                                            </small>
                                        </span>
                                        <small className="text-slate-400">
                                            {shortDate(item.date)}
                                        </small>
                                    </Link>
                                ))}
                                {metrics.recent.length === 0 && (
                                    <div className="px-5 py-8 text-center text-sm text-slate-400">
                                        Sin actividad reciente para la empresa
                                        activa.
                                    </div>
                                )}
                            </div>
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
