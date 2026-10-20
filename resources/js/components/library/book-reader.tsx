import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Bookmark,
    BookOpen,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FileText,
    Info,
    List,
    Maximize2,
    Minimize2,
    Search,
    Sparkles,
    X,
} from 'lucide-react';
import {
    Fragment,
    useEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import type { LibraryDocument, LibrarySection } from '@/components/library/kit';
import { askLuzma } from '@/components/luzma/client';

type Entry = {
    id: string;
    key: string;
    label: string;
    heading: string | null;
    path: string | null;
    text: string;
    first: boolean;
    reform: string | null;
};

type Page = { entries: Entry[] };

type TreeNode = {
    id: string;
    title: string;
    firstKey: string;
    children: TreeNode[];
    articles: { key: string; title: string }[];
};

type Saved = { anchor?: string; scale?: number; mode?: 'double' | 'single' };

const SCALES = [0.9, 1, 1.12, 1.25, 1.4];
const wideQuery = '(min-width: 1024px)';

const storageKey = (code: string) => `atlantia.library.${code}`;

function readStorage<T>(key: string): T | null {
    try {
        const raw = window.localStorage.getItem(key);

        return raw ? (JSON.parse(raw) as T) : null;
    } catch {
        return null;
    }
}

function writeStorage(key: string, value: unknown): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage can be blocked (private window); reading still works.
    }
}

/** Splits `text` at the last space (or line break) that keeps the first part within `max` characters. */
function takeUpTo(text: string, max: number): [string, string] {
    if (text.length <= max) {
        return [text, ''];
    }

    const cut = Math.max(
        text.lastIndexOf(' ', max),
        text.lastIndexOf('\n', max),
    );
    const at = cut > max * 0.4 ? cut : max;

    return [text.slice(0, at).trimEnd(), text.slice(at).trimStart()];
}

function paginate(
    sections: LibrarySection[],
    budget: number,
    headCost: number,
    contCost: number,
): Page[] {
    const pages: Page[] = [];
    let current: Page = { entries: [] };
    let used = 0;
    const turn = () => {
        pages.push(current);
        current = { entries: [] };
        used = 0;
    };

    for (const section of sections) {
        let rest = section.content;
        let index = 0;

        do {
            const overhead = index === 0 ? headCost : contCost;
            let space = budget - used - overhead;

            // Too little room left on the page: the text starts on the next one.
            if (space < budget * 0.15 && current.entries.length > 0) {
                turn();
                space = budget - overhead;
            }

            const [head, tail] = takeUpTo(rest, Math.max(40, space));

            current.entries.push({
                id: `${section.key}:${index}`,
                key: section.key,
                label: section.label,
                heading: section.heading,
                path: section.path,
                text: head,
                first: index === 0,
                reform: index === 0 ? section.reform_note : null,
            });
            used += head.length + overhead;
            rest = tail;
            index += 1;

            if (rest !== '') {
                turn();
            }
        } while (rest !== '');
    }

    if (current.entries.length > 0) {
        pages.push(current);
    }

    return pages;
}

function buildTree(sections: LibrarySection[]) {
    const root: TreeNode = {
        id: '',
        title: '',
        firstKey: '',
        children: [],
        articles: [],
    };
    const nodes = new Map<string, TreeNode>();
    const ancestors = new Map<string, string[]>();

    for (const section of sections) {
        const segments = (section.path ?? '')
            .split(' · ')
            .map((segment) => segment.trim())
            .filter(Boolean);
        let parent = root;
        let id = '';
        const ids: string[] = [];

        for (const segment of segments) {
            id = id === '' ? segment : `${id}|${segment}`;
            let node = nodes.get(id);

            if (!node) {
                node = {
                    id,
                    title: segment,
                    firstKey: section.key,
                    children: [],
                    articles: [],
                };
                nodes.set(id, node);
                parent.children.push(node);
            }

            parent = node;
            ids.push(id);
        }

        parent.articles.push({
            key: section.key,
            title: `${section.label}${section.heading ? ` · ${section.heading}` : ''}`,
        });
        ancestors.set(section.key, ids);
    }

    return { root, ancestors };
}

