import { Head } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Bell,
    BookOpen,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Download,
    Eye,
    FileText,
    RefreshCcw,
    Scale,
    Search,
    ShoppingCart,
    Tag,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type VatStatus = 'Conciliado' | 'Pendiente' | 'Diferencia';
type VatRow = {
    id: number;
    date: string;
    document: string;
    party: string;
    base: number;
    vat: number;
    exempt: number;
    status: VatStatus;
};
const sales: VatRow[] = [
    {
        id: 1,
        date: '02/01/2025',
        document: 'FACT-0001256',
        party: 'Distribuidora Central, S.A.',
        base: 15000,
        vat: 1800,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 2,
        date: '03/01/2025',
        document: 'FACT-0001257',
        party: 'Inversiones del Norte, S.A.',
        base: 8750,
        vat: 1050,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 3,
        date: '06/01/2025',
        document: 'FACT-0001258',
        party: 'Constructora El Roble, S.A.',
        base: 22300,
        vat: 2676,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 4,
        date: '08/01/2025',
        document: 'FACT-0001259',
        party: 'Servicios Globales, S.A.',
        base: 5600,
        vat: 672,
        exempt: 0,
        status: 'Pendiente',
    },
    {
        id: 5,
        date: '10/01/2025',
        document: 'NC-0000123',
        party: 'Comercializadora Ruiz, S.A.',
        base: -2400,
        vat: -288,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 6,
        date: '12/01/2025',
        document: 'FACT-0001260',
        party: 'Agroexport, S.A.',
        base: 18900,
        vat: 2268,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 7,
        date: '15/01/2025',
        document: 'FACT-0001261',
        party: 'Hospitales Unidos, S.A.',
        base: 12450,
        vat: 1494,
        exempt: 0,
        status: 'Diferencia',
    },
    {
        id: 8,
        date: '18/01/2025',
        document: 'FACT-0001262',
        party: 'Tecnología y Más, S.A.',
        base: 7300,
        vat: 876,
        exempt: 0,
        status: 'Conciliado',
    },
];
const purchases: VatRow[] = [
    {
        id: 11,
        date: '02/01/2025',
        document: 'COMP-000843',
        party: 'Papelería Universal, S.A.',
        base: 6250,
        vat: 750,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 12,
        date: '04/01/2025',
        document: 'COMP-000844',
        party: 'Tecnología Global, S.A.',
        base: 32500,
        vat: 3900,
        exempt: 0,
        status: 'Conciliado',
    },
    {
        id: 13,
        date: '07/01/2025',
        document: 'COMP-000845',
        party: 'Servicios Profesionales, S.C.',
        base: 12000,
        vat: 1440,
        exempt: 0,
        status: 'Pendiente',
    },
    {
        id: 14,
        date: '09/01/2025',
        document: 'COMP-000846',
        party: 'Distribuidora del Caribe, S.A.',
        base: 18900,
        vat: 2268,
        exempt: 350,
        status: 'Conciliado',
    },
    {
        id: 15,
        date: '13/01/2025',
        document: 'COMP-000847',
        party: 'Aceros del Norte, S.A.',
        base: 24000,
        vat: 2880,
        exempt: 0,
        status: 'Diferencia',
    },
    {
        id: 16,
        date: '17/01/2025',
        document: 'COMP-000848',
        party: 'Logística Integral, S.A.',
        base: 9750,
        vat: 1170,
        exempt: 0,
        status: 'Conciliado',
    },
];
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const statusStyle: Record<VatStatus, string> = {
    Conciliado: 'bg-emerald-100 text-emerald-700',
    Pendiente: 'bg-amber-100 text-amber-700',
    Diferencia: 'bg-red-100 text-red-700',
};
const money = (amount: number) =>
    `${amount < 0 ? '-' : ''}Q ${Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;

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
    detail: string;
    tone: string;
    progress?: string;
}) {
    return (
        <article
            className={`${panel} flex min-h-28 min-w-0 items-center gap-4 overflow-hidden p-5`}
        >
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-xs text-slate-600">{label}</p>
                <div className="flex flex-wrap items-end gap-3">
                    <strong className="text-2xl text-slate-950">{value}</strong>
                    <span className="pb-1 text-[10px] font-semibold text-emerald-600">
                        {detail}
                    </span>
                </div>
                {progress ? (
                    <div className="mt-3 h-1.5 rounded-full bg-slate-100">
                        <div
                            className={`h-full rounded-full ${tone.includes('red') ? 'bg-red-400' : 'bg-emerald-500'}`}
                            style={{ width: progress }}
                        />
                    </div>
                ) : (
                    <p className="mt-1 text-[10px] text-slate-500">
                        vs. período anterior
                    </p>
                )}
            </div>
        </article>
    );
}

export default function VatBooksWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [book, setBook] = useState<'Ventas' | 'Compras'>('Ventas');
    const [status, setStatus] = useState('Todos');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const [page, setPage] = useState(1);
    const rows = book === 'Ventas' ? sales : purchases;
    const filtered = useMemo(
        () =>
            rows.filter(
                (row) =>
                    (status === 'Todos' || row.status === status) &&
                    `${row.document} ${row.party}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [query, rows, status],
    );
    const totals = rows.reduce(
        (sum, row) => ({
            base: sum.base + row.base,
            vat: sum.vat + row.vat,
            exempt: sum.exempt + row.exempt,
        }),
        { base: 0, vat: 0, exempt: 0 },
    );
    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3200);
    };

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Libros IVA · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span>{' '}
                            <b>Libros IVA</b>
                        </p>
                        <h1 className="text-3xl font-bold">Libros IVA</h1>
                        <p className="mt-1 text-base text-slate-500">
                            Control fiscal de compras y ventas
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                action('Documento fiscal listo para registrar.')
                            }
                            className="rounded-lg bg-[#74000a] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            Registrar documento
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                action(
                                    `Libro de ${book.toLowerCase()} preparado para exportar.`,
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2.5 text-xs"
                        >
                            <Download className="size-4" />
                            Exportar
                            <ChevronDown className="size-3" />
                        </button>
                    </div>
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
                        icon={ArrowUp}
                        label="IVA débito (ventas)"
                        value="Q 124,832.50"
                        detail="↑ 12.5%"
                        tone="bg-rose-50 text-rose-600"
                    />
                    <Metric
                        icon={ArrowDown}
                        label="IVA crédito (compras)"
                        value="Q 86,301.25"
                        detail="↑ 8.3%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={FileText}
                        label="Documentos conciliados"
                        value="342"
                        detail="94.2% del total"
                        tone="bg-blue-50 text-blue-600"
                        progress="94.2%"
                    />
                    <Metric
                        icon={CircleAlert}
                        label="Diferencias detectadas"
                        value="21"
                        detail="5.8% del total"
                        tone="bg-red-50 text-red-600"
                        progress="12%"
                    />
                </section>
                <section className={`${panel} overflow-hidden`}>
                    <nav className="flex border-b">
                        <button
                            type="button"
                            onClick={() => setBook('Ventas')}
                            className={`flex items-center gap-2 border-b-2 px-8 py-4 text-sm font-semibold ${book === 'Ventas' ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                        >
                            <Tag className="size-5" />
                            Libro de Ventas
                        </button>
                        <button
                            type="button"
                            onClick={() => setBook('Compras')}
                            className={`flex items-center gap-2 border-b-2 px-8 py-4 text-sm font-semibold ${book === 'Compras' ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                        >
                            <ShoppingCart className="size-5" />
                            Libro de Compras
                        </button>
                    </nav>
                    <div className="grid gap-3 border-b p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1.3fr_1.3fr_auto]">
                        <button
                            type="button"
                            className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                        >
                            Enero 2025
                            <ChevronDown className="size-4" />
                        </button>
                        <button
                            type="button"
                            className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                        >
                            Todas las series
                            <ChevronDown className="size-4" />
                        </button>
                        <label className="relative">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Documento o cliente..."
                                className="w-full rounded-lg border py-2.5 pr-3 pl-9 text-xs"
                            />
                        </label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                        >
                            <option>Todos</option>
                            <option>Conciliado</option>
                            <option>Pendiente</option>
                            <option>Diferencia</option>
                        </select>
                        <button
                            type="button"
                            onClick={() => {
                                setQuery('');
                                setStatus('Todos');
                            }}
                            className="flex items-center justify-center gap-2 rounded-lg border px-4 py-2 text-xs"
                        >
                            <RefreshCcw className="size-4" />
                            Limpiar filtros
                        </button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[940px] text-left text-xs">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th>Documento</th>
                                    <th>Cliente / Proveedor</th>
                                    <th className="text-right">
                                        Base gravable
                                    </th>
                                    <th className="text-right">IVA (12%)</th>
                                    <th className="text-right">Exento</th>
                                    <th className="text-right">Total</th>
                                    <th>Estado</th>
                                    <th className="pr-4 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filtered.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="hover:bg-rose-50/30"
                                    >
                                        <td className="px-4 py-3">
                                            {row.date}
                                        </td>
                                        <td className="font-semibold">
                                            {row.document}
                                        </td>
                                        <td>{row.party}</td>
                                        <td className="text-right">
                                            {money(row.base)}
                                        </td>
                                        <td className="text-right">
                                            {money(row.vat)}
                                        </td>
                                        <td className="text-right">
                                            {money(row.exempt)}
                                        </td>
                                        <td className="text-right font-semibold">
                                            {money(
                                                row.base + row.vat + row.exempt,
                                            )}
                                        </td>
                                        <td>
                                            <span
                                                className={`inline-flex items-center gap-1 rounded-full px-3 py-1 font-semibold ${statusStyle[row.status]}`}
                                            >
                                                <span className="size-1.5 rounded-full bg-current" />
                                                {row.status}
                                            </span>
                                        </td>
                                        <td className="pr-4">
                                            <div className="flex justify-center gap-3">
                                                <Eye className="size-4" />
                                                <FileText className="size-4" />
                                                <ChevronDown className="size-4" />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {!filtered.length && (
                            <div className="p-10 text-center text-sm text-slate-400">
                                No hay documentos para estos filtros.
                            </div>
                        )}
                    </div>
                    <footer className="flex flex-wrap items-center justify-end gap-4 border-t p-3 text-xs text-slate-500">
                        <span>
                            Mostrando 1 - {filtered.length} de 342 documentos
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
                                onClick={() => setPage(page + 1)}
                                className="grid size-8 place-items-center rounded border"
                            >
                                <ChevronRight className="size-4" />
                            </button>
                        </div>
                    </footer>
                </section>
                <section className="grid gap-4 xl:grid-cols-3">
                    <article className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 font-bold">
                            <BookOpen className="size-5" />
                            Totales del período ({book})
                        </h2>
                        <dl className="space-y-3 text-xs">
                            <div className="flex justify-between">
                                <dt>Base gravable</dt>
                                <dd>{money(totals.base)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>
                                    IVA{' '}
                                    {book === 'Ventas' ? 'débito' : 'crédito'}{' '}
                                    (12%)
                                </dt>
                                <dd>{money(totals.vat)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Exento</dt>
                                <dd>{money(totals.exempt)}</dd>
                            </div>
                            <div className="flex justify-between border-t bg-slate-50 p-3 text-sm font-bold">
                                <dt>Total {book.toLowerCase()}</dt>
                                <dd>
                                    {money(
                                        totals.base +
                                            totals.vat +
                                            totals.exempt,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </article>
                    <article className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 font-bold">
                            <Scale className="size-5" />
                            Conciliación fiscal
                        </h2>
                        <dl className="space-y-3 text-xs">
                            <div className="flex justify-between">
                                <dt>IVA débito (ventas)</dt>
                                <dd>Q 124,832.50</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>IVA crédito (compras)</dt>
                                <dd>Q 86,301.25</dd>
                            </div>
                            <div className="flex justify-between bg-slate-100 p-3 text-sm font-bold">
                                <dt>IVA por pagar</dt>
                                <dd>Q 38,531.25</dd>
                            </div>
                        </dl>
                        <p className="mt-4 flex items-center gap-2 text-[10px] text-emerald-700">
                            <CheckCircle2 className="size-4" />
                            Cifras conciliadas con formularios SAT
                        </p>
                    </article>
                    <article className={`${panel} p-5`}>
                        <header className="flex justify-between">
                            <h2 className="flex items-center gap-2 font-bold">
                                <Bell className="size-5" />
                                Alertas y notificaciones
                            </h2>
                            <button
                                type="button"
                                className="text-xs text-[#74000a]"
                            >
                                Ver todas
                            </button>
                        </header>
                        <div className="mt-4 space-y-4">
                            {[
                                [
                                    '21 documentos con diferencias',
                                    'Revisar inconsistencia en montos o datos fiscales',
                                    'bg-red-600',
                                ],
                                [
                                    '8 documentos pendientes de conciliación',
                                    'Completar revisión y validación',
                                    'bg-amber-500',
                                ],
                                [
                                    'Nuevo período disponible',
                                    'Ya puedes trabajar en Febrero 2025',
                                    'bg-blue-600',
                                ],
                            ].map((alert) => (
                                <div key={alert[0]} className="flex gap-3">
                                    <span
                                        className={`mt-1 size-2.5 rounded-full ${alert[2]}`}
                                    />
                                    <span className="flex-1">
                                        <strong className="block text-xs">
                                            {alert[0]}
                                        </strong>
                                        <small className="text-[10px] text-slate-500">
                                            {alert[1]}
                                        </small>
                                    </span>
                                </div>
                            ))}
                        </div>
                    </article>
                </section>
            </div>
        </main>
    );
}
