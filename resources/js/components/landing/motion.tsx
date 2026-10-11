import type { CSSProperties, ReactNode, RefObject } from 'react';
import { useEffect, useRef, useState } from 'react';

/** Easing of every landing transition: fast start, long soft landing (no bounce). */
export const EASE = 'cubic-bezier(0.16, 1, 0.3, 1)';

export function usePrefersReducedMotion(): boolean {
    const [reduced, setReduced] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReduced(query.matches);
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return reduced;
}

/** True once the element has entered the viewport (it never goes back to false). */
export function useInView<T extends Element>(
    threshold = 0.18,
): [RefObject<T | null>, boolean] {
    const ref = useRef<T>(null);
    const [inView, setInView] = useState(false);

    useEffect(() => {
        const element = ref.current;

        if (!element || inView) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setInView(true);
                    observer.disconnect();
                }
            },
            { threshold, rootMargin: '0px 0px -8% 0px' },
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, [inView, threshold]);

    return [ref, inView];
}

/** Fades and slides its content in the first time it enters the viewport. */
export function Reveal({
    children,
    delay = 0,
    x = 0,
    y = 20,
    className = '',
    style,
}: {
    children: ReactNode;
    delay?: number;
    x?: number;
    y?: number;
    className?: string;
    style?: CSSProperties;
}) {
    const [ref, visible] = useInView<HTMLDivElement>();

    return (
        <div
            ref={ref}
            className={`landing-reveal ${className}`}
            style={{
                ...style,
                opacity: visible ? 1 : 0,
                transform: visible ? 'none' : `translate3d(${x}px, ${y}px, 0)`,
                transition: `opacity 700ms ${EASE} ${delay}ms, transform 700ms ${EASE} ${delay}ms`,
            }}
        >
            {children}
        </div>
    );
}

/** Counts from `from` to `to` with an ease-out curve once `start` is true. */
export function AnimatedCounter({
    to,
    from = 0,
    duration = 1200,
    delay = 0,
    start = true,
    decimals = 0,
    prefix = '',
    suffix = '',
}: {
    to: number;
    from?: number;
    duration?: number;
    delay?: number;
    start?: boolean;
    decimals?: number;
    prefix?: string;
    suffix?: string;
}) {
    const reduced = usePrefersReducedMotion();
    const [value, setValue] = useState(from);

    useEffect(() => {
        if (!start) {
            return;
        }

        let frame = 0;
        let begin = 0;
        const tick = (now: number) => {
            begin ||= now + (reduced ? 0 : delay);
            const progress = reduced
                ? 1
                : Math.min(1, Math.max(0, (now - begin) / duration));
            setValue(from + (to - from) * (1 - Math.pow(1 - progress, 3)));

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        };
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [start, to, from, duration, delay, reduced]);

    return (
        <span className="tabular-nums">
            {prefix}
            {value.toLocaleString('es-GT', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            })}
            {suffix}
        </span>
    );
}

/**
 * Gentle 3D tilt that follows the pointer inside `areaRef` (max ±2° X, ±3° Y) and adds depth on scroll. The
 * transform is written straight to the element on animation frames, so React does not re-render while moving.
 */
export function ThreeDTilt({
    areaRef,
    children,
    className = '',
    baseX = 4,
    baseY = -8,
}: {
    areaRef: RefObject<HTMLElement | null>;
    children: ReactNode;
    className?: string;
    baseX?: number;
    baseY?: number;
}) {
    const elementRef = useRef<HTMLDivElement>(null);
    const reduced = usePrefersReducedMotion();

    useEffect(() => {
        const element = elementRef.current;
        const area = areaRef.current;

        if (!element || !area) {
            return;
        }

        const desktop = window.matchMedia(
            '(min-width: 1024px) and (pointer: fine)',
        ).matches;
        const fullTilt = desktop && !reduced;
        const target = { x: 0, y: 0 };
        const current = { x: 0, y: 0, scroll: 0 };
        let frame = 0;

        const render = () => {
            const rect = area.getBoundingClientRect();
            const scroll = reduced
                ? 0
                : Math.min(
                      1,
                      Math.max(0, -rect.top / Math.max(1, rect.height)),
                  );
            current.x += (target.x - current.x) * 0.08;
            current.y += (target.y - current.y) * 0.08;
            current.scroll += (scroll - current.scroll) * 0.12;
            const rotateX = (fullTilt ? baseX : baseX / 2) + current.x;
            const rotateY =
                (fullTilt ? baseY : 0) * (1 - current.scroll * 0.5) + current.y;
            element.style.transform = `perspective(1400px) rotateX(${rotateX.toFixed(2)}deg) rotateY(${rotateY.toFixed(2)}deg) translate3d(0, ${(-20 * current.scroll).toFixed(1)}px, 0)`;
            element.style.opacity = String(1 - current.scroll * 0.05);
            frame = requestAnimationFrame(render);
        };

        const onMove = (event: PointerEvent) => {
            if (!fullTilt) {
                return;
            }

            const rect = area.getBoundingClientRect();
            const nx = (event.clientX - rect.left) / rect.width - 0.5;
            const ny = (event.clientY - rect.top) / rect.height - 0.5;
            target.x = -ny * 4;
            target.y = nx * 6;
        };
        const onLeave = () => {
            target.x = 0;
            target.y = 0;
        };

        area.addEventListener('pointermove', onMove);
        area.addEventListener('pointerleave', onLeave);
        frame = requestAnimationFrame(render);

        return () => {
            cancelAnimationFrame(frame);
            area.removeEventListener('pointermove', onMove);
            area.removeEventListener('pointerleave', onLeave);
        };
    }, [areaRef, reduced, baseX, baseY]);

    return (
        <div
            ref={elementRef}
            className={className}
            style={{ transformStyle: 'preserve-3d', willChange: 'transform' }}
        >
            {children}
        </div>
    );
}
