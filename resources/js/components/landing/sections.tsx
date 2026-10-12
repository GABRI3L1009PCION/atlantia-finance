import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Building2,
    Check,
    ChevronDown,
    DatabaseBackup,
    FileCheck2,
    Fingerprint,
    History,
    KeyRound,
    LockKeyhole,
    Network,
    ReceiptText,
    ScanSearch,
    ShieldCheck,
    Sparkles,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import { EASE, Reveal, useInView } from '@/components/landing/motion';
import { register } from '@/routes';

export const sectionTitle =
    'text-3xl font-bold tracking-[-0.03em] text-[#0C1424] sm:text-4xl lg:text-5xl';
export const eyebrow =
    'text-xs font-bold tracking-[0.2em] text-[#780606] uppercase';

/** The five promises right below the hero. */
export function BenefitsBar() {
    const items = [
        [
            Building2,
            'Multi-oficina',
            'Administra varias sedes desde un solo lugar',
        ],
        [
            Sparkles,
            'IA asistida',
            'Automatiza tareas y obtén información contextual',
        ],
        [ShieldCheck, 'Seguridad', 'Tus datos protegidos en todo momento'],
        [
            ReceiptText,
            'Cumplimiento fiscal',
            'Control de obligaciones y alertas',
        ],
        [
            TrendingUp,
            'Crecimiento',
            'Planes que se adaptan a tu firma contable',
        ],
    ] as const;

    return (
        <div className="relative z-10 mx-auto -mt-4 max-w-7xl px-5 sm:px-8">
            <div className="grid gap-px overflow-hidden rounded-[24px] border border-[#efe4e1] bg-[#efe4e1] shadow-[0_24px_60px_-34px_rgba(55,0,0,0.35)] sm:grid-cols-2 lg:grid-cols-5">
                {items.map(([Icon, title, text], index) => (
                    <Reveal
                        key={title}
                        delay={index * 80}
                        y={15}
                        className="flex items-center gap-3 bg-white/95 px-5 py-5"
                    >
                        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#fbf0ee] text-[#780606] ring-1 ring-[#f2dcd8]">
                            <Icon className="size-5" />
                        </span>
                        <span>
                            <span className="block text-sm font-bold text-[#0C1424]">
                                {title}
                            </span>
                            <span className="block text-xs leading-snug text-[#5A6780]">
                                {text}
                            </span>
                        </span>
                    </Reveal>
                ))}
            </div>
        </div>
    );
}

/** What the product is built around (facts about the product, not client logos). */
export function TrustStrip() {
    return (
        <Reveal className="mx-auto max-w-7xl px-5 pt-14 text-center sm:px-8">
            <p className="text-xs font-bold tracking-[0.2em] text-[#5A6780] uppercase">
                Diseñado para la realidad contable de Guatemala
            </p>
            <div className="mt-5 flex flex-wrap justify-center gap-3">
                {[
                    'Facturación FEL',
                    'Libros de IVA',
                    'Nómina e IGSS',
                    'Quetzales (GTQ)',
                    'Multi-oficina',
                    'Soporte en español',
                ].map((item) => (
                    <span
                        key={item}
                        className="rounded-full border border-[#ecdcd9] bg-white px-4 py-2 text-xs font-semibold text-[#26354D]"
                    >
                        {item}
                    </span>
                ))}
            </div>
        </Reveal>
    );
}

