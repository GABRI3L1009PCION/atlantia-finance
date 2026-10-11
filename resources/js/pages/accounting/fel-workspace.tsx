import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Clock3,
    Copy,
    Download,
    Eye,
    FileCode2,
    FileDown,
    FileText,
    Mail,
    MoreVertical,
    Plus,
    RefreshCw,
    Search,
    X,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type DteStatus = 'Certificado' | 'Pendiente' | 'Con error' | 'Anulado';
type Dte = {
    id: number;
    status: DteStatus;
    type: string;
    series: string;
    number: string;
    date: string;
    client: string;
    nit: string;
    total: string;
    certification: string;
    uuid: string;
    user: string;
};

const dtes: Dte[] = [
    {
        id: 1,
        status: 'Certificado',
        type: 'Factura',
        series: 'FEL',
        number: '000128',
        date: '15/04/2025',
        client: 'Constructora del Norte, S.A.',
        nit: '1234567',
        total: 'Q 12,500.00',
        certification: '15/04/2025 10:24',
        uuid: '4867e3b9-2f1c-4c9a-8e13-b2d5f7a9c001',
        user: 'J. Pérez',
    },
    {
        id: 2,
        status: 'Pendiente',
        type: 'Factura',
        series: 'FEL',
        number: '000127',
        date: '15/04/2025',
        client: 'Distribuidora Central, S.A.',
        nit: '7654321',
        total: 'Q 8,750.00',
        certification: 'En proceso',
        uuid: '—',
        user: 'M. López',
    },
    {
        id: 3,
        status: 'Con error',
        type: 'Nota de crédito',
        series: 'FEL',
        number: '000126',
        date: '14/04/2025',
        client: 'Comercializadora Fenix, S.A.',
        nit: '9876543',
        total: '- Q 2,500.00',
        certification: 'Error en certificación',
        uuid: '—',
        user: 'A. García',
    },
    {
        id: 4,
        status: 'Certificado',
        type: 'Factura',
        series: 'FEL',
        number: '000125',
        date: '14/04/2025',
        client: 'Servicios Profesionales, S.A.',
        nit: '1122334',
        total: 'Q 5,000.00',
        certification: '14/04/2025 16:18',
        uuid: 'a9f2c0d4-8b7c-4e9f-b2a1',
        user: 'J. Pérez',
    },
    {
        id: 5,
        status: 'Certificado',
        type: 'Factura',
        series: 'FEL',
        number: '000124',
        date: '13/04/2025',
        client: 'Inversiones La Paz, S.A.',
        nit: '5566778',
        total: 'Q 3,200.00',
        certification: '13/04/2025 11:02',
        uuid: 'c3d9e6f2-14ad-4f0a-b19c',
        user: 'M. López',
    },
    {
        id: 6,
        status: 'Anulado',
        type: 'Factura',
        series: 'FEL',
        number: '000123',
        date: '12/04/2025',
        client: 'Tecnología Global, S.A.',
        nit: '9988776',
        total: 'Q 1,850.00',
        certification: '12/04/2025 09:15',
        uuid: 'd7a1b3c9-5e2a-468f-9130',
        user: 'A. García',
    },
    {
        id: 7,
        status: 'Certificado',
        type: 'Nota de débito',
        series: 'FEL',
        number: '000122',
        date: '11/04/2025',
        client: 'Transportes Unidos, S.A.',
        nit: '7788990',
        total: 'Q 950.00',
        certification: '11/04/2025 14:33',
        uuid: 'e4b8d1a0-7c9d-4dc5-850f',
        user: 'J. Pérez',
    },
    {
        id: 8,
        status: 'Pendiente',
        type: 'Factura',
        series: 'FEL',
        number: '000121',
        date: '10/04/2025',
        client: 'Industrias del Sur, S.A.',
        nit: '4455667',
        total: 'Q 7,600.00',
        certification: 'En proceso',
        uuid: '—',
        user: 'M. López',
    },
];

const statusClass: Record<DteStatus, string> = {
    Certificado: 'bg-emerald-100 text-emerald-700',
    Pendiente: 'bg-amber-100 text-amber-700',
    'Con error': 'bg-red-100 text-red-700',
    Anulado: 'bg-slate-200 text-slate-600',
};

