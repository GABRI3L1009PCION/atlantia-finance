import { Head } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    BookOpen,
    CalendarDays,
    ChartPie,
    ChevronDown,
    Download,
    Eye,
    FileBarChart,
    FileText,
    PackageSearch,
    Plus,
    Search,
    Star,
    Upload,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { Company } from '@/components/accounting-section';
import * as routes from '@/routes/accounting';

type Row = {
    id: number;
    code: string;
    name: string;
    type: string;
    net: number;
};
type Statements = {
    incomeRows: Row[];
    positionRows: Row[];
    income: number;
    expenses: number;
    profit: number;
    assets: number;
    liabilities: number;
    equity: number;
    accumulated: number;
    difference: number;
};

type RecentReport = {
    name: string;
    category: string;
    company: string;
    period: string;
    format: 'PDF' | 'XLSX';
    owner: string;
    status: 'Completado' | 'Procesando';
    kind: string;
};

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const recentReports: RecentReport[] = [
    {
        name: 'Estado de Resultados',
        category: 'Financieros',
        company: 'Comercial Atlántida, S.A.',
        period: 'Ene - Mar 2025',
        format: 'PDF',
        owner: 'María Contreras',
        status: 'Completado',
        kind: 'income',
    },
    {
        name: 'Libro Mayor',
        category: 'Contables',
        company: 'Comercial Atlántida, S.A.',
        period: 'Marzo 2025',
        format: 'XLSX',
        owner: 'Juan Pérez',
        status: 'Completado',
        kind: 'ledger',
    },
    {
        name: 'Declaración IVA',
        category: 'Fiscales',
        company: 'Comercial Atlántida, S.A.',
        period: 'Marzo 2025',
        format: 'PDF',
        owner: 'María Contreras',
        status: 'Completado',
        kind: 'trial',
    },
    {
        name: 'Auxiliar de Cuentas',
        category: 'Contables',
        company: 'Inversiones del Sur, S.A.',
        period: 'Ene - Mar 2025',
        format: 'XLSX',
        owner: 'Carlos Méndez',
        status: 'Procesando',
        kind: 'ledger',
    },
    {
        name: 'Reporte de Inventario',
        category: 'Inventario',
        company: 'Comercial Atlántida, S.A.',
        period: 'Marzo 2025',
        format: 'PDF',
        owner: 'Ana López',
        status: 'Completado',
        kind: 'trial',
    },
    {
        name: 'Costos por Proyecto',
        category: 'Proyectos',
        company: 'Desarrollos Atlántida, S.A.',
        period: 'Ene - Mar 2025',
        format: 'XLSX',
        owner: 'Luis Ramírez',
        status: 'Completado',
        kind: 'income',
    },
    {
        name: 'Planilla de Nómina',
        category: 'Nómina',
        company: 'Comercial Atlántida, S.A.',
        period: 'Marzo 2025',
        format: 'PDF',
        owner: 'Sofía Herrera',
        status: 'Completado',
        kind: 'journal',
    },
    {
        name: 'Situación Financiera',
        category: 'Financieros',
        company: 'Inversiones del Sur, S.A.',
        period: 'Marzo 2025',
        format: 'PDF',
        owner: 'Juan Pérez',
        status: 'Completado',
        kind: 'balance',
    },
];

