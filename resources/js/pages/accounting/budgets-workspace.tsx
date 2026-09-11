import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Check,
    ChevronDown,
    CircleCheck,
    CircleDollarSign,
    Download,
    FileBarChart,
    FolderKanban,
    Info,
    Layers3,
    Plus,
    Target,
    TrendingUp,
    WalletCards,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        minimumFractionDigits: 2,
    }).format(value);

const months = [
    {
        month: 'Ene',
        budgetIncome: 230,
        realIncome: 240,
        budgetExpense: 145,
        realExpense: 120,
    },
    {
        month: 'Feb',
        budgetIncome: 275,
        realIncome: 220,
        budgetExpense: 130,
        realExpense: 110,
    },
    {
        month: 'Mar',
        budgetIncome: 290,
        realIncome: 235,
        budgetExpense: 145,
        realExpense: 125,
    },
    {
        month: 'Abr',
        budgetIncome: 350,
        realIncome: 330,
        budgetExpense: 150,
        realExpense: 130,
    },
    {
        month: 'May',
        budgetIncome: 295,
        realIncome: 235,
        budgetExpense: 135,
        realExpense: 115,
    },
    {
        month: 'Jun',
        budgetIncome: 275,
        realIncome: 240,
        budgetExpense: 155,
        realExpense: 130,
    },
    {
        month: 'Jul',
        budgetIncome: 290,
        realIncome: 250,
        budgetExpense: 145,
        realExpense: 125,
    },
    {
        month: 'Ago',
        budgetIncome: 325,
        realIncome: 245,
        budgetExpense: 160,
        realExpense: 140,
    },
    {
        month: 'Sep',
        budgetIncome: 370,
        realIncome: 255,
        budgetExpense: 170,
        realExpense: 150,
    },
    {
        month: 'Oct',
        budgetIncome: 300,
        realIncome: 260,
        budgetExpense: 175,
        realExpense: 155,
    },
    {
        month: 'Nov',
        budgetIncome: 345,
        realIncome: 300,
        budgetExpense: 195,
        realExpense: 175,
    },
    {
        month: 'Dic',
        budgetIncome: 380,
        realIncome: 305,
        budgetExpense: 200,
        realExpense: 180,
    },
];

