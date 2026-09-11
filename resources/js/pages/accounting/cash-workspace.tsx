import { Head } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    BadgeDollarSign,
    Banknote,
    BookOpen,
    BriefcaseBusiness,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    CircleAlert,
    Coins,
    FileText,
    Landmark,
    Layers3,
    LockKeyhole,
    MoreHorizontal,
    Plus,
    ReceiptText,
    Settings2,
    ShieldCheck,
    WalletCards,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';

type CashBox = {
    id: number;
    name: string;
    office: string;
    balance: number;
};

const cashBoxes: CashBox[] = [
    {
        id: 1,
        name: 'Caja General',
        office: 'Oficina Central',
        balance: 5235000,
    },
    {
        id: 2,
        name: 'Caja Chica Administración',
        office: 'Administración',
        balance: 1250000,
    },
    {
        id: 3,
        name: 'Caja Sucursal Puerto Barrios',
        office: 'Sucursal Puerto Barrios',
        balance: 1950000,
    },
];

const movements = [
    [
        '09/09/2026 16:45',
        'Ingreso',
        'FAC-1528',
        'Cobro factura Cliente García',
        425000,
        0,
    ],
    ['09/09/2026 15:30', 'Egreso', 'CH-089', 'Pago de papelería', 0, 35000],
    [
        '09/09/2026 13:20',
        'Ingreso',
        'REC-3241',
        'Cobro servicios varios',
        280000,
        0,
    ],
    [
        '09/09/2026 11:15',
        'Egreso',
        'VA-015',
        'Vale de caja · Combustible',
        0,
        50000,
    ],
    [
        '09/09/2026 10:05',
        'Ingreso',
        'REC-3240',
        'Cobro factura Cliente Pérez',
        540000,
        0,
    ],
] as const;

const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value / 100);

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

function Metric({
    label,
    value,
    detail,
    icon: Icon,
    tone,
}: {
    label: string;
    value: string;
    detail: string;
    icon: LucideIcon;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-start gap-3 p-4`}>
            <span
                className={`grid size-10 shrink-0 place-items-center rounded-lg ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div className="min-w-0">
                <p className="text-[11px] text-slate-500">{label}</p>
                <strong className="mt-1 block truncate text-xl text-[#122149]">
                    {value}
                </strong>
                <p className="mt-1 truncate text-[10px] text-slate-400">
                    {detail}
                </p>
            </div>
        </article>
    );
}