const categoryStyles: Record<string, string> = {
    Financieros: 'bg-amber-100 text-amber-800',
    Contables: 'bg-indigo-100 text-indigo-700',
    Fiscales: 'bg-violet-100 text-violet-700',
    Inventario: 'bg-orange-100 text-orange-700',
    Proyectos: 'bg-purple-100 text-purple-700',
    Nómina: 'bg-fuchsia-100 text-fuchsia-700',
};

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    alert = false,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
    alert?: boolean;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-center gap-4 p-4`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div>
                <p className="text-sm font-medium">{label}</p>
                <div className="mt-1 flex items-baseline gap-3">
                    <strong className="text-3xl text-slate-950">{value}</strong>
                    <span
                        className={`text-sm font-semibold ${alert ? 'text-red-500' : 'text-emerald-600'}`}
                    >
                        ↑ {detail}
                    </span>
                </div>
                <p className="text-xs text-slate-500">
                    {alert ? 'requieren atención' : 'vs. período anterior'}
                </p>
            </div>
        </article>
    );
}

export default function Reports({
    company,
    filters,
    statements,
}: {
    company: Company;
    filters: { from: string; to: string };
    statements: Statements;
}) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('Todos');
    const [notice, setNotice] = useState('');
    const [scheduled, setScheduled] = useState<Record<string, boolean>>({
        'Estados financieros mensuales': true,
        'Declaración IVA': true,
        'Reporte de inventario': true,
        'Resumen ejecutivo': false,
    });
    const [favorites, setFavorites] = useState([
        'Estado de Resultados',
        'Balance General',
        'Libro Mayor',
        'Declaración IVA',
        'Auxiliar de Cuentas',
    ]);
    const filtered = useMemo(
        () =>
            recentReports.filter(
                (report) =>
                    (category === 'Todos' || report.category === category) &&
                    `${report.name} ${report.category} ${report.company} ${report.owner}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [category, query],
    );
    const exportUrl = (report: RecentReport) =>
        routes.exportMethod.url(company.public_id, {
            query: {
                ...filters,
                kind: report.kind,
                format: report.format.toLowerCase(),
            },
        });

    return (
        <main className="min-h-full bg-[#f6f8fb] p-3 text-[#261c2a] sm:p-6">
            <Head title={`Reportes · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-5">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Inicio</span>
                    <span>›</span>
                    <strong>Reportes</strong>
                </div>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold text-[#640811]">
                            Reportes
                        </h1>
                        <p className="mt-1 text-base text-slate-500">
                            Análisis contable, financiero y fiscal
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <blockquote className="mr-3 hidden text-right text-xs text-slate-500 italic xl:block">
                            “Información confiable, decisiones más fuertes.”
                            <br />
                            <span>— Atlantia Finance</span>
                        </blockquote>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Asistente de generación de reportes abierto.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-[#6e0008] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Plus className="size-4" /> Generar reporte
                        </button>
                    </div>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-[#ead6d8] bg-[#fff3f4] px-4 py-2 text-xs text-[#6e0008]">
                        <span>{notice}</span>
                        <button
                            type="button"
                            aria-label="Cerrar aviso"
                            onClick={() => setNotice('')}
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={FileText}
                        label="Reportes generados"
                        value="248"
                        detail="12%"
                        tone="bg-rose-50 text-[#6e0008]"
                    />
                    <Metric
                        icon={CalendarDays}
                        label="Reportes programados"
                        value="18"
                        detail="29%"
                        tone="bg-rose-50 text-[#6e0008]"
                    />
                    <Metric
                        icon={Upload}
                        label="Exportaciones"
                        value="412"
                        detail="8%"
                        tone="bg-rose-50 text-[#6e0008]"
                    />
                    <Metric
                        icon={Bell}
                        label="Alertas"
                        value="3"
                        detail="50%"
                        tone="bg-rose-50 text-[#6e0008]"
                        alert
                    />
                </section>
                <section
                    className={`${panel} grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[.75fr_.8fr_.85fr_.8fr_1fr_auto_auto]`}
                >
                    {[
                        ['Tipo de reporte', 'Todos'],
                        ['Período', 'Enero - Marzo 2025'],
                        ['Empresa', 'Todas las empresas'],
                        ['Formato', 'Todos'],
                        ['Centro de costos', 'Todos'],
                    ].map(([label, value], index) => (
                        <label key={label} className="text-[10px] font-medium">
                            {label}
                            <button
                                type="button"
                                onClick={() =>
                                    index === 0 &&
                                    setCategory(
                                        category === 'Todos'
                                            ? 'Financieros'
                                            : 'Todos',
                                    )
                                }
                                className="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-left text-xs font-normal"
                            >
                                {index === 0 ? category : value}
                                <ChevronDown className="size-4" />
                            </button>
                        </label>
                    ))}
                    <button
                        type="button"
                        onClick={() => {
                            setCategory('Todos');
                            setQuery('');
                        }}
                        className="self-end rounded-lg border border-slate-200 px-4 py-2.5 text-xs"
                    >
                        Limpiar filtros
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            setNotice(
                                `${filtered.length} reportes encontrados.`,
                            )
                        }
                        className="flex items-center justify-center gap-2 self-end rounded-lg bg-[#6e0008] px-5 py-2.5 text-xs font-semibold text-white"
                    >
                        <Search className="size-4" /> Buscar
                    </button>
                </section>
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-lg font-bold">
                            Categorías de reportes
                        </h2>
                        <button
                            type="button"
                            className="text-xs font-medium text-[#6e0008]"
                        >
                            Ver biblioteca completa →
                        </button>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                        {[
                            [
                                BookOpen,
                                'Contables',
                                'Balances, libros y auxiliares contables',
                                '24 reportes',
                            ],
                            [
                                BarChart3,
                                'Financieros',
                                'Estados financieros y análisis',
                                '18 reportes',
                            ],
                            [
                                FileText,
                                'Fiscales',
                                'Reportes SAT, IVA, ISR y cumplimiento',
                                '16 reportes',
                            ],
                            [
                                PackageSearch,
                                'Inventario',
                                'Movimientos, valuaciones y existencias',
                                '12 reportes',
                            ],
                            [
                                Users,
                                'Nómina',
                                'Planillas, provisiones y obligaciones',
                                '10 reportes',
                            ],
                            [
                                ChartPie,
                                'Proyectos',
                                'Costos, presupuestos y rentabilidad',
                                '8 reportes',
                            ],
                        ].map(([Icon, name, description, count]) => {
                            const CategoryIcon = Icon as LucideIcon;

                            return (
                                <button
                                    key={String(name)}
                                    type="button"
                                    onClick={() => setCategory(String(name))}
                                    className={`${panel} flex gap-3 p-4 text-left transition hover:border-[#7c111a] ${category === name ? 'ring-2 ring-[#7c111a]/20' : ''}`}
                                >
                                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-rose-50 text-[#6e0008]">
                                        <CategoryIcon className="size-5" />
                                    </span>
                                    <span>
                                        <strong className="block text-sm">
                                            {String(name)}
                                        </strong>
                                        <span className="mt-1 block text-xs text-slate-500">
                                            {String(description)}
                                        </span>
                                        <span className="mt-2 block text-[10px] text-slate-500">
                                            {String(count)}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </section>
                <div className="grid gap-4 xl:grid-cols-[1fr_410px]">
                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                            <h2 className="flex items-center gap-2 text-base font-bold">
                                <FileText className="size-5 text-[#6e0008]" />{' '}
                                Reportes recientes
                            </h2>
                            <div className="flex gap-2">
                                <label className="relative">
                                    <Search className="absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-slate-400" />
                                    <input
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        placeholder="Buscar reporte..."
                                        className="w-44 rounded-lg border border-slate-200 py-2 pr-3 pl-8 text-[10px] outline-none focus:border-[#7c111a]"
                                    />
                                </label>
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 text-[10px]"
                                >
                                    Ver todos los reportes →
                                </button>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[950px] text-left text-[10px]">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2">Reporte</th>
                                        <th>Categoría</th>
                                        <th>Empresa</th>
                                        <th>Período</th>
                                        <th>Formato</th>
                                        <th>Generado por</th>
                                        <th>Estado</th>
                                        <th className="pr-4 text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((report) => (
                                        <tr
                                            key={report.name}
                                            className="hover:bg-rose-50/40"
                                        >
                                            <td className="px-4 py-2 font-medium">
                                                <FileBarChart className="mr-2 inline size-4 text-[#6e0008]" />
                                                {report.name}
                                            </td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-2 py-1 ${categoryStyles[report.category]}`}
                                                >
                                                    {report.category}
                                                </span>
                                            </td>
                                            <td>{report.company}</td>
                                            <td>{report.period}</td>
                                            <td>
                                                <span
                                                    className={`rounded px-2 py-1 font-semibold ${report.format === 'PDF' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'}`}
                                                >
                                                    {report.format}
                                                </span>
                                            </td>
                                            <td>{report.owner}</td>
                                            <td>
                                                <span
                                                    className={
                                                        report.status ===
                                                        'Completado'
                                                            ? 'text-emerald-700'
                                                            : 'text-amber-700'
                                                    }
                                                >
                                                    ● {report.status}
                                                </span>
                                            </td>
                                            <td className="pr-4">
                                                <div className="flex justify-end gap-3">
                                                    <button
                                                        type="button"
                                                        aria-label={`Ver ${report.name}`}
                                                        onClick={() =>
                                                            setNotice(
                                                                `${report.name} abierto en vista previa.`,
                                                            )
                                                        }
                                                    >
                                                        <Eye className="size-3.5" />
                                                    </button>
                                                    <a
                                                        aria-label={`Descargar ${report.name}`}
                                                        href={exportUrl(report)}
                                                    >
                                                        <Download className="size-3.5" />
                                                    </a>
                                                    <button type="button">
                                                        •••
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {filtered.length === 0 && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay reportes que coincidan con los
                                    filtros.
                                </div>
                            )}
                        </div>
                    </section>
                    <aside className="space-y-4">
                        <section className={`${panel} p-4`}>
                            <div className="flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-base font-bold">
                                    <CalendarDays className="size-5 text-[#6e0008]" />{' '}
                                    Reportes programados
                                </h2>
                                <button
                                    type="button"
                                    className="text-[10px] text-[#6e0008]"
                                >
                                    Ver todos →
                                </button>
                            </div>
                            <div className="mt-3 divide-y divide-slate-100">
                                {[
                                    [
                                        'Estados financieros mensuales',
                                        'Día 5 de cada mes | PDF',
                                    ],
                                    [
                                        'Declaración IVA',
                                        'Día 12 de cada mes | PDF',
                                    ],
                                    [
                                        'Reporte de inventario',
                                        'Día 1 de cada mes | XLSX',
                                    ],
                                    ['Resumen ejecutivo', 'Cada lunes | PDF'],
                                ].map(([name, detail]) => (
                                    <div
                                        key={name}
                                        className="flex items-center gap-3 py-2"
                                    >
                                        <span className="grid size-7 place-items-center rounded bg-rose-50">
                                            <FileBarChart className="size-4 text-[#6e0008]" />
                                        </span>
                                        <span className="flex-1">
                                            <strong className="block text-[11px]">
                                                {name}
                                            </strong>
                                            <span className="text-[10px] text-slate-500">
                                                {detail}
                                            </span>
                                        </span>
                                        <button
                                            type="button"
                                            aria-label={`Activar ${name}`}
                                            onClick={() =>
                                                setScheduled((current) => ({
                                                    ...current,
                                                    [name]: !current[name],
                                                }))
                                            }
                                            className={`relative h-5 w-9 rounded-full transition ${scheduled[name] ? 'bg-emerald-600' : 'bg-slate-300'}`}
                                        >
                                            <i
                                                className={`absolute top-0.5 size-4 rounded-full bg-white transition ${scheduled[name] ? 'left-[18px]' : 'left-0.5'}`}
                                            />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </section>
                        <section className={`${panel} p-4`}>
                            <div className="flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-base font-bold">
                                    <Star className="size-5 text-[#6e0008]" />{' '}
                                    Reportes favoritos
                                </h2>
                                <button
                                    type="button"
                                    className="text-[10px] text-[#6e0008]"
                                >
                                    Ver todos →
                                </button>
                            </div>
                            <div className="mt-3 divide-y divide-slate-100">
                                {favorites.map((name, index) => (
                                    <div
                                        key={name}
                                        className="flex items-center gap-3 py-2"
                                    >
                                        <Star className="size-3.5 fill-[#6e0008] text-[#6e0008]" />
                                        <span className="flex-1 text-[11px]">
                                            {name}
                                        </span>
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-[9px] text-slate-500">
                                            {index < 2
                                                ? 'Financieros'
                                                : index === 2
                                                  ? 'Contables'
                                                  : 'Fiscales'}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label={`Quitar ${name} de favoritos`}
                                            onClick={() =>
                                                setFavorites((current) =>
                                                    current.filter(
                                                        (item) => item !== name,
                                                    ),
                                                )
                                            }
                                        >
                                            •••
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </section>
                        <section className="rounded-xl bg-gradient-to-br from-[#6e0008] to-[#370005] p-4 text-white">
                            <h2 className="text-sm font-bold">
                                Resumen contable actual
                            </h2>
                            <div className="mt-3 grid grid-cols-2 gap-2 text-[10px]">
                                <span>
                                    Ingresos
                                    <strong className="block text-base">
                                        Q
                                        {(
                                            statements.income / 100
                                        ).toLocaleString('es-GT')}
                                    </strong>
                                </span>
                                <span>
                                    Resultado
                                    <strong className="block text-base">
                                        Q
                                        {(
                                            statements.profit / 100
                                        ).toLocaleString('es-GT')}
                                    </strong>
                                </span>
                            </div>
                        </section>
                    </aside>
                </div>
                <footer className="flex flex-wrap items-center justify-between gap-2 text-[10px] text-slate-400">
                    <span>{company.name} · Centro de reportes</span>
                    <span>
                        Período consultado: {filters.from} — {filters.to}
                    </span>
                </footer>
            </div>
        </main>
    );
}
