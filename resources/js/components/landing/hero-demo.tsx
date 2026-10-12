import {
    Bell,
    BookOpen,
    Building2,
    CalendarDays,
    ChevronDown,
    FileText,
    FolderOpen,
    House,
    ListChecks,
    Mic,
    PieChart,
    Search,
    Send,
    Settings,
    Sparkles,
    Target,
    UsersRound,
} from 'lucide-react';
import type { ReactNode, RefObject } from 'react';
import { useEffect, useState } from 'react';
import {
    AnimatedCounter,
    EASE,
    ThreeDTilt,
    usePrefersReducedMotion,
} from '@/components/landing/motion';

type DemoView = 'summary' | 'documents';
type Highlight = 'tasks' | 'compliance' | null;

const offices = [
    { name: 'Central', value: 62 },
    { name: 'Zona 10', value: 84 },
    { name: 'Zona 9', value: 73 },
    { name: 'Quetzaltenango', value: 91 },
    { name: 'Antigua', value: 70 },
];

const taskStates = [
    { label: 'Completadas', value: 68, color: '#22A866' },
    { label: 'En proceso', value: 22, color: '#D4A44C' },
    { label: 'Vencidas', value: 10, color: '#E24848' },
];

const sidebarItems = [
    { icon: House, label: 'Inicio' },
    { icon: UsersRound, label: 'Clientes' },
    { icon: Building2, label: 'Empresas' },
    { icon: BookOpen, label: 'Contabilidad' },
    { icon: CalendarDays, label: 'Obligaciones' },
    { icon: FolderOpen, label: 'Documentos' },
    { icon: Sparkles, label: 'IA (Luzma)' },
    { icon: PieChart, label: 'Reportes' },
];

const documents = [
    ['Factura FEL · Comercial Maya', 'Certificada', 'green'],
    ['Estado de cuenta · Banco Industrial', 'Conciliado', 'green'],
    ['Planilla IGSS · Septiembre', 'En revisión', 'gold'],
    ['Declaración IVA · Distribuidora Sur', 'Pendiente', 'red'],
    ['Libro de compras · Agosto', 'Cerrado', 'green'],
] as const;

const luzmaActions = [
    'Generar reportes',
    'Revisar obligaciones',
    'Analizar documentos',
    'Preparar recordatorios',
    'Resolver tus dudas',
];

const DONUT_RADIUS = 30;
const DONUT_CIRCUMFERENCE = 2 * Math.PI * DONUT_RADIUS;
const DONUT_DURATIONS = [600, 400, 300];

/** Donut segments drawn one after another (completed, in progress, overdue). */
const donutSegments = taskStates.map((state, index) => ({
    state,
    length: (state.value / 100) * DONUT_CIRCUMFERENCE,
    start: taskStates
        .slice(0, index)
        .reduce((sum, s) => sum + (s.value / 100) * DONUT_CIRCUMFERENCE, 0),
    duration: DONUT_DURATIONS[index],
    delay: 900 + DONUT_DURATIONS.slice(0, index).reduce((sum, d) => sum + d, 0),
}));

/** Demo timeline (ms) of the hero: entrance choreography, then the autoplay that pauses on interaction. */
const T = {
    dashboard: 150,
    sidebar: 300,
    cards: 450,
    counters: 700,
    charts: 900,
    luzma: 1300,
};

function appear(
    started: boolean,
    delay: number,
    from = 'translate3d(0, 16px, 0)',
) {
    return {
        opacity: started ? 1 : 0,
        transform: started ? 'none' : from,
        transition: `opacity 600ms ${EASE} ${delay}ms, transform 700ms ${EASE} ${delay}ms`,
    };
}

