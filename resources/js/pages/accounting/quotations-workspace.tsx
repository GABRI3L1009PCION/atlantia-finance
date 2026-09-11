import { Head } from '@inertiajs/react';
import {
    ArrowDownUp,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Eye,
    FileDown,
    FileText,
    FilterX,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Send,
    ShoppingCart,
    TrendingUp,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type QuoteStatus =
    'Aceptada' | 'Enviada' | 'Pendiente' | 'Convertida' | 'Borrador';
type Quote = {
    id: number;
    number: string;
    client: string;
    nit: string;
    address: string;
    date: string;
    expiration: string;
    total: string;
    status: QuoteStatus;
    seller: string;
    description: string;
};

const quotes: Quote[] = [
    {
        id: 1,
        number: 'COT-000125',
        client: 'Distribuidora del Pacífico, S.A.',
        nit: '5489123-6',
        address: 'Puerto Barrios, Izabal',
        date: '20/01/2025',
        expiration: '04/02/2025',
        total: 'Q 12,450.00',
        status: 'Aceptada',
        seller: 'María López',
        description: 'Renovación de equipo administrativo',
    },
    {
        id: 2,
        number: 'COT-000124',
        client: 'Tecnologías Globales, S.A.',
        nit: '1234567-8',
        address: 'Av. Reforma 12-34, Zona 10, Guatemala',
        date: '18/01/2025',
        expiration: '01/02/2025',
        total: 'Q 34,160.00',
        status: 'Enviada',
        seller: 'Juan Pérez',
        description: 'Propuesta de soluciones tecnológicas para oficinas',
    },
    {
        id: 3,
        number: 'COT-000123',
        client: 'Servicios Empresariales, S.A.',
        nit: '7854621-3',
        address: 'Zona 4, Ciudad de Guatemala',
        date: '15/01/2025',
        expiration: '30/01/2025',
        total: 'Q 5,200.00',
        status: 'Pendiente',
        seller: 'Ana Gómez',
        description: 'Servicios profesionales mensuales',
    },
    {
        id: 4,
        number: 'COT-000122',
        client: 'Industrias Centroamericanas, S.A.',
        nit: '4682135-9',
        address: 'Villa Nueva, Guatemala',
        date: '12/01/2025',
        expiration: '26/01/2025',
        total: 'Q 15,800.00',
        status: 'Convertida',
        seller: 'Juan Pérez',
        description: 'Equipo y soporte técnico',
    },
    {
        id: 5,
        number: 'COT-000121',
        client: 'Comercializadora Rivera, S.A.',
        nit: '2347651-2',
        address: 'Mixco, Guatemala',
        date: '10/01/2025',
        expiration: '25/01/2025',
        total: 'Q 3,950.00',
        status: 'Borrador',
        seller: 'Luis Martínez',
        description: 'Licenciamiento anual',
    },
];

const products = [
    ['LAP-001', 'Laptop Business 14”', '5', 'Q 4,500.00', 'Q 22,500.00'],
    ['MON-002', 'Monitor 24” FHD', '5', 'Q 1,250.00', 'Q 6,250.00'],
    ['SER-003', 'Instalación y configuración', '5', 'Q 350.00', 'Q 1,750.00'],
];

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const wine = '#74000a';
const statusStyle: Record<QuoteStatus, string> = {
    Aceptada: 'bg-emerald-100 text-emerald-700',
    Enviada: 'bg-blue-100 text-blue-700',
    Pendiente: 'bg-amber-100 text-amber-700',
    Convertida: 'bg-green-100 text-green-700',
    Borrador: 'bg-slate-200 text-slate-700',
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
        <article className={`${panel} flex min-h-28 items-center gap-5 p-5`}>
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-7" />
            </span>
            <div>
                <p className="text-sm text-slate-600">{label}</p>
                <strong className="text-3xl text-slate-950">{value}</strong>
                <p className="mt-1 text-xs text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

function SelectBox({ label, value }: { label: string; value: string }) {
    return (
        <label className="min-w-40 flex-1 text-xs font-semibold text-slate-700">
            {label}
            <button
                type="button"
                className="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2.5 font-normal text-slate-600"
            >
                {value}
                <ChevronDown className="size-4" />
            </button>
        </label>
    );
}

export default function QuotationsWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(2);
    const [status, setStatus] = useState('Todos');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const [page, setPage] = useState(1);
    const selected =
        quotes.find((quote) => quote.id === selectedId) ?? quotes[1];
    const filtered = useMemo(
        () =>
            quotes.filter(
                (quote) =>
                    (status === 'Todos' || quote.status === status) &&
                    `${quote.number} ${quote.client} ${quote.seller}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [query, status],
    );

    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3200);
    };

    return (
        <main className="min-h-full bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Cotizaciones · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span>{' '}
                            <b className="text-slate-700">Cotizaciones</b>
                        </p>
                        <h1 className="text-3xl font-bold">Cotizaciones</h1>
                        <p className="mt-1 text-base text-slate-500">
                            Propuestas comerciales y seguimiento
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            action('Nueva cotización preparada para completar.')
                        }
                        className="flex items-center gap-2 rounded-lg bg-[#74000a] px-5 py-3 text-sm font-semibold text-white shadow-sm"
                    >
                        <Plus className="size-5" /> Nueva cotización
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

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={FileText}
                        label="Cotizaciones del mes"
                        value="24"
                        detail="↑ +26% vs. mes anterior"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                    <Metric
                        icon={CheckCircle2}
                        label="Aceptadas"
                        value="8"
                        detail="33% del total"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Clock3}
                        label="Pendientes"
                        value="11"
                        detail="46% del total"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                    <Metric
                        icon={TrendingUp}
                        label="Convertidas a venta"
                        value="6"
                        detail="25% del total"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                </section>

                <section
                    className={`${panel} flex flex-wrap items-end gap-4 p-4`}
                >
                    <label className="min-w-48 flex-1 text-xs font-semibold">
                        Estado
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 font-normal text-slate-600 outline-none"
                        >
                            {[
                                'Todos',
                                'Aceptada',
                                'Enviada',
                                'Pendiente',
                                'Convertida',
                                'Borrador',
                            ].map((value) => (
                                <option key={value}>{value}</option>
                            ))}
                        </select>
                    </label>
                    <label className="relative min-w-56 flex-1 text-xs font-semibold">
                        Cliente
                        <Search className="absolute bottom-3 left-3 size-4 text-slate-400" />
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Todos los clientes"
                            className="mt-1 w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 font-normal outline-none"
                        />
                    </label>
                    <SelectBox label="Vendedor" value="Todos los vendedores" />
                    <SelectBox label="Fecha desde" value="01/01/2025" />
                    <SelectBox label="Fecha hasta" value="31/01/2025" />
                    <button
                        type="button"
                        onClick={() => {
                            setStatus('Todos');
                            setQuery('');
                        }}
                        className="flex items-center gap-2 px-2 py-2.5 text-xs font-semibold text-[#74000a]"
                    >
                        <FilterX className="size-4" /> Limpiar filtros
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            action(
                                `${filtered.length} cotizaciones encontradas.`,
                            )
                        }
                        className="rounded-lg bg-[#74000a] px-6 py-2.5 text-xs font-semibold text-white"
                    >
                        Aplicar filtros
                    </button>
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1000px] text-left text-xs">
                            <thead className="border-b border-slate-200 bg-slate-50 text-slate-700">
                                <tr>
                                    <th className="px-5 py-3">No.</th>
                                    <th>Cliente</th>
                                    <th>
                                        Fecha{' '}
                                        <ArrowDownUp className="ml-1 inline size-3" />
                                    </th>
                                    <th>
                                        Vencimiento{' '}
                                        <ArrowDownUp className="ml-1 inline size-3" />
                                    </th>
                                    <th>
                                        Total{' '}
                                        <ArrowDownUp className="ml-1 inline size-3" />
                                    </th>
                                    <th>Estado</th>
                                    <th>Vendedor</th>
                                    <th className="pr-5 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filtered.map((quote) => (
                                    <tr
                                        key={quote.id}
                                        onClick={() => setSelectedId(quote.id)}
                                        className={`cursor-pointer transition hover:bg-rose-50/60 ${selected.id === quote.id ? 'bg-rose-50' : ''}`}
                                    >
                                        <td className="px-5 py-3 font-medium">
                                            {quote.number}
                                        </td>
                                        <td>{quote.client}</td>
                                        <td>{quote.date}</td>
                                        <td>{quote.expiration}</td>
                                        <td className="font-medium">
                                            {quote.total}
                                        </td>
                                        <td>
                                            <span
                                                className={`rounded-full px-3 py-1 font-semibold ${statusStyle[quote.status]}`}
                                            >
                                                {quote.status}
                                            </span>
                                        </td>
                                        <td>{quote.seller}</td>
                                        <td className="pr-5">
                                            <div className="flex justify-center gap-4">
                                                <Eye className="size-4" />
                                                <FileDown className="size-4" />
                                                <MoreHorizontal className="size-4" />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {filtered.length === 0 && (
                            <div className="p-10 text-center text-sm text-slate-400">
                                No hay cotizaciones que coincidan con los
                                filtros.
                            </div>
                        )}
                    </div>
                    <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-xs text-slate-500">
                        <span>
                            Mostrando 1 a {filtered.length} de 24 cotizaciones
                        </span>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                onClick={() => setPage(Math.max(1, page - 1))}
                                className="grid size-8 place-items-center rounded border"
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                            {[1, 2, 3, 4, 5].map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => setPage(item)}
                                    className={`grid size-8 place-items-center rounded ${page === item ? 'bg-[#74000a] text-white' : 'border'}`}
                                >
                                    {item}
                                </button>
                            ))}
                            <button
                                type="button"
                                onClick={() => setPage(Math.min(5, page + 1))}
                                className="grid size-8 place-items-center rounded border"
                            >
                                <ChevronRight className="size-4" />
                            </button>
                        </div>
                    </footer>
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <header className="flex flex-wrap items-start gap-3 border-b border-slate-200 p-4">
                        <span className="grid size-12 place-items-center rounded-xl bg-rose-50 text-[#74000a]">
                            <FileText className="size-6" />
                        </span>
                        <div className="min-w-52 flex-1">
                            <div className="flex items-center gap-2">
                                <h2 className="text-xl font-bold">
                                    {selected.number}
                                </h2>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${statusStyle[selected.status]}`}
                                >
                                    {selected.status}
                                </span>
                            </div>
                            <strong className="text-sm">
                                {selected.client}
                            </strong>
                            <p className="text-xs text-slate-500">
                                {selected.description}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                className="grid size-10 place-items-center rounded-lg border"
                            >
                                <MoreHorizontal className="size-5" />
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        'Cotización abierta en modo edición.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold"
                            >
                                <Pencil className="size-4" />
                                Editar
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        `Cotización preparada para enviar a ${selected.client}.`,
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold"
                            >
                                <Send className="size-4" />
                                Enviar
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        `${selected.number} convertida a venta correctamente.`,
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold"
                            >
                                <ShoppingCart className="size-4" />
                                Convertir a venta
                            </button>
                        </div>
                    </header>
                    <div className="grid divide-y divide-slate-200 xl:grid-cols-[.85fr_1.4fr_1fr] xl:divide-x xl:divide-y-0">
                        <article className="p-5">
                            <h3 className="mb-3 font-bold">
                                Información general
                            </h3>
                            <dl className="grid grid-cols-[120px_1fr] gap-y-2 text-xs">
                                <dt className="text-slate-500">Cliente</dt>
                                <dd>{selected.client}</dd>
                                <dt className="text-slate-500">NIT</dt>
                                <dd>{selected.nit}</dd>
                                <dt className="text-slate-500">Dirección</dt>
                                <dd>{selected.address}</dd>
                                <dt className="text-slate-500">Vendedor</dt>
                                <dd>{selected.seller}</dd>
                                <dt className="text-slate-500">
                                    Fecha de emisión
                                </dt>
                                <dd>{selected.date}</dd>
                                <dt className="text-slate-500">
                                    Fecha de vencimiento
                                </dt>
                                <dd>{selected.expiration} (14 días)</dd>
                                <dt className="text-slate-500">Estado</dt>
                                <dd>
                                    <span
                                        className={`rounded-full px-2 py-1 ${statusStyle[selected.status]}`}
                                    >
                                        {selected.status}
                                    </span>
                                </dd>
                                <dt className="text-slate-500">
                                    Observaciones
                                </dt>
                                <dd>Precios válidos por 15 días.</dd>
                            </dl>
                        </article>
                        <article className="p-5">
                            <h3 className="mb-3 font-bold">
                                Productos y servicios
                            </h3>
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full min-w-[540px] text-xs">
                                    <thead className="bg-slate-50 text-left">
                                        <tr>
                                            <th className="p-3">Código</th>
                                            <th>Descripción</th>
                                            <th>Cantidad</th>
                                            <th>Precio unitario</th>
                                            <th className="pr-3 text-right">
                                                Total
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {products.map((product) => (
                                            <tr key={product[0]}>
                                                {product.map((value, index) => (
                                                    <td
                                                        key={value}
                                                        className={`p-3 ${index >= 2 ? 'text-right' : ''}`}
                                                    >
                                                        {value}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </article>
                        <article className="p-5">
                            <h3 className="mb-4 font-bold">Seguimiento</h3>
                            <div className="relative mb-7 flex justify-between">
                                <div className="absolute top-3 right-5 left-5 h-1 bg-slate-200" />
                                <div className="absolute top-3 left-5 h-1 w-1/3 bg-[#74000a]" />
                                {[
                                    ['Borrador', '10/01/2025'],
                                    ['Enviada', '18/01/2025'],
                                    ['Aceptada', ''],
                                    ['Convertida', ''],
                                ].map(([label, date], index) => (
                                    <div
                                        key={label}
                                        className="relative z-10 text-center"
                                    >
                                        <span
                                            className={`mx-auto grid size-7 place-items-center rounded-full border-2 ${index < 2 ? 'border-[#74000a] bg-[#74000a] text-white' : 'border-slate-300 bg-white'}`}
                                        >
                                            {index < 2 && (
                                                <Check className="size-3" />
                                            )}
                                        </span>
                                        <strong className="mt-1 block text-[10px]">
                                            {label}
                                        </strong>
                                        <small className="text-[9px] text-slate-500">
                                            {date}
                                        </small>
                                    </div>
                                ))}
                            </div>
                            <div className="space-y-2 rounded-lg border p-4 text-xs">
                                <div className="flex justify-between">
                                    <span>Subtotal</span>
                                    <span>Q 30,500.00</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>Descuentos</span>
                                    <span>Q 0.00</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>Impuestos (12%)</span>
                                    <span>Q 3,660.00</span>
                                </div>
                                <div className="mt-3 flex justify-between border-t border-rose-100 bg-rose-50 p-3 text-base font-bold">
                                    <span>Total</span>
                                    <span style={{ color: wine }}>
                                        Q 34,160.00
                                    </span>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </main>
    );
}
