import { Head, router, useForm } from '@inertiajs/react';
import {
    Award,
    BookOpenCheck,
    CalendarDays,
    Clock3,
    ClipboardList,
    Download,
    FileText,
    Globe2,
    GraduationCap,
    Info,
    Monitor,
    Plus,
    Search,
    Star,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { CertificatePreview } from '@/components/certificate';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Course = {
    id: number;
    title: string;
    area: string;
    modality: string;
    starts_on: string | null;
    start_time: string | null;
    duration_hours: number | null;
    session_url: string | null;
    capacity: number | null;
    status: string;
    enrolled: number;
    completed: number;
    satisfaction: number | null;
    last_completion: string | null;
};

type Certification = {
    id: number;
    name: string;
    issuer: string | null;
    user: string;
    issued_on: string;
    expires_on: string | null;
    state: 'valid' | 'expiring' | 'expired';
    code: string | null;
    generated: boolean;
    has_file: boolean;
};

type Need = {
    id: number;
    area: string;
    topic: string;
    priority: 'high' | 'medium' | 'low';
    employees: number;
    status: 'pending' | 'planned' | 'done';
    objective: string | null;
    target_date: string | null;
    participants: string[];
};

type Props = {
    summary: {
        staff: number;
        courses_completed: number;
        certifications: number;
        progress: number;
        satisfaction: number | null;
    };
    trend: { label: string; value: number }[];
    areas: { name: string; value: number }[];
    certification_status: { valid: number; expiring: number; expired: number };
    courses: Course[];
    certifications: Certification[];
    employees: {
        id: number;
        name: string;
        assigned: number;
        completed: number;
        progress: number;
    }[];
    needs: Need[];
    staff: { id: number; name: string }[];
    canManage: boolean;
    areaOptions: string[];
    next_certificate_code: string;
    firm_name: string;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const field =
    'h-10 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const button =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#8a0b0b] px-4 text-sm font-bold text-white disabled:opacity-60';
const PALETTE = [
    '#2563eb',
    '#16a34a',
    '#f0a93b',
    '#7c3aed',
    '#ee6c3a',
    '#0ea5e9',
    '#94a3b8',
];
const COURSE_STATUS: Record<string, { label: string; style: string }> = {
    scheduled: { label: 'Programado', style: 'bg-blue-50 text-blue-700' },
    open: {
        label: 'Inscripciones abiertas',
        style: 'bg-emerald-50 text-emerald-700',
    },
    in_progress: { label: 'En curso', style: 'bg-amber-50 text-amber-700' },
    completed: { label: 'Completado', style: 'bg-slate-100 text-slate-600' },
    cancelled: { label: 'Cancelado', style: 'bg-red-50 text-red-700' },
};
const PRIORITY = {
    high: { label: 'Alta', style: 'bg-red-50 text-red-700' },
    medium: { label: 'Media', style: 'bg-amber-50 text-amber-700' },
    low: { label: 'Baja', style: 'bg-emerald-50 text-emerald-700' },
} as const;
const NEED_STATUS = {
    pending: 'Pendiente',
    planned: 'En planificación',
    done: 'Atendida',
} as const;
const TABS = ['Resumen', 'Cursos', 'Certificaciones', 'Necesidades'] as const;

function formatDate(value: string | null): string {
    return value
        ? new Date(`${value}T12:00:00`).toLocaleDateString('es-GT', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}

function Kpi({
    icon,
    tone,
    value,
    label,
    hint,
}: {
    icon: ReactNode;
    tone: string;
    value: string;
    label: string;
    hint?: string;
}) {
    return (
        <div className={`${card} flex items-center gap-4 !p-4`}>
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p className="truncate text-sm font-bold">{label}</p>
                {hint && (
                    <p className="truncate text-xs text-slate-500">{hint}</p>
                )}
            </div>
        </div>
    );
}

function Donut({
    rows,
    center,
}: {
    rows: { name: string; value: number; color?: string }[];
    center: string;
}) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    const radius = 50;
    const circumference = 2 * Math.PI * radius;
    const lengths = rows.map((row) =>
        total > 0 ? (row.value / total) * circumference : 0,
    );

    return (
        <div className="flex items-center gap-4">
            <svg viewBox="0 0 130 130" className="size-36 shrink-0">
                <circle
                    cx="65"
                    cy="65"
                    r={radius}
                    fill="none"
                    stroke="#eef1f3"
                    strokeWidth="16"
                />
                {rows.map((row, i) => (
                    <circle
                        key={row.name}
                        cx="65"
                        cy="65"
                        r={radius}
                        fill="none"
                        stroke={row.color ?? PALETTE[i % PALETTE.length]}
                        strokeWidth="16"
                        strokeDasharray={`${lengths[i]} ${circumference - lengths[i]}`}
                        strokeDashoffset={
                            -lengths.slice(0, i).reduce((s, v) => s + v, 0)
                        }
                        transform="rotate(-90 65 65)"
                    />
                ))}
                <text
                    x="65"
                    y="64"
                    textAnchor="middle"
                    className="fill-[#061633] text-[22px] font-black"
                >
                    {total}
                </text>
                <text
                    x="65"
                    y="80"
                    textAnchor="middle"
                    className="fill-slate-500 text-[10px]"
                >
                    {center}
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                {rows.map((row, i) => (
                    <li key={row.name} className="flex items-center gap-2">
                        <i
                            className="size-2.5 shrink-0 rounded-full"
                            style={{
                                background:
                                    row.color ?? PALETTE[i % PALETTE.length],
                            }}
                        />
                        <span className="flex-1 truncate">{row.name}</span>
                        <b>{row.value}</b>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Trend({ points }: { points: Props['trend'] }) {
    const width = 520;
    const height = 190;
    const left = 34;
    const slot = (width - left) / Math.max(points.length, 1);
    const y = (value: number) =>
        10 + (height - 40) * (1 - Math.min(100, value) / 100);
    const line = points.map(
        (p, i) => `${left + slot * i + slot / 2},${y(p.value)}`,
    );

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 50, 100].map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width}
                        y1={y(tick)}
                        y2={y(tick)}
                        stroke="#eee7e3"
                    />
                    <text
                        x={left - 6}
                        y={y(tick)}
                        textAnchor="end"
                        dominantBaseline="central"
                        className="fill-slate-400 text-[10px]"
                    >
                        {tick}%
                    </text>
                </g>
            ))}
            <polygon
                points={`${left + slot / 2},${y(0)} ${line.join(' ')} ${left + slot * (points.length - 1) + slot / 2},${y(0)}`}
                fill="#8a0b0b"
                opacity="0.07"
            />
            <polyline
                points={line.join(' ')}
                fill="none"
                stroke="#8a0b0b"
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            {points.map((p, i) => (
                <g key={p.label + i}>
                    <circle
                        cx={left + slot * i + slot / 2}
                        cy={y(p.value)}
                        r="3.5"
                        fill="#fff"
                        stroke="#8a0b0b"
                        strokeWidth="2"
                    />
                    <text
                        x={left + slot * i + slot / 2}
                        y={y(p.value) - 9}
                        textAnchor="middle"
                        className="fill-[#6b1d1d] text-[10px] font-bold"
                    >
                        {p.value}%
                    </text>
                    <text
                        x={left + slot * i + slot / 2}
                        y={height - 8}
                        textAnchor="middle"
                        className="fill-slate-500 text-[10px]"
                    >
                        {p.label}
                    </text>
                </g>
            ))}
        </svg>
    );
}

type DialogKind = 'course' | 'enroll' | 'cert' | 'need';

const DIALOG_COPY: Record<DialogKind, { title: string; description: string }> =
    {
        course: {
            title: 'Registrar curso',
            description:
                'Organiza una capacitación para el equipo de tu firma.',
        },
        enroll: {
            title: 'Inscribir participantes',
            description: 'Elige el curso y las personas que lo tomarán.',
        },
        cert: {
            title: 'Registrar certificación',
            description:
                'Completa los datos y revisa el certificado antes de guardarlo.',
        },
        need: {
            title: 'Registrar necesidad de capacitación',
            description:
                'Identifica qué necesita aprender el equipo y quiénes deben participar.',
        },
    };

function useSubmit(onDone: () => void) {
    return (
            form: {
                post: (url: string, options: object) => void;
                reset: () => void;
            },
            url: string,
        ) =>
        (event: FormEvent) => {
            event.preventDefault();
            form.post(url, {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onDone();
                },
            });
        };
}

