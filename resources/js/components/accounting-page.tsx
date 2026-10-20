import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { Company } from '@/components/accounting-section';

export const cell = 'px-3 py-2.5 text-sm';
export const head =
    'px-3 py-2.5 text-left text-xs font-bold uppercase tracking-wide text-slate-500';

/** Common frame of the accounting screens: title, company and optional header actions. */
export function PageShell({
    company,
    title,
    subtitle,
    icon: Icon,
    actions,
    children,
}: {
    company: Company;
    title: string;
    subtitle: string;
    icon: LucideIcon;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title={title} />
            <div className="mx-auto max-w-[1500px] space-y-5">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid size-12 place-items-center rounded-2xl bg-[#7d0808] text-white shadow-lg shadow-red-950/15">
                            <Icon className="size-6" />
                        </span>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                {subtitle} · {company.name} · NIT{' '}
                                {company.tax_id}
                            </p>
                        </div>
                    </div>
                    {actions && (
                        <div className="flex flex-wrap gap-2">{actions}</div>
                    )}
                </header>
                {children}
            </div>
        </div>
    );
}

export function Stat({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    tone?: 'default' | 'good' | 'bad';
}) {
    const color =
        tone === 'good'
            ? 'text-emerald-700'
            : tone === 'bad'
              ? 'text-red-700'
              : 'text-[#142135]';

    return (
        <div className="rounded-2xl border border-[#ece3e3] bg-white p-4 shadow-sm">
            <p className="text-xs font-bold tracking-wide text-slate-500 uppercase">
                {label}
            </p>
            <p className={`mt-1 text-xl font-semibold ${color}`}>{value}</p>
        </div>
    );
}

export function Badge({
    children,
    tone = 'slate',
}: {
    children: ReactNode;
    tone?: 'slate' | 'green' | 'amber' | 'red';
}) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        green: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-800',
        red: 'bg-red-50 text-red-700',
    };

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${tones[tone]}`}
        >
            {children}
        </span>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
            {children}
        </p>
    );
}

export function Label({
    text,
    children,
}: {
    text: string;
    children: ReactNode;
}) {
    return (
        <label className="block text-xs font-bold tracking-wide text-slate-600 uppercase">
            {text}
            {children}
        </label>
    );
}

export function exportUrl(base: string, params: Record<string, string>) {
    return `${base}?${new URLSearchParams(params).toString()}`;
}
