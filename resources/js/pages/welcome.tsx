import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Bot,
    Check,
    Menu,
    RotateCcw,
    Send,
    Sparkles,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ConnectedOperationSection } from '@/components/landing/connected';
import { HeroProductDemo } from '@/components/landing/hero-demo';
import { EASE, useInView } from '@/components/landing/motion';
import { PricingSection } from '@/components/landing/pricing';
import type { PublicPlan } from '@/components/landing/pricing';
import {
    BenefitsBar,
    FaqSection,
    FinalCta,
    LuzmaSection,
    MultiOfficeSection,
    SecuritySection,
    TrustStrip,
} from '@/components/landing/sections';
import { dashboard, login, register } from '@/routes';

const navigation = [
    ['Funciones', '#funciones'],
    ['Multi-oficina', '#multi-office'],
    ['IA', '#luzma'],
    ['Planes', '#planes'],
    ['Seguridad', '#seguridad'],
    ['Preguntas', '#faq'],
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
        keywords: ['ia', 'inteligencia', 'decisiones', 'contables', 'luzma'],
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
    value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

function heroItem(visible: boolean, delay: number) {
    return {
        opacity: visible ? 1 : 0,
        transform: visible ? 'none' : 'translate3d(0, 18px, 0)',
        transition: `opacity 700ms ${EASE} ${delay}ms, transform 800ms ${EASE} ${delay}ms`,
    };
}

export default function Welcome({ plans }: { plans: PublicPlan[] | null }) {
    const { auth } = usePage().props;
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [isScrolled, setIsScrolled] = useState(false);
    const [heroRef, heroVisible] = useInView<HTMLElement>(0.05);
    const [helpInput, setHelpInput] = useState('');
    const [isHelpChatOpen, setIsHelpChatOpen] = useState(false);
    const [helpMessages, setHelpMessages] =
        useState<HelpMessage[]>(initialHelpMessages);
    const helpMessagesContainerRef = useRef<HTMLDivElement>(null);
    const nextHelpMessageIdRef = useRef(2);

    useEffect(() => {
        const onScroll = () => setIsScrolled(window.scrollY > 12);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

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

    return (
        <>
            <Head title="Tu oficina contable, bajo control">
                <meta
                    name="description"
                    content="Atlantia Finance conecta clientes, equipo, obligaciones y documentos en una plataforma segura con IA asistida (Luzma), lista para crecer de una a múltiples oficinas."
                />
                <meta
                    property="og:title"
                    content="Atlantia Finance · Tu oficina contable, bajo control"
                />
                <meta
                    property="og:description"
                    content="Contabilidad, fiscal, documentos y multi-oficina en una sola plataforma con IA asistida."
                />
                <meta property="og:type" content="website" />
                <meta
                    property="og:image"
                    content="/images/atlantia-finance-logo.png"
                />
            </Head>

            <div className="min-h-screen bg-[#FBF8F6] text-[#0C1424] antialiased selection:bg-[#f3d6d2]">
                <header className="sticky top-0 z-50 px-0 sm:px-4">
                    <div
                        className={`mx-auto max-w-[1500px] rounded-b-[28px] border-x border-b border-white/10 bg-[#650000]/92 backdrop-blur-xl transition-[box-shadow,background-color] duration-500 ${isScrolled ? 'bg-[#5a0000]/95 shadow-[0_18px_40px_-18px_rgba(55,0,0,0.55)]' : 'shadow-[0_8px_24px_-18px_rgba(55,0,0,0.35)]'}`}
                    >
                        <div
                            className={`mx-auto flex max-w-7xl items-center justify-between px-5 transition-[height] duration-500 sm:px-8 ${isScrolled ? 'h-16' : 'h-20'}`}
                        >
                            <a
                                href="#inicio"
                                className="flex items-center"
                                aria-label="Atlantia Finance, inicio"
                            >
                                <img
                                    src="/images/atlantia-finance-logo.png"
                                    alt="Atlantia Finance"
                                    className={`w-auto object-contain transition-[height] duration-500 ${isScrolled ? 'h-11' : 'h-13'}`}
                                />
                            </a>

                            <nav
                                className="hidden items-center gap-8 text-sm font-semibold text-white/80 lg:flex"
                                aria-label="Navegación principal"
                            >
                                {navigation.map(([label, href]) => (
                                    <a
                                        key={href}
                                        href={href}
                                        className="rounded transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                                    >
                                        {label}
                                    </a>
                                ))}
                            </nav>

                            <div className="hidden items-center gap-3 sm:flex">
                                {auth.user ? (
                                    <Link
                                        href={dashboard()}
                                        className="rounded-2xl bg-white px-5 py-2.5 text-sm font-bold text-[#650000] shadow-lg shadow-black/10 transition hover:-translate-y-0.5"
                                    >
                                        Ir al panel
                                    </Link>
                                ) : (
                                    <>
                                        <Link
                                            href={login()}
                                            className="px-3 py-2 text-sm font-semibold text-white/85 transition hover:text-white"
                                        >
                                            Ingresar
                                        </Link>
                                        <Link
                                            href={register()}
                                            className="inline-flex items-center gap-2 rounded-2xl bg-white px-5 py-2.5 text-sm font-bold text-[#650000] shadow-lg shadow-black/15 transition hover:-translate-y-0.5 hover:bg-[#fff6f4]"
                                        >
                                            Comenzar{' '}
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </>
                                )}
                            </div>

                            <button
                                type="button"
                                className="grid size-10 place-items-center rounded-xl border border-white/20 bg-white/10 text-white lg:hidden"
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
                                className="border-t border-white/10 px-5 py-5 text-white lg:hidden"
                                aria-label="Navegación móvil"
                            >
                                <div className="flex flex-col gap-4 text-sm font-semibold text-white/85">
                                    {navigation.map(([label, href]) => (
                                        <a
                                            key={href}
                                            href={href}
                                            onClick={() => setIsMenuOpen(false)}
                                        >
                                            {label}
                                        </a>
                                    ))}
                                    <div className="mt-2 grid grid-cols-2 gap-3 sm:hidden">
                                        <Link
                                            href={login()}
                                            className="rounded-xl border border-white/20 px-4 py-3 text-center"
                                        >
                                            Ingresar
                                        </Link>
                                        <Link
                                            href={register()}
                                            className="rounded-xl bg-white px-4 py-3 text-center text-[#650000]"
                                        >
                                            Comenzar
                                        </Link>
                                    </div>
                                </div>
                            </nav>
                        )}
                    </div>
                </header>

                <main>
                    <section
                        id="inicio"
                        ref={heroRef}
                        className="relative -mt-20 overflow-hidden pt-20"
                    >
                        <div
                            className="pointer-events-none absolute inset-0"
                            aria-hidden="true"
                        >
                            <div className="absolute inset-0 bg-[radial-gradient(circle_at_78%_35%,rgba(138,11,11,0.13),transparent_42%),radial-gradient(circle_at_8%_12%,rgba(212,164,76,0.12),transparent_30%),linear-gradient(180deg,#FBF8F6,#f8f1ee)]" />
                            <div className="absolute top-24 right-[6%] h-[420px] w-[520px] rounded-full bg-[#f1dcd7] opacity-60 blur-3xl" />
                            <div className="absolute bottom-0 left-[30%] h-40 w-[600px] rounded-full bg-[#f6e7d6] opacity-50 blur-3xl" />
                        </div>
                        <div className="relative mx-auto grid max-w-7xl items-center gap-12 px-5 pt-12 pb-20 sm:px-8 xl:min-h-[86vh] xl:grid-cols-[minmax(0,45fr)_minmax(0,55fr)] xl:gap-8 xl:py-16">
                            <div>
                                <span
                                    className="inline-flex items-center gap-2 rounded-full border border-[#f0dcd8] bg-white/80 px-3.5 py-2 text-xs font-bold text-[#780606] shadow-sm"
                                    style={heroItem(heroVisible, 0)}
                                >
                                    <Sparkles className="size-3.5" />
                                    Construido para la operación contable
                                    moderna
                                </span>
                                <h1
                                    className="mt-7 text-[44px] leading-[1.02] font-extrabold tracking-[-0.045em] text-[#0C1424] sm:text-6xl xl:text-[66px] 2xl:text-[74px]"
                                    style={heroItem(heroVisible, 80)}
                                >
                                    Tu oficina contable,{' '}
                                    <span className="text-[#780606]">
                                        bajo control.
                                    </span>
                                </h1>
                                <p
                                    className="mt-6 max-w-[34rem] text-lg leading-8 text-[#26354D]/85 sm:text-xl"
                                    style={heroItem(heroVisible, 160)}
                                >
                                    Atlantia Finance conecta clientes, equipo,
                                    obligaciones y documentos en una plataforma
                                    segura con IA asistida, lista para crecer de
                                    una a múltiples oficinas.
                                </p>
                                <div
                                    className="mt-9 flex flex-col gap-3 sm:flex-row"
                                    style={heroItem(heroVisible, 240)}
                                >
                                    <Link
                                        href={register()}
                                        className="inline-flex items-center justify-center gap-2 rounded-2xl bg-[linear-gradient(180deg,#8A0B0B,#650000)] px-7 py-4 text-sm font-bold text-white shadow-[0_2px_4px_rgba(55,0,0,0.2),0_16px_32px_-12px_rgba(101,0,0,0.6)] transition duration-300 hover:-translate-y-0.5 hover:scale-[1.02] hover:shadow-[0_4px_8px_rgba(55,0,0,0.2),0_22px_40px_-12px_rgba(101,0,0,0.7)]"
                                    >
                                        Comenzar ahora{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                    <a
                                        href="#planes"
                                        className="inline-flex items-center justify-center rounded-2xl border border-[#ead9d6] bg-white px-7 py-4 text-sm font-bold text-[#0C1424] transition duration-300 hover:border-[#d9b9b4] hover:bg-[#fdf3f1]"
                                    >
                                        Conocer los planes
                                    </a>
                                </div>
                                <ul
                                    className="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm font-medium text-[#5A6780]"
                                    style={heroItem(heroVisible, 320)}
                                >
                                    {[
                                        'Sin tarjeta para comenzar',
                                        'Configuración guiada',
                                        'Soporte en español',
                                    ].map((item) => (
                                        <li
                                            key={item}
                                            className="flex items-center gap-2"
                                        >
                                            <Check className="size-4 text-[#780606]" />
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="mx-auto w-full max-w-[680px] pr-6 sm:pr-12 xl:pr-24">
                                <HeroProductDemo
                                    areaRef={heroRef}
                                    started={heroVisible}
                                />
                            </div>
                        </div>
                    </section>

                    <BenefitsBar />
                    <TrustStrip />
                    <ConnectedOperationSection />
                    <MultiOfficeSection />
                    <LuzmaSection />
                    <SecuritySection />
                    <PricingSection plans={plans} />
                    <FaqSection />
                    <FinalCta onContact={() => setIsHelpChatOpen(true)} />
                </main>

                <footer className="bg-[linear-gradient(180deg,#2a0000,#1a0000)] text-white/60">
                    <div className="mx-auto max-w-7xl px-5 py-14 sm:px-8">
                        <div className="grid gap-10 border-b border-white/10 pb-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(4,.7fr)]">
                            <div>
                                <img
                                    src="/images/atlantia-finance-logo.png"
                                    alt="Atlantia Finance"
                                    loading="lazy"
                                    className="h-14 w-auto object-contain"
                                />
                                <p className="mt-5 max-w-sm text-sm leading-6">
                                    Operación, seguridad y crecimiento para
                                    firmas contables que quieren trabajar con
                                    más claridad.
                                </p>
                            </div>
                            {[
                                [
                                    'Producto',
                                    [
                                        ['Funciones', '#funciones'],
                                        ['Multi-oficina', '#multi-office'],
                                        ['Luzma', '#luzma'],
                                    ],
                                ],
                                [
                                    'Plataforma',
                                    [
                                        ['Planes', '#planes'],
                                        ['Seguridad', '#seguridad'],
                                        ['Preguntas', '#faq'],
                                    ],
                                ],
                            ].map(([title, links]) => (
                                <div key={title as string}>
                                    <p className="text-xs font-bold tracking-[0.16em] text-white uppercase">
                                        {title as string}
                                    </p>
                                    <nav className="mt-5 flex flex-col gap-3 text-sm">
                                        {(links as string[][]).map(
                                            ([label, href]) => (
                                                <a
                                                    key={href}
                                                    href={href}
                                                    className="transition hover:text-white"
                                                >
                                                    {label}
                                                </a>
                                            ),
                                        )}
                                    </nav>
                                </div>
                            ))}
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
                                </nav>
                            </div>
                            <div>
                                <p className="text-xs font-bold tracking-[0.16em] text-white uppercase">
                                    Soporte
                                </p>
                                <nav className="mt-5 flex flex-col gap-3 text-sm">
                                    <button
                                        type="button"
                                        onClick={() => setIsHelpChatOpen(true)}
                                        className="text-left transition hover:text-white"
                                    >
                                        Asistente de ayuda
                                    </button>
                                    <span>Guatemala · GTQ</span>
                                </nav>
                            </div>
                        </div>
                        <div className="flex flex-col justify-between gap-4 pt-6 text-xs sm:flex-row sm:items-center">
                            <p>
                                © {new Date().getFullYear()} Atlantia Finance.
                                Todos los derechos reservados.
                            </p>
                            <p className="text-white/40">
                                Una solución de Atlantia Systems
                            </p>
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
                        <header className="relative overflow-hidden bg-[#650000] px-4 py-4 text-white">
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
                                    className={`max-w-[88%] rounded-2xl px-3.5 py-2.5 text-sm leading-5 shadow-sm ${message.role === 'user' ? 'ml-auto rounded-br-md bg-[#650000] text-white' : 'rounded-bl-md border border-slate-200 bg-white text-slate-700'}`}
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
                                            <span className="grid size-6 shrink-0 place-items-center rounded-lg bg-rose-100 text-[10px] font-black text-[#650000] transition group-hover:bg-[#650000] group-hover:text-white">
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
                                className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#650000] text-white transition hover:bg-[#7a0a0a] disabled:cursor-not-allowed disabled:opacity-40"
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
