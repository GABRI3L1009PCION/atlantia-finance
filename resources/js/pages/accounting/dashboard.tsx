import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowRight,
    Bot,
    BadgeCheck,
    Banknote,
    Boxes,
    Building2,
    CalendarDays,
    CalendarRange,
    Check,
    ChevronDown,
    CircleAlert,
    CircleCheck,
    ChartNoAxesCombined,
    CircleDollarSign,
    Clock3,
    FileChartColumn,
    FileText,
    Filter,
    FolderKanban,
    Landmark,
    LibraryBig,
    ReceiptText,
    Search,
    Settings2,
    Sparkles,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    Users,
    WalletCards,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';
import { useMemo, useState } from 'react';
import OperationalModuleCenter from '@/components/operational-module-center';
import { hasPlanModule } from '@/lib/plan-capabilities';
import AccountStatementsWorkspace from '@/pages/accounting/account-statements-workspace';
import AuditWorkspace from '@/pages/accounting/audit-workspace';
import BankRulesWorkspace from '@/pages/accounting/bank-rules-workspace';
import BudgetsWorkspace from '@/pages/accounting/budgets-workspace';
import CashWorkspace from '@/pages/accounting/cash-workspace';
import ClientPortalWorkspace from '@/pages/accounting/client-portal-workspace';
import ConfigurationWorkspace from '@/pages/accounting/configuration-workspace';
import CreditNotesWorkspace from '@/pages/accounting/credit-notes-workspace';
import DocumentsWorkspace from '@/pages/accounting/documents-workspace';
import FelWorkspace from '@/pages/accounting/fel-workspace';
import FixedAssetsWorkspace from '@/pages/accounting/fixed-assets-workspace';
import InventoryWorkspace from '@/pages/accounting/inventory-workspace';
import KardexWorkspace from '@/pages/accounting/kardex-workspace';
import NormativeLibraryWorkspace from '@/pages/accounting/normative-library-workspace';
import PayrollWorkspace from '@/pages/accounting/payroll-workspace';
import PeriodCloseWorkspace from '@/pages/accounting/period-close-workspace';
import PhysicalInventoryWorkspace from '@/pages/accounting/physical-inventory-workspace';
import ProjectsWorkspace from '@/pages/accounting/projects-workspace';
import PurchaseOrdersWorkspace from '@/pages/accounting/purchase-orders-workspace';
import QuotationsWorkspace from '@/pages/accounting/quotations-workspace';
import RemindersWorkspace from '@/pages/accounting/reminders-workspace';
import UsersRolesWorkspace from '@/pages/accounting/users-roles-workspace';
import VatBooksWorkspace from '@/pages/accounting/vat-books-workspace';
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

type ReconciliationStatus = 'matched' | 'pending' | 'unmatched' | 'difference';

type ReconciliationRow = {
    id: number;
    date: string;
    bankDescription: string;
    reference: string;
    bankIn: number;
    bankOut: number;
    bankBalance: number;
    ledgerDate?: string;
    document?: string;
    ledgerDescription?: string;
    ledgerIn?: number;
    ledgerOut?: number;
    ledgerBalance?: number;
    status: ReconciliationStatus;
};

