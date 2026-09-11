import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    ExternalLink,
    Eye,
    FileText,
    Filter,
    History,
    Laptop,
    MoreHorizontal,
    Paperclip,
    Search,
    ShieldCheck,
    Users,
    Wifi,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type Risk = 'Crítico' | 'Alto' | 'Medio' | 'Bajo';
type AuditEvent = {
    id: number;
    date: string;
    user: string;
    company: string;
    module: string;
    action: string;
    record: string;
    risk: Risk;
    ip: string;
    reason: string;
};

const events: AuditEvent[] = [
    {
        id: 1,
        date: '29 Sep 2026 16:42',
        user: 'Ana López',
        company: 'Demo Atlántia S.A.',
        module: 'Compras',
        action: 'Modificación',
        record: 'Compra #OC-2026-0045',
        risk: 'Crítico',
        ip: '192.168.10.25',
        reason: 'Ajuste de condiciones comerciales',
    },
    {
        id: 2,
        date: '29 Sep 2026 15:18',
        user: 'Carlos Mendoza',
        company: 'Demo Atlántia S.A.',
        module: 'Cierre de Período',
        action: 'Reapertura',
        record: 'Período Sep 2026',
        risk: 'Alto',
        ip: '192.168.10.18',
        reason: 'Corrección posterior al cierre',
    },
    {
        id: 3,
        date: '29 Sep 2026 14:03',
        user: 'María Contreras',
        company: 'Demo Atlántia S.A.',
        module: 'Contabilidad',
        action: 'Cambio',
        record: 'Cuenta 1.1.02.001',
        risk: 'Medio',
        ip: '192.168.10.12',
        reason: 'Reclasificación contable',
    },
    {
        id: 4,
        date: '29 Sep 2026 12:27',
        user: 'Luis Ramírez',
        company: 'Comercial del Sur S.A.',
        module: 'Ventas',
        action: 'Anulación',
        record: 'Factura #F-0001287',
        risk: 'Alto',
        ip: '192.168.10.31',
        reason: 'Solicitud del cliente',
    },
    {
        id: 5,
        date: '29 Sep 2026 11:12',
        user: 'Sofía Herrera',
        company: 'Demo Atlántia S.A.',
        module: 'Configuración',
        action: 'Actualización',
        record: 'Parámetros fiscales',
        risk: 'Medio',
        ip: '192.168.10.42',
        reason: 'Actualización de tasas',
    },
    {
        id: 6,
        date: '29 Sep 2026 10:05',
        user: 'Juan Pérez',
        company: 'Demo Atlántia S.A.',
        module: 'Usuarios y Roles',
        action: 'Creación',
        record: 'Usuario jmartinez',
        risk: 'Bajo',
        ip: '192.168.10.36',
        reason: 'Alta de colaborador',
    },
    {
        id: 7,
        date: '28 Sep 2026 18:33',
        user: 'Ana López',
        company: 'Distribuidora Andina S.A.',
        module: 'Inventario',
        action: 'Eliminación',
        record: 'Producto PRD-00432',
        risk: 'Alto',
        ip: '192.168.10.25',
        reason: 'Producto duplicado',
    },
    {
        id: 8,
        date: '28 Sep 2026 17:21',
        user: 'Carlos Mendoza',
        company: 'Demo Atlántia S.A.',
        module: 'Contabilidad',
        action: 'Modificación',
        record: 'Asiento #AJ-2026-0921',
        risk: 'Medio',
        ip: '192.168.10.18',
        reason: 'Corrección de partida',
    },
    {
        id: 9,
        date: '28 Sep 2026 15:09',
        user: 'María Contreras',
        company: 'Demo Atlántia S.A.',
        module: 'Compras',
        action: 'Aprobación',
        record: 'Compra #OC-2026-0044',
        risk: 'Bajo',
        ip: '192.168.10.12',
        reason: 'Flujo de aprobación',
    },
    {
        id: 10,
        date: '28 Sep 2026 12:46',
        user: 'Sofía Herrera',
        company: 'Demo Atlántia S.A.',
        module: 'Reportes',
        action: 'Generación',
        record: 'Reporte Estados Financieros',
        risk: 'Bajo',
        ip: '192.168.10.42',
        reason: 'Reporte mensual',
    },
    {
        id: 11,
        date: '28 Sep 2026 10:15',
        user: 'Luis Ramírez',
        company: 'Comercial del Sur S.A.',
        module: 'Contabilidad',
        action: 'Cambio',
        record: 'Cuenta 2.1.01.003',
        risk: 'Medio',
        ip: '192.168.10.31',
        reason: 'Actualización de catálogo',
    },
    {
        id: 12,
        date: '27 Sep 2026 16:02',
        user: 'Juan Pérez',
        company: 'Demo Atlántia S.A.',
        module: 'Cierre de Período',
        action: 'Bloqueo',
        record: 'Período Sep 2026',
        risk: 'Bajo',
        ip: '192.168.10.36',
        reason: 'Cierre mensual',
    },
];

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const riskStyle: Record<Risk, string> = {
    Crítico: 'bg-red-600 text-white',
    Alto: 'bg-red-100 text-red-700',
    Medio: 'bg-amber-100 text-amber-700',
    Bajo: 'bg-emerald-100 text-emerald-700',
};