function useTypewriter(text: string, start: boolean, speed = 35): string {
    const reduced = usePrefersReducedMotion();
    const [length, setLength] = useState(0);

    useEffect(() => {
        if (!start || reduced) {
            return;
        }

        const timer = window.setInterval(
            () =>
                setLength((current) => {
                    if (current >= text.length) {
                        window.clearInterval(timer);
                    }

                    return Math.min(text.length, current + 1);
                }),
            speed,
        );

        return () => window.clearInterval(timer);
    }, [start, text, speed, reduced]);

    return reduced ? text : text.slice(0, length);
}

function DemoSidebar({ started }: { started: boolean }) {
    return (
        <aside
            className="hidden w-[150px] shrink-0 flex-col gap-1 rounded-l-[26px] bg-[linear-gradient(170deg,#7a0a0a,#4a0000_70%,#370000)] p-3 text-white sm:flex"
            style={appear(started, T.sidebar, 'translate3d(-14px, 0, 0)')}
            aria-hidden="true"
        >
            <img
                src="/images/atlantia-finance-logo.png"
                alt=""
                className="mx-auto mb-3 h-9 w-auto object-contain"
            />
            {sidebarItems.map(({ icon: Icon, label }, index) => (
                <span
                    key={label}
                    className={`flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold ${index === 0 ? 'bg-white/15 shadow-[inset_0_1px_0_rgba(255,255,255,0.15)]' : 'text-white/75'}`}
                >
                    <Icon className="size-3.5 shrink-0" />
                    {label}
                </span>
            ))}
        </aside>
    );
}

function DemoTopbar() {
    return (
        <div className="flex items-center gap-3" aria-hidden="true">
            <div className="flex h-7 flex-1 items-center gap-2 rounded-lg border border-[#ece4e1] bg-white/80 px-2.5 text-[9px] text-[#5A6780]">
                <Search className="size-3" />
                Buscar clientes, documentos, tareas…
            </div>
            <Bell className="size-3.5 text-[#780606]" />
            <Settings className="size-3.5 text-[#780606]" />
            <span className="hidden items-center gap-1.5 sm:flex">
                <span className="grid size-6 place-items-center rounded-full bg-[#650000] text-[8px] font-bold text-white">
                    ML
                </span>
                <span className="leading-tight">
                    <span className="block text-[9px] font-bold text-[#0C1424]">
                        María López
                    </span>
                    <span className="block text-[8px] text-[#5A6780]">
                        Administradora
                    </span>
                </span>
                <ChevronDown className="size-3 text-[#5A6780]" />
            </span>
        </div>
    );
}

function DemoMetricCard({
    label,
    icon,
    value,
    suffix = '',
    trend,
    started,
    delay,
    highlighted,
}: {
    label: string;
    icon: ReactNode;
    value: number;
    suffix?: string;
    trend?: ReactNode;
    started: boolean;
    delay: number;
    highlighted: boolean;
}) {
    return (
        <div
            className="rounded-2xl border bg-white/90 p-3 shadow-[0_1px_2px_rgba(12,20,36,0.04),0_10px_24px_-18px_rgba(55,0,0,0.35)]"
            style={{
                ...appear(started, delay),
                borderColor: highlighted ? '#D4A44C' : '#efe7e4',
                boxShadow: highlighted
                    ? '0 0 0 3px rgba(212,164,76,0.25), 0 14px 30px -16px rgba(120,6,6,0.45)'
                    : undefined,
                scale: highlighted ? '1.02' : '1',
                transition: `${appear(started, delay).transition}, scale 400ms ${EASE}, box-shadow 400ms ${EASE}, border-color 400ms`,
            }}
        >
            <div className="flex items-start justify-between gap-2">
                <p className="text-[9px] font-semibold text-[#5A6780]">
                    {label}
                </p>
                <span className="grid size-6 place-items-center rounded-lg bg-[#fbf1f0] text-[#780606]">
                    {icon}
                </span>
            </div>
            <p className="mt-1 flex items-baseline gap-1.5 text-xl font-bold tracking-tight text-[#0C1424]">
                <AnimatedCounter
                    to={value}
                    suffix={suffix}
                    start={started}
                    delay={T.counters}
                    duration={1200}
                />
                {trend}
            </p>
        </div>
    );
}