const reconciliationSeed: ReconciliationRow[] = [
    {
        id: 1,
        date: '30/09/2026',
        bankDescription: 'TRANSFERENCIA DISTR CENTRAL',
        reference: 'TRX-85623',
        bankIn: 0,
        bankOut: 560000,
        bankBalance: 4845000,
        ledgerDate: '30/09/2026',
        document: 'PA-0874',
        ledgerDescription: 'Pago a Distribuidora Central',
        ledgerOut: 560000,
        ledgerBalance: 4845000,
        status: 'matched',
    },
    {
        id: 2,
        date: '30/09/2026',
        bankDescription: 'DEPÓSITO COMERCIAL LÓPEZ',
        reference: 'DEP-1524',
        bankIn: 840000,
        bankOut: 0,
        bankBalance: 5405000,
        ledgerDate: '30/09/2026',
        document: 'CB-00122',
        ledgerDescription: 'Cobro Comercial López',
        ledgerIn: 840000,
        ledgerBalance: 5405000,
        status: 'matched',
    },
    {
        id: 3,
        date: '30/09/2026',
        bankDescription: 'COMISIÓN POR MANEJO',
        reference: 'COM-0158',
        bankIn: 0,
        bankOut: 7500,
        bankBalance: 4565000,
        status: 'unmatched',
    },
    {
        id: 4,
        date: '30/09/2026',
        bankDescription: 'INTERESES GANADOS',
        reference: 'INT-0926',
        bankIn: 12000,
        bankOut: 0,
        bankBalance: 4577000,
        status: 'unmatched',
    },
    {
        id: 5,
        date: '30/09/2026',
        bankDescription: 'CHEQUE 001254',
        reference: 'CHQ-001254',
        bankIn: 0,
        bankOut: 85000,
        bankBalance: 4562000,
        ledgerDate: '28/09/2026',
        document: 'CH-001254',
        ledgerDescription: 'Cheque 001254 · Papelería Total',
        ledgerOut: 85000,
        ledgerBalance: 4562000,
        status: 'pending',
    },
    {
        id: 6,
        date: '30/09/2026',
        bankDescription: 'DEPÓSITO EN EFECTIVO',
        reference: 'DEP-1477',
        bankIn: 50000,
        bankOut: 0,
        bankBalance: 4612000,
        ledgerDate: '30/09/2026',
        document: 'DEP-0904',
        ledgerDescription: 'Depósito en efectivo',
        ledgerIn: 50000,
        ledgerBalance: 4647000,
        status: 'pending',
    },
    {
        id: 7,
        date: '30/09/2026',
        bankDescription: 'TRANSFERENCIA A BAC',
        reference: 'TRF-00451',
        bankIn: 0,
        bankOut: 1000000,
        bankBalance: 4562000,
        ledgerDate: '29/09/2026',
        document: 'TR-00231',
        ledgerDescription: 'Transferencia a BAC ****2381',
        ledgerOut: 1000000,
        ledgerBalance: 4597000,
        status: 'matched',
    },
    {
        id: 8,
        date: '29/09/2026',
        bankDescription: 'PAGO SERVICIOS',
        reference: 'PAG-9852',
        bankIn: 0,
        bankOut: 32000,
        bankBalance: 5562000,
        ledgerDate: '29/09/2026',
        document: 'PA-0881',
        ledgerDescription: 'Pago Servicios Eléctricos',
        ledgerOut: 32000,
        ledgerBalance: 5597000,
        status: 'matched',
    },
    {
        id: 9,
        date: '28/09/2026',
        bankDescription: 'TRANSFERENCIA CLIENTE',
        reference: 'TRX-77456',
        bankIn: 200000,
        bankOut: 0,
        bankBalance: 5594000,
        ledgerDate: '28/09/2026',
        document: 'CB-00118',
        ledgerDescription: 'Cobro Distribuidora del Sur',
        ledgerIn: 195000,
        ledgerBalance: 5629000,
        status: 'difference',
    },
    {
        id: 10,
        date: '27/09/2026',
        bankDescription: 'CHEQUE 001200',
        reference: 'CHQ-001200',
        bankIn: 0,
        bankOut: 120000,
        bankBalance: 5394000,
        ledgerDate: '27/09/2026',
        document: 'CH-001200',
        ledgerDescription: 'Cheque 001200 · Transporte G',
        ledgerOut: 120000,
        ledgerBalance: 5424000,
        status: 'matched',
    },
];

const reconciliationTabs: {
    key: 'all' | ReconciliationStatus;
    label: string;
}[] = [
    { key: 'all', label: 'Todos' },
    { key: 'matched', label: 'Conciliados' },
    { key: 'pending', label: 'Pendientes' },
    { key: 'unmatched', label: 'Sin correspondencia' },
    { key: 'difference', label: 'Diferencias' },
];

const reconciliationTotals: Record<'all' | ReconciliationStatus, number> = {
    all: 152,
    matched: 128,
    pending: 18,
    unmatched: 6,
    difference: 3,
};

