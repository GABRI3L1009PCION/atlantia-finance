import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

export const PAGE_SIZES = [5, 10, 15, 20, 50];

type Options<T> = {
    /** Text the search box looks into for each row. */
    searchText: (row: T) => string;
    /** Extra filter (status, city...) applied before paging. */
    predicate?: (row: T) => boolean;
    initialPageSize?: number;
};

/** Search, page size and page position of a list that is already loaded in the browser. */
export function useTableControls<T>(
    rows: T[],
    { searchText, predicate, initialPageSize = 10 }: Options<T>,
) {
    const [search, setSearch] = useState('');
    const [pageSize, setPageSize] = useState(initialPageSize);
    const [position, setPosition] = useState<{
        search: string;
        pageSize: number;
        predicate: unknown;
        page: number;
    }>({ search: '', pageSize: initialPageSize, predicate, page: 1 });

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();

        return rows.filter(
            (row) =>
                (predicate ? predicate(row) : true) &&
                (term === '' || searchText(row).toLowerCase().includes(term)),
        );
    }, [rows, search, predicate, searchText]);

    const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    // A new search, filter or page size starts again at the first page.
    const sameView =
        position.search === search &&
        position.pageSize === pageSize &&
        position.predicate === predicate;
    const current = Math.min(sameView ? position.page : 1, pages);
    const setPage = (page: number) =>
        setPosition({ search, pageSize, predicate, page });

    return {
        search,
        setSearch,
        pageSize,
        setPageSize,
        page: current,
        setPage,
        pages,
        total: rows.length,
        count: filtered.length,
        pageRows: filtered.slice((current - 1) * pageSize, current * pageSize),
    };
}

const control =
    'h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15';

/** Search box, size selector and slots for filters, shown above a list. */
export function TableToolbar({
    search,
    onSearch,
    placeholder = 'Buscar…',
    pageSize,
    onPageSize,
    children,
}: {
    search: string;
    onSearch: (value: string) => void;
    placeholder?: string;
    pageSize: number;
    onPageSize: (value: number) => void;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
            <div className="relative min-w-56 flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input
                    className={`${control} w-full pl-9`}
                    type="search"
                    value={search}
                    onChange={(event) => onSearch(event.target.value)}
                    placeholder={placeholder}
                    aria-label="Buscar"
                />
            </div>
            {children}
            <label className="flex items-center gap-2 text-sm text-slate-600">
                Ver
                <select
                    className={control}
                    value={pageSize}
                    onChange={(event) => onPageSize(Number(event.target.value))}
                    aria-label="Registros por página"
                >
                    {PAGE_SIZES.map((size) => (
                        <option key={size} value={size}>
                            {size}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}

export function TableFilterSelect({
    value,
    onChange,
    label,
    options,
}: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    options: { value: string; label: string }[];
}) {
    return (
        <select
            className={control}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            aria-label={label}
        >
            <option value="">{label}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

function pageWindow(current: number, pages: number): (number | '…')[] {
    if (pages <= 7) {
        return Array.from({ length: pages }, (_, index) => index + 1);
    }

    const around = [current - 1, current, current + 1].filter(
        (value) => value > 1 && value < pages,
    );
    const items: (number | '…')[] = [1];

    if (around[0] > 2) {
        items.push('…');
    }

    items.push(...around);

    if (around[around.length - 1] < pages - 1) {
        items.push('…');
    }

    items.push(pages);

    return items;
}

/** "Showing x–y of z" with ‹ 1 2 3 › page buttons. */
export function TablePagination({
    page,
    pages,
    pageSize,
    count,
    onPage,
    noun = 'registros',
}: {
    page: number;
    pages: number;
    pageSize: number;
    count: number;
    onPage: (page: number) => void;
    noun?: string;
}) {
    const from = count === 0 ? 0 : (page - 1) * pageSize + 1;
    const to = Math.min(page * pageSize, count);
    const button =
        'grid h-9 min-w-9 place-items-center rounded-lg border px-2 text-sm font-semibold transition disabled:opacity-40';

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
            <p>
                Mostrando {from}–{to} de {count} {noun}
            </p>
            <nav className="flex items-center gap-1" aria-label="Paginación">
                <button
                    type="button"
                    className={`${button} border-slate-300 bg-white`}
                    disabled={page <= 1}
                    onClick={() => onPage(page - 1)}
                    aria-label="Página anterior"
                >
                    <ChevronLeft className="size-4" />
                </button>
                {pageWindow(page, pages).map((item, index) =>
                    item === '…' ? (
                        <span
                            key={`gap-${index}`}
                            className="px-1 text-slate-400"
                        >
                            …
                        </span>
                    ) : (
                        <button
                            key={item}
                            type="button"
                            onClick={() => onPage(item)}
                            aria-current={item === page ? 'page' : undefined}
                            className={`${button} ${item === page ? 'border-[#640000] bg-[#640000] text-white' : 'border-slate-300 bg-white hover:border-[#c9a36a]'}`}
                        >
                            {item}
                        </button>
                    ),
                )}
                <button
                    type="button"
                    className={`${button} border-slate-300 bg-white`}
                    disabled={page >= pages}
                    onClick={() => onPage(page + 1)}
                    aria-label="Página siguiente"
                >
                    <ChevronRight className="size-4" />
                </button>
            </nav>
        </div>
    );
}
