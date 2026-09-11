import { Head } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Clock3,
    Copy,
    Download,
    ExternalLink,
    FileCode2,
    FileDown,
    FileText,
    Filter,
    HelpCircle,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Send,
    ShieldCheck,
    UserRound,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type DteStatus = 'Certificado' | 'Enviado' | 'Rechazado' | 'Pendiente';
type Dte = {
    id: number;
    series: string;
    number: string;
    type: string;
    client: string;
    nit: string;
    address: string;
    date: string;
    total: string;
    status: DteStatus;
    uuid: string;
};

const documents: Dte[] = [
    {
        id: 1,
        series: 'A1',
        number: '00001234',
        type: 'Factura (FEL)',
        client: 'Cliente Industrial, S.A.',
        nit: '1234567-8',
        address: '7a. Avenida 12-34, Zona 9, Guatemala',
        date: '15/01/2025',
        total: 'Q 12,450.00',
        status: 'Certificado',
        uuid: 'A1B2C3D4-E5F6-7890-1234-56789ABCDEFO',
    },
    {
        id: 2,
        series: 'A1',
        number: '00001233',
        type: 'Factura (FEL)',
        client: 'Distribuidora La Paz, S.A.',
        nit: '5489123-6',
        address: 'Zona 10, Guatemala',
        date: '15/01/2025',
        total: 'Q 8,900.00',
        status: 'Enviado',
        uuid: 'F3E2D1C0-0987-6543-210F',
    },
    {
        id: 3,
        series: 'CR',
        number: '00000123',
        type: 'Nota de Crédito (FEL)',
        client: 'Comercial Delta, S.A.',
        nit: '7684321-5',
        address: 'Mixco, Guatemala',
        date: '14/01/2025',
        total: '- Q 1,250.00',
        status: 'Certificado',
        uuid: '9A8B7C6D-5432-1098-ABCD',
    },
    {
        id: 4,
        series: 'A1',
        number: '00001232',
        type: 'Factura (FEL)',
        client: 'Servicios Profesionales, S.A.',
        nit: '3345678-1',
        address: 'Zona 4, Guatemala',
        date: '14/01/2025',
        total: 'Q 5,600.00',
        status: 'Certificado',
        uuid: '4F5E6D7C-BA98-7654-3210',
    },
    {
        id: 5,
        series: 'A1',
        number: '00001231',
        type: 'Factura (FEL)',
        client: 'Inversiones Caribe, S.A.',
        nit: '8754123-9',
        address: 'Puerto Barrios, Izabal',
        date: '13/01/2025',
        total: 'Q 22,400.00',
        status: 'Rechazado',
        uuid: '8C7B6A5D-4321-0FED-CBA9',
    },
    {
        id: 6,
        series: 'A1',
        number: '00001230',
        type: 'Factura (FEL)',
        client: 'Tecnología Global, S.A.',
        nit: '2233445-5',
        address: 'Zona 10, Guatemala',
        date: '13/01/2025',
        total: 'Q 3,150.00',
        status: 'Certificado',
        uuid: '5D4C3B2A-1098-7654-3210',
    },
    {
        id: 7,
        series: 'A1',
        number: '00001229',
        type: 'Factura (FEL)',
        client: 'Grupo Empresarial, S.A.',
        nit: '5467891-2',
        address: 'Zona 14, Guatemala',
        date: '12/01/2025',
        total: 'Q 17,800.00',
        status: 'Certificado',
        uuid: '2A3B4C5D-6789-0123-4567',
    },
    {
        id: 8,
        series: 'A1',
        number: '00001228',
        type: 'Factura (FEL)',
        client: 'Soluciones Integrales, S.A.',
        nit: '7524681-4',
        address: 'Villa Nueva, Guatemala',
        date: '12/01/2025',
        total: 'Q 6,750.00',
        status: 'Pendiente',
        uuid: '7E6D5C4B-3210-FEDC-BA98',
    },
    {
        id: 9,
        series: 'A1',
        number: '00001227',
        type: 'Factura (FEL)',
        client: 'Constructora del Sur, S.A.',
        nit: '9912345-7',
        address: 'Escuintla, Guatemala',
        date: '11/01/2025',
        total: 'Q 28,900.00',
        status: 'Certificado',
        uuid: '1F2E3D4C-5B6A-7890-1234',
    },
    {
        id: 10,
        series: 'A1',
        number: '00001226',
        type: 'Factura (FEL)',
        client: 'Agroindustria, S.A.',
        nit: '3124567-8',
        address: 'Mazatenango, Suchitepéquez',
        date: '11/01/2025',
        total: 'Q 9,320.00',
        status: 'Certificado',
        uuid: '6B5A4C3D-2E1F-0987-6543',
    },
];

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const statusStyle: Record<DteStatus, string> = {
    Certificado: 'bg-emerald-100 text-emerald-700',
    Enviado: 'bg-blue-100 text-blue-700',
    Rechazado: 'bg-red-100 text-red-700',
    Pendiente: 'bg-amber-100 text-amber-700',
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
                <p className="mt-1 text-xs font-medium text-slate-500">
                    {detail}
                </p>
            </div>
        </article>
    );
}

