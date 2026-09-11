import { Head } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    CircleDollarSign,
    ClipboardList,
    Clock3,
    Download,
    Eye,
    FileText,
    Filter,
    MoreHorizontal,
    PackageCheck,
    Pencil,
    Plus,
    ReceiptText,
    Search,
    ShoppingCart,
    Truck,
    UserRound,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type OrderStatus =
    'Emitida' | 'Recibida' | 'En aprobación' | 'Parcialmente recibida';
type Order = {
    id: number;
    number: string;
    supplier: string;
    date: string;
    delivery: string;
    total: string;
    status: OrderStatus;
    buyer: string;
    budget: string;
};
const orders: Order[] = [
    {
        id: 1,
        number: 'OC-2025-0048',
        supplier: 'Equipos y Soluciones, S.A.',
        date: '12/03/2025',
        delivery: '28/03/2025',
        total: 'Q 45,230.00',
        status: 'Emitida',
        buyer: 'Juan Martínez',
        budget: 'TI - Equipos',
    },
    {
        id: 2,
        number: 'OC-2025-0047',
        supplier: 'Papelería Universal, S.A.',
        date: '10/03/2025',
        delivery: '18/03/2025',
        total: 'Q 6,540.00',
        status: 'Recibida',
        buyer: 'Ana López',
        budget: 'Administración',
    },
    {
        id: 3,
        number: 'OC-2025-0046',
        supplier: 'Servicios Profesionales, S.C.',
        date: '08/03/2025',
        delivery: '20/03/2025',
        total: 'Q 28,000.00',
        status: 'En aprobación',
        buyer: 'Carlos Ruiz',
        budget: 'Consultorías',
    },
    {
        id: 4,
        number: 'OC-2025-0045',
        supplier: 'Aceros del Norte, S.A.',
        date: '05/03/2025',
        delivery: '25/03/2025',
        total: 'Q 12,750.00',
        status: 'Parcialmente recibida',
        buyer: 'María González',
        budget: 'Producción',
    },
    {
        id: 5,
        number: 'OC-2025-0044',
        supplier: 'Tecnología Global, S.A.',
        date: '03/03/2025',
        delivery: '15/03/2025',
        total: 'Q 98,500.00',
        status: 'Recibida',
        buyer: 'Luis Herrera',
        budget: 'TI - Infraestructura',
    },
];
const items = [
    [
        '1',
        'Laptop Dell Latitude 5440',
        '10',
        'Unidad',
        'Q 3,850.00',
        'Q 38,500.00',
        '4 (parcial)',
    ],
    [
        '2',
        'Monitor Dell 24” P2422H',
        '10',
        'Unidad',
        'Q 670.00',
        'Q 6,700.00',
        '4 (parcial)',
    ],
    [
        '3',
        'Docking Station USB-C',
        '10',
        'Unidad',
        'Q 403.00',
        'Q 4,030.00',
        '0',
    ],
];
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const statusStyle: Record<OrderStatus, string> = {
    Emitida: 'bg-blue-100 text-blue-700',
    Recibida: 'bg-emerald-100 text-emerald-700',
    'En aprobación': 'bg-amber-100 text-amber-700',
    'Parcialmente recibida': 'bg-green-100 text-green-700',
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
    detail: string;
    tone: string;
}) {
    return (
        <article
            className={`${panel} flex min-h-28 min-w-0 items-center gap-4 overflow-hidden p-5`}
        >
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-full ${tone}`}
            >
                <Icon className="size-7" />
            </span>
            <div className="min-w-0">
                <p className="text-sm text-slate-600">{label}</p>
                <div className="flex flex-wrap items-end gap-3">
                    <strong className="text-3xl text-slate-950">{value}</strong>
                    <span className="pb-1 text-xs font-semibold text-emerald-600">
                        {detail}
                    </span>
                </div>
                <p className="text-[10px] text-slate-500">vs. mes anterior</p>
            </div>
        </article>
    );
}

export default function PurchaseOrdersWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [section, setSection] = useState('Todas');
    const [detailTab, setDetailTab] = useState('Detalle de la orden');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const selected =
        orders.find((order) => order.id === selectedId) ?? orders[0];
    const filtered = useMemo(
        () =>
            orders.filter(
                (order) =>
                    (section === 'Todas' || order.status === section) &&
                    `${order.number} ${order.supplier} ${order.buyer} ${order.budget}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [query, section],
    );
    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3200);
    };
    const workflow = [
        [ClipboardList, 'Solicitud', 'Se genera la solicitud de compra'],
        [UserRound, 'Aprobación', 'Revisión y autorización por responsables'],
        [ShoppingCart, 'Orden', 'Emisión de la orden de compra'],
        [Truck, 'Recepción', 'Registro de bienes o servicios recibidos'],
        [FileText, 'Factura', 'Conciliación con factura del proveedor'],
    ] as const;

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Órdenes de Compra · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">
                            Órdenes de Compra
                        </h1>
                        <p className="mt-1 text-base text-slate-500">
                            Control y aprobación previa de adquisiciones
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            action(
                                'Formulario de nueva orden de compra preparado.',
                            )
                        }
                        className="flex items-center gap-2 rounded-lg bg-[#74000a] px-5 py-3 text-sm font-semibold text-white"
                    >
                        <Plus className="size-5" />
                        Nueva Orden de Compra
                    </button>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs text-emerald-800">
                        <span className="flex items-center gap-2">
                            <Check className="size-4" />
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
                    <Metric
                        icon={ClipboardList}
                        label="Órdenes activas"
                        value="24"
                        detail="▲ 8%"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                    <Metric
                        icon={Clock3}
                        label="En aprobación"
                        value="6"
                        detail="▲ 2%"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                    <Metric
                        icon={PackageCheck}
                        label="Recibidas"
                        value="32"
                        detail="▲ 14%"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                    <Metric
                        icon={CircleDollarSign}
                        label="Comprometido presupuestario"
                        value="Q 487,320"
                        detail="▲ 11%"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                </section>
                <section
                    className={`${panel} grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-5`}
                >
                    {workflow.map(([Icon, title, description], index) => (
                        <div key={title} className="flex items-center gap-3">
                            <span
                                className={`grid size-12 shrink-0 place-items-center rounded-full ${index === 0 ? 'bg-[#74000a] text-white' : 'bg-slate-100 text-slate-700'}`}
                            >
                                <Icon className="size-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <strong className="block text-xs">
                                    {title}
                                </strong>
                                <small className="block text-[10px] leading-4 text-slate-500">
                                    {description}
                                </small>
                            </span>
                            {index < 4 && (
                                <ChevronRight className="hidden size-5 text-slate-400 lg:block" />
                            )}
                        </div>
                    ))}
                </section>
                <section className={`${panel} overflow-hidden`}>
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
                        <nav className="flex flex-wrap gap-1">
                            {[
                                'Todas',
                                'En aprobación',
                                'Aprobadas',
                                'Emitida',
                                'Parcialmente recibida',
                                'Recibida',
                                'Canceladas',
                            ].map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => setSection(item)}
                                    className={`border-b-2 px-3 py-2 text-xs font-semibold ${section === item ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                                >
                                    {item}
                                </button>
                            ))}
                        </nav>
                        <div className="flex flex-wrap gap-2">
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar órdenes..."
                                    className="w-56 rounded-lg border py-2 pr-3 pl-9 text-xs"
                                />
                            </label>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        `${filtered.length} órdenes coinciden con los filtros.`,
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Filter className="size-4" />
                                Filtros
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action('Exportación de órdenes preparada.')
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Download className="size-4" />
                                Exportar
                                <ChevronDown className="size-3" />
                            </button>
                        </div>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1000px] text-left text-xs">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3">No.</th>
                                    <th>Proveedor</th>
                                    <th>Fecha</th>
                                    <th>Entrega</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th>Comprador</th>
                                    <th>Presupuesto</th>
                                    <th className="pr-4 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filtered.map((order) => (
                                    <tr
                                        key={order.id}
                                        onClick={() => setSelectedId(order.id)}
                                        className={`cursor-pointer hover:bg-rose-50/50 ${selected.id === order.id ? 'bg-rose-50' : ''}`}
                                    >
                                        <td className="px-4 py-3 font-semibold">
                                            {order.number}
                                        </td>
                                        <td>{order.supplier}</td>
                                        <td>{order.date}</td>
                                        <td>{order.delivery}</td>
                                        <td className="font-semibold">
                                            {order.total}
                                        </td>
                                        <td>
                                            <span
                                                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold ${statusStyle[order.status]}`}
                                            >
                                                <CheckCircle2 className="size-3" />
                                                {order.status}
                                            </span>
                                        </td>
                                        <td>{order.buyer}</td>
                                        <td>{order.budget}</td>
                                        <td className="pr-4">
                                            <div className="flex justify-center gap-4">
                                                <Eye className="size-4" />
                                                <Pencil className="size-4" />
                                                <MoreHorizontal className="size-4" />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {!filtered.length && (
                            <div className="p-10 text-center text-sm text-slate-400">
                                No hay órdenes para esta selección.
                            </div>
                        )}
                    </div>
                </section>
                <section className={`${panel} overflow-hidden`}>
                    <header className="flex flex-wrap items-center gap-4 border-b p-4">
                        <span className="grid size-11 place-items-center rounded-lg bg-rose-50 text-[#74000a]">
                            <ReceiptText className="size-5" />
                        </span>
                        <div className="min-w-56 flex-1">
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold">
                                    {selected.number}
                                </h2>
                                <span
                                    className={`rounded-full px-2 py-1 text-[10px] font-semibold ${statusStyle[selected.status]}`}
                                >
                                    {selected.status}
                                </span>
                            </div>
                            <p className="text-xs text-slate-500">
                                Proveedor: {selected.supplier}
                            </p>
                        </div>
                        {[
                            ['Fecha de emisión', selected.date],
                            ['Fecha de entrega', selected.delivery],
                            ['Comprador', selected.buyer],
                            ['Centro de costo', selected.budget],
                            ['Total de la orden', selected.total],
                        ].map(([label, value]) => (
                            <div key={label} className="border-l px-4">
                                <small className="block text-[10px] text-slate-500">
                                    {label}
                                </small>
                                <strong className="text-xs">{value}</strong>
                            </div>
                        ))}
                        <button
                            type="button"
                            className="grid size-9 place-items-center rounded border"
                        >
                            <MoreHorizontal className="size-4" />
                        </button>
                    </header>
                    <nav className="flex flex-wrap border-b">
                        {[
                            'Detalle de la orden',
                            'Recepción',
                            'Facturas',
                            'Historial de aprobaciones',
                            'Observaciones',
                            'Documentos',
                        ].map((item) => (
                            <button
                                key={item}
                                type="button"
                                onClick={() => setDetailTab(item)}
                                className={`border-b-2 px-5 py-3 text-xs font-semibold ${detailTab === item ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                            >
                                {item}
                            </button>
                        ))}
                    </nav>
                    {detailTab === 'Detalle de la orden' ? (
                        <div className="grid divide-y xl:grid-cols-[1.35fr_1fr] xl:divide-x xl:divide-y-0">
                            <article className="p-4">
                                <h3 className="mb-3 flex items-center gap-2 text-sm font-bold">
                                    <PackageCheck className="size-4 text-emerald-600" />
                                    Artículos ordenados
                                </h3>
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full min-w-[650px] text-xs">
                                        <thead className="bg-slate-50 text-left">
                                            <tr>
                                                {[
                                                    'No.',
                                                    'Descripción',
                                                    'Cantidad',
                                                    'Unidad',
                                                    'Precio unitario',
                                                    'Total',
                                                    'Recibido',
                                                ].map((item) => (
                                                    <th
                                                        key={item}
                                                        className="p-3"
                                                    >
                                                        {item}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {items.map((item) => (
                                                <tr key={item[0]}>
                                                    {item.map(
                                                        (value, index) => (
                                                            <td
                                                                key={value}
                                                                className={`p-3 ${index === 6 ? 'font-semibold text-orange-600' : ''}`}
                                                            >
                                                                {value}
                                                            </td>
                                                        ),
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </article>
                            <aside className="space-y-4 p-4">
                                <section>
                                    <div className="flex justify-between">
                                        <h3 className="flex items-center gap-2 text-sm font-bold">
                                            <CheckCircle2 className="size-4 text-emerald-600" />
                                            Comparación con factura
                                        </h3>
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 rounded border px-3 py-1 text-[10px]"
                                        >
                                            <Pencil className="size-3" />
                                            Editar
                                        </button>
                                    </div>
                                    <div className="mt-3 flex gap-3 rounded-lg bg-emerald-50 p-4">
                                        <CheckCircle2 className="size-5 text-emerald-600" />
                                        <span>
                                            <strong className="block text-xs">
                                                Sin facturas registradas
                                            </strong>
                                            <small className="text-[10px] text-slate-500">
                                                Aún no se han asociado facturas
                                                a esta orden de compra.
                                            </small>
                                        </span>
                                    </div>
                                </section>
                                <section>
                                    <h3 className="mb-2 text-sm font-bold">
                                        Observaciones
                                    </h3>
                                    <p className="rounded-lg bg-slate-100 p-4 text-xs text-slate-600">
                                        Equipos para renovación del área de TI.
                                        Entrega por lotes según disponibilidad
                                        del proveedor.
                                    </p>
                                </section>
                            </aside>
                        </div>
                    ) : (
                        <div className="grid min-h-72 place-items-center p-8 text-center">
                            <div>
                                <ClipboardList className="mx-auto size-12 text-[#74000a]" />
                                <h3 className="mt-3 font-bold">{detailTab}</h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    Información vinculada a {selected.number}.
                                </p>
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </main>
    );
}
