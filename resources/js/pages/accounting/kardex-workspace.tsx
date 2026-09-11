import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    BarChart3,
    Boxes,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    FileDown,
    FileSpreadsheet,
    Filter,
    Info,
    Lightbulb,
    PackageCheck,
    Plus,
    Search,
    Sparkles,
    TrendingUp,
    Warehouse,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type KardexMovement = {
    date: string;
    document: string;
    type:
        | 'Saldo inicial'
        | 'Compra'
        | 'Venta'
        | 'Transferencia'
        | 'Ajuste'
        | 'Devolución';
    reference: string;
    warehouse: string;
    entry: number;
    exit: number;
    stock: number;
    unitCost: number;
    value: number;
    balance: number;
    user: string;
};

const kardexMovements: KardexMovement[] = [
    {
        date: '30/04/2025',
        document: '---',
        type: 'Saldo inicial',
        reference: '---',
        warehouse: 'Bodega Central',
        entry: 0,
        exit: 0,
        stock: 10,
        unitCost: 180000,
        value: 0,
        balance: 1800000,
        user: 'Sistema',
    },
    {
        date: '02/05/2025',
        document: 'FC-4587',
        type: 'Compra',
        reference: 'Compra a Grupo Intel S.A.',
        warehouse: 'Bodega Central',
        entry: 20,
        exit: 0,
        stock: 30,
        unitCost: 182000,
        value: 3640000,
        balance: 5440000,
        user: 'Ana López',
    },
    {
        date: '05/05/2025',
        document: 'FEL-1254',
        type: 'Venta',
        reference: 'Factura a Cliente C001',
        warehouse: 'Bodega Central',
        entry: 0,
        exit: 8,
        stock: 22,
        unitCost: 182000,
        value: -1456000,
        balance: 3984000,
        user: 'Carlos Pérez',
    },
    {
        date: '10/05/2025',
        document: 'TR-002',
        type: 'Transferencia',
        reference: 'Traslado a Sucursal 01',
        warehouse: 'Bodega Central',
        entry: 0,
        exit: 5,
        stock: 17,
        unitCost: 182000,
        value: -910000,
        balance: 3074000,
        user: 'Diego Martínez',
    },
    {
        date: '15/05/2025',
        document: 'AJ-008',
        type: 'Ajuste',
        reference: 'Ajuste por conteo físico',
        warehouse: 'Bodega Central',
        entry: 3,
        exit: 0,
        stock: 20,
        unitCost: 185000,
        value: 555000,
        balance: 3629000,
        user: 'María González',
    },
    {
        date: '20/05/2025',
        document: 'FEL-1289',
        type: 'Venta',
        reference: 'Factura a Cliente C015',
        warehouse: 'Bodega Central',
        entry: 0,
        exit: 4,
        stock: 16,
        unitCost: 185000,
        value: -740000,
        balance: 2889000,
        user: 'Ana López',
    },
    {
        date: '25/05/2025',
        document: 'DV-014',
        type: 'Devolución',
        reference: 'Devolución de Cliente C015',
        warehouse: 'Bodega Central',
        entry: 2,
        exit: 0,
        stock: 18,
        unitCost: 185000,
        value: 370000,
        balance: 3259000,
        user: 'Carlos Pérez',
    },
    {
        date: '28/05/2025',
        document: 'FEL-1293',
        type: 'Venta',
        reference: 'Factura a Cliente C020',
        warehouse: 'Bodega Central',
        entry: 0,
        exit: 0,
        stock: 18,
        unitCost: 185000,
        value: 0,
        balance: 3330000,
        user: 'Diego Martínez',
    },
];

const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value / 100);
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const typeStyle: Record<KardexMovement['type'], string> = {
    'Saldo inicial': 'bg-slate-100 text-slate-700',
    Compra: 'bg-emerald-100 text-emerald-700',
    Venta: 'bg-rose-100 text-rose-700',
    Transferencia: 'bg-amber-100 text-amber-700',
    Ajuste: 'bg-blue-100 text-blue-700',
    Devolución: 'bg-violet-100 text-violet-700',
};

