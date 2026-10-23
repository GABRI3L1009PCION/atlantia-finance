import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    Building2,
    ChevronLeft,
    ChevronRight,
    Crown,
    Gem,
    Info,
    Network,
    TrendingUp,
    UserRound,
} from 'lucide-react';
import type { CSSProperties, PointerEvent as ReactPointerEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import {
    EASE,
    Reveal,
    useInView,
    usePrefersReducedMotion,
} from '@/components/landing/motion';
import { register } from '@/routes';

export type PublicPlan = {
    code: string;
    name: string;
    tagline: string | null;
    description: string;
    monthly_price: number;
    annual_price: number;
    currency: string;
    months_included: number;
    annual_savings: number;
    recommended: boolean;
    price_prefix: string | null;
    cta_label: string | null;
    contact_only: boolean;
    features: { text: string; hint: string | null }[];
};

type Billing = 'monthly' | 'annual';

/** Same icon family for every plan; unknown plans fall back to the growth icon. */
const PLAN_ICONS: Record<string, typeof UserRound> = {
    essential: UserRound,
    professional: BarChart3,
    multi_office: Building2,
    corporate: Network,
    enterprise: Gem,
};

/** Entrance timeline (ms) once the section enters the viewport. */
const T = {
    cards: 500,
    badge: 950,
    prices: 1050,
    features: 1200,
    buttons: 1800,
};

function formatQ(value: number): string {
    return `Q${Math.round(value).toLocaleString('en-US')}`;
}

/** Counts up the first time it starts, then glides from the previous amount whenever the value changes. */
function AnimatedPrice({ value, start }: { value: number; start: boolean }) {
    const reduced = usePrefersReducedMotion();
    const [shown, setShown] = useState(0);
    const shownRef = useRef(0);

    useEffect(() => {
        if (!start) {
            return;
        }

        const from = shownRef.current;
        let frame = 0;
        let begin = 0;
        const tick = (now: number) => {
            begin ||= now;
            const progress = reduced ? 1 : Math.min(1, (now - begin) / 1000);
            const next =
                from + (value - from) * (1 - Math.pow(1 - progress, 3));
            shownRef.current = next;
            setShown(next);

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        };
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [value, start, reduced]);

    return (
        <span className="tabular-nums" aria-hidden="true">
            {formatQ(shown)}
        </span>
    );
}

function AnimatedFeatureList({
    features,
    show,
    dark,
}: {
    features: PublicPlan['features'];
    show: boolean;
    dark: boolean;
}) {
    return (
        <ul className="mt-3 flex flex-col gap-1 [@media(max-height:780px)]:mt-2 [@media(max-height:780px)]:gap-0.5">
            {features.map((feature, index) => {
                const delay = show ? `${index * 70}ms` : '0ms';

                return (
                    <li
                        key={feature.text}
                        className={`flex items-start gap-2.5 text-[13px] leading-[18px] ${dark ? 'text-white/90' : 'text-[#26354D]'}`}
                    >
                        <span
                            className={`plan-anim mt-px grid size-[18px] shrink-0 place-items-center rounded-full ${dark ? 'bg-[#D4A44C] text-[#450000]' : 'bg-[#8A0B0B] text-white'}`}
                            style={{
                                transform: show ? 'scale(1)' : 'scale(0)',
                                opacity: show ? 1 : 0,
                                transition: `transform 450ms ${EASE} ${delay}, opacity 300ms ${delay}`,
                            }}
                        >
                            <svg
                                viewBox="0 0 12 12"
                                className="size-2.5"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2.5 6.2 5 8.5l4.5-5"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.8"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                />
                            </svg>
                        </span>
                        <span
                            className="plan-anim flex-1"
                            style={{
                                transform: show ? 'none' : 'translateX(-6px)',
                                opacity: show ? 1 : 0,
                                transition: `transform 450ms ${EASE} ${delay}, opacity 400ms ${delay}`,
                            }}
                        >
                            {feature.text}
                            {feature.hint && (
                                <span className="group/hint relative ml-1 inline-flex align-middle">
                                    <button
                                        type="button"
                                        aria-label={feature.hint}
                                        className={`rounded-full focus-visible:outline-2 focus-visible:outline-[#D4A44C] ${dark ? 'text-white/60' : 'text-[#5A6780]'}`}
                                    >
                                        <Info className="size-3.5" />
                                    </button>
                                    <span
                                        role="tooltip"
                                        className="pointer-events-none absolute bottom-[calc(100%+6px)] left-1/2 z-50 w-56 -translate-x-1/2 rounded-lg bg-[#0C1424] px-3 py-2 text-[11px] leading-4 font-medium text-white opacity-0 shadow-xl transition group-focus-within/hint:opacity-100 group-hover/hint:opacity-100"
                                    >
                                        {feature.hint}
                                    </span>
                                </span>
                            )}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

function PlanBadge({ show }: { show: boolean }) {
    return (
        <span
            className="plan-anim absolute -top-4 left-1/2 z-20 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-[#e9c97f] bg-[linear-gradient(180deg,#fff8e6,#f3dca6)] px-4 py-1.5 text-[11px] font-extrabold tracking-[0.12em] whitespace-nowrap text-[#5a0000] uppercase shadow-[0_8px_20px_-8px_rgba(120,70,0,0.55)]"
            style={{
                opacity: show ? 1 : 0,
                translate: show ? '-50% 0' : '-50% -8px',
                scale: show ? '1' : '0.85',
                transition: `opacity 500ms ${EASE} ${show ? T.badge : 0}ms, translate 500ms ${EASE} ${show ? T.badge : 0}ms, scale 500ms ${EASE} ${show ? T.badge : 0}ms`,
            }}
        >
            <Crown className="size-3.5 text-[#b07a1c]" /> Más elegido
        </span>
    );
}

function PlanPedestal({
    center,
    recommended,
}: {
    center: boolean;
    recommended: boolean;
}) {
    const top =
        recommended && center
            ? 'bg-[linear-gradient(180deg,#9c1616,#5e0000_55%,#3a0000)] border-t-2 border-[#e6c27a]'
            : 'bg-[linear-gradient(180deg,#ffffff,#efe4e1_60%,#dccbc7)] border-t border-white';

    return (
        <div
            className="plan-anim pointer-events-none relative mx-auto -mt-3 h-10 w-[108%] -translate-x-[3.7%]"
            style={{
                opacity: center ? 1 : 0.75,
                transition: `opacity 600ms ${EASE}`,
            }}
            aria-hidden="true"
        >
            <div
                className={`absolute inset-x-[4%] top-1 h-7 overflow-hidden rounded-[50%/90%] shadow-[0_24px_40px_-18px_rgba(55,0,0,0.45)] ${top}`}
            >
                {center && (
                    <span className="plan-shine absolute inset-y-0 -left-[40%] w-[35%] bg-[linear-gradient(100deg,transparent,rgba(255,255,255,0.55),transparent)]" />
                )}
            </div>
            <div
                className={`absolute inset-x-[14%] top-6 h-5 rounded-full blur-xl transition-opacity duration-500 group-hover:opacity-75 ${recommended && center ? 'bg-[#D4A44C]/60' : 'bg-[#780606]/30'}`}
                style={{ opacity: center ? 0.55 : 0.35 }}
            />
        </div>
    );
}

/** One plan as a physical card: cursor tilt, inner depth layers, light that follows the pointer. */
function PlanCard3D({
    plan,
    billing,
    position,
    show,
    tilt,
}: {
    plan: PublicPlan;
    billing: Billing;
    position: 'left' | 'center' | 'right' | 'hidden';
    show: boolean;
    tilt: boolean;
}) {
    const cardRef = useRef<HTMLElement>(null);
    const dark = plan.recommended;
    const Icon = PLAN_ICONS[plan.code] ?? TrendingUp;
    const price =
        billing === 'monthly' ? plan.monthly_price : plan.annual_price;

    const move = (event: ReactPointerEvent<HTMLElement>) => {
        const card = cardRef.current;

        if (!tilt || !card || event.pointerType !== 'mouse') {
            return;
        }

        const rect = card.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width;
        const y = (event.clientY - rect.top) / rect.height;
        card.style.setProperty('--rx', `${((0.5 - y) * 3).toFixed(2)}deg`);
        card.style.setProperty('--ry', `${((x - 0.5) * 4).toFixed(2)}deg`);
        card.style.setProperty('--lift', '-8px');
        card.style.setProperty('--hs', '1.015');
        card.style.setProperty('--mx', `${(x * 100).toFixed(1)}%`);
        card.style.setProperty('--my', `${(y * 100).toFixed(1)}%`);
        card.style.setProperty('--glow', '1');
    };
    const leave = () => {
        const card = cardRef.current;

        if (!card) {
            return;
        }

        for (const [name, value] of [
            ['--rx', '0deg'],
            ['--ry', '0deg'],
            ['--lift', '0px'],
            ['--hs', '1'],
            ['--glow', '0'],
        ]) {
            card.style.setProperty(name, value);
        }
    };

    const ctaClass = `group/btn flex items-center justify-center gap-2 rounded-2xl px-5 py-2.5 text-[14px] font-bold transition duration-200 hover:-translate-y-0.5 hover:scale-[1.01] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#D4A44C] active:scale-[0.97] ${dark ? 'bg-[linear-gradient(180deg,#f8e3ad,#d9ab55)] text-[#3d0000] shadow-[0_12px_24px_-10px_rgba(212,164,76,0.8)] hover:shadow-[0_16px_30px_-10px_rgba(212,164,76,0.9)]' : 'border border-[#e4d3cf] bg-white text-[#0C1424] shadow-sm hover:border-[#c99b95] hover:shadow-[0_12px_24px_-14px_rgba(101,0,0,0.45)]'}`;

    return (
        <article
            ref={cardRef}
            aria-label={`Plan ${plan.name}`}
            onPointerMove={move}
            onPointerLeave={leave}
            className={`plan-anim relative flex min-h-full flex-col rounded-[28px] border px-5 py-4 sm:px-6 ${dark ? 'border-[#e6c27a]/50 bg-[linear-gradient(145deg,#7a0909,#560000_55%,#420000)] text-white' : 'border-white/90 bg-white/85 text-[#0C1424] backdrop-blur-xl'}`}
            style={
                {
                    transform:
                        'translateY(var(--lift, 0px)) rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg)) scale(var(--hs, 1))',
                    transition: `transform 350ms ${EASE}, box-shadow 400ms ${EASE}`,
                    boxShadow:
                        position === 'center'
                            ? dark
                                ? '0 2px 4px rgba(55,0,0,0.25), 0 40px 80px -30px rgba(55,0,0,0.75)'
                                : '0 2px 4px rgba(55,0,0,0.06), 0 40px 80px -34px rgba(55,0,0,0.45)'
                            : '0 1px 3px rgba(55,0,0,0.05), 0 24px 50px -34px rgba(55,0,0,0.4)',
                } as CSSProperties
            }
        >
            <div
                className="pointer-events-none absolute inset-0 overflow-hidden rounded-[28px]"
                aria-hidden="true"
            >
                <div
                    className="absolute inset-0 transition-opacity duration-500"
                    style={{
                        opacity: 'var(--glow, 0)',
                        background: `radial-gradient(circle at var(--mx, 50%) var(--my, 0%), ${dark ? 'rgba(255,214,150,0.18)' : 'rgba(138,11,11,0.07)'}, transparent 55%)`,
                    }}
                />
                {dark && (
                    <span className="plan-card-shine absolute -inset-y-10 -left-1/2 w-1/3 rotate-12 bg-[linear-gradient(90deg,transparent,rgba(255,255,255,0.12),transparent)]" />
                )}
            </div>
            {plan.recommended && <PlanBadge show={show} />}

            <header className="relative flex items-start gap-4">
                <span
                    className={`grid size-11 shrink-0 place-items-center rounded-2xl transition-transform duration-500 group-hover/card:[transform:rotateY(8deg)_scale(1.06)] ${dark ? 'bg-[linear-gradient(145deg,#a81c1c,#6d0000)] text-white shadow-[0_10px_24px_-10px_rgba(0,0,0,0.6),inset_0_1px_0_rgba(255,255,255,0.25)]' : 'bg-[linear-gradient(145deg,#fff5f3,#f6dedb)] text-[#780606] shadow-[0_10px_24px_-14px_rgba(120,6,6,0.5),inset_0_1px_0_#fff]'}`}
                >
                    <Icon className="size-5" />
                </span>
                <div className="min-w-0">
                    {plan.tagline && (
                        <p
                            className={`text-[11px] font-bold tracking-[0.12em] uppercase ${dark ? 'text-[#f3d9a4]' : 'text-[#780606]'}`}
                        >
                            {plan.tagline}
                        </p>
                    )}
                    <h3 className="mt-1 font-serif text-[24px] leading-tight font-bold">
                        {plan.name}
                    </h3>
                </div>
            </header>
            <p
                className={`relative mt-2 text-[13px] leading-5 [@media(max-height:820px)]:hidden ${dark ? 'text-white/75' : 'text-[#5A6780]'}`}
            >
                {plan.description}
            </p>
            <div
                className={`my-3 h-px ${dark ? 'bg-white/15' : 'bg-[#efe4e1]'}`}
            />

            <div
                className="plan-anim relative"
                style={{
                    opacity: show ? 1 : 0,
                    transition: `opacity 500ms ${show ? T.prices : 0}ms`,
                }}
            >
                {plan.price_prefix && (
                    <p
                        className={`text-xs font-bold uppercase ${dark ? 'text-white/60' : 'text-[#5A6780]'}`}
                    >
                        {plan.price_prefix}
                    </p>
                )}
                <p
                    key={billing}
                    className="landing-price flex items-end gap-1.5"
                >
                    <span className="text-[34px] leading-none font-black tracking-tight">
                        <AnimatedPrice value={price} start={show} />
                    </span>
                    <span className="sr-only">{formatQ(price)}</span>
                    <span
                        className={`pb-1 text-base ${dark ? 'text-white/65' : 'text-[#5A6780]'}`}
                    >
                        /{billing === 'monthly' ? 'mes' : 'año'}
                    </span>
                </p>
                <p
                    className={`mt-1 min-h-4 text-[11px] ${dark ? 'text-[#f3d9a4]' : 'text-[#8a6418]'}`}
                >
                    {billing === 'annual' && plan.annual_savings > 0
                        ? `Facturación anual · equivale a ${formatQ(plan.annual_price / 12)}/mes · ahorras ${formatQ(plan.annual_savings)}`
                        : billing === 'annual'
                          ? 'Facturación anual'
                          : 'Facturación mensual'}
                </p>
            </div>

            <AnimatedFeatureList
                features={plan.features}
                show={show}
                dark={dark}
            />

            <div
                className="plan-anim relative mt-auto pt-4"
                style={{
                    opacity: show ? 1 : 0,
                    transition: `opacity 500ms ${show ? T.buttons : 0}ms`,
                }}
            >
                {plan.contact_only ? (
                    <Link href="/cotizacion" className={ctaClass}>
                        {plan.cta_label ?? 'Solicitar cotización'}
                        <ArrowRight className="size-4 transition-transform duration-200 group-hover/btn:translate-x-[5px]" />
                    </Link>
                ) : (
                    <Link
                        href={register({ query: { plan: plan.code, billing } })}
                        className={ctaClass}
                    >
                        {plan.cta_label ?? 'Elegir este plan'}
                        <ArrowRight className="size-4 transition-transform duration-200 group-hover/btn:translate-x-[5px]" />
                    </Link>
                )}
            </div>
        </article>
    );
}

function CarouselArrow({
    direction,
    disabled,
    onClick,
}: {
    direction: -1 | 1;
    disabled: boolean;
    onClick: () => void;
}) {
    const Icon = direction < 0 ? ChevronLeft : ChevronRight;

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={direction < 0 ? 'Plan anterior' : 'Plan siguiente'}
            className="group/arrow grid size-12 place-items-center rounded-full border border-[#eadbd8] bg-white text-[#0C1424] shadow-[0_10px_24px_-12px_rgba(55,0,0,0.35)] transition duration-300 hover:scale-[1.08] hover:bg-[#fbefed] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#780606] disabled:pointer-events-none disabled:opacity-30"
        >
            <Icon
                className={`size-5 transition-transform duration-300 ${direction < 0 ? 'group-hover/arrow:-translate-x-0.5' : 'group-hover/arrow:translate-x-0.5'}`}
            />
        </button>
    );
}

function CarouselIndicators({
    plans,
    active,
    onSelect,
}: {
    plans: PublicPlan[];
    active: number;
    onSelect: (index: number) => void;
}) {
    return (
        <div
            className="relative flex w-max items-center gap-2"
            role="tablist"
            aria-label="Seleccionar plan"
        >
            <span
                className="pointer-events-none absolute top-1/2 left-0 h-2.5 w-[26px] -translate-y-1/2 rounded-full bg-[#650000]"
                style={{
                    transform: `translate(${active * 18 - 8}px, -50%)`,
                    transition: `transform 500ms ${EASE}`,
                }}
                aria-hidden="true"
            />
            {plans.map((plan, index) => (
                <button
                    key={plan.code}
                    type="button"
                    role="tab"
                    aria-selected={active === index}
                    aria-label={`Mostrar plan ${plan.name}`}
                    onClick={() => onSelect(index)}
                    className={`relative size-2.5 rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#780606] ${active === index ? 'bg-transparent' : 'bg-[#e1d0cc] hover:bg-[#c9aea9]'}`}
                />
            ))}
        </div>
    );
}

function FloatingPricingDecoration() {
    return (
        <div
            className="pointer-events-none absolute inset-0 hidden overflow-hidden lg:block"
            aria-hidden="true"
        >
            <svg
                className="absolute -top-10 -right-16 h-[420px] w-[360px] text-[#D4A44C]/55"
                viewBox="0 0 360 420"
                fill="none"
            >
                <path
                    d="M350 10C250 60 150 140 180 260s140 150 170 150"
                    stroke="currentColor"
                    strokeWidth="1.5"
                />
                <path
                    d="M360 60C280 110 210 170 230 270s100 120 130 130"
                    stroke="currentColor"
                    strokeWidth="1"
                />
            </svg>
            <svg
                className="absolute bottom-10 -left-16 h-[360px] w-[320px] text-[#D4A44C]/50"
                viewBox="0 0 320 360"
                fill="none"
            >
                <path
                    d="M0 40c90 30 190 110 190 200S120 350 60 360"
                    stroke="currentColor"
                    strokeWidth="1.5"
                />
            </svg>
            <div className="absolute top-0 -right-24 h-[520px] w-[420px] rounded-full bg-[radial-gradient(circle,rgba(120,6,6,0.16),transparent_65%)]" />
            <div className="absolute -bottom-24 -left-24 h-[460px] w-[420px] rounded-full bg-[radial-gradient(circle,rgba(120,6,6,0.14),transparent_65%)]" />
            <div className="plan-float absolute top-24 right-[4%] grid size-24 [transform:rotate(-8deg)] place-items-center rounded-[26px] border border-white/70 bg-[linear-gradient(150deg,rgba(255,255,255,0.75),rgba(246,225,218,0.45))] shadow-[0_24px_50px_-20px_rgba(120,6,6,0.45),inset_0_1px_0_#fff] backdrop-blur-md">
                <TrendingUp className="size-11 text-[#c9963f]" />
            </div>
            <div className="plan-float plan-float-slow absolute bottom-40 left-[3%] grid size-20 [transform:rotate(8deg)] place-items-center rounded-[22px] border border-white/70 bg-[linear-gradient(150deg,rgba(255,255,255,0.75),rgba(246,225,218,0.45))] shadow-[0_24px_50px_-20px_rgba(120,6,6,0.45),inset_0_1px_0_#fff] backdrop-blur-md">
                <BarChart3 className="size-9 text-[#c9963f]" />
            </div>
        </div>
    );
}

function BillingToggle({
    billing,
    onChange,
    monthsIncluded,
}: {
    billing: Billing;
    onChange: (billing: Billing) => void;
    monthsIncluded: number;
}) {
    return (
        <div
            className="relative inline-grid grid-cols-2 rounded-2xl border border-[#ecdcd9] bg-white/90 p-1 shadow-[0_10px_30px_-18px_rgba(55,0,0,0.4)] backdrop-blur"
            role="group"
            aria-label="Periodo de facturación"
        >
            <span
                className="absolute inset-y-1 left-1 w-[calc(50%-4px)] rounded-xl bg-[linear-gradient(180deg,#8A0B0B,#650000)] shadow-[0_8px_18px_-8px_rgba(101,0,0,0.75)]"
                style={{
                    transform:
                        billing === 'annual' ? 'translateX(100%)' : 'none',
                    transition: `transform 450ms ${EASE}`,
                }}
                aria-hidden="true"
            />
            {(['monthly', 'annual'] as const).map((period) => (
                <button
                    key={period}
                    type="button"
                    onClick={() => onChange(period)}
                    aria-pressed={billing === period}
                    className={`relative z-10 flex items-center justify-center gap-2 rounded-xl px-6 py-2 font-serif text-[15px] font-bold transition-colors duration-300 focus-visible:outline-2 focus-visible:outline-[#D4A44C] ${billing === period ? 'text-white' : 'text-[#26354D] hover:text-[#650000]'}`}
                >
                    {period === 'monthly' ? 'Mensual' : 'Anual'}
                    {period === 'annual' && monthsIncluded > 0 && (
                        <span
                            key={billing}
                            className={`rounded-full px-2.5 py-0.5 font-sans text-[9.5px] font-extrabold tracking-wide uppercase ${billing === 'annual' ? 'plan-badge-pop bg-[#D4A44C] text-[#370000]' : 'bg-[#f8e5e2] text-[#780606]'}`}
                        >
                            {monthsIncluded}{' '}
                            {monthsIncluded === 1
                                ? 'mes incluido'
                                : 'meses incluidos'}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}

function slot(
    offset: number,
    visible: boolean,
): { transform: string; opacity: number; z: number; delay: number } {
    const side = Math.sign(offset);
    const distance = Math.abs(offset);

    if (!visible && distance <= 1) {
        return distance === 0
            ? {
                  transform: 'translate3d(0, 45px, 0) scale(0.92)',
                  opacity: 0,
                  z: 30,
                  delay: T.cards + 100,
              }
            : {
                  transform: `translate3d(calc(${side} * (100% + 28px) + ${side * 50}px), 0, -30px) rotateY(${-side * 5}deg) scale(0.94)`,
                  opacity: 0,
                  z: 20,
                  delay: side < 0 ? T.cards : T.cards + 200,
              };
    }

    if (distance === 0) {
        return {
            transform: 'translate3d(0, -14px, 30px) scale(1.04)',
            opacity: 1,
            z: 30,
            delay: 0,
        };
    }

    if (distance === 1) {
        return {
            transform: `translate3d(calc(${side} * (100% + 28px)), 0, -30px) scale(0.96)`,
            opacity: 0.92,
            z: 20,
            delay: 0,
        };
    }

    return {
        transform: `translate3d(calc(${side} * (200% + 56px)), 0, -60px) scale(0.9)`,
        opacity: 0,
        z: 10,
        delay: 0,
    };
}

/**
 * Plans showroom: three cards on desktop (previous, center, next) on pedestals; the center one comes forward. The
 * five plans are always in the DOM (stacked in one grid cell so the height is the tallest card's, without layout
 * shift); off-screen plans are inert. Arrows, indicators, keyboard arrows, swipe and clicking a side card move it.
 */
export function PricingSection({ plans }: { plans: PublicPlan[] | null }) {
    const reduced = usePrefersReducedMotion();
    const [ref, visible] = useInView<HTMLDivElement>(0.2);
    const [billing, setBilling] = useState<Billing>('monthly');
    const recommendedIndex = plans?.findIndex((plan) => plan.recommended) ?? -1;
    const [active, setActive] = useState(() =>
        recommendedIndex >= 0
            ? recommendedIndex
            : Math.min(1, Math.max(0, (plans?.length ?? 1) - 1)),
    );
    const [entered, setEntered] = useState(false);
    const [fineTilt, setFineTilt] = useState(false);
    const swipeStart = useRef<number | null>(null);

    useEffect(() => {
        const query = window.matchMedia(
            '(min-width: 1024px) and (pointer: fine)',
        );
        const update = () => setFineTilt(query.matches);
        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    useEffect(() => {
        if (!visible) {
            return;
        }

        const timer = window.setTimeout(() => setEntered(true), 1600);

        return () => window.clearTimeout(timer);
    }, [visible]);

    const count = plans?.length ?? 0;
    const go = (index: number) =>
        setActive(Math.max(0, Math.min(count - 1, index)));
    const monthsIncluded = Math.max(
        0,
        ...(plans ?? []).map((plan) => plan.months_included),
    );

    return (
        <section
            id="planes"
            className="relative scroll-mt-16 overflow-hidden bg-[radial-gradient(ellipse_at_50%_0%,#ffffff,#FBF8F6_45%,#f5ebe8)] py-12 lg:py-6 [@media(max-height:780px)]:lg:py-2"
        >
            <FloatingPricingDecoration />
            <div className="relative mx-auto max-w-7xl px-5 sm:px-8">
                <div className="mx-auto max-w-6xl text-center">
                    <Reveal y={20}>
                        <p className="flex items-center justify-center gap-3 text-[11px] font-bold tracking-[0.32em] text-[#780606] uppercase">
                            <span className="h-px w-8 bg-[#D4A44C]" /> Planes de
                            Atlantia Finance{' '}
                            <span className="h-px w-8 bg-[#D4A44C]" />
                        </p>
                        <h2 className="mt-2 font-serif text-3xl leading-[1.1] font-bold tracking-[-0.02em] text-[#0C1424] sm:text-4xl lg:text-[42px] [@media(max-height:780px)]:lg:text-[34px]">
                            El plan ideal para cada etapa de{' '}
                            <span className="text-[#780606]">
                                tu crecimiento
                            </span>
                        </h2>
                    </Reveal>
                    <Reveal delay={200} y={12}>
                        <p className="mx-auto mt-2 max-w-3xl text-[15px] leading-6 text-[#5A6780] [@media(max-height:820px)]:hidden">
                            Potencia tu oficina contable con las herramientas,
                            la seguridad y el soporte que necesitas, en el plan
                            que mejor se adapta a ti.
                        </p>
                    </Reveal>
                    <Reveal delay={350} y={10} className="mt-4">
                        <BillingToggle
                            billing={billing}
                            onChange={setBilling}
                            monthsIncluded={monthsIncluded}
                        />
                    </Reveal>
                </div>

                {plans === null || count === 0 ? (
                    <p className="mx-auto mt-14 max-w-md rounded-2xl border border-[#efe4e1] bg-white p-6 text-center text-[#26354D]">
                        {plans === null
                            ? 'No fue posible cargar los planes. Intenta de nuevo en unos minutos.'
                            : 'Pronto publicaremos nuestros planes.'}
                    </p>
                ) : (
                    <div ref={ref} className="relative mt-3">
                        <div className="absolute top-[46%] left-0 z-40 hidden -translate-y-1/2 md:block xl:-left-14">
                            <CarouselArrow
                                direction={-1}
                                disabled={active === 0}
                                onClick={() => go(active - 1)}
                            />
                        </div>
                        <div className="absolute top-[46%] right-0 z-40 hidden -translate-y-1/2 md:block xl:-right-14">
                            <CarouselArrow
                                direction={1}
                                disabled={active === count - 1}
                                onClick={() => go(active + 1)}
                            />
                        </div>
                        <div
                            role="region"
                            aria-roledescription="carrusel"
                            aria-label="Planes de Atlantia Finance"
                            tabIndex={0}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'ArrowLeft' ||
                                    event.key === 'ArrowRight'
                                ) {
                                    event.preventDefault();
                                    go(
                                        active +
                                            (event.key === 'ArrowLeft'
                                                ? -1
                                                : 1),
                                    );
                                }
                            }}
                            onPointerDown={(event) => {
                                if (event.pointerType !== 'mouse') {
                                    swipeStart.current = event.clientX;
                                }
                            }}
                            onPointerUp={(event) => {
                                if (swipeStart.current === null) {
                                    return;
                                }

                                const delta =
                                    event.clientX - swipeStart.current;
                                swipeStart.current = null;

                                if (Math.abs(delta) > 50) {
                                    go(active + (delta < 0 ? 1 : -1));
                                }
                            }}
                            className="mx-auto [touch-action:pan-y] rounded-3xl focus-visible:outline-2 focus-visible:outline-offset-8 focus-visible:outline-[#780606]"
                        >
                            <div className="mx-auto grid w-[min(370px,calc(100vw-56px))] pt-6 [perspective:1600px]">
                                {plans.map((plan, index) => {
                                    const offset = index - active;
                                    const place = slot(offset, visible);
                                    const position =
                                        offset === 0
                                            ? 'center'
                                            : offset === -1
                                              ? 'left'
                                              : offset === 1
                                                ? 'right'
                                                : 'hidden';
                                    const delay = entered ? 0 : place.delay;

                                    return (
                                        <div
                                            key={plan.code}
                                            inert={position === 'hidden'}
                                            aria-hidden={position === 'hidden'}
                                            onClick={() => {
                                                if (
                                                    position === 'left' ||
                                                    position === 'right'
                                                ) {
                                                    go(index);
                                                }
                                            }}
                                            className={`plan-anim group group/card col-start-1 row-start-1 flex flex-col ${position === 'left' || position === 'right' ? 'cursor-pointer' : ''}`}
                                            style={{
                                                transform: place.transform,
                                                opacity: place.opacity,
                                                zIndex: place.z,
                                                transition: `transform 850ms ${EASE} ${delay}ms, opacity 600ms ${EASE} ${delay}ms`,
                                            }}
                                        >
                                            <div className="flex-1">
                                                <PlanCard3D
                                                    plan={plan}
                                                    billing={billing}
                                                    position={position}
                                                    show={visible}
                                                    tilt={fineTilt && !reduced}
                                                />
                                            </div>
                                            <PlanPedestal
                                                center={position === 'center'}
                                                recommended={plan.recommended}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                        <div className="mt-2 flex items-center justify-center gap-6">
                            <span className="md:hidden">
                                <CarouselArrow
                                    direction={-1}
                                    disabled={active === 0}
                                    onClick={() => go(active - 1)}
                                />
                            </span>
                            <CarouselIndicators
                                plans={plans}
                                active={active}
                                onSelect={go}
                            />
                            <span className="md:hidden">
                                <CarouselArrow
                                    direction={1}
                                    disabled={active === count - 1}
                                    onClick={() => go(active + 1)}
                                />
                            </span>
                        </div>
                    </div>
                )}

                <p className="mx-auto mt-2 max-w-3xl text-center text-[11px] leading-5 text-[#5A6780]">
                    Precios en quetzales, sin impuestos. Aplican los límites y
                    la política de uso razonable de cada plan.
                </p>
            </div>
        </section>
    );
}