const normalize = (text: string): string =>
    text
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');

function highlight(text: string, term: string): ReactNode {
    if (term.trim().length < 2) {
        return text;
    }

    const needle = term.trim().toLowerCase();
    const haystack = text.toLowerCase();
    const parts: ReactNode[] = [];
    let cursor = 0;

    for (
        let found = haystack.indexOf(needle);
        found !== -1;
        found = haystack.indexOf(needle, cursor)
    ) {
        if (found > cursor) {
            parts.push(
                <Fragment key={`t${cursor}`}>
                    {text.slice(cursor, found)}
                </Fragment>,
            );
        }

        cursor = found + needle.length;
        parts.push(
            <mark key={`m${found}`} className="rounded bg-amber-200 px-0.5">
                {text.slice(found, cursor)}
            </mark>,
        );
    }

    if (cursor < text.length) {
        parts.push(
            <Fragment key={`t${cursor}`}>{text.slice(cursor)}</Fragment>,
        );
    }

    return parts;
}

function useWide(): boolean {
    return useSyncExternalStore(
        (notify) => {
            const media = window.matchMedia(wideQuery);
            media.addEventListener('change', notify);

            return () => media.removeEventListener('change', notify);
        },
        () => window.matchMedia(wideQuery).matches,
        () => true,
    );
}

const toolButton =
    'inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-[#3a2a22] transition hover:bg-amber-50 disabled:opacity-40';

