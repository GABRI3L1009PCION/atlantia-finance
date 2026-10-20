import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpen,
    ChevronLeft,
    ChevronRight,
    FileText,
    Globe2,
    Landmark,
    LineChart,
    Scale,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import type { LibraryDocument } from '@/components/library/kit';

type Cover = {
    from: string;
    to: string;
    accent: string;
    text: string;
    icon: LucideIcon;
    kind: string;
};

const covers: Record<string, Cover> = {
    fiscal: {
        from: '#7a1523',
        to: '#4a0b15',
        accent: '#e2b86b',
        text: '#f6e7c8',
        icon: Scale,
        kind: 'Texto legal',
    },
    mercantil: {
        from: '#1c3f6e',
        to: '#0c2342',
        accent: '#e2b86b',
        text: '#f2e7cf',
        icon: Landmark,
        kind: 'Texto legal',
    },
    contable: {
        from: '#1f6b43',
        to: '#0d3f26',
        accent: '#e8d3a0',
        text: '#f4ecd6',
        icon: Globe2,
        kind: 'Resumen',
    },
    guia: {
        from: '#8a1c2c',
        to: '#55101b',
        accent: '#e8c98a',
        text: '#f8ecd2',
        icon: LineChart,
        kind: 'Guía práctica',
    },
    laboral: {
        from: '#0f6a6e',
        to: '#093f42',
        accent: '#e8d3a0',
        text: '#eef6f3',
        icon: Users,
        kind: 'Texto legal',
    },
    interna: {
        from: '#3b4252',
        to: '#1f2430',
        accent: '#d9c7a3',
        text: '#eef0f4',
        icon: FileText,
        kind: 'Política interna',
    },
};

const fallbackCover = covers.interna;

export const categoryTabIcon: Record<string, LucideIcon> = {
    fiscal: Scale,
    mercantil: Landmark,
    contable: FileText,
    guia: LineChart,
    laboral: Users,
    interna: BookOpen,
};

function Book({ document }: { document: LibraryDocument }) {
    const cover = covers[document.category] ?? fallbackCover;
    const Icon = cover.icon;
    const kind = document.is_full_text ? cover.kind : 'Resumen';

    return (
        <li className="w-44 shrink-0 snap-start sm:w-48">
            <Link
                href={`/library/${document.code}`}
                className="group block"
                aria-label={`Abrir ${document.title}`}
            >
                <div
                    className="relative aspect-[3/4.1] overflow-hidden rounded-l-sm rounded-r-lg shadow-[6px_10px_18px_-6px_rgba(0,0,0,.45)] transition duration-300 group-hover:-translate-y-1 group-hover:shadow-[8px_16px_24px_-8px_rgba(0,0,0,.5)]"
                    style={{
                        backgroundImage: `linear-gradient(145deg, ${cover.from}, ${cover.to})`,
                        color: cover.text,
                    }}
                >
                    <span
                        aria-hidden
                        className="absolute inset-y-0 left-0 w-3 bg-gradient-to-r from-black/45 to-transparent"
                    />
                    <span
                        aria-hidden
                        className="absolute inset-y-0 right-0 w-1.5 bg-[repeating-linear-gradient(0deg,rgba(255,255,255,.55)_0_1px,rgba(210,200,180,.9)_1px_3px)] opacity-70"
                    />
                    <span
                        aria-hidden
                        className="absolute inset-3 rounded-sm border"
                        style={{ borderColor: `${cover.accent}88` }}
                    />
                    <div className="relative flex h-full flex-col items-center px-5 pt-6 pb-4 text-center">
                        <Icon
                            className="size-7"
                            style={{ color: cover.accent }}
                        />
                        <p
                            className="mt-3 font-serif text-[15px] leading-tight font-bold tracking-wide uppercase"
                            style={{ textWrap: 'balance' }}
                        >
                            {document.short_title}
                        </p>
                        {document.reference && (
                            <p
                                className="mt-2 font-serif text-[11px]"
                                style={{ color: cover.accent }}
                            >
                                {document.reference}
                            </p>
                        )}
                        <p className="mt-auto text-[10px] tracking-[0.25em] uppercase opacity-80">
                            Guatemala
                        </p>
                    </div>
                </div>
                <p className="mt-3 text-xs font-bold text-[#061633]">{kind}</p>
                <p className="line-clamp-2 min-h-8 text-[11px] text-[#526b92]">
                    {document.title}
                </p>
                <span className="mt-2 inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-[#640000] text-xs font-bold text-white transition group-hover:bg-[#7a0a0a]">
                    Consultar <ArrowRight className="size-3.5" />
                </span>
            </Link>
        </li>
    );
}

/** Horizontal carousel of document covers with arrows and position dots. */
export function BookCarousel({ documents }: { documents: LibraryDocument[] }) {
    const track = useRef<HTMLUListElement | null>(null);
    const [position, setPosition] = useState({ page: 0, pages: 1 });

    const measure = useCallback(() => {
        const element = track.current;

        if (!element) {
            return;
        }

        const pages = Math.max(
            1,
            Math.ceil(element.scrollWidth / element.clientWidth - 0.05),
        );
        const page = Math.min(
            pages - 1,
            Math.round(
                (element.scrollLeft /
                    Math.max(1, element.scrollWidth - element.clientWidth)) *
                    (pages - 1),
            ),
        );

        setPosition((current) =>
            current.page === page && current.pages === pages
                ? current
                : { page, pages },
        );
    }, []);

    const attach = useCallback(
        (element: HTMLUListElement | null) => {
            track.current = element;
            measure();
        },
        [measure],
    );

    const scrollBy = (direction: 1 | -1) => {
        const element = track.current;

        element?.scrollBy({
            left: direction * element.clientWidth * 0.85,
            behavior: 'smooth',
        });
    };

    return (
        <div className="relative">
            {position.pages > 1 && (
                <>
                    <button
                        type="button"
                        onClick={() => scrollBy(-1)}
                        disabled={position.page === 0}
                        aria-label="Anterior"
                        className="absolute top-1/3 -left-3 z-10 grid size-10 place-items-center rounded-full border border-slate-200 bg-white shadow-md transition hover:bg-amber-50 disabled:pointer-events-none disabled:opacity-0"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => scrollBy(1)}
                        disabled={position.page >= position.pages - 1}
                        aria-label="Siguiente"
                        className="absolute top-1/3 -right-3 z-10 grid size-10 place-items-center rounded-full border border-slate-200 bg-white shadow-md transition hover:bg-amber-50 disabled:pointer-events-none disabled:opacity-0"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                </>
            )}
            <ul
                ref={attach}
                onScroll={measure}
                className="flex snap-x snap-mandatory [scrollbar-width:none] gap-5 overflow-x-auto scroll-smooth px-2 pt-2 pb-4 [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden"
            >
                {documents.map((document) => (
                    <Book key={document.id} document={document} />
                ))}
            </ul>
            {position.pages > 1 && (
                <div
                    className="flex justify-end gap-1.5 pr-2"
                    aria-hidden="true"
                >
                    {Array.from({ length: position.pages }, (_, index) => (
                        <span
                            key={index}
                            className={`size-2 rounded-full ${index === position.page ? 'bg-[#640000]' : 'bg-slate-300'}`}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
