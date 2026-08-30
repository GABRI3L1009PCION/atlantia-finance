import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    Bot,
    Building2,
    CalendarCheck,
    Check,
    ChevronLeft,
    ChevronRight,
    FileCheck2,
    Fingerprint,
    History,
    LockKeyhole,
    Menu,
    Network,
    ScanSearch,
    Send,
    ShieldCheck,
    Sparkles,
    RotateCcw,
    UsersRound,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        icon: UsersRound,
        iconClass: 'bg-rose-100 text-rose-700 ring-rose-200',
        title: 'Expedientes organizados',
        description:
            'Centraliza clientes, información fiscal, responsables, documentos y seguimiento en un solo lugar.',
    },
    {
        icon: CalendarCheck,
        iconClass: 'bg-amber-100 text-amber-700 ring-amber-200',
        title: 'Calendario fiscal',
        description:
            'Controla obligaciones, fechas límite, tareas recurrentes y alertas para que nada se quede atrás.',
    },
    {
        icon: Building2,
        iconClass: 'bg-sky-100 text-sky-700 ring-sky-200',
        title: 'Gestión multi-oficina',
        description:
            'Supervisa varias oficinas desde una cuenta, manteniendo equipos, clientes y datos correctamente separados.',
    },
    {
        icon: BarChart3,
        iconClass: 'bg-violet-100 text-violet-700 ring-violet-200',
        title: 'Visibilidad para decidir',
        description:
            'Compara carga de trabajo, cumplimiento y operación por oficina, colaborador o periodo.',
    },
    {
        icon: FileCheck2,
        iconClass: 'bg-cyan-100 text-cyan-700 ring-cyan-200',
        title: 'Documentos bajo control',
        description:
            'Mantén evidencias y archivos vinculados al cliente y a cada proceso, con trazabilidad de cambios.',
    },
    {
        icon: ShieldCheck,
        iconClass: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        title: 'Seguridad desde el diseño',
        description:
            'Permisos por función, autenticación reforzada y auditoría para proteger la información contable.',
    },
    {
        icon: Bot,
        iconClass: 'bg-indigo-100 text-indigo-700 ring-indigo-200',
        title: 'Asistencia con IA',
        description:
            'Resume, clasifica y detecta pendientes para agilizar el trabajo, siempre bajo revisión del profesional.',
    },
];

type Plan = {
    code: string;
    name: string;
    eyebrow: string;
    description: string;
    monthlyPrice: number;
    annualPrice: number;
    features: string[];
    highlighted?: boolean;
    pricePrefix?: string;
};

const plans: Plan[] = [
    {
        code: 'essential',
        name: 'Esencial',
        eyebrow: 'Para comenzar con orden',
        description:
            'La base contable y operativa para profesionales independientes y oficinas pequeñas.',
        monthlyPrice: 399,
        annualPrice: 3990,
        features: [
            '1 oficina',
            '2 usuarios y 150 expedientes activos',
            '2 GB de almacenamiento',
            'Contabilidad y facturación básica',
            'Expedientes, tareas y obligaciones',
            '20 consultas de IA al mes',
            'Seguridad, auditoría y copias de respaldo',
        ],
    },
    {
        code: 'professional',
        name: 'Profesional',
        eyebrow: 'Para oficinas establecidas',
        description:
            'Más capacidad, automatización e inteligencia para equipos contables en crecimiento.',
        monthlyPrice: 799,
        annualPrice: 7990,
        features: [
            '1 oficina',
            '8 usuarios y 500 expedientes activos',
            '10 GB de almacenamiento',
            'Contabilidad, reportes e inventario',
            'Roles, aprobaciones y FEL',
            'OCR y 120 consultas de IA al mes',
            'Soporte prioritario',
        ],
        highlighted: true,
    },
    {
        code: 'multi_office',
        name: 'Multi-oficina',
        eyebrow: 'Para propietarios de varias sedes',
        description:
            'Control consolidado con independencia operativa y financiera para cada oficina.',
        monthlyPrice: 1499,
        annualPrice: 14990,
        features: [
            'Hasta 5 oficinas y 25 usuarios',
            '1,500 expedientes activos',
            '40 GB de almacenamiento',
            'Contabilidad e inventario multi-oficina',
            'Panel consolidado y permisos por sede',
            '500 consultas de IA multi-oficina',
            'Auditoría central y capacitación inicial',
        ],
    },
    {
        code: 'corporate',
        name: 'Corporativo',
        eyebrow: 'Para firmas de gran escala',
        description:
            'Operación corporativa con flujos avanzados, integraciones y analítica ejecutiva.',
        monthlyPrice: 2999,
        annualPrice: 29990,
        features: [
            'Hasta 10 oficinas y 60 usuarios',
            '5,000 expedientes activos',
            '120 GB de almacenamiento',
            'Contabilidad, inventario y nómina',
            'API, webhooks y flujos corporativos',
            '1,500 consultas de IA avanzada',
            'Soporte preferente y auditoría extendida',
        ],
    },
    {
        code: 'enterprise',
        name: 'Empresarial',
        eyebrow: 'Para necesidades especiales',
        description:
            'Infraestructura, límites, implementación e integraciones definidos para tu organización.',
        monthlyPrice: 4999,
        annualPrice: 49990,
        pricePrefix: 'Desde',
        features: [
            'Todas las funciones de Atlantia',
            'Oficinas, usuarios y capacidad a medida',
            'Infraestructura compartida o dedicada',
            'IA y retención personalizadas',
            'Integraciones y migraciones especiales',
            'Acuerdos de servicio y seguridad',
            'Implementación y acompañamiento',
        ],
    },
];

const questions = [
    {
        question: '¿Atlantia Finance sustituye el criterio del contador?',
        answer: 'No. Atlantia Finance organiza la operación, la información y el seguimiento para que el profesional trabaje con mayor control y visibilidad.',
        keywords: ['contador', 'criterio', 'sustituye', 'reemplaza'],
    },
    {
        question: '¿Puedo administrar varias oficinas con una sola cuenta?',
        answer: 'Sí. El plan Multi-oficina está pensado para propietarios que necesitan supervisar varias sedes, comparar resultados y delegar permisos sin mezclar sus datos.',
        keywords: ['oficina', 'oficinas', 'sedes', 'multi-oficina'],
    },
    {
        question: '¿Cómo protegen la información?',
        answer: 'La plataforma se diseña con aislamiento entre organizaciones, permisos en el servidor, autenticación multifactor, registros de auditoría y controles alineados con OWASP.',
        keywords: ['seguridad', 'protegen', 'informacion', 'datos', 'owasp'],
    },
    {
        question: '¿La IA puede tomar decisiones contables por mí?',
        answer: 'No. La IA funciona como asistente: propone resúmenes, clasificaciones y alertas, pero las acciones sensibles requieren revisión y aprobación de una persona autorizada.',
        keywords: ['ia', 'inteligencia', 'decisiones', 'contables'],
    },
    {
        question: '¿Puedo pagar mensual o anualmente?',
        answer: 'Sí. Puedes pagar mes a mes o elegir la facturación anual, que equivale a pagar diez meses y recibir dos meses incluidos.',
        keywords: ['pago', 'pagar', 'mensual', 'anual', 'planes', 'precio'],
    },
];