export function BookReader({
    doc,
    sections,
    start,
}: {
    doc: LibraryDocument;
    sections: LibrarySection[];
    start: string | null;
}) {
    const wide = useWide();
    const root = useRef<HTMLDivElement | null>(null);
    const [scaleIndex, setScaleIndex] = useState(
        () =>
            Math.max(
                0,
                SCALES.indexOf(
                    readStorage<Saved>(storageKey(doc.code))?.scale ?? 1,
                ),
            ) || 1,
    );
    const [mode, setMode] = useState<'double' | 'single'>(
        () => readStorage<Saved>(storageKey(doc.code))?.mode ?? 'double',
    );
    const double = wide && mode === 'double';
    const bookBox = useRef<HTMLDivElement | null>(null);
    const [size, setSize] = useState({ width: 1000, height: 560 });

    useEffect(() => {
        const element = bookBox.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            // Rounded so that small layout noise does not repaginate the book.
            const width = Math.round(entry.contentRect.width / 40) * 40;
            const height = Math.round(entry.contentRect.height / 20) * 20;

            setSize((previous) =>
                previous.width === width && previous.height === height
                    ? previous
                    : { width, height },
            );
        });
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    // Characters that fit a page: lines by height times characters per line by width, for the chosen font size.
    const fontPx = 16 * SCALES[scaleIndex];
    const pageWidth = size.width / (double ? 2 : 1) - 100;
    const charsPerLine = Math.max(20, pageWidth / (fontPx * 0.5));
    const lines = Math.max(6, (size.height - 150) / (fontPx * 1.75));
    const budget = Math.round((lines * charsPerLine * 0.86) / 10) * 10;
    const headCost = Math.round(charsPerLine * 3);
    const contCost = Math.round(charsPerLine);
    const pages = useMemo(
        () => paginate(sections, budget, headCost, contCost),
        [sections, budget, headCost, contCost],
    );
    const { root: tree, ancestors } = useMemo(
        () => buildTree(sections),
        [sections],
    );
    const haystack = useMemo(
        () =>
            sections.map((section) =>
                normalize(
                    `${section.label} ${section.heading ?? ''} ${section.content}`,
                ),
            ),
        [sections],
    );

    const [anchor, setAnchor] = useState(() => {
        const hash = window.location.hash.slice(1);
        const saved = readStorage<Saved>(storageKey(doc.code));
        const key = hash || start;

        return key ? `${key}:0` : (saved?.anchor ?? `${sections[0]?.key}:0`);
    });
    const [indexOpen, setIndexOpen] = useState(
        () => window.matchMedia(wideQuery).matches,
    );
    const [expanded, setExpanded] = useState<Set<string>>(() => {
        const key = anchor.split(':')[0];

        return new Set(ancestors.get(key) ?? []);
    });
    const [indexSearch, setIndexSearch] = useState('');
    const [term, setTerm] = useState('');
    const [activeTerm, setActiveTerm] = useState('');
    const [matchPosition, setMatchPosition] = useState(-1);
    const [searchNote, setSearchNote] = useState('');
    const [fullscreen, setFullscreen] = useState(false);
    const [infoOpen, setInfoOpen] = useState(false);
    const [bookmark, setBookmark] = useState<string | null>(() =>
        readStorage<string>(`${storageKey(doc.code)}.bookmark`),
    );
    const [saved, setSaved] = useState(false);

    const current = useMemo(() => {
        let index = pages.findIndex((page) =>
            page.entries.some((entry) => entry.id === anchor),
        );

        if (index < 0) {
            const key = anchor.split(':')[0];
            index = pages.findIndex((page) =>
                page.entries.some((entry) => entry.key === key),
            );
        }

        return Math.max(0, index);
    }, [pages, anchor]);
    const step = double ? 2 : 1;
    const left = current - (current % step);
    const visible = pages.slice(left, left + step);
    const firstEntry = pages[left]?.entries[0];
    const lastSpread = Math.floor((pages.length - 1) / step);

    const persist = (patch: Saved) => {
        writeStorage(storageKey(doc.code), {
            anchor,
            scale: SCALES[scaleIndex],
            mode,
            ...patch,
        });
        setSaved(true);
    };

    const go = (target: number) => {
        const index = Math.min(Math.max(0, target), pages.length - 1);
        const entry = pages[index]?.entries[0];

        if (!entry) {
            return;
        }

        setAnchor(entry.id);
        setExpanded((open) => {
            const next = new Set(open);
            (ancestors.get(entry.key) ?? []).forEach((id) => next.add(id));

            return next;
        });
        persist({ anchor: entry.id });
    };

    const goToKey = (key: string) => {
        const index = pages.findIndex((page) =>
            page.entries.some((entry) => entry.key === key),
        );

        if (index >= 0) {
            go(index);
        }
    };

    const changeScale = (delta: number) => {
        const next = Math.min(
            Math.max(0, scaleIndex + delta),
            SCALES.length - 1,
        );
        setScaleIndex(next);
        persist({ scale: SCALES[next] });
    };

    const changeMode = (next: 'double' | 'single') => {
        setMode(next);
        persist({ mode: next });
    };

    const submitSearch = () => {
        const value = term.trim();
        const normalized = normalize(value);

        if (normalized.length < 2) {
            setActiveTerm('');
            setSearchNote('');

            return;
        }

        const matches = haystack.reduce<number[]>(
            (list, text, index) =>
                text.includes(normalized) ? [...list, index] : list,
            [],
        );

        if (matches.length === 0) {
            setActiveTerm(value);
            setMatchPosition(-1);
            setSearchNote('Sin coincidencias');

            return;
        }

        const sameTerm = value === activeTerm && matchPosition >= 0;
        const currentSection = sections.findIndex(
            (section) => section.key === firstEntry?.key,
        );
        const next = sameTerm
            ? (matchPosition + 1) % matches.length
            : Math.max(
                  0,
                  matches.findIndex((index) => index >= currentSection),
              );

        setActiveTerm(value);
        setMatchPosition(next);
        setSearchNote(`${next + 1} de ${matches.length}`);
        const key = sections[matches[next]].key;
        const pageWithTerm = pages.findIndex((page) =>
            page.entries.some(
                (entry) =>
                    entry.key === key &&
                    normalize(
                        `${entry.first ? `${entry.label} ${entry.heading ?? ''} ` : ''}${entry.text}`,
                    ).includes(normalized),
            ),
        );

        if (pageWithTerm >= 0) {
            go(pageWithTerm);
        } else {
            goToKey(key);
        }
    };

    const toggleBookmark = () => {
        const entry = firstEntry?.id ?? null;
        const next = bookmark === entry ? null : entry;

        setBookmark(next);
        writeStorage(`${storageKey(doc.code)}.bookmark`, next);
    };

    const toggleFullscreen = () => {
        if (window.document.fullscreenElement) {
            void window.document.exitFullscreen();
            setFullscreen(false);
        } else {
            void root.current?.requestFullscreen().then(() => {
                setFullscreen(true);
            });
        }
    };

    const onKey = (event: KeyboardEvent) => {
        const target = event.target as HTMLElement;

        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) {
            return;
        }

        if (event.key === 'ArrowRight') {
            go(left + step);
        } else if (event.key === 'ArrowLeft') {
            go(left - step);
        }
    };

    const filteredArticles = useMemo(() => {
        const text = normalize(indexSearch.trim());

        if (text.length < 2) {
            return null;
        }

        return sections
            .filter((section) =>
                normalize(`${section.label} ${section.heading ?? ''}`).includes(
                    text,
                ),
            )
            .slice(0, 80);
    }, [sections, indexSearch]);

    const articleButton = (key: string, title: string, depth: number) => (
        <button
            key={key}
            type="button"
            onClick={() => goToKey(key)}
            style={{ paddingLeft: `${depth * 14 + 12}px` }}
            className={`block w-full border-l-4 py-1.5 pr-2 text-left font-serif text-sm transition hover:bg-amber-50 ${
                firstEntry?.key === key
                    ? 'border-[#640000] bg-[#f8e7e3] font-bold text-[#640000]'
                    : 'border-transparent text-[#3a2a22]'
            }`}
        >
            {title}
        </button>
    );

    const renderNode = (node: TreeNode, depth: number): ReactNode => {
        const open = expanded.has(node.id);

        return (
            <div key={node.id}>
                <button
                    type="button"
                    onClick={() => {
                        setExpanded((previous) => {
                            const next = new Set(previous);

                            if (next.has(node.id)) {
                                next.delete(node.id);
                            } else {
                                next.add(node.id);
                            }

                            return next;
                        });
                    }}
                    style={{ paddingLeft: `${depth * 14 + 8}px` }}
                    className="flex w-full items-center gap-1.5 py-1.5 pr-2 text-left text-sm font-bold text-[#3a2a22] hover:bg-amber-50"
                >
                    {open ? (
                        <ChevronDown className="size-4 shrink-0" />
                    ) : (
                        <ChevronRight className="size-4 shrink-0" />
                    )}
                    <span className="truncate" title={node.title}>
                        {node.title}
                    </span>
                </button>
                {open && (
                    <>
                        {node.children.map((child) =>
                            renderNode(child, depth + 1),
                        )}
                        {node.articles.map((article) =>
                            articleButton(
                                article.key,
                                article.title,
                                depth + 1,
                            ),
                        )}
                    </>
                )}
            </div>
        );
    };

    const renderPage = (
        page: Page | undefined,
        side: 'left' | 'right',
        index: number,
    ) => {
        const head = page?.entries[0]?.path?.split(' · ') ?? [];

        return (
            <section
                key={`${side}-${index}`}
                className={`relative flex min-h-0 min-w-0 flex-1 basis-0 flex-col px-8 py-6 break-words sm:px-12 ${
                    side === 'left'
                        ? 'bg-[linear-gradient(90deg,#efe3c8,#fbf6ea_10%,#fbf6ea_90%,#f3e9d2)]'
                        : 'bg-[linear-gradient(90deg,#f3e9d2,#fbf6ea_10%,#fbf6ea_90%,#efe3c8)]'
                }`}
                style={{ fontSize: `${SCALES[scaleIndex]}rem` }}
            >
                {page ? (
                    <>
                        <header className="text-center font-serif text-[0.7em] tracking-[0.18em] text-[#5a4a3e] uppercase">
                            <p className="truncate">
                                {head[0] ?? doc.short_title}
                            </p>
                            {head.length > 1 && (
                                <p className="mt-1 truncate border-t border-[#c9b896] pt-1">
                                    {head.slice(1).join(' · ')}
                                </p>
                            )}
                        </header>
                        <div className="mt-5 min-h-0 flex-1 space-y-4 overflow-y-auto pr-1 font-serif text-[#2a1f19]">
                            {page.entries.map((entry) => (
                                <article key={entry.id}>
                                    {entry.first ? (
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <h2 className="font-serif text-[1.45em] leading-tight font-bold text-[#6b1220]">
                                                {entry.label}
                                                {entry.heading &&
                                                    `. ${entry.heading}`}
                                            </h2>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    askLuzma(
                                                        `Explícame el ${entry.label}${entry.heading ? ` (${entry.heading})` : ''} de ${doc.short_title} y cómo se aplica`,
                                                    )
                                                }
                                                className="inline-flex items-center gap-1 rounded-lg border border-[#d9c7a3] bg-[#fffaf0] px-2 py-1 font-sans text-[11px] font-bold text-[#8a5b32] shadow-sm hover:bg-white"
                                            >
                                                <Sparkles className="size-3.5" />{' '}
                                                Preguntar a Luzma
                                            </button>
                                        </div>
                                    ) : (
                                        <p className="font-sans text-[0.7em] text-[#8a7a68] italic">
                                            {entry.label} (continuación)
                                        </p>
                                    )}
                                    {entry.reform && (
                                        <p className="mt-2 flex gap-1.5 rounded-lg bg-amber-100/70 p-2 font-sans text-[0.7em] font-semibold text-amber-900">
                                            <AlertTriangle className="size-3.5 shrink-0" />
                                            Este artículo fue reformado:{' '}
                                            {entry.reform}
                                        </p>
                                    )}
                                    {entry.text
                                        .split('\n')
                                        .map((paragraph, i) => (
                                            <p
                                                key={i}
                                                lang="es"
                                                className="mt-2 text-justify leading-[1.75] hyphens-auto"
                                            >
                                                {highlight(
                                                    paragraph,
                                                    activeTerm,
                                                )}
                                            </p>
                                        ))}
                                </article>
                            ))}
                        </div>
                        <footer className="mt-3 flex items-center gap-3 font-serif text-sm text-[#5a4a3e]">
                            <span className="h-px flex-1 bg-[#c9b896]" />
                            {left + index + 1}
                            <span className="h-px flex-1 bg-[#c9b896]" />
                        </footer>
                    </>
                ) : null}
            </section>
        );
    };

    const official = doc.official_url ?? doc.source_url;
    const hasNotice = doc.warning !== null || doc.reforms.length > 0;

    return (
        <div
            ref={root}
            tabIndex={-1}
            onKeyDown={onKey}
            className={`flex flex-col gap-3 outline-none ${fullscreen ? 'min-h-screen overflow-y-auto bg-[#f6f1e7] p-4' : ''}`}
        >
            <div className="flex items-center justify-between gap-3">
                <Link
                    href="/library"
                    className="inline-flex items-center gap-2 text-sm font-semibold text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Volver a la biblioteca
                </Link>
                <div className="min-w-0 text-center">
                    <h1 className="truncate font-serif text-xl font-bold text-[#2a1f19]">
                        {doc.title}
                    </h1>
                    <p className="truncate font-serif text-sm text-[#6a5a4e]">
                        {doc.reference ?? doc.issuer}
                    </p>
                </div>
                <div className="flex gap-1">
                    <button
                        type="button"
                        onClick={toggleBookmark}
                        title={
                            bookmark === firstEntry?.id
                                ? 'Quitar marcador'
                                : 'Marcar esta página'
                        }
                        aria-label="Marcador"
                        className="grid size-10 place-items-center rounded-lg text-[#3a2a22] hover:bg-amber-50"
                    >
                        <Bookmark
                            className={`size-5 ${bookmark === firstEntry?.id ? 'fill-[#640000] text-[#640000]' : ''}`}
                        />
                    </button>
                    {bookmark && bookmark !== firstEntry?.id && (
                        <button
                            type="button"
                            onClick={() => goToKey(bookmark.split(':')[0])}
                            className="rounded-lg px-2 text-xs font-bold text-[#640000] hover:bg-amber-50"
                        >
                            Ir al marcador
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={toggleFullscreen}
                        title="Pantalla completa"
                        aria-label="Pantalla completa"
                        className="grid size-10 place-items-center rounded-lg text-[#3a2a22] hover:bg-amber-50"
                    >
                        {fullscreen ? (
                            <Minimize2 className="size-5" />
                        ) : (
                            <Maximize2 className="size-5" />
                        )}
                    </button>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 rounded-xl border border-[#e4d9c4] bg-[#fbf7ee] p-2">
                <button
                    type="button"
                    onClick={() => setIndexOpen((open) => !open)}
                    className={`${toolButton} min-w-28 justify-start`}
                    aria-pressed={indexOpen}
                >
                    <List className="size-4" /> Índice
                </button>
                <form
                    className="relative min-w-52 flex-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submitSearch();
                    }}
                >
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                    <input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Buscar en el libro… (Enter para la siguiente)"
                        aria-label="Buscar en el libro"
                        className="h-10 w-full rounded-lg border border-slate-300 bg-white pr-24 pl-9 text-sm"
                    />
                    {searchNote && (
                        <span className="absolute top-1/2 right-3 -translate-y-1/2 text-xs font-semibold text-[#640000]">
                            {searchNote}
                        </span>
                    )}
                </form>
                <div className="flex overflow-hidden rounded-lg border border-slate-300 bg-white">
                    <button
                        type="button"
                        onClick={() => changeScale(-1)}
                        disabled={scaleIndex === 0}
                        aria-label="Reducir letra"
                        className="h-10 px-3 font-serif text-sm font-bold hover:bg-amber-50 disabled:opacity-40"
                    >
                        A−
                    </button>
                    <button
                        type="button"
                        onClick={() => changeScale(1)}
                        disabled={scaleIndex === SCALES.length - 1}
                        aria-label="Aumentar letra"
                        className="h-10 border-l border-slate-300 px-3 font-serif text-lg font-bold hover:bg-amber-50 disabled:opacity-40"
                    >
                        A+
                    </button>
                </div>
                <div className="hidden overflow-hidden rounded-lg border border-slate-300 bg-white lg:flex">
                    <button
                        type="button"
                        onClick={() => changeMode('single')}
                        aria-pressed={mode === 'single'}
                        className={`inline-flex h-10 items-center gap-2 px-3 text-sm font-semibold ${mode === 'single' ? 'bg-[#640000] text-white' : 'hover:bg-amber-50'}`}
                    >
                        <FileText className="size-4" /> Una página
                    </button>
                    <button
                        type="button"
                        onClick={() => changeMode('double')}
                        aria-pressed={mode === 'double'}
                        className={`inline-flex h-10 items-center gap-2 px-3 text-sm font-semibold ${mode === 'double' ? 'bg-[#640000] text-white' : 'hover:bg-amber-50'}`}
                    >
                        <BookOpen className="size-4" /> Doble página
                    </button>
                </div>
                <button
                    type="button"
                    onClick={() =>
                        askLuzma(
                            firstEntry
                                ? `Explícame el ${firstEntry.label}${firstEntry.heading ? ` (${firstEntry.heading})` : ''} de ${doc.short_title} y cómo se aplica`
                                : `Explícame ${doc.title}`,
                        )
                    }
                    className="inline-flex h-10 items-center gap-2 rounded-lg border border-[#d9c7a3] bg-[#fff6e2] px-4 text-sm font-bold text-[#8a5b32] hover:bg-white"
                >
                    <Sparkles className="size-4" /> Consultar a Luzma
                </button>
            </div>

            <div className="flex flex-col gap-3 lg:flex-row">
                {indexOpen && (
                    <aside className="flex max-h-72 w-full shrink-0 flex-col overflow-hidden rounded-xl border border-[#e4d9c4] bg-[#fbf7ee] lg:h-[calc(100vh-20rem)] lg:max-h-none lg:min-h-[460px] lg:w-72">
                        <div className="flex items-center justify-between px-4 pt-4">
                            <h2 className="font-serif text-lg font-bold text-[#2a1f19]">
                                Índice del libro
                            </h2>
                            <button
                                type="button"
                                onClick={() => setIndexOpen(false)}
                                aria-label="Cerrar índice"
                                className="text-slate-500"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                        <div className="relative m-3">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                value={indexSearch}
                                onChange={(event) =>
                                    setIndexSearch(event.target.value)
                                }
                                placeholder="Buscar artículo…"
                                aria-label="Buscar artículo"
                                className="h-9 w-full rounded-lg border border-slate-300 bg-white pl-9 text-sm"
                            />
                        </div>
                        <nav className="min-h-0 flex-1 overflow-y-auto pb-3">
                            {filteredArticles ? (
                                <>
                                    {filteredArticles.map((section) =>
                                        articleButton(
                                            section.key,
                                            `${section.label}${section.heading ? ` · ${section.heading}` : ''}`,
                                            0,
                                        ),
                                    )}
                                    {filteredArticles.length === 0 && (
                                        <p className="px-4 py-3 text-sm text-slate-500">
                                            Sin resultados.
                                        </p>
                                    )}
                                </>
                            ) : (
                                <>
                                    {tree.children.map((node) =>
                                        renderNode(node, 0),
                                    )}
                                    {tree.articles.map((article) =>
                                        articleButton(
                                            article.key,
                                            article.title,
                                            0,
                                        ),
                                    )}
                                </>
                            )}
                        </nav>
                    </aside>
                )}

                <div className="relative min-w-0 flex-1">
                    <button
                        type="button"
                        onClick={() => go(left - step)}
                        disabled={left === 0}
                        aria-label="Página anterior"
                        className="absolute top-1/2 -left-3 z-10 grid size-11 -translate-y-1/2 place-items-center rounded-full border border-slate-200 bg-white shadow-lg hover:bg-amber-50 disabled:pointer-events-none disabled:opacity-0"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => go(left + step)}
                        disabled={left + step >= pages.length}
                        aria-label="Página siguiente"
                        className="absolute top-1/2 -right-3 z-10 grid size-11 -translate-y-1/2 place-items-center rounded-full border border-slate-200 bg-white shadow-lg hover:bg-amber-50 disabled:pointer-events-none disabled:opacity-0"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                    <div className="rounded-2xl bg-gradient-to-b from-[#7a1523] to-[#4a0b15] p-3 shadow-[0_18px_40px_-14px_rgba(74,11,21,.7)]">
                        <div
                            ref={bookBox}
                            className="relative flex h-[calc(100vh-21rem)] min-h-[440px] overflow-hidden rounded-lg shadow-inner"
                        >
                            {renderPage(visible[0], 'left', 0)}
                            {double && (
                                <>
                                    <span
                                        aria-hidden
                                        className="pointer-events-none absolute inset-y-0 left-1/2 z-[1] w-10 -translate-x-1/2 bg-gradient-to-r from-transparent via-black/20 to-transparent"
                                    />
                                    {renderPage(visible[1], 'right', 1)}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-[#e4d9c4] bg-[#fbf7ee] px-3 py-2">
                {(hasNotice || official) && (
                    <button
                        type="button"
                        onClick={() => setInfoOpen(true)}
                        className="inline-flex items-center gap-2 rounded-lg border border-[#e8d49a] bg-[#fff6d8] px-3 py-2 text-sm text-[#7a5b1f] hover:bg-white"
                    >
                        <Info className="size-4" />
                        {hasNotice
                            ? 'Texto con reformas posteriores · Consultar reformas y fuente oficial'
                            : 'Consultar fuente oficial'}
                        <ExternalLink className="size-3.5" />
                    </button>
                )}
                <div className="flex min-w-60 flex-1 items-center gap-3">
                    <span className="font-serif text-sm whitespace-nowrap text-[#3a2a22]">
                        {pages.length === 0
                            ? 'Sin páginas'
                            : double && visible.length > 1
                              ? `Páginas ${left + 1} – ${left + 2}`
                              : `Página ${left + 1}`}{' '}
                        de {pages.length}
                    </span>
                    <input
                        type="range"
                        min={0}
                        max={Math.max(0, lastSpread)}
                        value={Math.floor(left / step)}
                        onChange={(event) =>
                            go(Number(event.target.value) * step)
                        }
                        aria-label="Posición en el libro"
                        className="h-2 flex-1 cursor-pointer accent-[#640000]"
                    />
                </div>
                <label className="flex items-center gap-2 font-serif text-sm text-[#3a2a22]">
                    Ir a página
                    <input
                        key={left}
                        type="number"
                        min={1}
                        max={pages.length}
                        defaultValue={left + 1}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                go(Number(event.currentTarget.value) - 1);
                            }
                        }}
                        onBlur={(event) =>
                            go(Number(event.currentTarget.value) - 1)
                        }
                        className="h-9 w-20 rounded-lg border border-slate-300 bg-white px-2 text-center text-sm"
                    />
                </label>
                {saved && (
                    <span className="inline-flex items-center gap-1.5 text-sm text-[#526b92]">
                        <Check className="size-4 text-emerald-600" /> Lectura
                        guardada
                    </span>
                )}
            </div>

            {infoOpen && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Reformas y fuente"
                    className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
                    onClick={() => setInfoOpen(false)}
                >
                    <div
                        className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <h2 className="text-lg font-black text-[#061633]">
                                {doc.title}
                            </h2>
                            <button
                                type="button"
                                onClick={() => setInfoOpen(false)}
                                aria-label="Cerrar"
                            >
                                <X className="size-5" />
                            </button>
                        </div>
                        <p className="mt-1 text-xs text-[#526b92]">
                            {doc.status_label} · {doc.version}
                        </p>
                        {doc.warning && (
                            <p className="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                                <AlertTriangle className="size-4 shrink-0" />
                                {doc.warning}
                            </p>
                        )}
                        {doc.reforms.length > 0 && (
                            <div className="mt-3 space-y-2 text-xs">
                                <p className="font-bold text-[#061633]">
                                    Reformas posteriores conocidas
                                </p>
                                {doc.reforms.map((reform) => (
                                    <p key={reform.decree}>
                                        <b>{reform.decree}</b>
                                        {reform.published_on &&
                                            ` (publicado ${reform.published_on})`}
                                        : {reform.note}
                                        {reform.effective &&
                                            ` Vigencia: ${reform.effective}`}
                                    </p>
                                ))}
                            </div>
                        )}
                        <p className="mt-3 text-[11px] text-slate-500">
                            {doc.source_name}
                            {doc.license_note && ` · ${doc.license_note}`}
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {doc.source_url && (
                                <a
                                    href={doc.source_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold"
                                >
                                    Fuente <ExternalLink className="size-3" />
                                </a>
                            )}
                            {doc.official_url && (
                                <a
                                    href={doc.official_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold"
                                >
                                    Sitio oficial{' '}
                                    <ExternalLink className="size-3" />
                                </a>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