const COURSE_STATUS_OPTIONS: Record<string, string> = {
    scheduled: 'Programado',
    open: 'Inscripciones abiertas',
    in_progress: 'En curso',
    completed: 'Completado',
};

const COURSE_MODALITY = [
    { value: 'virtual', label: 'Virtual', icon: Monitor },
    { value: 'presencial', label: 'Presencial', icon: Users },
    { value: 'híbrido', label: 'Híbrida', icon: Globe2 },
];

function CourseForm({
    areaOptions,
    onDone,
    onCancel,
}: {
    areaOptions: string[];
    onDone: () => void;
    onCancel: () => void;
}) {
    const course = useForm({
        title: '',
        area: areaOptions[0] ?? '',
        modality: 'virtual',
        starts_on: '',
        start_time: '09:00',
        duration_hours: '2',
        capacity: '20',
        session_url: '',
        status: 'scheduled',
        description: '',
    });
    const submit = useSubmit(onDone);
    const label = 'block text-sm font-semibold text-[#061633]';
    const required = <span className="text-red-600"> *</span>;
    const modality =
        COURSE_MODALITY.find((item) => item.value === course.data.modality) ??
        COURSE_MODALITY[0];
    const ModalityIcon = modality.icon;
    const dateLabel = course.data.starts_on
        ? new Date(`${course.data.starts_on}T12:00:00`).toLocaleDateString(
              'es-GT',
              { day: 'numeric', month: 'long', year: 'numeric' },
          )
        : 'Fecha por definir';
    const hours = Number(course.data.duration_hours);
    const seats = Number(course.data.capacity);
    const statusStyle: Record<string, string> = {
        scheduled: 'bg-emerald-50 text-emerald-700',
        open: 'bg-blue-50 text-blue-700',
        in_progress: 'bg-amber-50 text-amber-700',
        completed: 'bg-slate-100 text-slate-600',
    };

    return (
        <form
            onSubmit={submit(course, '/office/training/courses')}
            className="space-y-4"
        >
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
                <div className="space-y-4">
                    <h3 className="flex items-center gap-3 text-lg font-bold text-[#061633]">
                        Información del curso
                        <span className="h-px flex-1 bg-slate-200" />
                    </h3>
                    <label className={label}>
                        Nombre del curso{required}
                        <input
                            className={`${field} mt-1`}
                            placeholder="Ej. Actualización fiscal y declaración de ISR"
                            value={course.data.title}
                            onChange={(e) =>
                                course.setData('title', e.target.value)
                            }
                            maxLength={160}
                            required
                        />
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className={label}>
                            Área{required}
                            <select
                                className={`${field} mt-1`}
                                value={course.data.area}
                                onChange={(e) =>
                                    course.setData('area', e.target.value)
                                }
                            >
                                {areaOptions.map((area) => (
                                    <option key={area}>{area}</option>
                                ))}
                            </select>
                        </label>
                        <label className={label}>
                            Estado{required}
                            <select
                                className={`${field} mt-1`}
                                value={course.data.status}
                                onChange={(e) =>
                                    course.setData('status', e.target.value)
                                }
                            >
                                {Object.entries(COURSE_STATUS_OPTIONS).map(
                                    ([value, text]) => (
                                        <option key={value} value={value}>
                                            {text}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                    </div>
                    <div className={label}>
                        Modalidad{required}
                        <div
                            className="mt-1 grid grid-cols-3 gap-3"
                            role="radiogroup"
                            aria-label="Modalidad"
                        >
                            {COURSE_MODALITY.map((item) => {
                                const Icon = item.icon;
                                const active =
                                    course.data.modality === item.value;

                                return (
                                    <button
                                        key={item.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={active}
                                        onClick={() =>
                                            course.setData(
                                                'modality',
                                                item.value,
                                            )
                                        }
                                        className={`inline-flex h-12 items-center justify-center gap-2 rounded-xl border text-sm font-semibold transition ${active ? 'border-[#640000] bg-[#7a0a0a] text-white shadow' : 'border-slate-300 bg-white text-slate-700 hover:border-[#c9a36a]'}`}
                                    >
                                        <Icon className="size-4" /> {item.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <h3 className="flex items-center gap-3 pt-2 text-lg font-bold text-[#061633]">
                        Programación y acceso
                        <span className="h-px flex-1 bg-slate-200" />
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className={label}>
                            Fecha{required}
                            <input
                                className={`${field} mt-1`}
                                type="date"
                                min={new Date().toLocaleDateString('en-CA')}
                                value={course.data.starts_on}
                                onChange={(e) =>
                                    course.setData('starts_on', e.target.value)
                                }
                                required
                            />
                        </label>
                        <label className={label}>
                            Hora de inicio
                            <input
                                className={`${field} mt-1`}
                                type="time"
                                value={course.data.start_time}
                                onChange={(e) =>
                                    course.setData('start_time', e.target.value)
                                }
                            />
                        </label>
                        <label className={label}>
                            Duración (horas)
                            <input
                                className={`${field} mt-1`}
                                type="number"
                                min="0.5"
                                step="0.5"
                                value={course.data.duration_hours}
                                onChange={(e) =>
                                    course.setData(
                                        'duration_hours',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className={label}>
                            Cupo máximo{required}
                            <input
                                className={`${field} mt-1`}
                                type="number"
                                min="1"
                                value={course.data.capacity}
                                onChange={(e) =>
                                    course.setData('capacity', e.target.value)
                                }
                                required
                            />
                        </label>
                    </div>
                    <label className={label}>
                        Enlace de la sesión
                        <input
                            className={`${field} mt-1`}
                            type="url"
                            placeholder="https://…"
                            value={course.data.session_url}
                            onChange={(e) =>
                                course.setData('session_url', e.target.value)
                            }
                        />
                        <span className="text-xs font-normal text-slate-500">
                            Disponible para los participantes inscritos.
                        </span>
                        {course.errors.session_url && (
                            <span className="block text-xs font-normal text-red-600">
                                {course.errors.session_url}
                            </span>
                        )}
                    </label>
                    <label className={label}>
                        Descripción{' '}
                        <span className="font-normal text-slate-500">
                            (opcional)
                        </span>
                        <textarea
                            className={`${field} mt-1 h-20 py-2`}
                            value={course.data.description}
                            onChange={(e) =>
                                course.setData('description', e.target.value)
                            }
                            maxLength={2000}
                        />
                    </label>
                </div>

                <aside className="h-fit rounded-2xl bg-slate-50 p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-lg font-bold text-[#061633]">
                            Así se verá el curso
                        </h3>
                        <span className="rounded-lg bg-slate-200 px-3 py-1 text-xs font-medium text-slate-700">
                            Vista previa
                        </span>
                    </div>
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="grid h-36 place-items-center bg-[radial-gradient(circle_at_30%_20%,#a52a3b,#6b0f1c_70%)]">
                            <GraduationCap className="size-16 text-white/80" />
                        </div>
                        <div className="space-y-3 p-4">
                            <span className="inline-block rounded-md bg-[#fbe9e9] px-3 py-1 text-xs font-semibold text-[#8a0b0b]">
                                {course.data.area}
                            </span>
                            <p className="text-xl leading-tight font-bold break-words text-[#061633]">
                                {course.data.title || 'Nombre del curso'}
                            </p>
                            <span
                                className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ${statusStyle[course.data.status] ?? ''}`}
                            >
                                <span className="size-2 rounded-full bg-current" />
                                {COURSE_STATUS_OPTIONS[course.data.status]}
                            </span>
                            <ul className="space-y-2 text-sm text-slate-700">
                                <li className="flex items-center gap-3">
                                    <ModalityIcon className="size-4 text-slate-500" />
                                    {modality.label}
                                </li>
                                <li className="flex items-center gap-3">
                                    <CalendarDays className="size-4 text-slate-500" />
                                    {dateLabel}
                                </li>
                                <li className="flex items-center gap-3">
                                    <Clock3 className="size-4 text-slate-500" />
                                    {course.data.start_time || '—'}
                                    {hours > 0 &&
                                        ` · ${hours} ${hours === 1 ? 'hora' : 'horas'}`}
                                </li>
                                <li className="flex items-center gap-3">
                                    <Users className="size-4 text-slate-500" />
                                    {seats > 0
                                        ? `${seats} cupos disponibles`
                                        : 'Sin cupo definido'}
                                </li>
                            </ul>
                            <div className="rounded-lg border border-[#8a0b0b] py-2 text-center text-sm font-bold text-[#8a0b0b]">
                                Ver detalles
                            </div>
                        </div>
                    </div>
                    <p className="mt-3 flex items-center gap-2 text-xs text-slate-500">
                        <Info className="size-4 shrink-0" /> La vista previa se
                        actualiza al completar el formulario.
                    </p>
                </aside>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <p className="text-xs text-slate-500">
                    Podrás gestionar las inscripciones después de guardar.
                </p>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="inline-flex h-11 items-center rounded-xl border border-[#8a0b0b] px-6 text-sm font-bold text-[#8a0b0b]"
                    >
                        Cancelar
                    </button>
                    <button className={button} disabled={course.processing}>
                        <Plus className="size-4" /> Guardar curso
                    </button>
                </div>
            </div>
        </form>
    );
}

function EnrollForm({
    courses,
    staff,
    onDone,
}: Pick<Props, 'courses' | 'staff'> & { onDone: () => void }) {
    const enroll = useForm({ course: '', user_ids: [] as string[] });
    const submit = useSubmit(onDone);
    const [filter, setFilter] = useState('');
    const selected = new Set(enroll.data.user_ids);
    const shown = staff.filter((person) =>
        person.name.toLowerCase().includes(filter.trim().toLowerCase()),
    );
    const allShown =
        shown.length > 0 &&
        shown.every((person) => selected.has(String(person.id)));
    const toggle = (id: string) => {
        const next = new Set(selected);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        enroll.setData('user_ids', Array.from(next));
    };
    const toggleAll = () => {
        const next = new Set(selected);

        shown.forEach((person) => {
            if (allShown) {
                next.delete(String(person.id));
            } else {
                next.add(String(person.id));
            }
        });

        enroll.setData('user_ids', Array.from(next));
    };

    return (
        <form
            onSubmit={submit(
                enroll as never,
                `/office/training/courses/${enroll.data.course || 0}/enroll`,
            )}
            className="space-y-3"
        >
            <select
                className={field}
                value={enroll.data.course}
                onChange={(e) => enroll.setData('course', e.target.value)}
                required
            >
                <option value="">Selecciona un curso</option>
                {courses.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.title}
                    </option>
                ))}
            </select>
            <div className="space-y-2">
                <input
                    className={field}
                    type="search"
                    placeholder="Buscar persona…"
                    aria-label="Buscar persona"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                />
                <div className="flex items-center justify-between text-xs">
                    <label className="flex cursor-pointer items-center gap-2 font-bold text-[#061633]">
                        <input
                            type="checkbox"
                            className="size-4 accent-[#640000]"
                            checked={allShown}
                            onChange={toggleAll}
                        />
                        Seleccionar todos
                        {filter.trim() !== '' && ' los visibles'}
                    </label>
                    <span className="font-semibold text-[#640000]">
                        {selected.size} seleccionada
                        {selected.size === 1 ? '' : 's'}
                    </span>
                </div>
                <ul className="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-300 bg-white">
                    {shown.map((person) => (
                        <li key={person.id}>
                            <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-amber-50">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[#640000]"
                                    checked={selected.has(String(person.id))}
                                    onChange={() => toggle(String(person.id))}
                                />
                                {person.name}
                            </label>
                        </li>
                    ))}
                    {shown.length === 0 && (
                        <li className="px-3 py-4 text-center text-sm text-slate-500">
                            Nadie coincide con la búsqueda.
                        </li>
                    )}
                </ul>
            </div>
            <button
                className={button}
                disabled={enroll.processing || selected.size === 0}
            >
                <Users className="size-4" /> Inscribir
            </button>
        </form>
    );
}

function CertificationForm({
    staff,
    nextCode,
    firmName,
    onDone,
    onCancel,
}: Pick<Props, 'staff'> & {
    nextCode: string;
    firmName: string;
    onDone: () => void;
    onCancel: () => void;
}) {
    const cert = useForm({
        user_id: '',
        name: '',
        issuer: firmName,
        issued_on: new Date().toLocaleDateString('en-CA'),
        expires_on: '',
        code: nextCode,
        attachment: null as File | null,
        generated: true,
    });
    const [noExpiry, setNoExpiry] = useState(true);
    const [enlarged, setEnlarged] = useState(false);
    const submit = useSubmit(onDone);
    const person = staff.find((item) => String(item.id) === cert.data.user_id);
    const preview = {
        person: person?.name ?? '',
        course: cert.data.name,
        issuer: cert.data.issuer || firmName,
        code: cert.data.code || null,
        issued_on: cert.data.issued_on,
        firm: firmName,
    };
    const label = 'block text-sm font-medium text-slate-600';

    return (
        <form
            onSubmit={submit(cert, '/office/training/certifications')}
            className="space-y-4"
        >
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
                <div className="space-y-3">
                    <h3 className="text-lg font-bold text-[#061633]">
                        Datos de la certificación
                    </h3>
                    <label className={label}>
                        Colaborador
                        <select
                            className={`${field} mt-1`}
                            value={cert.data.user_id}
                            onChange={(e) =>
                                cert.setData('user_id', e.target.value)
                            }
                            required
                        >
                            <option value="">Selecciona un colaborador</option>
                            {staff.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={label}>
                        Curso / certificación
                        <input
                            className={`${field} mt-1`}
                            placeholder="Ej. Contabilidad y cumplimiento fiscal"
                            value={cert.data.name}
                            onChange={(e) =>
                                cert.setData('name', e.target.value)
                            }
                            required
                        />
                    </label>
                    <label className={label}>
                        Entidad emisora
                        <input
                            className={`${field} mt-1`}
                            value={cert.data.issuer}
                            onChange={(e) =>
                                cert.setData('issuer', e.target.value)
                            }
                        />
                    </label>
                    <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                        <label className={label}>
                            Fecha de emisión
                            <input
                                className={`${field} mt-1`}
                                type="date"
                                value={cert.data.issued_on}
                                onChange={(e) =>
                                    cert.setData('issued_on', e.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="flex h-10 items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                className="size-4 accent-[#640000]"
                                checked={noExpiry}
                                onChange={(e) => {
                                    setNoExpiry(e.target.checked);

                                    if (e.target.checked) {
                                        cert.setData('expires_on', '');
                                    }
                                }}
                            />
                            Sin vencimiento
                        </label>
                        <label className={label}>
                            Fecha de vencimiento
                            <input
                                className={`${field} mt-1 disabled:bg-slate-50 disabled:text-slate-400`}
                                type="date"
                                min={cert.data.issued_on}
                                disabled={noExpiry}
                                required={!noExpiry}
                                value={cert.data.expires_on}
                                onChange={(e) =>
                                    cert.setData('expires_on', e.target.value)
                                }
                            />
                        </label>
                    </div>
                    <label className={label}>
                        Código del certificado
                        <input
                            className={`${field} mt-1`}
                            value={cert.data.code}
                            onChange={(e) =>
                                cert.setData('code', e.target.value)
                            }
                            maxLength={40}
                        />
                        {cert.errors.code && (
                            <span className="text-xs text-red-600">
                                {cert.errors.code}
                            </span>
                        )}
                    </label>
                    <div className={label}>
                        Adjuntar certificado existente
                        <label className="mt-1 flex h-11 cursor-pointer items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-700 hover:border-[#c9a36a]">
                            <span className="truncate">
                                {cert.data.attachment?.name ??
                                    'Seleccionar archivo'}
                            </span>
                            <span className="shrink-0 text-xs text-slate-500">
                                PDF, JPG o PNG · Opcional
                            </span>
                            <input
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                className="sr-only"
                                onChange={(e) =>
                                    cert.setData(
                                        'attachment',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                        {cert.errors.attachment && (
                            <span className="text-xs text-red-600">
                                {cert.errors.attachment}
                            </span>
                        )}
                    </div>
                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="checkbox"
                            role="switch"
                            className="mt-0.5 h-6 w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-slate-300 transition before:m-0.5 before:block before:size-5 before:rounded-full before:bg-white before:transition checked:bg-[#640000] checked:before:translate-x-5"
                            checked={cert.data.generated}
                            onChange={(e) =>
                                cert.setData('generated', e.target.checked)
                            }
                        />
                        <span className="text-sm">
                            <span className="block font-semibold text-[#061633]">
                                Generar certificado con estos datos
                            </span>
                            <span className="text-xs text-slate-500">
                                Se generará un certificado de {firmName} con la
                                información ingresada.
                            </span>
                        </span>
                    </label>
                    <p className="flex gap-2 rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs text-slate-600">
                        <Info className="mt-0.5 size-4 shrink-0 text-blue-600" />
                        <span>
                            <b className="block text-slate-800">
                                También puedes adjuntar un certificado ya
                                emitido por otra entidad.
                            </b>
                            El archivo quedará como evidencia en el historial
                            del colaborador.
                        </span>
                    </p>
                </div>

                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <h3 className="flex items-center gap-2 text-lg font-bold text-[#061633]">
                            Vista previa
                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                Borrador
                            </span>
                        </h3>
                        <button
                            type="button"
                            onClick={() => setEnlarged(true)}
                            className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium"
                        >
                            <Search className="size-4" /> Ampliar
                        </button>
                    </div>
                    <div className="rounded-xl bg-slate-100 p-3">
                        <CertificatePreview data={preview} draft />
                    </div>
                    <p className="text-xs text-slate-500">
                        La vista previa se actualiza con los datos del
                        formulario.
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <p className="flex items-center gap-2 text-xs text-slate-500">
                    <Info className="size-4" /> Revisa el nombre y las fechas
                    antes de guardar.
                </p>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="inline-flex h-11 items-center rounded-xl border border-[#8a0b0b] px-6 text-sm font-bold text-[#8a0b0b]"
                    >
                        Cancelar
                    </button>
                    <button className={button} disabled={cert.processing}>
                        <Award className="size-4" /> Guardar certificación
                    </button>
                </div>
            </div>

            {enlarged && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Vista previa ampliada"
                    className="fixed inset-0 z-[60] grid place-items-center bg-black/70 p-4"
                    onClick={() => setEnlarged(false)}
                >
                    <div
                        className="w-full max-w-5xl rounded-xl bg-white p-3"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <CertificatePreview data={preview} draft />
                        <button
                            type="button"
                            onClick={() => setEnlarged(false)}
                            className="mt-3 inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold"
                        >
                            Cerrar
                        </button>
                    </div>
                </div>
            )}
        </form>
    );
}

function NeedForm({
    areaOptions,
    staff,
    onDone,
    onCancel,
}: Pick<Props, 'staff'> & {
    areaOptions: string[];
    onDone: () => void;
    onCancel: () => void;
}) {
    const need = useForm({
        area: areaOptions[0] ?? '',
        topic: '',
        priority: 'medium',
        objective: '',
        participants: [] as number[],
        target_date: '',
    });
    const submit = useSubmit(onDone);
    const [search, setSearch] = useState('');
    const label = 'block text-sm font-semibold text-[#061633]';
    const required = <span className="text-red-600"> *</span>;
    const chosen = need.data.participants
        .map((id) => staff.find((person) => person.id === id))
        .filter((person): person is Props['staff'][number] => Boolean(person));
    const suggestions =
        search.trim().length === 0
            ? []
            : staff
                  .filter(
                      (person) =>
                          !need.data.participants.includes(person.id) &&
                          person.name
                              .toLowerCase()
                              .includes(search.trim().toLowerCase()),
                  )
                  .slice(0, 6);
    const allChosen = staff.length > 0 && chosen.length === staff.length;
    const initials = (name: string) =>
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('');
    const priorities = [
        {
            value: 'low',
            label: 'Baja',
            active: 'bg-emerald-100 text-emerald-800',
        },
        {
            value: 'medium',
            label: 'Media',
            active: 'bg-amber-200 text-amber-900',
        },
        { value: 'high', label: 'Alta', active: 'bg-red-100 text-red-800' },
    ];
    const priority = priorities.find(
        (item) => item.value === need.data.priority,
    );
    const targetLabel = need.data.target_date
        ? new Date(`${need.data.target_date}T12:00:00`).toLocaleDateString(
              'es-GT',
              { day: 'numeric', month: 'long', year: 'numeric' },
          )
        : 'Sin fecha';
    const summaryRow = 'grid grid-cols-[6.5rem_1fr] items-start gap-3 text-sm';

    return (
        <form
            onSubmit={submit(need, '/office/training/needs')}
            className="space-y-4"
        >
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                        <label className={label}>
                            Área{required}
                            <select
                                className={`${field} mt-1`}
                                value={need.data.area}
                                onChange={(e) =>
                                    need.setData('area', e.target.value)
                                }
                            >
                                {areaOptions.map((area) => (
                                    <option key={area}>{area}</option>
                                ))}
                            </select>
                        </label>
                        <div className={label}>
                            Prioridad{required}
                            <div
                                className="mt-1 flex h-11 overflow-hidden rounded-xl border border-slate-300 bg-slate-100"
                                role="radiogroup"
                                aria-label="Prioridad"
                            >
                                {priorities.map((item) => (
                                    <button
                                        key={item.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={
                                            need.data.priority === item.value
                                        }
                                        onClick={() =>
                                            need.setData('priority', item.value)
                                        }
                                        className={`min-w-20 px-4 text-sm font-semibold transition ${need.data.priority === item.value ? item.active : 'text-slate-600 hover:bg-white'}`}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <label className={label}>
                        Tema de capacitación{required}
                        <input
                            className={`${field} mt-1`}
                            placeholder="Ej. Actualización fiscal y declaración de ISR"
                            value={need.data.topic}
                            onChange={(e) =>
                                need.setData('topic', e.target.value)
                            }
                            maxLength={160}
                            required
                        />
                    </label>
                    <label className={label}>
                        Objetivo o necesidad detectada{required}
                        <textarea
                            className={`${field} mt-1 h-24 py-2`}
                            placeholder="¿Qué debe poder hacer el equipo después de la capacitación?"
                            value={need.data.objective}
                            onChange={(e) =>
                                need.setData('objective', e.target.value)
                            }
                            maxLength={1000}
                            required
                        />
                    </label>
                    <div className={label}>
                        Colaboradores que necesitan capacitación{required}
                        <div className="relative mt-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                className={`${field} pl-9`}
                                placeholder="Buscar por nombre…"
                                aria-label="Buscar colaborador"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            {suggestions.length > 0 && (
                                <ul className="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                                    {suggestions.map((person) => (
                                        <li key={person.id}>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    need.setData(
                                                        'participants',
                                                        [
                                                            ...need.data
                                                                .participants,
                                                            person.id,
                                                        ],
                                                    );
                                                    setSearch('');
                                                }}
                                                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-normal hover:bg-amber-50"
                                            >
                                                <span className="grid size-7 place-items-center rounded-full bg-slate-100 text-[11px] font-bold">
                                                    {initials(person.name)}
                                                </span>
                                                {person.name}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {chosen.map((person) => (
                                <span
                                    key={person.id}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 py-1 pr-1.5 pl-3 text-sm font-normal"
                                >
                                    {person.name}
                                    <button
                                        type="button"
                                        aria-label={`Quitar a ${person.name}`}
                                        onClick={() =>
                                            need.setData(
                                                'participants',
                                                need.data.participants.filter(
                                                    (id) => id !== person.id,
                                                ),
                                            )
                                        }
                                        className="grid size-5 place-items-center rounded-full text-slate-500 hover:bg-slate-200"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </span>
                            ))}
                        </div>
                        <div className="mt-2 flex items-center justify-between text-sm font-normal">
                            <label className="flex cursor-pointer items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[#640000]"
                                    checked={allChosen}
                                    onChange={(e) =>
                                        need.setData(
                                            'participants',
                                            e.target.checked
                                                ? staff.map(
                                                      (person) => person.id,
                                                  )
                                                : [],
                                        )
                                    }
                                />
                                Seleccionar a todo el equipo
                            </label>
                            <span className="text-slate-600">
                                {chosen.length} seleccionado
                                {chosen.length === 1 ? '' : 's'}
                            </span>
                        </div>
                        {need.errors.participants && (
                            <span className="text-xs font-normal text-red-600">
                                {need.errors.participants}
                            </span>
                        )}
                    </div>
                    <label className={label}>
                        Fecha objetivo{' '}
                        <span className="font-normal text-slate-500">
                            (opcional)
                        </span>
                        <input
                            className={`${field} mt-1`}
                            type="date"
                            min={new Date().toLocaleDateString('en-CA')}
                            value={need.data.target_date}
                            onChange={(e) =>
                                need.setData('target_date', e.target.value)
                            }
                        />
                        {need.errors.target_date && (
                            <span className="text-xs font-normal text-red-600">
                                {need.errors.target_date}
                            </span>
                        )}
                    </label>
                </div>

                <aside className="h-fit space-y-4 rounded-2xl bg-slate-50 p-5">
                    <div className="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <span className="grid size-11 place-items-center rounded-xl bg-[#fbe9e9] text-[#8a0b0b]">
                            <ClipboardList className="size-5" />
                        </span>
                        <h3 className="text-lg font-bold text-[#061633]">
                            Resumen de la solicitud
                        </h3>
                    </div>
                    <dl className="space-y-4">
                        <div className={summaryRow}>
                            <dt className="text-slate-500">Área</dt>
                            <dd>{need.data.area || '—'}</dd>
                        </div>
                        <div className={summaryRow}>
                            <dt className="text-slate-500">Prioridad</dt>
                            <dd>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-bold ${priority?.active ?? ''}`}
                                >
                                    {priority?.label}
                                </span>
                            </dd>
                        </div>
                        <div className={summaryRow}>
                            <dt className="text-slate-500">Tema</dt>
                            <dd className="break-words">
                                {need.data.topic || '—'}
                            </dd>
                        </div>
                        <div className={summaryRow}>
                            <dt className="text-slate-500">Participantes</dt>
                            <dd>
                                {chosen.length === 0 ? (
                                    '—'
                                ) : (
                                    <>
                                        <span className="flex flex-wrap gap-1.5">
                                            {chosen
                                                .slice(0, 6)
                                                .map((person) => (
                                                    <span
                                                        key={person.id}
                                                        title={person.name}
                                                        className="grid size-9 place-items-center rounded-full bg-[#fbe9e9] text-xs font-bold text-[#8a0b0b]"
                                                    >
                                                        {initials(person.name)}
                                                    </span>
                                                ))}
                                            {chosen.length > 6 && (
                                                <span className="grid size-9 place-items-center rounded-full bg-slate-200 text-xs font-bold">
                                                    +{chosen.length - 6}
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-1 block text-slate-600">
                                            {chosen.length} colaborador
                                            {chosen.length === 1 ? '' : 'es'}
                                        </span>
                                    </>
                                )}
                            </dd>
                        </div>
                        <div className={summaryRow}>
                            <dt className="text-slate-500">Fecha objetivo</dt>
                            <dd>{targetLabel}</dd>
                        </div>
                    </dl>
                    <p className="flex gap-2 border-t border-slate-200 pt-4 text-xs text-slate-500">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Esta necesidad quedará disponible para planificar una
                        capacitación.
                    </p>
                </aside>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <p className="text-xs text-slate-500">
                    Puedes editar esta necesidad después de guardarla.
                </p>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="inline-flex h-11 items-center rounded-xl border border-[#8a0b0b] px-6 text-sm font-bold text-[#8a0b0b]"
                    >
                        Cancelar
                    </button>
                    <button
                        className={button}
                        disabled={
                            need.processing ||
                            need.data.participants.length === 0
                        }
                    >
                        <Plus className="size-4" /> Guardar necesidad
                    </button>
                </div>
            </div>
        </form>
    );
}

function ManageButtons({ onOpen }: { onOpen: (kind: DialogKind) => void }) {
    const ghost =
        'inline-flex h-10 items-center gap-2 rounded-xl border border-[#e4dbd6] bg-white px-4 text-sm font-bold text-[#8a0b0b] transition hover:border-[#c9a36a]';

    return (
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                className={ghost}
                onClick={() => onOpen('enroll')}
            >
                <Users className="size-4" /> Inscribir participantes
            </button>
            <button
                type="button"
                className={ghost}
                onClick={() => onOpen('cert')}
            >
                <Award className="size-4" /> Registrar certificación
            </button>
            <button
                type="button"
                className={ghost}
                onClick={() => onOpen('need')}
            >
                <Plus className="size-4" /> Registrar necesidad
            </button>
            <button
                type="button"
                className={button}
                onClick={() => onOpen('course')}
            >
                <Plus className="size-4" /> Registrar curso
            </button>
        </div>
    );
}

function ManageDialog({
    kind,
    onClose,
    courses,
    staff,
    areaOptions,
    nextCode,
    firmName,
}: { kind: DialogKind | null; onClose: () => void } & Pick<
    Props,
    'courses' | 'staff' | 'areaOptions'
> & { nextCode: string; firmName: string }) {
    return (
        <Dialog
            open={kind !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                className={`max-h-[90dvh] overflow-y-auto bg-white text-[#142135] ${kind === 'cert' || kind === 'need' || kind === 'course' ? 'sm:max-w-5xl' : 'sm:max-w-2xl'}`}
            >
                {kind && (
                    <>
                        <DialogTitle>{DIALOG_COPY[kind].title}</DialogTitle>
                        <DialogDescription>
                            {DIALOG_COPY[kind].description}
                        </DialogDescription>
                        {kind === 'course' && (
                            <CourseForm
                                areaOptions={areaOptions}
                                onDone={onClose}
                                onCancel={onClose}
                            />
                        )}
                        {kind === 'enroll' && (
                            <EnrollForm
                                courses={courses}
                                staff={staff}
                                onDone={onClose}
                            />
                        )}
                        {kind === 'cert' && (
                            <CertificationForm
                                staff={staff}
                                nextCode={nextCode}
                                firmName={firmName}
                                onDone={onClose}
                                onCancel={onClose}
                            />
                        )}
                        {kind === 'need' && (
                            <NeedForm
                                areaOptions={areaOptions}
                                staff={staff}
                                onDone={onClose}
                                onCancel={onClose}
                            />
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default function Training(props: Props) {
    const {
        summary,
        trend,
        areas,
        certification_status,
        courses,
        certifications,
        employees,
        needs,
        canManage,
    } = props;
    const [tab, setTab] = useState<(typeof TABS)[number]>('Resumen');
    const [dialog, setDialog] = useState<DialogKind | null>(null);
    const upcoming = courses
        .filter(
            (course) =>
                course.status !== 'completed' && course.status !== 'cancelled',
        )
        .slice(0, 5);
    const recent = courses
        .filter((course) => course.completed > 0)
        .sort((a, b) =>
            (b.last_completion ?? '').localeCompare(a.last_completion ?? ''),
        )
        .slice(0, 5);
    const totalCerts =
        certification_status.valid +
        certification_status.expiring +
        certification_status.expired;

    return (
        <>
            <Head title="Capacitación" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <GraduationCap className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Capacitación
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Desarrollo y crecimiento del equipo de la
                                    firma.
                                </p>
                            </div>
                        </div>
                        {canManage && <ManageButtons onOpen={setDialog} />}
                    </header>

                    <nav className="flex flex-wrap gap-2">
                        {TABS.map((name) => (
                            <button
                                key={name}
                                type="button"
                                onClick={() => setTab(name)}
                                className={`rounded-lg px-5 py-2 text-sm font-bold ${tab === name ? 'bg-[#8a0b0b] text-white' : 'border border-[#ebe3df] bg-white text-slate-700'}`}
                            >
                                {name}
                            </button>
                        ))}
                    </nav>

                    {tab === 'Resumen' && (
                        <>
                            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                <Kpi
                                    icon={<Users className="size-7" />}
                                    tone="bg-rose-50 text-rose-500"
                                    value={String(summary.staff)}
                                    label="Empleados"
                                    hint="en la firma"
                                />
                                <Kpi
                                    icon={<BookOpenCheck className="size-7" />}
                                    tone="bg-emerald-50 text-emerald-600"
                                    value={String(summary.courses_completed)}
                                    label="Cursos completados"
                                />
                                <Kpi
                                    icon={<Award className="size-7" />}
                                    tone="bg-blue-50 text-blue-600"
                                    value={String(summary.certifications)}
                                    label="Certificaciones"
                                    hint="vigentes"
                                />
                                <Kpi
                                    icon={<GraduationCap className="size-7" />}
                                    tone="bg-teal-50 text-teal-600"
                                    value={`${summary.progress}%`}
                                    label="Progreso promedio"
                                    hint="de capacitación"
                                />
                                <Kpi
                                    icon={<Star className="size-7" />}
                                    tone="bg-amber-50 text-amber-500"
                                    value={
                                        summary.satisfaction === null
                                            ? '—'
                                            : `${summary.satisfaction}%`
                                    }
                                    label="Evaluación promedio"
                                    hint="de satisfacción"
                                />
                            </section>

                            <section className="grid gap-4 xl:grid-cols-[1.4fr_1fr_1fr]">
                                <div className={card}>
                                    <h2 className="font-black">
                                        Progreso de capacitación del equipo
                                    </h2>
                                    <p className="mb-2 text-xs text-slate-500">
                                        Evolución del porcentaje de
                                        inscripciones completadas.
                                    </p>
                                    <Trend points={trend} />
                                </div>
                                <div className={card}>
                                    <h2 className="mb-3 font-black">
                                        Distribución de cursos por área
                                    </h2>
                                    <Donut rows={areas} center="cursos" />
                                </div>
                                <div className={card}>
                                    <h2 className="mb-3 font-black">
                                        Estado de certificaciones
                                    </h2>
                                    <Donut
                                        center="certificaciones"
                                        rows={[
                                            {
                                                name: 'Vigentes',
                                                value: certification_status.valid,
                                                color: '#16a34a',
                                            },
                                            {
                                                name: 'Por vencer (≤ 90 días)',
                                                value: certification_status.expiring,
                                                color: '#f0a93b',
                                            },
                                            {
                                                name: 'Vencidas',
                                                value: certification_status.expired,
                                                color: '#dc2626',
                                            },
                                        ]}
                                    />
                                    <p className="mt-2 text-xs text-slate-500">
                                        {totalCerts} certificaciones
                                        registradas.
                                    </p>
                                </div>
                            </section>

                            <section className="grid gap-4 xl:grid-cols-2">
                                <div className={`${card} overflow-x-auto`}>
                                    <h2 className="mb-3 font-black">
                                        Próximos cursos
                                    </h2>
                                    <table className="w-full min-w-[480px] text-sm">
                                        <thead>
                                            <tr className="text-left text-xs text-slate-600">
                                                <th className="py-2">Fecha</th>
                                                <th>Curso</th>
                                                <th>Área</th>
                                                <th>Modalidad</th>
                                                <th>Participantes</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {upcoming.map((course) => (
                                                <tr key={course.id}>
                                                    <td className="py-2.5">
                                                        {formatDate(
                                                            course.starts_on,
                                                        )}
                                                    </td>
                                                    <td className="font-bold">
                                                        {course.title}
                                                    </td>
                                                    <td>{course.area}</td>
                                                    <td className="capitalize">
                                                        {course.modality}
                                                    </td>
                                                    <td>
                                                        {course.enrolled}
                                                        {course.capacity
                                                            ? ` / ${course.capacity}`
                                                            : ''}
                                                    </td>
                                                    <td>
                                                        <span
                                                            className={`rounded-md px-2 py-1 text-xs font-bold ${COURSE_STATUS[course.status]?.style}`}
                                                        >
                                                            {
                                                                COURSE_STATUS[
                                                                    course
                                                                        .status
                                                                ]?.label
                                                            }
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                            {upcoming.length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={6}
                                                        className="py-8 text-center text-slate-500"
                                                    >
                                                        No hay cursos
                                                        programados.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                <div className={card}>
                                    <h2 className="mb-3 font-black">
                                        Cursos completados recientemente
                                    </h2>
                                    <ul className="divide-y divide-slate-100 text-sm">
                                        {recent.map((course) => (
                                            <li
                                                key={course.id}
                                                className="flex items-center justify-between gap-3 py-2.5"
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate font-bold">
                                                        {course.title}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {course.area} ·{' '}
                                                        {course.completed}{' '}
                                                        participante(s)
                                                    </p>
                                                </div>
                                                <span className="text-xs text-slate-500">
                                                    {formatDate(
                                                        course.last_completion,
                                                    )}
                                                </span>
                                                {course.satisfaction !==
                                                    null && (
                                                    <span className="rounded-md bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">
                                                        {course.satisfaction}%
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                        {recent.length === 0 && (
                                            <li className="py-8 text-center text-slate-500">
                                                Aún no hay cursos completados.
                                            </li>
                                        )}
                                    </ul>
                                </div>
                            </section>

                            <section className="grid gap-4 xl:grid-cols-2">
                                <div className={`${card} overflow-x-auto`}>
                                    <h2 className="mb-3 font-black">
                                        Desempeño por empleado
                                    </h2>
                                    <table className="w-full min-w-[480px] text-sm">
                                        <thead>
                                            <tr className="text-left text-xs text-slate-600">
                                                <th className="py-2">
                                                    Empleado
                                                </th>
                                                <th className="text-center">
                                                    Cursos asignados
                                                </th>
                                                <th className="text-center">
                                                    Completados
                                                </th>
                                                <th>Progreso</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {employees.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="py-2.5 font-bold">
                                                        {row.name}
                                                    </td>
                                                    <td className="text-center">
                                                        {row.assigned}
                                                    </td>
                                                    <td className="text-center">
                                                        {row.completed}
                                                    </td>
                                                    <td>
                                                        <div className="flex items-center gap-2">
                                                            <div className="h-2 w-24 overflow-hidden rounded-full bg-slate-100">
                                                                <div
                                                                    className="h-full rounded-full bg-emerald-500"
                                                                    style={{
                                                                        width: `${row.progress}%`,
                                                                    }}
                                                                />
                                                            </div>
                                                            <b className="text-xs">
                                                                {row.progress}%
                                                            </b>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span
                                                            className={`rounded-md px-2 py-1 text-xs font-bold ${row.progress >= 100 ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}`}
                                                        >
                                                            {row.progress >= 100
                                                                ? 'Al día'
                                                                : 'En curso'}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                            {employees.length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={5}
                                                        className="py-8 text-center text-slate-500"
                                                    >
                                                        Nadie está inscrito
                                                        todavía.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                <NeedsTable
                                    needs={needs}
                                    canManage={canManage}
                                />
                            </section>
                        </>
                    )}

                    {tab === 'Cursos' && (
                        <div className={`${card} overflow-x-auto`}>
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-600">
                                        <th className="py-2">Curso</th>
                                        <th>Área</th>
                                        <th>Modalidad</th>
                                        <th>Inicio</th>
                                        <th>Inscritos</th>
                                        <th>Completados</th>
                                        <th>Satisfacción</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {courses.map((course) => (
                                        <tr key={course.id}>
                                            <td className="py-2.5 font-bold">
                                                {course.title}
                                            </td>
                                            <td>{course.area}</td>
                                            <td className="capitalize">
                                                {course.modality}
                                            </td>
                                            <td>
                                                {formatDate(course.starts_on)}
                                            </td>
                                            <td>
                                                {course.enrolled}
                                                {course.capacity
                                                    ? ` / ${course.capacity}`
                                                    : ''}
                                            </td>
                                            <td>{course.completed}</td>
                                            <td>
                                                {course.satisfaction === null
                                                    ? '—'
                                                    : `${course.satisfaction}%`}
                                            </td>
                                            <td>
                                                <span
                                                    className={`rounded-md px-2 py-1 text-xs font-bold ${COURSE_STATUS[course.status]?.style}`}
                                                >
                                                    {
                                                        COURSE_STATUS[
                                                            course.status
                                                        ]?.label
                                                    }
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                    {courses.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="py-10 text-center text-slate-500"
                                            >
                                                No hay cursos registrados.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {tab === 'Certificaciones' && (
                        <div className={`${card} overflow-x-auto`}>
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-600">
                                        <th className="py-2">Certificación</th>
                                        <th>Colaborador</th>
                                        <th>Entidad</th>
                                        <th>Emitida</th>
                                        <th>Vence</th>
                                        <th>Estado</th>
                                        <th>Certificado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {certifications.map((item) => (
                                        <tr key={item.id}>
                                            <td className="py-2.5 font-bold">
                                                {item.name}
                                            </td>
                                            <td>{item.user}</td>
                                            <td>{item.issuer ?? '—'}</td>
                                            <td>
                                                {formatDate(item.issued_on)}
                                            </td>
                                            <td>
                                                {formatDate(item.expires_on)}
                                            </td>
                                            <td>
                                                <span
                                                    className={`rounded-md px-2 py-1 text-xs font-bold ${item.state === 'valid' ? 'bg-emerald-50 text-emerald-700' : item.state === 'expiring' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700'}`}
                                                >
                                                    {item.state === 'valid'
                                                        ? 'Vigente'
                                                        : item.state ===
                                                            'expiring'
                                                          ? 'Por vencer'
                                                          : 'Vencida'}
                                                </span>
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap items-center gap-2 text-xs font-bold text-[#8a0b0b]">
                                                    {item.generated && (
                                                        <a
                                                            href={`/office/training/certifications/${item.id}/certificate`}
                                                            className="inline-flex items-center gap-1 hover:underline"
                                                        >
                                                            <FileText className="size-3.5" />{' '}
                                                            Ver certificado
                                                        </a>
                                                    )}
                                                    {item.has_file && (
                                                        <a
                                                            href={`/office/training/certifications/${item.id}/file`}
                                                            className="inline-flex items-center gap-1 hover:underline"
                                                        >
                                                            <Download className="size-3.5" />{' '}
                                                            Archivo
                                                        </a>
                                                    )}
                                                    {!item.generated &&
                                                        !item.has_file && (
                                                            <span className="font-normal text-slate-400">
                                                                —
                                                            </span>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {certifications.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="py-10 text-center text-slate-500"
                                            >
                                                No hay certificaciones
                                                registradas.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {tab === 'Necesidades' && (
                        <NeedsTable needs={needs} canManage={canManage} />
                    )}

                    {canManage && (
                        <ManageDialog
                            kind={dialog}
                            onClose={() => setDialog(null)}
                            courses={courses}
                            staff={props.staff}
                            areaOptions={props.areaOptions}
                            nextCode={props.next_certificate_code}
                            firmName={props.firm_name}
                        />
                    )}
                </div>
            </main>
        </>
    );
}

function NeedsTable({
    needs,
    canManage,
}: {
    needs: Need[];
    canManage: boolean;
}) {
    return (
        <div className={`${card} overflow-x-auto`}>
            <h2 className="mb-3 font-black">Necesidades de capacitación</h2>
            <table className="w-full min-w-[480px] text-sm">
                <thead>
                    <tr className="text-left text-xs text-slate-600">
                        <th className="py-2">Área</th>
                        <th>Tema sugerido</th>
                        <th>Prioridad</th>
                        <th className="text-center">Empleados</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {needs.map((need) => (
                        <tr key={need.id}>
                            <td className="py-2.5">{need.area}</td>
                            <td>
                                <p className="font-bold">{need.topic}</p>
                                {need.objective && (
                                    <p className="max-w-md text-xs text-slate-500">
                                        {need.objective}
                                    </p>
                                )}
                                {need.participants.length > 0 && (
                                    <p
                                        className="text-xs text-slate-500"
                                        title={need.participants.join(', ')}
                                    >
                                        {need.participants
                                            .slice(0, 3)
                                            .join(', ')}
                                        {need.participants.length > 3 &&
                                            ` y ${need.participants.length - 3} más`}
                                        {need.target_date &&
                                            ` · Meta ${formatDate(need.target_date)}`}
                                    </p>
                                )}
                            </td>
                            <td>
                                <span
                                    className={`rounded-md px-2 py-1 text-xs font-bold ${PRIORITY[need.priority].style}`}
                                >
                                    {PRIORITY[need.priority].label}
                                </span>
                            </td>
                            <td className="text-center">{need.employees}</td>
                            <td>
                                {canManage ? (
                                    <select
                                        className="rounded-lg border border-[#e4dbd6] px-2 py-1 text-xs"
                                        value={need.status}
                                        onChange={(e) =>
                                            router.patch(
                                                `/office/training/needs/${need.id}`,
                                                { status: e.target.value },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {Object.entries(NEED_STATUS).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                ) : (
                                    <span className="text-xs font-semibold text-slate-600">
                                        {NEED_STATUS[need.status]}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                    {needs.length === 0 && (
                        <tr>
                            <td
                                colSpan={5}
                                className="py-8 text-center text-slate-500"
                            >
                                Sin necesidades registradas.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
