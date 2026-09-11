import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleDollarSign,
    CirclePause,
    CircleX,
    FileSpreadsheet,
    FileText,
    Filter,
    FolderKanban,
    Layers3,
    Play,
    Plus,
    ReceiptText,
    Search,
    TrendingUp,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type ProjectStatus = 'running' | 'finished' | 'paused' | 'cancelled';
type Project = {
    code: string;
    name: string;
    client: string;
    start: string;
    end: string;
    budget: number;
    costs: number;
    invoiced: number;
    collected: number;
    progress: number;
    status: ProjectStatus;
};

const projects: Project[] = [
    {
        code: 'PR-2026-001',
        name: 'Implementación sistema contable',
        client: 'Comercial López',
        start: '01/09/2026',
        end: '30/11/2026',
        budget: 120000,
        costs: 52500,
        invoiced: 90000,
        collected: 65000,
        progress: 68,
        status: 'running',
    },
    {
        code: 'PR-2026-002',
        name: 'Desarrollo ecommerce',
        client: 'Tienda Maya',
        start: '15/08/2026',
        end: '15/10/2026',
        budget: 85000,
        costs: 48200,
        invoiced: 60000,
        collected: 30000,
        progress: 45,
        status: 'running',
    },
    {
        code: 'PR-2026-003',
        name: 'Consultoría fiscal',
        client: 'Servicios GT',
        start: '01/07/2026',
        end: '31/08/2026',
        budget: 40000,
        costs: 28500,
        invoiced: 40000,
        collected: 40000,
        progress: 100,
        status: 'finished',
    },
    {
        code: 'PR-2026-004',
        name: 'Migración de datos',
        client: 'Inversiones del Caribe',
        start: '10/09/2026',
        end: '20/12/2026',
        budget: 95000,
        costs: 21400,
        invoiced: 25000,
        collected: 0,
        progress: 22,
        status: 'running',
    },
    {
        code: 'PR-2026-005',
        name: 'Capacitación usuarios',
        client: 'Constructora Rivera',
        start: '05/08/2026',
        end: '30/09/2026',
        budget: 25000,
        costs: 24800,
        invoiced: 25000,
        collected: 25000,
        progress: 100,
        status: 'finished',
    },
    {
        code: 'PR-2026-006',
        name: 'Soporte técnico anual',
        client: 'Hotel del Mar',
        start: '01/06/2026',
        end: '31/12/2026',
        budget: 60000,
        costs: 35600,
        invoiced: 42000,
        collected: 18000,
        progress: 60,
        status: 'running',
    },
    {
        code: 'PR-2026-007',
        name: 'Infraestructura TI',
        client: 'Logística GT',
        start: '12/07/2026',
        end: '30/09/2026',
        budget: 75000,
        costs: 70500,
        invoiced: 50000,
        collected: 20000,
        progress: 80,
        status: 'paused',
    },
    {
        code: 'PR-2026-008',
        name: 'Auditoría de procesos',
        client: 'Exportadora Atlántica',
        start: '01/05/2026',
        end: '31/07/2026',
        budget: 35000,
        costs: 33800,
        invoiced: 35000,
        collected: 35000,
        progress: 100,
        status: 'finished',
    },
];

const months = [
    { label: 'Abr', income: 320000, cost: 290000, margin: 18 },
    { label: 'May', income: 405000, cost: 220000, margin: 14 },
    { label: 'Jun', income: 425000, cost: 250000, margin: 18 },
    { label: 'Jul', income: 430000, cost: 230000, margin: 14 },
    { label: 'Ago', income: 450000, cost: 260000, margin: 19 },
    { label: 'Sep', income: 495000, cost: 285000, margin: 23 },
];

const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        minimumFractionDigits: 2,
    }).format(value);
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const statuses: Record<ProjectStatus, { label: string; style: string }> = {
    running: { label: 'En ejecución', style: 'bg-blue-100 text-blue-700' },
    finished: { label: 'Finalizado', style: 'bg-emerald-100 text-emerald-700' },
    paused: { label: 'En pausa', style: 'bg-amber-100 text-amber-700' },
    cancelled: { label: 'Cancelado', style: 'bg-rose-100 text-rose-700' },
};

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail?: string;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-20 items-start gap-3 p-3`}>
            <span
                className={`grid size-10 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div>
                <p className="text-[10px] leading-4 text-slate-500">{label}</p>
                <strong className="block text-lg text-[#12213d]">
                    {value}
                </strong>
                {detail && (
                    <span className="text-[9px] text-emerald-700">
                        {detail}
                    </span>
                )}
            </div>
        </article>
    );
}