function AnimatedBarChart({ started }: { started: boolean }) {
    const [hovered, setHovered] = useState<number | null>(null);

    return (
        <div
            className="rounded-2xl border border-[#efe7e4] bg-white/90 p-3"
            style={appear(started, T.cards + 200)}
        >
            <p className="text-[10px] font-bold text-[#0C1424]">
                Cumplimiento por oficina
            </p>
            <div
                className="mt-2 flex h-24 items-end gap-2"
                role="img"
                aria-label={`Cumplimiento por oficina: ${offices.map((o) => `${o.name} ${o.value}%`).join(', ')}`}
            >
                {offices.map((office, index) => (
                    <div
                        key={office.name}
                        className="relative flex h-full flex-1 flex-col items-center justify-end"
                        onPointerEnter={() => setHovered(index)}
                        onPointerLeave={() => setHovered(null)}
                    >
                        {hovered === index && (
                            <span className="absolute -top-7 z-10 rounded-md bg-[#0C1424] px-2 py-1 text-[8px] font-semibold whitespace-nowrap text-white shadow-lg">
                                {office.name} · {office.value}%
                            </span>
                        )}
                        <div className="relative h-full w-full overflow-hidden rounded-t-md bg-[#f8eceb]">
                            <div
                                className="absolute inset-x-0 bottom-0 origin-bottom rounded-t-md bg-[linear-gradient(180deg,#a3161c,#650000)]"
                                style={{
                                    height: `${office.value}%`,
                                    transform: `${hovered === index ? 'translateY(-4px) ' : ''}scaleY(${started ? 1 : 0})`,
                                    filter:
                                        hovered === index
                                            ? 'brightness(1.18)'
                                            : 'none',
                                    transition: `transform 1000ms ${EASE} ${hovered === null ? T.charts + index * 100 : 0}ms, filter 200ms`,
                                }}
                            />
                        </div>
                    </div>
                ))}
            </div>
            <div className="mt-1 flex gap-2">
                {offices.map((office) => (
                    <span
                        key={office.name}
                        className="flex-1 truncate text-center text-[7px] text-[#5A6780]"
                    >
                        {office.name}
                    </span>
                ))}
            </div>
        </div>
    );
}

