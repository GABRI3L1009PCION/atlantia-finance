import { Head, router, useForm } from '@inertiajs/react';
import {
    Award,
    BookOpenCheck,
    GraduationCap,
    Plus,
    Star,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
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
};

type Need = {
    id: number;
    area: string;
    topic: string;
    priority: 'high' | 'medium' | 'low';
    employees: number;
    status: 'pending' | 'planned' | 'done';
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
            description: 'Programa un curso para el equipo de la firma.',
        },
        enroll: {
            title: 'Inscribir participantes',
            description: 'Elige el curso y las personas que lo tomarán.',
        },
        cert: {
            title: 'Registrar certificación',
            description:
                'Guarda una certificación obtenida por un colaborador.',
        },
        need: {
            title: 'Registrar necesidad de capacitación',
            description: 'Anota un tema que el equipo necesita aprender.',
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

function CourseForm({
    areaOptions,
    onDone,
}: {
    areaOptions: string[];
    onDone: () => void;
}) {
    const course = useForm({
        title: '',
        area: areaOptions[0] ?? '',
        modality: 'virtual',
        starts_on: '',
        capacity: '',
        status: 'scheduled',
        description: '',
    });
    const submit = useSubmit(onDone);

    return (
        <form
            onSubmit={submit(course, '/office/training/courses')}
            className="space-y-3"
        >
            <input
                className={field}
                placeholder="Nombre del curso"
                value={course.data.title}
                onChange={(e) => course.setData('title', e.target.value)}
                required
            />
            <div className="grid gap-3 sm:grid-cols-3">
                <select
                    className={field}
                    value={course.data.area}
                    onChange={(e) => course.setData('area', e.target.value)}
                >
                    {areaOptions.map((area) => (
                        <option key={area}>{area}</option>
                    ))}
                </select>
                <select
                    className={field}
                    value={course.data.modality}
                    onChange={(e) => course.setData('modality', e.target.value)}
                >
                    <option value="virtual">Virtual</option>
                    <option value="presencial">Presencial</option>
                    <option value="híbrido">Híbrido</option>
                </select>
                <select
                    className={field}
                    value={course.data.status}
                    onChange={(e) => course.setData('status', e.target.value)}
                >
                    <option value="scheduled">Programado</option>
                    <option value="open">Inscripciones abiertas</option>
                    <option value="in_progress">En curso</option>
                    <option value="completed">Completado</option>
                </select>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <input
                    className={field}
                    type="date"
                    value={course.data.starts_on}
                    onChange={(e) =>
                        course.setData('starts_on', e.target.value)
                    }
                />
                <input
                    className={field}
                    type="number"
                    min="1"
                    placeholder="Cupo"
                    value={course.data.capacity}
                    onChange={(e) => course.setData('capacity', e.target.value)}
                />
            </div>
            <button className={button} disabled={course.processing}>
                <Plus className="size-4" /> Guardar curso
            </button>
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
            <select
                multiple
                className={`${field} h-40 py-2`}
                value={enroll.data.user_ids}
                onChange={(e) =>
                    enroll.setData(
                        'user_ids',
                        Array.from(
                            e.target.selectedOptions,
                            (option) => option.value,
                        ),
                    )
                }
                required
            >
                {staff.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name}
                    </option>
                ))}
            </select>
            <p className="text-xs text-slate-500">
                Mantén Ctrl para elegir varias personas.
            </p>
            <button className={button} disabled={enroll.processing}>
                <Users className="size-4" /> Inscribir
            </button>
        </form>
    );
}

function CertificationForm({
    staff,
    onDone,
}: Pick<Props, 'staff'> & { onDone: () => void }) {
    const cert = useForm({
        user_id: '',
        name: '',
        issuer: '',
        issued_on: '',
        expires_on: '',
    });
    const submit = useSubmit(onDone);

    return (
        <form
            onSubmit={submit(cert, '/office/training/certifications')}
            className="space-y-3"
        >
            <select
                className={field}
                value={cert.data.user_id}
                onChange={(e) => cert.setData('user_id', e.target.value)}
                required
            >
                <option value="">Colaborador</option>
                {staff.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name}
                    </option>
                ))}
            </select>
            <input
                className={field}
                placeholder="Certificación"
                value={cert.data.name}
                onChange={(e) => cert.setData('name', e.target.value)}
                required
            />
            <input
                className={field}
                placeholder="Entidad emisora"
                value={cert.data.issuer}
                onChange={(e) => cert.setData('issuer', e.target.value)}
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-xs text-slate-500">
                    Emitida
                    <input
                        className={field}
                        type="date"
                        value={cert.data.issued_on}
                        onChange={(e) =>
                            cert.setData('issued_on', e.target.value)
                        }
                        required
                    />
                </label>
                <label className="text-xs text-slate-500">
                    Vence
                    <input
                        className={field}
                        type="date"
                        value={cert.data.expires_on}
                        onChange={(e) =>
                            cert.setData('expires_on', e.target.value)
                        }
                    />
                </label>
            </div>
            <button className={button} disabled={cert.processing}>
                <Award className="size-4" /> Guardar certificación
            </button>
        </form>
    );
}

function NeedForm({
    areaOptions,
    onDone,
}: {
    areaOptions: string[];
    onDone: () => void;
}) {
    const need = useForm({
        area: areaOptions[0] ?? '',
        topic: '',
        priority: 'medium',
        employees: '',
    });
    const submit = useSubmit(onDone);

    return (
        <form
            onSubmit={submit(need, '/office/training/needs')}
            className="space-y-3"
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <select
                    className={field}
                    value={need.data.area}
                    onChange={(e) => need.setData('area', e.target.value)}
                >
                    {areaOptions.map((area) => (
                        <option key={area}>{area}</option>
                    ))}
                </select>
                <select
                    className={field}
                    value={need.data.priority}
                    onChange={(e) => need.setData('priority', e.target.value)}
                >
                    <option value="high">Prioridad alta</option>
                    <option value="medium">Prioridad media</option>
                    <option value="low">Prioridad baja</option>
                </select>
            </div>
            <input
                className={field}
                placeholder="Tema sugerido"
                value={need.data.topic}
                onChange={(e) => need.setData('topic', e.target.value)}
                required
            />
            <input
                className={field}
                type="number"
                min="0"
                placeholder="Personas que lo necesitan"
                value={need.data.employees}
                onChange={(e) => need.setData('employees', e.target.value)}
            />
            <button className={button} disabled={need.processing}>
                <Plus className="size-4" /> Guardar necesidad
            </button>
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
}: { kind: DialogKind | null; onClose: () => void } & Pick<
    Props,
    'courses' | 'staff' | 'areaOptions'
>) {
    return (
        <Dialog
            open={kind !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
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
                            <CertificationForm staff={staff} onDone={onClose} />
                        )}
                        {kind === 'need' && (
                            <NeedForm
                                areaOptions={areaOptions}
                                onDone={onClose}
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
                                        </tr>
                                    ))}
                                    {certifications.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={6}
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
                            <td className="font-bold">{need.topic}</td>
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
