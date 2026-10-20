import { Head, Link, router } from '@inertiajs/react';
import { Activity, BrainCircuit, Play, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    ccButton,
    ccGhost,
    ccInput,
} from '@/components/control-center/kit';

type Output = { name: string; probability: number };
type Outcome = {
    winner: string;
    confidence: number;
    known: string[] | number;
    hidden1: number[];
    hidden2: number[];
    outputs: Output[];
    influence?: { term: string; weight: number }[];
    unknown?: string[];
};
type FeedItem = {
    id: number;
    at: string;
    source: string;
    winner: string;
    confidence: number;
    known: number;
    hidden1: number[];
    hidden2: number[];
    outputs: Output[];
};
type Info = {
    available?: boolean;
    ready: boolean;
    network?: {
        layers: number[];
        hidden?: number[];
        embedding?: number;
        hand_accuracy?: number | null;
        parameters: number;
        vocabulary: number;
        examples: number;
        accuracy: number | null;
        loss: number | null;
        epochs: number;
        trained_at: string | null;
        seconds: number | null;
    };
    training?: {
        running: boolean;
        epoch: number;
        epochs: number;
        loss: number | null;
        message: string | null;
    };
    learned?: number;
};

const WIDTH = 760;
const HEIGHT = 420;
const COLUMN_X = [70, 270, 470, 680];
const INPUT_SLOTS = 12;

function ys(count: number, top = 28, bottom = HEIGHT - 28): number[] {
    if (count === 1) {
        return [(top + bottom) / 2];
    }

    return Array.from(
        { length: count },
        (_, i) => top + ((bottom - top) * i) / (count - 1),
    );
}

// The project's wine red (a brighter tone of #640000 so it reads on the dark panel), with a soft rose as contrast.
const WINE = { on: '236, 40, 70', off: '255, 150, 170' };

const SIZES = [INPUT_SLOTS, 32, 16, 8];

