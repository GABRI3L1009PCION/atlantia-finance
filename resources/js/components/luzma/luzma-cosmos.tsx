import { useEffect, useRef } from 'react';
import type { OrbState } from '@/components/luzma/luzma-orb';

type Vec = { x: number; y: number; z: number };
type Filament = {
    u: Vec;
    v: Vec;
    n: Vec;
    radius: number;
    start: number;
    span: number;
    spin: number;
    wobble: number;
    wave: number;
    phase: number;
    alpha: number;
    hot: boolean;
    spark: number;
};
type Circuit = {
    kind: 'arc' | 'tick';
    radius: number;
    angle: number;
    span: number;
    length: number;
    elbow: number;
    speed: number;
    alpha: number;
};
type Signal = { link: number; t: number; speed: number; forward: boolean };

const SPEED: Record<OrbState, number> = {
    idle: 1,
    listening: 1.5,
    thinking: 3.2,
    speaking: 2.1,
};

/** New signals per second along the synapses, by state: she murmurs at rest and storms while thinking. */
const FIRING: Record<OrbState, number> = {
    idle: 14,
    listening: 22,
    thinking: 70,
    speaking: 34,
};

/** How much of the filaments carry a travelling spark, by state. */
const SPARKS: Record<OrbState, number> = {
    idle: 0.3,
    listening: 0.45,
    thinking: 1,
    speaking: 0.7,
};

const SPHERE = 0.44;

/** Small deterministic generator, so the cosmos looks the same on every open. */
function generator(seed: number) {
    let value = seed;

    return () => {
        value = (value * 16807) % 2147483647;

        return (value - 1) / 2147483646;
    };
}

function normalize(a: Vec): Vec {
    const length = Math.hypot(a.x, a.y, a.z) || 1;

    return { x: a.x / length, y: a.y / length, z: a.z / length };
}

function cross(a: Vec, b: Vec): Vec {
    return {
        x: a.y * b.z - a.z * b.y,
        y: a.z * b.x - a.x * b.z,
        z: a.x * b.y - a.y * b.x,
    };
}

function build() {
    const random = generator(2026);
    const direction = (): Vec =>
        normalize({
            x: random() - 0.5,
            y: random() - 0.5,
            z: random() - 0.5,
        });

    // Tangled filaments: arcs of tilted circles on and inside the sphere, each one slowly turning on its own.
    const filaments: Filament[] = Array.from({ length: 120 }, (_, i) => {
        const n = direction();
        const helper =
            Math.abs(n.x) < 0.8 ? { x: 1, y: 0, z: 0 } : { x: 0, y: 1, z: 0 };
        const u = normalize(cross(n, helper));
        const v = cross(n, u);

        return {
            u,
            v,
            n,
            radius:
                SPHERE *
                (i < 70 ? 0.78 + random() * 0.24 : 0.25 + random() * 0.55),
            start: random() * 6.28,
            span: 0.9 + random() * 3.6,
            spin: (random() - 0.5) * 0.25,
            wobble: 0.02 + random() * 0.07,
            wave: 2 + Math.floor(random() * 4),
            phase: random() * 6.28,
            alpha: 0.28 + random() * 0.5,
            hot: random() > 0.62,
            spark: random(),
        };
    });

    // Neurons: bright nodes wired to their nearest neighbours; the signals travel along these.
    const neurons: Vec[] = Array.from({ length: 70 }, (_, i) => {
        const y = 1 - (i / 69) * 2;
        const ring = Math.sqrt(1 - y * y);
        const turn = i * 2.399963 + random() * 0.5;
        const shell = SPHERE * (0.8 + random() * 0.3);

        return {
            x: Math.cos(turn) * ring * shell,
            y: y * shell,
            z: Math.sin(turn) * ring * shell,
        };
    });
    const links: [number, number][] = [];

    neurons.forEach((n, i) => {
        neurons
            .map((m, j) => ({
                j,
                d: (n.x - m.x) ** 2 + (n.y - m.y) ** 2 + (n.z - m.z) ** 2,
            }))
            .filter((m) => m.j !== i)
            .sort((p, q) => p.d - q.d)
            .slice(0, 3)
            .forEach((m) => {
                if (!links.some(([a, b]) => a === m.j && b === i)) {
                    links.push([i, m.j]);
                }
            });
    });

    // Fine dust thrown around the sphere.
    const dust = Array.from({ length: 320 }, () => {
        const d = direction();
        const radius = SPHERE * (0.7 + Math.abs(random() + random() - 1) * 1.4);

        return {
            x: d.x * radius,
            y: d.y * radius,
            z: d.z * radius,
            size: 0.6 + random() * 1.3,
            phase: random() * 6.28,
        };
    });

    // Circuit-like pieces around the sphere: long arcs and ticks with right-angle elbows.
    const circuits: Circuit[] = [
        ...Array.from({ length: 22 }, (): Circuit => ({
            kind: 'arc',
            radius: 0.56 + random() * 0.42,
            angle: random() * 6.28,
            span: 0.25 + random() * 1.5,
            length: 0,
            elbow: 0,
            speed: (random() - 0.5) * 0.05,
            alpha: 0.55 + random() * 0.4,
        })),
        ...Array.from({ length: 90 }, (): Circuit => ({
            kind: 'tick',
            radius: 0.56 + random() * 0.34,
            angle: random() * 6.28,
            span: 0,
            length: 0.02 + random() * random() * 0.22,
            elbow: random() > 0.5 ? (random() - 0.5) * 0.18 : 0,
            speed: (random() - 0.5) * 0.04,
            alpha: 0.4 + random() * 0.5,
        })),
    ];

    return { filaments, neurons, links, dust, circuits };
}

