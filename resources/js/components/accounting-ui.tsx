import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export const field =
    'mt-1 w-full rounded-xl border border-[#d9dce3] bg-white px-3 py-2.5 text-sm text-[#142135] outline-none focus:border-[#640000] focus:ring-2 focus:ring-[#640000]/15';
export const primary =
    'inline-flex items-center justify-center gap-2 rounded-xl bg-[#640000] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#420001] disabled:cursor-not-allowed disabled:opacity-50';
export const secondary =
    'inline-flex items-center justify-center gap-2 rounded-xl border border-[#e3d5d5] bg-white px-4 py-2.5 text-sm font-semibold text-[#640000] hover:bg-[#fbf4f4] disabled:opacity-50';
export const money = (cents: number | string) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
    }).format(Number(cents) / 100);
export type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export function Pager({ page }: { page: Page<unknown> }) {
    return (
        <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500">
            <span>
                {page.total} registros · Página {page.current_page} de{' '}
                {page.last_page}
            </span>
            <div className="flex gap-2">
                {page.prev_page_url && (
                    <Link
                        preserveScroll
                        preserveState
                        href={page.prev_page_url}
                        className={secondary}
                    >
                        Anterior
                    </Link>
                )}
                {page.next_page_url && (
                    <Link
                        preserveScroll
                        preserveState
                        href={page.next_page_url}
                        className={secondary}
                    >
                        Siguiente
                    </Link>
                )}
            </div>
        </div>
    );
}
export function Card({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-[#ece3e3] bg-white p-5 shadow-sm sm:p-6">
            <h2 className="mb-4 text-lg font-semibold text-[#142135]">
                {title}
            </h2>
            {children}
        </section>
    );
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length ? (
        <div
            role="alert"
            className="my-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"
        >
            {[...new Set(Object.values(errors))].map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
    ) : null;
}