const categories = [
    ['Nómina', '27%', 'Q648,000', '#17395f'],
    ['Operación', '22%', 'Q528,000', '#3b82f6'],
    ['Administración', '18%', 'Q432,000', '#60a5fa'],
    ['Ventas y marketing', '15%', 'Q360,000', '#f2ad32'],
    ['Proyectos', '10%', 'Q240,000', '#ef4444'],
    ['Inversiones', '6%', 'Q144,000', '#e4bd79'],
    ['Otros', '2%', 'Q48,000', '#94a3b8'],
];

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    progress,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail?: string;
    tone: string;
    progress?: number;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-start gap-3 p-4`}>
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[11px] text-slate-500">{label}</p>
                <strong className="mt-1 block text-xl text-[#12213d]">
                    {value}
                </strong>
                {progress !== undefined && (
                    <div className="mt-2 flex items-center gap-2">
                        <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200">
                            <i
                                className="block h-full rounded-full bg-emerald-500"
                                style={{ width: `${progress}%` }}
                            />
                        </span>
                        <span className="text-[9px] text-slate-500">
                            {progress}%
                        </span>
                    </div>
                )}
                {detail && (
                    <p className="mt-1 text-[10px] text-emerald-700">
                        {detail}
                    </p>
                )}
            </div>
        </article>
    );
}

function BudgetChart() {
    return (
        <div className="mt-3">
            <div className="flex flex-wrap justify-end gap-4 text-[9px] text-slate-500">
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-[#17395f]" />
                    Ingresos presupuestados
                </span>
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-blue-400" />
                    Ingresos reales
                </span>
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-amber-500" />
                    Gastos presupuestados
                </span>
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-amber-200" />
                    Gastos reales
                </span>
            </div>
            <div className="mt-3 grid grid-cols-[48px_1fr] gap-2">
                <div className="flex h-44 flex-col justify-between pb-5 text-[9px] text-slate-500">
                    <span>Q400k</span>
                    <span>Q200k</span>
                    <span>Q0</span>
                </div>
                <div className="relative flex h-44 items-end border-b border-l border-slate-200 px-1 pb-5">
                    <div className="absolute inset-x-0 top-1/2 h-px bg-slate-100" />
                    {months.map((item) => (
                        <div
                            key={item.month}
                            className="relative flex h-full flex-1 items-end justify-center gap-px"
                        >
                            <span
                                className="w-2 bg-[#17395f] lg:w-3"
                                style={{ height: `${item.budgetIncome / 4}%` }}
                            />
                            <span
                                className="w-2 bg-blue-400 lg:w-3"
                                style={{ height: `${item.realIncome / 4}%` }}
                            />
                            <span
                                className="w-2 bg-amber-500 lg:w-3"
                                style={{ height: `${item.budgetExpense / 4}%` }}
                            />
                            <span
                                className="w-2 bg-amber-200 lg:w-3"
                                style={{ height: `${item.realExpense / 4}%` }}
                            />
                            <span className="absolute -bottom-5 text-[8px] text-slate-500">
                                {item.month}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function DistributionDonut() {
    return (
        <div className="mt-4 flex flex-col items-center gap-5 sm:flex-row">
            <div
                className="grid size-40 shrink-0 place-items-center rounded-full"
                style={{
                    background:
                        'conic-gradient(#17395f 0 27%, #3b82f6 27% 49%, #60a5fa 49% 67%, #f2ad32 67% 82%, #ef4444 82% 92%, #e4bd79 92% 98%, #94a3b8 98% 100%)',
                }}
            >
                <div className="grid size-24 place-items-center rounded-full bg-white text-center">
                    <div>
                        <strong className="block text-lg">Q2,400,000</strong>
                        <span className="text-[9px] text-slate-500">Total</span>
                    </div>
                </div>
            </div>
            <div className="w-full divide-y divide-slate-100 text-[10px]">
                {categories.map(([name, percent, value, color]) => (
                    <div
                        key={name}
                        className="grid grid-cols-[1fr_auto_auto] gap-4 py-1.5"
                    >
                        <span>
                            <i
                                className="mr-2 inline-block size-2 rounded-full"
                                style={{ backgroundColor: color }}
                            />
                            {name}
                        </span>
                        <span>{percent}</span>
                        <strong>{value}</strong>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ProgressRows({ type }: { type: 'cost' | 'project' }) {
    const rows =
        type === 'cost'
            ? [
                  ['Administración', '92%', 'Q432,000', 'Q397,500'],
                  ['Ventas', '76%', 'Q360,000', 'Q273,800'],
                  ['Operaciones', '68%', 'Q528,000', 'Q358,200'],
                  ['Tecnología', '54%', 'Q240,000', 'Q129,800'],
                  ['Proyectos', '48%', 'Q240,000', 'Q115,200'],
                  ['Dirección', '35%', 'Q120,000', 'Q42,500'],
              ]
            : [
                  ['PR-2026-001 · Sistema contable', '80%', '', ''],
                  ['PR-2026-002 · E-commerce', '62%', '', ''],
                  ['PR-2026-003 · Consultoría fiscal', '45%', '', ''],
                  ['PR-2026-004 · Migración de datos', '92%', '', ''],
                  ['PR-2026-005 · Soporte anual', '38%', '', ''],
              ];

    return (
        <div className="mt-3 space-y-2">
            {rows.map((row, index) => {
                const pct = Number(row[1].replace('%', ''));

                return (
                    <div
                        key={row[0]}
                        className={`grid items-center gap-3 text-[10px] ${type === 'cost' ? 'grid-cols-[16px_95px_1fr_45px_75px_75px]' : 'grid-cols-[16px_140px_1fr_40px]'}`}
                    >
                        <span>{index + 1}</span>
                        <span>{row[0]}</span>
                        <span className="h-3 overflow-hidden rounded-full bg-slate-200">
                            <i
                                className={`block h-full rounded-full ${pct >= 90 ? 'bg-red-500' : pct >= 75 ? 'bg-amber-400' : 'bg-blue-500'}`}
                                style={{ width: `${pct}%` }}
                            />
                        </span>
                        <strong className={pct >= 90 ? 'text-red-600' : ''}>
                            {row[1]}
                        </strong>
                        {type === 'cost' && (
                            <>
                                <span>{row[2]}</span>
                                <span>{row[3]}</span>
                            </>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export default function BudgetsWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [tab, setTab] = useState('Vista general');
    const [notice, setNotice] = useState('');
    const action = (message: string) => setNotice(message);

    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#12213d] sm:p-5">
            <Head title={`Presupuestos · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Presupuestos</span>
                    <span>›</span>
                    <strong>Vista general</strong>
                </div>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">Presupuestos</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Planifica, controla y analiza el desempeño
                            financiero de tu empresa.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                action('Presupuesto anual exportado.')
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-5 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <Download className="size-4" /> Exportar{' '}
                            <ChevronDown className="size-3" />
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                action(
                                    'Formulario de nuevo presupuesto preparado.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-[#0b3473] px-6 py-2.5 text-xs font-semibold text-white"
                        >
                            <Plus className="size-4" /> Nuevo presupuesto
                        </button>
                    </div>
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
                <nav className={`${panel} flex overflow-x-auto px-2`}>
                    {[
                        'Vista general',
                        'Presupuestos',
                        'Partidas',
                        'Centros de costos',
                        'Proyectos',
                        'Flujo de efectivo',
                        'Reportes',
                    ].map((item) => (
                        <button
                            key={item}
                            type="button"
                            onClick={() => setTab(item)}
                            className={`border-b-2 px-5 py-3 text-xs font-semibold whitespace-nowrap ${tab === item ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                        >
                            {item}
                        </button>
                    ))}
                </nav>
                {tab === 'Vista general' ? (
                    <>
                        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                            <Metric
                                icon={CircleDollarSign}
                                label="Presupuesto vigente"
                                value={money(2400000)}
                                detail="↑ 12% vs. 2025"
                                tone="bg-emerald-50 text-emerald-600"
                            />
                            <Metric
                                icon={FileBarChart}
                                label="Ejecutado"
                                value={money(1720000)}
                                progress={71.7}
                                tone="bg-blue-50 text-blue-600"
                            />
                            <Metric
                                icon={WalletCards}
                                label="Comprometido"
                                value={money(410000)}
                                progress={17}
                                tone="bg-fuchsia-50 text-fuchsia-600"
                            />
                            <Metric
                                icon={CircleCheck}
                                label="Disponible"
                                value={money(270000)}
                                progress={11.3}
                                tone="bg-emerald-50 text-emerald-600"
                            />
                            <Metric
                                icon={AlertTriangle}
                                label="Partidas excedidas"
                                value="4"
                                detail="de 58 partidas"
                                tone="bg-rose-50 text-rose-600"
                            />
                            <Metric
                                icon={TrendingUp}
                                label="Desviación proyectada"
                                value="+3.2%"
                                detail="Q78,500.00"
                                tone="bg-violet-50 text-violet-600"
                            />
                        </section>
                        <div className="grid gap-4 xl:grid-cols-[1.2fr_.85fr]">
                            <section className={`${panel} p-4`}>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold">
                                        Presupuesto vs. Real (Ingresos y Gastos)
                                    </h2>
                                    <button
                                        type="button"
                                        className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-[10px]"
                                    >
                                        Año 2026{' '}
                                        <ChevronDown className="size-3" />
                                    </button>
                                </div>
                                <BudgetChart />
                            </section>
                            <section className={`${panel} p-4`}>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold">
                                        Distribución del presupuesto por
                                        categoría
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-[10px] text-blue-600"
                                    >
                                        Ver detalle
                                    </button>
                                </div>
                                <DistributionDonut />
                            </section>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-[1fr_.75fr_.78fr]">
                            <section className={`${panel} p-4`}>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold">
                                        Ejecución por centro de costos
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-[10px] text-blue-600"
                                    >
                                        Ver todos
                                    </button>
                                </div>
                                <ProgressRows type="cost" />
                            </section>
                            <section className={`${panel} p-4`}>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold">
                                        Ejecución por proyecto
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-[10px] text-blue-600"
                                    >
                                        Ver todos
                                    </button>
                                </div>
                                <ProgressRows type="project" />
                            </section>
                            <section className={`${panel} p-4`}>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold">
                                        Estado del presupuesto
                                    </h2>
                                    <button
                                        type="button"
                                        className="rounded border border-slate-200 px-3 py-1.5 text-[10px]"
                                    >
                                        Año 2026⌄
                                    </button>
                                </div>
                                <div className="mt-3 flex items-center gap-4">
                                    <div
                                        className="grid size-32 place-items-center rounded-full"
                                        style={{
                                            background:
                                                'conic-gradient(#3182f6 0 71.7%, #e8eef5 71.7% 100%)',
                                        }}
                                    >
                                        <div className="grid size-20 place-items-center rounded-full bg-white text-center">
                                            <div>
                                                <strong className="block text-xl">
                                                    71.7%
                                                </strong>
                                                <span className="text-[9px] text-slate-500">
                                                    Ejecutado
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex-1 space-y-3 text-[10px]">
                                        {[
                                            [
                                                'Ejecutado',
                                                'Q1,720,000',
                                                'bg-[#17395f]',
                                            ],
                                            [
                                                'Comprometido',
                                                'Q410,000',
                                                'bg-amber-400',
                                            ],
                                            [
                                                'Disponible',
                                                'Q270,000',
                                                'bg-blue-500',
                                            ],
                                        ].map(([name, value, color]) => (
                                            <div
                                                key={name}
                                                className="flex items-center gap-2"
                                            >
                                                <i
                                                    className={`size-2 rounded-full ${color}`}
                                                />
                                                <span className="flex-1">
                                                    {name}
                                                </span>
                                                <strong>{value}</strong>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className="mt-3 flex gap-2 rounded-lg bg-blue-50 p-3 text-[9px] text-blue-800">
                                    <Info className="size-4 shrink-0" />
                                    <p>
                                        Si se mantiene la tendencia actual, se
                                        proyecta un cierre de Q2,478,500{' '}
                                        <strong className="text-red-600">
                                            (+3.2% sobre el presupuesto)
                                        </strong>
                                    </p>
                                </div>
                            </section>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-[1.2fr_.58fr_.68fr]">
                            <section className={`${panel} overflow-hidden`}>
                                <div className="flex items-center justify-between border-b border-slate-200 p-4">
                                    <div className="flex items-center gap-4">
                                        <h2 className="text-sm font-bold">
                                            Principales partidas del presupuesto
                                        </h2>
                                        <span className="rounded border border-blue-400 px-3 py-1 text-[10px] text-blue-600">
                                            Gastos
                                        </span>
                                        <span className="text-[10px] text-slate-500">
                                            Ingresos
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        className="text-[10px] text-blue-600"
                                    >
                                        Ver todas
                                    </button>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[720px] text-left text-[9px]">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-3 py-2">#</th>
                                                <th>Cuenta</th>
                                                <th>Descripción</th>
                                                <th>Presupuesto</th>
                                                <th>Ejecutado</th>
                                                <th>Comprometido</th>
                                                <th>Disponible</th>
                                                <th>% Ejecución</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {[
                                                [
                                                    '6.1.01',
                                                    'Sueldos y salarios',
                                                    'Q648,000',
                                                    'Q646,200',
                                                    'Q20,000',
                                                    '-Q6,200',
                                                    '103%',
                                                    'Excedido',
                                                ],
                                                [
                                                    '6.1.02',
                                                    'Alquiler de oficinas',
                                                    'Q180,000',
                                                    'Q162,000',
                                                    'Q0',
                                                    'Q18,000',
                                                    '90%',
                                                    'En riesgo',
                                                ],
                                                [
                                                    '6.1.03',
                                                    'Publicidad y marketing',
                                                    'Q120,000',
                                                    'Q125,500',
                                                    'Q10,000',
                                                    '-Q15,500',
                                                    '113%',
                                                    'Excedido',
                                                ],
                                                [
                                                    '6.1.04',
                                                    'Transporte y viáticos',
                                                    'Q95,000',
                                                    'Q72,300',
                                                    'Q8,000',
                                                    'Q14,700',
                                                    '76%',
                                                    'En riesgo',
                                                ],
                                                [
                                                    '6.1.05',
                                                    'Servicios (agua, luz, internet)',
                                                    'Q75,000',
                                                    'Q58,200',
                                                    'Q5,000',
                                                    'Q11,800',
                                                    '78%',
                                                    'En riesgo',
                                                ],
                                            ].map((row, index) => (
                                                <tr key={row[0]}>
                                                    <td className="px-3 py-2">
                                                        {index + 1}
                                                    </td>
                                                    <td>{row[0]}</td>
                                                    <td>{row[1]}</td>
                                                    <td>{row[2]}</td>
                                                    <td>{row[3]}</td>
                                                    <td>{row[4]}</td>
                                                    <td
                                                        className={
                                                            row[5].startsWith(
                                                                '-',
                                                            )
                                                                ? 'text-red-600'
                                                                : ''
                                                        }
                                                    >
                                                        {row[5]}
                                                    </td>
                                                    <td
                                                        className={
                                                            Number(
                                                                row[6].replace(
                                                                    '%',
                                                                    '',
                                                                ),
                                                            ) > 100
                                                                ? 'font-bold text-red-600'
                                                                : ''
                                                        }
                                                    >
                                                        {row[6]}
                                                    </td>
                                                    <td>
                                                        <span
                                                            className={`rounded-full px-2 py-1 ${row[7] === 'Excedido' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}`}
                                                        >
                                                            ● {row[7]}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                            <section className={`${panel} p-4`}>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold">
                                        Próximas acciones
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-[10px] text-blue-600"
                                    >
                                        Ver todas
                                    </button>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {[
                                        [
                                            'SEP',
                                            '15',
                                            'Revisar ampliación presupuestaria',
                                            'Partida: Publicidad',
                                        ],
                                        [
                                            'SEP',
                                            '20',
                                            'Transferencia entre partidas',
                                            'De: Tecnología → A: Proyectos',
                                        ],
                                        [
                                            'SEP',
                                            '25',
                                            'Cierre del presupuesto Q3',
                                            'Generar informe de ejecución',
                                        ],
                                        [
                                            'OCT',
                                            '01',
                                            'Preparar presupuesto 2027',
                                            'Iniciar planificación anual',
                                        ],
                                    ].map(([month, day, title, detail]) => (
                                        <button
                                            key={title}
                                            type="button"
                                            onClick={() =>
                                                action(`${title} abierto.`)
                                            }
                                            className="flex w-full gap-3 rounded-lg p-1.5 text-left hover:bg-blue-50"
                                        >
                                            <span className="grid size-10 shrink-0 place-items-center rounded bg-rose-50 text-[8px] font-bold text-rose-600">
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
                                                    {detail}
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
                                            '4 partidas excedidas del presupuesto. Revisa las partidas de nómina y publicidad.',
                                            'text-red-600',
                                        ],
                                        [
                                            AlertTriangle,
                                            'El centro de costos Administración supera el 90% de ejecución.',
                                            'text-amber-600',
                                        ],
                                        [
                                            Info,
                                            'El proyecto PR-2026-004 tiene una desviación del 22% en costos.',
                                            'text-blue-600',
                                        ],
                                        [
                                            CircleCheck,
                                            'Hay Q28,000 disponibles en partidas de baja ejecución que podrían transferirse.',
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
                        </div>
                    </>
                ) : (
                    <section
                        className={`${panel} grid min-h-[420px] place-items-center p-10 text-center`}
                    >
                        <div>
                            {tab === 'Presupuestos' ? (
                                <CircleDollarSign className="mx-auto size-12 text-blue-600" />
                            ) : tab === 'Partidas' ? (
                                <Layers3 className="mx-auto size-12 text-blue-600" />
                            ) : tab === 'Centros de costos' ? (
                                <Target className="mx-auto size-12 text-blue-600" />
                            ) : tab === 'Proyectos' ? (
                                <FolderKanban className="mx-auto size-12 text-blue-600" />
                            ) : tab === 'Flujo de efectivo' ? (
                                <BarChart3 className="mx-auto size-12 text-blue-600" />
                            ) : (
                                <FileBarChart className="mx-auto size-12 text-blue-600" />
                            )}
                            <h2 className="mt-4 text-xl font-bold">{tab}</h2>
                            <p className="mt-2 text-sm text-slate-500">
                                Consulta y administra este apartado del
                                presupuesto anual.
                            </p>
                            <button
                                type="button"
                                onClick={() =>
                                    action(`${tab}: nueva operación preparada.`)
                                }
                                className="mt-5 rounded-lg bg-blue-700 px-5 py-2.5 text-xs font-semibold text-white"
                            >
                                Abrir gestión
                            </button>
                        </div>
                    </section>
                )}
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Presupuesto 2026</span>
                    <span>Última actualización: 09/09/2026</span>
                </footer>
            </div>
        </main>
    );
}
