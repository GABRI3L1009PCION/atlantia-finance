import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type Company = {
    public_id: string;
    name: string;
    tax_id: string;
    currency?: string;
};
export default function AccountingSection({
    company,
    title,
    action,
    compact = false,
    denseHeader = false,
    children,
}: {
    company: Company;
    title: string;
    action?: ReactNode;
    compact?: boolean;
    denseHeader?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="min-h-full bg-[#faf8f7] p-4 text-[#142135] sm:p-8">
            <Head title={title} />
            <div
                className={`mx-auto space-y-6 ${compact ? 'max-w-[1600px]' : 'max-w-7xl'}`}
            >
                {!compact && (
                    <header
                        className={`relative overflow-hidden bg-gradient-to-br from-[#650000] via-[#7d0808] to-[#4a0000] text-white shadow-[0_16px_40px_rgba(100,0,0,.18)] ${
                            denseHeader
                                ? 'rounded-2xl px-5 py-4 sm:px-6 sm:py-5'
                                : 'rounded-3xl p-6 sm:p-8'
                        }`}
                    >
                        <div className="pointer-events-none absolute -top-28 right-12 size-72 rounded-full border border-white/10" />
                        <div className="pointer-events-none absolute -right-16 -bottom-40 size-96 rounded-full border border-white/10" />
                        <div
                            className={`relative flex flex-wrap items-center justify-between ${
                                denseHeader ? 'gap-3' : 'gap-6'
                            }`}
                        >
                            <div>
                                <p
                                    className={`tracking-[.2em] text-rose-200 ${
                                        denseHeader ? 'text-[11px]' : 'text-xs'
                                    }`}
                                >
                                    ATLANTIA SYSTEMS / {company.name}
                                </p>
                                <h1
                                    className={`font-semibold ${
                                        denseHeader
                                            ? 'mt-1 text-2xl sm:text-3xl'
                                            : 'mt-3 text-3xl sm:text-4xl'
                                    }`}
                                >
                                    {title}
                                </h1>
                                <p
                                    className={`text-rose-100 ${
                                        denseHeader
                                            ? 'mt-1 text-xs'
                                            : 'mt-2 text-sm'
                                    }`}
                                >
                                    NIT {company.tax_id} · Control por empresa ·
                                    Moneda {company.currency ?? 'GTQ'}
                                </p>
                            </div>
                            {action}
                        </div>
                    </header>
                )}
                {children}
            </div>
        </div>
    );
}