/** A head office linked to its branches; the links draw themselves when the section scrolls in. */
export function MultiOfficeSection() {
    const [ref, visible] = useInView<HTMLDivElement>(0.3);
    const branches = [
        { name: 'Zona 10', score: 84, x: 18, y: 20 },
        { name: 'Zona 9', score: 73, x: 82, y: 20 },
        { name: 'Quetzaltenango', score: 91, x: 18, y: 80 },
        { name: 'Antigua', score: 70, x: 82, y: 80 },
    ];

    return (
        <section
            id="multi-office"
            className="relative scroll-mt-24 overflow-hidden bg-[linear-gradient(160deg,#450000,#2a0000_60%,#1d0000)] py-20 text-white sm:py-24"
        >
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_80%_20%,rgba(212,164,76,0.16),transparent_40%),radial-gradient(circle_at_10%_90%,rgba(138,11,11,0.5),transparent_40%)]" />
            <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-5 sm:px-8 lg:grid-cols-2">
                <Reveal>
                    <p className="text-xs font-bold tracking-[0.2em] text-[#D4A44C] uppercase">
                        Crece sin perder visibilidad
                    </p>
                    <h2 className="mt-3 text-3xl font-bold tracking-[-0.03em] sm:text-5xl">
                        Todas tus oficinas. Una sola visión.
                    </h2>
                    <p className="mt-6 text-lg leading-8 text-white/70">
                        Para quien dirige dos, tres o más sedes y necesita saber
                        cómo trabaja cada equipo sin mezclar responsabilidades
                        ni información.
                    </p>
                    <ul className="mt-8 grid gap-4 sm:grid-cols-2">
                        {[
                            'Resultados consolidados',
                            'Datos separados por oficina',
                            'Responsables y permisos propios',
                            'Comparación de desempeño',
                        ].map((item) => (
                            <li
                                key={item}
                                className="flex items-start gap-3 text-sm font-semibold text-white/85"
                            >
                                <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-[#D4A44C] text-[#370000]">
                                    <Check className="size-3.5" />
                                </span>
                                {item}
                            </li>
                        ))}
                    </ul>
                </Reveal>
                <div
                    ref={ref}
                    className="relative mx-auto aspect-[5/4] w-full max-w-xl"
                    role="img"
                    aria-label="Oficina central conectada con las sedes de Zona 10, Zona 9, Quetzaltenango y Antigua"
                >
                    <svg
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                        className="absolute inset-0 size-full"
                        aria-hidden="true"
                    >
                        {branches.map((branch, index) => (
                            <path
                                key={branch.name}
                                d={`M50 50 C ${(50 + branch.x) / 2} 50, ${(50 + branch.x) / 2} ${branch.y}, ${branch.x} ${branch.y}`}
                                fill="none"
                                stroke="url(#branch-line)"
                                strokeWidth="1.6"
                                vectorEffect="non-scaling-stroke"
                                pathLength={1}
                                strokeDasharray="1"
                                style={{
                                    strokeDashoffset: visible ? 0 : 1,
                                    transition: `stroke-dashoffset 1100ms ${EASE} ${300 + index * 150}ms`,
                                }}
                            />
                        ))}
                        <defs>
                            <linearGradient id="branch-line" x1="0" x2="1">
                                <stop offset="0" stopColor="#D4A44C" />
                                <stop offset="1" stopColor="#f3d9a4" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <div
                        className="absolute top-1/2 left-1/2 w-44 -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-[#D4A44C]/40 bg-white/10 p-4 text-center shadow-[0_0_60px_-10px_rgba(212,164,76,0.5)] backdrop-blur-xl"
                        style={{
                            opacity: visible ? 1 : 0,
                            scale: visible ? '1' : '0.9',
                            transition: `opacity 600ms ${EASE}, scale 600ms ${EASE}`,
                        }}
                    >
                        <Building2 className="mx-auto size-6 text-[#D4A44C]" />
                        <p className="mt-2 text-sm font-bold">
                            Oficina central
                        </p>
                        <p className="text-[11px] text-white/60">
                            Vista consolidada
                        </p>
                    </div>
                    {branches.map((branch, index) => (
                        <div
                            key={branch.name}
                            className="absolute w-32 -translate-x-1/2 -translate-y-1/2 rounded-xl border border-white/15 bg-white/[0.07] px-3 py-2.5 backdrop-blur sm:w-36"
                            style={{
                                left: `${branch.x}%`,
                                top: `${branch.y}%`,
                                opacity: visible ? 1 : 0,
                                transition: `opacity 600ms ${EASE} ${900 + index * 150}ms`,
                            }}
                        >
                            <p className="text-xs font-bold">{branch.name}</p>
                            <p className="mt-0.5 flex items-baseline gap-1 text-[11px] text-white/60">
                                <span className="text-base font-bold text-[#f3d9a4]">
                                    {branch.score}%
                                </span>
                                cumplimiento
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

export function LuzmaSection() {
    const conversation = [
        [
            '¿Qué empresas requieren atención?',
            'Comercial Maya y Distribuidora Sur tienen la declaración de IVA de septiembre pendiente y dos conciliaciones sin cerrar.',
        ],
        [
            '¿Qué obligaciones vencen esta semana?',
            'Vencen 5 obligaciones: 3 declaraciones de IVA, 1 retención de ISR y 1 planilla del IGSS. Te preparo los recordatorios.',
        ],
        [
            '¿Qué falta para cerrar septiembre?',
            'Faltan 4 asientos por aprobar y 2 estados de cuenta por conciliar. El resto del checklist de cierre está completo.',
        ],
    ];

    return (
        <section id="luzma" className="scroll-mt-24 py-20 sm:py-24">
            <div className="mx-auto grid max-w-7xl items-center gap-14 px-5 sm:px-8 lg:grid-cols-[.95fr_1.05fr]">
                <Reveal>
                    <span className="inline-flex items-center gap-2 rounded-full border border-[#f0dcd8] bg-white px-3.5 py-2 text-xs font-bold text-[#780606]">
                        <Sparkles className="size-3.5" /> Conoce a Luzma
                    </span>
                    <h2 className={`mt-5 ${sectionTitle}`}>
                        Tu asistente inteligente dentro de Atlantia Finance.
                    </h2>
                    <p className="mt-6 text-lg leading-8 text-[#5A6780]">
                        Luzma consulta la información de tu operación que tu rol
                        puede ver, resume, detecta pendientes y prepara
                        borradores. Tú decides: las acciones sensibles siempre
                        requieren tu aprobación.
                    </p>
                    <div className="mt-8 grid gap-3 sm:grid-cols-2">
                        {[
                            [
                                ShieldCheck,
                                'Datos aislados',
                                'Solo accede a la organización y oficina autorizadas.',
                            ],
                            [
                                ScanSearch,
                                'Mínimo contexto',
                                'Usa únicamente la información necesaria para responder.',
                            ],
                            [
                                History,
                                'Aprobación y auditoría',
                                'Lo sensible requiere confirmación y queda registrado.',
                            ],
                            [
                                LockKeyhole,
                                'Sin entrenamiento',
                                'Tus datos no se usan para entrenar modelos públicos.',
                            ],
                        ].map(([Icon, title, text]) => {
                            const ItemIcon = Icon as typeof ShieldCheck;

                            return (
                                <div
                                    key={title as string}
                                    className="flex gap-3 rounded-2xl border border-[#efe4e1] bg-white p-4"
                                >
                                    <ItemIcon className="mt-0.5 size-5 shrink-0 text-[#780606]" />
                                    <span>
                                        <span className="block text-sm font-bold text-[#0C1424]">
                                            {title as string}
                                        </span>
                                        <span className="block text-xs leading-5 text-[#5A6780]">
                                            {text as string}
                                        </span>
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </Reveal>
                <Reveal delay={150}>
                    <div className="rounded-[28px] border border-white/70 bg-white/85 p-5 shadow-[0_40px_90px_-40px_rgba(55,0,0,0.5)] ring-1 ring-[#650000]/10 backdrop-blur-xl sm:p-6">
                        <div className="flex items-center gap-3 border-b border-[#f1e8e6] pb-4">
                            <span className="grid size-10 place-items-center rounded-2xl bg-[linear-gradient(135deg,#a3161c,#650000)] text-white">
                                <Sparkles className="size-5" />
                            </span>
                            <div>
                                <p className="font-bold text-[#0C1424]">
                                    Luzma
                                </p>
                                <p className="text-xs text-[#326FE8]">
                                    Asistente Contable con IA
                                </p>
                            </div>
                            <span className="ml-auto rounded-full bg-[#fbf3ee] px-2.5 py-1 text-[10px] font-semibold text-[#5A6780]">
                                Conversación de demostración
                            </span>
                        </div>
                        <div className="mt-4 flex flex-col gap-3">
                            {conversation.map(([question, answer], index) => (
                                <Reveal
                                    key={question}
                                    delay={300 + index * 250}
                                    y={12}
                                    className="flex flex-col gap-2"
                                >
                                    <p className="ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-[#650000] px-4 py-2.5 text-sm text-white">
                                        {question}
                                    </p>
                                    <p className="max-w-[90%] rounded-2xl rounded-bl-md border border-[#f0e7e4] bg-[#fbf8f6] px-4 py-2.5 text-sm leading-6 text-[#26354D]">
                                        {answer}
                                    </p>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </Reveal>
            </div>
        </section>
    );
}

export function SecuritySection() {
    const items = [
        [
            Fingerprint,
            'Doble factor y passkeys',
            'Autenticación de dos factores y llaves de acceso para proteger cada cuenta.',
        ],
        [
            Network,
            'Datos aislados',
            'Cada organización está separada a nivel de base de datos; cada consulta valida permisos.',
        ],
        [
            KeyRound,
            'Roles y permisos',
            'Cada persona ve y hace solo lo que su rol permite, por oficina y empresa.',
        ],
        [
            FileCheck2,
            'Auditoría',
            'Registro de accesos y cambios críticos para revisar actividades sensibles.',
        ],
        [
            LockKeyhole,
            'Secretos cifrados',
            'Credenciales e integraciones se guardan cifradas y nunca se muestran completas.',
        ],
        [
            DatabaseBackup,
            'Copias de respaldo',
            'Respaldos automáticos de la información incluidos en todos los planes.',
        ],
    ] as const;

    return (
        <section
            id="seguridad"
            className="scroll-mt-24 border-y border-[#efe4e1] bg-white py-20 sm:py-24"
        >
            <div className="mx-auto grid max-w-7xl items-center gap-12 px-5 sm:px-8 lg:grid-cols-[.8fr_1.2fr]">
                <Reveal className="text-center lg:text-left">
                    <div className="relative mx-auto grid size-40 place-items-center lg:mx-0">
                        <div className="absolute inset-4 rounded-full bg-[#780606]/25 blur-2xl" />
                        <div className="relative grid size-32 [transform:perspective(600px)_rotateX(10deg)_rotateY(-14deg)] place-items-center rounded-[36%] bg-[linear-gradient(150deg,#a3161c,#650000_55%,#370000)] shadow-[0_30px_60px_-20px_rgba(101,0,0,0.75),inset_0_2px_0_rgba(255,255,255,0.25)]">
                            <ShieldCheck className="size-16 text-[#f3d9a4] drop-shadow-[0_4px_10px_rgba(0,0,0,0.35)]" />
                        </div>
                    </div>
                    <p className={`mt-8 ${eyebrow}`}>Seguridad por diseño</p>
                    <h2 className={`mt-3 ${sectionTitle}`}>
                        La confianza no es una función adicional.
                    </h2>
                    <p className="mt-5 leading-7 text-[#5A6780]">
                        Es parte de cada decisión técnica, desde el inicio de
                        sesión hasta el acceso a cada registro.
                    </p>
                </Reveal>
                <div className="grid gap-4 sm:grid-cols-2">
                    {items.map(([Icon, title, text], index) => (
                        <Reveal key={title} delay={(index % 2) * 80}>
                            <article className="h-full rounded-[20px] border border-[#efe4e1] bg-[#fdfaf9] p-5">
                                <Icon className="size-5 text-[#780606]" />
                                <h3 className="mt-3 font-bold text-[#0C1424]">
                                    {title}
                                </h3>
                                <p className="mt-1.5 text-sm leading-6 text-[#5A6780]">
                                    {text}
                                </p>
                            </article>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

const faqs = [
    [
        '¿Necesito instalar algo?',
        'No. Atlantia Finance funciona en el navegador, en computadora, tableta o teléfono. Solo necesitas crear tu cuenta.',
    ],
    [
        '¿Puedo administrar varias oficinas?',
        'Sí. Desde el plan Multi-oficina supervisas varias sedes con una vista consolidada, manteniendo los datos, equipos y permisos de cada oficina separados.',
    ],
    [
        '¿Cómo funciona Luzma?',
        'Luzma es el asistente con IA integrado en la plataforma. Consulta solo la información que tu rol puede ver, resume, detecta pendientes y prepara borradores. Las acciones sensibles siempre requieren aprobación de una persona.',
    ],
    [
        '¿Puedo cambiar de plan?',
        'Sí. Al mejorar tu plan el cambio aplica de inmediato con un ajuste prorrateado. Si bajas de plan, se programa para el final del período, siempre que tu uso quepa en los nuevos límites.',
    ],
    [
        '¿Cómo protegen mi información?',
        'Cada organización está aislada en la base de datos, el acceso usa doble factor y permisos por rol, los cambios críticos quedan auditados y los secretos se guardan cifrados.',
    ],
    [
        '¿Puedo invitar a mis clientes?',
        'Sí. Con el portal del cliente compartes documentos, solicitudes y aprobaciones con tus clientes, sin darles acceso a la información de los demás.',
    ],
];

export function FaqSection() {
    const [open, setOpen] = useState<number | null>(0);

    return (
        <section id="faq" className="scroll-mt-24 py-20 sm:py-24">
            <div className="mx-auto max-w-3xl px-5 sm:px-8">
                <Reveal className="text-center">
                    <p className={eyebrow}>Preguntas frecuentes</p>
                    <h2 className={`mt-3 ${sectionTitle}`}>
                        Resolvemos tus dudas
                    </h2>
                </Reveal>
                <div className="mt-10 flex flex-col gap-3">
                    {faqs.map(([question, answer], index) => {
                        const expanded = open === index;

                        return (
                            <Reveal key={question} delay={index * 50} y={12}>
                                <div
                                    className={`rounded-2xl border bg-white transition-colors ${expanded ? 'border-[#d9b9b4] shadow-[0_18px_40px_-30px_rgba(55,0,0,0.4)]' : 'border-[#efe4e1]'}`}
                                >
                                    <h3>
                                        <button
                                            type="button"
                                            id={`faq-q-${index}`}
                                            aria-expanded={expanded}
                                            aria-controls={`faq-a-${index}`}
                                            onClick={() =>
                                                setOpen(expanded ? null : index)
                                            }
                                            className="flex w-full items-center justify-between gap-4 rounded-2xl px-5 py-4 text-left font-semibold text-[#0C1424] focus-visible:outline-2 focus-visible:outline-[#780606]"
                                        >
                                            {question}
                                            <ChevronDown
                                                className="size-5 shrink-0 text-[#780606]"
                                                style={{
                                                    transform: expanded
                                                        ? 'rotate(180deg)'
                                                        : 'none',
                                                    transition: `transform 350ms ${EASE}`,
                                                }}
                                            />
                                        </button>
                                    </h3>
                                    <div
                                        id={`faq-a-${index}`}
                                        role="region"
                                        aria-labelledby={`faq-q-${index}`}
                                        className="grid"
                                        style={{
                                            gridTemplateRows: expanded
                                                ? '1fr'
                                                : '0fr',
                                            transition: `grid-template-rows 400ms ${EASE}`,
                                        }}
                                    >
                                        <div className="overflow-hidden">
                                            <p className="px-5 pb-5 text-sm leading-6 text-[#5A6780]">
                                                {answer}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

export function FinalCta({ onContact }: { onContact: () => void }) {
    return (
        <section className="px-5 pb-20 sm:px-8 sm:pb-24">
            <Reveal className="relative mx-auto max-w-7xl overflow-hidden rounded-[32px] bg-[linear-gradient(140deg,#8A0B0B,#650000_45%,#370000)] px-6 py-16 text-center text-white shadow-[0_40px_90px_-40px_rgba(55,0,0,0.8)] sm:px-12">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_0%,rgba(212,164,76,0.32),transparent_55%)]" />
                <div className="relative">
                    <BadgeCheck className="mx-auto size-10 text-[#D4A44C]" />
                    <h2 className="mx-auto mt-5 max-w-3xl text-3xl font-bold tracking-[-0.03em] sm:text-5xl">
                        Lleva tu oficina contable al siguiente nivel.
                    </h2>
                    <p className="mx-auto mt-5 max-w-xl text-white/70">
                        Crea tu cuenta, elige tu plan y configura tu primera
                        oficina paso a paso.
                    </p>
                    <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        <Link
                            href={register()}
                            className="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-7 py-3.5 text-sm font-bold text-[#650000] shadow-xl shadow-black/20 transition duration-300 hover:-translate-y-0.5 hover:scale-[1.02]"
                        >
                            Comenzar ahora <ArrowRight className="size-4" />
                        </Link>
                        <button
                            type="button"
                            onClick={onContact}
                            className="inline-flex items-center justify-center rounded-2xl border border-white/30 bg-white/10 px-7 py-3.5 text-sm font-bold text-white backdrop-blur transition hover:bg-white/20"
                        >
                            Hablar con nosotros
                        </button>
                    </div>
                </div>
            </Reveal>
        </section>
    );
}
