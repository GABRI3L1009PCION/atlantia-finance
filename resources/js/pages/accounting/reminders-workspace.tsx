import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    BarChart3,
    Building2,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Eye,
    FileText,
    Landmark,
    MoreHorizontal,
    Plus,
    Receipt,
    Settings2,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type Priority = 'Alta' | 'Media';
type ReminderStatus = 'Vencido' | 'Pendiente' | 'Próximo' | 'Completado';
type Reminder = {
    id: number;
    icon: LucideIcon;
    description: string;
    company: string;
    due: string;
    owner: string;
    priority: Priority;
    status: ReminderStatus;
    tone: string;
};

const reminders: Reminder[] = [
    {
        id: 1,
        icon: FileText,
        description: 'Declaración IVA (Agosto)',
        company: 'Demo Atlántia S.A.',
        due: '28 Sep 2026 11:00',
        owner: 'María Contreras',
        priority: 'Alta',
        status: 'Vencido',
        tone: 'text-red-600',
    },
    {
        id: 2,
        icon: Landmark,
        description: 'Envío de estados bancarios',
        company: 'Demo Atlántia S.A.',
        due: '28 Sep 2026 09:00',
        owner: 'Luis Ramírez',
        priority: 'Alta',
        status: 'Vencido',
        tone: 'text-blue-600',
    },
    {
        id: 3,
        icon: Banknote,
        description: 'Pago a proveedor Comercializadora Andina',
        company: 'Demo Atlántia S.A.',
        due: '28 Sep 2026 15:00',
        owner: 'Ana López',
        priority: 'Media',
        status: 'Pendiente',
        tone: 'text-amber-600',
    },
    {
        id: 4,
        icon: Users,
        description: 'Cierre de nómina',
        company: 'Demo Atlántia S.A.',
        due: '29 Sep 2026 10:00',
        owner: 'María Contreras',
        priority: 'Media',
        status: 'Próximo',
        tone: 'text-violet-600',
    },
    {
        id: 5,
        icon: BarChart3,
        description: 'Depreciación mensual',
        company: 'Demo Atlántia S.A.',
        due: '30 Sep 2026 09:00',
        owner: 'Carlos Mendoza',
        priority: 'Media',
        status: 'Próximo',
        tone: 'text-emerald-600',
    },
    {
        id: 6,
        icon: Receipt,
        description: 'Cobro vencido - Constructora Sur',
        company: 'Demo Atlántia S.A.',
        due: '30 Sep 2026 16:00',
        owner: 'Ana López',
        priority: 'Alta',
        status: 'Pendiente',
        tone: 'text-orange-600',
    },
    {
        id: 7,
        icon: FileText,
        description: 'Revisión de facturas pendientes',
        company: 'Demo Atlántia S.A.',
        due: '30 Sep 2026 11:00',
        owner: 'Sofía Herrera',
        priority: 'Media',
        status: 'Pendiente',
        tone: 'text-amber-600',
    },
    {
        id: 8,
        icon: BarChart3,
        description: 'Presentación de estados financieros',
        company: 'Demo Atlántia S.A.',
        due: '1 Oct 2026 10:00',
        owner: 'María Contreras',
        priority: 'Alta',
        status: 'Próximo',
        tone: 'text-blue-600',
    },
];

const statusStyle: Record<ReminderStatus, string> = {
    Vencido: 'bg-red-100 text-red-700',
    Pendiente: 'bg-amber-100 text-amber-700',
    Próximo: 'bg-blue-100 text-blue-700',
    Completado: 'bg-emerald-100 text-emerald-700',
};
const priorityStyle: Record<Priority, string> = {
    Alta: 'bg-red-50 text-red-600',
    Media: 'bg-amber-50 text-amber-700',
};
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    change,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
    change?: string;
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
                    {change && (
                        <span className="pb-1 text-xs font-semibold text-emerald-600">
                            {change}
                        </span>
                    )}
                </div>
                <p className="text-[11px] text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