type HelpMessage = {
    id: number;
    role: 'assistant' | 'user';
    content: string;
};

const initialHelpMessages: HelpMessage[] = [
    {
        id: 1,
        role: 'assistant',
        content:
            '¡Hola! Soy el asistente de orientación de Atlantia Finance. Elige una pregunta o escribe una palabra clave para conocer la respuesta.',
    },
];

const normalizeHelpText = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

const lightSparklePoints = [
    { left: '4%', top: '16%', size: 'size-4', delay: '0s' },
    { left: '12%', top: '72%', size: 'size-3', delay: '1.8s' },
    { left: '31%', top: '10%', size: 'size-3.5', delay: '3.1s' },
    { left: '68%', top: '78%', size: 'size-3', delay: '0.9s' },
    { left: '84%', top: '18%', size: 'size-4', delay: '2.4s' },
    { left: '95%', top: '62%', size: 'size-3.5', delay: '4s' },
];

function LightSectionSparkles() {
    return (
        <div
            className="pointer-events-none absolute inset-0 z-0 overflow-hidden"
            aria-hidden="true"
        >
            {lightSparklePoints.map((point, index) => (
                <Sparkles
                    key={`${point.left}-${point.top}`}
                    className={`atlantia-sparkle absolute ${point.size}`}
                    style={{
                        left: point.left,
                        top: point.top,
                        animationDelay: point.delay,
                        animationDuration: `${5.5 + index * 0.45}s`,
                    }}
                />
            ))}
        </div>
    );
}

