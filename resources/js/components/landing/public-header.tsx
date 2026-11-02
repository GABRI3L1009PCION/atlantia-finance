import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { dashboard, login, register } from '@/routes';

/**
 * The landing page's red header for the public pages that hang from it (project documentation): same logo and
 * account buttons, plus a button back to the landing page instead of the section links.
 */
export function PublicHeader({
    backHref = '/',
    backLabel = 'Regresar',
    actions,
}: {
    backHref?: string;
    backLabel?: string;
    actions?: ReactNode;
}) {
    const { auth } = usePage().props;

    return (
        <header className="sticky top-0 z-50 px-0 sm:px-4">
            <div className="mx-auto max-w-[1500px] rounded-b-[28px] border-x border-b border-white/10 bg-[#650000]/92 shadow-[0_8px_24px_-18px_rgba(55,0,0,0.35)] backdrop-blur-xl">
                <div className="mx-auto flex h-20 max-w-7xl items-center justify-between gap-3 px-5 sm:px-8">
                    <div className="flex items-center gap-3 sm:gap-5">
                        <Link
                            href={backHref}
                            className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
                        >
                            <ArrowLeft className="size-4" />
                            {backLabel}
                        </Link>
                        <Link
                            href="/"
                            className="flex items-center"
                            aria-label="Atlantia Finance, inicio"
                        >
                            <img
                                src="/images/atlantia-finance-logo.png"
                                alt="Atlantia Finance"
                                className="h-11 w-auto object-contain sm:h-13"
                            />
                        </Link>
                    </div>

                    <div className="flex items-center gap-3">
                        {actions}
                        <div className="hidden items-center gap-3 sm:flex">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-2xl bg-white px-5 py-2.5 text-sm font-bold text-[#650000] shadow-lg shadow-black/10 transition hover:-translate-y-0.5"
                                >
                                    Ir al panel
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="px-3 py-2 text-sm font-semibold text-white/85 transition hover:text-white"
                                    >
                                        Ingresar
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="inline-flex items-center gap-2 rounded-2xl bg-white px-5 py-2.5 text-sm font-bold text-[#650000] shadow-lg shadow-black/15 transition hover:-translate-y-0.5 hover:bg-[#fff6f4]"
                                    >
                                        Comenzar{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </header>
    );
}
