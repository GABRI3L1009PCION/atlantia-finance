import { Head } from '@inertiajs/react';
import {
    CalendarClock,
    Clock3,
    Globe2,
    MoonStar,
    RefreshCw,
    Sun,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Hours = {
    opens_label: string;
    closes_label: string;
    next_opening_label: string;
    next_opening_day: string;
    next_opening_at: string;
    timezone_label: string;
};

function remaining(until: string, now: number): string {
    const minutes = Math.max(
        0,
        Math.round((new Date(until).getTime() - now) / 60000),
    );
    const hours = Math.floor(minutes / 60);

    return hours > 0
        ? `${hours} h ${String(minutes % 60).padStart(2, '0')} min`
        : `${minutes} min`;
}

export default function ServiceHours({ hours }: { hours: Hours }) {
    const [now, setNow] = useState(() => Date.now());
    const [retrying, setRetrying] = useState(false);
    const [clockValue, clockPeriod] = useMemo(
        () => hours.closes_label.split(' '),
        [hours.closes_label],
    );

    useEffect(() => {
        const tick = setInterval(() => setNow(Date.now()), 30000);
        // When the platform opens, the page reloads itself.
        const wait = Math.min(
            new Date(hours.next_opening_at).getTime() - Date.now() + 1500,
            2_147_000_000,
        );
        const reload = setTimeout(
            () => window.location.reload(),
            Math.max(wait, 5000),
        );

        return () => {
            clearInterval(tick);
            clearTimeout(reload);
        };
    }, [hours.next_opening_at]);

    const retry = () => {
        setRetrying(true);
        window.location.reload();
    };

    const rows = [
        { icon: Sun, label: 'Apertura:', value: hours.opens_label },
        { icon: MoonStar, label: 'Cierre:', value: hours.closes_label },
        {
            icon: CalendarClock,
            label: `Próxima apertura (${hours.next_opening_day}):`,
            value: hours.next_opening_label,
        },
    ];

    return (
        <>
            <Head title="Fuera de horario de servicio" />
            <main className="relative isolate min-h-screen overflow-hidden bg-[#12060a] text-white">
                <div
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_80%_30%,rgba(190,24,56,.35),transparent_55%),radial-gradient(ellipse_at_10%_90%,rgba(120,10,30,.45),transparent_50%),linear-gradient(135deg,#1a0910,#0c0508)]"
                />
                <div
                    aria-hidden
                    className="absolute inset-0 -z-10 [background-image:linear-gradient(rgba(255,255,255,.6)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.6)_1px,transparent_1px)] [background-size:56px_56px] opacity-[.07]"
                />

                <div className="mx-auto flex min-h-screen max-w-7xl flex-col px-6 py-8 sm:px-10">
                    <header className="flex items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-3">
                                <img
                                    src="/images/atlantia-finance-logo.png"
                                    alt=""
                                    className="size-12 object-contain"
                                />
                                <div>
                                    <p className="text-3xl leading-none font-bold tracking-tight sm:text-4xl">
                                        Atlantia{' '}
                                        <span className="font-light">
                                            Finance
                                        </span>
                                    </p>
                                    <p className="mt-2 text-[10px] tracking-[0.28em] text-white/70 uppercase sm:text-xs">
                                        Finanzas hoy, más posibilidades mañana
                                    </p>
                                </div>
                            </div>
                            <div className="mt-4 h-px w-12 bg-[#e2354f]" />
                        </div>
                        <p className="hidden items-center gap-3 pt-2 text-[11px] tracking-[0.3em] text-white/60 uppercase md:flex">
                            Seguridad · Tecnología · Tu futuro{' '}
                            <span className="h-px w-10 bg-[#e2354f]" />
                        </p>
                    </header>

                    <section className="grid flex-1 items-center gap-10 py-10 lg:grid-cols-[1.05fr_1fr]">
                        <div>
                            <h1 className="text-4xl leading-[1.05] font-extrabold tracking-tight sm:text-5xl xl:text-6xl">
                                Nuestro horario de servicio{' '}
                                <span className="text-[#ff3d5a]">
                                    ha finalizado
                                </span>
                            </h1>
                            <p className="mt-6 max-w-xl text-base leading-7 text-white/85 sm:text-lg">
                                La plataforma se encuentra temporalmente fuera
                                de su horario habitual de funcionamiento.
                                Estaremos nuevamente disponibles en nuestro
                                próximo horario de servicio para que puedas
                                continuar utilizando tus herramientas
                                financieras con normalidad.
                            </p>

                            <div className="mt-8 max-w-xl rounded-2xl border border-[#e2354f]/30 bg-white/[0.04] p-5 shadow-[0_20px_60px_-30px_rgba(226,53,79,.6)] backdrop-blur">
                                <div className="flex items-center gap-3 border-b border-white/10 pb-3 font-semibold">
                                    <Clock3 className="size-6 text-white/90" />{' '}
                                    Horario de servicio
                                </div>
                                <dl className="divide-y divide-white/10">
                                    {rows.map(
                                        ({ icon: Icon, label, value }) => (
                                            <div
                                                key={label}
                                                className="flex items-center justify-between gap-4 py-3"
                                            >
                                                <dt className="flex items-center gap-3 text-white/85">
                                                    <Icon className="size-5 text-[#ff6a80]" />{' '}
                                                    {label}
                                                </dt>
                                                <dd className="font-bold">
                                                    {value}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                    <div className="flex items-center gap-3 pt-3 text-white/85">
                                        <Globe2 className="size-5 text-[#ff6a80]" />{' '}
                                        {hours.timezone_label}
                                    </div>
                                </dl>
                            </div>

                            <button
                                type="button"
                                onClick={retry}
                                disabled={retrying}
                                className="mt-6 inline-flex h-14 w-full max-w-xl items-center justify-center gap-3 rounded-full bg-gradient-to-r from-[#d61f43] to-[#a5122f] px-8 text-lg font-bold shadow-[0_14px_40px_-12px_rgba(226,53,79,.9)] transition hover:brightness-110 disabled:opacity-70"
                            >
                                <RefreshCw
                                    className={`size-5 ${retrying ? 'animate-spin' : ''}`}
                                />{' '}
                                Volver a intentar
                            </button>
                            <p className="mt-4 max-w-xl text-center text-sm text-white/70">
                                Abrimos de nuevo en{' '}
                                <b className="text-white">
                                    {remaining(hours.next_opening_at, now)}
                                </b>
                                . Gracias por tu comprensión y confianza.
                            </p>
                        </div>

                        <div
                            aria-hidden
                            className="relative hidden min-h-[460px] lg:block"
                        >
                            <svg
                                viewBox="0 0 640 460"
                                className="absolute inset-0 size-full"
                            >
                                <defs>
                                    <linearGradient id="line" x1="0" x2="1">
                                        <stop
                                            offset="0"
                                            stopColor="#ff3d5a"
                                            stopOpacity=".2"
                                        />
                                        <stop offset="1" stopColor="#ff3d5a" />
                                    </linearGradient>
                                    <linearGradient
                                        id="area"
                                        x1="0"
                                        x2="0"
                                        y1="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0"
                                            stopColor="#ff3d5a"
                                            stopOpacity=".35"
                                        />
                                        <stop
                                            offset="1"
                                            stopColor="#ff3d5a"
                                            stopOpacity="0"
                                        />
                                    </linearGradient>
                                </defs>
                                <g fill="none" stroke="rgba(255,255,255,.08)">
                                    {[80, 160, 240, 320, 400].map((y) => (
                                        <line
                                            key={y}
                                            x1="20"
                                            x2="620"
                                            y1={y}
                                            y2={y}
                                        />
                                    ))}
                                </g>
                                <path
                                    d="M20 380 L110 330 L190 350 L280 270 L360 300 L450 190 L540 220 L620 90 L620 460 L20 460 Z"
                                    fill="url(#area)"
                                />
                                <path
                                    d="M20 380 L110 330 L190 350 L280 270 L360 300 L450 190 L540 220 L620 90"
                                    fill="none"
                                    stroke="url(#line)"
                                    strokeWidth="4"
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                />
                                <circle cx="620" cy="90" r="7" fill="#ff3d5a" />
                                <circle
                                    cx="620"
                                    cy="90"
                                    r="16"
                                    fill="none"
                                    stroke="#ff3d5a"
                                    strokeOpacity=".4"
                                />
                            </svg>

                            <div className="absolute top-1/2 left-1/2 w-[78%] -translate-x-1/2 -translate-y-1/2 rounded-[2rem] border border-[#ff6a80]/40 bg-gradient-to-b from-[#3a0b17] to-[#13050a] p-8 shadow-[0_30px_80px_-20px_rgba(226,53,79,.7)]">
                                <div className="flex items-end justify-center gap-3 font-mono">
                                    <span className="text-7xl font-light tracking-widest text-white drop-shadow-[0_0_18px_rgba(255,255,255,.55)] xl:text-8xl">
                                        {clockValue}
                                    </span>
                                    <span className="pb-3 text-xl text-white/80">
                                        {clockPeriod}
                                    </span>
                                </div>
                                <p className="mt-3 text-center text-xs tracking-[0.3em] text-white/50 uppercase">
                                    Hora de cierre
                                </p>
                            </div>
                        </div>
                    </section>

                    <footer className="border-t border-white/10 pt-5 text-center text-xs text-white/60">
                        © {new Date().getFullYear()} Atlantia Finance - Todos
                        los derechos reservados
                    </footer>
                </div>
            </main>
        </>
    );
}
