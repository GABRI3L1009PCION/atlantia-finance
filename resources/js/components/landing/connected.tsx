import {
    ArrowRight,
    BarChart3,
    BookOpen,
    Bot,
    Building2,
    FileText,
    FolderOpen,
    ReceiptText,
    UsersRound,
} from 'lucide-react';
import type {
    CSSProperties,
    PointerEvent as ReactPointerEvent,
    RefObject,
} from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    EASE,
    Reveal,
    useInView,
    usePrefersReducedMotion,
} from '@/components/landing/motion';

type FeatureKey =
    'accounting' | 'fiscal' | 'clients' | 'documents' | 'multioffice' | 'luzma';

type Feature = {
    key: FeatureKey;
    title: string;
    description: string;
    icon: typeof BookOpen;
    /** Existing section of the sales page that explains the module; none means the card does not navigate. */
    href?: string;
    /** Entrance offset (x, y) in px. */
    from: [number, number];
};

const FEATURES: Feature[] = [
    {
        key: 'accounting',
        title: 'Contabilidad',
        icon: BookOpen,
        from: [-35, 20],
        description:
            'Partida doble, libros, períodos y cierres con revisión y aprobación por rol.',
    },
    {
        key: 'fiscal',
        title: 'Fiscal',
        icon: ReceiptText,
        from: [0, 35],
        description:
            'Obligaciones, calendario fiscal, libros de IVA y facturación electrónica FEL.',
    },
    {
        key: 'clients',
        title: 'Clientes',
        icon: UsersRound,
        from: [35, 20],
        description:
            'Expedientes, responsables y un portal para compartir documentos y solicitudes.',
    },
    {
        key: 'documents',
        title: 'Documentos',
        icon: FolderOpen,
        from: [-35, 30],
        description:
            'Archivos vinculados a cada cliente y proceso, con estado y trazabilidad.',
    },
    {
        key: 'multioffice',
        title: 'Multi-oficina',
        icon: Building2,
        href: '#multi-office',
        from: [0, 40],
        description:
            'Varias sedes con datos separados, permisos propios y una vista consolidada.',
    },
    {
        key: 'luzma',
        title: 'Luzma',
        icon: Bot,
        href: '#luzma',
        from: [35, 30],
        description:
            'Tu asistente con IA dentro de la operación: resume, detecta pendientes y prepara borradores.',
    },
];

/** Commercial relations between modules (a visual story, not a technical architecture). */
const RELATIONS: Record<FeatureKey, FeatureKey[]> = {
    accounting: ['fiscal', 'documents', 'luzma'],
    fiscal: ['accounting', 'documents', 'clients', 'luzma'],
    clients: ['documents', 'accounting', 'multioffice', 'luzma'],
    documents: ['clients', 'accounting', 'fiscal', 'luzma'],
    multioffice: ['accounting', 'fiscal', 'clients', 'documents', 'luzma'],
    luzma: ['accounting', 'fiscal', 'clients', 'documents', 'multioffice'],
};

/** Links always drawn (neighbours in the grid); the rest appear only while a related module is active. */
const BASE_EDGES = new Set([
    'accounting-fiscal',
    'clients-fiscal',
    'accounting-documents',
    'documents-multioffice',
    'luzma-multioffice',
    'clients-luzma',
    'fiscal-multioffice',
]);

const EDGES: [FeatureKey, FeatureKey][] = (() => {
    const seen = new Set<string>();
    const edges: [FeatureKey, FeatureKey][] = [];
    (Object.keys(RELATIONS) as FeatureKey[]).forEach((from) =>
        RELATIONS[from].forEach((to) => {
            const id = [from, to].sort().join('-');

            if (!seen.has(id)) {
                seen.add(id);
                edges.push([from, to]);
            }
        }),
    );

    return edges;
})();

const edgeId = (a: FeatureKey, b: FeatureKey) => [a, b].sort().join('-');

/** Timeline (ms) after the section enters the viewport. */
const T = {
    cards: 350,
    step: 100,
    icons: 1000,
    texts: 1150,
    arrows: 1400,
    lines: 1550,
    decoration: 2000,
    demo: 2400,
};

type Point = { x: number; y: number };