function Network({
    outcome,
    inputs,
    hidden,
    palette,
    animate,
}: {
    outcome: Outcome | null;
    inputs: string[];
    hidden: number[];
    palette: { on: string; off: string };
    animate: boolean;
}) {
    // A decision travels through the network layer by layer; at rest only the neurons drift, softly.
    const [anim, setAnim] = useState<{ of: Outcome | null; step: number }>({
        of: null,
        step: 4,
    });
    // Until the effect below advances it, a new outcome starts dark (step -1).
    const phase = outcome ? (anim.of === outcome ? anim.step : -1) : 4;

    useEffect(() => {
        if (!outcome) {
            return;
        }

        let step = -1;
        const timer = window.setInterval(() => {
            step += 1;
            setAnim({ of: outcome, step });

            if (step >= 4) {
                window.clearInterval(timer);
            }
        }, 320);

        return () => window.clearInterval(timer);
    }, [outcome]);

    // At rest the network shows a steady, dim glow: only the neurons drift, nothing flickers.
    const idle = (layer: number, count: number): number[] =>
        Array.from({ length: count }, (_, i) => {
            const r =
                Math.abs(Math.sin(i * 12.9898 + layer * 7.31) * 43758.5453) % 1;

            return r > 0.7 ? 0.3 + (r - 0.7) * 0.5 : 0.1;
        });
    const gate = (layer: number, values: number[]): number[] =>
        outcome && phase < layer ? values.map(() => 0) : values;
    const pad = (values: number[], size: number): number[] =>
        Array.from({ length: size }, (_, i) => values[i] ?? 0);

    const outputs = outcome?.outputs.slice(0, 8) ?? [];
    const inputValues = Array.from({ length: INPUT_SLOTS }, (_, i) =>
        i < Math.min(inputs.length, INPUT_SLOTS) ? 1 : 0,
    );
    const targets = [
        gate(0, outcome ? inputValues : idle(0, INPUT_SLOTS)),
        gate(1, pad(outcome?.hidden1 ?? idle(1, 32), 32)),
        gate(2, pad(outcome?.hidden2 ?? idle(2, 16), 16)),
        gate(
            3,
            outcome
                ? pad(
                      outputs.map((o) => o.probability),
                      8,
                  )
                : idle(3, 8),
        ),
    ];

    // The whole network is drawn on a canvas (about a thousand connections would be too heavy as SVG elements), and
    // everything eases toward its target values every frame, so it stays smooth.
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const live = useRef({
        targets,
        palette,
        animate,
        winner: Boolean(outcome),
    });

    useEffect(() => {
        live.current = { targets, palette, animate, winner: Boolean(outcome) };
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
        const base = SIZES.map((count) => ys(count));
        const current = SIZES.map((count) => Array<number>(count).fill(0));
        const pairs: [number, number, number][] = [];

        for (let l = 0; l < 3; l++) {
            for (let i = 0; i < SIZES[l]; i++) {
                for (let j = 0; j < SIZES[l + 1]; j++) {
                    pairs.push([l, i, j]);
                }
            }
        }

        const resize = () => {
            const ratio = window.devicePixelRatio || 1;
            const width = canvas.clientWidth;
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round((width * HEIGHT * ratio) / WIDTH);
        };
        resize();
        const observer = new ResizeObserver(resize);
        observer.observe(canvas);

        const drift = (layer: number, index: number, t: number) => {
            const seed = layer * 97 + index * 13.7;

            return [
                Math.sin(t * 0.7 + seed) * 3.2 +
                    Math.sin(t * 0.23 + seed * 2) * 1.5,
                Math.cos(t * 0.55 + seed * 1.3) * 3.2 +
                    Math.cos(t * 0.31 + seed * 0.7) * 1.5,
            ];
        };

        let raf = 0;
        let clock = 0;
        let last = performance.now();
        const frame = (now: number) => {
            const state = live.current;
            const moving = state.animate && !reduced;
            clock += moving ? (now - last) / 1000 : 0;
            last = now;
            const scale = canvas.width / WIDTH;
            context.setTransform(scale, 0, 0, scale, 0, 0);
            context.clearRect(0, 0, WIDTH, HEIGHT);

            for (let l = 0; l < 4; l++) {
                for (let i = 0; i < SIZES[l]; i++) {
                    current[l][i] +=
                        (state.targets[l][i] - current[l][i]) * 0.06;
                }
            }

            const point = (l: number, i: number): [number, number] => {
                const [dx, dy] = moving ? drift(l, i, clock) : [0, 0];

                return [COLUMN_X[l] + dx, base[l][i] + dy];
            };

            // The faint mesh in one stroke per sign; only the connections that carry the signal are drawn one by one.
            const strong: {
                pair: [number, number, number];
                a: [number, number];
                b: [number, number];
                s: number;
                sign: number;
            }[] = [];
            const meshOn = new Path2D();
            const meshOff = new Path2D();

            for (const [l, i, j] of pairs) {
                const product = current[l][i] * current[l + 1][j];
                const s = Math.abs(product);
                const a = point(l, i);
                const b = point(l + 1, j);

                if (s < 0.08) {
                    const path = product >= 0 ? meshOn : meshOff;
                    path.moveTo(a[0], a[1]);
                    path.lineTo(b[0], b[1]);
                } else {
                    strong.push({
                        pair: [l, i, j],
                        a,
                        b,
                        s,
                        sign: product >= 0 ? 1 : -1,
                    });
                }
            }

            context.lineWidth = 0.6;
            context.strokeStyle = `rgba(${state.palette.on}, 0.16)`;
            context.stroke(meshOn);
            context.strokeStyle = `rgba(${state.palette.off}, 0.16)`;
            context.stroke(meshOff);
            context.lineWidth = 1.1;

            for (const line of strong) {
                context.strokeStyle = `rgba(${line.sign > 0 ? state.palette.on : state.palette.off}, ${Math.min(0.95, 0.2 + line.s * 1.1)})`;
                context.beginPath();
                context.moveTo(line.a[0], line.a[1]);
                context.lineTo(line.b[0], line.b[1]);
                context.stroke();
            }

            // Neurons on top.
            for (let l = 0; l < 4; l++) {
                for (let i = 0; i < SIZES[l]; i++) {
                    const value = current[l][i];
                    const [x, y] = point(l, i);
                    const strength = Math.min(1, Math.abs(value));
                    const winner = l === 3 && i === 0 && state.winner;
                    context.shadowColor = `rgba(${state.palette.on}, 0.95)`;
                    context.shadowBlur = 14 * strength;
                    context.fillStyle = `rgba(${value >= 0 ? state.palette.on : state.palette.off}, ${0.25 + 0.75 * strength})`;
                    context.beginPath();
                    context.arc(
                        x,
                        y,
                        l === 0 ? 6 : l === 3 ? 8 : 5,
                        0,
                        Math.PI * 2,
                    );
                    context.fill();
                    context.shadowBlur = 0;
                    context.lineWidth = winner ? 2 : 1;
                    context.strokeStyle = winner
                        ? '#fbbf24'
                        : 'rgba(255,255,255,.18)';
                    context.stroke();
                }
            }

            raf = window.requestAnimationFrame(frame);
        };
        raf = window.requestAnimationFrame(frame);

        return () => {
            window.cancelAnimationFrame(raf);
            observer.disconnect();
        };
    }, []);

    const positions = SIZES.map((count) => ys(count));

    return (
        <div className="relative">
            <canvas
                ref={canvasRef}
                className="block h-auto w-full"
                style={{ aspectRatio: `${WIDTH} / ${HEIGHT}` }}
                role="img"
                aria-label="Red neuronal de Luzma con sus capas encendidas"
            />
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                className="pointer-events-none absolute inset-0 h-full w-full"
                aria-hidden="true"
            >
                {inputs.slice(0, INPUT_SLOTS).map((term, i) => (
                    <text
                        key={term}
                        x={COLUMN_X[0] - 12}
                        y={positions[0][i] + 3}
                        textAnchor="end"
                        fontSize="9"
                        fill="#94a3b8"
                    >
                        {term.length > 14 ? `${term.slice(0, 13)}…` : term}
                    </text>
                ))}
                {outputs.map((o, i) => (
                    <text
                        key={o.name}
                        x={COLUMN_X[3] - 14}
                        y={positions[3][i] + 3}
                        textAnchor="end"
                        fontSize="9"
                        fill={i === 0 ? '#fde68a' : '#94a3b8'}
                    >
                        {o.name.replace('get_', '').replace('_summary', '')}{' '}
                        {Math.round(o.probability * 100)}%
                    </text>
                ))}
                {[
                    'Entrada',
                    `Oculta 1 (${hidden[0].toLocaleString('es-GT')})`,
                    `Oculta 2 (${hidden[1].toLocaleString('es-GT')})`,
                    'Salida',
                ].map((label, l) => (
                    <text
                        key={label}
                        x={COLUMN_X[l]}
                        y={12}
                        textAnchor="middle"
                        fontSize="10"
                        fill="#cbd5e1"
                    >
                        {label}
                    </text>
                ))}
            </svg>
        </div>
    );
}

