import { Link } from '@inertiajs/react';
import {
    BrainCircuit,
    CheckCircle2,
    LockKeyhole,
    ShieldCheck,
} from 'lucide-react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh bg-[#faf9f8] text-slate-950 [--background:#ffffff] [--border:#e2e8f0] [--foreground:#040b15] [--input:#cbd5e1] [--muted-foreground:#64748b] [--primary-foreground:#ffffff] [--primary:#420001] [--ring:#b67e7d] lg:h-svh lg:grid-cols-[46%_54%] lg:overflow-hidden">
            <aside className="relative hidden h-svh overflow-hidden bg-emerald-700 px-10 py-8 text-white lg:flex lg:flex-col">
                <div className="absolute -top-32 -left-32 size-96 rounded-full border border-white/10" />
                <div className="absolute -top-16 -left-16 size-96 rounded-full border border-white/10" />
                <div className="absolute right-[-12rem] bottom-[-12rem] size-[34rem] rounded-full bg-emerald-800/50 blur-3xl" />

                <Link href={home()} className="relative z-10 w-fit">
                    <span className="text-sm font-bold tracking-[0.24em] uppercase">
                        Atlantia Systems
                    </span>
                </Link>

                <div className="relative z-10 m-auto flex w-full max-w-xl flex-col items-center text-center">
                    <img
                        src="/images/atlantia-finance-logo.png"
                        alt="Atlantia Finance"
                        className="w-full max-w-[430px] drop-shadow-2xl"
                    />
                    <div className="mt-5 h-px w-20 bg-emerald-300" />
                    <p className="mt-5 text-sm font-semibold tracking-[0.2em] text-white/90 uppercase">
                        Gestión contable inteligente y segura
                    </p>
                    <p className="mt-2 max-w-md text-sm leading-6 text-white/65">
                        Clientes, oficinas, obligaciones y documentos bajo una
                        sola visión, con IA asistida y seguridad desde el
                        diseño.
                    </p>
                </div>

                <div className="relative z-10 grid grid-cols-3 gap-3 text-[11px] font-semibold text-white/75">
                    <span className="flex items-center gap-2">
                        <ShieldCheck className="size-4 text-emerald-300" />{' '}
                        OWASP
                    </span>
                    <span className="flex items-center justify-center gap-2">
                        <BrainCircuit className="size-4 text-emerald-300" /> IA
                        asistida
                    </span>
                    <span className="flex items-center justify-end gap-2">
                        <LockKeyhole className="size-4 text-emerald-300" />{' '}
                        Datos aislados
                    </span>
                </div>
            </aside>

            <main className="flex min-h-svh overflow-y-auto px-5 py-4 sm:px-10 lg:h-svh lg:min-h-0 lg:px-16 lg:py-4">
                <div className="m-auto w-full max-w-md">
                    <Link
                        href={home()}
                        className="mb-3 flex justify-center lg:hidden"
                    >
                        <img
                            src="/images/atlantia-finance-logo.png"
                            alt="Atlantia Finance"
                            className="h-auto w-40"
                        />
                    </Link>

                    <div className="mb-3">
                        <div className="mb-2.5 inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-700 [@media(max-height:900px)]:hidden">
                            <CheckCircle2 className="size-3.5" /> Acceso
                            protegido
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-950">
                            {title}
                        </h1>
                        <p className="mt-1.5 text-sm leading-5 text-slate-600">
                            {description}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xl shadow-slate-950/5 sm:p-5">
                        {children}
                    </div>

                    <p className="mt-3 text-center text-xs leading-5 text-slate-500 [@media(max-height:900px)]:hidden">
                        Protegido con cifrado, control de sesiones y limitación
                        de intentos de acceso.
                    </p>
                </div>
            </main>
        </div>
    );
}