function useCardCenters(
    containerRef: RefObject<HTMLDivElement | null>,
    cardRefs: RefObject<Partial<Record<FeatureKey, HTMLElement | null>>>,
): Partial<Record<FeatureKey, Point>> & { width?: number; height?: number } {
    const [centers, setCenters] = useState<
        Partial<Record<FeatureKey, Point>> & { width?: number; height?: number }
    >({});

    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const measure = () => {
            const box = container.getBoundingClientRect();
            const next: Partial<Record<FeatureKey, Point>> & {
                width?: number;
                height?: number;
            } = { width: box.width, height: box.height };
            FEATURES.forEach(({ key }) => {
                const card = cardRefs.current[key];

                if (card) {
                    next[key] = {
                        x: card.offsetLeft + card.offsetWidth / 2,
                        y: card.offsetTop + card.offsetHeight / 2,
                    };
                }
            });
            setCenters(next);
        };
        const observer = new ResizeObserver(measure);
        observer.observe(container);

        return () => observer.disconnect();
    }, [containerRef, cardRefs]);

    return centers;
}

function curve(a: Point, b: Point): string {
    const midX = (a.x + b.x) / 2;

    if (Math.abs(a.y - b.y) < 4) {
        return `M ${a.x} ${a.y} C ${midX} ${a.y - 36}, ${midX} ${b.y - 36}, ${b.x} ${b.y}`;
    }

    if (Math.abs(a.x - b.x) < 4) {
        return `M ${a.x} ${a.y} C ${a.x + 40} ${(a.y + b.y) / 2}, ${b.x + 40} ${(a.y + b.y) / 2}, ${b.x} ${b.y}`;
    }

    return `M ${a.x} ${a.y} C ${midX} ${a.y}, ${midX} ${b.y}, ${b.x} ${b.y}`;
}

function FeatureConnections({
    centers,
    active,
    drawn,
    reduced,
}: {
    centers: ReturnType<typeof useCardCenters>;
    active: FeatureKey | null;
    drawn: boolean;
    reduced: boolean;
}) {
    if (!centers.width || !centers.height) {
        return null;
    }

    return (
        <svg
            className="pointer-events-none absolute inset-0 z-0 hidden md:block"
            width={centers.width}
            height={centers.height}
            viewBox={`0 0 ${centers.width} ${centers.height}`}
            aria-hidden="true"
        >
            <defs>
                <linearGradient id="connected-line" x1="0" x2="1">
                    <stop offset="0" stopColor="#c9963f" />
                    <stop offset="1" stopColor="#8A0B0B" />
                </linearGradient>
            </defs>
            {EDGES.map(([a, b], index) => {
                const from = centers[a];
                const to = centers[b];

                if (!from || !to) {
                    return null;
                }

                const id = edgeId(a, b);
                const base = BASE_EDGES.has(id);
                const related =
                    active !== null &&
                    ((a === active && RELATIONS[active].includes(b)) ||
                        (b === active && RELATIONS[active].includes(a)));
                const opacity =
                    active === null
                        ? base
                            ? 0.45
                            : 0
                        : related
                          ? 0.95
                          : base
                            ? 0.18
                            : 0;

                return (
                    <path
                        key={id}
                        id={`connected-${id}`}
                        d={curve(from, to)}
                        fill="none"
                        stroke="url(#connected-line)"
                        strokeWidth={related ? 2 : 1.5}
                        strokeLinecap="round"
                        pathLength={1}
                        strokeDasharray="1"
                        style={{
                            strokeDashoffset: drawn ? 0 : 1,
                            opacity,
                            transition: `stroke-dashoffset 1200ms ${EASE} ${drawn ? index * 40 : 0}ms, opacity 350ms ease`,
                        }}
                    />
                );
            })}
            {drawn && !reduced && (
                <>
                    <circle r="3.5" fill="#D4A44C" opacity="0.85">
                        <animateMotion
                            dur="7s"
                            repeatCount="indefinite"
                            begin="1.4s"
                        >
                            <mpath href="#connected-accounting-fiscal" />
                        </animateMotion>
                    </circle>
                    <circle r="3" fill="#8A0B0B" opacity="0.7">
                        <animateMotion
                            dur="9s"
                            repeatCount="indefinite"
                            begin="2.6s"
                            keyPoints="1;0"
                            keyTimes="0;1"
                            calcMode="linear"
                        >
                            <mpath href="#connected-luzma-multioffice" />
                        </animateMotion>
                    </circle>
                </>
            )}
        </svg>
    );
}