export default function CashWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [activeTab, setActiveTab] = useState('Resumen');
    const [notice, setNotice] = useState('');
    const [closingConfirmed, setClosingConfirmed] = useState(false);
    const selected =
        cashBoxes.find((cashBox) => cashBox.id === selectedId) ?? cashBoxes[0];
    const stages = [
        ['Apertura', 'Saldo inicial'],
        ['Ingresos/Egresos', 'Movimientos'],
        ['Vales', 'Control y liquidación'],
        ['Arqueo', 'Conteo de efectivo'],
        ['Depósito', 'A banco / retiros'],
        ['Cierre', 'Fin del día'],
        ['Asiento', 'Impacto contable'],
    ];

    const showNotice = (message: string) => setNotice(message);

    return (
        <main className="min-h-full bg-[#f4f7fb] p-3 text-[#16213d] sm:p-5">
            <Head title={`Caja · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Caja</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Control de efectivo, fondos, movimientos y arqueos
                        </p>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-slate-500">
                        <span>Inicio</span>
                        <span>›</span>
                        <strong className="text-slate-700">Caja</strong>
                    </div>
                </header>

                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
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

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    <Metric
                        label="Saldo total en caja"
                        value={money(8435000)}
                        detail="↑ 12% vs. mes anterior"
                        icon={WalletCards}
                        tone="bg-rose-50 text-[#9f2f42]"
                    />
                    <Metric
                        label="Entradas del día"
                        value={money(1245000)}
                        detail="8 movimientos"
                        icon={ArrowDown}
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        label="Salidas del día"
                        value={money(823000)}
                        detail="6 movimientos"
                        icon={ArrowUp}
                        tone="bg-rose-50 text-rose-600"
                    />
                    <Metric
                        label="Cajas abiertas"
                        value="3 / 3"
                        detail="● Todas operativas"
                        icon={Layers3}
                        tone="bg-slate-50 text-[#122149]"
                    />
                    <Metric
                        label="Diferencias pendientes"
                        value={money(0)}
                        detail="● Cajas cuadradas"
                        icon={CircleAlert}
                        tone="bg-rose-50 text-[#9f2f42]"
                    />
                    <Metric
                        label="Vales por liquidar"
                        value={money(125000)}
                        detail="● 2 vales pendientes"
                        icon={FileText}
                        tone="bg-amber-50 text-amber-600"
                    />
                </section>

                <div className="grid gap-4 2xl:grid-cols-[390px_minmax(0,1fr)]">
                    <aside className="space-y-4">
                        <section className={panel}>
                            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                                <h2 className="text-sm font-bold">
                                    Cajas registradas
                                </h2>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Formulario de nueva caja preparado para captura.',
                                        )
                                    }
                                    className="flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#9f2f42]"
                                >
                                    <Plus className="size-4" /> Nueva caja
                                </button>
                            </div>
                            <div className="divide-y divide-slate-100">
                                {cashBoxes.map((cashBox) => (
                                    <button
                                        key={cashBox.id}
                                        type="button"
                                        onClick={() =>
                                            setSelectedId(cashBox.id)
                                        }
                                        className={`flex w-full items-center gap-3 border-l-2 px-4 py-3 text-left transition ${selected.id === cashBox.id ? 'border-[#a52f43] bg-rose-50/60' : 'border-transparent hover:bg-slate-50'}`}
                                    >
                                        <i className="size-2.5 rounded-full bg-emerald-600" />
                                        <div className="min-w-0 flex-1">
                                            <strong className="block truncate text-xs">
                                                {cashBox.name}
                                            </strong>
                                            <span className="text-[10px] text-slate-500">
                                                {cashBox.office}
                                            </span>
                                        </div>
                                        <strong className="text-xs">
                                            {money(cashBox.balance)}
                                        </strong>
                                        <span className="rounded bg-emerald-100 px-2 py-1 text-[9px] text-emerald-700">
                                            Abierta
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </section>

                        <section className={`${panel} p-4`}>
                            <h2 className="border-b border-slate-100 pb-2 text-sm font-bold">
                                Resumen de caja
                            </h2>
                            <div className="mt-2 space-y-1.5 text-xs">
                                {[
                                    ['Saldo inicial', money(5200000), ''],
                                    [
                                        'Total ingresos',
                                        money(3845000),
                                        'text-emerald-600',
                                    ],
                                    [
                                        'Total egresos',
                                        money(1110000),
                                        'text-red-600',
                                    ],
                                    [
                                        'Saldo teórico',
                                        money(5235000),
                                        'font-bold',
                                    ],
                                    [
                                        'Saldo contado (arqueo)',
                                        money(5235000),
                                        'font-bold',
                                    ],
                                    [
                                        'Diferencia',
                                        money(0),
                                        'bg-rose-50 font-bold text-[#9f2f42]',
                                    ],
                                ].map(([label, value, style]) => (
                                    <div
                                        key={label}
                                        className={`flex justify-between rounded px-2 py-1 ${style}`}
                                    >
                                        <span>{label}</span>
                                        <strong>{value}</strong>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-3 flex gap-3 rounded-lg bg-emerald-50 p-3 text-emerald-800">
                                <CheckCircle2 className="size-5 shrink-0" />
                                <div>
                                    <strong className="text-xs">
                                        Caja cuadrada
                                    </strong>
                                    <p className="text-[10px]">
                                        El saldo coincide con el conteo físico.
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section className={`${panel} overflow-hidden`}>
                            <h2 className="px-4 py-3 text-sm font-bold">
                                Denominaciones (Arqueo)
                            </h2>
                            <table className="w-full text-xs">
                                <thead className="bg-slate-50 text-[10px] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-2 text-left">
                                            Denominación
                                        </th>
                                        <th>Cantidad</th>
                                        <th className="pr-4 text-right">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {[
                                        [20000, 80],
                                        [10000, 120],
                                        [5000, 160],
                                        [2000, 240],
                                        [1000, 300],
                                        [500, 400],
                                        [100, 550],
                                    ].map(([denomination, quantity]) => (
                                        <tr
                                            key={denomination}
                                            className="border-t border-slate-100"
                                        >
                                            <td className="px-4 py-1.5">
                                                {money(denomination)}
                                            </td>
                                            <td className="text-center">
                                                {quantity}
                                            </td>
                                            <td className="pr-4 text-right">
                                                {money(denomination * quantity)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-200 font-bold">
                                        <td className="px-4 py-3" colSpan={2}>
                                            Total contado
                                        </td>
                                        <td className="pr-4 text-right">
                                            {money(5235000)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </section>
                    </aside>

                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-4">
                            <div>
                                <div className="flex items-center gap-3">
                                    <h2 className="text-2xl font-bold">
                                        {selected.name}
                                    </h2>
                                    <span className="rounded-full bg-emerald-100 px-3 py-1 text-[10px] font-semibold text-emerald-700">
                                        ● Abierta
                                    </span>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    {selected.office} &nbsp; | &nbsp;
                                    Responsable: Diego Martínez
                                </p>
                            </div>
                            <div className="flex items-center gap-4">
                                <div className="text-right text-[10px] text-slate-500">
                                    <span>Último arqueo</span>
                                    <strong className="mt-1 flex items-center gap-1 text-xs text-slate-700">
                                        <CalendarDays className="size-3.5" />{' '}
                                        09/09/2026 17:30
                                    </strong>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Configuración de la caja abierta.',
                                        )
                                    }
                                    className="flex items-center gap-2 rounded-lg border border-slate-200 px-4 py-2.5 text-xs font-semibold"
                                >
                                    <Settings2 className="size-4" /> Editar caja
                                </button>
                            </div>
                        </div>

                        <div className="overflow-x-auto px-5 py-4">
                            <div className="relative flex min-w-[850px] justify-between before:absolute before:top-4 before:right-[6%] before:left-[6%] before:h-px before:bg-slate-300">
                                {stages.map(([name, detail], index) => (
                                    <div
                                        key={name}
                                        className="relative z-10 w-28 text-center"
                                    >
                                        <span
                                            className={`mx-auto grid size-8 place-items-center rounded-full border text-xs font-semibold ${index === 0 ? 'border-[#a52f43] bg-[#a52f43] text-white' : 'border-slate-300 bg-white text-slate-600'}`}
                                        >
                                            {index + 1}
                                        </span>
                                        <strong
                                            className={`mt-2 block text-[11px] ${index === 0 ? 'text-[#a52f43]' : ''}`}
                                        >
                                            {name}
                                        </strong>
                                        <span className="block text-[9px] text-slate-500">
                                            {detail}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <nav className="flex overflow-x-auto border-y border-slate-200 bg-slate-50/60 px-3">
                            {(
                                [
                                    ['Resumen', BadgeDollarSign],
                                    ['Movimientos', ReceiptText],
                                    ['Arqueos', Coins],
                                    ['Vales', FileText],
                                    ['Reposiciones', BriefcaseBusiness],
                                    ['Depósitos', Landmark],
                                    ['Historial', CalendarDays],
                                ] satisfies [string, LucideIcon][]
                            ).map(([tab, Icon]) => (
                                <button
                                    key={String(tab)}
                                    type="button"
                                    onClick={() => setActiveTab(String(tab))}
                                    className={`flex items-center gap-2 border-b-2 px-4 py-3 text-[11px] font-semibold whitespace-nowrap ${activeTab === tab ? 'border-[#a52f43] bg-white text-[#a52f43]' : 'border-transparent text-slate-500'}`}
                                >
                                    <Icon className="size-3.5" />
                                    {tab}
                                </button>
                            ))}
                        </nav>

                        <div className="flex flex-wrap justify-end gap-2 border-b border-slate-200 p-3">
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Nuevo ingreso agregado a la cola de captura.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg bg-[#a52f43] px-4 py-2.5 text-xs font-semibold text-white"
                            >
                                <ArrowDown className="size-4" /> Registrar
                                ingreso
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Nuevo egreso agregado a la cola de captura.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border border-[#a52f43] px-4 py-2.5 text-xs font-semibold text-[#a52f43]"
                            >
                                <ArrowUp className="size-4" /> Registrar egreso
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Nuevo vale listo para completar.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border border-[#a52f43] px-4 py-2.5 text-xs font-semibold text-[#a52f43]"
                            >
                                <FileText className="size-4" /> Nuevo vale
                            </button>
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                <MoreHorizontal className="size-4" /> Más
                                acciones <ChevronDown className="size-3" />
                            </button>
                        </div>

                        {activeTab === 'Resumen' ? (
                            <div className="grid gap-4 p-4 xl:grid-cols-[1fr_360px]">
                                <div className="space-y-4">
                                    <section className="overflow-hidden rounded-lg border border-slate-200">
                                        <div className="flex items-center justify-between px-3 py-2">
                                            <h3 className="text-sm font-bold">
                                                Últimos movimientos de caja
                                            </h3>
                                            <button
                                                type="button"
                                                className="text-[10px] text-[#a52f43]"
                                            >
                                                Ver todos
                                            </button>
                                        </div>
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[720px] text-left text-[10px]">
                                                <thead className="bg-slate-50 text-slate-500">
                                                    <tr>
                                                        <th className="px-3 py-2">
                                                            Fecha
                                                        </th>
                                                        <th>Tipo</th>
                                                        <th>Referencia</th>
                                                        <th>Concepto</th>
                                                        <th className="text-right">
                                                            Entrada
                                                        </th>
                                                        <th className="text-right">
                                                            Salida
                                                        </th>
                                                        <th className="pr-3 text-right">
                                                            Estado
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {movements.map(
                                                        ([
                                                            date,
                                                            type,
                                                            reference,
                                                            concept,
                                                            income,
                                                            outcome,
                                                        ]) => (
                                                            <tr key={reference}>
                                                                <td className="px-3 py-2">
                                                                    {date}
                                                                </td>
                                                                <td>
                                                                    <span
                                                                        className={`rounded-full px-2 py-1 ${type === 'Ingreso' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}
                                                                    >
                                                                        ● {type}
                                                                    </span>
                                                                </td>
                                                                <td className="font-semibold text-blue-700">
                                                                    {reference}
                                                                </td>
                                                                <td>
                                                                    {concept}
                                                                </td>
                                                                <td className="text-right font-semibold">
                                                                    {income
                                                                        ? money(
                                                                              income,
                                                                          )
                                                                        : '—'}
                                                                </td>
                                                                <td className="text-right font-semibold">
                                                                    {outcome
                                                                        ? money(
                                                                              outcome,
                                                                          )
                                                                        : '—'}
                                                                </td>
                                                                <td className="pr-3 text-right">
                                                                    <span className="rounded-full bg-emerald-100 px-2 py-1 text-emerald-700">
                                                                        ●
                                                                        Confirmado
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <section className="rounded-lg border border-slate-200 p-3">
                                            <div className="flex items-center justify-between">
                                                <h3 className="text-sm font-bold">
                                                    Vales de caja
                                                </h3>
                                                <button
                                                    type="button"
                                                    className="text-[10px] text-[#a52f43]"
                                                >
                                                    + Nuevo vale
                                                </button>
                                            </div>
                                            {[
                                                [
                                                    'VA-015',
                                                    'Combustible unidad 3',
                                                    50000,
                                                    'Pendiente de liquidar',
                                                ],
                                                [
                                                    'VA-014',
                                                    'Gastos de cafetería',
                                                    25000,
                                                    'Liquidado',
                                                ],
                                            ].map(
                                                ([
                                                    code,
                                                    concept,
                                                    amount,
                                                    status,
                                                ]) => (
                                                    <div
                                                        key={String(code)}
                                                        className="mt-3 flex items-center gap-2 rounded-lg border border-slate-100 p-3 text-[10px]"
                                                    >
                                                        <div className="flex-1">
                                                            <strong>
                                                                {code}
                                                            </strong>
                                                            <p className="text-slate-500">
                                                                {concept}
                                                            </p>
                                                        </div>
                                                        <strong>
                                                            {money(
                                                                Number(amount),
                                                            )}
                                                        </strong>
                                                        <span
                                                            className={`rounded-full px-2 py-1 ${status === 'Liquidado' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}
                                                        >
                                                            ● {status}
                                                        </span>
                                                    </div>
                                                ),
                                            )}
                                        </section>
                                        <section className="rounded-lg border border-slate-200 p-3">
                                            <div className="flex items-center justify-between">
                                                <h3 className="text-sm font-bold">
                                                    Transferencias / Depósitos
                                                </h3>
                                                <button
                                                    type="button"
                                                    className="text-[10px] text-[#a52f43]"
                                                >
                                                    + Nueva transferencia
                                                </button>
                                            </div>
                                            {[
                                                [
                                                    'DEP-012',
                                                    'Depósito a Banco Industrial',
                                                    1000000,
                                                ],
                                                [
                                                    'RET-004',
                                                    'Retiro desde banco',
                                                    500000,
                                                ],
                                            ].map(([code, concept, amount]) => (
                                                <div
                                                    key={String(code)}
                                                    className="mt-3 flex items-center gap-2 rounded-lg border border-slate-100 p-3 text-[10px]"
                                                >
                                                    <div className="flex-1">
                                                        <strong>{code}</strong>
                                                        <p className="text-slate-500">
                                                            {concept}
                                                        </p>
                                                    </div>
                                                    <strong>
                                                        {money(Number(amount))}
                                                    </strong>
                                                    <span className="rounded-full bg-emerald-100 px-2 py-1 text-emerald-700">
                                                        ● Completado
                                                    </span>
                                                </div>
                                            ))}
                                        </section>
                                    </div>
                                </div>
                                <aside className="space-y-3">
                                    <section className="rounded-lg border border-slate-200 p-3">
                                        <h3 className="flex items-center gap-2 text-xs font-bold">
                                            <ShieldCheck className="size-4 text-[#122149]" />{' '}
                                            Asiento contable sugerido
                                        </h3>
                                        <table className="mt-3 w-full text-[10px]">
                                            <thead className="bg-slate-50 text-slate-500">
                                                <tr>
                                                    <th className="p-2 text-left">
                                                        Cuenta
                                                    </th>
                                                    <th className="text-right">
                                                        Debe
                                                    </th>
                                                    <th className="pr-2 text-right">
                                                        Haber
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {[
                                                    [
                                                        '1111 - Caja General',
                                                        '—',
                                                        '—',
                                                    ],
                                                    [
                                                        '5120 - Gastos varios',
                                                        money(1110000),
                                                        '—',
                                                    ],
                                                    [
                                                        '1101 - Bancos (depósitos)',
                                                        money(1000000),
                                                        '—',
                                                    ],
                                                    [
                                                        '1111 - Caja General',
                                                        '—',
                                                        money(2110000),
                                                    ],
                                                ].map((row) => (
                                                    <tr
                                                        key={`${row[0]}-${row[1]}`}
                                                        className="border-t border-slate-100"
                                                    >
                                                        <td className="p-2">
                                                            {row[0]}
                                                        </td>
                                                        <td className="text-right">
                                                            {row[1]}
                                                        </td>
                                                        <td className="pr-2 text-right">
                                                            {row[2]}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                        <p className="mt-2 text-[9px] text-slate-400">
                                            El asiento se genera automáticamente
                                            con los movimientos del día.
                                        </p>
                                        <button
                                            type="button"
                                            className="mt-2 flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 py-2 text-[10px] font-semibold"
                                        >
                                            <BookOpen className="size-3.5" />{' '}
                                            Ver en libro diario
                                        </button>
                                    </section>
                                    <section className="rounded-lg border border-slate-200 p-3">
                                        <h3 className="flex items-center gap-2 text-xs font-bold">
                                            <LockKeyhole className="size-4" />{' '}
                                            Cierre de caja
                                        </h3>
                                        <p className="mt-2 text-[10px] text-slate-500">
                                            Al cerrar la caja no se podrán
                                            registrar más movimientos para este
                                            período.
                                        </p>
                                        <label className="mt-3 flex items-start gap-2 text-[10px]">
                                            <input
                                                type="checkbox"
                                                checked={closingConfirmed}
                                                onChange={(event) =>
                                                    setClosingConfirmed(
                                                        event.target.checked,
                                                    )
                                                }
                                                className="mt-0.5"
                                            />{' '}
                                            Confirmo que el arqueo ha sido
                                            realizado y el saldo es correcto.
                                        </label>
                                        <button
                                            type="button"
                                            disabled={!closingConfirmed}
                                            onClick={() =>
                                                showNotice(
                                                    'Caja del día cerrada y asiento contable generado.',
                                                )
                                            }
                                            className="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-[#a52f43] py-2.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
                                        >
                                            <LockKeyhole className="size-4" />{' '}
                                            Cerrar caja del día
                                        </button>
                                    </section>
                                    <section className="rounded-lg border border-slate-200 p-3">
                                        <h3 className="flex items-center gap-2 text-xs font-bold">
                                            <CircleAlert className="size-4 text-blue-600" />{' '}
                                            Información de la caja
                                        </h3>
                                        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-[9px]">
                                            <div>
                                                <dt className="text-slate-400">
                                                    Código
                                                </dt>
                                                <dd>CJ-001</dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-400">
                                                    Responsable
                                                </dt>
                                                <dd>Diego Martínez</dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-400">
                                                    Nombre
                                                </dt>
                                                <dd>{selected.name}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-400">
                                                    Fecha de apertura
                                                </dt>
                                                <dd>09/09/2026 08:00</dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-400">
                                                    Ubicación
                                                </dt>
                                                <dd>{selected.office}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-400">
                                                    Estado
                                                </dt>
                                                <dd className="font-semibold text-emerald-700">
                                                    ● Abierta
                                                </dd>
                                            </div>
                                        </dl>
                                    </section>
                                </aside>
                            </div>
                        ) : (
                            <div className="grid min-h-[430px] place-items-center p-8 text-center">
                                <div>
                                    <Banknote className="mx-auto size-10 text-[#a52f43]" />
                                    <h3 className="mt-3 text-base font-bold">
                                        {activeTab} de {selected.name}
                                    </h3>
                                    <p className="mt-1 max-w-md text-xs text-slate-500">
                                        Consulta y administra este apartado del
                                        ciclo de caja seleccionado.
                                    </p>
                                </div>
                            </div>
                        )}
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Tesorería y caja</span>
                    <span className="flex items-center gap-1">
                        <Check className="size-3 text-emerald-600" /> Saldos
                        expresados en GTQ
                    </span>
                </footer>
            </div>
        </main>
    );
}
