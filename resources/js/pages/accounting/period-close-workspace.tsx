import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardCheck,
    Download,
    FileText,
    History,
    LockKeyhole,
    MoreHorizontal,
    RefreshCcw,
    Search,
    ShieldAlert,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type CloseStatus = 'Completado' | 'Advertencia' | 'Bloqueante' | 'Pendiente';
type ChecklistRow = {
    id: number;
    activity: string;
    description: string;
    owner: string;
    due: string;
    status: CloseStatus;
    updated: string;
};

const checklist: ChecklistRow[] = [
    {
        id: 1,
        activity: 'Ventas contabilizadas',
        description: 'Todas las facturas de ventas registradas',
        owner: 'Ana López',
        due: '28 Sep 2026',
        status: 'Completado',
        updated: '28 Sep 2026 16:20',
    },
    {
        id: 2,
        activity: 'Compras contabilizadas',
        description: 'Todas las facturas de compras registradas',
        owner: 'Luis Ramírez',
        due: '28 Sep 2026',
        status: 'Completado',
        updated: '28 Sep 2026 14:10',
    },
    {
        id: 3,
        activity: 'Bancos conciliados',
        description: 'Conciliación bancaria completa',
        owner: 'Carlos Mendoza',
        due: '29 Sep 2026',
        status: 'Completado',
        updated: '29 Sep 2026 09:15',
    },
    {
        id: 4,
        activity: 'Caja cerrada',
        description: 'Arqueo y cierre de caja chica',
        owner: 'Sofía Herrera',
        due: '29 Sep 2026',
        status: 'Completado',
        updated: '29 Sep 2026 11:32',
    },
    {
        id: 5,
        activity: 'Inventario revisado',
        description: 'Valuación y ajustes de inventario',
        owner: 'Juan Pérez',
        due: '30 Sep 2026',
        status: 'Advertencia',
        updated: '29 Sep 2026 18:45',
    },
    {
        id: 6,
        activity: 'Nómina contabilizada',
        description: 'Planillas y provisiones registradas',
        owner: 'María Contreras',
        due: '30 Sep 2026',
        status: 'Completado',
        updated: '29 Sep 2026 13:20',
    },
    {
        id: 7,
        activity: 'Depreciación',
        description: 'Cálculo y registro de depreciaciones',
        owner: 'Luis Ramírez',
        due: '30 Sep 2026',
        status: 'Completado',
        updated: '29 Sep 2026 10:12',
    },
    {
        id: 8,
        activity: 'Activos fijos',
        description: 'Revisión de movimientos de activos fijos',
        owner: 'Ana López',
        due: '30 Sep 2026',
        status: 'Completado',
        updated: '28 Sep 2026 17:30',
    },
    {
        id: 9,
        activity: 'Libros IVA',
        description: 'Generación y revisión de libros de IVA',
        owner: 'Carlos Mendoza',
        due: '30 Sep 2026',
        status: 'Bloqueante',
        updated: '29 Sep 2026 16:05',
    },
    {
        id: 10,
        activity: 'FEL / SAT',
        description: 'Validación y envío de documentos FEL',
        owner: 'Sofía Herrera',
        due: '30 Sep 2026',
        status: 'Pendiente',
        updated: '—',
    },
    {
        id: 11,
        activity: 'Documentos pendientes',
        description: 'Revisión de documentos sin contabilizar',
        owner: 'Juan Pérez',
        due: '30 Sep 2026',
        status: 'Advertencia',
        updated: '29 Sep 2026 15:40',
    },
    {
        id: 12,
        activity: 'CxC revisadas',
        description: 'Antigüedad de saldos y provisiones',
        owner: 'Ana López',
        due: '30 Sep 2026',
        status: 'Completado',
        updated: '29 Sep 2026 12:18',
    },
    {
        id: 13,
        activity: 'CxP revisadas',
        description: 'Antigüedad de saldos y provisiones',
        owner: 'Luis Ramírez',
        due: '30 Sep 2026',
        status: 'Completado',
        updated: '29 Sep 2026 11:56',
    },
];