function FeatureIcon3D({
    feature,
    visible,
    delay,
}: {
    feature: Feature;
    visible: boolean;
    delay: number;
}) {
    const Icon = feature.icon;

    return (
        <span
            className="relative block w-max [transform:translateZ(30px)]"
            style={{
                opacity: visible ? 1 : 0,
                scale: visible ? '1' : '0.75',
                rotate: visible ? 'y 0deg' : 'y -15deg',
                transition: `opacity 500ms ${EASE} ${delay}ms, scale 600ms ${EASE} ${delay}ms, rotate 600ms ${EASE} ${delay}ms`,
            }}
        >
            {feature.key === 'luzma' && (
                <span
                    className="connected-ring absolute inset-0 rounded-2xl border-2 border-[#8A0B0B]/40"
                    aria-hidden="true"
                />
            )}
            <span className="relative grid size-12 place-items-center rounded-2xl bg-[linear-gradient(145deg,#990d0d,#5b0000)] text-white shadow-[0_12px_25px_rgba(100,0,0,0.24),inset_0_1px_0_rgba(255,255,255,0.3)] transition-transform duration-500 ease-[cubic-bezier(0.16,1,0.3,1)] group-hover/card:[transform:translateY(-3px)_rotateY(6deg)_scale(1.06)] group-data-[active=true]/card:[transform:translateY(-3px)_rotateY(6deg)_scale(1.06)]">
                <Icon className="size-[22px]" aria-hidden="true" />
            </span>
        </span>
    );
}