export default function Welcome() {
    const { auth } = usePage().props;
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [billingPeriod, setBillingPeriod] = useState<'monthly' | 'annual'>(
        'monthly',
    );
    const plansCarouselRef = useRef<HTMLDivElement>(null);
    const [activePlanIndex, setActivePlanIndex] = useState(0);
    const [helpInput, setHelpInput] = useState('');
    const [isHelpChatOpen, setIsHelpChatOpen] = useState(false);
    const [helpMessages, setHelpMessages] =
        useState<HelpMessage[]>(initialHelpMessages);
    const helpMessagesContainerRef = useRef<HTMLDivElement>(null);
    const nextHelpMessageIdRef = useRef(2);

    useEffect(() => {
        const messagesContainer = helpMessagesContainerRef.current;

        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }, [helpMessages, isHelpChatOpen]);

    useEffect(() => {
        if (!isHelpChatOpen) {
            return;
        }

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setIsHelpChatOpen(false);
            }
        };

        window.addEventListener('keydown', closeOnEscape);

        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [isHelpChatOpen]);

    const askHelpQuestion = (value: string) => {
        const questionText = value.trim();

        if (!questionText) {
            return;
        }

        const normalizedQuestion = normalizeHelpText(questionText);
        const matchedQuestion = questions.find(
            ({ question, keywords }) =>
                normalizeHelpText(question) === normalizedQuestion ||
                keywords.some((keyword) =>
                    normalizedQuestion.includes(normalizeHelpText(keyword)),
                ),
        );
        const response =
            matchedQuestion?.answer ??
            'Puedo orientarte sobre criterio profesional, varias oficinas, seguridad, uso de IA y formas de pago. Elige una de las preguntas sugeridas para ver la información confirmada.';
        const messageId = nextHelpMessageIdRef.current;

        nextHelpMessageIdRef.current += 2;

        setHelpMessages((currentMessages) => [
            ...currentMessages,
            { id: messageId, role: 'user', content: questionText },
            { id: messageId + 1, role: 'assistant', content: response },
        ]);
        setHelpInput('');
    };

    const resetHelpChat = () => {
        nextHelpMessageIdRef.current = 2;
        setHelpMessages(initialHelpMessages);
        setHelpInput('');
    };

    const showPlan = (index: number) => {
        const carousel = plansCarouselRef.current;
        const cards =
            carousel?.querySelectorAll<HTMLElement>('[data-plan-card]');

        if (!carousel || !cards?.length) {
            return;
        }

        const safeIndex = Math.max(0, Math.min(index, cards.length - 1));
        const card = cards[safeIndex];
        const left = card.offsetLeft - carousel.offsetLeft;

        carousel.scrollTo({ left, behavior: 'smooth' });
        setActivePlanIndex(safeIndex);
    };

    const syncActivePlan = () => {
        const carousel = plansCarouselRef.current;
        const cards =
            carousel?.querySelectorAll<HTMLElement>('[data-plan-card]');

        if (!carousel || !cards?.length) {
            return;
        }

        const center = carousel.scrollLeft + carousel.clientWidth / 2;
        let closestIndex = 0;
        let closestDistance = Number.POSITIVE_INFINITY;

        cards.forEach((card, index) => {
            const cardCenter = card.offsetLeft + card.clientWidth / 2;
            const distance = Math.abs(center - cardCenter);

            if (distance < closestDistance) {
                closestDistance = distance;
                closestIndex = index;
            }
        });

        setActivePlanIndex(closestIndex);
    };

    return (
        <>
            <Head title="Gestión segura para oficinas contables">
                <meta
                    name="description"
                    content="Atlantia Finance usa IA de forma segura para centralizar clientes, tareas, documentos y supervisión multi-oficina para equipos contables."
                />
            </Head>

            <div className="min-h-screen bg-[#f7f9f8] text-slate-950 antialiased selection:bg-emerald-200">
                <header className="sticky top-0 z-50 border-b border-white/10 bg-[#640000]/95 shadow-lg shadow-[#320000]/10 backdrop-blur-xl">
                    <div className="mx-auto flex h-18 max-w-7xl items-center justify-between px-5 sm:px-8">
                        <a
                            href="#inicio"
                            className="flex items-center"
                            aria-label="Atlantia Finance, inicio"
                        >
                            <img
                                src="/images/atlantia-finance-logo.png"
                                alt="Atlantia Finance"
                                className="h-12 w-auto object-contain sm:h-14"
                            />
                        </a>

                        <nav
                            className="hidden items-center gap-7 text-sm font-medium text-white/75 lg:flex"
                            aria-label="Navegación principal"
                        >
                            <a
                                className="transition hover:text-white"
                                href="#funciones"
                            >
                                Funciones
                            </a>
                            <a
                                className="transition hover:text-white"
                                href="#multi-oficina"
                            >
                                Multi-oficina
                            </a>
                            <a
                                className="transition hover:text-white"
                                href="#inteligencia-artificial"
                            >
                                IA
                            </a>
                            <a
                                className="transition hover:text-white"
                                href="#planes"
                            >
                                Planes
                            </a>
                            <a
                                className="transition hover:text-white"
                                href="#seguridad"
                            >
                                Seguridad
                            </a>
                            <button
                                type="button"
                                className="transition hover:text-white"
                                onClick={() => setIsHelpChatOpen(true)}
                            >
                                Preguntas
                            </button>
                        </nav>

                        <div className="hidden items-center gap-3 sm:flex">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-[#640000] shadow-lg shadow-black/10 transition hover:bg-[#fff4f4]"
                                >
                                    Ir al panel
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="px-3 py-2 text-sm font-semibold text-white/80 transition hover:text-white"
                                    >
                                        Ingresar
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-[#640000] shadow-lg shadow-black/10 transition hover:bg-[#fff4f4]"
                                    >
                                        Comenzar
                                    </Link>
                                </>
                            )}
                        </div>

                        <button
                            type="button"
                            className="grid size-10 place-items-center rounded-xl border border-white/20 bg-white/10 text-white sm:hidden"
                            onClick={() => setIsMenuOpen((open) => !open)}
                            aria-expanded={isMenuOpen}
                            aria-label="Abrir menú"
                        >
                            {isMenuOpen ? (
                                <X className="size-5" />
                            ) : (
                                <Menu className="size-5" />
                            )}
                        </button>
                    </div>

                    {isMenuOpen && (
                        <nav
                            className="border-t border-white/10 bg-[#4b0000] px-5 py-5 text-white sm:hidden"
                            aria-label="Navegación móvil"
                        >
                            <div className="flex flex-col gap-4 text-sm font-semibold text-white/85">
                                {[
                                    'funciones',
                                    'multi-oficina',
                                    'inteligencia-artificial',
                                    'planes',
                                    'seguridad',
                                    'preguntas',
                                ].map((section) => (
                                    <a
                                        key={section}
                                        href={`#${section}`}
                                        onClick={(event) => {
                                            setIsMenuOpen(false);

                                            if (section === 'preguntas') {
                                                event.preventDefault();
                                                setIsHelpChatOpen(true);
                                            }
                                        }}
                                        className="capitalize"
                                    >
                                        {section.replace('-', ' ')}
                                    </a>
                                ))}
                                <div className="mt-2 grid grid-cols-2 gap-3">
                                    <Link
                                        href={login()}
                                        className="rounded-xl border border-white/20 px-4 py-3 text-center"
                                    >
                                        Ingresar
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="rounded-xl bg-white px-4 py-3 text-center text-[#640000]"
                                    >
                                        Comenzar
                                    </Link>
                                </div>
                            </div>
                        </nav>
                    )}
                </header>

                <main>
                    <section id="inicio" className="relative overflow-hidden">
                        <div className="absolute inset-x-0 top-0 -z-0 h-[620px] bg-[radial-gradient(circle_at_75%_15%,rgba(182,126,125,0.22),transparent_36%),radial-gradient(circle_at_10%_10%,rgba(100,0,0,0.08),transparent_28%)]" />
                        <LightSectionSparkles />
                        <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-5 py-14 sm:px-8 sm:py-16 lg:grid-cols-[1.04fr_.96fr]">
                            <div>
                                <div className="mb-7 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white/80 px-3.5 py-2 text-xs font-bold text-emerald-800 shadow-sm">
                                    <Sparkles className="size-3.5" />
                                    Construido para la operación contable
                                    moderna
                                </div>
                                <h1 className="max-w-3xl text-5xl leading-[1.02] font-bold tracking-[-0.045em] text-slate-950 sm:text-6xl lg:text-7xl">
                                    Tu oficina contable,{' '}
                                    <span className="text-emerald-700">
                                        bajo control.
                                    </span>
                                </h1>
                                <p className="mt-7 max-w-2xl text-lg leading-8 text-slate-600 sm:text-xl">
                                    Atlantia Finance conecta clientes, equipo,
                                    obligaciones y documentos en una plataforma
                                    segura con IA asistida, lista para crecer de
                                    una a múltiples oficinas.
                                </p>
                                <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                                    <Link
                                        href={register()}
                                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-700 px-6 py-3.5 text-sm font-bold text-white shadow-xl shadow-emerald-950/15 transition hover:-translate-y-0.5 hover:bg-emerald-800"
                                    >
                                        Comenzar ahora{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                    <a
                                        href="#planes"
                                        className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white/80 px-6 py-3.5 text-sm font-bold text-slate-800 transition hover:border-slate-400 hover:bg-white"
                                    >
                                        Conocer los planes
                                    </a>
                                </div>
                                <div className="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-xs font-medium text-slate-500">
                                    <span className="flex items-center gap-2">
                                        <Check className="size-4 text-emerald-700" />{' '}
                                        Sin tarjeta para comenzar
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <Check className="size-4 text-emerald-700" />{' '}
                                        Configuración guiada
                                    </span>
                                </div>
                            </div>

                            <div className="relative">
                                <div className="absolute -inset-5 rounded-[2.5rem] bg-gradient-to-br from-emerald-200/60 to-amber-100/40 blur-2xl" />
                                <div className="relative overflow-hidden rounded-[2rem] border border-white/80 bg-slate-950 p-3 shadow-2xl shadow-slate-950/20">
                                    <div className="rounded-[1.35rem] bg-[#f8faf9] p-5 sm:p-7">
                                        <div className="flex items-center justify-between gap-4">
                                            <div>
                                                <p className="text-xs font-bold tracking-[0.16em] text-emerald-700 uppercase">
                                                    Vista consolidada
                                                </p>
                                                <h2 className="mt-1 text-xl font-bold">
                                                    Resumen de oficinas
                                                </h2>
                                            </div>
                                            <span className="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-800">
                                                Este mes
                                            </span>
                                        </div>
                                        <div className="mt-7 grid grid-cols-2 gap-3">
                                            {[
                                                ['Clientes activos', '148'],
                                                ['Cumplimiento', '96%'],
                                                ['Tareas abiertas', '34'],
                                                ['Oficinas', '4'],
                                            ].map(([label, value]) => (
                                                <div
                                                    key={label}
                                                    className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
                                                >
                                                    <p className="text-xs text-slate-500">
                                                        {label}
                                                    </p>
                                                    <p className="mt-2 text-2xl font-bold tracking-tight">
                                                        {value}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                        <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                            <div className="flex items-center justify-between">
                                                <p className="text-sm font-bold">
                                                    Cumplimiento por oficina
                                                </p>
                                                <BarChart3 className="size-4 text-emerald-700" />
                                            </div>
                                            <div className="mt-5 flex h-28 items-end gap-3">
                                                {[
                                                    72, 94, 84, 100, 91, 97, 88,
                                                    96,
                                                ].map((height, index) => (
                                                    <div
                                                        key={index}
                                                        className="flex h-full flex-1 items-end rounded-t-md bg-emerald-50"
                                                    >
                                                        <div
                                                            className="w-full rounded-t-md bg-emerald-600"
                                                            style={{
                                                                height: `${height}%`,
                                                            }}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        id="funciones"
                        className="relative scroll-mt-18 overflow-hidden border-y border-slate-200 bg-white py-14 sm:py-16"
                    >
                        <LightSectionSparkles />
                        <div className="relative z-10 mx-auto max-w-7xl px-5 sm:px-8">
                            <div className="grid gap-5 lg:grid-cols-[1.15fr_.85fr] lg:items-end lg:gap-16">
                                <div>
                                    <p className="text-sm font-bold tracking-[0.18em] text-emerald-700 uppercase">
                                        Una operación conectada
                                    </p>
                                    <h2 className="mt-3 max-w-3xl text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl">
                                        Menos seguimiento manual. Más claridad
                                        para tu equipo.
                                    </h2>
                                </div>
                                <p className="max-w-xl text-base leading-7 text-slate-600 sm:text-lg">
                                    Las herramientas esenciales para organizar
                                    el trabajo diario y construir una oficina
                                    que pueda crecer.
                                </p>
                            </div>
                            <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {features.map(
                                    ({
                                        icon: Icon,
                                        iconClass,
                                        title,
                                        description,
                                    }) => (
                                        <article
                                            key={title}
                                            className="group rounded-2xl border border-slate-200 bg-[#fbfcfb] p-5 transition hover:-translate-y-1 hover:border-slate-300 hover:bg-white hover:shadow-xl hover:shadow-slate-950/5"
                                        >
                                            <span
                                                className={`grid size-10 place-items-center rounded-xl ring-1 transition ring-inset group-hover:scale-105 ${iconClass}`}
                                            >
                                                <Icon className="size-[1.15rem]" />
                                            </span>
                                            <h3 className="mt-4 text-base font-bold sm:text-lg">
                                                {title}
                                            </h3>
                                            <p className="mt-2 text-sm leading-5 text-slate-600">
                                                {description}
                                            </p>
                                        </article>
                                    ),
                                )}
                            </div>
                        </div>
                    </section>

                    <section
                        id="multi-oficina"
                        className="overflow-hidden bg-slate-950 py-20 text-white sm:py-24"
                    >
                        <div className="mx-auto grid max-w-7xl items-center gap-14 px-5 sm:px-8 lg:grid-cols-2">
                            <div>
                                <p className="text-sm font-bold tracking-[0.18em] text-emerald-300 uppercase">
                                    Crece sin perder visibilidad
                                </p>
                                <h2 className="mt-4 text-3xl font-bold tracking-tight sm:text-5xl">
                                    Todas tus oficinas. Una sola visión.
                                </h2>
                                <p className="mt-6 text-lg leading-8 text-slate-300">
                                    Para el propietario de dos, tres, cuatro o
                                    más oficinas que necesita saber cómo trabaja
                                    cada equipo sin mezclar responsabilidades ni
                                    información.
                                </p>
                                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                                    {[
                                        'Resultados consolidados',
                                        'Datos separados por oficina',
                                        'Responsables y permisos propios',
                                        'Comparación de desempeño',
                                    ].map((item) => (
                                        <div
                                            key={item}
                                            className="flex items-start gap-3 text-sm font-semibold text-slate-200"
                                        >
                                            <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emerald-400 text-slate-950">
                                                <Check className="size-3.5" />
                                            </span>
                                            {item}
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <div className="rounded-[2rem] border border-white/10 bg-white/5 p-6 backdrop-blur sm:p-8">
                                <div className="flex items-center gap-4 border-b border-white/10 pb-6">
                                    <span className="grid size-12 place-items-center rounded-2xl bg-emerald-400 text-slate-950">
                                        <Building2 className="size-6" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-bold tracking-widest text-emerald-300 uppercase">
                                            Organización
                                        </p>
                                        <p className="mt-1 font-bold">
                                            Grupo Contable Atlántico
                                        </p>
                                    </div>
                                </div>
                                <div className="mt-6 grid gap-3">
                                    {[
                                        [
                                            'Oficina Central',
                                            '98%',
                                            '54 clientes',
                                        ],
                                        ['Oficina Norte', '94%', '38 clientes'],
                                        ['Oficina Sur', '91%', '31 clientes'],
                                        [
                                            'Oficina Occidente',
                                            '96%',
                                            '25 clientes',
                                        ],
                                    ].map(([office, score, clients]) => (
                                        <div
                                            key={office}
                                            className="flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-white/5 px-4 py-4"
                                        >
                                            <div>
                                                <p className="text-sm font-bold">
                                                    {office}
                                                </p>
                                                <p className="mt-1 text-xs text-slate-400">
                                                    {clients}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-lg font-bold text-emerald-300">
                                                    {score}
                                                </p>
                                                <p className="text-[10px] tracking-wider text-slate-500 uppercase">
                                                    cumplimiento
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        id="inteligencia-artificial"
                        className="relative overflow-hidden border-y border-emerald-200 bg-white py-20 sm:py-24"
                    >
                        <LightSectionSparkles />
                        <div className="relative z-10 mx-auto grid max-w-7xl items-center gap-14 px-5 sm:px-8 lg:grid-cols-[1.05fr_.95fr]">
                            <div>
                                <div className="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3.5 py-2 text-xs font-bold text-emerald-800">
                                    <Sparkles className="size-3.5" />
                                    IA diseñada para el trabajo contable
                                </div>
                                <h2 className="mt-6 text-3xl font-bold tracking-tight sm:text-5xl">
                                    Un asistente que acelera el trabajo. Tú
                                    conservas el control.
                                </h2>
                                <p className="mt-6 text-lg leading-8 text-slate-600">
                                    La IA de Atlantia ayuda a encontrar
                                    pendientes, resumir expedientes, clasificar
                                    documentos y preparar borradores. No
                                    reemplaza el criterio profesional ni ejecuta
                                    decisiones sensibles por su cuenta.
                                </p>
                                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                                    {[
                                        'Resúmenes de clientes y expedientes',
                                        'Detección de pendientes y vencimientos',
                                        'Clasificación asistida de documentos',
                                        'Borradores de tareas y comunicaciones',
                                    ].map((item) => (
                                        <div
                                            key={item}
                                            className="flex items-start gap-3 text-sm font-semibold text-slate-700"
                                        >
                                            <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emerald-700 text-white">
                                                <Check className="size-3.5" />
                                            </span>
                                            {item}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-950/20 sm:p-8">
                                <div className="flex items-center gap-4">
                                    <span className="grid size-12 place-items-center rounded-2xl bg-emerald-400 text-slate-950">
                                        <Bot className="size-6" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-bold tracking-widest text-emerald-300 uppercase">
                                            IA con controles
                                        </p>
                                        <h3 className="mt-1 text-xl font-bold">
                                            Seguridad antes que automatización
                                        </h3>
                                    </div>
                                </div>
                                <div className="mt-7 flex flex-col gap-4">
                                    {[
                                        [
                                            ShieldCheck,
                                            'Datos aislados',
                                            'La IA solo accede a la organización y oficina autorizadas para la tarea.',
                                        ],
                                        [
                                            ScanSearch,
                                            'Mínimo contexto necesario',
                                            'Se envía únicamente la información indispensable y se ocultan datos cuando sea posible.',
                                        ],
                                        [
                                            History,
                                            'Aprobación y auditoría',
                                            'Las acciones sensibles requieren confirmación y quedan registradas para revisión.',
                                        ],
                                        [
                                            LockKeyhole,
                                            'Sin entrenamiento por defecto',
                                            'Los datos privados del cliente no se usarán para entrenar modelos públicos.',
                                        ],
                                    ].map(([Icon, title, description]) => {
                                        const AiSecurityIcon =
                                            Icon as typeof ShieldCheck;

                                        return (
                                            <article
                                                key={title as string}
                                                className="flex gap-4 rounded-2xl border border-white/10 bg-white/5 p-4"
                                            >
                                                <AiSecurityIcon className="mt-0.5 size-5 shrink-0 text-emerald-300" />
                                                <div>
                                                    <h4 className="text-sm font-bold">
                                                        {title as string}
                                                    </h4>
                                                    <p className="mt-1 text-xs leading-5 text-slate-400">
                                                        {description as string}
                                                    </p>
                                                </div>
                                            </article>
                                        );
                                    })}
                                </div>
                                <p className="mt-6 text-xs leading-5 text-slate-500">
                                    Las sugerencias de IA deben validarse antes
                                    de usarse en procesos fiscales, financieros
                                    o comunicaciones con clientes.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section
                        id="planes"
                        className="relative scroll-mt-18 overflow-hidden bg-[#f7f9f8] py-14 sm:py-16"
                    >
                        <LightSectionSparkles />
                        <div className="relative z-10 mx-auto max-w-7xl px-5 sm:px-8">
                            <div className="mx-auto max-w-2xl text-center">
                                <p className="text-sm font-bold tracking-[0.18em] text-emerald-700 uppercase">
                                    Planes que crecen contigo
                                </p>
                                <h2 className="mt-4 text-3xl font-bold tracking-tight sm:text-5xl">
                                    Un plan para cada etapa de crecimiento
                                </h2>
                                <p className="mt-5 text-slate-600">
                                    Elige pago mensual o ahorra dos meses con la
                                    facturación anual. Todos los precios están
                                    expresados en quetzales.
                                </p>
                                <div
                                    className="mt-8 inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm"
                                    role="group"
                                    aria-label="Periodo de facturación"
                                >
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setBillingPeriod('monthly')
                                        }
                                        aria-pressed={
                                            billingPeriod === 'monthly'
                                        }
                                        className={`rounded-lg px-5 py-2.5 text-sm font-bold transition ${billingPeriod === 'monthly' ? 'bg-emerald-700 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'}`}
                                    >
                                        Mensual
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setBillingPeriod('annual')
                                        }
                                        aria-pressed={
                                            billingPeriod === 'annual'
                                        }
                                        className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-bold transition ${billingPeriod === 'annual' ? 'bg-emerald-700 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'}`}
                                    >
                                        Anual
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[10px] font-extrabold uppercase ${billingPeriod === 'annual' ? 'bg-white/15 text-white' : 'bg-emerald-100 text-emerald-800'}`}
                                        >
                                            2 meses incluidos
                                        </span>
                                    </button>
                                </div>
                            </div>
                            <div className="mt-10 flex items-center justify-between gap-4">
                                <p className="text-sm text-slate-500">
                                    Desliza o usa las flechas para comparar los
                                    cinco planes.
                                </p>
                                <div className="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            showPlan(activePlanIndex - 1)
                                        }
                                        disabled={activePlanIndex === 0}
                                        aria-label="Ver plan anterior"
                                        className="grid size-11 place-items-center rounded-full border border-slate-300 bg-white text-slate-800 shadow-sm transition hover:border-emerald-600 hover:text-emerald-800 disabled:cursor-not-allowed disabled:opacity-35"
                                    >
                                        <ChevronLeft className="size-5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            showPlan(activePlanIndex + 1)
                                        }
                                        disabled={
                                            activePlanIndex === plans.length - 1
                                        }
                                        aria-label="Ver plan siguiente"
                                        className="grid size-11 place-items-center rounded-full border border-slate-300 bg-white text-slate-800 shadow-sm transition hover:border-emerald-600 hover:text-emerald-800 disabled:cursor-not-allowed disabled:opacity-35"
                                    >
                                        <ChevronRight className="size-5" />
                                    </button>
                                </div>
                            </div>
                            <div
                                ref={plansCarouselRef}
                                role="region"
                                aria-roledescription="carrusel"
                                aria-label="Planes de Atlantia Finance"
                                tabIndex={0}
                                onScroll={syncActivePlan}
                                onKeyDown={(event) => {
                                    if (event.key === 'ArrowLeft') {
                                        event.preventDefault();
                                        showPlan(activePlanIndex - 1);
                                    }

                                    if (event.key === 'ArrowRight') {
                                        event.preventDefault();
                                        showPlan(activePlanIndex + 1);
                                    }
                                }}
                                className="mt-5 flex snap-x snap-mandatory [scrollbar-width:none] gap-5 overflow-x-auto px-1 pt-4 pb-8 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600 [&::-webkit-scrollbar]:hidden"
                            >
                                {plans.map((plan) => (
                                    <article
                                        key={plan.name}
                                        data-plan-card
                                        aria-label={`Plan ${plan.name}`}
                                        className={`relative flex min-h-[640px] w-[88%] shrink-0 snap-start flex-col rounded-2xl border p-6 sm:w-[68%] lg:w-[calc((100%-2.5rem)/3)] ${plan.highlighted ? 'border-emerald-600 bg-emerald-700 text-white shadow-2xl shadow-emerald-950/15' : 'border-slate-200 bg-white'}`}
                                    >
                                        {plan.highlighted && (
                                            <span className="absolute -top-3 left-6 rounded-full bg-emerald-400 px-3 py-1 text-[10px] font-extrabold tracking-widest text-slate-950 uppercase">
                                                Más elegido
                                            </span>
                                        )}
                                        <p
                                            className={`text-xs font-bold tracking-wider uppercase ${plan.highlighted ? 'text-emerald-300' : 'text-emerald-700'}`}
                                        >
                                            {plan.eyebrow}
                                        </p>
                                        <h3 className="mt-3 text-2xl font-bold">
                                            {plan.name}
                                        </h3>
                                        <p
                                            className={`mt-4 min-h-18 text-sm leading-6 ${plan.highlighted ? 'text-slate-300' : 'text-slate-600'}`}
                                        >
                                            {plan.description}
                                        </p>
                                        <div className="mt-5">
                                            <p
                                                className={`text-xs font-bold uppercase ${plan.highlighted ? 'text-emerald-200' : 'text-slate-500'}`}
                                            >
                                                {plan.pricePrefix &&
                                                    `${plan.pricePrefix} `}
                                                {billingPeriod === 'monthly'
                                                    ? 'pago mensual'
                                                    : 'pago anual'}
                                            </p>
                                            <div className="mt-1 flex items-end gap-1.5">
                                                <span className="text-4xl font-black tracking-tight">
                                                    Q
                                                    {(billingPeriod ===
                                                    'monthly'
                                                        ? plan.monthlyPrice
                                                        : plan.annualPrice
                                                    ).toLocaleString('es-GT')}
                                                </span>
                                                <span
                                                    className={`pb-1 text-sm ${plan.highlighted ? 'text-slate-300' : 'text-slate-500'}`}
                                                >
                                                    /
                                                    {billingPeriod === 'monthly'
                                                        ? 'mes'
                                                        : 'año'}
                                                </span>
                                            </div>
                                            {billingPeriod === 'annual' && (
                                                <p
                                                    className={`mt-2 text-xs ${plan.highlighted ? 'text-emerald-200' : 'text-emerald-700'}`}
                                                >
                                                    Equivale a Q
                                                    {Math.round(
                                                        plan.annualPrice / 12,
                                                    ).toLocaleString('es-GT')}
                                                    /mes · Ahorras Q
                                                    {(
                                                        plan.monthlyPrice * 12 -
                                                        plan.annualPrice
                                                    ).toLocaleString('es-GT')}
                                                </p>
                                            )}
                                        </div>
                                        <ul className="mt-6 flex flex-col gap-3">
                                            {plan.features.map((feature) => (
                                                <li
                                                    key={feature}
                                                    className={`flex gap-2 text-sm ${plan.highlighted ? 'text-slate-200' : 'text-slate-600'}`}
                                                >
                                                    <Check
                                                        className={`mt-0.5 size-4 shrink-0 ${plan.highlighted ? 'text-emerald-300' : 'text-emerald-700'}`}
                                                    />
                                                    {feature}
                                                </li>
                                            ))}
                                        </ul>
                                        <Link
                                            href={register({
                                                query: {
                                                    plan: plan.code,
                                                    billing: billingPeriod,
                                                },
                                            })}
                                            className={`mt-8 rounded-xl px-4 py-3 text-center text-sm font-bold transition ${plan.highlighted ? 'bg-emerald-400 text-slate-950 hover:bg-emerald-300' : 'border border-slate-300 hover:border-emerald-600 hover:text-emerald-800'}`}
                                        >
                                            {plan.name === 'Empresarial'
                                                ? 'Solicitar cotización'
                                                : 'Elegir este plan'}
                                        </Link>
                                    </article>
                                ))}
                            </div>
                            <div
                                className="mt-1 flex justify-center gap-2"
                                role="tablist"
                                aria-label="Seleccionar plan visible"
                            >
                                {plans.map((plan, index) => (
                                    <button
                                        key={plan.code}
                                        type="button"
                                        role="tab"
                                        aria-selected={
                                            activePlanIndex === index
                                        }
                                        aria-label={`Mostrar plan ${plan.name}`}
                                        onClick={() => showPlan(index)}
                                        className={`h-2.5 rounded-full transition-all ${activePlanIndex === index ? 'w-8 bg-emerald-700' : 'w-2.5 bg-slate-300 hover:bg-slate-400'}`}
                                    />
                                ))}
                            </div>
                            <p className="mt-8 text-center text-xs leading-5 text-slate-500">
                                Precios sin impuestos. Las funciones indicadas
                                como beta o próximas se habilitarán conforme a
                                la hoja de ruta del producto. Aplican límites y
                                política de uso razonable.
                            </p>
                        </div>
                    </section>

                    <section
                        id="seguridad"
                        className="relative scroll-mt-18 overflow-hidden border-y border-slate-200 bg-white py-12 sm:py-14"
                    >
                        <LightSectionSparkles />
                        <div className="relative z-10 mx-auto grid max-w-7xl gap-8 px-5 sm:px-8 lg:grid-cols-[.8fr_1.2fr] lg:gap-10">
                            <div>
                                <span className="grid size-12 place-items-center rounded-xl bg-emerald-100 text-emerald-800">
                                    <LockKeyhole className="size-6" />
                                </span>
                                <p className="mt-5 text-sm font-bold tracking-[0.18em] text-emerald-700 uppercase">
                                    Seguridad por diseño
                                </p>
                                <h2 className="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                                    La confianza no es una función adicional.
                                </h2>
                                <p className="mt-4 max-w-xl leading-7 text-slate-600">
                                    Es parte de cada decisión técnica de
                                    Atlantia Finance, desde el inicio de sesión
                                    hasta el acceso a cada registro.
                                </p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {[
                                    [
                                        Fingerprint,
                                        'Acceso reforzado',
                                        'Autenticación multifactor y passkeys para reducir el riesgo de robo de cuentas.',
                                    ],
                                    [
                                        Network,
                                        'Aislamiento de organizaciones',
                                        'Cada consulta valida la organización, la oficina, el usuario y sus permisos.',
                                    ],
                                    [
                                        ShieldCheck,
                                        'Controles OWASP',
                                        'Desarrollo y verificación alineados con OWASP Top 10 y ASVS nivel 2.',
                                    ],
                                    [
                                        FileCheck2,
                                        'Trazabilidad',
                                        'Registro de accesos y cambios críticos para detectar y revisar actividades sensibles.',
                                    ],
                                ].map(([Icon, title, description]) => {
                                    const SecurityIcon =
                                        Icon as typeof ShieldCheck;

                                    return (
                                        <article
                                            key={title as string}
                                            className="rounded-2xl border border-slate-200 bg-[#fbfcfb] p-5"
                                        >
                                            <SecurityIcon className="size-5 text-emerald-700" />
                                            <h3 className="mt-3 font-bold">
                                                {title as string}
                                            </h3>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                {description as string}
                                            </p>
                                        </article>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    <section
                        id="preguntas"
                        className="relative scroll-mt-18 overflow-hidden bg-[#f7f9f8] px-5 py-14 sm:px-8 sm:py-16"
                    >
                        <LightSectionSparkles />
                        <div className="relative z-10 mx-auto max-w-7xl overflow-hidden rounded-[2rem] border border-white/10 bg-[#13070a] text-white shadow-[0_35px_90px_-35px_rgba(45,0,8,0.65)]">
                            <div className="absolute inset-0 bg-[radial-gradient(circle_at_85%_15%,rgba(154,32,48,0.46),transparent_34%),radial-gradient(circle_at_10%_100%,rgba(111,0,18,0.38),transparent_32%)]" />
                            <div className="absolute -top-28 right-[8%] size-72 rounded-full border border-white/8" />
                            <div className="absolute -top-12 right-[4%] size-52 rounded-full border border-white/8" />
                            <div className="relative grid gap-10 p-8 sm:p-12 lg:grid-cols-[1.18fr_.82fr] lg:items-center lg:p-14">
                                <div>
                                    <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3.5 py-2 text-[11px] font-bold tracking-[0.16em] text-rose-100 uppercase backdrop-blur">
                                        <Sparkles className="size-3.5" />
                                        El siguiente nivel para tu oficina
                                    </div>
                                    <h2 className="mt-6 max-w-3xl text-4xl leading-[1.05] font-bold tracking-[-0.04em] sm:text-5xl lg:text-6xl">
                                        Convierte una operación compleja en una
                                        ventaja clara.
                                    </h2>
                                    <p className="mt-5 max-w-2xl text-base leading-7 text-white/65 sm:text-lg">
                                        Centraliza clientes, equipo y control
                                        contable en una plataforma creada para
                                        crecer con tu firma.
                                    </p>
                                    <div className="mt-7 flex flex-wrap gap-x-6 gap-y-3 text-sm text-white/75">
                                        {[
                                            'Configuración guiada',
                                            'Seguridad por diseño',
                                            'Escala a varias oficinas',
                                        ].map((benefit) => (
                                            <span
                                                key={benefit}
                                                className="flex items-center gap-2"
                                            >
                                                <span className="grid size-5 place-items-center rounded-full bg-white/10 text-rose-200">
                                                    <Check className="size-3" />
                                                </span>
                                                {benefit}
                                            </span>
                                        ))}
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-white/12 bg-white/[0.07] p-6 shadow-2xl shadow-black/20 backdrop-blur-xl sm:p-7">
                                    <div className="flex items-center justify-between gap-4">
                                        <span className="grid size-11 place-items-center rounded-xl border border-white/10 bg-white/10 text-rose-100">
                                            <ArrowRight className="size-5" />
                                        </span>
                                        <span className="rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-1 text-[10px] font-bold tracking-wider text-emerald-200 uppercase">
                                            Listo para comenzar
                                        </span>
                                    </div>
                                    <p className="mt-8 text-sm font-semibold text-white/60">
                                        Tu espacio de trabajo
                                    </p>
                                    <h3 className="mt-2 text-2xl font-bold tracking-tight">
                                        Empieza con el plan que acompaña tu
                                        ritmo.
                                    </h3>
                                    <p className="mt-3 text-sm leading-6 text-white/55">
                                        Crea tu cuenta, elige tu plan y
                                        configura tu primera oficina paso a
                                        paso.
                                    </p>
                                    <Link
                                        href={register()}
                                        className="mt-7 flex w-full items-center justify-between rounded-xl bg-white px-5 py-4 text-sm font-bold text-[#59000d] shadow-xl shadow-black/10 transition hover:-translate-y-0.5 hover:bg-rose-50"
                                    >
                                        Crear mi cuenta
                                        <ArrowRight className="size-4" />
                                    </Link>
                                    <p className="mt-3 text-center text-[11px] text-white/40">
                                        Configuración inicial sin tarjeta
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="bg-[#050912] text-slate-400">
                    <div className="mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-14">
                        <div className="grid gap-10 border-b border-white/10 pb-10 sm:grid-cols-2 lg:grid-cols-[1.45fr_.75fr_.75fr_.75fr]">
                            <div>
                                <a
                                    href="#inicio"
                                    aria-label="Atlantia Finance, inicio"
                                    className="inline-flex"
                                >
                                    <img
                                        src="/images/atlantia-finance-logo.png"
                                        alt="Atlantia Finance"
                                        className="h-14 w-auto object-contain"
                                    />
                                </a>
                                <p className="mt-5 max-w-sm text-sm leading-6 text-slate-400">
                                    Operación, seguridad y crecimiento para
                                    firmas contables que quieren trabajar con
                                    más claridad.
                                </p>
                                <div className="mt-5 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] font-semibold text-slate-300">
                                    <span className="size-2 rounded-full bg-emerald-400" />
                                    Plataforma segura y escalable
                                </div>
                            </div>

                            <div>
                                <p className="text-xs font-bold tracking-[0.16em] text-white uppercase">
                                    Producto
                                </p>
                                <nav className="mt-5 flex flex-col gap-3 text-sm">
                                    <a
                                        href="#funciones"
                                        className="transition hover:text-white"
                                    >
                                        Funciones
                                    </a>
                                    <a
                                        href="#multi-oficina"
                                        className="transition hover:text-white"
                                    >
                                        Multi-oficina
                                    </a>
                                    <a
                                        href="#inteligencia-artificial"
                                        className="transition hover:text-white"
                                    >
                                        Inteligencia artificial
                                    </a>
                                </nav>
                            </div>

                            <div>
                                <p className="text-xs font-bold tracking-[0.16em] text-white uppercase">
                                    Explorar
                                </p>
                                <nav className="mt-5 flex flex-col gap-3 text-sm">
                                    <a
                                        href="#planes"
                                        className="transition hover:text-white"
                                    >
                                        Planes
                                    </a>
                                    <a
                                        href="#seguridad"
                                        className="transition hover:text-white"
                                    >
                                        Seguridad
                                    </a>
                                    <button
                                        type="button"
                                        onClick={() => setIsHelpChatOpen(true)}
                                        className="transition hover:text-white"
                                    >
                                        Asistente de ayuda
                                    </button>
                                </nav>
                            </div>

                            <div>
                                <p className="text-xs font-bold tracking-[0.16em] text-white uppercase">
                                    Tu cuenta
                                </p>
                                <nav className="mt-5 flex flex-col gap-3 text-sm">
                                    <Link
                                        href={login()}
                                        className="transition hover:text-white"
                                    >
                                        Ingresar
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="transition hover:text-white"
                                    >
                                        Crear una cuenta
                                    </Link>
                                    <a
                                        href="#planes"
                                        className="transition hover:text-white"
                                    >
                                        Comparar planes
                                    </a>
                                </nav>
                            </div>
                        </div>
                        <div className="flex flex-col justify-between gap-4 pt-6 text-xs sm:flex-row sm:items-center">
                            <p>
                                © {new Date().getFullYear()} Atlantia Finance.
                                Todos los derechos reservados.
                            </p>
                            <div className="flex flex-wrap gap-x-5 gap-y-2 text-slate-500">
                                <span>Guatemala · GTQ</span>
                                <span>Seguridad por diseño</span>
                                <span>Una solución de Atlantia Systems</span>
                            </div>
                        </div>
                    </div>
                </footer>

                {isHelpChatOpen && (
                    <section
                        role="dialog"
                        aria-modal="false"
                        aria-labelledby="help-chat-title"
                        className="fixed right-4 bottom-22 z-[70] flex max-h-[min(620px,calc(100vh-7rem))] w-[min(390px,calc(100vw-2rem))] flex-col overflow-hidden rounded-[1.75rem] border border-white/70 bg-white shadow-[0_30px_80px_-20px_rgba(15,23,42,0.45)] sm:right-6 sm:bottom-24"
                    >
                        <header className="relative overflow-hidden bg-[#640000] px-4 py-4 text-white">
                            <div className="absolute -top-12 -right-8 size-32 rounded-full border border-white/10" />
                            <div className="relative flex items-center gap-3">
                                <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-white/12 ring-1 ring-white/15">
                                    <Bot className="size-6" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <h2
                                        id="help-chat-title"
                                        className="text-sm font-bold"
                                    >
                                        Asistente Atlantia
                                    </h2>
                                    <p className="mt-0.5 flex items-center gap-1.5 text-xs text-white/70">
                                        <span className="size-2 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(52,211,153,0.12)]" />
                                        Orientación disponible
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={resetHelpChat}
                                    className="grid size-9 place-items-center rounded-xl text-white/70 transition hover:bg-white/10 hover:text-white"
                                    aria-label="Reiniciar conversación"
                                    title="Reiniciar conversación"
                                >
                                    <RotateCcw className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsHelpChatOpen(false)}
                                    className="grid size-9 place-items-center rounded-xl bg-white/10 text-white transition hover:bg-white/20"
                                    aria-label="Cerrar asistente"
                                >
                                    <X className="size-4" />
                                </button>
                            </div>
                        </header>

                        <div
                            ref={helpMessagesContainerRef}
                            className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto bg-[#f7f9f8] p-4"
                            aria-live="polite"
                            aria-label="Conversación con el asistente"
                        >
                            {helpMessages.map((message) => (
                                <div
                                    key={message.id}
                                    className={`max-w-[88%] rounded-2xl px-3.5 py-2.5 text-sm leading-5 shadow-sm ${message.role === 'user' ? 'ml-auto rounded-br-md bg-[#640000] text-white' : 'rounded-bl-md border border-slate-200 bg-white text-slate-700'}`}
                                >
                                    {message.content}
                                </div>
                            ))}

                            <div className="mt-1">
                                <p className="mb-2 text-[11px] font-bold tracking-[0.12em] text-slate-400 uppercase">
                                    Preguntas rápidas
                                </p>
                                <div className="flex flex-col gap-1.5">
                                    {questions.map(({ question }, index) => (
                                        <button
                                            key={question}
                                            type="button"
                                            onClick={() =>
                                                askHelpQuestion(question)
                                            }
                                            className="group flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-xs font-semibold text-slate-700 transition hover:border-[#b77b82] hover:bg-rose-50"
                                        >
                                            <span className="grid size-6 shrink-0 place-items-center rounded-lg bg-rose-100 text-[10px] font-black text-[#640000] transition group-hover:bg-[#640000] group-hover:text-white">
                                                {index + 1}
                                            </span>
                                            {question}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <form
                            className="flex gap-2 border-t border-slate-200 bg-white p-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                askHelpQuestion(helpInput);
                            }}
                        >
                            <label htmlFor="help-question" className="sr-only">
                                Escribe tu pregunta
                            </label>
                            <input
                                id="help-question"
                                value={helpInput}
                                onChange={(event) =>
                                    setHelpInput(event.target.value)
                                }
                                placeholder="Seguridad, IA, planes..."
                                className="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm transition outline-none placeholder:text-slate-400 focus:border-[#8d313d] focus:ring-3 focus:ring-rose-100"
                                autoFocus
                            />
                            <button
                                type="submit"
                                disabled={!helpInput.trim()}
                                className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#640000] text-white transition hover:bg-[#7a0a0a] disabled:cursor-not-allowed disabled:opacity-40"
                                aria-label="Enviar pregunta"
                            >
                                <Send className="size-4" />
                            </button>
                        </form>
                    </section>
                )}

                <button
                    type="button"
                    onClick={() => setIsHelpChatOpen((open) => !open)}
                    className="group fixed right-4 bottom-4 z-[71] grid size-14 place-items-center rounded-2xl border border-white/30 bg-[linear-gradient(145deg,#7b0713,#3f0008)] text-white shadow-[0_18px_45px_-12px_rgba(100,0,0,0.72)] transition hover:-translate-y-1 hover:shadow-[0_24px_55px_-12px_rgba(100,0,0,0.78)] sm:right-6 sm:bottom-6 sm:size-16"
                    aria-label={
                        isHelpChatOpen
                            ? 'Cerrar asistente Atlantia'
                            : 'Abrir asistente Atlantia'
                    }
                    aria-expanded={isHelpChatOpen}
                >
                    <span className="absolute -top-1 -right-1 size-3.5 rounded-full border-2 border-white bg-emerald-400" />
                    {isHelpChatOpen ? (
                        <X className="size-6" />
                    ) : (
                        <Bot className="size-7 transition group-hover:scale-110" />
                    )}
                    {!isHelpChatOpen && (
                        <span className="pointer-events-none absolute right-[calc(100%+0.75rem)] hidden rounded-xl bg-slate-950 px-3 py-2 text-xs font-semibold whitespace-nowrap text-white opacity-0 shadow-xl transition group-hover:opacity-100 sm:block">
                            ¿Tienes dudas?
                        </span>
                    )}
                </button>
            </div>
        </>
    );
}