function AnimatedDonut({ started }: { started: boolean }) {
    return (
        <div
            className="rounded-2xl border border-[#efe7e4] bg-white/90 p-3"
            style={appear(started, T.cards + 300)}
        >
            <p className="text-[10px] font-bold text-[#0C1424]">
                Estado de tareas
            </p>
            <div className="mt-2 flex items-center gap-3">
                <svg
                    viewBox="0 0 80 80"
                    className="size-20 shrink-0 -rotate-90"
                    role="img"
                    aria-label="Estado de tareas: 68% completadas, 22% en proceso, 10% vencidas"
                >
                    <circle
                        cx="40"
                        cy="40"
                        r={DONUT_RADIUS}
                        fill="none"
                        stroke="#f4eceb"
                        strokeWidth="11"
                    />
                    {donutSegments.map(
                        ({ state, length, start, duration, delay }) => (
                            <circle
                                key={state.label}
                                cx="40"
                                cy="40"
                                r={DONUT_RADIUS}
                                fill="none"
                                stroke={state.color}
                                strokeWidth="11"
                                strokeDashoffset={-start}
                                style={{
                                    strokeDasharray: `${started ? length : 0} ${DONUT_CIRCUMFERENCE}`,
                                    transition: `stroke-dasharray ${duration}ms ease-out ${delay}ms`,
                                }}
                            />
                        ),
                    )}
                </svg>
                <ul className="flex flex-1 flex-col gap-1.5">
                    {taskStates.map((state) => (
                        <li
                            key={state.label}
                            className="flex items-center gap-1.5 text-[9px] text-[#26354D]"
                        >
                            <span
                                className="size-2 rounded-full"
                                style={{ background: state.color }}
                            />
                            <span className="flex-1">{state.label}</span>
                            <span className="font-bold">
                                <AnimatedCounter
                                    to={state.value}
                                    suffix="%"
                                    start={started}
                                    delay={T.charts}
                                    duration={1300}
                                />
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function DocumentsView() {
    const tone = { green: '#22A866', gold: '#B8862E', red: '#E24848' };

    return (
        <div className="h-full rounded-2xl border border-[#efe7e4] bg-white/90 p-3">
            <p className="text-[10px] font-bold text-[#0C1424]">
                Documentos recientes
            </p>
            <ul className="mt-2 divide-y divide-[#f3ecea]">
                {documents.map(([name, status, color]) => (
                    <li
                        key={name}
                        className="flex items-center gap-2 py-2 text-[9px]"
                    >
                        <FileText className="size-3.5 shrink-0 text-[#780606]" />
                        <span className="flex-1 truncate text-[#26354D]">
                            {name}
                        </span>
                        <span
                            className="rounded-full px-2 py-0.5 font-bold"
                            style={{
                                color: tone[color],
                                background: `${tone[color]}18`,
                            }}
                        >
                            {status}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function LuzmaDemo({
    started,
    insight,
}: {
    started: boolean;
    insight: string | null;
}) {
    const greeting = useTypewriter('Hola, soy Luzma', started);

    return (
        <div
            className="absolute right-2 bottom-6 z-20 w-[150px] rounded-[20px] border border-white/80 bg-white/90 p-3 shadow-[0_24px_50px_-18px_rgba(55,0,0,0.45)] backdrop-blur-xl sm:-right-10 sm:w-[190px] xl:top-12 xl:-right-24 xl:bottom-auto"
            style={{
                opacity: started ? 1 : 0,
                transform: started
                    ? 'translate3d(0,0,40px) scale(1)'
                    : 'translate3d(50px,0,40px) scale(0.95)',
                transition: `opacity 600ms ${EASE} ${T.luzma}ms, transform 600ms ${EASE} ${T.luzma}ms`,
            }}
        >
            <div className="flex items-center gap-2">
                <span className="grid size-6 place-items-center rounded-full bg-[linear-gradient(135deg,#a3161c,#650000)] text-white">
                    <Sparkles className="size-3.5" />
                </span>
                <div className="leading-tight">
                    <p className="text-[11px] font-bold text-[#0C1424]">
                        Luzma
                    </p>
                    <p className="text-[8px] text-[#326FE8]">
                        Asistente Contable con IA
                    </p>
                </div>
            </div>
            <div className="mt-2 rounded-xl bg-[#fbf6f4] p-2">
                <p className="min-h-[14px] text-[10px] font-bold text-[#0C1424]">
                    {greeting}
                    {greeting.length < 15 && (
                        <span className="landing-caret">|</span>
                    )}
                </p>
                <p className="text-[8px] text-[#5A6780]">¿Qué necesitas hoy?</p>
            </div>
            <div
                className="grid transition-[grid-template-rows] duration-500"
                style={{ gridTemplateRows: insight ? '1fr' : '0fr' }}
                aria-live="polite"
            >
                <div className="overflow-hidden">
                    <p className="mt-2 rounded-xl border border-[#D4A44C]/50 bg-[#fdf7ea] p-2 text-[8.5px] leading-snug font-semibold text-[#6b4a12]">
                        <Sparkles className="mr-1 inline size-3 text-[#D4A44C]" />
                        {insight}
                    </p>
                </div>
            </div>
            <ul className="mt-2 hidden flex-col gap-1 sm:flex">
                {luzmaActions.map((action) => (
                    <li
                        key={action}
                        className="flex items-center gap-1.5 rounded-lg border border-[#f0e7e4] bg-white px-2 py-1 text-[8.5px] font-semibold text-[#26354D]"
                    >
                        <ListChecks className="size-3 text-[#780606]" />
                        {action}
                    </li>
                ))}
            </ul>
            <div className="mt-2 flex items-center gap-1.5 rounded-lg border border-[#f0e7e4] bg-white px-2 py-1.5 text-[8px] text-[#5A6780]">
                <span className="flex-1">Escribe tu consulta…</span>
                <Mic className="size-3" />
                <span className="grid size-4 place-items-center rounded-md bg-[#650000] text-white">
                    <Send className="size-2.5" />
                </span>
            </div>
        </div>
    );
}

function DashboardPedestal() {
    return (
        <div className="relative mx-auto -mt-3 h-12 w-[96%]" aria-hidden="true">
            <div className="absolute inset-x-[18%] -top-2 h-6 rounded-b-xl bg-[linear-gradient(180deg,#d9c3c0,#b99692)]" />
            <div className="landing-pedestal absolute inset-x-0 top-3 h-8 overflow-hidden rounded-[50%/100%] bg-[linear-gradient(180deg,#f3e6e3_0%,#cfaaa5_45%,#8f4a46_100%)] shadow-[0_30px_60px_-12px_rgba(101,0,0,0.45),inset_0_2px_0_rgba(255,255,255,0.7)]">
                <span className="landing-pedestal-shine absolute inset-y-0 w-1/3 bg-[linear-gradient(100deg,transparent,rgba(255,255,255,0.65),transparent)]" />
            </div>
            <div className="absolute inset-x-[8%] top-10 h-8 rounded-full bg-[#780606]/30 blur-2xl" />
        </div>
    );
}

/**
 * The hero's living product demo: a real React dashboard on a 3D pedestal. It enters in sequence, then plays a short
 * Luzma story (insight → highlight → documents → back) that pauses while the visitor hovers or focuses it.
 */
export function HeroProductDemo({
    areaRef,
    started,
}: {
    areaRef: RefObject<HTMLElement | null>;
    started: boolean;
}) {
    const reduced = usePrefersReducedMotion();
    const [step, setStep] = useState(0);
    const [paused, setPaused] = useState(false);

    useEffect(() => {
        if (!started || reduced || paused) {
            return;
        }

        const timer = window.setTimeout(
            () => setStep((current) => (current + 1) % 5),
            3000,
        );

        return () => window.clearTimeout(timer);
    }, [started, reduced, paused, step]);

    const view: DemoView = step === 3 ? 'documents' : 'summary';
    const highlight: Highlight =
        step === 1 ? 'tasks' : step === 2 ? 'compliance' : null;
    const insight =
        step === 1
            ? 'Luzma detectó 3 obligaciones que requieren atención.'
            : step === 2
              ? 'Luzma puede ayudarte a priorizarlas.'
              : null;

    return (
        <div
            className="relative"
            onPointerEnter={() => setPaused(true)}
            onPointerLeave={() => setPaused(false)}
            onFocusCapture={() => setPaused(true)}
            onBlurCapture={() => setPaused(false)}
        >
            <div className="pointer-events-none absolute -inset-10 -z-10 rounded-full bg-[radial-gradient(circle_at_50%_55%,rgba(120,6,6,0.20),transparent_62%)] blur-2xl" />
            <ThreeDTilt areaRef={areaRef}>
                <div
                    className="relative"
                    style={appear(
                        started,
                        T.dashboard,
                        'translate3d(0, 35px, 0)',
                    )}
                >
                    <div className="relative flex overflow-hidden rounded-[30px] border border-white/70 bg-white/80 shadow-[0_2px_6px_rgba(55,0,0,0.06),0_40px_90px_-30px_rgba(55,0,0,0.55)] ring-1 ring-[#650000]/10 backdrop-blur-xl">
                        <DemoSidebar started={started} />
                        <div className="min-w-0 flex-1 bg-[linear-gradient(160deg,#ffffff,#fbf6f4)] p-3 sm:p-4">
                            <DemoTopbar />
                            <div className="mt-3 flex items-end justify-between">
                                <div>
                                    <p className="text-[9px] font-bold tracking-[0.18em] text-[#780606] uppercase">
                                        Vista consolidada
                                    </p>
                                    <p className="text-sm font-bold text-[#0C1424]">
                                        {view === 'summary'
                                            ? 'Resumen de oficinas'
                                            : 'Documentos'}
                                    </p>
                                </div>
                                <span className="flex items-center gap-1 rounded-lg border border-[#efe7e4] bg-white px-2 py-1 text-[9px] font-semibold text-[#26354D]">
                                    <CalendarDays className="size-3" /> Este mes
                                </span>
                            </div>
                            <div className="mt-3 grid">
                                <div
                                    className="col-start-1 row-start-1 flex flex-col gap-2.5"
                                    style={{
                                        opacity: view === 'summary' ? 1 : 0,
                                        transform:
                                            view === 'summary'
                                                ? 'none'
                                                : 'translate3d(-12px,0,0)',
                                        transition: `opacity 500ms ${EASE}, transform 500ms ${EASE}`,
                                    }}
                                    aria-hidden={view !== 'summary'}
                                >
                                    <div className="grid grid-cols-2 gap-2.5">
                                        <DemoMetricCard
                                            label="Clientes activos"
                                            icon={
                                                <UsersRound className="size-3.5" />
                                            }
                                            value={148}
                                            trend={
                                                <span className="text-[8px] font-bold text-[#22A866]">
                                                    ↑ 12%
                                                </span>
                                            }
                                            started={started}
                                            delay={T.cards}
                                            highlighted={false}
                                        />
                                        <DemoMetricCard
                                            label="Cumplimiento"
                                            icon={
                                                <Target className="size-3.5" />
                                            }
                                            value={96}
                                            suffix="%"
                                            started={started}
                                            delay={T.cards + 60}
                                            highlighted={
                                                highlight === 'compliance'
                                            }
                                        />
                                        <DemoMetricCard
                                            label="Tareas abiertas"
                                            icon={
                                                <ListChecks className="size-3.5" />
                                            }
                                            value={34}
                                            trend={
                                                <span className="text-[8px] font-bold text-[#E24848]">
                                                    ↓ 18%
                                                </span>
                                            }
                                            started={started}
                                            delay={T.cards + 120}
                                            highlighted={highlight === 'tasks'}
                                        />
                                        <DemoMetricCard
                                            label="Oficinas"
                                            icon={
                                                <Building2 className="size-3.5" />
                                            }
                                            value={4}
                                            started={started}
                                            delay={T.cards + 180}
                                            highlighted={false}
                                        />
                                    </div>
                                    <div className="grid gap-2.5 sm:grid-cols-[1.15fr_1fr]">
                                        <AnimatedBarChart started={started} />
                                        <div className="hidden sm:block">
                                            <AnimatedDonut started={started} />
                                        </div>
                                    </div>
                                </div>
                                <div
                                    className="col-start-1 row-start-1"
                                    style={{
                                        opacity: view === 'documents' ? 1 : 0,
                                        transform:
                                            view === 'documents'
                                                ? 'none'
                                                : 'translate3d(12px,0,0)',
                                        transition: `opacity 500ms ${EASE}, transform 500ms ${EASE}`,
                                    }}
                                    aria-hidden={view !== 'documents'}
                                >
                                    <DocumentsView />
                                </div>
                            </div>
                        </div>
                    </div>
                    <LuzmaDemo started={started} insight={insight} />
                </div>
                <DashboardPedestal />
            </ThreeDTilt>
        </div>
    );
}
