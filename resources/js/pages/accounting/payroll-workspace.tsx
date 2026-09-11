import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    BarChart3,
    BriefcaseBusiness,
    CalendarDays,
    Check,
    ChevronRight,
    CircleAlert,
    Clock3,
    Download,
    FileBarChart,
    FileText,
    Gift,
    HandCoins,
    HeartPulse,
    Play,
    Plus,
    ReceiptText,
    Settings,
    UserPlus,
    Users,
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

const payrollPeriods = [
    {
        period: 'Sep 2026',
        start: '01/09/2026',
        end: '30/09/2026',
        employees: 85,
        gross: 425800,
        deductions: 52700,
        net: 373100,
        status: 'En preparación',
    },
    {
        period: 'Ago 2026',
        start: '01/08/2026',
        end: '31/08/2026',
        employees: 84,
        gross: 412300,
        deductions: 49100,
        net: 363200,
        status: 'Cerrada',
    },
    {
        period: 'Jul 2026',
        start: '01/07/2026',
        end: '31/07/2026',
        employees: 84,
        gross: 398500,
        deductions: 47800,
        net: 350700,
        status: 'Cerrada',
    },
    {
        period: 'Jun 2026',
        start: '01/06/2026',
        end: '30/06/2026',
        employees: 82,
        gross: 395000,
        deductions: 46200,
        net: 348800,
        status: 'Cerrada',
    },
    {
        period: 'May 2026',
        start: '01/05/2026',
        end: '31/05/2026',
        employees: 82,
        gross: 389200,
        deductions: 45100,
        net: 344100,
        status: 'Cerrada',
    },
    {
        period: 'Abr 2026',
        start: '01/04/2026',
        end: '30/04/2026',
        employees: 81,
        gross: 372600,
        deductions: 43800,
        net: 328800,
        status: 'Cerrada',
    },
];

const monthly = [
    { month: 'Abr', total: 372600, deduction: 43800, net: 328800 },
    { month: 'May', total: 389200, deduction: 45100, net: 344100 },
    { month: 'Jun', total: 395000, deduction: 46200, net: 348800 },
    { month: 'Jul', total: 398500, deduction: 47800, net: 350700 },
    { month: 'Ago', total: 412300, deduction: 49100, net: 363200 },
    { month: 'Sep', total: 425800, deduction: 52700, net: 373100 },
];