const agenda = [
    {
        day: 'Lun',
        date: '28 Sep',
        items: [
            [
                '09:00',
                'Envío de estados bancarios',
                'bg-blue-50 border-blue-400',
                Landmark,
            ],
            [
                '11:00',
                'Declaración IVA (Agosto)',
                'bg-red-50 border-red-400',
                FileText,
            ],
            [
                '15:00',
                'Pago a proveedor',
                'bg-amber-50 border-amber-400',
                Banknote,
            ],
        ],
    },
    {
        day: 'Mar',
        date: '29 Sep',
        items: [
            [
                '10:00',
                'Cierre de nómina Septiembre',
                'bg-violet-50 border-violet-400',
                Users,
            ],
            [
                '14:00',
                'Conciliación bancaria',
                'bg-blue-50 border-blue-400',
                Landmark,
            ],
        ],
    },
    {
        day: 'Mié',
        date: '30 Sep',
        items: [
            [
                '09:00',
                'Depreciación mensual',
                'bg-emerald-50 border-emerald-400',
                BarChart3,
            ],
            [
                '11:00',
                'Revisión de facturas pendientes',
                'bg-amber-50 border-amber-400',
                FileText,
            ],
            ['16:00', 'Cobro vencido', 'bg-red-50 border-red-400', Receipt],
        ],
    },
    {
        day: 'Jue',
        date: '1 Oct',
        items: [
            [
                '10:00',
                'Presentación de estados financieros',
                'bg-blue-50 border-blue-400',
                BarChart3,
            ],
            [
                '15:00',
                'Declaración retenciones',
                'bg-red-50 border-red-400',
                FileText,
            ],
        ],
    },
    {
        day: 'Vie',
        date: '2 Oct',
        items: [
            [
                '09:00',
                'Reunión con auditoría interna',
                'bg-amber-50 border-amber-400',
                Users,
            ],
            [
                '14:00',
                'Actualización de documentos FEE',
                'bg-blue-50 border-blue-400',
                FileText,
            ],
        ],
    },
] as const;

