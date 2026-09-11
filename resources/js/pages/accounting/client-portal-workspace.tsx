import { Head } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronRight,
    ClipboardList,
    CloudUpload,
    FileCheck2,
    FileText,
    Headphones,
    Mail,
    MessageCircle,
    MoreVertical,
    Search,
    Send,
    Upload,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

const requests = [
    [
        'Estados de cuenta bancarios (abril 2025)',
        'Alta',
        '30 abr 2025',
        'María López',
        'Pendiente',
    ],
    [
        'Facturas de gastos del mes',
        'Alta',
        '30 abr 2025',
        'María López',
        'Pendiente',
    ],
    [
        'Nómina y constancias de pago (marzo)',
        'Media',
        '25 abr 2025',
        'Jorge Ramírez',
        'En revisión',
    ],
    [
        'Contratos de proveedores nuevos',
        'Media',
        '10 may 2025',
        'Jorge Ramírez',
        'Pendiente',
    ],
    [
        'Declaración de IVA y anexos',
        'Alta',
        '30 abr 2025',
        'María López',
        'Pendiente',
    ],
];

const deadlines = [
    ['IVA Mensual', 'Abril 2025', '16 may 2025', 'Próximo'],
    ['ISR Retenciones', 'Abril 2025', '16 may 2025', 'Próximo'],
    ['Planilla IGSS', 'Abril 2025', '20 may 2025', 'Próximo'],
    ['Impuesto de Solidaridad', 'Abril 2025', '31 may 2025', 'Programado'],
];

const reports = [
    ['Estado de Resultados', 'Abril 2025'],
    ['Balance General', 'Abril 2025'],
    ['Libro de IVA', 'Abril 2025'],
    ['Integración de Cuentas', 'Marzo 2025'],
];

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    alert = false,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
    alert?: boolean;
}) {
    return (
        <article
            className={`${panel} flex min-h-28 min-w-0 items-center gap-4 overflow-hidden p-5`}
        >
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-7" />
            </span>
            <div className="min-w-0">
                <p className="text-sm text-slate-600">{label}</p>
                <div className="flex items-end gap-3">
                    <strong className="text-3xl text-slate-950">{value}</strong>
                    <span
                        className={`pb-1 text-xs font-semibold ${alert ? 'text-red-500' : 'text-emerald-600'}`}
                    >
                        {detail}
                    </span>
                </div>
                <p className="text-[10px] text-slate-500">vs. mes anterior</p>
            </div>
        </article>
    );
}

function Shortcut({
    icon: Icon,
    title,
    detail,
    tone,
    onClick,
}: {
    icon: LucideIcon;
    title: string;
    detail: string;
    tone: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`${panel} flex min-h-24 min-w-0 items-center gap-4 overflow-hidden p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md`}
        >
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <span className="flex-1">
                <strong className="block text-sm">{title}</strong>
                <small className="mt-1 block text-[10px] leading-4 text-slate-500">
                    {detail}
                </small>
            </span>
            <ChevronRight className="size-4 text-slate-400" />
        </button>
    );
}