function FinanceChart() {
    const max = 520000;

    return (
        <div className="mt-3">
            <div className="flex justify-end gap-4 text-[9px] text-slate-500">
                <span>
                    ● <b className="text-[#17395f]">Ingresos</b>
                </span>
                <span>
                    ● <b className="text-blue-400">Costos</b>
                </span>
                <span>
                    ● <b className="text-amber-500">Margen %</b>
                </span>
            </div>
            <div className="mt-2 grid grid-cols-[48px_1fr] gap-2">
                <div className="flex h-40 flex-col justify-between pb-5 text-[9px] text-slate-500">
                    <span>Q500k</span>
                    <span>Q250k</span>
                    <span>Q0</span>
                </div>
                <div className="relative flex h-40 items-end border-b border-l border-slate-200 px-2 pb-5">
                    <div className="absolute inset-x-0 top-1/2 h-px bg-slate-100" />
                    {months.map((month) => (
                        <div
                            key={month.label}
                            className="relative flex h-full flex-1 items-end justify-center gap-1"
                        >
                            <span
                                className="w-4 bg-[#17395f] sm:w-7"
                                style={{
                                    height: `${(month.income / max) * 100}%`,
                                }}
                            />
                            <span
                                className="w-4 bg-blue-400 sm:w-7"
                                style={{
                                    height: `${(month.cost / max) * 100}%`,
                                }}
                            />
                            <i
                                className="absolute right-0 left-0 h-0.5 bg-amber-500"
                                style={{
                                    bottom: `${25 + month.margin * 1.7}%`,
                                }}
                            />
                            <i
                                className="absolute size-2 rounded-full bg-amber-500"
                                style={{
                                    bottom: `${23 + month.margin * 1.7}%`,
                                }}
                            />
                            <span className="absolute -bottom-5 text-[9px] text-slate-500">
                                {month.label}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function Donut({ progress = false }: { progress?: boolean }) {
    const background = progress
        ? 'conic-gradient(#3182f6 0 62%, #e8eef5 62% 100%)'
        : 'conic-gradient(#17395f 0 43%, #48c58a 43% 79%, #f4b63e 79% 90%, #ef5350 90% 100%)';

    return (
        <div
            className="grid size-36 place-items-center rounded-full"
            style={{ background }}
        >
            <div className="grid size-20 place-items-center rounded-full bg-white text-center">
                <div>
                    <strong className="block text-xl">
                        {progress ? '62%' : '28'}
                    </strong>
                    <span className="text-[9px] text-slate-500">
                        {progress ? 'Promedio de avance' : 'Total'}
                    </span>
                </div>
            </div>
        </div>
    );
}

export default function ProjectsWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [tab, setTab] = useState('Todos (28)');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const filtered = useMemo(
        () =>
            projects.filter((project) => {
                const matchesQuery =
                    `${project.code} ${project.name} ${project.client}`
                        .toLowerCase()
                        .includes(query.toLowerCase());
                const matchesTab =
                    tab.startsWith('Todos') ||
                    (tab.startsWith('En ejecución') &&
                        project.status === 'running') ||
                    (tab.startsWith('Finalizados') &&
                        project.status === 'finished') ||
                    (tab.startsWith('En pausa') &&
                        project.status === 'paused') ||
                    (tab.startsWith('Cancelados') &&
                        project.status === 'cancelled');

                return matchesQuery && matchesTab;
            }),
        [query, tab],
    );

    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#12213d] sm:p-5">
            <Head title={`Proyectos · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Proyectos</span>
                    <span>›</span>
                    <strong>Vista general</strong>
                </div>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">Proyectos</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Controla la rentabilidad, presupuestos, costos y
                            facturación de todos tus proyectos.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            setNotice('Formulario de nuevo proyecto preparado.')
                        }
                        className="flex items-center gap-2 rounded-lg bg-[#0b3473] px-6 py-2.5 text-xs font-semibold text-white"
                    >
                        <Plus className="size-4" /> Nuevo proyecto
                    </button>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <Check className="size-4" />
                            {notice}
                        </span>
                        <button
                            type="button"
                            aria-label="Cerrar aviso"
                            onClick={() => setNotice('')}
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
                    <Metric
                        icon={FolderKanban}
                        label="Proyectos totales"
                        value="28"
                        detail="↑ 12% vs. mes anterior"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={Play}
                        label="Proyectos en ejecución"
                        value="12"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={CircleCheck}
                        label="Proyectos finalizados"
                        value="10"
                        tone="bg-green-50 text-green-600"
                    />
                    <Metric
                        icon={CirclePause}
                        label="Proyectos en pausa"
                        value="3"
                        tone="bg-amber-50 text-amber-600"
                    />
                    <Metric
                        icon={CircleX}
                        label="Proyectos cancelados"
                        value="3"
                        tone="bg-rose-50 text-rose-600"
                    />
                    <Metric
                        icon={TrendingUp}
                        label="Ingresos proyectados"
                        value={money(1250000)}
                        tone="bg-indigo-50 text-indigo-600"
                    />
                    <Metric
                        icon={CircleDollarSign}
                        label="Costo proyectado"
                        value={money(680000)}
                        tone="bg-emerald-50 text-emerald-600"
                    />
                </section>
                <div className="grid gap-4 xl:grid-cols-[1fr_330px_230px]">
                    <section className={`${panel} p-4`}>
                        <h2 className="text-sm font-bold">
                            Ingresos, costos y rentabilidad (últimos 6 meses)
                        </h2>
                        <FinanceChart />
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="text-sm font-bold">
                            Estado de los proyectos
                        </h2>
                        <div className="mt-3 flex items-center gap-5">
                            <Donut />
                            <div className="flex-1 space-y-3 text-[10px]">
                                {[
                                    [
                                        'En ejecución',
                                        '12 (43%)',
                                        'bg-[#17395f]',
                                    ],
                                    [
                                        'Finalizados',
                                        '10 (36%)',
                                        'bg-emerald-500',
                                    ],
                                    ['En pausa', '3 (11%)', 'bg-amber-400'],
                                    ['Cancelados', '3 (11%)', 'bg-red-500'],
                                ].map(([name, value, color]) => (
                                    <div
                                        key={name}
                                        className="flex items-center gap-2"
                                    >
                                        <i
                                            className={`size-2 rounded-full ${color}`}
                                        />
                                        <span className="flex-1">{name}</span>
                                        <strong>{value}</strong>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                    <section className={`${panel} grid place-items-center p-4`}>
                        <h2 className="justify-self-start text-sm font-bold">
                            Avance general
                        </h2>
                        <Donut progress />
                    </section>
                </div>
                <div className="grid gap-4 xl:grid-cols-[1fr_280px]">
                    <section className={`${panel} overflow-hidden`}>
                        <nav className="flex overflow-x-auto border-b border-slate-200 px-2">
                            {[
                                'Todos (28)',
                                'En ejecución (12)',
                                'Finalizados (10)',
                                'En pausa (3)',
                                'Cancelados (3)',
                            ].map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => setTab(item)}
                                    className={`border-b-2 px-4 py-3 text-xs font-semibold whitespace-nowrap ${tab === item ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {item}
                                </button>
                            ))}
                        </nav>
                        <div className="grid gap-2 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_.7fr_.7fr_.7fr_auto]">
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar proyecto por código, nombre o cliente..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-blue-500"
                                />
                            </label>
                            {[
                                'Todos los clientes',
                                'Todos los responsables',
                                'Todos los estados',
                            ].map((label) => (
                                <button
                                    key={label}
                                    type="button"
                                    className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                                >
                                    {label}
                                    <ChevronDown className="size-4" />
                                </button>
                            ))}
                            <button
                                type="button"
                                className="flex items-center justify-center gap-2 rounded-lg border border-blue-500 px-4 py-2.5 text-xs font-semibold text-blue-700"
                            >
                                <Filter className="size-4" /> Más filtros
                            </button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1120px] text-left text-[9px]">
                                <thead className="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th className="px-3 py-2">#</th>
                                        <th>Código</th>
                                        <th>Proyecto</th>
                                        <th>Cliente</th>
                                        <th>Fecha inicio</th>
                                        <th>Fecha fin</th>
                                        <th>Presupuesto</th>
                                        <th>Costos</th>
                                        <th>Facturado</th>
                                        <th>Cobrado</th>
                                        <th>Avance</th>
                                        <th>Estado</th>
                                        <th className="pr-3 text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((project, index) => (
                                        <tr
                                            key={project.code}
                                            className="hover:bg-blue-50"
                                        >
                                            <td className="px-3 py-2">
                                                {index + 1}
                                            </td>
                                            <td className="font-semibold text-blue-700">
                                                {project.code}
                                            </td>
                                            <td>{project.name}</td>
                                            <td>{project.client}</td>
                                            <td>{project.start}</td>
                                            <td>{project.end}</td>
                                            <td>{money(project.budget)}</td>
                                            <td>{money(project.costs)}</td>
                                            <td>{money(project.invoiced)}</td>
                                            <td>{money(project.collected)}</td>
                                            <td>
                                                <span className="flex items-center gap-2">
                                                    <i className="h-1.5 w-16 overflow-hidden rounded-full bg-slate-200">
                                                        <i
                                                            className={`block h-full ${project.progress === 100 ? 'bg-emerald-500' : 'bg-blue-600'}`}
                                                            style={{
                                                                width: `${project.progress}%`,
                                                            }}
                                                        />
                                                    </i>
                                                    {project.progress}%
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-2 py-1 ${statuses[project.status].style}`}
                                                >
                                                    ●{' '}
                                                    {
                                                        statuses[project.status]
                                                            .label
                                                    }
                                                </span>
                                            </td>
                                            <td className="pr-3 text-right">
                                                •••
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {filtered.length === 0 && (
                                <div className="p-8 text-center text-xs text-slate-400">
                                    No hay proyectos que coincidan con los
                                    filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-3 text-[10px] text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 28 proyectos
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    className="grid size-7 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronLeft className="size-3" />
                                </button>
                                {['1', '2', '3', '4'].map((page) => (
                                    <button
                                        key={page}
                                        type="button"
                                        className={`grid size-7 place-items-center rounded ${page === '1' ? 'bg-blue-600 text-white' : 'border border-slate-200'}`}
                                    >
                                        {page}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className="grid size-7 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronRight className="size-3" />
                                </button>
                            </div>
                            <button
                                type="button"
                                className="rounded border border-slate-200 px-3 py-2"
                            >
                                8 por página⌄
                            </button>
                        </footer>
                    </section>
                    <aside className="space-y-4">
                        <section className={`${panel} p-4`}>
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-bold">
                                    Próximos hitos
                                </h2>
                                <button
                                    type="button"
                                    className="text-[10px] text-blue-600"
                                >
                                    Ver todos
                                </button>
                            </div>
                            <div className="mt-3 space-y-2">
                                {[
                                    [
                                        'SEP',
                                        '12',
                                        'Entrega Fase 2',
                                        'PR-2026-001',
                                    ],
                                    [
                                        'SEP',
                                        '18',
                                        'Reunión de avance',
                                        'PR-2026-002',
                                    ],
                                    [
                                        'SEP',
                                        '25',
                                        'Facturación parcial',
                                        'PR-2026-004',
                                    ],
                                    [
                                        'SEP',
                                        '30',
                                        'Cierre de proyecto',
                                        'PR-2026-006',
                                    ],
                                ].map(([month, day, title, code]) => (
                                    <button
                                        type="button"
                                        key={title}
                                        onClick={() =>
                                            setNotice(`${title} abierto.`)
                                        }
                                        className="flex w-full items-center gap-3 rounded-lg border border-slate-200 p-2 text-left hover:bg-blue-50"
                                    >
                                        <span className="grid size-10 place-items-center rounded bg-rose-50 text-[8px] font-bold text-rose-600">
                                            {month}
                                            <strong className="block text-sm text-slate-800">
                                                {day}
                                            </strong>
                                        </span>
                                        <span>
                                            <strong className="block text-[10px]">
                                                {title}
                                            </strong>
                                            <span className="text-[9px] text-slate-500">
                                                {code}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </section>
                        <section className={`${panel} p-4`}>
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-bold">
                                    Alertas y recomendaciones
                                </h2>
                                <button
                                    type="button"
                                    className="text-[10px] text-blue-600"
                                >
                                    Ver todas
                                </button>
                            </div>
                            <div className="mt-2 divide-y divide-slate-100">
                                {[
                                    [
                                        AlertTriangle,
                                        'Proyecto PR-2026-007 supera 90% del presupuesto de costos.',
                                        'text-rose-600',
                                    ],
                                    [
                                        AlertTriangle,
                                        'Hay 3 proyectos con facturas pendientes de emitir.',
                                        'text-amber-600',
                                    ],
                                    [
                                        BarChart3,
                                        'El margen del proyecto PR-2026-001 está 8% debajo de lo esperado.',
                                        'text-blue-600',
                                    ],
                                    [
                                        CircleCheck,
                                        '2 proyectos listos para cerrar contablemente.',
                                        'text-emerald-600',
                                    ],
                                ].map(([Icon, text, tone]) => {
                                    const AlertIcon = Icon as LucideIcon;

                                    return (
                                        <div
                                            key={String(text)}
                                            className="flex gap-2 py-3"
                                        >
                                            <AlertIcon
                                                className={`size-4 shrink-0 ${tone}`}
                                            />
                                            <p className="text-[10px]">
                                                {String(text)}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    </aside>
                </div>
                <div className="grid gap-4 xl:grid-cols-3">
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <ReceiptText className="size-4 text-blue-600" />{' '}
                                Últimos gastos registrados
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todos
                            </button>
                        </div>
                        <div className="mt-3 divide-y divide-slate-100 text-[10px]">
                            {[
                                [
                                    '10/09/2026',
                                    'PR-2026-001',
                                    'Licencia SQL Server',
                                    'Q8,500.00',
                                ],
                                [
                                    '09/09/2026',
                                    'PR-2026-004',
                                    'Viáticos de viaje',
                                    'Q1,200.00',
                                ],
                                [
                                    '08/09/2026',
                                    'PR-2026-002',
                                    'Servicios de diseño',
                                    'Q4,800.00',
                                ],
                                [
                                    '05/09/2026',
                                    'PR-2026-006',
                                    'Renovación de licencia',
                                    'Q2,500.00',
                                ],
                                [
                                    '03/09/2026',
                                    'PR-2026-007',
                                    'Transporte y logística',
                                    'Q3,200.00',
                                ],
                            ].map((row) => (
                                <div
                                    key={row[0] + row[1]}
                                    className="grid grid-cols-[75px_85px_1fr_auto] gap-2 py-2"
                                >
                                    <span>{row[0]}</span>
                                    <span className="text-blue-600">
                                        {row[1]}
                                    </span>
                                    <span>{row[2]}</span>
                                    <strong>{row[3]}</strong>
                                </div>
                            ))}
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <Layers3 className="size-4 text-blue-600" />{' '}
                                Facturación reciente
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todos
                            </button>
                        </div>
                        <div className="mt-3 divide-y divide-slate-100 text-[10px]">
                            {[
                                [
                                    '10/09/2026',
                                    'PR-2026-001',
                                    'FAC-1254',
                                    'Q30,000.00',
                                    'Cobrado',
                                ],
                                [
                                    '05/09/2026',
                                    'PR-2026-002',
                                    'FAC-1253',
                                    'Q15,000.00',
                                    'Pendiente',
                                ],
                                [
                                    '01/09/2026',
                                    'PR-2026-006',
                                    'FAC-1252',
                                    'Q12,000.00',
                                    'Cobrado',
                                ],
                                [
                                    '28/08/2026',
                                    'PR-2026-004',
                                    'FAC-1251',
                                    'Q25,000.00',
                                    'Pendiente',
                                ],
                                [
                                    '20/08/2026',
                                    'PR-2026-003',
                                    'FAC-1250',
                                    'Q40,000.00',
                                    'Cobrado',
                                ],
                            ].map((row) => (
                                <div
                                    key={row[2]}
                                    className="grid grid-cols-[75px_85px_70px_1fr_auto] gap-2 py-2"
                                >
                                    <span>{row[0]}</span>
                                    <span className="text-blue-600">
                                        {row[1]}
                                    </span>
                                    <span className="text-blue-600">
                                        {row[2]}
                                    </span>
                                    <strong>{row[3]}</strong>
                                    <span
                                        className={`rounded-full px-2 py-0.5 ${row[4] === 'Cobrado' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}
                                    >
                                        ● {row[4]}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <FileText className="size-4 text-blue-600" />{' '}
                            Documentos del proyecto
                        </h2>
                        <div className="mt-3 divide-y divide-slate-100 text-[10px]">
                            {[
                                [
                                    'Contrato_Comercial_Lopez.pdf',
                                    '1.2 MB',
                                    '01/09/2026',
                                    FileText,
                                ],
                                [
                                    'Propuesta_Tecnica.pdf',
                                    '850 KB',
                                    '28/08/2026',
                                    FileText,
                                ],
                                [
                                    'Cronograma_Implementacion.xlsx',
                                    '450 KB',
                                    '25/08/2026',
                                    FileSpreadsheet,
                                ],
                                [
                                    'Informe_Avance_Sept.pdf',
                                    '620 KB',
                                    '15/09/2026',
                                    FileText,
                                ],
                                [
                                    'Acta_Reunion_Cliente.pdf',
                                    '300 KB',
                                    '10/09/2026',
                                    FileText,
                                ],
                            ].map(([name, size, date, Icon]) => {
                                const DocumentIcon = Icon as LucideIcon;

                                return (
                                    <div
                                        key={String(name)}
                                        className="grid grid-cols-[18px_1fr_auto_auto] gap-2 py-2"
                                    >
                                        <DocumentIcon className="size-4 text-red-500" />
                                        <span className="text-blue-600">
                                            {String(name)}
                                        </span>
                                        <span>{String(size)}</span>
                                        <span>{String(date)}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Gestión de proyectos</span>
                    <span>Datos actualizados al 10/09/2026</span>
                </footer>
            </div>
        </main>
    );
}