function Metric({
    icon: Icon,
    label,
    value,
    change,
    detail,
    tone,
    negative = false,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    change: string;
    detail: string;
    tone: string;
    negative?: boolean;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-center gap-4 p-5`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div>
                <p className="text-xs font-semibold text-slate-600">{label}</p>
                <div className="mt-1 flex items-end gap-3">
                    <strong className="text-2xl">{value}</strong>
                    <span
                        className={`pb-1 text-xs font-semibold ${negative ? 'text-red-600' : 'text-emerald-600'}`}
                    >
                        {change}
                    </span>
                </div>
                <p className="text-[11px] text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

export default function AuditWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selected, setSelected] = useState(events[0]);
    const [module, setModule] = useState('Todos');
    const [risk, setRisk] = useState('Todos');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const [advanced, setAdvanced] = useState(false);

    const filtered = useMemo(
        () =>
            events.filter((event) => {
                const matchesModule =
                    module === 'Todos' || event.module === module;
                const matchesRisk = risk === 'Todos' || event.risk === risk;
                const haystack =
                    `${event.user} ${event.company} ${event.module} ${event.action} ${event.record}`.toLowerCase();

                return (
                    matchesModule &&
                    matchesRisk &&
                    haystack.includes(query.toLowerCase())
                );
            }),
        [module, query, risk],
    );

    const showNotice = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3400);
    };

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Auditoría · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Auditoría
                        </p>
                        <h1 className="text-3xl font-bold text-[#74000a]">
                            Auditoría
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Bitácora de cambios, eventos y trazabilidad del
                            sistema
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2.5 text-xs font-semibold"
                        >
                            <Building2 className="size-4 text-[#74000a]" />
                            {company.name}
                            <ChevronDown className="size-4" />
                        </button>
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2.5 text-xs font-semibold"
                        >
                            <CalendarDays className="size-4 text-[#74000a]" />
                            Septiembre 2026
                            <ChevronDown className="size-4" />
                        </button>
                    </div>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
                    <Metric
                        icon={FileText}
                        label="Eventos hoy"
                        value="142"
                        change="↑ 12%"
                        detail="vs. 128 del día anterior"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={AlertTriangle}
                        label="Cambios críticos"
                        value="8"
                        change="↑ 33%"
                        detail="vs. 6 del día anterior"
                        tone="bg-red-50 text-red-700"
                        negative
                    />
                    <Metric
                        icon={Users}
                        label="Usuarios activos"
                        value="12"
                        change="↑ 20%"
                        detail="vs. 10 del día anterior"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={History}
                        label="Reaperturas"
                        value="2"
                        change="↓ 50%"
                        detail="vs. 4 del día anterior"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                </section>
                <section
                    className={`${panel} grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-[1fr_.8fr_.9fr_1fr_1fr_1fr_.8fr_auto_auto]`}
                >
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todas las empresas
                        <ChevronDown className="size-4" />
                    </button>
                    <select
                        value={module}
                        onChange={(event) => setModule(event.target.value)}
                        className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                    >
                        <option>Todos</option>
                        <option>Compras</option>
                        <option>Contabilidad</option>
                        <option>Cierre de Período</option>
                        <option>Ventas</option>
                        <option>Inventario</option>
                    </select>
                    <label className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Usuario o registro..."
                            className="w-full rounded-lg border py-2.5 pr-3 pl-9 text-xs"
                        />
                    </label>
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todas las acciones
                        <ChevronDown className="size-4" />
                    </button>
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        01/09/2026
                        <CalendarDays className="size-4" />
                    </button>
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        30/09/2026
                        <CalendarDays className="size-4" />
                    </button>
                    <select
                        value={risk}
                        onChange={(event) => setRisk(event.target.value)}
                        className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                    >
                        <option>Todos</option>
                        <option>Crítico</option>
                        <option>Alto</option>
                        <option>Medio</option>
                        <option>Bajo</option>
                    </select>
                    <button
                        type="button"
                        onClick={() => {
                            setModule('Todos');
                            setRisk('Todos');
                            setQuery('');
                        }}
                        className="rounded-lg border px-4 py-2.5 text-xs text-slate-600"
                    >
                        Limpiar filtros
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            showNotice(
                                `${filtered.length} eventos encontrados.`,
                            )
                        }
                        className="flex items-center justify-center gap-2 rounded-lg bg-[#74000a] px-5 py-2.5 text-xs font-semibold text-white"
                    >
                        <Search className="size-4" />
                        Buscar
                    </button>
                </section>
                {advanced && (
                    <section
                        className={`${panel} grid gap-3 p-4 sm:grid-cols-3`}
                    >
                        <button
                            type="button"
                            className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                        >
                            Dirección IP
                            <ChevronDown className="size-4" />
                        </button>
                        <button
                            type="button"
                            className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                        >
                            Tipo de dispositivo
                            <ChevronDown className="size-4" />
                        </button>
                        <button
                            type="button"
                            className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                        >
                            Registro relacionado
                            <ChevronDown className="size-4" />
                        </button>
                    </section>
                )}
                <section className="grid items-start gap-4 2xl:grid-cols-[minmax(0,1fr)_390px]">
                    <article className={`${panel} overflow-hidden`}>
                        <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="flex items-center gap-2 font-bold">
                                    <FileText className="size-5 text-[#74000a]" />
                                    Bitácora de auditoría
                                </h2>
                                <p className="text-xs text-slate-500">
                                    Registro de todos los cambios, eventos y
                                    acciones realizadas en el sistema.
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Bitácora preparada para exportar.',
                                        )
                                    }
                                    className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                                >
                                    <Download className="size-4" />
                                    Exportar
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setAdvanced(!advanced)}
                                    className={`flex items-center gap-2 rounded-lg border px-4 py-2 text-xs ${advanced ? 'border-[#74000a] bg-rose-50 text-[#74000a]' : ''}`}
                                >
                                    <Filter className="size-4" />
                                    Búsqueda avanzada
                                </button>
                            </div>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-left text-xs">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3">
                                            Fecha y hora ↓
                                        </th>
                                        <th>Usuario</th>
                                        <th>Empresa</th>
                                        <th>Módulo</th>
                                        <th>Acción</th>
                                        <th>Registro</th>
                                        <th>Riesgo</th>
                                        <th className="pr-4 text-center">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {filtered.map((event) => (
                                        <tr
                                            key={event.id}
                                            onClick={() => setSelected(event)}
                                            className={`cursor-pointer ${selected.id === event.id ? 'bg-rose-50' : 'hover:bg-slate-50'}`}
                                        >
                                            <td
                                                className={`px-4 py-2.5 ${selected.id === event.id ? 'border-l-4 border-[#74000a]' : ''}`}
                                            >
                                                {event.date}
                                            </td>
                                            <td>{event.user}</td>
                                            <td>{event.company}</td>
                                            <td>{event.module}</td>
                                            <td>{event.action}</td>
                                            <td className="font-medium">
                                                {event.record}
                                            </td>
                                            <td>
                                                <span
                                                    className={`inline-flex min-w-20 items-center justify-center gap-1 rounded-full px-3 py-1 font-semibold ${riskStyle[event.risk]}`}
                                                >
                                                    <span className="size-1.5 rounded-full bg-current" />
                                                    {event.risk}
                                                </span>
                                            </td>
                                            <td className="pr-4 text-center">
                                                <button
                                                    type="button"
                                                    onClick={(click) => {
                                                        click.stopPropagation();
                                                        setSelected(event);
                                                    }}
                                                    aria-label={`Ver evento ${event.id}`}
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!filtered.length && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No se encontraron eventos con estos filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t p-4 text-xs text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 142 registros
                            </span>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                {[1, 2, 3, 4, 5].map((page) => (
                                    <button
                                        key={page}
                                        type="button"
                                        className={`grid size-8 place-items-center rounded ${page === 1 ? 'bg-[#74000a] text-white' : 'border'}`}
                                    >
                                        {page}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    className="ml-2 flex items-center gap-3 rounded border px-3 py-2"
                                >
                                    12 por página
                                    <ChevronDown className="size-4" />
                                </button>
                            </div>
                        </footer>
                    </article>
                    <aside className={`${panel} overflow-hidden`}>
                        <header className="flex items-center justify-between border-b px-5 py-4">
                            <h2 className="flex items-center gap-2 font-bold">
                                <History className="size-5 text-[#74000a]" />
                                Detalle del evento
                            </h2>
                            <button type="button" aria-label="Cerrar detalle">
                                <X className="size-4" />
                            </button>
                        </header>
                        <div className="p-5">
                            <div className="flex items-start gap-4">
                                <span className="grid size-14 shrink-0 place-items-center rounded-full bg-rose-50 text-[#9b1923]">
                                    <FileText className="size-7" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <strong className="text-base">
                                            {selected.action} de{' '}
                                            {selected.module.toLowerCase()}
                                        </strong>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${riskStyle[selected.risk]}`}
                                        >
                                            {selected.risk}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {selected.record}
                                    </p>
                                    <p className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                                        <Clock3 className="size-4" />
                                        {selected.date}{' '}
                                        <span>· Hace 2 minutos</span>
                                    </p>
                                </div>
                            </div>
                            <div className="my-4 border-t" />
                            <dl className="grid grid-cols-2 gap-4 text-xs">
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Usuario
                                    </dt>
                                    <dd className="mt-1 flex items-center gap-2">
                                        <Users className="size-4" />
                                        {selected.user}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Dirección IP
                                    </dt>
                                    <dd className="mt-1 flex items-center gap-2">
                                        <Wifi className="size-4" />
                                        {selected.ip}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Empresa
                                    </dt>
                                    <dd className="mt-1 flex items-center gap-2">
                                        <Building2 className="size-4" />
                                        {selected.company}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Dispositivo
                                    </dt>
                                    <dd className="mt-1 flex items-center gap-2">
                                        <Laptop className="size-4" />
                                        Windows / Chrome 128.0
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Módulo
                                    </dt>
                                    <dd className="mt-1 flex items-center gap-2">
                                        <ShieldCheck className="size-4" />
                                        {selected.module}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Motivo
                                    </dt>
                                    <dd className="mt-1 flex items-center gap-2">
                                        <FileText className="size-4" />
                                        {selected.reason}
                                    </dd>
                                </div>
                            </dl>
                            <div className="my-4 border-t" />
                            <h3 className="mb-3 text-sm font-bold">
                                Cambios realizados
                            </h3>
                            <div className="overflow-hidden rounded-lg border">
                                <table className="w-full text-left text-[11px]">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="p-2">Campo</th>
                                            <th>Valor anterior</th>
                                            <th>Valor nuevo</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        <tr>
                                            <td className="p-2">Proveedor</td>
                                            <td>Comercial Andina S.A.</td>
                                            <td>Importadora Global S.A.</td>
                                        </tr>
                                        <tr>
                                            <td className="p-2">Monto total</td>
                                            <td>USD 12,500.00</td>
                                            <td className="font-bold text-red-600">
                                                USD 13,750.00
                                            </td>
                                        </tr>
                                        <tr>
                                            <td className="p-2">
                                                Fecha de entrega
                                            </td>
                                            <td>05 Oct 2026</td>
                                            <td className="font-bold text-red-600">
                                                12 Oct 2026
                                            </td>
                                        </tr>
                                        <tr>
                                            <td className="p-2">
                                                Condición de pago
                                            </td>
                                            <td>30 días</td>
                                            <td className="font-bold text-red-600">
                                                45 días
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div className="mt-4 flex items-center gap-3 rounded-lg bg-slate-50 p-3">
                                <Paperclip className="size-6 text-[#74000a]" />
                                <div className="min-w-0 flex-1">
                                    <strong className="block truncate text-xs">
                                        OC-2026-0045.pdf
                                    </strong>
                                    <small className="text-[10px] text-slate-500">
                                        Documento relacionado
                                    </small>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            `Documento ${selected.record} abierto.`,
                                        )
                                    }
                                    className="flex items-center gap-1 rounded border bg-white px-3 py-2 text-[10px]"
                                >
                                    Ver documento
                                    <ExternalLink className="size-3" />
                                </button>
                            </div>
                            <div className="mt-4 grid grid-cols-3 gap-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            `Registro ${selected.record} abierto.`,
                                        )
                                    }
                                    className="flex items-center justify-center gap-1 rounded-lg bg-[#74000a] px-2 py-2.5 text-[10px] font-semibold text-white"
                                >
                                    <Eye className="size-4" />
                                    Ver registro
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Comparación de cambios activada.',
                                        )
                                    }
                                    className="flex items-center justify-center gap-1 rounded-lg border border-[#9b1923] px-2 py-2.5 text-[10px] font-semibold text-[#74000a]"
                                >
                                    <History className="size-4" />
                                    Comparar
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Evidencia preparada para descargar.',
                                        )
                                    }
                                    className="flex items-center justify-center gap-1 rounded-lg border border-[#9b1923] px-2 py-2.5 text-[10px] font-semibold text-[#74000a]"
                                >
                                    <Download className="size-4" />
                                    Evidencia
                                </button>
                            </div>
                        </div>
                    </aside>
                </section>
            </div>
        </main>
    );
}
