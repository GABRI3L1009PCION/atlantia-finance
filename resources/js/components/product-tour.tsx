import { router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Compass,
    Pause,
    Play,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { CSSProperties } from 'react';
import { createPortal } from 'react-dom';

export type ProductTourName = 'firm' | 'company';

type TourStep = {
    /** `data-tour` value of the element the step points at; none for a centered card. */
    target?: string;
    title: string;
    body: string;
};

/** How long each step stays on screen before the tour moves on by itself. */
const STEP_MS = 7000;

const START_EVENT = 'atlantia:product-tour';

const CARD_WIDTH = 340;

const GAP = 14;

/** Starts the guided tour of the current workspace again, from the help menu. */
export function startProductTour(): void {
    window.dispatchEvent(new Event(START_EVENT));
}

function firmSteps(): TourStep[] {
    return [
        {
            target: 'search',
            title: 'Buscador',
            body: 'Encuentra cualquier empresa cliente, oficina o colaborador escribiendo su nombre o NIT. Atajo: Ctrl K.',
        },
        {
            target: 'group:Mi oficina',
            title: 'Mi oficina',
            body: 'Inicio con el resumen de la firma, Clientes (las empresas a las que les llevas la contabilidad; desde aquí creas una nueva) y tu Equipo.',
        },
        {
            target: 'group:Dirección',
            title: 'Dirección',
            body: 'El panel ejecutivo, tus oficinas y Empresas clientes: ahí entras a la contabilidad de cada cliente.',
        },
        {
            target: 'group:Administración',
            title: 'Administración',
            body: 'Oficinas, equipo y Empresas: crea clientes, asígnales responsables y entra a su contabilidad.',
        },
        {
            target: 'group:Empresas',
            title: 'Tus empresas',
            body: 'Las empresas a tu cargo. En Contabilidad de empresas entras a los libros, ventas, compras y reportes de cada una.',
        },
        {
            target: 'group:Usuarios',
            title: 'Usuarios',
            body: 'Colaboradores, roles y permisos, y los accesos de tus clientes al portal.',
        },
        {
            target: 'group:Trabajo',
            title: 'Trabajo',
            body: 'Tareas del equipo, obligaciones fiscales con sus vencimientos, facturación y la contabilidad de cada cliente.',
        },
        {
            target: 'group:Mi trabajo',
            title: 'Mi trabajo',
            body: 'Tus tareas, revisiones y cierres pendientes, ordenados por vencimiento.',
        },
        {
            target: 'group:Fiscal',
            title: 'Fiscal',
            body: 'Declaraciones y obligaciones ante la SAT de tus empresas clientes.',
        },
        {
            target: 'group:Control',
            title: 'Control',
            body: 'Auditoría de quién hizo cada cambio, respaldos y seguridad de la cuenta.',
        },
        {
            target: 'group:Cuenta',
            title: 'Cuenta',
            body: 'Tu plan, la suscripción y los datos de la firma.',
        },
        {
            target: 'luzma',
            title: 'Luzma, tu asistente',
            body: 'Pregúntale cifras, comparaciones o impuestos, o pídele que prepare asientos y facturas. Nada se registra sin tu confirmación.',
        },
        {
            target: 'notifications',
            title: 'Alertas',
            body: 'Vencimientos, tareas asignadas y avisos importantes de tus clientes.',
        },
        {
            target: 'messages',
            title: 'Comentarios',
            body: 'Los mensajes de tu equipo sobre tareas y empresas.',
        },
        {
            target: 'plan',
            title: 'Tu firma y tu plan',
            body: 'El plan contratado. Si trabajas con varias firmas, aquí cambias de una a otra.',
        },
        {
            target: 'help',
            title: 'Ayuda',
            body: 'Desde aquí puedes volver a ver este recorrido cuando quieras.',
        },
    ];
}

function companySteps(): TourStep[] {
    return [
        {
            target: 'company-select',
            title: 'Empresa en la que trabajas',
            body: 'Todo lo que ves y registras es de esta empresa. Cámbiala aquí sin salir de la pantalla.',
        },
        {
            target: 'period',
            title: 'Mes de trabajo',
            body: 'El período contable que estás viendo.',
        },
        {
            target: 'new-transaction',
            title: 'Registro rápido',
            body: 'Una venta, una compra o un asiento contable en un clic.',
        },
        {
            target: 'group:company-home',
            title: 'Inicio de la empresa',
            body: 'Ingresos, gastos, utilidad, disponibilidad y lo que requiere atención este mes.',
        },
        {
            target: 'group:Contabilidad',
            title: 'Contabilidad',
            body: 'Libro diario, mayor, balance de comprobación, plan de cuentas y períodos. Lo primero es abrir el período del mes.',
        },
        {
            target: 'group:Operaciones',
            title: 'Operaciones',
            body: 'Facturación electrónica FEL, ventas, clientes, compras, proveedores y cuentas por cobrar y por pagar.',
        },
        {
            target: 'group:Contabilidad avanzada',
            title: 'Contabilidad avanzada',
            body: 'Inventario, activos fijos, nómina, proyectos, presupuestos y el cierre anual con sus libros legales.',
        },
        {
            target: 'group:Finanzas',
            title: 'Finanzas',
            body: 'Bancos, conciliación bancaria y caja.',
        },
        {
            target: 'group:Sistema',
            title: 'Sistema',
            body: 'Reportes y estados financieros, libros de IVA y los parámetros fiscales de la empresa (configúralos al empezar).',
        },
        {
            target: 'luzma',
            title: 'Luzma conoce esta empresa',
            body: 'Pregúntale “¿cuánto IVA pago este mes?” o “¿por qué bajó la utilidad?”, con las cifras reales de esta empresa.',
        },
        {
            target: 'notifications',
            title: 'Alertas',
            body: 'Vencimientos y pendientes de esta y de tus demás empresas.',
        },
        {
            target: 'help',
            title: 'Ayuda',
            body: 'Desde aquí puedes volver a ver este recorrido cuando quieras.',
        },
    ];
}

function intro(tour: ProductTourName, companyName?: string): TourStep {
    return tour === 'company'
        ? {
              title: `Recorrido por ${companyName ?? 'la empresa'}`,
              body: 'Te mostramos en un minuto dónde está la contabilidad, las ventas, las compras y los reportes de esta empresa.',
          }
        : {
              title: 'Bienvenido a Atlantia Finance',
              body: 'Te mostramos en un minuto dónde está cada cosa de tu firma: clientes, equipo, obligaciones fiscales y Luzma.',
          };
}

function closing(tour: ProductTourName): TourStep {
    return tour === 'company'
        ? {
              title: '¡Listo para registrar!',
              body: 'Siguiente paso: abre el período del mes en Contabilidad → Períodos Contables y configura los parámetros fiscales.',
          }
        : {
              title: '¡Ya conoces lo principal!',
              body: 'Empieza creando tu primer cliente en Mi oficina → Clientes. Si tienes dudas, pregúntale a Luzma.',
          };
}

function find(target: string): HTMLElement | null {
    const element = document.querySelector<HTMLElement>(
        `[data-tour="${CSS.escape(target)}"]`,
    );

    if (!element) {
        return null;
    }

    const rect = element.getBoundingClientRect();

    // Hidden on this screen size, or outside of it (the sidebar closed on a phone).
    return rect.width > 0 &&
        rect.height > 0 &&
        rect.right > 0 &&
        rect.left < window.innerWidth
        ? element
        : null;
}

/** Where the card goes: beside the sidebar, or under (or over) a header element. */
function placement(rect: DOMRect | null, cardHeight: number): CSSProperties {
    const width = Math.min(CARD_WIDTH, window.innerWidth - 32);

    if (!rect) {
        return {
            width,
            left: (window.innerWidth - width) / 2,
            top: Math.max(16, (window.innerHeight - cardHeight) / 2),
        };
    }

    const clampTop = (top: number) =>
        Math.min(Math.max(16, top), window.innerHeight - cardHeight - 16);
    const clampLeft = (left: number) =>
        Math.min(Math.max(16, left), window.innerWidth - width - 16);

    if (
        rect.left < window.innerWidth * 0.3 &&
        rect.right + GAP + width <= window.innerWidth - 16
    ) {
        return { width, left: rect.right + GAP, top: clampTop(rect.top) };
    }

    const left = clampLeft(rect.left + rect.width / 2 - width / 2);

    if (rect.bottom + GAP + cardHeight <= window.innerHeight - 16) {
        return { width, left, top: rect.bottom + GAP };
    }

    return { width, left, top: clampTop(rect.top - GAP - cardHeight) };
}

/**
 * Guided tour of a workspace. It is offered once to each user: accepting it walks through the screen by itself, step by
 * step, and skipping it is remembered just the same. The help menu starts it again.
 */
export function ProductTour({
    tour,
    companyName,
}: {
    tour: ProductTourName;
    companyName?: string;
}) {
    const page = usePage();
    const seen = (page.props.productTours as string[] | undefined) ?? [];
    const alreadySeen = seen.includes(tour);
    const [phase, setPhase] = useState<'idle' | 'welcome' | 'running'>('idle');
    const [steps, setSteps] = useState<TourStep[]>([]);
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const [measured, setMeasured] = useState<{
        target: string;
        rect: DOMRect | null;
    } | null>(null);
    const [cardHeight, setCardHeight] = useState(220);
    const cardRef = useRef<HTMLDivElement>(null);
    const answered = useRef(alreadySeen);

    const record = useCallback(
        (status: 'completed' | 'skipped') => {
            if (answered.current) {
                return;
            }

            answered.current = true;
            router.post(
                `/settings/product-tours/${tour}`,
                { status },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['productTours'],
                },
            );
        },
        [tour],
    );

    const begin = useCallback(() => {
        const list = tour === 'company' ? companySteps() : firmSteps();
        setSteps([
            ...list.filter((step) => !step.target || find(step.target)),
            closing(tour),
        ]);
        setIndex(0);
        setPaused(false);
        setPhase('running');
    }, [tour]);

    const finish = useCallback(
        (status: 'completed' | 'skipped') => {
            setPhase('idle');
            setMeasured(null);
            record(status);
        },
        [record],
    );

    // Offered once, after the page settles; the help menu starts it at any time.
    useEffect(() => {
        if (alreadySeen || answered.current) {
            return;
        }

        const timer = window.setTimeout(() => setPhase('welcome'), 900);

        return () => window.clearTimeout(timer);
    }, [alreadySeen]);

    useEffect(() => {
        window.addEventListener(START_EVENT, begin);

        return () => window.removeEventListener(START_EVENT, begin);
    }, [begin]);

    const step = phase === 'running' ? steps[index] : undefined;
    const rect =
        step?.target && measured?.target === step.target ? measured.rect : null;

    // Follows the element of the step while the page scrolls or the window changes size.
    useEffect(() => {
        const target = step?.target;

        if (!target) {
            return;
        }

        const element = find(target);
        element?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        let frame = 0;
        const measure = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() =>
                setMeasured({
                    target,
                    rect: find(target)?.getBoundingClientRect() ?? null,
                }),
            );
        };
        measure();
        window.addEventListener('resize', measure);
        window.addEventListener('scroll', measure, true);

        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('resize', measure);
            window.removeEventListener('scroll', measure, true);
        };
    }, [step]);

    useLayoutEffect(() => {
        if (cardRef.current) {
            setCardHeight(cardRef.current.offsetHeight);
        }
    }, [phase, index, measured]);

    const next = useCallback(() => {
        if (index >= steps.length - 1) {
            finish('completed');
        } else {
            setIndex(index + 1);
        }
    }, [finish, index, steps.length]);

    useEffect(() => {
        if (phase === 'idle') {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                finish('skipped');
            } else if (phase === 'running' && event.key === 'ArrowRight') {
                next();
            } else if (phase === 'running' && event.key === 'ArrowLeft') {
                setIndex((current) => Math.max(0, current - 1));
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [finish, next, phase]);

    if (phase === 'idle' || typeof document === 'undefined') {
        return null;
    }

    const shown = phase === 'welcome' ? intro(tour, companyName) : step;

    if (!shown) {
        return null;
    }

    const isLast = phase === 'running' && index === steps.length - 1;
    const running = phase === 'running' && !paused;
    const spotlight = rect
        ? {
              top: rect.top - 6,
              left: rect.left - 6,
              width: rect.width + 12,
              height: rect.height + 12,
          }
        : null;

    return createPortal(
        <div
            className="fixed inset-0 z-[80]"
            role="dialog"
            aria-modal="true"
            aria-label="Recorrido guiado"
        >
            {spotlight ? (
                <div
                    className="pointer-events-none fixed rounded-xl ring-2 ring-[#c7972d] transition-all duration-300 ease-out"
                    style={{
                        ...spotlight,
                        boxShadow: '0 0 0 9999px rgba(20, 6, 8, 0.58)',
                    }}
                />
            ) : (
                <div className="fixed inset-0 bg-[rgba(20,6,8,0.58)]" />
            )}

            <div
                ref={cardRef}
                className="fixed rounded-2xl border border-[#ead9cf] bg-white p-5 text-[#172033] shadow-2xl transition-all duration-300 ease-out"
                style={placement(rect, cardHeight)}
            >
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <span className="grid size-8 place-items-center rounded-lg bg-[#730009]/10 text-[#730009]">
                            <Compass className="size-4" />
                        </span>
                        <span className="text-xs font-semibold text-[#8a5b32]">
                            {phase === 'welcome'
                                ? 'Recorrido guiado'
                                : `Paso ${index + 1} de ${steps.length}`}
                        </span>
                    </div>
                    <button
                        type="button"
                        aria-label="Omitir recorrido"
                        onClick={() => finish('skipped')}
                        className="grid size-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                    >
                        <X className="size-4" />
                    </button>
                </div>

                <h2 className="mt-3 text-base font-bold">{shown.title}</h2>
                <p className="mt-1.5 text-sm leading-relaxed text-[#4a5568]">
                    {shown.body}
                </p>

                {phase === 'welcome' ? (
                    <>
                        <p className="mt-3 text-xs text-slate-500">
                            Avanza solo; puedes pausarlo o volver a verlo desde
                            el botón de ayuda.
                        </p>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => finish('skipped')}
                                className="h-9 rounded-xl border border-[#ded8d4] px-4 text-sm font-semibold text-[#172033] transition hover:bg-[#f7f3f1]"
                            >
                                Omitir recorrido
                            </button>
                            <button
                                type="button"
                                autoFocus
                                onClick={begin}
                                className="h-9 rounded-xl bg-[#730009] px-4 text-sm font-semibold text-white shadow-sm transition hover:brightness-110"
                            >
                                Comenzar recorrido
                            </button>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="mt-4 h-1 overflow-hidden rounded-full bg-[#f1e9e4]">
                            <div
                                key={index}
                                className="h-full origin-left rounded-full bg-gradient-to-r from-[#9a6a19] to-[#c7972d]"
                                style={{
                                    animation: `atlantia-tour-progress ${STEP_MS}ms linear forwards`,
                                    animationPlayState: running
                                        ? 'running'
                                        : 'paused',
                                }}
                                onAnimationEnd={next}
                            />
                        </div>
                        <div className="mt-4 flex items-center justify-between gap-2">
                            <button
                                type="button"
                                onClick={() => finish('skipped')}
                                className="text-xs font-semibold text-slate-500 underline-offset-2 hover:underline"
                            >
                                Omitir recorrido
                            </button>
                            <div className="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    aria-label="Paso anterior"
                                    disabled={index === 0}
                                    onClick={() =>
                                        setIndex(Math.max(0, index - 1))
                                    }
                                    className="grid size-9 place-items-center rounded-xl border border-[#ded8d4] text-[#172033] transition hover:bg-[#f7f3f1] disabled:opacity-40"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    aria-label={
                                        paused
                                            ? 'Continuar recorrido'
                                            : 'Pausar recorrido'
                                    }
                                    onClick={() => setPaused(!paused)}
                                    className="grid size-9 place-items-center rounded-xl border border-[#ded8d4] text-[#172033] transition hover:bg-[#f7f3f1]"
                                >
                                    {paused ? (
                                        <Play className="size-4" />
                                    ) : (
                                        <Pause className="size-4" />
                                    )}
                                </button>
                                <button
                                    type="button"
                                    onClick={next}
                                    className="flex h-9 items-center gap-1 rounded-xl bg-[#730009] px-3 text-sm font-semibold text-white shadow-sm transition hover:brightness-110"
                                >
                                    {isLast ? 'Terminar' : 'Siguiente'}
                                    {!isLast && (
                                        <ChevronRight className="size-4" />
                                    )}
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </div>,
        document.body,
    );
}