function Metric({
    icon: Icon,
    label,
    value,
    change,
    tone,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    change: string;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-start gap-3 p-4`}>
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div>
                <p className="text-xs text-slate-500">{label}</p>
                <strong className="mt-1 block text-xl text-[#12213d]">
                    {value}
                </strong>
                <p className="mt-1 text-[10px] text-slate-400">
                    <span className="rounded bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                        ↑ {change}
                    </span>{' '}
                    <span className="ml-1">vs. mes anterior</span>
                </p>
            </div>
        </article>
    );
}

function PayrollChart() {
    const max = 450000;

    return (
        <div className="mt-4">
            <div className="flex justify-end gap-4 text-[10px] text-slate-500">
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-[#17395f]" />
                    Costo total
                </span>
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-amber-500" />
                    Deducciones
                </span>
                <span>
                    <i className="mr-1 inline-block size-2 rounded-full bg-blue-400" />
                    Neto a pagar
                </span>
            </div>
            <div className="mt-3 grid grid-cols-[52px_1fr] gap-2">
                <div className="flex h-48 flex-col justify-between pb-5 text-[9px] text-slate-500">
                    <span>Q450k</span>
                    <span>Q300k</span>
                    <span>Q150k</span>
                    <span>Q0</span>
                </div>
                <div className="relative flex h-48 items-end border-b border-l border-slate-200 px-2 pb-5">
                    <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-slate-100" />
                    <div className="pointer-events-none absolute inset-x-0 top-1/3 h-px bg-slate-100" />
                    <div className="pointer-events-none absolute inset-x-0 top-2/3 h-px bg-slate-100" />
                    {monthly.map((item) => (
                        <div
                            key={item.month}
                            className="relative flex h-full flex-1 items-end justify-center gap-1"
                        >
                            <span
                                className="w-3 bg-[#17395f] sm:w-5"
                                style={{
                                    height: `${(item.total / max) * 100}%`,
                                }}
                            />
                            <span
                                className="w-3 bg-amber-500 sm:w-5"
                                style={{
                                    height: `${Math.max(12, (item.deduction / max) * 100)}%`,
                                }}
                            />
                            <span
                                className="w-3 bg-blue-400 sm:w-5"
                                style={{ height: `${(item.net / max) * 100}%` }}
                            />
                            <span className="absolute -bottom-5 text-[9px] text-slate-500">
                                {item.month}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function DepartmentDonut() {
    return (
        <div className="mt-4 flex flex-col items-center">
            <div
                className="grid size-36 place-items-center rounded-full"
                style={{
                    background:
                        'conic-gradient(#17395f 0 42%, #eca51f 42% 66%, #60a5fa 66% 84%, #a855f7 84% 94%, #94a3b8 94% 100%)',
                }}
            >
                <div className="grid size-20 place-items-center rounded-full bg-white text-center">
                    <div>
                        <strong className="block text-base">Q425,800</strong>
                        <span className="text-[9px] text-slate-500">Total</span>
                    </div>
                </div>
            </div>
            <div className="mt-4 w-full space-y-1 text-[10px]">
                {[
                    ['Operaciones', '42%', 'Q178,200', 'bg-[#17395f]'],
                    ['Ventas', '24%', 'Q102,200', 'bg-amber-500'],
                    ['Administración', '18%', 'Q76,800', 'bg-blue-400'],
                    ['Tecnología', '10%', 'Q42,500', 'bg-purple-500'],
                    ['Otros', '6%', 'Q26,100', 'bg-slate-400'],
                ].map(([name, percent, amount, color]) => (
                    <div
                        key={name}
                        className="grid grid-cols-[1fr_auto_auto] items-center gap-3"
                    >
                        <span>
                            <i
                                className={`mr-2 inline-block size-2 rounded-full ${color}`}
                            />
                            {name}
                        </span>
                        <span>{percent}</span>
                        <span>{amount}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function PayrollWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [activeTab, setActiveTab] = useState('Períodos de nómina');
    const [notice, setNotice] = useState('');
    const action = (message: string) => setNotice(message);

    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#12213d] sm:p-5">
            <Head title={`Nómina · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Nómina</span>
                    <span>›</span>
                    <strong>Vista general</strong>
                </div>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">Nómina</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestiona el pago de tu personal, controla novedades
                            y mantén todo integrado con la contabilidad.
                        </p>
                    </div>
                    <div className="grid w-full gap-2 sm:w-auto sm:grid-cols-2 xl:grid-cols-1">
                        <button
                            type="button"
                            onClick={() =>
                                action(
                                    'Proceso de nómina de septiembre preparado para revisión.',
                                )
                            }
                            className="flex min-w-56 items-center justify-center gap-2 rounded-lg bg-[#0b3473] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Play className="size-4" /> Procesar nómina
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                action('Formulario de nueva nómina preparado.')
                            }
                            className="flex items-center justify-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2 text-xs font-semibold text-blue-700"
                        >
                            <Plus className="size-4" /> Nueva nómina
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                action('Importador de novedades listo.')
                            }
                            className="flex items-center justify-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2 text-xs font-semibold text-blue-700"
                        >
                            <Download className="size-4" /> Importar novedades
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                action('Reporte de nómina generado.')
                            }
                            className="flex items-center justify-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2 text-xs font-semibold text-blue-700"
                        >
                            <FileBarChart className="size-4" /> Generar reportes
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

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={Users}
                        label="Empleados activos"
                        value="85"
                        change="2.4%"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={Banknote}
                        label="Costo total de nómina"
                        value={money(425800)}
                        change="3.5%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={WalletCards}
                        label="Total deducciones"
                        value={money(52700)}
                        change="4.1%"
                        tone="bg-rose-50 text-rose-600"
                    />
                    <Metric
                        icon={ReceiptText}
                        label="Neto a pagar"
                        value={money(373100)}
                        change="3.4%"
                        tone="bg-green-50 text-green-600"
                    />
                </section>

                <div className="grid gap-4 xl:grid-cols-[1fr_220px_310px]">
                    <section className={`${panel} p-4`}>
                        <h2 className="text-sm font-bold">
                            Evolución de la nómina (últimos 6 meses)
                        </h2>
                        <PayrollChart />
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="text-sm font-bold">
                            Distribución por departamento
                        </h2>
                        <DepartmentDonut />
                    </section>
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-bold">
                                Próximas tareas
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
                                    '10',
                                    'Revisar y aprobar nómina',
                                    'Período: Septiembre 2026',
                                ],
                                [
                                    'SEP',
                                    '12',
                                    'Generar archivo de pago',
                                    'Banco Industrial',
                                ],
                                [
                                    'SEP',
                                    '15',
                                    'Enviar recibos de pago',
                                    'A todos los empleados',
                                ],
                                [
                                    'SEP',
                                    '30',
                                    'Cierre de período',
                                    'Nómina Septiembre 2026',
                                ],
                            ].map(([month, day, title, detail]) => (
                                <button
                                    type="button"
                                    key={title}
                                    onClick={() =>
                                        action(`${title}: tarea abierta.`)
                                    }
                                    className="flex w-full items-center gap-3 rounded-lg border border-slate-200 p-2 text-left hover:bg-blue-50"
                                >
                                    <span className="grid size-11 place-items-center rounded-lg bg-rose-50 text-[9px] font-bold text-rose-600">
                                        <span>
                                            {month}
                                            <strong className="block text-base text-slate-800">
                                                {day}
                                            </strong>
                                        </span>
                                    </span>
                                    <span>
                                        <strong className="block text-[11px]">
                                            {title}
                                        </strong>
                                        <span className="text-[10px] text-slate-500">
                                            {detail}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </section>
                </div>

                <div className="grid gap-4 xl:grid-cols-[1fr_310px]">
                    <section className={`${panel} overflow-hidden`}>
                        <nav className="flex overflow-x-auto border-b border-slate-200 px-2">
                            {[
                                'Períodos de nómina',
                                'Empleados',
                                'Novedades',
                                'Pagos',
                                'Liquidaciones',
                            ].map((tab) => (
                                <button
                                    key={tab}
                                    type="button"
                                    onClick={() => setActiveTab(tab)}
                                    className={`border-b-2 px-4 py-3 text-xs font-semibold whitespace-nowrap ${activeTab === tab ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {tab}
                                </button>
                            ))}
                            <button
                                type="button"
                                className="ml-auto hidden items-center gap-1 px-3 text-[10px] text-blue-600 lg:flex"
                            >
                                Ver todos los períodos{' '}
                                <ChevronRight className="size-3" />
                            </button>
                        </nav>
                        {activeTab === 'Períodos de nómina' ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-left text-[10px]">
                                    <thead className="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th className="px-4 py-2">#</th>
                                            <th>Período</th>
                                            <th>Fecha inicio</th>
                                            <th>Fecha fin</th>
                                            <th>Empleados</th>
                                            <th>Bruto</th>
                                            <th>Deducciones</th>
                                            <th>Neto a pagar</th>
                                            <th>Estado</th>
                                            <th className="pr-4 text-right">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {payrollPeriods.map((period, index) => (
                                            <tr
                                                key={period.period}
                                                className={
                                                    index === 0
                                                        ? 'bg-blue-50'
                                                        : ''
                                                }
                                            >
                                                <td className="px-4 py-2">
                                                    {index + 1}
                                                </td>
                                                <td className="font-semibold text-blue-700">
                                                    {period.period}
                                                </td>
                                                <td>{period.start}</td>
                                                <td>{period.end}</td>
                                                <td>{period.employees}</td>
                                                <td>{money(period.gross)}</td>
                                                <td>
                                                    {money(period.deductions)}
                                                </td>
                                                <td>{money(period.net)}</td>
                                                <td>
                                                    <span
                                                        className={`rounded-full px-2 py-1 ${period.status === 'Cerrada' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700'}`}
                                                    >
                                                        ● {period.status}
                                                    </span>
                                                </td>
                                                <td className="pr-4 text-right">
                                                    •••
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="grid min-h-52 place-items-center p-8 text-center">
                                <div>
                                    <BriefcaseBusiness className="mx-auto size-9 text-blue-600" />
                                    <h3 className="mt-3 font-bold">
                                        {activeTab}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Consulta y administra la información de
                                        este apartado de nómina.
                                    </p>
                                </div>
                            </div>
                        )}
                    </section>
                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex items-center justify-between border-b border-slate-200 p-4">
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
                        <div className="divide-y divide-slate-100">
                            {[
                                [
                                    AlertTriangle,
                                    '3 empleados con horas extra inusuales',
                                    'Revisa las novedades del período.',
                                    'text-rose-600 bg-rose-50',
                                ],
                                [
                                    CircleAlert,
                                    '1 empleado sin cuenta bancaria',
                                    'Completa la información antes de procesar el pago.',
                                    'text-amber-600 bg-amber-50',
                                ],
                                [
                                    Clock3,
                                    '2 préstamos por finalizar',
                                    'Revisar renovación o cierre.',
                                    'text-blue-600 bg-blue-50',
                                ],
                                [
                                    BarChart3,
                                    'Incremento del 8.5% en horas extra',
                                    'Comparado con el mes anterior.',
                                    'text-blue-600 bg-blue-50',
                                ],
                            ].map(([Icon, title, detail, tone]) => {
                                const ItemIcon = Icon as LucideIcon;

                                return (
                                    <div
                                        key={String(title)}
                                        className="flex gap-3 p-3"
                                    >
                                        <span
                                            className={`grid size-8 shrink-0 place-items-center rounded-lg ${tone}`}
                                        >
                                            <ItemIcon className="size-4" />
                                        </span>
                                        <div>
                                            <strong className="block text-[11px]">
                                                {String(title)}
                                            </strong>
                                            <p className="mt-0.5 text-[10px] text-slate-500">
                                                {String(detail)}
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                </div>

                <div className="grid gap-4 xl:grid-cols-[1.05fr_.75fr_1.25fr]">
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <CalendarDays className="size-4 text-blue-600" />{' '}
                                Últimas novedades
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todas
                            </button>
                        </div>
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full min-w-[520px] text-left text-[10px]">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Empleado</th>
                                        <th>Tipo</th>
                                        <th>Descripción</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {[
                                        [
                                            '08/09/2026',
                                            'Carlos Pérez',
                                            'Horas extra',
                                            '4 horas',
                                        ],
                                        [
                                            '07/09/2026',
                                            'Ana López',
                                            'Ausencia',
                                            '1 día',
                                        ],
                                        [
                                            '06/09/2026',
                                            'Miguel Torres',
                                            'Bonificación',
                                            'Bono por desempeño',
                                        ],
                                        [
                                            '05/09/2026',
                                            'Laura Méndez',
                                            'Vacaciones',
                                            '3 días',
                                        ],
                                        [
                                            '04/09/2026',
                                            'José Ramírez',
                                            'Anticipo',
                                            'Q500.00',
                                        ],
                                    ].map((row, index) => (
                                        <tr key={row[0]}>
                                            <td className="py-1.5">{row[0]}</td>
                                            <td className="font-semibold text-blue-700">
                                                {row[1]}
                                            </td>
                                            <td>
                                                <span className="rounded bg-blue-50 px-2 py-1 text-blue-700">
                                                    {row[2]}
                                                </span>
                                            </td>
                                            <td>{row[3]}</td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-2 py-1 ${index === 4 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}
                                                >
                                                    ●{' '}
                                                    {index === 4
                                                        ? 'Pendiente'
                                                        : 'Aprobado'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <Users className="size-4 text-blue-600" /> Empleados
                            por estado
                        </h2>
                        <div className="mt-4 flex items-center justify-around gap-4">
                            <div
                                className="grid size-32 shrink-0 place-items-center rounded-full"
                                style={{
                                    background:
                                        'conic-gradient(#17395f 0 94%, #60a5fa 94% 97%, #a855f7 97% 99%, #94a3b8 99% 100%)',
                                }}
                            >
                                <div className="grid size-20 place-items-center rounded-full bg-white text-center">
                                    <div>
                                        <strong className="block text-xl">
                                            85
                                        </strong>
                                        <span className="text-[9px] text-slate-500">
                                            Total
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="space-y-2 text-[10px]">
                                {[
                                    ['Activos', '80 (94%)', 'bg-[#17395f]'],
                                    ['Vacaciones', '3 (4%)', 'bg-blue-400'],
                                    ['Licencia', '1 (1%)', 'bg-purple-500'],
                                    ['Inactivos', '1 (1%)', 'bg-slate-400'],
                                ].map(([name, value, color]) => (
                                    <div
                                        key={name}
                                        className="flex items-center gap-2"
                                    >
                                        <i
                                            className={`size-2 rounded-full ${color}`}
                                        />
                                        <span className="min-w-20">{name}</span>
                                        <strong>{value}</strong>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <HeartPulse className="size-4 text-blue-600" />{' '}
                            Accesos rápidos
                        </h2>
                        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {[
                                [UserPlus, 'Nuevo empleado'],
                                [CalendarDays, 'Registrar novedad'],
                                [Banknote, 'Calcular nómina'],
                                [FileText, 'Generar recibos'],
                                [Gift, 'Liquidación'],
                                [HandCoins, 'Préstamos'],
                                [FileBarChart, 'Reportes'],
                                [Settings, 'Configuración'],
                            ].map(([Icon, label]) => {
                                const QuickIcon = Icon as LucideIcon;

                                return (
                                    <button
                                        key={String(label)}
                                        type="button"
                                        onClick={() =>
                                            action(`${String(label)} abierto.`)
                                        }
                                        className="grid min-h-20 place-items-center rounded-lg border border-slate-200 p-2 text-center text-[10px] text-slate-600 hover:border-blue-400 hover:bg-blue-50"
                                    >
                                        <span>
                                            <QuickIcon className="mx-auto mb-2 size-5 text-blue-600" />
                                            {String(label)}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Gestión de nómina</span>
                    <span>Período activo: septiembre 2026</span>
                </footer>
            </div>
        </main>
    );
}