const statusStyles: Record<CloseStatus, string> = {
    Completado: 'bg-emerald-100 text-emerald-700',
    Advertencia: 'bg-amber-100 text-amber-700',
    Bloqueante: 'bg-red-100 text-red-700',
    Pendiente: 'bg-rose-100 text-rose-700',
};

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    trend,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
    trend?: string;
}) {
    const trendTone = trend?.includes('↓')
        ? 'text-red-600'
        : 'text-emerald-600';

    return (
        <article
            className={`${panel} flex min-h-[98px] min-w-0 items-center gap-4 overflow-hidden p-5`}
        >
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div className="min-w-0">
                <p className="text-xs font-medium text-slate-600">{label}</p>
                <div className="mt-1 flex flex-wrap items-end gap-3">
                    <strong className="text-2xl font-black text-slate-950">
                        {value}
                    </strong>
                    {trend && (
                        <span
                            className={`pb-1 text-xs font-semibold ${trendTone}`}
                        >
                            {trend}
                        </span>
                    )}
                </div>
                <p className="mt-1 text-[11px] text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

export default function PeriodCloseWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('Todos');
    const [notice, setNotice] = useState('');
    const [detailsOpen, setDetailsOpen] = useState(true);
    const [validated, setValidated] = useState(false);

    const rows = useMemo(
        () =>
            checklist.filter((row) => {
                const matchesStatus =
                    status === 'Todos' || row.status === status;
                const haystack =
                    `${row.activity} ${row.description} ${row.owner}`.toLowerCase();

                return matchesStatus && haystack.includes(query.toLowerCase());
            }),
        [query, status],
    );

    const completed = checklist.filter(
        (row) => row.status === 'Completado',
    ).length;
    const progress = Math.round((completed / checklist.length) * 100);
    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3500);
    };

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] px-4 py-5 text-[#172033] sm:px-6">
            <Head title={`Cierre de Período · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Cierre de
                            Período
                        </p>
                        <h1 className="text-[2rem] leading-none font-black tracking-tight text-[#74000a]">
                            Cierre de Período
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Validación y bloqueo contable del mes
                        </p>
                    </div>
                    <blockquote className="max-w-md pt-6 text-right text-sm text-slate-500 italic">
                        “Disciplina hoy, información confiable mañana.”
                        <span className="block text-xs">
                            — Atlantia Finance
                        </span>
                    </blockquote>
                </header>

                {notice && (
                    <div
                        className={`flex items-center justify-between rounded-lg border px-4 py-3 text-xs ${validated ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}
                    >
                        <span className="flex items-center gap-2">
                            {validated ? (
                                <AlertTriangle className="size-4" />
                            ) : (
                                <Check className="size-4" />
                            )}
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}

                <section className="grid grid-cols-4 gap-3">
                    <Metric
                        icon={CalendarDays}
                        label="Períodos abiertos"
                        value="1"
                        detail="de 12 del año fiscal"
                        tone="bg-rose-50 text-rose-700"
                    />
                    <Metric
                        icon={CheckCircle2}
                        label="Checklist completado"
                        value={`${progress}%`}
                        detail="vs. período anterior"
                        trend="↑ 15%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={AlertTriangle}
                        label="Pendientes críticos"
                        value="2"
                        detail="vs. período anterior"
                        trend="↓ 60%"
                        tone="bg-red-50 text-red-700"
                    />
                    <Metric
                        icon={Building2}
                        label="Empresas listas para cerrar"
                        value="4 de 5"
                        detail="vs. período anterior"
                        trend="↑ 25%"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                </section>

                <section
                    className={`${panel} grid gap-3 p-4 xl:grid-cols-[1fr_1fr_1fr_1fr_auto_auto]`}
                >
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Empresa</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center justify-between rounded-lg border border-[#dfe5ef] bg-white px-3 text-left text-xs font-normal text-[#26344d]"
                        >
                            Todas las empresas
                            <ChevronDown className="size-4" />
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Período</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center justify-between rounded-lg border border-[#dfe5ef] bg-white px-3 text-left text-xs font-normal text-[#26344d]"
                        >
                            Septiembre 2026
                            <ChevronDown className="size-4" />
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Responsable</span>
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Todos"
                            className="h-10 w-full rounded-lg border border-[#dfe5ef] bg-white px-3 text-xs font-normal text-[#26344d]"
                        />
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Estado</span>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="h-10 w-full rounded-lg border border-[#dfe5ef] bg-white px-3 text-xs font-normal text-[#26344d]"
                        >
                            <option>Todos</option>
                            <option>Completado</option>
                            <option>Advertencia</option>
                            <option>Bloqueante</option>
                            <option>Pendiente</option>
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={() => {
                                setQuery('');
                                setStatus('Todos');
                            }}
                            className="h-10 rounded-lg border border-[#dfe5ef] bg-slate-50 px-6 text-xs font-semibold text-slate-600"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={() =>
                                action(
                                    `Se encontraron ${checklist.length - completed} elementos que requieren revisión.`,
                                )
                            }
                            className="flex h-10 items-center justify-center gap-2 rounded-lg bg-[#74000a] px-7 text-xs font-semibold text-white"
                        >
                            <Search className="size-4" />
                            Buscar
                        </button>
                    </div>
                </section>

                <section className="grid items-start gap-4 2xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="space-y-4">
                        <article className={`${panel} overflow-hidden`}>
                            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                                <div>
                                    <h2 className="flex items-center gap-2 font-bold">
                                        <ClipboardCheck className="size-5 text-[#74000a]" />
                                        Checklist de cierre de período
                                    </h2>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Verifica que todos los procesos
                                        contables estén completados antes de
                                        cerrar el período.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        action(
                                            'Checklist preparado para exportar.',
                                        )
                                    }
                                    className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                                >
                                    <Download className="size-4" />
                                    Exportar checklist
                                </button>
                            </header>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[1050px] text-left text-[11px]">
                                    <thead className="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th className="px-4 py-3">#</th>
                                            <th>Actividad</th>
                                            <th>Descripción</th>
                                            <th>Responsable</th>
                                            <th>Fecha límite</th>
                                            <th>Estado</th>
                                            <th>Última actualización</th>
                                            <th className="pr-4 text-center">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="hover:bg-rose-50/30"
                                            >
                                                <td className="px-4 py-2.5">
                                                    {row.id}
                                                </td>
                                                <td className="font-semibold">
                                                    {row.activity}
                                                </td>
                                                <td className="text-slate-600">
                                                    {row.description}
                                                </td>
                                                <td>{row.owner}</td>
                                                <td>{row.due}</td>
                                                <td>
                                                    <span
                                                        className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold ${statusStyles[row.status]}`}
                                                    >
                                                        {row.status ===
                                                        'Completado' ? (
                                                            <CheckCircle2 className="size-3" />
                                                        ) : (
                                                            <span className="size-1.5 rounded-full bg-current" />
                                                        )}
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="text-slate-600">
                                                    {row.updated}
                                                </td>
                                                <td className="pr-4">
                                                    <div className="flex justify-center gap-3">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                action(
                                                                    `Detalle abierto: ${row.activity}.`,
                                                                )
                                                            }
                                                            aria-label={`Ver ${row.activity}`}
                                                        >
                                                            <FileText className="size-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            aria-label={`Más opciones de ${row.activity}`}
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {rows.length === 0 && (
                                    <div className="p-10 text-center text-sm text-slate-400">
                                        No hay actividades para estos filtros.
                                    </div>
                                )}
                            </div>
                        </article>

                        <article className={`${panel} overflow-hidden`}>
                            <header className="flex items-center justify-between border-b px-5 py-4">
                                <div>
                                    <h2 className="flex items-center gap-2 font-bold">
                                        <History className="size-5 text-[#74000a]" />
                                        Historial de cierres
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Últimos períodos cerrados y estado
                                        actual.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="flex items-center gap-2 text-xs font-semibold text-[#74000a]"
                                >
                                    Ver todo el historial
                                    <ChevronRight className="size-4" />
                                </button>
                            </header>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-left text-xs">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-5 py-3">
                                                Período
                                            </th>
                                            <th>Empresa</th>
                                            <th>Fecha de cierre</th>
                                            <th>Cerrado por</th>
                                            <th>Estado</th>
                                            <th>Observaciones</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {[
                                            [
                                                'Agosto 2026',
                                                '31 Ago 2026 18:24',
                                                'María Contreras',
                                                'Cierre sin observaciones',
                                            ],
                                            [
                                                'Julio 2026',
                                                '31 Jul 2026 17:10',
                                                'María Contreras',
                                                'Cierre sin observaciones',
                                            ],
                                            [
                                                'Junio 2026',
                                                '30 Jun 2026 16:58',
                                                'Carlos Mendoza',
                                                'Ajuste en inventario',
                                            ],
                                            [
                                                'Mayo 2026',
                                                '31 May 2026 17:12',
                                                'María Contreras',
                                                'Cierre sin observaciones',
                                            ],
                                        ].map((item) => (
                                            <tr key={item[0]}>
                                                <td className="px-5 py-3 font-semibold">
                                                    {item[0]}
                                                </td>
                                                <td>{company.name}</td>
                                                <td>{item[1]}</td>
                                                <td>{item[2]}</td>
                                                <td>
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 font-semibold text-emerald-700">
                                                        <CheckCircle2 className="size-3" />
                                                        Cerrado
                                                    </span>
                                                </td>
                                                <td>{item[3]}</td>
                                                <td>
                                                    <Download className="size-4" />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    </div>

                    <aside className={`${panel} overflow-hidden`}>
                        <header className="border-b px-5 py-4">
                            <h2 className="flex items-center gap-2 font-bold">
                                <LockKeyhole className="size-5 text-[#74000a]" />
                                Preparación para cierre
                            </h2>
                        </header>
                        <div className="p-5">
                            <div className="flex items-center gap-6">
                                <div
                                    className="relative grid size-28 shrink-0 place-items-center rounded-full"
                                    style={{
                                        background: `conic-gradient(#16a34a ${progress * 3.6}deg, #e2e8f0 0deg)`,
                                    }}
                                >
                                    <div className="grid size-20 place-items-center rounded-full bg-white text-2xl font-bold">
                                        {progress}%
                                    </div>
                                </div>
                                <dl className="flex-1 space-y-2 text-xs">
                                    <div className="flex justify-between">
                                        <dt className="flex items-center gap-2">
                                            <i className="size-2 rounded-full bg-emerald-600" />
                                            Completadas
                                        </dt>
                                        <dd className="font-bold">
                                            {completed}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="flex items-center gap-2">
                                            <i className="size-2 rounded-full bg-amber-400" />
                                            Pendientes
                                        </dt>
                                        <dd className="font-bold">1</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="flex items-center gap-2">
                                            <i className="size-2 rounded-full bg-orange-500" />
                                            Advertencias
                                        </dt>
                                        <dd className="font-bold">2</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="flex items-center gap-2">
                                            <i className="size-2 rounded-full bg-red-700" />
                                            Bloqueantes
                                        </dt>
                                        <dd className="font-bold">1</dd>
                                    </div>
                                    <div className="flex justify-between border-t pt-2">
                                        <dt>Total</dt>
                                        <dd className="font-bold">13</dd>
                                    </div>
                                </dl>
                            </div>
                            <div className="mt-5 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">
                                <strong className="flex items-center gap-2 text-sm">
                                    <ShieldAlert className="size-5" />
                                    No se puede cerrar el período
                                </strong>
                                <p className="mt-1 text-xs">
                                    Existe 1 bloqueo crítico que debe resolverse
                                    antes de continuar.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setDetailsOpen(!detailsOpen)}
                                className="mt-3 flex w-full items-center justify-between rounded-lg border px-4 py-3 text-sm font-bold"
                            >
                                Detalles
                                <ChevronDown
                                    className={`size-4 transition ${detailsOpen ? 'rotate-180' : ''}`}
                                />
                            </button>
                            {detailsOpen && (
                                <div className="mt-3 space-y-3 rounded-lg bg-slate-50 p-4">
                                    {[
                                        [
                                            'bg-red-700',
                                            'FEL / SAT pendiente de envío',
                                            'Es necesario validar y enviar los documentos FEL del mes.',
                                        ],
                                        [
                                            'bg-amber-500',
                                            'Inventario con advertencias',
                                            'Existen diferencias en la valuación de inventario.',
                                        ],
                                        [
                                            'bg-amber-500',
                                            'Documentos pendientes',
                                            '3 documentos sin contabilizar en el período.',
                                        ],
                                    ].map((item) => (
                                        <div
                                            key={item[1]}
                                            className="flex gap-3"
                                        >
                                            <span
                                                className={`mt-1.5 size-2.5 shrink-0 rounded-full ${item[0]}`}
                                            />
                                            <div>
                                                <strong className="block text-xs">
                                                    {item[1]}
                                                </strong>
                                                <p className="mt-0.5 text-[11px] text-slate-500">
                                                    {item[2]}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <div className="mt-4 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setValidated(true);
                                        action(
                                            'Prevalidación completada: hay 1 bloqueo y 3 observaciones por resolver.',
                                        );
                                    }}
                                    className="flex items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-xs font-semibold"
                                >
                                    <Search className="size-4" />
                                    Prevalidar
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setValidated(true);
                                        action(
                                            'El cierre permanece bloqueado hasta resolver Libros IVA y FEL / SAT.',
                                        );
                                    }}
                                    className="flex items-center justify-center gap-2 rounded-lg bg-[#74000a] px-3 py-2.5 text-xs font-semibold text-white"
                                >
                                    <LockKeyhole className="size-4" />
                                    Cerrar período
                                </button>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        'Solicitud de reapertura preparada para autorización.',
                                    )
                                }
                                className="mt-2 flex w-full items-center justify-center gap-2 rounded-lg border border-[#9b1923] px-3 py-2.5 text-xs font-semibold text-[#74000a]"
                            >
                                <RefreshCcw className="size-4" />
                                Reabrir con autorización
                            </button>
                        </div>
                    </aside>
                </section>
            </div>
        </main>
    );
}