export default function RemindersWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [week, setWeek] = useState(0);
    const [priority, setPriority] = useState('Todas');
    const [status, setStatus] = useState('Todos');
    const [selected, setSelected] = useState<number[]>([]);
    const [completed, setCompleted] = useState<number[]>([]);
    const [notice, setNotice] = useState('');

    const filtered = useMemo(
        () =>
            reminders.filter(
                (item) =>
                    (priority === 'Todas' || item.priority === priority) &&
                    (status === 'Todos' || item.status === status),
            ),
        [priority, status],
    );
    const showNotice = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3400);
    };
    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((item) => item !== id)
                : [...current, id],
        );

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Recordatorios · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Recordatorios
                        </p>
                        <h1 className="text-3xl font-bold text-[#74000a]">
                            Recordatorios
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Seguimiento de vencimientos, tareas y alertas
                            contables
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
                        icon={CalendarDays}
                        label="Hoy"
                        value="8"
                        detail="recordatorios"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={CalendarDays}
                        label="Próximos 7 días"
                        value="24"
                        detail="vs. semana anterior"
                        change="↑ 20%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={AlertTriangle}
                        label="Vencidos"
                        value="5"
                        detail="vs. semana anterior"
                        change="↑ 67%"
                        tone="bg-red-50 text-red-700"
                    />
                    <Metric
                        icon={CheckCircle2}
                        label="Completados"
                        value="18"
                        detail="vs. semana anterior"
                        change="↑ 12%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                </section>
                <section
                    className={`${panel} grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_.7fr_1fr_1fr_1fr_auto_auto]`}
                >
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todas las empresas
                        <ChevronDown className="size-4" />
                    </button>
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todas las categorías
                        <ChevronDown className="size-4" />
                    </button>
                    <select
                        value={priority}
                        onChange={(event) => setPriority(event.target.value)}
                        className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                    >
                        <option>Todas</option>
                        <option>Alta</option>
                        <option>Media</option>
                    </select>
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todos los responsables
                        <ChevronDown className="size-4" />
                    </button>
                    <button
                        type="button"
                        className="flex items-center gap-2 rounded-lg border px-3 py-2.5 text-xs"
                    >
                        <CalendarDays className="size-4" />
                        Septiembre 2026
                    </button>
                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                    >
                        <option>Todos</option>
                        <option>Vencido</option>
                        <option>Pendiente</option>
                        <option>Próximo</option>
                    </select>
                    <button
                        type="button"
                        onClick={() =>
                            showNotice(
                                'Reglas automáticas listas para configurar.',
                            )
                        }
                        className="flex items-center justify-center gap-2 rounded-lg border border-[#9b1923] px-4 py-2.5 text-xs font-semibold text-[#74000a]"
                    >
                        <Settings2 className="size-4" />
                        Reglas automáticas
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            showNotice(
                                'Formulario de nuevo recordatorio preparado.',
                            )
                        }
                        className="flex items-center justify-center gap-2 rounded-lg bg-[#74000a] px-5 py-2.5 text-xs font-semibold text-white"
                    >
                        <Plus className="size-4" />
                        Nuevo recordatorio
                    </button>
                </section>
                <section className="grid items-stretch gap-4 2xl:grid-cols-[minmax(0,1.45fr)_minmax(330px,.8fr)]">
                    <article className={`${panel} overflow-hidden`}>
                        <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                            <h2 className="flex items-center gap-2 font-bold">
                                <CalendarDays className="size-5 text-[#74000a]" />
                                Agenda de recordatorios
                            </h2>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setWeek(week - 1)}
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <strong className="min-w-36 text-center text-xs">
                                    {week === 0
                                        ? '28 Sep – 4 Oct 2026'
                                        : week > 0
                                          ? '5 – 11 Oct 2026'
                                          : '21 – 27 Sep 2026'}
                                </strong>
                                <button
                                    type="button"
                                    onClick={() => setWeek(week + 1)}
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        </header>
                        <div className="overflow-x-auto">
                            <div className="grid min-w-[820px] grid-cols-5 divide-x">
                                {agenda.map((day) => (
                                    <section
                                        key={day.day}
                                        className={`${day.day === 'Mié' ? 'bg-rose-50/30' : ''}`}
                                    >
                                        <header className="border-b p-2 text-center">
                                            <strong className="block text-xs">
                                                {day.day}
                                            </strong>
                                            <span className="text-[11px] text-slate-500">
                                                {day.date}
                                            </span>
                                        </header>
                                        <div className="min-h-56 space-y-2 p-2">
                                            {week === 0 ? (
                                                day.items.map(
                                                    ([
                                                        time,
                                                        title,
                                                        tone,
                                                        Icon,
                                                    ]) => (
                                                        <button
                                                            type="button"
                                                            key={`${time}${title}`}
                                                            onClick={() =>
                                                                showNotice(
                                                                    `Recordatorio abierto: ${title}.`,
                                                                )
                                                            }
                                                            className={`flex w-full gap-2 rounded-md border-l-2 p-2 text-left ${tone}`}
                                                        >
                                                            <Icon className="mt-0.5 size-4 shrink-0" />
                                                            <span>
                                                                <small className="block text-[10px]">
                                                                    {time}
                                                                </small>
                                                                <span className="block text-[11px] leading-tight font-medium">
                                                                    {title}
                                                                </span>
                                                            </span>
                                                        </button>
                                                    ),
                                                )
                                            ) : (
                                                <div className="grid h-40 place-items-center text-xs text-slate-400">
                                                    Sin recordatorios
                                                </div>
                                            )}
                                        </div>
                                        <footer className="border-t p-2 text-center text-[10px] text-slate-500">
                                            {week === 0
                                                ? `${day.items.length} recordatorios`
                                                : '0 recordatorios'}
                                        </footer>
                                    </section>
                                ))}
                            </div>
                        </div>
                    </article>
                    <article className={`${panel} p-5`}>
                        <header className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 font-bold">
                                <Clock3 className="size-5 text-[#74000a]" />
                                Resumen y prioridades
                            </h2>
                            <button
                                type="button"
                                className="text-xs text-[#74000a]"
                            >
                                Ver todos
                                <ChevronRight className="ml-1 inline size-4" />
                            </button>
                        </header>
                        <h3 className="mt-4 text-sm font-bold">
                            Recordatorios críticos
                        </h3>
                        <div className="mt-2 divide-y">
                            {reminders.slice(0, 6).map((item) => {
                                const Icon = item.icon;

                                return (
                                    <button
                                        type="button"
                                        key={item.id}
                                        onClick={() =>
                                            showNotice(
                                                `Detalle de ${item.description}.`,
                                            )
                                        }
                                        className="flex w-full items-center gap-3 py-2 text-left"
                                    >
                                        <span
                                            className={`grid size-8 shrink-0 place-items-center rounded-lg bg-slate-50 ${item.tone}`}
                                        >
                                            <Icon className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <strong className="block truncate text-xs">
                                                {item.description}
                                            </strong>
                                            <small className="block truncate text-[10px] text-slate-500">
                                                {item.id === 1
                                                    ? 'Presentar declaración y realizar pago'
                                                    : item.company}
                                            </small>
                                        </span>
                                        <span className="rounded bg-rose-50 px-2 py-1 text-[10px] text-red-600">
                                            {item.due.includes('28 Sep')
                                                ? 'Hoy'
                                                : item.due
                                                      .split(' ')
                                                      .slice(0, 3)
                                                      .join(' ')}
                                        </span>
                                        <span
                                            className={`rounded-full px-2.5 py-1 text-[10px] ${priorityStyle[item.priority]}`}
                                        >
                                            {item.priority}
                                        </span>
                                        <ChevronRight className="size-4 text-slate-400" />
                                    </button>
                                );
                            })}
                        </div>
                    </article>
                </section>
                <article className={`${panel} overflow-hidden`}>
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                        <h2 className="flex items-center gap-2 font-bold">
                            <FileText className="size-5 text-[#74000a]" />
                            Lista de recordatorios
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    if (!selected.length) {
                                        showNotice(
                                            'Selecciona al menos un recordatorio.',
                                        );

                                        return;
                                    }

                                    setCompleted((current) => [
                                        ...new Set([...current, ...selected]),
                                    ]);
                                    showNotice(
                                        `${selected.length} recordatorio(s) marcados como completados.`,
                                    );
                                    setSelected([]);
                                }}
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Check className="size-4" />
                                Marcar completado
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        selected.length
                                            ? `${selected.length} recordatorio(s) pospuestos.`
                                            : 'Selecciona los recordatorios que deseas posponer.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Clock3 className="size-4" />
                                Posponer
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        selected.length
                                            ? `Mostrando ${selected.length} recordatorio(s).`
                                            : 'Selecciona un recordatorio para ver su detalle.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Eye className="size-4" />
                                Ver detalle
                            </button>
                        </div>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1000px] text-left text-xs">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            aria-label="Seleccionar todos"
                                            checked={
                                                selected.length ===
                                                    filtered.length &&
                                                filtered.length > 0
                                            }
                                            onChange={() =>
                                                setSelected(
                                                    selected.length ===
                                                        filtered.length
                                                        ? []
                                                        : filtered.map(
                                                              (item) => item.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </th>
                                    <th>Tipo</th>
                                    <th>Descripción</th>
                                    <th>Empresa</th>
                                    <th>Fecha límite</th>
                                    <th>Responsable</th>
                                    <th>Prioridad</th>
                                    <th>Estado</th>
                                    <th className="pr-4 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filtered.map((item) => {
                                    const Icon = item.icon;
                                    const effectiveStatus: ReminderStatus =
                                        completed.includes(item.id)
                                            ? 'Completado'
                                            : item.status;

                                    return (
                                        <tr
                                            key={item.id}
                                            className={
                                                selected.includes(item.id)
                                                    ? 'bg-rose-50'
                                                    : ''
                                            }
                                        >
                                            <td className="px-4 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Seleccionar ${item.description}`}
                                                    checked={selected.includes(
                                                        item.id,
                                                    )}
                                                    onChange={() =>
                                                        toggle(item.id)
                                                    }
                                                />
                                            </td>
                                            <td>
                                                <Icon
                                                    className={`size-4 ${item.tone}`}
                                                />
                                            </td>
                                            <td className="font-medium">
                                                {item.description}
                                            </td>
                                            <td>{item.company}</td>
                                            <td
                                                className={
                                                    effectiveStatus ===
                                                    'Vencido'
                                                        ? 'font-semibold text-red-600'
                                                        : ''
                                                }
                                            >
                                                {item.due}
                                            </td>
                                            <td>{item.owner}</td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-2.5 py-1 font-semibold ${priorityStyle[item.priority]}`}
                                                >
                                                    {item.priority}
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    className={`inline-flex min-w-24 items-center justify-center gap-1 rounded-full px-3 py-1 font-semibold ${statusStyle[effectiveStatus]}`}
                                                >
                                                    <CheckCircle2 className="size-3" />
                                                    {effectiveStatus}
                                                </span>
                                            </td>
                                            <td className="pr-4 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        showNotice(
                                                            `Opciones abiertas para ${item.description}.`,
                                                        )
                                                    }
                                                    aria-label={`Opciones de ${item.description}`}
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </main>
    );
}