function Metric({
    label,
    value,
    suffix,
    change,
    icon: Icon,
    tone,
}: {
    label: string;
    value: string;
    suffix?: string;
    change: string;
    icon: LucideIcon;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-start gap-4 p-4`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div>
                <p className="text-[11px] text-slate-500">{label}</p>
                <div className="mt-1 flex items-baseline gap-2">
                    <strong className="text-xl text-[#11203c]">{value}</strong>
                    {suffix && (
                        <span className="text-xs text-slate-500">{suffix}</span>
                    )}
                </div>
                <p className="mt-2 text-[10px] text-emerald-700">
                    {change}{' '}
                    <span className="ml-2 text-slate-400">
                        vs. mes anterior
                    </span>
                </p>
            </div>
        </article>
    );
}

function StockChart() {
    const entries = [24, 18, 5, 20, 19];
    const exits = [10, 8, 4, 8, 10];
    const stock = [28, 24, 14, 20, 22];

    return (
        <div className="mt-3">
            <div className="flex justify-end gap-4 text-[9px] text-slate-500">
                <span className="text-blue-600">■ Entradas</span>
                <span className="text-rose-500">■ Salidas</span>
                <span>● Existencia</span>
            </div>
            <div className="relative mt-2 flex h-36 items-end justify-around border-b border-slate-200 px-4">
                {entries.map((entry, index) => (
                    <div
                        key={index}
                        className="relative flex h-full flex-1 items-end justify-center gap-1"
                    >
                        <span
                            className="w-6 bg-blue-500"
                            style={{ height: `${entry * 2.3}%` }}
                        />
                        <span
                            className="w-6 bg-rose-500"
                            style={{ height: `${exits[index] * 2.3}%` }}
                        />
                        <span
                            className="absolute left-1/2 size-2 -translate-x-1/2 rounded-full bg-[#11203c]"
                            style={{ bottom: `${stock[index] * 2.3}%` }}
                        />
                        <span className="absolute -bottom-5 text-[9px] text-slate-500">
                            {['Ene', 'Feb', 'Mar', 'Abr', 'May'][index]}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function KardexWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [query, setQuery] = useState('');
    const [movementType, setMovementType] = useState('Todos');
    const [notice, setNotice] = useState('');
    const filtered = useMemo(
        () =>
            kardexMovements.filter((movement) => {
                const matchesQuery =
                    `${movement.document} ${movement.reference} ${movement.user}`
                        .toLowerCase()
                        .includes(query.toLowerCase());
                const matchesType =
                    movementType === 'Todos' || movement.type === movementType;

                return matchesQuery && matchesType;
            }),
        [movementType, query],
    );

    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#13213d] sm:p-5">
            <Head title={`Kardex de Inventario · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Inventario</span>
                    <span>›</span>
                    <span>Kardex</span>
                </div>
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Kardex de Inventario
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Historial detallado de entradas, salidas,
                            existencias y valorización.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            setNotice(
                                'Atlantia IA analizó rotación, existencias y valorización del período.',
                            )
                        }
                        className="flex items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-4 py-2.5 text-xs font-semibold text-violet-700"
                    >
                        <Sparkles className="size-4" /> Asistente IA
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
                            onClick={() => setNotice('')}
                            aria-label="Cerrar aviso"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                    <Metric
                        label="Existencia actual"
                        value="18"
                        suffix="Unidades"
                        change="↑ 12.5%"
                        icon={Boxes}
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        label="Costo promedio"
                        value={money(185000)}
                        change="↓ -3.8%"
                        icon={PackageCheck}
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        label="Valor en inventario"
                        value={money(3330000)}
                        change="↗ 7.2%"
                        icon={Warehouse}
                        tone="bg-amber-50 text-amber-600"
                    />
                    <Metric
                        label="Movimientos del mes"
                        value="8"
                        suffix="Transacciones"
                        change="↑ 14.3%"
                        icon={ArrowLeftRight}
                        tone="bg-rose-50 text-rose-600"
                    />
                </section>

                <section className={`${panel} p-4`}>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.3fr_.8fr_.7fr_.7fr_1fr_auto]">
                        <label className="text-[10px] font-semibold">
                            Producto
                            <button
                                type="button"
                                className="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                <span>
                                    💻 &nbsp; PROD-001 · Laptop Dell Inspiron 15
                                </span>
                                <ChevronDown className="size-4" />
                            </button>
                        </label>
                        <label className="text-[10px] font-semibold">
                            Bodega
                            <button
                                type="button"
                                className="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                Todas las bodegas
                                <ChevronDown className="size-4" />
                            </button>
                        </label>
                        <label className="text-[10px] font-semibold">
                            Período
                            <button
                                type="button"
                                className="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                <span className="flex items-center gap-2">
                                    <CalendarDays className="size-4 text-slate-500" />{' '}
                                    Mayo 2025
                                </span>
                                <ChevronDown className="size-4" />
                            </button>
                        </label>
                        <label className="text-[10px] font-semibold">
                            Tipo de movimiento
                            <select
                                value={movementType}
                                onChange={(event) =>
                                    setMovementType(event.target.value)
                                }
                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs"
                            >
                                <option>Todos</option>
                                <option>Compra</option>
                                <option>Venta</option>
                                <option>Transferencia</option>
                                <option>Ajuste</option>
                                <option>Devolución</option>
                            </select>
                        </label>
                        <label className="text-[10px] font-semibold">
                            Buscar documento
                            <span className="relative mt-1 block">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="No. documento, referencia..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-blue-500"
                                />
                            </span>
                        </label>
                        <div className="flex flex-wrap items-end justify-end gap-2 xl:max-w-96">
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice('Kardex exportado a Excel.')
                                }
                                className="flex items-center gap-2 rounded-lg border border-emerald-500 px-3 py-2.5 text-xs font-semibold text-emerald-700"
                            >
                                <FileSpreadsheet className="size-4" /> Excel
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice('Kardex exportado a PDF.')
                                }
                                className="flex items-center gap-2 rounded-lg border border-rose-400 px-3 py-2.5 text-xs font-semibold text-rose-600"
                            >
                                <FileDown className="size-4" /> PDF
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        'Nuevo ajuste preparado para captura.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg bg-[#a52f43] px-4 py-2.5 text-xs font-semibold text-white"
                            >
                                <Plus className="size-4" /> Nuevo ajuste
                            </button>
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-lg border border-blue-400 px-3 py-2.5 text-xs font-semibold text-blue-700"
                            >
                                <Filter className="size-4" /> Filtros
                            </button>
                        </div>
                    </div>
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <h2 className="border-b border-slate-200 px-4 py-3 text-base font-bold">
                        Movimientos de Kardex
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1250px] text-left text-[10px]">
                            <thead className="bg-slate-50 text-slate-600">
                                <tr>
                                    <th className="px-4 py-2">Fecha</th>
                                    <th>Documento</th>
                                    <th>Tipo</th>
                                    <th>Referencia</th>
                                    <th>Bodega</th>
                                    <th className="text-right">Entrada</th>
                                    <th className="text-right">Salida</th>
                                    <th className="text-right">Existencia</th>
                                    <th className="text-right">
                                        Costo unitario
                                    </th>
                                    <th className="text-right">
                                        Valor movimiento
                                    </th>
                                    <th className="text-right">
                                        Saldo valorizado
                                    </th>
                                    <th className="pr-4 text-right">Usuario</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filtered.map((movement) => (
                                    <tr
                                        key={`${movement.date}-${movement.document}`}
                                        className="hover:bg-blue-50/50"
                                    >
                                        <td className="px-4 py-2">
                                            {movement.date}
                                        </td>
                                        <td className="font-semibold text-blue-600">
                                            {movement.document}
                                        </td>
                                        <td>
                                            <span
                                                className={`rounded px-2 py-1 ${typeStyle[movement.type]}`}
                                            >
                                                {movement.type}
                                            </span>
                                        </td>
                                        <td>{movement.reference}</td>
                                        <td>{movement.warehouse}</td>
                                        <td className="text-right font-semibold text-emerald-700">
                                            {movement.entry}
                                        </td>
                                        <td className="text-right font-semibold text-red-600">
                                            {movement.exit}
                                        </td>
                                        <td className="text-right font-bold">
                                            {movement.stock}
                                        </td>
                                        <td className="text-right">
                                            {money(movement.unitCost)}
                                        </td>
                                        <td
                                            className={`text-right ${movement.value < 0 ? 'text-red-600' : ''}`}
                                        >
                                            {money(movement.value)}
                                        </td>
                                        <td className="text-right font-semibold">
                                            {money(movement.balance)}
                                        </td>
                                        <td className="pr-4 text-right">
                                            {movement.user}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {filtered.length === 0 && (
                            <div className="p-10 text-center text-sm text-slate-400">
                                No hay movimientos que coincidan con los
                                filtros.
                            </div>
                        )}
                    </div>
                    <footer className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                        <span>
                            Mostrando 1 a {filtered.length} de 8 movimientos
                        </span>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                className="grid size-8 place-items-center rounded border border-slate-200"
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                            <button
                                type="button"
                                className="grid size-8 place-items-center rounded bg-blue-600 text-white"
                            >
                                1
                            </button>
                            <button
                                type="button"
                                className="grid size-8 place-items-center rounded border border-slate-200"
                            >
                                <ChevronRight className="size-4" />
                            </button>
                        </div>
                    </footer>
                </section>

                <div className="grid gap-4 xl:grid-cols-[.9fr_1.1fr_1fr]">
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <PackageCheck className="size-4 text-blue-600" />{' '}
                            Información del producto
                        </h2>
                        <div className="mt-3 flex gap-4">
                            <span className="grid size-20 place-items-center rounded-xl border border-slate-200 bg-slate-50 text-5xl">
                                💻
                            </span>
                            <div>
                                <strong className="block">PROD-001</strong>
                                <strong className="block text-sm">
                                    Laptop Dell Inspiron 15
                                </strong>
                                <div className="mt-2 flex gap-2">
                                    <span className="rounded bg-slate-100 px-2 py-1 text-[9px]">
                                        Tecnología
                                    </span>
                                    <span className="rounded bg-slate-100 px-2 py-1 text-[9px]">
                                        Equipo de cómputo
                                    </span>
                                </div>
                            </div>
                        </div>
                        <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-[10px]">
                            <div className="flex justify-between">
                                <dt>Código</dt>
                                <dd>PROD-001</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Stock mínimo</dt>
                                <dd>5</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Categoría</dt>
                                <dd>Tecnología</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Stock máximo</dt>
                                <dd>50</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Unidad de medida</dt>
                                <dd>Unidad</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Disponible</dt>
                                <dd className="font-bold text-emerald-700">
                                    18
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Bodega principal</dt>
                                <dd>Bodega Central</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Reservado</dt>
                                <dd className="font-bold text-red-600">2</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Cuenta contable</dt>
                                <dd>1.1.05.001</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Último movimiento</dt>
                                <dd>28/05/2025</dd>
                            </div>
                        </dl>
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <BarChart3 className="size-4 text-blue-600" />{' '}
                            Evolución de existencia (últimos 6 meses)
                        </h2>
                        <StockChart />
                        <div className="mt-8 rounded-lg bg-slate-50 p-3">
                            <h3 className="text-xs font-bold">
                                Resumen del período (Mayo 2025)
                            </h3>
                            <div className="mt-3 grid grid-cols-3 divide-x divide-slate-200">
                                <div>
                                    <span className="text-[10px] text-slate-500">
                                        Entradas
                                    </span>
                                    <strong className="block text-lg">5</strong>
                                    <small className="text-emerald-600">
                                        ↑ 150%
                                    </small>
                                </div>
                                <div className="pl-4">
                                    <span className="text-[10px] text-slate-500">
                                        Salidas
                                    </span>
                                    <strong className="block text-lg">4</strong>
                                    <small className="text-emerald-600">
                                        ↑ 100%
                                    </small>
                                </div>
                                <div className="pl-4">
                                    <span className="text-[10px] text-slate-500">
                                        Movimiento neto
                                    </span>
                                    <strong className="block text-lg">1</strong>
                                    <small className="text-slate-500">
                                        → 0%
                                    </small>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section className={`${panel} overflow-hidden`}>
                        <h2 className="flex items-center gap-2 border-b border-violet-100 bg-violet-50 px-4 py-3 text-sm font-bold text-violet-700">
                            <Sparkles className="size-4" /> Análisis de Atlantia
                            IA
                        </h2>
                        <div className="divide-y divide-slate-100">
                            {(
                                [
                                    [
                                        TrendingUp,
                                        'Movimiento saludable',
                                        'El producto mantiene un movimiento constante con 8 transacciones en el período.',
                                        'bg-emerald-50 text-emerald-600',
                                    ],
                                    [
                                        AlertTriangle,
                                        'Stock en nivel adecuado',
                                        'La existencia actual (18) está dentro del rango óptimo (5 - 50 unidades).',
                                        'bg-amber-50 text-amber-600',
                                    ],
                                    [
                                        Info,
                                        'Tendencia estable',
                                        'El valor del inventario se ha mantenido estable durante los últimos 3 meses.',
                                        'bg-blue-50 text-blue-600',
                                    ],
                                    [
                                        Lightbulb,
                                        'Sugerencia',
                                        'Considera revisar la rotación para optimizar el nivel de stock y reducir costos.',
                                        'bg-violet-50 text-violet-600',
                                    ],
                                ] satisfies [
                                    LucideIcon,
                                    string,
                                    string,
                                    string,
                                ][]
                            ).map(([Icon, title, detail, tone]) => (
                                <div
                                    key={String(title)}
                                    className="flex gap-3 p-3"
                                >
                                    <span
                                        className={`grid size-9 shrink-0 place-items-center rounded-full ${tone}`}
                                    >
                                        <Icon className="size-4" />
                                    </span>
                                    <div>
                                        <strong className="text-xs">
                                            {title}
                                        </strong>
                                        <p className="mt-1 text-[10px] leading-4 text-slate-500">
                                            {detail}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Kardex</span>
                    <span className="flex items-center gap-1">
                        <CircleAlert className="size-3" /> Método de costo
                        promedio ponderado
                    </span>
                </footer>
            </div>
        </main>
    );
}
