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
    children,
}: {
    company: Company;
    title: string;
    action?: ReactNode;
    compact?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="min-h-full bg-[#faf8f7] p-4 text-[#142135] sm:p-8">
            <Head title={title} />
            <div
                className={`mx-auto space-y-6 ${compact ? 'max-w-[1600px]' : 'max-w-7xl'}`}
            >
                {!compact && (
                    <header className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#650000] via-[#7d0808] to-[#4a0000] p-6 text-white shadow-[0_16px_40px_rgba(100,0,0,.18)] sm:p-8">
                        <div className="pointer-events-none absolute -top-28 right-12 size-72 rounded-full border border-white/10" />
                        <div className="pointer-events-none absolute -right-16 -bottom-40 size-96 rounded-full border border-white/10" />
                        <div className="relative flex flex-wrap items-start justify-between gap-6">
                            <div>
                                <p className="text-xs tracking-[.2em] text-rose-200">
                                    ATLANTIA SYSTEMS / {company.name}
                                </p>
                                <h1 className="mt-3 text-3xl font-semibold sm:text-4xl">
                                    {title}
                                </h1>
                                <p className="mt-2 text-sm text-rose-100">
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