function csrf(): string {
    const entry = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return entry ? decodeURIComponent(entry.split('=').slice(1).join('=')) : '';
}

export default function LuzmaNeural({
    info: initialInfo,
    recent,
    can_manage,
}: {
    info: Info;
    recent: { items: FeedItem[]; last_id: number };
    can_manage: boolean;
}) {
    const [info, setInfo] = useState<Info>(initialInfo);
    const [feed, setFeed] = useState<FeedItem[]>(
        [...recent.items].reverse().slice(0, 30),
    );
    const [live, setLive] = useState(true);

    const [selected, setSelected] = useState<FeedItem | null>(null);
    const [test, setTest] = useState<Outcome | null>(null);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [feedback, setFeedback] = useState(true);
    const [epochs, setEpochs] = useState(14);
    const lastId = useRef(recent.last_id);

    const poll = useCallback(async () => {
        try {
            const response = await fetch(
                `/control-center/luzma/neural/recent?after=${lastId.current}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            setInfo(data.info);

            if (data.items.length > 0) {
                lastId.current = data.last_id;
                setFeed((current) =>
                    [...[...data.items].reverse(), ...current].slice(0, 30),
                );
            }
        } catch {
            // the next tick tries again
        }
    }, []);

    useEffect(() => {
        const timer = window.setInterval(
            poll,
            info.training?.running ? 1500 : 3000,
        );

        return () => window.clearInterval(timer);
    }, [poll, info.training?.running]);

    const latest = selected ?? (live ? (feed[0] ?? null) : null);
    const shown: Outcome | null = test ?? latest;
    const inputs = test
        ? (test.known as string[])
        : Array.from(
              { length: Number(latest?.known ?? 0) },
              (_, i) => `•${i + 1}`,
          );

    const run = async () => {
        if (text.trim().length < 2) {
            setError('Escribe una pregunta de prueba.');

            return;
        }

        setBusy(true);
        setError(null);

        try {
            const response = await fetch(
                '/control-center/luzma/neural/inspect',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ text }),
                },
            );

            if (!response.ok) {
                throw new Error('La red no respondió.');
            }

            setSelected(null);
            setTest(await response.json());
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Error inesperado.',
            );
        } finally {
            setBusy(false);
        }
    };

    const network = info.network;
    const training = info.training;

    return (
        <>
            <Head title="Red neuronal · Control Center" />
            <CCPage
                title="Red neuronal de Luzma"
                subtitle="Perceptrón multicapa propio: decide qué herramienta responde cada pregunta. Aquí ves sus neuronas encenderse en vivo; nunca ves el texto de las preguntas de las firmas."
                actions={
                    <Link href="/control-center/luzma" className={ccGhost}>
                        Estado de Luzma
                    </Link>
                }
            >
                {!info.ready && (
                    <Panel title="Sin red entrenada">
                        <p className="text-sm text-slate-400">
                            {info.available === false
                                ? 'El servicio de Luzma no responde. Enciéndelo y vuelve a cargar.'
                                : 'La red aún no está entrenada. Entrénala desde aquí.'}
                        </p>
                    </Panel>
                )}

                <div className="grid gap-4 xl:grid-cols-[1fr_340px]">
                    <Panel
                        title="Red en vivo"
                        icon={
                            <BrainCircuit className="size-4 text-[#22d3ee]" />
                        }
                        action={
                            <button
                                type="button"
                                onClick={() => {
                                    setLive(!live);
                                    setSelected(null);
                                    setTest(null);
                                }}
                                className={ccGhost}
                            >
                                <Activity className="size-3.5" />
                                {live ? 'En vivo' : 'Pausada'}
                            </button>
                        }
                    >
                        <div className="rounded-xl bg-[#080b11] p-2">
                            <Network
                                outcome={shown}
                                inputs={inputs}
                                hidden={network?.hidden ?? [32, 16]}
                                palette={WINE}
                                animate={live || test !== null}
                            />
                        </div>

                        <p className="mt-2 text-xs text-slate-400">
                            Las neuronas de entrada son las palabras que la red
                            reconoce en la pregunta (la red completa conoce
                            miles; aquí se dibujan 12 espacios, y los vacíos son
                            palabras que esta pregunta no usó). Las líneas
                            brillan según qué tanto aportan a la decisión.
                        </p>
                        {shown && (
                            <p className="mt-1 text-xs text-slate-300">
                                Decisión:{' '}
                                <b className="text-amber-300">{shown.winner}</b>{' '}
                                con {Math.round(shown.confidence * 100)} %.
                            </p>
                        )}
                        <div className="mt-4 flex gap-2">
                            <input
                                className={ccInput}
                                value={text}
                                maxLength={300}
                                placeholder="Escribe una pregunta de prueba, por ejemplo: ¿Cuánto nos deben los clientes?"
                                onChange={(event) =>
                                    setText(event.target.value)
                                }
                                onKeyDown={(event) =>
                                    event.key === 'Enter' && run()
                                }
                            />
                            <button
                                type="button"
                                className={ccButton}
                                disabled={busy || !info.ready}
                                onClick={run}
                            >
                                <Play className="size-3.5" /> Probar
                            </button>
                        </div>
                        {error && (
                            <p className="mt-2 text-xs text-red-300">{error}</p>
                        )}
                        {test?.influence && test.influence.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-2 text-xs font-bold text-slate-300">
                                    Qué palabras decidieron
                                </p>
                                {test.influence.map((item) => (
                                    <div
                                        key={item.term}
                                        className="mb-1 flex items-center gap-2 text-xs"
                                    >
                                        <span className="w-32 truncate text-slate-300">
                                            {item.term.replace('_', ' + ')}
                                        </span>
                                        <span className="h-2 flex-1 rounded bg-white/5">
                                            <span
                                                className="block h-2 rounded bg-cyan-400"
                                                style={{
                                                    width: `${Math.max(2, Math.min(100, item.weight * 100))}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="w-10 text-right text-slate-400">
                                            {Math.round(item.weight * 100)}%
                                        </span>
                                    </div>
                                ))}
                                {test.unknown && test.unknown.length > 0 && (
                                    <p className="mt-2 text-[11px] text-slate-500">
                                        Palabras que la red no conoce:{' '}
                                        {test.unknown.join(', ')}
                                    </p>
                                )}
                            </div>
                        )}
                    </Panel>

                    <div className="space-y-4">
                        <Panel title="La red">
                            {network ? (
                                <dl className="space-y-1.5 text-xs text-slate-300">
                                    <div className="flex justify-between">
                                        <dt>Capas</dt>
                                        <dd>{network.layers.join(' → ')}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Parámetros</dt>
                                        <dd>
                                            {network.parameters.toLocaleString(
                                                'es-GT',
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Ejemplos de entrenamiento</dt>
                                        <dd>{network.examples}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Exactitud en frases nuevas</dt>
                                        <dd>
                                            {network.accuracy === null
                                                ? '—'
                                                : `${(network.accuracy * 100).toFixed(1)} %`}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>
                                            Exactitud en frases escritas a mano
                                        </dt>
                                        <dd>
                                            {network.hand_accuracy == null
                                                ? '—'
                                                : `${(network.hand_accuracy * 100).toFixed(1)} %`}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Pérdida final</dt>
                                        <dd>{network.loss ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Entrenada</dt>
                                        <dd>{network.trained_at ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>
                                            Preguntas aprendidas de usuarios
                                        </dt>
                                        <dd>{info.learned ?? 0}</dd>
                                    </div>
                                </dl>
                            ) : (
                                <p className="text-xs text-slate-500">
                                    Sin datos.
                                </p>
                            )}
                        </Panel>

                        <Panel
                            title="Entrenamiento"
                            icon={
                                <RefreshCw className="size-4 text-[#c4b5fd]" />
                            }
                        >
                            {training?.running && (
                                <div className="mb-3">
                                    <div className="h-2 rounded bg-white/5">
                                        <div
                                            className="h-2 rounded bg-violet-400 transition-all"
                                            style={{
                                                width: `${(training.epoch / Math.max(1, training.epochs)) * 100}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-slate-400">
                                        Época {training.epoch} de{' '}
                                        {training.epochs} · pérdida{' '}
                                        {training.loss ?? '…'}
                                    </p>
                                </div>
                            )}
                            {!training?.running && training?.message && (
                                <p className="mb-3 text-xs text-emerald-300">
                                    {training.message}
                                </p>
                            )}
                            {can_manage ? (
                                <div className="space-y-2">
                                    <label className="flex items-center gap-2 text-xs text-slate-300">
                                        <input
                                            type="checkbox"
                                            checked={feedback}
                                            onChange={(event) =>
                                                setFeedback(
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        Aprender de las respuestas marcadas como
                                        útiles
                                    </label>
                                    <label className="flex items-center gap-2 text-xs text-slate-300">
                                        Épocas
                                        <input
                                            type="number"
                                            min={5}
                                            max={80}
                                            value={epochs}
                                            className={`${ccInput} !h-8 w-20`}
                                            onChange={(event) =>
                                                setEpochs(
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                    </label>
                                    <button
                                        type="button"
                                        className={ccButton}
                                        disabled={training?.running}
                                        onClick={() =>
                                            router.post(
                                                '/control-center/luzma/neural/train',
                                                {
                                                    use_feedback: feedback,
                                                    epochs,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Reentrenar la red
                                    </button>
                                    <p className="text-[11px] text-slate-500">
                                        Los números de las preguntas se
                                        enmascaran antes de guardarse.
                                    </p>
                                </div>
                            ) : (
                                <p className="text-xs text-slate-500">
                                    Solo quien administra Luzma puede
                                    reentrenar.
                                </p>
                            )}
                        </Panel>
                    </div>
                </div>

                <Panel title="Decisiones en tiempo real">
                    {feed.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            Aún no hay preguntas. Cuando alguien use Luzma, su
                            decisión aparece aquí (solo la herramienta elegida y
                            la confianza, nunca el texto).
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs text-slate-300">
                                <thead>
                                    <tr className="text-left text-slate-500">
                                        <th className="py-1.5 pr-3">Hora</th>
                                        <th className="pr-3">Origen</th>
                                        <th className="pr-3">Herramienta</th>
                                        <th className="pr-3">Confianza</th>
                                        <th>Palabras reconocidas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {feed.map((item) => (
                                        <tr
                                            key={item.id}
                                            onClick={() => {
                                                setSelected(item);
                                                setTest(null);
                                                setLive(false);
                                            }}
                                            className="cursor-pointer border-t border-white/5 hover:bg-white/[0.03]"
                                        >
                                            <td className="py-1.5 pr-3">
                                                {item.at}
                                            </td>
                                            <td className="pr-3">
                                                <Pill
                                                    tone={
                                                        item.source === 'plan'
                                                            ? 'blue'
                                                            : 'slate'
                                                    }
                                                >
                                                    {item.source === 'plan'
                                                        ? 'Pregunta real'
                                                        : 'Prueba'}
                                                </Pill>
                                            </td>
                                            <td className="pr-3 font-semibold text-slate-100">
                                                {item.winner}
                                            </td>
                                            <td className="pr-3">
                                                {Math.round(
                                                    item.confidence * 100,
                                                )}
                                                %
                                            </td>
                                            <td>{item.known}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            </CCPage>
        </>
    );
}
