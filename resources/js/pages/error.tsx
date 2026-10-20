import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Clock3,
    FileQuestion,
    Hourglass,
    LockKeyhole,
    ServerCrash,
    ShieldAlert,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type Props = {
    status: number;
    detail: string | null;
    reference: string | null;
};

const copy: Record<
    number,
    { title: string; message: string; icon: LucideIcon }
> = {
    403: {
        title: 'No tienes autorización para esta sección',
        message:
            'Tu cuenta no tiene permiso para entrar a esta página o realizar esta acción. Si crees que es un error, pide a la persona administradora de tu oficina que revise tu rol.',
        icon: ShieldAlert,
    },
    404: {
        title: 'No encontramos esta página',
        message:
            'La dirección que buscas no existe o ya no está disponible. Revisa que esté bien escrita o vuelve a tu panel.',
        icon: FileQuestion,
    },
    419: {
        title: 'Tu sesión de formulario expiró',
        message:
            'Por seguridad, la página estuvo abierta demasiado tiempo. Recárgala e inténtalo de nuevo.',
        icon: Clock3,
    },
    429: {
        title: 'Demasiados intentos',
        message:
            'Hiciste muchas solicitudes seguidas. Espera un momento antes de volver a intentarlo.',
        icon: Hourglass,
    },
    500: {
        title: 'Algo salió mal de nuestro lado',
        message:
            'Ya registramos el problema. Intenta de nuevo en unos minutos; si continúa, comparte el código de referencia con soporte.',
        icon: ServerCrash,
    },
    503: {
        title: 'Estamos en mantenimiento',
        message:
            'El servicio no está disponible por un momento. Vuelve a intentarlo en unos minutos.',
        icon: Wrench,
    },
};

export default function ErrorPage({ status, detail, reference }: Props) {
    const page = usePage<{
        auth?: { user: unknown };
        workspaceNavigation?: { homePath?: string } | null;
    }>();
    const view = copy[status] ?? copy[500];
    const Icon = status === 403 ? LockKeyhole : view.icon;
    const signedIn = Boolean(page.props.auth?.user);
    const home = page.props.workspaceNavigation?.homePath ?? '/';

    return (
        <>
            <Head title={view.title} />
            <main className="relative grid min-h-dvh place-items-center overflow-hidden bg-[#faf6f4] px-4 py-10 text-[#142135]">
                <div className="pointer-events-none absolute -top-40 -left-32 size-[34rem] rounded-full bg-[#760b16]/10 blur-3xl" />
                <div className="pointer-events-none absolute -right-32 -bottom-40 size-[30rem] rounded-full bg-[#b98f3a]/15 blur-3xl" />
                <section className="relative w-full max-w-lg rounded-3xl border border-white bg-white/85 p-8 text-center shadow-[0_30px_80px_-30px_rgba(118,11,22,.45)] backdrop-blur sm:p-10">
                    <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-[#760b16] text-white shadow-lg shadow-[#760b16]/30">
                        <Icon className="size-8" strokeWidth={1.8} />
                    </div>
                    <p className="mt-6 text-xs font-bold tracking-[0.3em] text-[#9b5757] uppercase">
                        Error {status}
                    </p>
                    <h1 className="mt-2 text-2xl font-black text-balance sm:text-3xl">
                        {view.title}
                    </h1>
                    <p className="mt-3 text-[15px] leading-6 text-slate-600">
                        {view.message}
                    </p>
                    {detail && (
                        <p className="mt-4 rounded-xl border border-[#760b16]/15 bg-[#760b16]/5 px-4 py-3 text-sm font-medium text-[#760b16]">
                            {detail}
                        </p>
                    )}
                    <div className="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                        {signedIn ? (
                            <Link
                                href={home}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl bg-[#760b16] px-5 text-sm font-bold text-white transition hover:bg-[#93131f]"
                            >
                                Ir a mi panel
                            </Link>
                        ) : (
                            <Link
                                href="/login"
                                className="inline-flex min-h-11 items-center justify-center rounded-xl bg-[#760b16] px-5 text-sm font-bold text-white transition hover:bg-[#93131f]"
                            >
                                Iniciar sesión
                            </Link>
                        )}
                        <button
                            type="button"
                            onClick={() => window.history.back()}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 text-sm font-bold text-[#142135] transition hover:border-[#760b16]/40"
                        >
                            <ArrowLeft className="size-4" /> Volver atrás
                        </button>
                    </div>
                    {reference && (
                        <p className="mt-6 text-xs text-slate-400">
                            Código de referencia:{' '}
                            <span className="font-mono">{reference}</span>
                        </p>
                    )}
                </section>
            </main>
        </>
    );
}