function FeatureCard3D({
    feature,
    index,
    visible,
    state,
    related,
    tilt,
    cardRef,
    onActivate,
    onRelease,
}: {
    feature: Feature;
    index: number;
    visible: boolean;
    state: 'idle' | 'active' | 'related' | 'glow';
    related: number;
    tilt: boolean;
    cardRef: (element: HTMLElement | null) => void;
    onActivate: (key: FeatureKey, source: 'pointer' | 'focus' | 'tap') => void;
    onRelease: () => void;
}) {
    const innerRef = useRef<HTMLDivElement>(null);
    const delay = T.cards + index * T.step;
    const [x, y] = feature.from;

    const move = (event: ReactPointerEvent<HTMLElement>) => {
        const inner = innerRef.current;

        if (!inner || event.pointerType !== 'mouse') {
            return;
        }

        const rect = inner.getBoundingClientRect();
        const nx = (event.clientX - rect.left) / rect.width;
        const ny = (event.clientY - rect.top) / rect.height;
        inner.style.setProperty('--mouse-x', `${(nx * 100).toFixed(1)}%`);
        inner.style.setProperty('--mouse-y', `${(ny * 100).toFixed(1)}%`);

        if (tilt) {
            inner.style.setProperty(
                '--rx',
                `${((0.5 - ny) * 3).toFixed(2)}deg`,
            );
            inner.style.setProperty(
                '--ry',
                `${((nx - 0.5) * 4).toFixed(2)}deg`,
            );
        }
    };
    const leave = () => {
        innerRef.current?.style.setProperty('--rx', '0deg');
        innerRef.current?.style.setProperty('--ry', '0deg');
        onRelease();
    };
    const lifted = state === 'active';
    const Body = feature.href ? 'a' : 'div';

    return (
        <article
            ref={cardRef}
            className="connected-anim group/card relative [perspective:1400px]"
            data-active={lifted}
            style={{
                opacity: visible ? 1 : 0,
                transform: visible
                    ? 'none'
                    : `translate3d(${x}px, ${y}px, 0) scale(0.96)`,
                transition: `opacity 750ms ${EASE} ${delay}ms, transform 800ms ${EASE} ${delay}ms`,
            }}
            onPointerEnter={(event) =>
                event.pointerType === 'mouse' &&
                onActivate(feature.key, 'pointer')
            }
            onPointerMove={move}
            onPointerLeave={leave}
        >
            <span
                className="pointer-events-none absolute inset-x-[10%] -bottom-2 h-3 rounded-[50%] bg-[radial-gradient(closest-side,rgba(90,10,10,0.35),transparent)] blur-[6px] transition-opacity duration-500"
                style={{ opacity: lifted ? 0.9 : 0.5 }}
                aria-hidden="true"
            />
            <Body
                {...(feature.href ? { href: feature.href } : { tabIndex: 0 })}
                aria-label={
                    feature.href ? `${feature.title}: ver más` : undefined
                }
                onFocus={() => onActivate(feature.key, 'focus')}
                onBlur={onRelease}
                onClick={() => onActivate(feature.key, 'tap')}
                className="block h-full rounded-[26px] outline-none focus-visible:ring-2 focus-visible:ring-[#780606] focus-visible:ring-offset-4 focus-visible:ring-offset-[#FBF8F6] active:scale-[0.985]"
                style={{ transition: 'scale 180ms ease' }}
            >
                <div
                    ref={innerRef}
                    className="relative flex h-full flex-col rounded-[26px] border bg-white/[0.88] p-6 backdrop-blur-[16px] [transform-style:preserve-3d]"
                    style={
                        {
                            transform: `translateY(${lifted ? -8 : 0}px) rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg)) scale(${lifted ? 1.015 : 1})`,
                            transition: `transform 450ms ${EASE}, border-color 350ms ease, box-shadow 350ms ease`,
                            borderColor: lifted
                                ? 'rgba(122,7,7,0.28)'
                                : state === 'related'
                                  ? 'rgba(201,150,63,0.55)'
                                  : state === 'glow'
                                    ? 'rgba(201,150,63,0.4)'
                                    : 'rgba(115,0,0,0.10)',
                            boxShadow: lifted
                                ? '0 30px 60px -24px rgba(56,18,18,0.35), 0 0 0 4px rgba(122,7,7,0.05)'
                                : state === 'related' || state === 'glow'
                                  ? '0 18px 45px rgba(56,18,18,0.08), 0 0 0 4px rgba(212,164,76,0.12), 0 0 28px -6px rgba(212,164,76,0.45)'
                                  : '0 18px 45px rgba(56,18,18,0.08)',
                        } as CSSProperties
                    }
                >
                    <div
                        className="pointer-events-none absolute inset-0 overflow-hidden rounded-[26px]"
                        aria-hidden="true"
                    >
                        <div
                            className="absolute inset-0 transition-opacity duration-300"
                            style={{
                                opacity: lifted ? 1 : 0,
                                background:
                                    'radial-gradient(circle at var(--mouse-x, 50%) var(--mouse-y, 0%), rgba(130,10,10,0.10), transparent 38%)',
                            }}
                        />
                        {visible && (
                            <span
                                className="connected-shine absolute -inset-y-6 -left-1/3 w-1/4 bg-[linear-gradient(115deg,transparent,rgba(255,255,255,0.65),transparent)]"
                                style={{ animationDelay: `${delay + 400}ms` }}
                            />
                        )}
                    </div>

                    <FeatureIcon3D
                        feature={feature}
                        visible={visible}
                        delay={T.icons + index * 60}
                    />
                    <div
                        className="relative mt-4 flex-1"
                        style={{
                            opacity: visible ? 1 : 0,
                            transition: `opacity 600ms ${EASE} ${T.texts + index * 60}ms`,
                        }}
                    >
                        <h3
                            className="[transform:translateZ(22px)] text-lg font-bold transition-colors duration-300"
                            style={{ color: lifted ? '#720606' : '#0C1424' }}
                        >
                            {feature.title}
                        </h3>
                        <p className="mt-1.5 [transform:translateZ(12px)] pr-8 text-sm leading-6 text-[#5E6A7D]">
                            {feature.description}
                        </p>
                    </div>
                    <div className="mt-3 flex h-7 [transform:translateZ(24px)] items-center justify-between">
                        <span
                            className="text-[11px] font-semibold text-[#8a6418] transition-opacity duration-300"
                            style={{ opacity: lifted && related > 0 ? 1 : 0 }}
                            aria-hidden={!lifted}
                        >
                            {feature.key === 'multioffice' ||
                            feature.key === 'luzma'
                                ? 'Presente en todos los módulos'
                                : `Conectado con ${related} módulos`}
                        </span>
                        <span
                            className={`grid size-7 place-items-center rounded-full border transition-[background-color,border-color,color,opacity] duration-300 ${lifted ? 'border-[#780606] bg-[#780606] text-white' : 'border-[#ecdcd9] bg-white text-[#780606]'}`}
                            style={{
                                opacity: visible ? 1 : 0,
                                transitionDelay: visible
                                    ? '0ms'
                                    : `${T.arrows}ms`,
                            }}
                            aria-hidden="true"
                        >
                            <ArrowRight
                                className="size-3.5 transition-transform duration-300"
                                style={{
                                    transform: lifted
                                        ? 'translateX(2px)'
                                        : 'none',
                                }}
                            />
                        </span>
                    </div>
                </div>
            </Body>
        </article>
    );
}

