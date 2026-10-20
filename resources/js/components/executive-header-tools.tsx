import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Bell,
    Building2,
    CalendarDays,
    CheckCheck,
    ChevronDown,
    CircleHelp,
    Loader2,
    MessageSquare,
    Search,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EXECUTIVE_HOME } from '@/lib/executive-workspace';

export type Inbox = {
    unread: number;
    notifications: {
        id: number;
        type: string;
        title: string;
        body: string | null;
        href: string;
        read: boolean;
        archived: boolean;
        created_at: string;
    }[];
    comments: {
        id: number;
        task_id: number;
        task_title: string;
        company_name: string | null;
        author: string | null;
        message: string;
        type: string;
        href: string;
        created_at: string;
    }[];
};

type SearchResult = {
    type: string;
    title: string;
    subtitle: string;
    href: string;
};

type ExecutiveFilters = {
    filters?: { office_id: number | null; period: string | null };
    selectorOptions?: {
        offices: { id: number; name: string }[];
        periods: { key: string; name: string }[];
    };
};

const iconButton =
    'relative grid size-9 place-items-center rounded-lg text-[#426083] transition hover:bg-[#eef4fa] hover:text-[#640000]';
const pill =
    'flex h-10 items-center gap-2 rounded-xl border border-[#d8e1eb] bg-white px-3 text-sm font-bold text-[#172033] shadow-sm';

function relativeTime(value: string): string {
    const minutes = Math.round(
        (Date.now() - new Date(value).getTime()) / 60000,
    );

    if (minutes < 1) {
        return 'Ahora';
    }

    if (minutes < 60) {
        return `Hace ${minutes} min`;
    }

    if (minutes < 1440) {
        return `Hace ${Math.round(minutes / 60)} h`;
    }

    return new Intl.DateTimeFormat('es-GT', {
        day: '2-digit',
        month: 'short',
    }).format(new Date(value));
}

