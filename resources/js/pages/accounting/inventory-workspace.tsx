import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownToLine,
    ArrowUpFromLine,
    BarChart3,
    Boxes,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleMinus,
    Edit3,
    Filter,
    Info,
    PackageCheck,
    PackageOpen,
    Plus,
    Search,
    ShieldCheck,
    Sparkles,
    Warehouse,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type ProductStatus = 'active' | 'low' | 'out';
type Product = {
    id: number;
    code: string;
    name: string;
    category: string;
    warehouse: string;
    stock: number;
    price: number;
    cost: number;
    reserved: number;
    status: ProductStatus;
    icon: string;
    description: string;
};

const products: Product[] = [
    {
        id: 1,
        code: 'PROD-001',
        name: 'Laptop Dell Inspiron 15',
        category: 'Tecnología',
        warehouse: 'Bodega Central',
        stock: 12,
        price: 680000,
        cost: 550000,
        reserved: 2,
        status: 'active',
        icon: '💻',
        description: 'Laptop Dell Inspiron 15, 16GB RAM, 512GB SSD, Windows 11',
    },
    {
        id: 2,
        code: 'PROD-002',
        name: 'Resma papel carta',
        category: 'Papelería',
        warehouse: 'Bodega Central',
        stock: 85,
        price: 4800,
        cost: 3400,
        reserved: 4,
        status: 'active',
        icon: '📚',
        description: 'Resma de papel bond tamaño carta, 500 hojas.',
    },
    {
        id: 3,
        code: 'PROD-003',
        name: 'Mouse inalámbrico',
        category: 'Tecnología',
        warehouse: 'Sucursal PB',
        stock: 5,
        price: 12500,
        cost: 8200,
        reserved: 1,
        status: 'low',
        icon: '🖱️',
        description: 'Mouse inalámbrico ergonómico con receptor USB.',
    },
    {
        id: 4,
        code: 'PROD-004',
        name: 'Teclado mecánico',
        category: 'Tecnología',
        warehouse: 'Bodega Central',
        stock: 0,
        price: 45000,
        cost: 32000,
        reserved: 0,
        status: 'out',
        icon: '⌨️',
        description: 'Teclado mecánico de tamaño completo.',
    },
    {
        id: 5,
        code: 'PROD-005',
        name: 'Silla ergonómica',
        category: 'Mobiliario',
        warehouse: 'Sucursal STC',
        stock: 7,
        price: 125000,
        cost: 89000,
        reserved: 2,
        status: 'low',
        icon: '🪑',
        description: 'Silla ergonómica ajustable para oficina.',
    },
    {
        id: 6,
        code: 'PROD-006',
        name: 'Monitor 24 pulgadas',
        category: 'Tecnología',
        warehouse: 'Bodega Central',
        stock: 18,
        price: 185000,
        cost: 141000,
        reserved: 3,
        status: 'active',
        icon: '🖥️',
        description: 'Monitor IPS Full HD de 24 pulgadas.',
    },
    {
        id: 7,
        code: 'PROD-007',
        name: 'Tinta impresora HP',
        category: 'Consumibles',
        warehouse: 'Bodega Central',
        stock: 3,
        price: 12000,
        cost: 8600,
        reserved: 1,
        status: 'low',
        icon: '🧴',
        description: 'Cartucho de tinta negra para impresora HP.',
    },
    {
        id: 8,
        code: 'PROD-008',
        name: 'Café en grano 1kg',
        category: 'Alimentos',
        warehouse: 'Sucursal PB',
        stock: 50,
        price: 8500,
        cost: 5900,
        reserved: 0,
        status: 'active',
        icon: '☕',
        description: 'Café guatemalteco tostado en grano, bolsa de 1kg.',
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
const statuses = {
    active: { label: 'Activo', className: 'bg-emerald-100 text-emerald-700' },
    low: { label: 'Stock bajo', className: 'bg-amber-100 text-amber-700' },
    out: { label: 'Sin existencia', className: 'bg-rose-100 text-rose-700' },
};

function InventoryMetric({
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
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div>
                <p className="text-[11px] text-slate-500">{label}</p>
                <strong className="mt-1 block text-xl text-[#10213f]">
                    {value}
                </strong>
                <p className="mt-1 text-[10px] text-blue-700">{detail}</p>
            </div>
        </article>
    );
}

function InventoryChart() {
    const entries = [38, 45, 33, 25, 24, 22];
    const exits = [10, 12, 18, 3, 6, 9];
    const stock = [82, 80, 52, 49, 50, 52];

    return (
        <div className="mt-3">
            <div className="flex justify-end gap-4 text-[9px] text-slate-500">
                <span>
                    ■ <i className="text-blue-600">Entradas</i>
                </span>
                <span>
                    ■ <i className="text-rose-500">Salidas</i>
                </span>
                <span>● Existencia</span>
            </div>
            <div className="relative mt-2 flex h-32 items-end justify-around border-b border-slate-200 px-4">
                {entries.map((entry, index) => (
                    <div
                        key={index}
                        className="relative flex h-full flex-1 items-end justify-center gap-1"
                    >
                        <span
                            className="w-5 bg-blue-500"
                            style={{ height: `${entry * 1.7}%` }}
                        />
                        <span
                            className="w-5 bg-rose-500"
                            style={{ height: `${exits[index] * 1.7}%` }}
                        />
                        <span
                            className="absolute left-1/2 size-2 -translate-x-1/2 rounded-full bg-[#10213f]"
                            style={{ bottom: `${stock[index]}%` }}
                        />
                        <span className="absolute -bottom-5 text-[9px] text-slate-500">
                            {['Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep'][index]}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function InventoryWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [query, setQuery] = useState('');
    const [catalogTab, setCatalogTab] = useState('Todos');
    const [detailTab, setDetailTab] = useState('Resumen');
    const [notice, setNotice] = useState('');
    const selected =
        products.find((product) => product.id === selectedId) ?? products[0];
    const filtered = useMemo(
        () =>
            products.filter((product) => {
                const matchesQuery =
                    `${product.code} ${product.name} ${product.category}`
                        .toLowerCase()
                        .includes(query.toLowerCase());
                const matchesTab =
                    catalogTab === 'Todos' ||
                    (catalogTab === 'Stock bajo' && product.status === 'low') ||
                    (catalogTab === 'Sin existencia' &&
                        product.status === 'out') ||
                    catalogTab === 'Más vendidos' ||
                    catalogTab === 'Por vencer';

                return matchesQuery && matchesTab;
            }),
        [catalogTab, query],
    );

    return (
        <main className="min-h-full bg-[#f4f7fb] p-3 text-[#14213b] sm:p-5">
            <Head title={`Inventario · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Inventario</span>
                    <span>›</span>
                    <span>Productos</span>
                </div>
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">Productos</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestiona tu catálogo de productos, existencias,
                            movimientos y valorización.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Selecciona un archivo CSV o Excel para importar productos.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <ArrowDownToLine className="size-4" /> Importar
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'La exportación del catálogo quedó preparada.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <ArrowUpFromLine className="size-4" /> Exportar
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Formulario para nuevo producto abierto como borrador.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-700/20"
                        >
                            <Plus className="size-4" /> Nuevo producto
                        </button>
                    </div>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <Sparkles className="size-4" />
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
                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                    <InventoryMetric
                        label="Valor total de inventario"
                        value={money(24580000)}
                        detail="↑ 12.5%"
                        icon={PackageCheck}
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <InventoryMetric
                        label="Productos activos"
                        value="325"
                        detail="↑ 5.2%"
                        icon={Boxes}
                        tone="bg-blue-50 text-blue-600"
                    />
                    <InventoryMetric
                        label="Stock bajo"
                        value="8"
                        detail="Ver productos"
                        icon={AlertTriangle}
                        tone="bg-amber-50 text-amber-600"
                    />
                    <InventoryMetric
                        label="Sin existencia"
                        value="12"
                        detail="Ver productos"
                        icon={CircleMinus}
                        tone="bg-rose-50 text-rose-600"
                    />
                    <InventoryMetric
                        label="Por vencer"
                        value="5"
                        detail="Próximos 30 días"
                        icon={CalendarDays}
                        tone="bg-orange-50 text-orange-600"
                    />
                </section>

                <div className="grid gap-4 2xl:grid-cols-[1fr_.8fr]">
                    <section className={`${panel} overflow-hidden`}>
                        <nav className="flex overflow-x-auto border-b border-slate-200 px-3">
                            {[
                                'Todos',
                                'Stock bajo',
                                'Sin existencia',
                                'Más vendidos',
                                'Por vencer',
                            ].map((tab) => (
                                <button
                                    key={tab}
                                    type="button"
                                    onClick={() => setCatalogTab(tab)}
                                    className={`border-b-2 px-4 py-3 text-xs font-semibold whitespace-nowrap ${catalogTab === tab ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {tab}
                                </button>
                            ))}
                        </nav>
                        <div className="space-y-2 border-b border-slate-200 p-3">
                            <label className="relative block">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar productos, código o descripción..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-blue-500"
                                />
                            </label>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_.8fr_auto]">
                                {[
                                    'Todas las categorías',
                                    'Todas las bodegas',
                                    'Todos los estados',
                                ].map((label) => (
                                    <button
                                        key={label}
                                        type="button"
                                        className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs font-medium"
                                    >
                                        {label}
                                        <ChevronDown className="size-4 text-slate-400" />
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className="flex items-center justify-center gap-2 rounded-lg border border-slate-200 px-4 py-2.5 text-xs font-semibold text-blue-700"
                                >
                                    <Filter className="size-4" /> Filtros
                                </button>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[790px] text-left text-[10px]">
                                <thead className="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">□</th>
                                        <th>Código</th>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th>Bodega principal</th>
                                        <th className="text-center">
                                            Existencia
                                        </th>
                                        <th className="text-right">
                                            Precio venta
                                        </th>
                                        <th className="text-center">Estado</th>
                                        <th className="pr-3 text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((product) => (
                                        <tr
                                            key={product.id}
                                            onClick={() =>
                                                setSelectedId(product.id)
                                            }
                                            className={`cursor-pointer hover:bg-blue-50/60 ${selected.id === product.id ? 'bg-blue-50' : ''}`}
                                        >
                                            <td className="px-3 py-2">□</td>
                                            <td className="text-blue-700">
                                                {product.code}
                                            </td>
                                            <td className="font-semibold">
                                                <span className="mr-2 text-base">
                                                    {product.icon}
                                                </span>
                                                {product.name}
                                            </td>
                                            <td>{product.category}</td>
                                            <td>{product.warehouse}</td>
                                            <td
                                                className={`text-center font-bold ${product.stock <= 5 ? 'text-red-600' : 'text-emerald-700'}`}
                                            >
                                                {product.stock}
                                            </td>
                                            <td className="text-right font-semibold">
                                                {money(product.price)}
                                            </td>
                                            <td className="text-center">
                                                <span
                                                    className={`rounded-full px-2 py-1 ${statuses[product.status].className}`}
                                                >
                                                    ●{' '}
                                                    {
                                                        statuses[product.status]
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
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay productos que coincidan con los
                                    filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 325 productos
                            </span>
                            <div className="flex items-center gap-1">
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                {['1', '2', '3', '4', '5', '…', '41'].map(
                                    (page) => (
                                        <button
                                            key={page}
                                            type="button"
                                            className={`grid size-8 place-items-center rounded ${page === '1' ? 'bg-blue-600 text-white' : 'border border-slate-200'}`}
                                        >
                                            {page}
                                        </button>
                                    ),
                                )}
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-3 py-2"
                            >
                                8 por página⌄
                            </button>
                        </footer>
                    </section>

                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex flex-wrap items-start gap-4 p-4">
                            <span className="grid size-20 place-items-center rounded-xl border border-slate-200 bg-slate-50 text-5xl">
                                {selected.icon}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-lg font-bold">
                                        {selected.name}
                                    </h2>
                                    <span
                                        className={`rounded-full px-2 py-1 text-[10px] ${statuses[selected.status].className}`}
                                    >
                                        ● {statuses[selected.status].label}
                                    </span>
                                </div>
                                <p className="mt-1 text-xs font-semibold text-blue-700">
                                    {selected.code}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <span className="rounded bg-slate-100 px-2 py-1 text-[10px]">
                                        {selected.category}
                                    </span>
                                    <span className="rounded bg-slate-100 px-2 py-1 text-[10px]">
                                        Equipo de cómputo
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(`Editando ${selected.name}.`)
                                }
                                className="flex items-center gap-2 rounded-lg border border-blue-500 px-4 py-2 text-xs font-semibold text-blue-700"
                            >
                                <Edit3 className="size-4" /> Editar
                            </button>
                            <div className="flex w-full gap-10 border-t border-slate-100 pt-3">
                                <div>
                                    <p className="text-[10px] text-slate-500">
                                        Precio de venta
                                    </p>
                                    <strong className="text-xl">
                                        {money(selected.price)}
                                    </strong>
                                </div>
                                <div>
                                    <p className="text-[10px] text-slate-500">
                                        Costo promedio
                                    </p>
                                    <strong className="text-xl">
                                        {money(selected.cost)}
                                    </strong>
                                </div>
                            </div>
                        </div>
                        <nav className="flex overflow-x-auto border-y border-slate-200 px-3">
                            {[
                                'Resumen',
                                'Kardex',
                                'Bodegas',
                                'Compras',
                                'Ventas',
                                'Lotes/Series',
                                'Ajustes',
                            ].map((tab) => (
                                <button
                                    key={tab}
                                    type="button"
                                    onClick={() => setDetailTab(tab)}
                                    className={`border-b-2 px-4 py-3 text-xs font-medium whitespace-nowrap ${detailTab === tab ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {tab}
                                </button>
                            ))}
                        </nav>
                        {detailTab === 'Resumen' ? (
                            <div className="space-y-4 p-4">
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                    {(
                                        [
                                            [
                                                'Existencia total',
                                                selected.stock,
                                                Boxes,
                                                'bg-blue-50 text-blue-600',
                                            ],
                                            [
                                                'Disponible',
                                                selected.stock -
                                                    selected.reserved,
                                                ShieldCheck,
                                                'bg-emerald-50 text-emerald-600',
                                            ],
                                            [
                                                'Reservado',
                                                selected.reserved,
                                                CircleAlert,
                                                'bg-rose-50 text-rose-600',
                                            ],
                                            [
                                                'Valor en inventario',
                                                money(
                                                    selected.stock *
                                                        selected.cost,
                                                ),
                                                PackageOpen,
                                                'bg-blue-50 text-blue-600',
                                            ],
                                        ] satisfies [
                                            string,
                                            string | number,
                                            LucideIcon,
                                            string,
                                        ][]
                                    ).map(([label, value, Icon, tone]) => (
                                        <article
                                            key={String(label)}
                                            className="rounded-lg border border-slate-200 p-3"
                                        >
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`grid size-8 place-items-center rounded-lg ${tone}`}
                                                >
                                                    <Icon className="size-4" />
                                                </span>
                                                <div>
                                                    <p className="text-[9px] text-slate-500">
                                                        {label}
                                                    </p>
                                                    <strong className="text-base">
                                                        {value}
                                                    </strong>
                                                </div>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <section>
                                        <h3 className="text-sm font-bold">
                                            Información general
                                        </h3>
                                        <dl className="mt-3 grid grid-cols-2 gap-3 text-[10px]">
                                            <div className="col-span-2">
                                                <dt className="font-semibold">
                                                    Descripción
                                                </dt>
                                                <dd className="text-slate-500">
                                                    {selected.description}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="font-semibold">
                                                    Unidad de medida
                                                </dt>
                                                <dd>Unidad</dd>
                                            </div>
                                            <div>
                                                <dt className="font-semibold">
                                                    Categoría
                                                </dt>
                                                <dd>{selected.category}</dd>
                                            </div>
                                            <div>
                                                <dt className="font-semibold">
                                                    Bodega principal
                                                </dt>
                                                <dd>{selected.warehouse}</dd>
                                            </div>
                                            <div>
                                                <dt className="font-semibold">
                                                    Existencia mínima
                                                </dt>
                                                <dd>5</dd>
                                            </div>
                                        </dl>
                                    </section>
                                    <section>
                                        <h3 className="text-sm font-bold">
                                            Cuentas contables
                                        </h3>
                                        <div className="mt-3 overflow-hidden rounded-lg border border-slate-200 text-[10px]">
                                            {[
                                                ['Inventario', '1.1.05.001'],
                                                [
                                                    'Costo de ventas',
                                                    '5.1.01.001',
                                                ],
                                                ['Ventas', '4.1.01.001'],
                                                ['IVA ventas', '2.1.04.001'],
                                            ].map(([label, code]) => (
                                                <div
                                                    key={label}
                                                    className="flex justify-between border-b border-slate-100 p-2 last:border-0"
                                                >
                                                    <span>{label}</span>
                                                    <strong>{code}</strong>
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                </div>
                                <section>
                                    <h3 className="text-sm font-bold">
                                        Movimiento de inventario (últimos 6
                                        meses)
                                    </h3>
                                    <InventoryChart />
                                </section>
                            </div>
                        ) : (
                            <div className="grid min-h-96 place-items-center p-8 text-center">
                                <div>
                                    <Boxes className="mx-auto size-10 text-blue-500" />
                                    <h3 className="mt-3 font-bold">
                                        {detailTab} de {selected.name}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Consulta el detalle operativo del
                                        producto seleccionado.
                                    </p>
                                </div>
                            </div>
                        )}
                    </section>
                </div>

                <div className="grid gap-4 xl:grid-cols-3">
                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex items-center justify-between px-4 py-3">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <BarChart3 className="size-4 text-blue-600" />{' '}
                                Últimos movimientos
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todos
                            </button>
                        </div>
                        <table className="w-full text-[10px]">
                            <thead className="bg-slate-50 text-slate-500">
                                <tr>
                                    <th className="p-2 text-left">Fecha</th>
                                    <th>Documento</th>
                                    <th>Tipo</th>
                                    <th>Cantidad</th>
                                    <th>Bodega</th>
                                </tr>
                            </thead>
                            <tbody>
                                {[
                                    [
                                        '08/09/2026',
                                        'VTA-001254',
                                        'Salida',
                                        '-2',
                                    ],
                                    [
                                        '05/09/2026',
                                        'COM-000985',
                                        'Entrada',
                                        '+10',
                                    ],
                                    [
                                        '02/09/2026',
                                        'TR-000125',
                                        'Transferencia',
                                        '-3',
                                    ],
                                    ['28/08/2026', 'AJ-000087', 'Ajuste', '+1'],
                                ].map((row) => (
                                    <tr
                                        key={row[1]}
                                        className="border-t border-slate-100"
                                    >
                                        <td className="p-2">{row[0]}</td>
                                        <td className="font-semibold text-blue-600">
                                            {row[1]}
                                        </td>
                                        <td>{row[2]}</td>
                                        <td
                                            className={
                                                row[3].startsWith('+')
                                                    ? 'text-emerald-600'
                                                    : 'text-red-600'
                                            }
                                        >
                                            {row[3]}
                                        </td>
                                        <td>Bodega Central</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex items-center justify-between px-4 py-3">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <Warehouse className="size-4 text-blue-600" />{' '}
                                Bodegas
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todas
                            </button>
                        </div>
                        <table className="w-full text-[10px]">
                            <thead className="bg-slate-50 text-slate-500">
                                <tr>
                                    <th className="p-2 text-left">Bodega</th>
                                    <th>Existencia</th>
                                    <th>Reservado</th>
                                    <th>Disponible</th>
                                </tr>
                            </thead>
                            <tbody>
                                {[
                                    ['Bodega Central', 8, 2, 6],
                                    ['Sucursal Puerto Barrios', 3, 0, 3],
                                    ['Sucursal Santo Tomás', 1, 0, 1],
                                ].map((row) => (
                                    <tr
                                        key={String(row[0])}
                                        className="border-t border-slate-100"
                                    >
                                        <td className="p-2">{row[0]}</td>
                                        <td className="text-center">
                                            {row[1]}
                                        </td>
                                        <td className="text-center">
                                            {row[2]}
                                        </td>
                                        <td className="text-center">
                                            {row[3]}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t border-slate-200 font-bold">
                                    <td className="p-2">Total</td>
                                    <td className="text-center">12</td>
                                    <td className="text-center">2</td>
                                    <td className="text-center">10</td>
                                </tr>
                            </tfoot>
                        </table>
                    </section>
                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex items-center justify-between px-4 py-3">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <CircleAlert className="size-4 text-blue-600" />{' '}
                                Alertas y recomendaciones
                            </h2>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        'Asistente IA revisó rotación, stock mínimo y demanda.',
                                    )
                                }
                                className="flex items-center gap-1 rounded bg-violet-50 px-2 py-1 text-[10px] text-violet-700"
                            >
                                <Sparkles className="size-3" /> Asistente IA
                            </button>
                        </div>
                        <div className="divide-y divide-slate-100">
                            {(
                                [
                                    [
                                        AlertTriangle,
                                        'Stock bajo',
                                        'La existencia está por debajo del mínimo (5 unidades).',
                                        'text-amber-600',
                                    ],
                                    [
                                        Info,
                                        'Rotación baja',
                                        'Este producto tiene una rotación 38% menor al promedio.',
                                        'text-blue-600',
                                    ],
                                    [
                                        PackageCheck,
                                        'Sugerencia de compra',
                                        'Se recomienda comprar 20 unidades para cubrir la demanda.',
                                        'text-emerald-600',
                                    ],
                                    [
                                        ShieldCheck,
                                        '2 unidades reservadas',
                                        'Existen 2 unidades en pedidos pendientes.',
                                        'text-blue-600',
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
                                    <Icon
                                        className={`size-4 shrink-0 ${tone}`}
                                    />
                                    <div>
                                        <strong className="block text-[10px]">
                                            {title}
                                        </strong>
                                        <p className="text-[9px] text-slate-500">
                                            {detail}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Inventario</span>
                    <span className="flex items-center gap-1">
                        <Check className="size-3 text-emerald-600" />{' '}
                        Valorización en GTQ
                    </span>
                </footer>
            </div>
        </main>
    );
}