function FloatingDecoration({ visible }: { visible: boolean }) {
    return (
        <div
            className="pointer-events-none absolute inset-0 hidden xl:block"
            style={{
                opacity: visible ? 1 : 0,
                transition: `opacity 900ms ${EASE} ${visible ? T.decoration : 0}ms`,
            }}
            aria-hidden="true"
        >
            <div className="connected-parallax-left absolute top-16 left-[1.5%]">
                <div
                    className="plan-float relative"
                    style={{ animationDuration: '8s' }}
                >
                    <div className="w-44 [transform:perspective(700px)_rotateY(14deg)] rounded-3xl border border-white/80 bg-[linear-gradient(150deg,rgba(255,255,255,0.9),rgba(246,232,228,0.6))] p-4 shadow-[0_30px_60px_-30px_rgba(90,10,10,0.45),inset_0_1px_0_#fff] backdrop-blur-md">
                        <div className="flex h-16 items-end gap-1.5">
                            {[35, 55, 45, 75, 95].map((height) => (
                                <span
                                    key={height}
                                    className="flex-1 rounded-t bg-[linear-gradient(180deg,#a3161c,#650000)]"
                                    style={{ height: `${height}%` }}
                                />
                            ))}
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <span className="size-7 rounded-full border-[5px] border-[#D4A44C] border-r-[#8A0B0B]" />
                            <span className="h-1.5 flex-1 rounded bg-[#ead9d5]" />
                        </div>
                    </div>
                    <span className="absolute -bottom-6 -left-4 grid size-14 place-items-center rounded-2xl bg-[linear-gradient(145deg,#990d0d,#5b0000)] text-white shadow-[0_16px_30px_-10px_rgba(100,0,0,0.5),inset_0_1px_0_rgba(255,255,255,0.3)]">
                        <BarChart3 className="size-6" />
                    </span>
                </div>
            </div>
            <div className="connected-parallax-right absolute top-10 right-[1.5%]">
                <div
                    className="plan-float relative"
                    style={{ animationDuration: '10s', animationDelay: '-3s' }}
                >
                    <svg
                        className="absolute -top-8 -left-10 size-56 text-[#D4A44C]/70"
                        viewBox="0 0 200 200"
                        fill="none"
                    >
                        <ellipse
                            cx="100"
                            cy="100"
                            rx="88"
                            ry="96"
                            stroke="currentColor"
                            strokeWidth="1.5"
                            className="connected-ring-draw"
                            pathLength={1}
                        />
                    </svg>
                    <div className="relative">
                        <div className="absolute top-3 left-4 h-36 w-28 rotate-6 rounded-2xl border border-white/80 bg-white/70 shadow-[0_20px_40px_-24px_rgba(90,10,10,0.4)]" />
                        <div className="relative h-36 w-28 -rotate-3 rounded-2xl border border-white/90 bg-[linear-gradient(160deg,#ffffff,#f7ece9)] p-3 shadow-[0_24px_50px_-24px_rgba(90,10,10,0.5)]">
                            {[80, 60, 70, 50, 65].map((width, index) => (
                                <span
                                    key={index}
                                    className="mb-2 block h-1.5 rounded bg-[#ead9d5]"
                                    style={{ width: `${width}%` }}
                                />
                            ))}
                        </div>
                        <span className="absolute -right-5 -bottom-4 grid size-14 place-items-center rounded-2xl bg-[linear-gradient(145deg,#990d0d,#5b0000)] text-white shadow-[0_16px_30px_-10px_rgba(100,0,0,0.5),inset_0_1px_0_rgba(255,255,255,0.3)]">
                            <FileText className="size-6" />
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * "Una operación conectada": the six modules as 3D cards over a light marble-like stage. Golden links draw between
 * them once they appear; hovering, focusing or tapping a module lights its related modules, Multi-oficina and Luzma
 * reach every module. A short one-time demo highlights a few modules and stops at the first interaction.
 */
export function ConnectedOperationSection() {
    const reduced = usePrefersReducedMotion();
    const [sectionRef, visible] = useInView<HTMLElement>(0.2);
    const gridRef = useRef<HTMLDivElement>(null);
    const cardRefs = useRef<Partial<Record<FeatureKey, HTMLElement | null>>>(
        {},
    );
    const centers = useCardCenters(gridRef, cardRefs);
    const [active, setActive] = useState<FeatureKey | null>(null);
    const [drawn, setDrawn] = useState(false);
    const [interacted, setInteracted] = useState(false);
    const [bubble, setBubble] = useState(false);
    const [tilt, setTilt] = useState(false);
    const tapTimer = useRef<number | null>(null);

    useEffect(() => {
        const query = window.matchMedia(
            '(min-width: 1024px) and (pointer: fine)',
        );
        const update = () => setTilt(query.matches && !reduced);
        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, [reduced]);

    useEffect(() => {
        if (!visible) {
            return;
        }

        const timers = [
            window.setTimeout(() => setDrawn(true), reduced ? 0 : T.lines),
            window.setTimeout(
                () => setBubble(true),
                T.cards + 5 * T.step + 2000,
            ),
            window.setTimeout(
                () => setBubble(false),
                T.cards + 5 * T.step + 5200,
            ),
        ];

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [visible, reduced]);

    useEffect(() => {
        if (!visible || reduced || interacted) {
            return;
        }

        const steps: [number, FeatureKey | null][] = [
            [T.demo + 100, 'accounting'],
            [T.demo + 1100, 'fiscal'],
            [T.demo + 2100, 'documents'],
            [T.demo + 3100, 'luzma'],
            [T.demo + 4300, null],
        ];
        const timers = steps.map(([at, key]) =>
            window.setTimeout(() => setActive(key), at),
        );

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [visible, reduced, interacted]);

    useEffect(() => {
        const section = sectionRef.current;

        if (!section || reduced) {
            return;
        }

        let frame = 0;
        const update = () => {
            frame = 0;
            const rect = section.getBoundingClientRect();
            const progress = Math.max(
                -1,
                Math.min(
                    1,
                    (window.innerHeight / 2 - (rect.top + rect.height / 2)) /
                        window.innerHeight,
                ),
            );
            section.style.setProperty(
                '--connected-scroll',
                progress.toFixed(3),
            );
        };
        const onScroll = () => {
            frame ||= requestAnimationFrame(update);
        };
        update();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            cancelAnimationFrame(frame);
        };
    }, [sectionRef, reduced]);

    const activate = useCallback(
        (key: FeatureKey, source: 'pointer' | 'focus' | 'tap') => {
            setInteracted(true);
            setActive(key);

            if (tapTimer.current !== null) {
                window.clearTimeout(tapTimer.current);
                tapTimer.current = null;
            }

            if (
                source === 'tap' &&
                !window.matchMedia('(pointer: fine)').matches
            ) {
                tapTimer.current = window.setTimeout(
                    () => setActive(null),
                    2500,
                );
            }
        },
        [],
    );
    const release = useCallback(() => setActive(null), []);

    const stateOf = (
        key: FeatureKey,
    ): 'idle' | 'active' | 'related' | 'glow' => {
        if (active === null) {
            return 'idle';
        }

        if (active === key) {
            return 'active';
        }

        if (active === 'luzma') {
            return 'glow';
        }

        return RELATIONS[active].includes(key) ? 'related' : 'idle';
    };

    return (
        <section
            id="funciones"
            ref={sectionRef}
            className="connected-stage relative scroll-mt-16 overflow-hidden bg-[radial-gradient(circle_at_top_right,rgba(120,6,6,0.08),transparent_40%),radial-gradient(circle_at_bottom_left,rgba(212,164,76,0.10),transparent_42%),linear-gradient(180deg,#FFFFFF,#FBF8F6_55%,#F8F2EF)] py-14 lg:py-10"
        >
            <div
                className="connected-ambient pointer-events-none absolute -inset-x-1/4 top-0 h-full opacity-60"
                aria-hidden="true"
            />
            <FloatingDecoration visible={visible} />
            <div
                className="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
                aria-hidden="true"
            >
                <svg
                    className="absolute inset-0 size-full text-[#D4A44C]/45"
                    viewBox="0 0 1440 400"
                    preserveAspectRatio="none"
                    fill="none"
                >
                    <path
                        d="M-40 380 C 300 200, 1140 200, 1480 380"
                        stroke="currentColor"
                        strokeWidth="1.2"
                        pathLength={1}
                        className="connected-ring-draw"
                    />
                    <path
                        d="M-40 400 C 360 260, 1080 260, 1480 400"
                        stroke="currentColor"
                        strokeWidth="0.8"
                        pathLength={1}
                        className="connected-ring-draw"
                    />
                </svg>
                <div className="absolute inset-x-[12%] bottom-6 h-24 rounded-[50%] bg-[radial-gradient(closest-side,rgba(90,10,10,0.10),transparent)]" />
            </div>

            <div className="relative mx-auto max-w-6xl px-5 sm:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <Reveal y={22}>
                        <p className="flex items-center justify-center gap-3 text-xs font-bold tracking-[0.2em] text-[#780606] uppercase">
                            <span className="h-px w-10 bg-[linear-gradient(90deg,transparent,#c9963f)]" />
                            Una operación conectada
                            <span className="h-px w-10 bg-[linear-gradient(90deg,#c9963f,transparent)]" />
                        </p>
                    </Reveal>
                    <Reveal delay={150} y={22}>
                        <h2 className="mt-3 text-3xl font-bold tracking-[-0.03em] text-[#0C1424] sm:text-4xl lg:text-[44px] lg:leading-[1.08]">
                            Todo lo que tu oficina necesita,{' '}
                            <span className="text-[#780606]">conectado.</span>
                        </h2>
                    </Reveal>
                    <Reveal delay={300} y={15}>
                        <p className="mx-auto mt-3 max-w-2xl text-base leading-7 text-[#5E6A7D]">
                            Menos seguimiento manual y más claridad para tu
                            equipo, desde el primer cliente hasta varias
                            oficinas.
                        </p>
                    </Reveal>
                </div>

                <div
                    ref={gridRef}
                    className="relative mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3"
                    onKeyDown={() => setInteracted(true)}
                >
                    <FeatureConnections
                        centers={centers}
                        active={active}
                        drawn={drawn}
                        reduced={reduced}
                    />
                    {FEATURES.map((feature, index) => (
                        <FeatureCard3D
                            key={feature.key}
                            feature={feature}
                            index={index}
                            visible={visible}
                            state={stateOf(feature.key)}
                            related={RELATIONS[feature.key].length}
                            tilt={tilt}
                            cardRef={(element) => {
                                cardRefs.current[feature.key] = element;
                            }}
                            onActivate={activate}
                            onRelease={release}
                        />
                    ))}
                    <p
                        role="status"
                        className="pointer-events-none absolute right-4 -bottom-3 z-20 rounded-full border border-[#ecd8b0] bg-white/95 px-3.5 py-1.5 text-xs font-semibold text-[#6b4a12] shadow-[0_10px_24px_-12px_rgba(90,60,10,0.45)] transition-[opacity,translate] duration-500"
                        style={{
                            opacity: bubble ? 1 : 0,
                            translate: bubble ? '0 0' : '0 6px',
                        }}
                    >
                        {bubble ? 'Luzma · 6 módulos conectados' : ''}
                    </p>
                </div>
            </div>
        </section>
    );
}