const statusDot: Record<DteStatus, string> = {
    Certificado: 'bg-emerald-600',
    Pendiente: 'bg-amber-500',
    'Con error': 'bg-red-600',
    Anulado: 'bg-slate-500',
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
        <article className="flex min-h-24 items-center gap-4 rounded-lg border border-[#eadada] bg-white p-4 shadow-[0_8px_22px_rgba(28,38,54,.04)]">
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon size={23} />
            </span>
            <div className="min-w-0">
                <p className="text-[11px] font-medium text-[#536889]">
                    {label}
                </p>
                <div className="mt-1 flex items-end gap-2">
                    <strong className="text-2xl leading-none text-[#08162c]">
                        {value}
                    </strong>
                    <span className="pb-0.5 text-[10px] font-bold text-emerald-600">
                        {detail}
                    </span>
                </div>
                <p className="mt-1 text-[10px] text-[#6d7b92]">
                    {label === 'Documentos emitidos'
                        ? 'Este mes: 28'
                        : label === 'Total facturado'
                          ? 'Este mes: Q 28,750.00'
                          : label === 'Pendientes'
                            ? 'En proceso de certificación'
                            : label === 'Certificados'
                              ? '84.6% del total'
                              : label === 'Con error'
                                ? 'Requieren atención'
                                : '2.6% del total'}
                </p>
            </div>
        </article>
    );
}

