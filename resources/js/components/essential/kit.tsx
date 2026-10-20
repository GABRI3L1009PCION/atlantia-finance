import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { input } from '@/components/office-admin/kit';

export type EssentialContext = {
    owner: boolean;
    solo: boolean;
    badges: { tasks: number; obligations: number };
};

export type Paged = {
    total: number;
    page: number;
    pages: number;
    per_page: number;
};

export const selectClass = `${input} !w-auto min-w-40`;

export const priorityLabel: Record<string, [string, string]> = {
    urgent: ['Urgente', 'red'],
    high: ['Alta', 'amber'],
    normal: ['Normal', 'blue'],
    low: ['Baja', 'slate'],
};

export const taskTone: Record<string, string> = {
    pending: 'slate',
    in_progress: 'blue',
    ready_for_review: 'amber',
    in_review: 'amber',
    changes_requested: 'violet',
    approved: 'green',
    completed: 'green',
    cancelled: 'slate',
    blocked: 'red',
    waiting_client: 'violet',
    waiting_external: 'violet',
    not_applicable: 'slate',
};

export const obligationTone: Record<string, string> = {
    pending: 'slate',
    in_progress: 'blue',
    ready_for_review: 'amber',
    changes_requested: 'violet',
    approved: 'green',
    completed: 'green',
    cancelled: 'slate',
    not_applicable: 'slate',
};

export const felTone: Record<string, string> = {
    awaiting_sat: 'amber',
    draft: 'slate',
    certified: 'green',
    failed: 'red',
    cancelled: 'slate',
    pending: 'blue',
    processing: 'blue',
};

export const felLabel: Record<string, string> = {
    awaiting_sat: 'Pendiente de emitir en SAT',
    draft: 'Borrador',
    certified: 'Emitida',
    failed: 'Con error',
    cancelled: 'Anulada',
    pending: 'En cola',
    processing: 'Procesando',
};

/** Reads and writes the filters of a server-paginated list in the address, so every view can be shared and reloaded. */
export function useServerFilters(path: string) {
    const page = usePage();

    return (changes: Record<string, string | number | null>) => {
        const current = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        const next = Object.fromEntries(
            Object.entries({ ...current, page: '1', ...changes })
                .map(([key, value]) => [
                    key,
                    value === null ? '' : String(value),
                ])
                .filter(([, value]) => value !== ''),
        );

        router.get(path, next, { preserveScroll: true, preserveState: true });
    };
}

export function SearchBox({
    value,
    onSubmit,
    placeholder,
}: {
    value: string;
    onSubmit: (value: string) => void;
    placeholder: string;
}) {
    const [text, setText] = useState(value);

    return (
        <form
            className="relative min-w-56 flex-1"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(text.trim());
            }}
        >
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <input
                className={`${input} pl-9`}
                type="search"
                value={text}
                placeholder={placeholder}
                onChange={(event) => setText(event.target.value)}
                onBlur={() => text.trim() !== value && onSubmit(text.trim())}
            />
        </form>
    );
}

export function Pager({
    paged,
    onPage,
}: {
    paged: Paged;
    onPage: (page: number) => void;
}) {
    const from = paged.total === 0 ? 0 : (paged.page - 1) * paged.per_page + 1;
    const to = Math.min(paged.total, paged.page * paged.per_page);

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-3 text-xs text-slate-600">
            <span>
                {from}–{to} de {paged.total}
            </span>
            <div className="flex items-center gap-1">
                <button
                    type="button"
                    className="grid size-8 place-items-center rounded-lg border border-slate-300 disabled:opacity-40"
                    disabled={paged.page <= 1}
                    aria-label="Página anterior"
                    onClick={() => onPage(paged.page - 1)}
                >
                    <ChevronLeft className="size-4" />
                </button>
                <span className="px-2 font-semibold">
                    {paged.page} / {paged.pages}
                </span>
                <button
                    type="button"
                    className="grid size-8 place-items-center rounded-lg border border-slate-300 disabled:opacity-40"
                    disabled={paged.page >= paged.pages}
                    aria-label="Página siguiente"
                    onClick={() => onPage(paged.page + 1)}
                >
                    <ChevronRight className="size-4" />
                </button>
            </div>
        </div>
    );
}

export function Stat({
    label,
    value,
    hint,
    tone = 'slate',
    href,
}: {
    label: string;
    value: ReactNode;
    hint?: string;
    tone?: 'slate' | 'red' | 'amber' | 'green' | 'blue';
    href?: string;
}) {
    const color = {
        slate: 'text-[#061633]',
        red: 'text-red-700',
        amber: 'text-amber-700',
        green: 'text-emerald-700',
        blue: 'text-blue-700',
    }[tone];
    const body = (
        <>
            <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                {label}
            </p>
            <p className={`mt-1 text-3xl font-black ${color}`}>{value}</p>
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </>
    );
    const className =
        'block rounded-xl border border-[#e7e1dd] bg-white p-4 shadow-[0_8px_22px_-18px_rgba(49,11,8,.45)]';

    return href ? (
        <a
            href={href}
            className={`${className} transition hover:border-[#c9a36a]`}
        >
            {body}
        </a>
    ) : (
        <div className={className}>{body}</div>
    );
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    if (bytes < 1024 ** 3) {
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    }

    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

export type PrimaryAction = {
    key: string;
    label: string;
    destination: string | null;
    enabled: boolean;
    disabled_reason: string | null;
    method: string;
};

export const priorityTone: Record<string, string> = {
    critical: 'red',
    urgent: 'red',
    high: 'amber',
    normal: 'blue',
    low: 'slate',
};

/** The one button the backend resolved: starting is an action, everything else opens the real work. */
export function PrimaryActionButton({
    action,
    startUrl,
}: {
    action: PrimaryAction;
    startUrl: string;
}) {
    const [busy, setBusy] = useState(false);
    const className = action.enabled
        ? 'inline-flex h-8 items-center rounded-md bg-blue-600 px-3 text-xs font-bold whitespace-nowrap text-white'
        : 'inline-flex h-8 items-center rounded-md bg-slate-200 px-3 text-xs font-bold whitespace-nowrap text-slate-500';

    if (action.method === 'POST') {
        return (
            <button
                type="button"
                className={className}
                disabled={busy || !action.enabled}
                title={action.disabled_reason ?? undefined}
                onClick={() => {
                    setBusy(true);
                    router.post(
                        startUrl,
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                {action.label}
            </button>
        );
    }

    return action.enabled && action.destination ? (
        <Link href={action.destination} className={className}>
            {action.label}
        </Link>
    ) : (
        <span className={className} title={action.disabled_reason ?? undefined}>
            {action.label}
        </span>
    );
}

export function ProgressBar({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-slate-400">—</span>;
    }

    return (
        <div className="min-w-24">
            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                <span
                    className={`block h-full ${value >= 100 ? 'bg-emerald-500' : 'bg-blue-500'}`}
                    style={{ width: `${value}%` }}
                />
            </div>
            <span className="text-[11px] text-slate-500">{value}%</span>
        </div>
    );
}