const reconciliationStatus = {
    matched: {
        label: 'Conciliado',
        className: 'bg-emerald-50 text-emerald-700',
        icon: CircleCheck,
    },
    pending: {
        label: 'Pendiente banco',
        className: 'bg-blue-50 text-blue-700',
        icon: CalendarRange,
    },
    unmatched: {
        label: 'Sin registro',
        className: 'bg-amber-50 text-amber-700',
        icon: CircleAlert,
    },
    difference: {
        label: 'Diferencia',
        className: 'bg-orange-50 text-orange-700',
        icon: CircleAlert,
    },
};

function ReconciliationWorkspace({ company }: { company: Props['company'] }) {
    const [rows, setRows] = useState(reconciliationSeed);
    const [selectedId, setSelectedId] = useState(1);
    const [activeTab, setActiveTab] = useState<'all' | ReconciliationStatus>(
        'all',
    );
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const selected = rows.find((row) => row.id === selectedId) ?? rows[0];
    const filteredRows = useMemo(
        () =>
            rows.filter((row) => {
                const matchesTab =
                    activeTab === 'all' || row.status === activeTab;
                const haystack =
                    `${row.bankDescription} ${row.reference} ${row.document ?? ''} ${row.ledgerDescription ?? ''}`.toLowerCase();

                return matchesTab && haystack.includes(query.toLowerCase());
            }),
        [activeTab, query, rows],
    );
    const progress = 84;
    const applyMatch = () => {
        setRows((current) =>
            current.map((row) =>
                row.id === selected.id ? { ...row, status: 'matched' } : row,
            ),
        );
        setNotice('Movimiento conciliado y registrado en el historial.');
    };
    const runAi = () => {
        const candidate = rows.find((row) => row.status !== 'matched');

        if (candidate) {
            setSelectedId(candidate.id);
        }

        setNotice(
            'Atlantia IA encontró la siguiente coincidencia con mayor confianza.',
        );
    };

    return (
        <main className="min-h-full bg-[#f5f8fd] p-3 text-[#10245c] sm:p-5">
            <Head title={`Conciliación bancaria · ${company.name}`} />
            <div className="mx-auto max-w-[1780px] space-y-3">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <span className="grid size-11 place-items-center rounded-xl bg-[#0757d6] text-white shadow-lg shadow-blue-900/20">
                            <Landmark className="size-6" />
                        </span>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">
                                Conciliación bancaria
                            </h1>
                            <p className="text-xs text-slate-500">
                                Compara los movimientos del banco con los
                                registrados en Atlantia
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2 text-xs font-semibold">
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Selecciona un archivo OFX, CSV o Excel para importar el estado de cuenta.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-100 bg-white px-4 py-2.5 shadow-sm hover:border-blue-300"
                        >
                            <ArrowDownToLine className="size-4" /> Importar
                            estado de cuenta
                        </button>
                        <button
                            type="button"
                            onClick={runAi}
                            className="flex items-center gap-2 rounded-lg border border-violet-200 bg-white px-4 py-2.5 text-violet-700 shadow-sm hover:bg-violet-50"
                        >
                            <Sparkles className="size-4" /> Conciliar con IA
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Ajustes y partidas listos para revisión.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-100 bg-white px-4 py-2.5 shadow-sm"
                        >
                            <Settings2 className="size-4" /> Ajustes y partidas
                        </button>
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg border border-blue-100 bg-white px-4 py-2.5 shadow-sm"
                        >
                            Más acciones <ChevronDown className="size-4" />
                        </button>
                    </div>
                </header>

                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <Bot className="size-4" />
                            {notice}
                        </span>
                        <button
                            type="button"
                            onClick={() => setNotice('')}
                            aria-label="Cerrar aviso"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}

                <section className="grid gap-3 lg:grid-cols-[1fr_1fr_.62fr] xl:max-w-[760px]">
                    <label className="rounded-xl border border-blue-100 bg-white p-3 text-[10px] font-semibold">
                        Cuenta bancaria
                        <select className="mt-1 block w-full bg-transparent text-xs font-bold outline-none">
                            <option>
                                Banco Industrial · Monetaria ****4582
                            </option>
                        </select>
                    </label>
                    <label className="rounded-xl border border-blue-100 bg-white p-3 text-[10px] font-semibold">
                        Período
                        <div className="mt-1 flex items-center justify-between text-xs font-bold">
                            <span>01/09/2026 - 30/09/2026</span>
                            <CalendarDays className="size-4 text-blue-600" />
                        </div>
                    </label>
                    <label className="rounded-xl border border-blue-100 bg-white p-3 text-[10px] font-semibold">
                        Estado de conciliación
                        <span className="mt-1 block w-fit rounded-full bg-amber-50 px-3 py-1 text-amber-700">
                            En proceso
                        </span>
                    </label>
                </section>

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
                    {(
                        [
                            [
                                'Saldo según banco',
                                money(4845000),
                                'Al 30/09/2026',
                                Landmark,
                                'text-blue-700 bg-blue-50',
                            ],
                            [
                                'Saldo según Atlantia',
                                money(4810000),
                                'Al 30/09/2026',
                                WalletCards,
                                'text-blue-700 bg-blue-50',
                            ],
                            [
                                'Diferencia actual',
                                money(35000),
                                '0.72% del saldo',
                                CircleAlert,
                                'text-red-600 bg-red-50',
                            ],
                            [
                                'Movimientos del banco',
                                '152',
                                'Importados',
                                FileText,
                                'text-blue-700 bg-blue-50',
                            ],
                            [
                                'Conciliados',
                                '128',
                                '84.21%',
                                CircleCheck,
                                'text-emerald-700 bg-emerald-50',
                            ],
                            [
                                'Sin pendientes',
                                '18',
                                '11.84%',
                                CircleAlert,
                                'text-red-600 bg-red-50',
                            ],
                        ] satisfies [
                            string,
                            string,
                            string,
                            LucideIcon,
                            string,
                        ][]
                    ).map(([label, value, detail, MetricIcon, tone]) => (
                        <article
                            key={String(label)}
                            className="min-h-24 rounded-xl border border-blue-100 bg-white p-3 shadow-sm"
                        >
                            <div className="flex items-center gap-2 text-[10px] font-semibold text-slate-500">
                                <span
                                    className={`grid size-7 place-items-center rounded-full ${tone}`}
                                >
                                    <MetricIcon className="size-3.5" />
                                </span>
                                {label}
                            </div>
                            <strong
                                className={`mt-2 block text-xl ${String(label).includes('Diferencia') ? 'text-red-600' : 'text-[#10245c]'}`}
                            >
                                {value}
                            </strong>
                            <span className="text-[10px] text-slate-400">
                                {detail}
                            </span>
                        </article>
                    ))}
                    <article className="flex min-h-24 items-center justify-between rounded-xl border border-blue-100 bg-white p-3 shadow-sm">
                        <div>
                            <p className="text-[10px] font-semibold text-slate-500">
                                Avance de conciliación
                            </p>
                            <strong className="mt-2 block text-lg text-emerald-700">
                                {progress}%
                            </strong>
                            <span className="text-[10px] text-slate-400">
                                revisado
                            </span>
                        </div>
                        <div className="grid size-16 place-items-center rounded-full border-[7px] border-emerald-500 text-sm font-bold text-emerald-700">
                            {progress}%
                        </div>
                    </article>
                </section>

                <div className="grid gap-3 2xl:grid-cols-[minmax(0,1fr)_300px]">
                    <section className="overflow-hidden rounded-xl border border-blue-100 bg-white shadow-sm">
                        <nav className="flex gap-1 overflow-x-auto border-b border-blue-100 px-3 pt-1">
                            {reconciliationTabs.map((tab) => {
                                const tabCount = reconciliationTotals[tab.key];

                                return (
                                    <button
                                        key={tab.key}
                                        type="button"
                                        onClick={() => setActiveTab(tab.key)}
                                        className={`border-b-2 px-3 py-3 text-[11px] font-semibold whitespace-nowrap ${activeTab === tab.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                                    >
                                        {tab.label}{' '}
                                        <span className="ml-1 rounded-full bg-blue-50 px-1.5 py-0.5 text-[9px] text-blue-600">
                                            {tabCount}
                                        </span>
                                    </button>
                                );
                            })}
                        </nav>
                        <div className="flex flex-wrap items-center gap-2 border-b border-blue-100 p-3 text-[11px]">
                            <label className="relative min-w-56 flex-1">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar movimiento, referencia..."
                                    className="w-full rounded-lg border border-blue-100 py-2 pr-3 pl-9 outline-none focus:border-blue-400"
                                />
                            </label>
                            {['Todos', 'Estado', 'Origen'].map((label) => (
                                <button
                                    key={label}
                                    type="button"
                                    className="flex items-center gap-5 rounded-lg border border-blue-100 px-3 py-2 font-semibold"
                                >
                                    {label}
                                    <ChevronDown className="size-3" />
                                </button>
                            ))}
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-lg border border-blue-100 px-3 py-2 font-semibold"
                            >
                                <CalendarDays className="size-3.5 text-blue-600" />{' '}
                                01/09/2026 - 30/09/2026
                            </button>
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-lg border border-blue-100 px-3 py-2 font-semibold"
                            >
                                <Filter className="size-3.5" /> Filtros
                                avanzados
                            </button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1220px] text-left text-[10px]">
                                <thead>
                                    <tr className="bg-gradient-to-r from-blue-50 via-blue-50 to-emerald-50 text-[9px] text-blue-700 uppercase">
                                        <th
                                            colSpan={6}
                                            className="px-3 py-2 text-center"
                                        >
                                            Movimientos del banco
                                        </th>
                                        <th
                                            colSpan={6}
                                            className="border-l border-blue-100 px-3 py-2 text-center text-emerald-700"
                                        >
                                            Movimientos en Atlantia
                                        </th>
                                        <th className="border-l border-blue-100 px-3 py-2 text-center">
                                            Resultado
                                        </th>
                                    </tr>
                                    <tr className="border-b border-blue-100 bg-slate-50 text-slate-500">
                                        <th className="px-2 py-2">Fecha</th>
                                        <th>Descripción del banco</th>
                                        <th>Referencia</th>
                                        <th className="text-right">Entrada</th>
                                        <th className="text-right">Salida</th>
                                        <th className="pr-2 text-right">
                                            Saldo banco
                                        </th>
                                        <th className="border-l border-blue-100 pl-2">
                                            Fecha
                                        </th>
                                        <th>Documento / Origen</th>
                                        <th>Descripción / Tercero</th>
                                        <th className="text-right">Entrada</th>
                                        <th className="text-right">Salida</th>
                                        <th className="pr-2 text-right">
                                            Saldo Atlantia
                                        </th>
                                        <th className="border-l border-blue-100 pl-2">
                                            Estado
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-blue-50">
                                    {filteredRows.map((row) => {
                                        const state =
                                            reconciliationStatus[row.status];
                                        const StateIcon = state.icon;

                                        return (
                                            <tr
                                                key={row.id}
                                                onClick={() =>
                                                    setSelectedId(row.id)
                                                }
                                                className={`cursor-pointer hover:bg-blue-50/70 ${selected.id === row.id ? 'bg-blue-50/60' : ''}`}
                                            >
                                                <td className="px-2 py-3 font-medium">
                                                    {row.date}
                                                </td>
                                                <td className="max-w-44 truncate font-semibold">
                                                    {row.bankDescription}
                                                </td>
                                                <td className="font-semibold text-blue-700">
                                                    {row.reference}
                                                </td>
                                                <td className="text-right font-semibold text-emerald-600">
                                                    {row.bankIn
                                                        ? money(row.bankIn)
                                                        : '—'}
                                                </td>
                                                <td className="text-right font-semibold text-red-600">
                                                    {row.bankOut
                                                        ? money(row.bankOut)
                                                        : '—'}
                                                </td>
                                                <td className="pr-2 text-right font-semibold">
                                                    {money(row.bankBalance)}
                                                </td>
                                                <td className="border-l border-blue-50 pl-2">
                                                    {row.ledgerDate ?? '—'}
                                                </td>
                                                <td className="font-semibold text-blue-700">
                                                    {row.document ?? '—'}
                                                </td>
                                                <td className="max-w-44 truncate">
                                                    {row.ledgerDescription ??
                                                        '—'}
                                                </td>
                                                <td className="text-right font-semibold text-emerald-600">
                                                    {row.ledgerIn
                                                        ? money(row.ledgerIn)
                                                        : '—'}
                                                </td>
                                                <td className="text-right font-semibold text-red-600">
                                                    {row.ledgerOut
                                                        ? money(row.ledgerOut)
                                                        : '—'}
                                                </td>
                                                <td className="pr-2 text-right font-semibold">
                                                    {row.ledgerBalance
                                                        ? money(
                                                              row.ledgerBalance,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="border-l border-blue-50 px-2">
                                                    <span
                                                        className={`inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold whitespace-nowrap ${state.className}`}
                                                    >
                                                        <StateIcon className="size-3" />
                                                        {state.label}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            {filteredRows.length === 0 && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay movimientos que coincidan con los
                                    filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex items-center justify-between border-t border-blue-100 px-4 py-3 text-[10px] text-slate-500">
                            <span>
                                Mostrando {filteredRows.length} de 152
                                movimientos
                            </span>
                            <div className="flex gap-1">
                                {[
                                    '‹',
                                    '1',
                                    '2',
                                    '3',
                                    '4',
                                    '5',
                                    '…',
                                    '16',
                                    '›',
                                ].map((page, index) => (
                                    <button
                                        key={`${page}-${index}`}
                                        type="button"
                                        className={`grid size-7 place-items-center rounded border ${page === '1' ? 'border-blue-600 bg-blue-600 text-white' : 'border-blue-100 bg-white'}`}
                                    >
                                        {page}
                                    </button>
                                ))}
                            </div>
                            <span>
                                Filas por página&nbsp; <strong>10</strong>
                            </span>
                        </footer>
                    </section>

                    <aside className="rounded-xl border border-blue-100 bg-white p-3 shadow-sm">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-bold">
                                Detalle de conciliación
                            </h2>
                            <X className="size-4 text-slate-400" />
                        </div>
                        <div className="space-y-3 text-[10px]">
                            <section className="rounded-lg border border-blue-100 bg-blue-50/40 p-3">
                                <h3 className="mb-3 flex items-center gap-2 font-bold text-blue-700">
                                    <Landmark className="size-3.5" /> Movimiento
                                    del banco
                                </h3>
                                {[
                                    ['Fecha', selected.date],
                                    ['Descripción', selected.bankDescription],
                                    ['Referencia', selected.reference],
                                    [
                                        'Tipo',
                                        selected.bankIn
                                            ? 'Depósito'
                                            : 'Transferencia',
                                    ],
                                    [
                                        'Entrada',
                                        selected.bankIn
                                            ? money(selected.bankIn)
                                            : '—',
                                    ],
                                    [
                                        'Salida',
                                        selected.bankOut
                                            ? money(selected.bankOut)
                                            : '—',
                                    ],
                                    ['Saldo', money(selected.bankBalance)],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="mb-2 flex items-start justify-between gap-3"
                                    >
                                        <span className="text-slate-500">
                                            {label}
                                        </span>
                                        <strong className="max-w-40 text-right">
                                            {value}
                                        </strong>
                                    </div>
                                ))}
                            </section>
                            <section className="rounded-lg border border-emerald-100 bg-emerald-50/40 p-3">
                                <h3 className="mb-3 flex items-center gap-2 font-bold text-emerald-700">
                                    <CircleCheck className="size-3.5" />{' '}
                                    Movimiento en Atlantia
                                </h3>
                                {[
                                    [
                                        'Documento',
                                        selected.document ?? 'Sin asignar',
                                    ],
                                    ['Fecha', selected.ledgerDate ?? '—'],
                                    [
                                        'Descripción',
                                        selected.ledgerDescription ??
                                            'Pendiente de asociación',
                                    ],
                                    [
                                        'Entrada',
                                        selected.ledgerIn
                                            ? money(selected.ledgerIn)
                                            : '—',
                                    ],
                                    [
                                        'Salida',
                                        selected.ledgerOut
                                            ? money(selected.ledgerOut)
                                            : '—',
                                    ],
                                    [
                                        'Saldo',
                                        selected.ledgerBalance
                                            ? money(selected.ledgerBalance)
                                            : '—',
                                    ],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="mb-2 flex items-start justify-between gap-3"
                                    >
                                        <span className="text-slate-500">
                                            {label}
                                        </span>
                                        <strong className="max-w-40 text-right">
                                            {value}
                                        </strong>
                                    </div>
                                ))}
                            </section>
                            <section className="rounded-lg border border-violet-100 p-3">
                                <h3 className="mb-2 flex items-center justify-between font-bold">
                                    <span className="flex items-center gap-2">
                                        <Sparkles className="size-3.5 text-violet-600" />{' '}
                                        Coincidencia IA
                                    </span>
                                    <span className="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                                        98%
                                    </span>
                                </h3>
                                <p className="mb-2 text-slate-500">
                                    Razones de coincidencia
                                </p>
                                {[
                                    'Monto igual',
                                    'Referencia similar',
                                    'Tercero coincide',
                                    'Fecha dentro del rango',
                                ].map((reason) => (
                                    <p
                                        key={reason}
                                        className="mt-1 flex items-center gap-2 text-emerald-700"
                                    >
                                        <Check className="size-3" />
                                        {reason}
                                    </p>
                                ))}
                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        onClick={applyMatch}
                                        className="rounded-lg bg-emerald-600 px-2 py-2 font-semibold text-white hover:bg-emerald-700"
                                    >
                                        Aplicar conciliación
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setNotice(
                                                'Movimiento marcado como revisado.',
                                            )
                                        }
                                        className="rounded-lg border border-blue-100 px-2 py-2 font-semibold"
                                    >
                                        Marcar revisado
                                    </button>
                                </div>
                            </section>
                            <section className="rounded-lg border border-blue-100 p-3">
                                <h3 className="mb-3 font-bold">
                                    Historial de acciones
                                </h3>
                                <div className="space-y-3 border-l border-blue-100 pl-3 text-slate-500">
                                    <p>
                                        <strong className="block text-[#10245c]">
                                            30/09/2026 10:35
                                        </strong>
                                        Sugerencia IA generada
                                    </p>
                                    <p>
                                        <strong className="block text-[#10245c]">
                                            30/09/2026 10:36
                                        </strong>
                                        Marcado para revisión
                                    </p>
                                </div>
                            </section>
                        </div>
                    </aside>
                </div>

                <section className="grid gap-3 rounded-xl border border-blue-100 bg-white p-3 shadow-sm sm:grid-cols-2 xl:grid-cols-[1fr_2fr_1fr_1fr_1fr]">
                    <div className="rounded-lg bg-slate-50 p-4">
                        <p className="text-[10px] font-semibold text-slate-500">
                            SALDO SEGÚN BANCO
                        </p>
                        <strong className="mt-2 block text-xl">
                            {money(4845000)}
                        </strong>
                        <span className="text-[10px] text-slate-400">
                            Al 30/09/2026
                        </span>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-4">
                        <p className="text-[10px] font-semibold text-slate-500">
                            PARTIDAS CONCILIATORIAS
                        </p>
                        <div className="mt-2 flex flex-wrap gap-6 text-xs">
                            <span className="text-emerald-700">
                                Depósitos en tránsito{' '}
                                <strong className="block text-base">
                                    + {money(50000)}
                                </strong>
                            </span>
                            <span className="text-red-600">
                                Cheques pendientes{' '}
                                <strong className="block text-base">
                                    - {money(85000)}
                                </strong>
                            </span>
                        </div>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-4">
                        <p className="text-[10px] font-semibold text-slate-500">
                            SALDO CONCILIADO
                        </p>
                        <strong className="mt-2 block text-xl">
                            {money(4810000)}
                        </strong>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-4">
                        <p className="text-[10px] font-semibold text-slate-500">
                            SALDO SEGÚN ATLANTIA
                        </p>
                        <strong className="mt-2 block text-xl">
                            {money(4810000)}
                        </strong>
                        <span className="text-[10px] text-emerald-600">
                            Diferencia: Q0.00
                        </span>
                    </div>
                    <div className="rounded-lg bg-emerald-50 p-4">
                        <p className="flex items-center gap-2 text-[10px] font-semibold text-slate-500">
                            <CircleCheck className="size-4 text-emerald-600" />{' '}
                            ESTADO DE CONCILIACIÓN
                        </p>
                        <strong className="mt-2 block">En proceso</strong>
                        <span className="text-[10px] text-slate-500">
                            {progress}% conciliado
                        </span>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'La conciliación quedará disponible al resolver todas las diferencias.',
                                )
                            }
                            className="mt-3 w-full rounded-lg bg-blue-700 py-2 text-xs font-semibold text-white"
                        >
                            Cerrar conciliación
                        </button>
                    </div>
                </section>
            </div>
        </main>
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

    if (!['fel', 'configuracion'].includes(moduleKey)) {
        return (
            <main className="min-h-full bg-[#f6f8fb] p-3 sm:p-5">
                <Head title={module.title + ' · ' + company.name} />
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

    const operational = (workspace: ReactNode): ReactNode => {
        if (moduleKey === 'fel' || moduleKey === 'configuracion') {
            return workspace;
        }

        return (
            <>
                <OperationalModuleCenter
                    company={company}
                    module={moduleKey}
                    title={module.title}
                    canWrite={canWrite}
                />
                <div
                    onClickCapture={(
                        event: ReactMouseEvent<HTMLDivElement>,
                    ) => {
                        const button = (event.target as HTMLElement).closest(
                            'button',
                        );
                        const action =
                            button?.textContent?.replace(/\s+/g, ' ').trim() ??
                            '';

                        if (
                            !/^(Nuevo|Nueva|Agregar|Subir|Registrar|Invitar|Crear)\b/i.test(
                                action,
                            )
                        ) {
                            return;
                        }

                        event.preventDefault();
                        event.stopPropagation();
                        window.dispatchEvent(
                            new CustomEvent('atlantia:operation:new', {
                                detail: { module: moduleKey, title: action },
                            }),
                        );
                    }}
                >
                    {workspace}
                </div>
            </>
        );
    };

    if (moduleKey === 'conciliacion') {
        return operational(<ReconciliationWorkspace company={company} />);
    }

    if (moduleKey === 'reglas') {
        return operational(<BankRulesWorkspace company={company} />);
    }

    if (moduleKey === 'caja') {
        return operational(<CashWorkspace company={company} />);
    }

    if (moduleKey === 'inventario') {
        return operational(<InventoryWorkspace company={company} />);
    }

    if (moduleKey === 'kardex') {
        return operational(<KardexWorkspace company={company} />);
    }

    if (moduleKey === 'inventario-fisico') {
        return operational(<PhysicalInventoryWorkspace company={company} />);
    }

    if (moduleKey === 'activos-fijos') {
        return operational(<FixedAssetsWorkspace company={company} />);
    }

    if (moduleKey === 'nomina') {
        return operational(<PayrollWorkspace company={company} />);
    }

    if (moduleKey === 'proyectos') {
        return operational(<ProjectsWorkspace company={company} />);
    }

    if (moduleKey === 'presupuestos') {
        return operational(<BudgetsWorkspace company={company} />);
    }

    if (moduleKey === 'normativa') {
        return operational(<NormativeLibraryWorkspace company={company} />);
    }

    if (moduleKey === 'documentos') {
        return operational(<DocumentsWorkspace company={company} />);
    }

    if (moduleKey === 'cotizaciones') {
        return operational(<QuotationsWorkspace company={company} />);
    }

    if (moduleKey === 'fel') {
        return operational(<FelWorkspace company={company} />);
    }

    if (moduleKey === 'portal-cliente') {
        return operational(<ClientPortalWorkspace company={company} />);
    }

    if (moduleKey === 'notas-credito') {
        return operational(<CreditNotesWorkspace company={company} />);
    }

    if (moduleKey === 'ordenes-compra') {
        return operational(<PurchaseOrdersWorkspace company={company} />);
    }

    if (moduleKey === 'libros-iva') {
        return operational(<VatBooksWorkspace company={company} />);
    }

    if (moduleKey === 'cierre-periodo') {
        return operational(<PeriodCloseWorkspace company={company} />);
    }

    if (moduleKey === 'estados-cuenta') {
        return operational(<AccountStatementsWorkspace company={company} />);
    }

    if (moduleKey === 'recordatorios') {
        return operational(<RemindersWorkspace company={company} />);
    }

    if (moduleKey === 'auditoria') {
        return operational(<AuditWorkspace company={company} />);
    }

    if (moduleKey === 'usuarios-roles') {
        return operational(<UsersRolesWorkspace company={company} />);
    }

    if (moduleKey === 'configuracion') {
        return operational(<ConfigurationWorkspace company={company} />);
    }

    if (moduleKey !== 'fel' && moduleKey !== 'configuracion') {
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