function StatusSummary({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone: string;
}) {
    return (
        <div className="flex min-w-28 flex-1 items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <span className={`size-3 rounded-full ring-4 ${tone}`} />
            <span>
                <small className="block text-[10px] text-slate-500">
                    {label}
                </small>
                <strong className="text-sm">{value}</strong>
            </span>
        </div>
    );
}

export default function FelWorkspace({
    company,
}: {
    company: { name: string; public_id: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [status, setStatus] = useState('Todos los estados');
    const [type, setType] = useState('Todos los tipos');
    const [query, setQuery] = useState('');
    const [tab, setTab] = useState('Información');
    const [notice, setNotice] = useState('');
    const [page, setPage] = useState(1);
    const selected =
        documents.find((document) => document.id === selectedId) ??
        documents[0];
    const filtered = useMemo(
        () =>
            documents.filter(
                (document) =>
                    (status === 'Todos los estados' ||
                        document.status === status) &&
                    (type === 'Todos los tipos' || document.type === type) &&
                    `${document.series} ${document.number} ${document.client} ${document.uuid}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [query, status, type],
    );

    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3500);
    };
    const createUrl = `/accounting/companies/${company.public_id}/fel/create`;
    const indexUrl = `/accounting/companies/${company.public_id}/fel`;

    return (
        <main className="min-h-full bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`FEL / SAT · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">FEL / SAT</h1>
                        <p className="mt-1 text-base text-slate-500">
                            Documentos tributarios electrónicos
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <a
                            href={indexUrl}
                            className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold"
                        >
                            <HelpCircle className="size-4" />
                            Ayuda
                        </a>
                        <a
                            href={createUrl}
                            className="flex items-center gap-2 rounded-lg bg-[#74000a] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Plus className="size-4" />
                            Nuevo documento
                        </a>
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

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={FileText}
                        label="Documentos emitidos"
                        value="1,284"
                        detail="▲ +12% vs. período anterior"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={ShieldCheck}
                        label="Certificados"
                        value="1,196"
                        detail="▲ 93.1% del total"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={CircleAlert}
                        label="Rechazados"
                        value="24"
                        detail="▲ 1.9% del total"
                        tone="bg-red-50 text-red-600"
                    />
                    <Metric
                        icon={Clock3}
                        label="Pendientes"
                        value="64"
                        detail="▲ 5.0% del total"
                        tone="bg-amber-50 text-amber-600"
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[1.35fr_.65fr]">
                    <section className={`${panel} p-4`}>
                        <h2 className="mb-3 text-sm font-bold">
                            Resumen por estado
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            <StatusSummary
                                label="Borrador"
                                value="18"
                                tone="bg-slate-500 ring-slate-200"
                            />
                            <StatusSummary
                                label="Enviado"
                                value="42"
                                tone="bg-blue-500 ring-blue-100"
                            />
                            <StatusSummary
                                label="Certificado"
                                value="1,196"
                                tone="bg-emerald-600 ring-emerald-100"
                            />
                            <StatusSummary
                                label="Anulado"
                                value="14"
                                tone="bg-red-500 ring-red-100"
                            />
                            <StatusSummary
                                label="Error"
                                value="24"
                                tone="bg-red-600 ring-red-100"
                            />
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="mb-3 text-sm font-bold">
                            Acciones rápidas
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={createUrl}
                                className="rounded-lg bg-[#74000a] px-4 py-2 text-xs font-semibold text-white"
                            >
                                Emitir DTE
                            </a>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        'Selector XML listo para cargar un documento.',
                                    )
                                }
                                className="rounded-lg border px-4 py-2 text-xs"
                            >
                                Subir XML
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action('Consulta simulada enviada a SAT.')
                                }
                                className="rounded-lg border px-4 py-2 text-xs"
                            >
                                Consultar en SAT
                            </button>
                        </div>
                    </section>
                </div>

                <div className="grid gap-4 2xl:grid-cols-[1fr_430px]">
                    <section className={`${panel} overflow-hidden`}>
                        <header className="flex items-center justify-between border-b p-4">
                            <h2 className="text-lg font-bold">
                                Documentos tributarios
                            </h2>
                            <button
                                type="button"
                                onClick={() =>
                                    action('Exportación FEL preparada.')
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Download className="size-4" />
                                Exportar
                                <ChevronDown className="size-3" />
                            </button>
                        </header>
                        <div className="space-y-3 p-4">
                            <label className="relative block">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar por serie, número, cliente o UUID..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-[#74000a]"
                                />
                            </label>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.55fr_auto]">
                                <select
                                    value={type}
                                    onChange={(event) =>
                                        setType(event.target.value)
                                    }
                                    className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                                >
                                    <option>Todos los tipos</option>
                                    <option>Factura (FEL)</option>
                                    <option>Nota de Crédito (FEL)</option>
                                </select>
                                <select
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(event.target.value)
                                    }
                                    className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                                >
                                    <option>Todos los estados</option>
                                    {Object.keys(statusStyle).map((item) => (
                                        <option key={item}>{item}</option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs text-slate-500"
                                >
                                    <span>Desde</span>
                                    <span>–</span>
                                    <span>Hasta</span>
                                    <Clock3 className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        action(
                                            `${filtered.length} documentos coinciden con los filtros.`,
                                        )
                                    }
                                    className="flex items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-xs"
                                >
                                    <Filter className="size-4" />
                                    Filtros
                                </button>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-left text-xs">
                                <thead className="border-y bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3">Serie</th>
                                        <th>Número</th>
                                        <th>Tipo DTE</th>
                                        <th>Cliente</th>
                                        <th>Fecha</th>
                                        <th>Total</th>
                                        <th>Estado</th>
                                        <th>UUID</th>
                                        <th className="pr-4 text-center">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((document) => (
                                        <tr
                                            key={document.id}
                                            onClick={() =>
                                                setSelectedId(document.id)
                                            }
                                            className={`cursor-pointer hover:bg-rose-50/50 ${selected.id === document.id ? 'bg-rose-50' : ''}`}
                                        >
                                            <td className="px-4 py-3">
                                                {document.series}
                                            </td>
                                            <td>{document.number}</td>
                                            <td>{document.type}</td>
                                            <td>{document.client}</td>
                                            <td>{document.date}</td>
                                            <td className="font-medium">
                                                {document.total}
                                            </td>
                                            <td>
                                                <span
                                                    className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold ${statusStyle[document.status]}`}
                                                >
                                                    <span className="size-1.5 rounded-full bg-current" />
                                                    {document.status}
                                                </span>
                                            </td>
                                            <td>
                                                {document.uuid.slice(0, 9)}...
                                            </td>
                                            <td className="pr-4 text-center">
                                                <MoreVertical className="inline size-5 rounded border p-0.5" />
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
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 1,284
                                documentos
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setPage(Math.max(1, page - 1))
                                    }
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
                                <span className="grid size-8 place-items-center">
                                    …
                                </span>
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    129
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setPage(Math.min(129, page + 1))
                                    }
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        </footer>
                    </section>

                    <aside className={`${panel} overflow-hidden`}>
                        <header className="flex items-start gap-3 border-b p-4">
                            <span className="grid size-11 place-items-center rounded-lg bg-blue-50 text-blue-600">
                                <FileText className="size-6" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="font-bold">
                                        {selected.type}
                                    </h2>
                                    <span
                                        className={`rounded-full px-2 py-1 text-[10px] font-semibold ${statusStyle[selected.status]}`}
                                    >
                                        {selected.status}
                                    </span>
                                </div>
                                <p className="text-xs text-slate-500">
                                    Serie {selected.series} · Número{' '}
                                    {selected.number}
                                </p>
                                <p className="mt-1 flex items-center gap-1 truncate text-[10px] text-slate-500">
                                    UUID: {selected.uuid}
                                    <Copy className="size-3" />
                                </p>
                            </div>
                            <X className="size-4" />
                        </header>
                        <nav className="flex border-b">
                            {[
                                'Información',
                                'XML / Archivos',
                                'Bitácora',
                                'Historial',
                            ].map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => setTab(item)}
                                    className={`flex-1 border-b-2 py-3 text-[10px] font-semibold ${tab === item ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                                >
                                    {item}
                                </button>
                            ))}
                        </nav>
                        {tab === 'Información' ? (
                            <div className="space-y-3 p-3">
                                <section className="rounded-lg border p-3">
                                    <div className="mb-3 flex justify-between">
                                        <h3 className="text-xs font-bold">
                                            Datos generales
                                        </h3>
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 rounded border px-2 py-1 text-[10px]"
                                        >
                                            <Pencil className="size-3" />
                                            Editar
                                        </button>
                                    </div>
                                    <dl className="grid grid-cols-[140px_1fr] gap-y-2 text-[10px]">
                                        <dt className="text-slate-500">
                                            Fecha de emisión
                                        </dt>
                                        <dd>15/01/2025 10:24:35</dd>
                                        <dt className="text-slate-500">
                                            Fecha de certificación
                                        </dt>
                                        <dd>15/01/2025 10:24:37</dd>
                                        <dt className="text-slate-500">
                                            Tipo de DTE
                                        </dt>
                                        <dd>{selected.type}</dd>
                                        <dt className="text-slate-500">
                                            Serie / Número
                                        </dt>
                                        <dd>
                                            {selected.series} ·{' '}
                                            {selected.number}
                                        </dd>
                                        <dt className="text-slate-500">
                                            Moneda
                                        </dt>
                                        <dd>GTQ · Quetzales</dd>
                                        <dt className="text-slate-500">
                                            Total
                                        </dt>
                                        <dd className="font-bold">
                                            {selected.total}
                                        </dd>
                                    </dl>
                                </section>
                                <section className="rounded-lg border p-3">
                                    <div className="mb-3 flex justify-between">
                                        <h3 className="text-xs font-bold">
                                            Datos del receptor
                                        </h3>
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 rounded border px-2 py-1 text-[10px]"
                                        >
                                            <UserRound className="size-3" />
                                            Ver cliente
                                        </button>
                                    </div>
                                    <dl className="grid grid-cols-[140px_1fr] gap-y-2 text-[10px]">
                                        <dt className="text-slate-500">
                                            Nombre / Razón social
                                        </dt>
                                        <dd>{selected.client}</dd>
                                        <dt className="text-slate-500">NIT</dt>
                                        <dd>{selected.nit}</dd>
                                        <dt className="text-slate-500">
                                            Dirección
                                        </dt>
                                        <dd>{selected.address}</dd>
                                    </dl>
                                </section>
                                <section className="rounded-lg border p-3">
                                    <h3 className="mb-2 text-xs font-bold">
                                        Totales e impuestos
                                    </h3>
                                    <table className="w-full text-[10px]">
                                        <thead className="bg-slate-100">
                                            <tr>
                                                <th className="p-2 text-left">
                                                    Descripción
                                                </th>
                                                <th>Base</th>
                                                <th>Impuesto</th>
                                                <th className="text-right">
                                                    Total
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td className="p-2">
                                                    Gravado (IVA 12%)
                                                </td>
                                                <td className="text-right">
                                                    Q 11,116.07
                                                </td>
                                                <td className="text-right">
                                                    Q 1,333.93
                                                </td>
                                                <td className="text-right">
                                                    Q 12,450.00
                                                </td>
                                            </tr>
                                            <tr className="border-t font-bold">
                                                <td className="p-2">Total</td>
                                                <td className="text-right">
                                                    Q 11,116.07
                                                </td>
                                                <td className="text-right">
                                                    Q 1,333.93
                                                </td>
                                                <td className="text-right">
                                                    Q 12,450.00
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </section>
                            </div>
                        ) : (
                            <div className="grid min-h-96 place-items-center p-8 text-center">
                                <div>
                                    <FileCode2 className="mx-auto size-12 text-[#74000a]" />
                                    <h3 className="mt-3 font-bold">{tab}</h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Información vinculada al DTE
                                        seleccionado.
                                    </p>
                                </div>
                            </div>
                        )}
                        <footer className="grid gap-2 border-t p-3 sm:grid-cols-3">
                            <button
                                type="button"
                                onClick={() =>
                                    action('XML listo para descargar.')
                                }
                                className="flex items-center justify-center gap-1 rounded-lg border py-2 text-[10px]"
                            >
                                <FileDown className="size-4" />
                                Descargar XML
                            </button>
                            <a
                                href={indexUrl}
                                className="flex items-center justify-center gap-1 rounded-lg border py-2 text-[10px]"
                            >
                                <ExternalLink className="size-4" />
                                Representación gráfica
                            </a>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        `Correo FEL preparado para ${selected.client}.`,
                                    )
                                }
                                className="flex items-center justify-center gap-1 rounded-lg border py-2 text-[10px]"
                            >
                                <Send className="size-4" />
                                Enviar por correo
                            </button>
                        </footer>
                    </aside>
                </div>
            </div>
        </main>
    );
}