export function ExecutiveSearch() {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const focusSearch = (event: KeyboardEvent) => {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                inputRef.current?.focus();
            }
        };

        window.addEventListener('keydown', focusSearch);

        return () => window.removeEventListener('keydown', focusSearch);
    }, []);

    useEffect(() => {
        const query = term.trim();
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            if (query.length < 2) {
                setResults([]);
                setLoading(false);

                return;
            }

            try {
                const response = await fetch(
                    `/office/search?q=${encodeURIComponent(query)}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                const payload = (await response.json()) as {
                    results: SearchResult[];
                };
                setResults(payload.results ?? []);
                setActive(0);
            } catch {
                if (!controller.signal.aborted) {
                    setResults([]);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        }, 220);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [term]);

    const go = (result: SearchResult | undefined) => {
        if (!result) {
            return;
        }

        setOpen(false);
        setTerm('');
        router.visit(result.href);
    };

    return (
        <div className="relative hidden max-w-lg min-w-40 flex-1 lg:block">
            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#5f789c]" />
            <input
                ref={inputRef}
                aria-label="Buscar en el panel ejecutivo"
                value={term}
                onChange={(event) => {
                    setTerm(event.target.value);
                    setLoading(event.target.value.trim().length >= 2);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setActive((index) =>
                            Math.min(index + 1, results.length - 1),
                        );
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setActive((index) => Math.max(index - 1, 0));
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        go(results[active]);
                    } else if (event.key === 'Escape') {
                        setOpen(false);
                        inputRef.current?.blur();
                    }
                }}
                placeholder="Buscar empresas, oficinas, colaboradores..."
                className="h-10 w-full rounded-xl border border-[#d8e1eb] bg-white pr-14 pl-10 text-sm text-[#0b1733] shadow-sm transition outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15"
            />
            <kbd className="absolute top-1/2 right-3 -translate-y-1/2 rounded-md bg-[#f7f9fb] px-2 py-1 text-[10px] font-bold text-[#6f86a6] ring-1 ring-[#d8e1eb]">
                Ctrl K
            </kbd>
            {open && term.trim().length >= 2 && (
                <div className="absolute top-12 right-0 left-0 z-50 overflow-hidden rounded-xl border border-[#dfe6ee] bg-white shadow-xl">
                    {loading && (
                        <p className="flex items-center gap-2 p-3 text-sm text-[#526b92]">
                            <Loader2 className="size-4 animate-spin" />
                            Buscando...
                        </p>
                    )}
                    {!loading && results.length === 0 && (
                        <p className="p-3 text-sm text-[#526b92]">
                            Sin resultados para "{term.trim()}".
                        </p>
                    )}
                    {!loading &&
                        results.map((result, index) => (
                            <button
                                key={`${result.type}-${result.href}`}
                                type="button"
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => go(result)}
                                onMouseEnter={() => setActive(index)}
                                className={`flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left ${index === active ? 'bg-[#f4f8fb]' : ''}`}
                            >
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-bold text-[#061633]">
                                        {result.title}
                                    </span>
                                    <span className="block truncate text-xs text-[#526b92]">
                                        {result.subtitle}
                                    </span>
                                </span>
                                <span className="shrink-0 rounded-full bg-[#f3f6f9] px-2 py-0.5 text-[11px] font-bold text-[#395277]">
                                    {result.type}
                                </span>
                            </button>
                        ))}
                </div>
            )}
        </div>
    );
}

/** Office and period selectors of the executive dashboard, shown in the header on wide screens. */
export function ExecutiveFilters() {
    const page = usePage();
    const dashboard = (page.props as { dashboard?: ExecutiveFilters })
        .dashboard;
    const currentPath = page.url.split('?')[0].split('#')[0];

    if (currentPath !== EXECUTIVE_HOME || !dashboard?.selectorOptions) {
        return null;
    }

    const officeId = dashboard.filters?.office_id?.toString() ?? '';
    const period = dashboard.filters?.period ?? '';
    const offices = dashboard.selectorOptions.offices;
    const periods = dashboard.selectorOptions.periods;
    const officeLabel =
        offices.find((office) => office.id.toString() === officeId)?.name ??
        'Toda la organización';
    const periodLabel =
        periods.find((option) => option.key === period)?.name ?? 'Sin períodos';
    const apply = (next: { office_id?: string; period?: string }) => {
        const params: Record<string, string> = {};
        const office = next.office_id ?? officeId;
        const selectedPeriod = next.period ?? period;

        if (office) {
            params.office_id = office;
        }

        if (selectedPeriod) {
            params.period = selectedPeriod;
        }

        router.get(EXECUTIVE_HOME, params, {
            preserveScroll: true,
            preserveState: false,
        });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button type="button" className={`hidden xl:flex ${pill}`}>
                        <Building2 className="size-4 text-[#9b5b20]" />
                        <span className="max-w-44 truncate">{officeLabel}</span>
                        <ChevronDown className="size-4 text-[#7188a7]" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    className="w-64 rounded-xl p-2"
                >
                    <DropdownMenuLabel>Alcance</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={() => apply({ office_id: '' })}>
                        Toda la organización
                    </DropdownMenuItem>
                    {offices.map((office) => (
                        <DropdownMenuItem
                            key={office.id}
                            onSelect={() =>
                                apply({ office_id: office.id.toString() })
                            }
                        >
                            {office.name}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        disabled={periods.length === 0}
                        className={`hidden xl:flex ${pill}`}
                    >
                        <CalendarDays className="size-4 text-[#365173]" />
                        <span className="max-w-40 truncate">{periodLabel}</span>
                        <ChevronDown className="size-4 text-[#7188a7]" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    className="max-h-80 w-56 overflow-y-auto rounded-xl p-2"
                >
                    <DropdownMenuLabel>Período contable</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {periods.map((option) => (
                        <DropdownMenuItem
                            key={option.key}
                            onSelect={() => apply({ period: option.key })}
                        >
                            {option.name}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}

type OfficeScope = {
    global: boolean;
    offices: { id: number; name: string }[];
    selected: number | null;
};

/** Branch selector of the office administration screens; only global administrators get "Todas las oficinas". */
export function OfficeScopeSelector() {
    const page = usePage();
    const props = page.props as {
        panel?: { scope?: OfficeScope };
        officeScope?: OfficeScope;
        context?: { scope?: OfficeScope };
    };
    const scope =
        props.panel?.scope ?? props.officeScope ?? props.context?.scope;

    if (!scope || scope.offices.length === 0) {
        return null;
    }

    const path = page.url.split('?')[0].split('#')[0];
    const label =
        scope.offices.find((office) => office.id === scope.selected)?.name ??
        'Todas las oficinas';
    const period = new URL(page.url, 'https://atlantia.local').searchParams.get(
        'period',
    );
    const choose = (officeId: string) =>
        router.get(
            path,
            { office_id: officeId, ...(period ? { period } : {}) },
            { preserveState: false },
        );
    const canChoose = scope.global || scope.offices.length > 1;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    disabled={!canChoose}
                    className={`flex max-w-72 ${pill}`}
                >
                    <Building2 className="size-4 shrink-0 text-[#9b5b20]" />
                    <span className="truncate">{label}</span>
                    {canChoose && (
                        <ChevronDown className="size-4 shrink-0 text-[#7188a7]" />
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-64 rounded-xl p-2">
                <DropdownMenuLabel>Oficina que administras</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {scope.global && (
                    <DropdownMenuItem onSelect={() => choose('all')}>
                        Todas las oficinas
                    </DropdownMenuItem>
                )}
                {scope.offices.map((office) => (
                    <DropdownMenuItem
                        key={office.id}
                        onSelect={() => choose(office.id.toString())}
                    >
                        {office.name}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Accounting period selector of the operations screens; the options are the periods that exist for the office. */
export function OperationsPeriodSelector() {
    const page = usePage();
    const context = (
        page.props as {
            context?: {
                periods?: { key: string; name: string }[];
                period?: { key: string; name: string } | null;
            };
        }
    ).context;

    if (!context?.periods) {
        return null;
    }

    const path = page.url.split('?')[0].split('#')[0];
    const officeId = new URL(
        page.url,
        'https://atlantia.local',
    ).searchParams.get('office_id');
    const choose = (period: string) =>
        router.get(
            path,
            { period, ...(officeId ? { office_id: officeId } : {}) },
            { preserveState: false },
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    disabled={context.periods.length === 0}
                    className={`hidden md:flex ${pill}`}
                >
                    <CalendarDays className="size-4 text-[#365173]" />
                    <span className="max-w-40 truncate">
                        {context.period?.name ?? 'Sin períodos'}
                    </span>
                    <ChevronDown className="size-4 text-[#7188a7]" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-80 w-56 overflow-y-auto rounded-xl p-2"
            >
                <DropdownMenuLabel>Período contable</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {context.periods.map((option) => (
                    <DropdownMenuItem
                        key={option.key}
                        onSelect={() => choose(option.key)}
                    >
                        {option.name}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

type InboxNotification = Inbox['notifications'][number];

const notificationTabs = [
    { key: 'unread', label: 'No leídas' },
    { key: 'read', label: 'Leídas' },
    { key: 'archived', label: 'Archivadas' },
] as const;

type NotificationTab = (typeof notificationTabs)[number]['key'];

function notificationTab(notification: InboxNotification): NotificationTab {
    if (notification.archived) {
        return 'archived';
    }

    return notification.read ? 'read' : 'unread';
}

export function NotificationsMenu({ inbox }: { inbox: Inbox | undefined }) {
    const unread = inbox?.unread ?? 0;
    const notifications = inbox?.notifications ?? [];
    const [tab, setTab] = useState<NotificationTab>('unread');
    const counts = {
        unread,
        read: notifications.filter((row) => notificationTab(row) === 'read')
            .length,
        archived: notifications.filter(
            (row) => notificationTab(row) === 'archived',
        ).length,
    };
    const visible = notifications.filter((row) => notificationTab(row) === tab);
    const emptyText = {
        unread: 'Estás al día: no tienes notificaciones sin leer.',
        read: 'Aquí aparecen las notificaciones que ya abriste.',
        archived: 'No has archivado notificaciones.',
    }[tab];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Notificaciones${unread > 0 ? ` (${unread} sin leer)` : ''}`}
                    className={iconButton}
                >
                    <Bell className="size-[19px]" />
                    {unread > 0 && (
                        <span className="absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-red-600 px-1 text-[10px] font-black text-white">
                            {unread > 9 ? '9+' : unread}
                        </span>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-96 max-w-[calc(100vw-1rem)] rounded-xl border-[#e7e1dd] bg-white p-2 text-[#061633]"
            >
                <div className="flex items-center justify-between px-2 py-1.5">
                    <p className="text-sm font-black">Notificaciones</p>
                    {unread > 0 && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    '/work/notifications/read-all',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            className="flex items-center gap-1 text-xs font-bold text-[#640000]"
                        >
                            <CheckCheck className="size-3.5" />
                            Marcar todas como leídas
                        </button>
                    )}
                </div>
                <div className="mb-1 flex gap-1 border-b border-[#ebe5e1] px-1">
                    {notificationTabs.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`-mb-px flex items-center gap-1.5 border-b-2 px-2.5 py-2 text-xs font-bold ${tab === item.key ? 'border-[#640000] text-[#640000]' : 'border-transparent text-[#526b92] hover:text-[#061633]'}`}
                        >
                            {item.label}
                            {counts[item.key] > 0 && (
                                <span
                                    className={`rounded-full px-1.5 text-[10px] ${item.key === 'unread' ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-600'}`}
                                >
                                    {counts[item.key]}
                                </span>
                            )}
                        </button>
                    ))}
                </div>
                <div className="max-h-[22rem] overflow-y-auto">
                    {visible.length === 0 && (
                        <p className="p-4 text-center text-sm text-[#526b92]">
                            {emptyText}
                        </p>
                    )}
                    {visible.map((notification) => (
                        <div
                            key={notification.id}
                            className="group flex items-start gap-1 rounded-lg hover:bg-[#f6f1ee]"
                        >
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        `/work/notifications/${notification.id}/read`,
                                    )
                                }
                                className="flex min-w-0 flex-1 items-start gap-2 p-2 text-left"
                            >
                                <span
                                    className={`mt-1.5 size-2 shrink-0 rounded-full ${notification.read ? 'bg-transparent' : 'bg-red-600'}`}
                                />
                                <span className="min-w-0">
                                    <span
                                        className={`block text-sm leading-tight ${notification.read ? 'text-[#395277]' : 'font-bold text-[#061633]'}`}
                                    >
                                        {notification.title}
                                    </span>
                                    {notification.body && (
                                        <span className="mt-0.5 line-clamp-2 block text-xs text-[#526b92]">
                                            {notification.body}
                                        </span>
                                    )}
                                    <span className="mt-0.5 block text-[11px] text-[#7188a7]">
                                        {relativeTime(notification.created_at)}
                                    </span>
                                </span>
                            </button>
                            <button
                                type="button"
                                title={
                                    notification.archived
                                        ? 'Restaurar a la bandeja'
                                        : 'Archivar'
                                }
                                aria-label={
                                    notification.archived
                                        ? 'Restaurar notificación'
                                        : 'Archivar notificación'
                                }
                                onClick={() =>
                                    router.post(
                                        `/work/notifications/${notification.id}/${notification.archived ? 'restore' : 'archive'}`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="mt-1.5 mr-1 grid size-7 shrink-0 place-items-center rounded-md text-[#7188a7] hover:bg-white hover:text-[#640000]"
                            >
                                {notification.archived ? (
                                    <ArchiveRestore className="size-4" />
                                ) : (
                                    <Archive className="size-4" />
                                )}
                            </button>
                        </div>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function MessagesMenu({ inbox }: { inbox: Inbox | undefined }) {
    const comments = inbox?.comments ?? [];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Comentarios recientes"
                    className={iconButton}
                >
                    <MessageSquare className="size-[19px]" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-80 rounded-xl border-[#e7e1dd] bg-white p-2 text-[#061633]"
            >
                <DropdownMenuLabel>Comentarios en tus tareas</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {comments.length === 0 && (
                    <p className="p-3 text-sm text-[#526b92]">
                        No hay comentarios recientes.
                    </p>
                )}
                {comments.map((comment) => (
                    <DropdownMenuItem key={comment.id} asChild>
                        <Link
                            href={comment.href}
                            className="flex flex-col items-start gap-0.5 rounded-lg p-2"
                        >
                            <span className="text-xs font-bold text-[#640000]">
                                {comment.author ?? 'Colaborador'}
                                {comment.type === 'observation' &&
                                    ' · Observación'}
                            </span>
                            <span className="line-clamp-2 text-sm text-[#061633]">
                                {comment.message}
                            </span>
                            <span className="truncate text-[11px] text-[#7188a7]">
                                {comment.task_title}
                                {comment.company_name &&
                                    ` · ${comment.company_name}`}
                                {' · '}
                                {relativeTime(comment.created_at)}
                            </span>
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

const helpTopics = [
    {
        title: 'Indicadores',
        detail: 'Cifras del período y comparación con el anterior.',
        href: `${EXECUTIVE_HOME}#indicadores`,
    },
    {
        title: 'Cumplimiento',
        detail: 'Estado del cierre de cada empresa cliente.',
        href: `${EXECUTIVE_HOME}#cumplimiento`,
    },
    {
        title: 'Carga y productividad',
        detail: 'Trabajo abierto y terminado por colaborador.',
        href: `${EXECUTIVE_HOME}#equipo`,
    },
    {
        title: 'Reportes',
        detail: 'Descarga en Excel o PDF con los filtros activos.',
        href: `${EXECUTIVE_HOME}#reportes`,
    },
];

export function HelpMenu() {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" aria-label="Ayuda" className={iconButton}>
                    <CircleHelp className="size-[19px]" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72 rounded-xl p-2">
                <DropdownMenuLabel>Guía del panel ejecutivo</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {helpTopics.map((topic) => (
                    <DropdownMenuItem key={topic.title} asChild>
                        <Link
                            href={topic.href}
                            className="flex flex-col items-start gap-0.5 rounded-lg p-2"
                        >
                            <span className="text-sm font-bold text-[#061633]">
                                {topic.title}
                            </span>
                            <span className="text-xs text-[#526b92]">
                                {topic.detail}
                            </span>
                        </Link>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <p className="px-2 py-1.5 text-xs text-[#526b92]">
                    Atajo: <strong>Ctrl K</strong> para buscar empresas,
                    oficinas y colaboradores.
                </p>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