export default function FelWorkspace({
    company,
}: {
    company: { name: string; public_id: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [status, setStatus] = useState('Todos');
    const [type, setType] = useState('Todos');
    const [query, setQuery] = useState('');
    const selected = dtes.find((dte) => dte.id === selectedId) ?? dtes[0];
    const filtered = useMemo(
        () =>
            dtes.filter(
                (dte) =>
                    (status === 'Todos' || dte.status === status) &&
                    (type === 'Todos' || dte.type === type) &&
                    `${dte.client} ${dte.nit} ${dte.number} ${dte.uuid}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [query, status, type],
    );

    return (
        <main className="min-h-full bg-[#f6f8fb] p-4 text-[#102039] sm:p-6">
            <Head title={`Facturación electrónica FEL · ${company.name}`} />
            <div className="mx-auto max-w-[1880px] space-y-3">
                <section className="relative overflow-hidden rounded-xl bg-gradient-to-r from-[#7a0007] via-[#980b12] to-[#640006] px-6 py-5 text-white shadow-[0_10px_28px_rgba(70,0,0,.20)]">
                    <div className="pointer-events-none absolute inset-0 opacity-20">
                        <div className="absolute top-[-90px] right-20 size-72 rounded-full border border-white" />
                        <div className="absolute top-[-40px] right-48 size-48 rounded-full border border-white" />
                    </div>
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[.34em] text-white/80">
                                ATLANTIA SYSTEMS / Facturación electrónica
                            </p>
                            <h1 className="mt-2 text-4xl leading-tight font-extrabold">
                                Facturación electrónica FEL
                            </h1>
                            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                <span>
                                    Empresa: <strong>Atlantia Systems</strong>
                                </span>
                                <span className="opacity-60">|</span>
                                <span>
                                    NIT: <strong>97655988</strong>
                                </span>
                                <span className="opacity-60">|</span>
                                <span>
                                    Ambiente: <strong>Pruebas</strong>
                                </span>
                                <span className="opacity-60">|</span>
                                <span>
                                    Certificador: <strong>FEL simulado</strong>
                                </span>
                                <span className="opacity-60">|</span>
                                <span>
                                    Estado del emisor: <strong>Activo</strong>{' '}
                                    <span className="ml-1 inline-block size-2 rounded-full bg-emerald-400" />
                                </span>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button className="rounded-lg bg-white px-5 py-3 text-sm font-bold text-[#7a0007] shadow-sm">
                                Configurar emisor
                            </button>
                            <a
                                href={`/accounting/companies/${company.public_id}/fel/create`}
                                className="inline-flex items-center gap-2 rounded-lg bg-[#c58b1b] px-5 py-3 text-sm font-bold text-white shadow-sm"
                            >
                                <Plus size={17} /> Nuevo DTE
                            </a>
                            <button className="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-3 text-sm font-bold text-[#102039] shadow-sm">
                                <RefreshCw size={16} /> Sincronizar
                            </button>
                        </div>
                    </div>
                </section>

                <section className="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-amber-400 text-white">
                        <AlertTriangle size={18} />
                    </span>
                    <div>
                        <strong>AMBIENTE DE PRUEBA · FEL SIMULADO</strong>
                        <p className="mt-1 text-xs">
                            Los documentos generados en este ambiente no
                            constituyen certificación FEL ante la SAT. Úsalos
                            solo para pruebas y validaciones.
                        </p>
                    </div>
                </section>

                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <Metric
                        icon={FileText}
                        label="Documentos emitidos"
                        value="156"
                        detail="↑ 12%"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={CircleDollarSign}
                        label="Total facturado"
                        value="Q 125,430.50"
                        detail="↑ 18%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Clock3}
                        label="Pendientes"
                        value="12"
                        detail="↓ 25%"
                        tone="bg-amber-50 text-amber-600"
                    />
                    <Metric
                        icon={CheckCircle2}
                        label="Certificados"
                        value="132"
                        detail="↑ 15%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={AlertTriangle}
                        label="Con error"
                        value="8"
                        detail="↓ 20%"
                        tone="bg-red-50 text-red-600"
                    />
                    <Metric
                        icon={X}
                        label="Anulados"
                        value="4"
                        detail="− 0%"
                        tone="bg-slate-100 text-slate-600"
                    />
                </section>

                <nav className="flex gap-8 border-b border-slate-200 px-4 text-sm">
                    {[
                        'Resumen',
                        'Documentos',
                        'Emitir',
                        'Anulaciones',
                        'Incidencias',
                        'Configuración',
                    ].map((item) => (
                        <button
                            key={item}
                            className={`border-b-2 px-1 py-3 font-medium ${item === 'Documentos' ? 'border-[#7a0007] text-[#7a0007]' : 'border-transparent text-[#4e6384]'}`}
                        >
                            {item}
                        </button>
                    ))}
                </nav>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_530px]">
                    <div className="space-y-3">
                        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(28,38,54,.04)]">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-bold">
                                <Search size={17} className="text-[#7a0007]" />{' '}
                                Filtros de búsqueda
                            </h2>
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Tipo de DTE
                                    <select
                                        value={type}
                                        onChange={(event) =>
                                            setType(event.target.value)
                                        }
                                        className="mt-1 h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option>Todos</option>
                                        <option>Factura</option>
                                        <option>Nota de crédito</option>
                                        <option>Nota de débito</option>
                                    </select>
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Serie
                                    <select className="mt-1 h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs">
                                        <option>Todas</option>
                                        <option>FEL</option>
                                    </select>
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Número interno
                                    <input
                                        className="mt-1 h-9 w-full rounded-md border border-slate-200 px-3 text-xs"
                                        placeholder="Ej. 1234"
                                    />
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Estado
                                    <select
                                        value={status}
                                        onChange={(event) =>
                                            setStatus(event.target.value)
                                        }
                                        className="mt-1 h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option>Todos</option>
                                        <option>Certificado</option>
                                        <option>Pendiente</option>
                                        <option>Con error</option>
                                        <option>Anulado</option>
                                    </select>
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Cliente
                                    <div className="relative mt-1">
                                        <input
                                            value={query}
                                            onChange={(event) =>
                                                setQuery(event.target.value)
                                            }
                                            className="h-9 w-full rounded-md border border-slate-200 px-3 pr-8 text-xs"
                                            placeholder="Buscar cliente..."
                                        />
                                        <Search className="absolute top-1/2 right-2 size-4 -translate-y-1/2 text-[#6d7b92]" />
                                    </div>
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    NIT receptor
                                    <input
                                        className="mt-1 h-9 w-full rounded-md border border-slate-200 px-3 text-xs"
                                        placeholder="Ej. 1234567"
                                    />
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Desde
                                    <div className="relative mt-1">
                                        <input
                                            className="h-9 w-full rounded-md border border-slate-200 px-3 text-xs"
                                            placeholder="dd / mm / aaaa"
                                        />
                                        <CalendarDays className="absolute top-1/2 right-2 size-4 -translate-y-1/2 text-[#536889]" />
                                    </div>
                                </label>
                                <label className="text-[11px] font-medium text-[#536889]">
                                    Hasta
                                    <div className="relative mt-1">
                                        <input
                                            className="h-9 w-full rounded-md border border-slate-200 px-3 text-xs"
                                            placeholder="dd / mm / aaaa"
                                        />
                                        <CalendarDays className="absolute top-1/2 right-2 size-4 -translate-y-1/2 text-[#536889]" />
                                    </div>
                                </label>
                            </div>
                            <div className="mt-3 flex gap-2">
                                <button className="inline-flex min-w-44 items-center justify-center gap-2 rounded-lg bg-[#820009] px-4 py-2 text-sm font-bold text-white">
                                    <Search size={16} /> Filtrar
                                </button>
                                <button className="inline-flex min-w-40 items-center justify-center gap-2 rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold text-[#435878]">
                                    <X size={15} /> Limpiar
                                </button>
                            </div>
                        </section>

                        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_8px_22px_rgba(28,38,54,.04)]">
                            <header className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <h2 className="flex items-center gap-2 text-sm font-bold">
                                    <FileText
                                        size={17}
                                        className="text-[#7a0007]"
                                    />{' '}
                                    Documentos electrónicos
                                </h2>
                                <div className="flex items-center gap-2 text-xs text-[#536889]">
                                    <span>Mostrando 1–8 de 156</span>
                                    <button className="grid size-8 place-items-center rounded-md border">
                                        <ChevronLeft size={15} />
                                    </button>
                                    <button className="grid size-8 place-items-center rounded-md bg-[#7a0007] text-white">
                                        1
                                    </button>
                                    <button className="grid size-8 place-items-center rounded-md border">
                                        <ChevronRight size={15} />
                                    </button>
                                    <button className="inline-flex h-8 items-center gap-1 rounded-md border px-3">
                                        10 por página <ChevronDown size={13} />
                                    </button>
                                </div>
                            </header>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[980px] text-left text-[11px]">
                                    <thead className="bg-[#f3f6fa] text-[#314261]">
                                        <tr>
                                            <th className="px-3 py-2">
                                                Estado
                                            </th>
                                            <th>Tipo</th>
                                            <th>Serie</th>
                                            <th>Número</th>
                                            <th>Fecha</th>
                                            <th>Cliente</th>
                                            <th>NIT</th>
                                            <th>Total</th>
                                            <th>Certificación</th>
                                            <th>UUID</th>
                                            <th>Usuario</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {filtered.map((dte) => (
                                            <tr
                                                key={dte.id}
                                                onClick={() =>
                                                    setSelectedId(dte.id)
                                                }
                                                className={`cursor-pointer hover:bg-[#fff4f4] ${selected.id === dte.id ? 'bg-[#fff8f8]' : ''}`}
                                            >
                                                <td className="px-3 py-2">
                                                    <span
                                                        className={`inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold ${statusClass[dte.status]}`}
                                                    >
                                                        <span
                                                            className={`size-2 rounded-full ${statusDot[dte.status]}`}
                                                        />
                                                        {dte.status}
                                                    </span>
                                                </td>
                                                <td>{dte.type}</td>
                                                <td>{dte.series}</td>
                                                <td>{dte.number}</td>
                                                <td>{dte.date}</td>
                                                <td>{dte.client}</td>
                                                <td>{dte.nit}</td>
                                                <td>{dte.total}</td>
                                                <td
                                                    className={
                                                        dte.status ===
                                                        'Con error'
                                                            ? 'text-red-600'
                                                            : 'text-[#536889]'
                                                    }
                                                >
                                                    {dte.certification}
                                                </td>
                                                <td>
                                                    {dte.uuid === '—'
                                                        ? '—'
                                                        : `${dte.uuid.slice(0, 9)}...`}
                                                </td>
                                                <td>{dte.user}</td>
                                                <td>
                                                    <div className="flex items-center gap-1 text-blue-700">
                                                        <Eye size={13} /> Ver{' '}
                                                        <span>|</span>{' '}
                                                        <FileDown size={13} />{' '}
                                                        PDF <span>|</span>{' '}
                                                        <FileCode2 size={13} />{' '}
                                                        XML
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    <aside className="rounded-xl border border-slate-200 bg-white shadow-[0_8px_22px_rgba(28,38,54,.04)]">
                        <header className="flex items-start justify-between border-b border-slate-100 px-4 py-3">
                            <div>
                                <h2 className="flex items-center gap-2 text-lg font-bold">
                                    <FileText
                                        size={18}
                                        className="text-[#7a0007]"
                                    />
                                    DTE FEL-{selected.number}
                                    <span
                                        className={`rounded-full px-2 py-1 text-[10px] ${statusClass[selected.status]}`}
                                    >
                                        {selected.status}
                                    </span>
                                </h2>
                            </div>
                            <MoreVertical size={18} />
                        </header>
                        <div className="space-y-4 p-4 text-xs">
                            <section>
                                <h3 className="mb-2 font-bold">
                                    Información general
                                </h3>
                                <dl className="grid grid-cols-[150px_1fr] gap-y-1 text-[#536889]">
                                    <dt>UUID</dt>
                                    <dd className="flex items-center gap-2 text-[#314261]">
                                        {selected.uuid}
                                        <Copy size={13} />
                                    </dd>
                                    <dt>Fecha de emisión</dt>
                                    <dd className="text-[#314261]">
                                        15/04/2025 10:22:15
                                    </dd>
                                    <dt>Fecha de certificación</dt>
                                    <dd className="text-[#314261]">
                                        15/04/2025 10:24:36
                                    </dd>
                                    <dt>Tipo de DTE</dt>
                                    <dd className="text-[#314261]">
                                        {selected.type} (FACT)
                                    </dd>
                                    <dt>Serie y número</dt>
                                    <dd className="text-[#314261]">
                                        FEL - {selected.number}
                                    </dd>
                                    <dt>Ambiente</dt>
                                    <dd className="text-[#314261]">
                                        Pruebas (FEL simulado)
                                    </dd>
                                </dl>
                            </section>
                            <section className="border-t border-slate-100 pt-3">
                                <h3 className="mb-2 font-bold">Emisor</h3>
                                <dl className="grid grid-cols-[150px_1fr] gap-y-1 text-[#536889]">
                                    <dt>Empresa</dt>
                                    <dd className="text-[#314261]">
                                        Atlantia Systems
                                    </dd>
                                    <dt>NIT</dt>
                                    <dd className="text-[#314261]">97655988</dd>
                                </dl>
                            </section>
                            <section className="border-t border-slate-100 pt-3">
                                <h3 className="mb-2 font-bold">Receptor</h3>
                                <dl className="grid grid-cols-[150px_1fr] gap-y-1 text-[#536889]">
                                    <dt>Cliente</dt>
                                    <dd className="text-[#314261]">
                                        {selected.client}
                                    </dd>
                                    <dt>NIT</dt>
                                    <dd className="text-[#314261]">
                                        {selected.nit}
                                    </dd>
                                </dl>
                            </section>
                            <section className="border-t border-slate-100 pt-3">
                                <h3 className="mb-2 font-bold">Totales</h3>
                                <dl className="space-y-1 text-[#536889]">
                                    <div className="flex justify-between">
                                        <dt>Subtotal</dt>
                                        <dd className="text-[#314261]">
                                            Q 11,160.71
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>IVA (12%)</dt>
                                        <dd className="text-[#314261]">
                                            Q 1,339.29
                                        </dd>
                                    </div>
                                    <div className="flex justify-between rounded-md bg-[#edf1f5] px-3 py-2 text-base font-extrabold text-[#102039]">
                                        <dt>Total</dt>
                                        <dd>{selected.total}</dd>
                                    </div>
                                </dl>
                            </section>
                            <section className="border-t border-slate-100 pt-3">
                                <h3 className="mb-2 font-bold">
                                    Respuesta del certificador
                                </h3>
                                <dl className="grid grid-cols-[150px_1fr] gap-y-1 text-[#536889]">
                                    <dt>Estado</dt>
                                    <dd className="font-bold text-emerald-600">
                                        Certificado correctamente
                                    </dd>
                                    <dt>Código de respuesta</dt>
                                    <dd className="text-[#314261]">200</dd>
                                    <dt>Descripción</dt>
                                    <dd className="text-[#314261]">
                                        Documento certificado
                                        satisfactoriamente.
                                    </dd>
                                </dl>
                            </section>
                            <section className="grid grid-cols-4 gap-2 border-t border-slate-100 pt-3">
                                <button className="inline-flex items-center justify-center gap-1 rounded-md border px-2 py-2 font-semibold">
                                    <Download size={14} /> Descargar PDF
                                </button>
                                <button className="inline-flex items-center justify-center gap-1 rounded-md border px-2 py-2 font-semibold">
                                    <FileCode2 size={14} /> Descargar XML
                                </button>
                                <button className="inline-flex items-center justify-center gap-1 rounded-md border px-2 py-2 font-semibold">
                                    <Mail size={14} /> Reenviar correo
                                </button>
                                <button className="inline-flex items-center justify-center gap-1 rounded-md border border-red-200 px-2 py-2 font-semibold text-red-700">
                                    <XCircle size={14} /> Anular
                                </button>
                            </section>
                            <section className="border-t border-slate-100 pt-3">
                                <div className="mb-2 flex justify-between">
                                    <h3 className="font-bold">
                                        Historial del documento
                                    </h3>
                                    <button className="text-blue-700">
                                        Ver todo
                                    </button>
                                </div>
                                {[
                                    'Documento certificado por FEL simulado',
                                    'Documento enviado a certificación',
                                    'Documento creado por J. Pérez',
                                ].map((item, index) => (
                                    <div
                                        key={item}
                                        className="flex gap-2 border-l border-emerald-200 pb-2 pl-3 last:pb-0"
                                    >
                                        <span className="mt-1 -ml-[17px] size-2 rounded-full bg-emerald-600" />
                                        <p>
                                            <span className="text-[#536889]">
                                                15/04/2025 10:{24 - index}
                                            </span>{' '}
                                            {item}
                                        </p>
                                    </div>
                                ))}
                            </section>
                        </div>
                    </aside>
                </section>
            </div>
        </main>
    );
}