export default function ClientPortalWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [notice, setNotice] = useState('');
    const [query, setQuery] = useState('');
    const [uploaded, setUploaded] = useState<string[]>([]);
    const inputRef = useRef<HTMLInputElement>(null);
    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3200);
    };
    const handleFiles = (files: FileList | null) => {
        if (!files?.length) {
            return;
        }

        const names = Array.from(files).map((file) => file.name);
        setUploaded((current) => [...names, ...current]);
        action(`${names.length} archivo(s) cargado(s) correctamente.`);
    };

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Portal Cliente · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid size-16 place-items-center rounded-2xl bg-rose-50 text-[#74000a]">
                            <CheckCircle2 className="size-8" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-bold">
                                Portal Cliente
                            </h1>
                            <p className="text-base text-slate-500">
                                Comunicación y autoservicio para empresas
                                clientes
                            </p>
                        </div>
                    </div>
                    <div className="max-w-2xl flex-1 rounded-xl border border-slate-200 bg-gradient-to-r from-white to-slate-100 px-5 py-4 lg:min-w-[440px]">
                        <strong className="text-sm">
                            Un mejor trabajo, en equipo
                        </strong>
                        <p className="mt-1 text-xs text-slate-500">
                            Comparte documentos, da seguimiento a tus
                            solicitudes y mantente al día con tus obligaciones.
                        </p>
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
                <div className="grid gap-4 2xl:grid-cols-[1fr_370px]">
                    <div className="space-y-4">
                        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Metric
                                icon={FileCheck2}
                                label="Solicitudes abiertas"
                                value="3"
                                detail="↓ 25%"
                                tone="bg-emerald-50 text-emerald-600"
                            />
                            <Metric
                                icon={FileText}
                                label="Documentos recibidos"
                                value="24"
                                detail="↑ 33%"
                                tone="bg-blue-50 text-blue-600"
                            />
                            <Metric
                                icon={ClipboardList}
                                label="Tareas pendientes"
                                value="5"
                                detail="↑ 67%"
                                tone="bg-orange-50 text-orange-600"
                                alert
                            />
                            <Metric
                                icon={Mail}
                                label="Mensajes sin leer"
                                value="2"
                                detail=""
                                tone="bg-violet-50 text-violet-600"
                            />
                        </section>
                        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Shortcut
                                icon={Upload}
                                title="Subir documentos"
                                detail="Comparte facturas, estados de cuenta, contratos y más."
                                tone="bg-slate-50 text-slate-800"
                                onClick={() => inputRef.current?.click()}
                            />
                            <Shortcut
                                icon={ClipboardList}
                                title="Ver solicitudes"
                                detail="Revisa los requerimientos de tu contador."
                                tone="bg-rose-50 text-rose-600"
                                onClick={() =>
                                    action(
                                        'Mostrando las solicitudes del contador.',
                                    )
                                }
                            />
                            <Shortcut
                                icon={MessageCircle}
                                title="Mensajería"
                                detail="Comunícate con tu equipo contable."
                                tone="bg-emerald-50 text-emerald-600"
                                onClick={() =>
                                    action('Centro de mensajería abierto.')
                                }
                            />
                            <Shortcut
                                icon={BarChart3}
                                title="Reportes disponibles"
                                detail="Consulta tus reportes contables y fiscales."
                                tone="bg-blue-50 text-blue-600"
                                onClick={() =>
                                    action('Mostrando reportes disponibles.')
                                }
                            />
                        </section>
                        <section className={`${panel} overflow-hidden`}>
                            <header className="flex items-center justify-between border-b px-4 py-3">
                                <h2 className="flex items-center gap-2 font-bold">
                                    <ClipboardList className="size-5" />
                                    Solicitudes del contador
                                </h2>
                                <button
                                    type="button"
                                    className="text-xs text-[#74000a] underline"
                                >
                                    Ver todas
                                </button>
                            </header>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[780px] text-left text-xs">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-2">
                                                Solicitud
                                            </th>
                                            <th>Prioridad</th>
                                            <th>Fecha límite</th>
                                            <th>Responsable</th>
                                            <th>Estado</th>
                                            <th className="pr-4 text-center">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {requests
                                            .filter((item) =>
                                                item[0]
                                                    .toLowerCase()
                                                    .includes(
                                                        query.toLowerCase(),
                                                    ),
                                            )
                                            .map((item) => (
                                                <tr key={item[0]}>
                                                    <td className="px-4 py-3 font-medium">
                                                        {item[0]}
                                                    </td>
                                                    <td>
                                                        <span
                                                            className={`rounded-full px-3 py-1 ${item[1] === 'Alta' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600'}`}
                                                        >
                                                            {item[1]}
                                                        </span>
                                                    </td>
                                                    <td
                                                        className={
                                                            item[1] === 'Alta'
                                                                ? 'text-red-600'
                                                                : ''
                                                        }
                                                    >
                                                        {item[2]}
                                                    </td>
                                                    <td>{item[3]}</td>
                                                    <td>
                                                        <span
                                                            className={`rounded-full px-3 py-1 ${item[4] === 'En revisión' ? 'bg-blue-100 text-blue-600' : 'bg-amber-100 text-amber-700'}`}
                                                        >
                                                            {item[4]}
                                                        </span>
                                                    </td>
                                                    <td className="pr-4 text-center">
                                                        <MoreVertical className="inline size-4" />
                                                    </td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <div className="grid gap-4 xl:grid-cols-2">
                            <section className={`${panel} overflow-hidden`}>
                                <header className="flex items-center justify-between border-b px-4 py-3">
                                    <h2 className="flex items-center gap-2 font-bold">
                                        <CalendarDays className="size-5" />
                                        Próximos vencimientos
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-xs text-blue-700 underline"
                                    >
                                        Ver calendario
                                    </button>
                                </header>
                                <table className="w-full text-left text-xs">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-2">
                                                Obligación
                                            </th>
                                            <th>Período</th>
                                            <th>Fecha límite</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {deadlines.map((item) => (
                                            <tr key={item[0]}>
                                                <td className="px-4 py-3 font-medium">
                                                    {item[0]}
                                                </td>
                                                <td>{item[1]}</td>
                                                <td className="text-red-600">
                                                    {item[2]}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`rounded-full px-3 py-1 ${item[3] === 'Programado' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-600'}`}
                                                    >
                                                        {item[3]}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </section>
                            <section className={`${panel} overflow-hidden`}>
                                <header className="flex items-center justify-between border-b px-4 py-3">
                                    <h2 className="flex items-center gap-2 font-bold">
                                        <BarChart3 className="size-5" />
                                        Reportes disponibles
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-xs text-blue-700 underline"
                                    >
                                        Ver todos
                                    </button>
                                </header>
                                <div className="divide-y">
                                    {reports.map((report) => (
                                        <div
                                            key={report[0]}
                                            className="flex items-center gap-3 px-4 py-2"
                                        >
                                            <FileText className="size-5" />
                                            <span className="flex-1">
                                                <strong className="block text-xs">
                                                    {report[0]}
                                                </strong>
                                                <small className="text-[10px] text-slate-500">
                                                    {report[1]}
                                                </small>
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    action(
                                                        `${report[0]} preparado para descargar.`,
                                                    )
                                                }
                                                className="rounded-lg border px-5 py-2 text-[10px]"
                                            >
                                                Descargar
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>
                    </div>
                    <aside className="space-y-4">
                        <section className={`${panel} p-4`}>
                            <h2 className="flex items-center gap-2 font-bold">
                                <CloudUpload className="size-5" />
                                Carga rápida de documentos
                            </h2>
                            <input
                                ref={inputRef}
                                type="file"
                                multiple
                                className="hidden"
                                onChange={(event) =>
                                    handleFiles(event.target.files)
                                }
                            />
                            <button
                                type="button"
                                onClick={() => inputRef.current?.click()}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    handleFiles(event.dataTransfer.files);
                                }}
                                className="mt-4 grid min-h-44 w-full place-items-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-5 text-center"
                            >
                                <span>
                                    <CloudUpload className="mx-auto size-10 text-slate-600" />
                                    <strong className="mt-3 block text-sm">
                                        Arrastra tus archivos aquí
                                    </strong>
                                    <span className="mt-1 block text-xs text-slate-500">
                                        o haz clic para seleccionar
                                    </span>
                                    <small className="mt-4 block text-[10px] text-slate-400">
                                        PDF, XML, XLSX, PNG, JPG (máx. 20 MB)
                                    </small>
                                </span>
                            </button>
                            {uploaded.length > 0 && (
                                <div className="mt-3 space-y-1">
                                    {uploaded.slice(0, 3).map((file) => (
                                        <p
                                            key={file}
                                            className="truncate rounded bg-emerald-50 px-2 py-1 text-[10px] text-emerald-700"
                                        >
                                            ✓ {file}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </section>
                        <section className={`${panel} p-4`}>
                            <header className="flex justify-between">
                                <h2 className="font-bold">
                                    Actividad reciente
                                </h2>
                                <button
                                    type="button"
                                    className="text-xs text-blue-700 underline"
                                >
                                    Ver toda
                                </button>
                            </header>
                            <div className="mt-4 space-y-4 border-l border-slate-200 pl-5">
                                {[
                                    [
                                        'María López',
                                        'Te envió una nueva solicitud',
                                        'hace 2 horas',
                                        'bg-orange-100 text-orange-600',
                                    ],
                                    [
                                        'Tú',
                                        'Subiste 3 documentos',
                                        'hace 4 horas',
                                        'bg-emerald-100 text-emerald-600',
                                    ],
                                    [
                                        'Jorge Ramírez',
                                        'Comentó en: Facturas de gastos',
                                        'hace 1 día',
                                        'bg-violet-100 text-violet-600',
                                    ],
                                    [
                                        'Sistema',
                                        'Nuevo reporte disponible',
                                        'hace 1 día',
                                        'bg-amber-100 text-amber-600',
                                    ],
                                    [
                                        'María López',
                                        'Marcó como resuelta una solicitud',
                                        'hace 2 días',
                                        'bg-emerald-100 text-emerald-600',
                                    ],
                                ].map((item) => (
                                    <div
                                        key={item[1]}
                                        className="relative flex gap-3"
                                    >
                                        <span
                                            className={`absolute -left-9 grid size-7 place-items-center rounded-full ${item[3]}`}
                                        >
                                            <Check className="size-3" />
                                        </span>
                                        <span className="flex-1">
                                            <strong className="block text-xs">
                                                {item[0]}
                                            </strong>
                                            <small className="text-[10px] text-slate-500">
                                                {item[1]}
                                            </small>
                                        </span>
                                        <small className="text-[9px] text-slate-400">
                                            {item[2]}
                                        </small>
                                    </div>
                                ))}
                            </div>
                        </section>
                        <section className={`${panel} p-4`}>
                            <h2 className="flex items-center gap-2 font-bold">
                                <Headphones className="size-5" />
                                ¿Necesitas ayuda?
                            </h2>
                            <p className="mt-3 text-xs text-slate-500">
                                Estamos para brindarte soporte en todo momento.
                            </p>
                            <div className="mt-4 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        action('Mensaje de soporte preparado.')
                                    }
                                    className="flex items-center justify-center gap-2 rounded-lg bg-[#74000a] py-2.5 text-xs font-semibold text-white"
                                >
                                    <Send className="size-4" />
                                    Enviar mensaje
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        action('Preguntas frecuentes abiertas.')
                                    }
                                    className="rounded-lg bg-slate-100 py-2.5 text-xs font-semibold"
                                >
                                    Preguntas frecuentes
                                </button>
                            </div>
                        </section>
                    </aside>
                </div>
                <label className="relative block max-w-md">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Buscar en solicitudes..."
                        className="w-full rounded-lg border bg-white py-2.5 pr-3 pl-9 text-xs"
                    />
                </label>
            </div>
        </main>
    );
}
