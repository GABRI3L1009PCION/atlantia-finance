import { Head } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronRight,
    ClipboardList,
    CloudUpload,
    FileInput,
    FileText,
    Handshake,
    Headphones,
    Mail,
    MessageSquare,
    MoreVertical,
    Send,
    TrendingDown,
    TrendingUp,
    Upload,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';

const card =
    'rounded-2xl border border-[#dfe5ef] bg-white shadow-[0_16px_45px_-35px_rgba(15,23,42,0.55)]';
const tinyText = 'text-[11px] leading-4 text-[#617089]';

const requests = [
    {
        name: 'Estados de cuenta bancarios (abril 2025)',
        priority: 'Alta',
        due: '30 abr 2025',
        owner: 'María López',
        status: 'Pendiente',
    },
    {
        name: 'Facturas de gastos del mes',
        priority: 'Alta',
        due: '30 abr 2025',
        owner: 'María López',
        status: 'Pendiente',
    },
    {
        name: 'Nómina y constancias de pago (marzo)',
        priority: 'Media',
        due: '25 abr 2025',
        owner: 'Jorge Ramírez',
        status: 'En revisión',
    },
    {
        name: 'Contratos de proveedores nuevos',
        priority: 'Media',
        due: '10 may 2025',
        owner: 'Jorge Ramírez',
        status: 'Pendiente',
    },
    {
        name: 'Declaración de IVA y anexos',
        priority: 'Alta',
        due: '30 abr 2025',
        owner: 'María López',
        status: 'Pendiente',
    },
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

const activity = [
    [
        'María López',
        'Te envió una nueva solicitud',
        'hace 2 horas',
        FileText,
        'bg-orange-100 text-orange-600',
    ],
    [
        'Tú',
        'Subiste 3 documentos',
        'hace 4 horas',
        Upload,
        'bg-emerald-100 text-emerald-700',
    ],
    [
        'Jorge Ramírez',
        'Comentó en: Facturas de gastos',
        'hace 1 día',
        MessageSquare,
        'bg-violet-100 text-violet-700',
    ],
    [
        'Sistema',
        'Nuevo reporte disponible: Estado de Resultados (abril 2025)',
        'hace 1 día',
        BarChart3,
        'bg-amber-100 text-amber-700',
    ],
    [
        'María López',
        'Marcó como resuelta una solicitud',
        'hace 2 días',
        Check,
        'bg-emerald-100 text-emerald-700',
    ],
] as const;

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    foot,
    tone,
    direction,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail?: string;
    foot: string;
    tone: string;
    direction?: 'up' | 'down';
}) {
    const Trend = direction === 'down' ? TrendingDown : TrendingUp;

    return (
        <article
            className={`${card} flex min-h-[118px] items-center gap-4 p-4`}
        >
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                <Icon className="size-7" strokeWidth={2.1} />
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-medium text-[#121a32]">
                    {label}
                </p>
                <div className="mt-1 flex items-end gap-3">
                    <strong className="text-[2rem] leading-none font-extrabold tracking-tight text-[#071029]">
                        {value}
                    </strong>
                    {detail && (
                        <span
                            className={`mb-1 inline-flex items-center gap-1 text-xs font-bold ${
                                detail.includes('67')
                                    ? 'text-red-500'
                                    : 'text-emerald-600'
                            }`}
                        >
                            <Trend className="size-3.5" />
                            {detail}
                        </span>
                    )}
                </div>
                <p className="mt-1 text-[11px] text-[#64748b]">{foot}</p>
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
            className={`${card} flex min-h-[104px] items-center gap-4 p-4 text-left transition hover:-translate-y-0.5 hover:border-[#cbd5e1] hover:shadow-[0_22px_55px_-35px_rgba(15,23,42,0.7)]`}
        >
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <span className="min-w-0 flex-1">
                <strong className="block text-sm font-extrabold text-[#071029]">
                    {title}
                </strong>
                <small className={`mt-1 block ${tinyText}`}>{detail}</small>
            </span>
            <ChevronRight className="size-4 shrink-0 text-[#7c889b]" />
        </button>
    );
}

function Pill({ children, tone }: { children: React.ReactNode; tone: string }) {
    return (
        <span
            className={`inline-flex min-w-20 justify-center rounded-lg px-3 py-1 text-[11px] font-semibold ${tone}`}
        >
            {children}
        </span>
    );
}

export default function ClientPortalWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [notice, setNotice] = useState('');
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
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] px-4 py-5 text-[#10182d] sm:px-6">
            <Head title={`Portal Cliente · ${company.name}`} />

            <div className="mx-auto max-w-[1900px] space-y-5">
                <header className="grid gap-4 xl:grid-cols-[1fr_1.2fr]">
                    <div className="flex min-w-0 items-center gap-5">
                        <span className="grid size-[84px] shrink-0 place-items-center rounded-2xl bg-[#fbebef] text-[#730009]">
                            <Handshake className="size-10" strokeWidth={2.3} />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[2.2rem] leading-none font-black tracking-tight text-[#071029]">
                                Portal Cliente
                            </h1>
                            <p className="mt-2 text-lg leading-6 text-[#526079]">
                                Comunicación y autoservicio para empresas
                                clientes
                            </p>
                        </div>
                    </div>

                    <section className="relative hidden overflow-hidden rounded-2xl border border-[#dfe5ef] bg-gradient-to-r from-white via-[#f5f8fc] to-[#edf2f8] px-6 py-5 shadow-[0_14px_40px_-34px_rgba(15,23,42,0.8)] lg:block">
                        <div className="relative z-10 max-w-[540px]">
                            <strong className="text-base font-extrabold text-[#071029]">
                                Un mejor trabajo, en equipo
                            </strong>
                            <p className="mt-1 max-w-xl text-sm leading-5 text-[#526079]">
                                Comparte documentos, da seguimiento a tus
                                solicitudes y mantente al día con tus
                                obligaciones.
                            </p>
                        </div>
                        <FileText className="absolute top-5 right-16 size-16 rotate-12 text-[#b7c1d2]/55" />
                        <BarChart3 className="absolute right-32 bottom-4 size-12 -rotate-12 text-[#c8d1df]/70" />
                    </section>
                </header>

                {notice && (
                    <div className="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
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

                <div className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_386px]">
                    <div className="min-w-0 space-y-5">
                        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <Metric
                                icon={FileInput}
                                label="Solicitudes abiertas"
                                value="3"
                                detail="25%"
                                foot="vs. mes anterior"
                                tone="bg-emerald-50 text-emerald-600"
                                direction="down"
                            />
                            <Metric
                                icon={FileText}
                                label="Documentos recibidos"
                                value="24"
                                detail="33%"
                                foot="en este período"
                                tone="bg-blue-50 text-blue-600"
                                direction="up"
                            />
                            <Metric
                                icon={CheckCircle2}
                                label="Tareas pendientes"
                                value="5"
                                detail="67%"
                                foot="requieren atención"
                                tone="bg-orange-50 text-orange-600"
                                direction="up"
                            />
                            <Metric
                                icon={Mail}
                                label="Mensajes sin leer"
                                value="2"
                                foot="de tu contador"
                                tone="bg-violet-50 text-violet-700"
                            />
                        </section>

                        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <Shortcut
                                icon={Upload}
                                title="Subir documentos"
                                detail="Comparte facturas, estados de cuenta, contratos y más."
                                tone="bg-slate-50 text-[#071029]"
                                onClick={() => inputRef.current?.click()}
                            />
                            <Shortcut
                                icon={ClipboardList}
                                title="Ver solicitudes"
                                detail="Revisa los requerimientos de tu contador."
                                tone="bg-rose-50 text-rose-600"
                                onClick={() =>
                                    action(
                                        'Mostrando solicitudes del contador.',
                                    )
                                }
                            />
                            <Shortcut
                                icon={MessageSquare}
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

                        <section className={`${card} overflow-hidden`}>
                            <header className="flex items-center justify-between px-5 py-4">
                                <h2 className="flex items-center gap-3 text-lg font-extrabold text-[#071029]">
                                    <FileText className="size-5" />
                                    Solicitudes del contador
                                </h2>
                                <button
                                    type="button"
                                    className="text-sm font-medium text-[#071029] underline"
                                >
                                    Ver todas
                                </button>
                            </header>
                            <div className="overflow-x-auto px-5 pb-4">
                                <table className="w-full min-w-[860px] border-separate border-spacing-0 text-left text-sm">
                                    <thead>
                                        <tr className="bg-[#eef2f7] text-xs font-bold text-[#566176]">
                                            <th className="rounded-l-lg px-4 py-3">
                                                Solicitud
                                            </th>
                                            <th className="px-4 py-3">
                                                Prioridad
                                            </th>
                                            <th className="px-4 py-3">
                                                Fecha límite
                                            </th>
                                            <th className="px-4 py-3">
                                                Responsable
                                            </th>
                                            <th className="px-4 py-3">
                                                Estado
                                            </th>
                                            <th className="rounded-r-lg px-4 py-3 text-center">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {requests.map((request) => (
                                            <tr key={request.name}>
                                                <td className="border-b border-[#e4e9f1] px-4 py-3.5 font-medium text-[#14213d]">
                                                    {request.name}
                                                </td>
                                                <td className="border-b border-[#e4e9f1] px-4 py-3.5">
                                                    <Pill
                                                        tone={
                                                            request.priority ===
                                                            'Alta'
                                                                ? 'bg-red-100 text-red-600'
                                                                : 'bg-blue-100 text-blue-600'
                                                        }
                                                    >
                                                        {request.priority}
                                                    </Pill>
                                                </td>
                                                <td
                                                    className={`border-b border-[#e4e9f1] px-4 py-3.5 ${
                                                        request.priority ===
                                                        'Alta'
                                                            ? 'font-semibold text-red-600'
                                                            : 'text-[#14213d]'
                                                    }`}
                                                >
                                                    {request.due}
                                                </td>
                                                <td className="border-b border-[#e4e9f1] px-4 py-3.5 text-[#14213d]">
                                                    {request.owner}
                                                </td>
                                                <td className="border-b border-[#e4e9f1] px-4 py-3.5">
                                                    <Pill
                                                        tone={
                                                            request.status ===
                                                            'En revisión'
                                                                ? 'bg-blue-100 text-blue-600'
                                                                : 'bg-amber-100 text-amber-700'
                                                        }
                                                    >
                                                        {request.status}
                                                    </Pill>
                                                </td>
                                                <td className="border-b border-[#e4e9f1] px-4 py-3.5 text-center">
                                                    <button
                                                        type="button"
                                                        className="rounded-lg p-1.5 text-[#14213d] hover:bg-slate-100"
                                                    >
                                                        <MoreVertical className="size-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <div className="grid gap-5 xl:grid-cols-[1.05fr_0.95fr]">
                            <section className={`${card} overflow-hidden`}>
                                <header className="flex items-center justify-between px-5 py-4">
                                    <h2 className="flex items-center gap-3 text-lg font-extrabold">
                                        <CalendarDays className="size-5" />
                                        Próximos vencimientos
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-sm font-medium text-blue-700 underline"
                                    >
                                        Ver calendario
                                    </button>
                                </header>
                                <div className="px-5 pb-4">
                                    <table className="w-full border-separate border-spacing-0 text-left text-sm">
                                        <thead>
                                            <tr className="bg-[#eef2f7] text-xs text-[#566176]">
                                                <th className="rounded-l-lg px-4 py-3">
                                                    Obligación
                                                </th>
                                                <th className="px-4 py-3">
                                                    Período
                                                </th>
                                                <th className="px-4 py-3">
                                                    Fecha límite
                                                </th>
                                                <th className="rounded-r-lg px-4 py-3">
                                                    Estado
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {deadlines.map(
                                                ([
                                                    name,
                                                    period,
                                                    due,
                                                    status,
                                                ]) => (
                                                    <tr key={name}>
                                                        <td className="border-b border-[#e4e9f1] px-4 py-3 font-medium">
                                                            {name}
                                                        </td>
                                                        <td className="border-b border-[#e4e9f1] px-4 py-3">
                                                            {period}
                                                        </td>
                                                        <td className="border-b border-[#e4e9f1] px-4 py-3 font-semibold text-red-600">
                                                            {due}
                                                        </td>
                                                        <td className="border-b border-[#e4e9f1] px-4 py-3">
                                                            <Pill
                                                                tone={
                                                                    status ===
                                                                    'Programado'
                                                                        ? 'bg-emerald-100 text-emerald-700'
                                                                        : 'bg-blue-100 text-blue-600'
                                                                }
                                                            >
                                                                {status}
                                                            </Pill>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section className={`${card} overflow-hidden`}>
                                <header className="flex items-center justify-between px-5 py-4">
                                    <h2 className="flex items-center gap-3 text-lg font-extrabold">
                                        <BarChart3 className="size-5" />
                                        Reportes disponibles
                                    </h2>
                                    <button
                                        type="button"
                                        className="text-sm font-medium text-blue-700 underline"
                                    >
                                        Ver todos
                                    </button>
                                </header>
                                <div className="divide-y divide-[#e4e9f1] px-5 pb-4">
                                    {reports.map(([title, period]) => (
                                        <div
                                            key={title}
                                            className="flex items-center gap-4 py-3"
                                        >
                                            <FileText className="size-6 shrink-0 text-[#071029]" />
                                            <span className="min-w-0 flex-1">
                                                <strong className="block text-sm font-extrabold text-[#071029]">
                                                    {title}
                                                </strong>
                                                <small className="text-xs text-[#64748b]">
                                                    {period}
                                                </small>
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    action(
                                                        `${title} preparado para descargar.`,
                                                    )
                                                }
                                                className="rounded-lg border border-[#d7dee9] px-6 py-2 text-sm font-semibold text-[#071029] hover:bg-slate-50"
                                            >
                                                Descargar
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>
                    </div>

                    <aside className="space-y-5">
                        <section className={`${card} p-5`}>
                            <h2 className="flex items-center gap-3 text-lg font-extrabold">
                                <CloudUpload className="size-6" />
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
                                className="mt-5 grid min-h-[194px] w-full place-items-center rounded-2xl border-2 border-dashed border-[#cbd5e1] bg-[#fafcff] p-6 text-center transition hover:border-[#730009] hover:bg-white"
                            >
                                <span>
                                    <CloudUpload className="mx-auto size-12 text-[#536077]" />
                                    <strong className="mt-5 block text-base font-extrabold">
                                        Arrastra tus archivos aquí
                                    </strong>
                                    <span className="mt-1 block text-sm text-[#536077]">
                                        o haz clic para seleccionar
                                    </span>
                                    <small className="mt-5 block text-xs text-[#64748b]">
                                        PDF, XML, XLSX, PNG, JPG (máx. 20 MB)
                                    </small>
                                </span>
                            </button>
                            {uploaded.length > 0 && (
                                <div className="mt-3 space-y-1">
                                    {uploaded.slice(0, 3).map((file) => (
                                        <p
                                            key={file}
                                            className="truncate rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700"
                                        >
                                            {file}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </section>

                        <section className={`${card} p-5`}>
                            <header className="flex items-center justify-between">
                                <h2 className="flex items-center gap-3 text-lg font-extrabold">
                                    <TrendingUp className="size-5" />
                                    Actividad reciente
                                </h2>
                                <button
                                    type="button"
                                    className="text-sm font-medium text-blue-700 underline"
                                >
                                    Ver toda
                                </button>
                            </header>
                            <div className="mt-5 space-y-4 border-l border-[#dfe5ef] pl-5">
                                {activity.map(
                                    ([person, detail, time, Icon, tone]) => (
                                        <div
                                            key={`${person}-${detail}`}
                                            className="relative flex gap-3"
                                        >
                                            <span
                                                className={`absolute -left-[35px] grid size-8 place-items-center rounded-full ${tone}`}
                                            >
                                                <Icon className="size-4" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <strong className="block text-sm font-extrabold text-[#071029]">
                                                    {person}
                                                </strong>
                                                <small className="block text-xs leading-4 text-[#536077]">
                                                    {detail}
                                                </small>
                                            </span>
                                            <small className="shrink-0 text-xs text-[#7b879a]">
                                                {time}
                                            </small>
                                        </div>
                                    ),
                                )}
                            </div>
                        </section>

                        <section className={`${card} p-5`}>
                            <h2 className="flex items-center gap-3 text-lg font-extrabold">
                                <Headphones className="size-6" />
                                ¿Necesitas ayuda?
                            </h2>
                            <p className="mt-3 text-sm text-[#536077]">
                                Estamos para brindarte soporte en todo momento.
                            </p>
                            <div className="mt-5 grid grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    onClick={() =>
                                        action('Mensaje de soporte preparado.')
                                    }
                                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#730009] px-4 text-sm font-extrabold text-white shadow-[0_12px_24px_-18px_#730009] hover:bg-[#5d0007]"
                                >
                                    <Send className="size-4" />
                                    Enviar mensaje
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        action('Preguntas frecuentes abiertas.')
                                    }
                                    className="min-h-11 rounded-xl bg-[#f0f3f8] px-4 text-sm font-extrabold text-[#071029] hover:bg-[#e6ebf2]"
                                >
                                    Ver preguntas frecuentes
                                </button>
                            </div>
                        </section>
                    </aside>
                </div>
            </div>
        </main>
    );
}