/**
 * Luzma as a ball of thinking neurons: a tangle of glowing filaments with a hot core, nodes wired to each other where
 * signals travel and flash, and circuit-like arcs turning around it. It speeds up and fires more while she thinks.
 * Drawn on a canvas, so it stays smooth and costs almost nothing.
 */
export function LuzmaCosmos({ state = 'idle' }: { state?: OrbState }) {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const live = useRef(state);

    useEffect(() => {
        live.current = state;
    });

    useEffect(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const { filaments, neurons, links, dust, circuits } = build();
        const fire = Array<number>(neurons.length).fill(0);
        const signals: Signal[] = [];
        let spawn = 0;
        const resize = () => {
            const ratio = window.devicePixelRatio || 1;
            canvas.width = Math.max(1, Math.round(canvas.clientWidth * ratio));
            canvas.height = Math.max(
                1,
                Math.round(canvas.clientHeight * ratio),
            );
        };
        resize();
        const observer = new ResizeObserver(resize);
        observer.observe(canvas);

        let raf = 0;
        let clock = 0;
        let last = performance.now();
        let speed = 1;
        let energy = 0;
        const paint = (now: number) => {
            const mode = live.current;
            speed += (SPEED[mode] - speed) * 0.04;
            energy += ((mode === 'thinking' ? 1 : 0.15) - energy) * 0.05;
            const elapsed = Math.min(0.1, (now - last) / 1000);
            clock += reduced ? 0 : elapsed * speed;
            last = now;
            const w = canvas.width;
            const h = canvas.height;
            const unit = (Math.min(w, h) / 2) * 0.96;
            const pulse =
                mode === 'listening'
                    ? 1 + Math.sin(clock * 5) * 0.025
                    : mode === 'speaking'
                      ? 1 + Math.abs(Math.sin(clock * 3.2)) * 0.04
                      : 1;
            context.clearRect(0, 0, w, h);
            context.save();
            context.translate(w / 2, h / 2);
            context.scale(unit * pulse, unit * pulse);
            const dot = 1 / unit; // one canvas pixel in unit space

            // Circuit pieces around the sphere.
            context.lineWidth = dot * 1.5;
            context.lineCap = 'round';

            // They turn at their own pace, stretch and shrink, flicker like live circuits and carry a travelling light.
            circuits.forEach((piece, index) => {
                const angle = piece.angle + clock * piece.speed * 5;
                const beat = Math.sin(
                    clock * (0.5 + (index % 7) * 0.13) + index,
                );
                const flicker = 0.55 + 0.45 * Math.abs(beat);
                context.strokeStyle = `rgba(236,160,62,${piece.alpha * flicker})`;
                context.beginPath();

                if (piece.kind === 'arc') {
                    const span = piece.span * (0.75 + 0.25 * beat);
                    context.arc(0, 0, piece.radius, angle, angle + span);
                    context.stroke();
                    const t = (clock * 0.18 + index * 0.37) % 1;
                    const at = angle + span * t;
                    context.fillStyle = `rgba(255,236,190,${Math.sin(Math.PI * t) * 0.95})`;
                    context.fillRect(
                        Math.cos(at) * piece.radius - dot * 1.5,
                        Math.sin(at) * piece.radius - dot * 1.5,
                        dot * 3,
                        dot * 3,
                    );

                    return;
                }

                const c = Math.cos(angle);
                const s = Math.sin(angle);
                const reach =
                    piece.length * (0.6 + 0.4 * (beat + 1) * 0.5 + 0.25);
                const end = piece.radius + reach;
                context.moveTo(c * piece.radius, s * piece.radius);
                context.lineTo(c * end, s * end);

                if (piece.elbow !== 0) {
                    context.lineTo(
                        c * end - s * piece.elbow,
                        s * end + c * piece.elbow,
                    );
                }

                context.stroke();
            });

            // A slow scan wave leaves the sphere and fades as it crosses the circuits.
            const wave = (clock * 0.22) % 1;
            context.strokeStyle = `rgba(255,190,100,${(1 - wave) * 0.22})`;
            context.lineWidth = dot * 1.2;
            context.beginPath();
            context.arc(0, 0, 0.45 + wave * 0.55, 0, Math.PI * 2);
            context.stroke();
            context.lineWidth = dot * 1.5;

            // Everything inside glows additively, like light.
            context.globalCompositeOperation = 'lighter';

            const yaw = clock * 0.14;
            const pitch = 0.5 + Math.sin(clock * 0.09) * 0.2;
            const cy = Math.cos(yaw);
            const sy = Math.sin(yaw);
            const cp = Math.cos(pitch);
            const sp = Math.sin(pitch);
            const project = (x: number, y: number, z: number) => {
                const x1 = x * cy + z * sy;
                const z1 = -x * sy + z * cy;
                const y2 = y * cp - z1 * sp;
                const z2 = y * sp + z1 * cp;
                const k = 1 / (1 - z2 * 0.7);

                return {
                    x: x1 * k,
                    y: y2 * k,
                    depth: Math.max(0, Math.min(1, (z2 / SPHERE + 1) / 2)),
                };
            };

            // Hot core.
            const core = 0.2 + energy * 0.14 + Math.sin(clock * 1.7) * 0.015;
            const glow = context.createRadialGradient(
                0,
                0,
                0,
                0,
                0,
                core * 2.4,
            );
            glow.addColorStop(0, `rgba(255,236,170,${0.55 + energy * 0.3})`);
            glow.addColorStop(0.25, 'rgba(255,170,60,0.28)');
            glow.addColorStop(1, 'rgba(120,30,10,0)');
            context.fillStyle = glow;
            context.beginPath();
            context.arc(0, 0, core * 2.4, 0, Math.PI * 2);
            context.fill();

            // Filaments.
            context.lineWidth = dot * 1.1;
            const sparkLimit = SPARKS[mode];
            const sparks: { x: number; y: number; depth: number }[] = [];

            for (const f of filaments) {
                const steps = Math.max(8, Math.round(f.span * 9));
                const turn = f.start + clock * f.spin;
                let first = true;
                let mid = 0;
                context.beginPath();

                for (let s = 0; s <= steps; s++) {
                    const t = s / steps;
                    const theta = turn + t * f.span;
                    const wob =
                        f.wobble *
                        Math.sin(f.wave * theta + clock * 0.6 + f.phase);
                    const r = f.radius;
                    const px =
                        r *
                            (Math.cos(theta) * f.u.x +
                                Math.sin(theta) * f.v.x) +
                        wob * f.n.x;
                    const py =
                        r *
                            (Math.cos(theta) * f.u.y +
                                Math.sin(theta) * f.v.y) +
                        wob * f.n.y;
                    const pz =
                        r *
                            (Math.cos(theta) * f.u.z +
                                Math.sin(theta) * f.v.z) +
                        wob * f.n.z;
                    const p = project(px, py, pz);

                    if (first) {
                        context.moveTo(p.x, p.y);
                        first = false;
                    } else {
                        context.lineTo(p.x, p.y);
                    }

                    mid += p.depth;
                }

                const depth = mid / (steps + 1);
                const bright = f.alpha * (0.45 + depth * 0.75);
                context.strokeStyle = f.hot
                    ? `rgba(255,${190 + energy * 40},90,${Math.min(1, bright + energy * 0.2)})`
                    : `rgba(255,120,60,${bright * 0.7})`;
                context.stroke();

                if (f.spark < sparkLimit) {
                    const t =
                        (clock * (0.12 + f.spark * 0.3) + f.spark * 9) % 1;
                    const theta = turn + t * f.span;
                    const r = f.radius;
                    const p = project(
                        r * (Math.cos(theta) * f.u.x + Math.sin(theta) * f.v.x),
                        r * (Math.cos(theta) * f.u.y + Math.sin(theta) * f.v.y),
                        r * (Math.cos(theta) * f.u.z + Math.sin(theta) * f.v.z),
                    );
                    sparks.push(p);
                }
            }

            for (const p of sparks) {
                context.fillStyle = `rgba(255,236,180,${0.45 + p.depth * 0.5})`;
                context.fillRect(
                    p.x - dot * 1.1,
                    p.y - dot * 1.1,
                    dot * 2.2,
                    dot * 2.2,
                );
            }

            // Synapses between neurons, and the signals running through them.
            if (!reduced) {
                spawn += elapsed * FIRING[mode];

                while (spawn >= 1 && signals.length < 160) {
                    spawn -= 1;
                    signals.push({
                        link: Math.floor(Math.random() * links.length),
                        t: 0,
                        speed: 0.7 + Math.random() * 0.9,
                        forward: Math.random() > 0.3,
                    });
                }

                spawn = Math.min(spawn, 1);
            }

            const nodes = neurons.map((n) => project(n.x, n.y, n.z));
            context.lineWidth = dot * 0.9;

            for (const [a, b] of links) {
                const p = nodes[a];
                const q = nodes[b];
                context.strokeStyle = `rgba(255,170,80,${0.1 + ((p.depth + q.depth) / 2) * 0.3})`;
                context.beginPath();
                context.moveTo(p.x, p.y);
                context.lineTo(q.x, q.y);
                context.stroke();
            }

            for (let s = signals.length - 1; s >= 0; s--) {
                const signal = signals[s];
                signal.t += elapsed * speed * signal.speed;
                const [from, to] = links[signal.link];
                const origin = signal.forward ? from : to;
                const target = signal.forward ? to : from;
                const a = nodes[origin];
                const b = nodes[target];

                if (signal.t >= 1) {
                    fire[target] = 1;
                    signals.splice(s, 1);

                    // A neuron that fires sometimes passes the signal on, so thoughts spread like a chain.
                    if (Math.random() > 0.4 && signals.length < 160) {
                        const next = links
                            .map((l, i) => ({ l, i }))
                            .filter(
                                ({ l }) => l[0] === target || l[1] === target,
                            );

                        if (next.length > 0) {
                            const pick =
                                next[Math.floor(Math.random() * next.length)];
                            signals.push({
                                link: pick.i,
                                t: 0,
                                speed: signal.speed,
                                forward: pick.l[0] === target,
                            });
                        }
                    }

                    continue;
                }

                const x = a.x + (b.x - a.x) * signal.t;
                const y = a.y + (b.y - a.y) * signal.t;
                const tail = Math.max(0, signal.t - 0.2);
                const tx = a.x + (b.x - a.x) * tail;
                const ty = a.y + (b.y - a.y) * tail;
                const trail = context.createLinearGradient(tx, ty, x, y);
                trail.addColorStop(0, 'rgba(255,200,120,0)');
                trail.addColorStop(1, 'rgba(255,240,190,1)');
                context.strokeStyle = trail;
                context.lineWidth = dot * 1.8;
                context.beginPath();
                context.moveTo(tx, ty);
                context.lineTo(x, y);
                context.stroke();
            }

            nodes.forEach((n, i) => {
                fire[i] *= 0.94;
                const size = dot * (1.8 + n.depth * 1.8);
                const strength = 0.4 + n.depth * 0.5 + fire[i];
                const halo = context.createRadialGradient(
                    n.x,
                    n.y,
                    0,
                    n.x,
                    n.y,
                    dot * (6 + fire[i] * 16),
                );
                halo.addColorStop(
                    0,
                    `rgba(255,200,110,${Math.min(0.9, 0.18 + fire[i] * 0.7)})`,
                );
                halo.addColorStop(1, 'rgba(255,140,50,0)');
                context.fillStyle = halo;
                context.beginPath();
                context.arc(n.x, n.y, dot * (6 + fire[i] * 16), 0, Math.PI * 2);
                context.fill();
                context.fillStyle = `rgba(255,${215 + fire[i] * 40},${150 + fire[i] * 90},${Math.min(1, strength)})`;
                context.fillRect(
                    n.x - size / 2,
                    n.y - size / 2,
                    size * (1 + fire[i]),
                    size * (1 + fire[i]),
                );
            });

            // Dust around the sphere.
            for (const mote of dust) {
                const p = project(mote.x, mote.y, mote.z);
                const twinkle =
                    0.35 + 0.65 * Math.abs(Math.sin(clock * 1.3 + mote.phase));
                context.fillStyle = `rgba(255,190,110,${twinkle * (0.25 + p.depth * 0.5)})`;
                const size = dot * mote.size;
                context.fillRect(p.x, p.y, size, size);
            }

            context.globalCompositeOperation = 'source-over';
            context.restore();
            raf = window.requestAnimationFrame(paint);
        };
        raf = window.requestAnimationFrame(paint);

        return () => {
            window.cancelAnimationFrame(raf);
            observer.disconnect();
        };
    }, []);

    return (
        <canvas
            ref={canvasRef}
            className="absolute inset-0 h-full w-full"
            aria-hidden="true"
        />
    );
}
