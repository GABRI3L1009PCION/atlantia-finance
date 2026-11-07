import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BadgeDollarSign,
    Bell,
    BookOpenCheck,
    Boxes,
    BrainCircuit,
    Bot,
    Building2,
    ChevronDown,
    CircleHelp,
    CreditCard,
    FileText,
    Flag,
    Gauge,
    Home,
    KeyRound,
    Layers,
    LockKeyhole,
    LifeBuoy,
    LogOut,
    Menu,
    Plug,
    ReceiptText,
    Scale,
    Search,
    ServerCog,
    Settings,
    ShieldCheck,
    Siren,
    Sparkles,
    TerminalSquare,
    UserCog,
    Users,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { usePlatform } from '@/components/control-center/kit';
import { FeedbackModals } from '@/components/feedback-modals';

type Item = {
    title: string;
    href: string;
    icon: LucideIcon;
    permission: string;
    badge?: string;
};
type Group = { label: string; items: Item[] };

const groups: Group[] = [
    {
        label: 'Visión general',
        items: [
            {
                title: 'Inicio',
                href: '/control-center',
                icon: Home,
                permission: 'saas.dashboard.view',
            },
            {
                title: 'Organizaciones',
                href: '/control-center/organizations',
                icon: Building2,
                permission: 'saas.organizations.view',
            },
            {
                title: 'Oficinas',
                href: '/control-center/offices',
                icon: Layers,
                permission: 'saas.offices.view',
            },
            {
                title: 'Usuarios SaaS',
                href: '/control-center/saas-users',
                icon: UserCog,
                permission: 'saas.users.view',
            },
            {
                title: 'Roles y permisos',
                href: '/control-center/roles',
                icon: LockKeyhole,
                permission: 'saas.users.view',
            },
        ],
    },
    {
        label: 'Comercial',
        items: [
            {
                title: 'Planes',
                href: '/control-center/plans',
                icon: Boxes,
                permission: 'saas.plans.view',
            },
            {
                title: 'Suscripciones',
                href: '/control-center/subscriptions',
                icon: BadgeDollarSign,
                permission: 'saas.subscriptions.view',
                badge: 'past_due',
            },
            {
                title: 'Facturación',
                href: '/control-center/billing',
                icon: ReceiptText,
                permission: 'saas.billing.view',
            },
            {
                title: 'Pagos',
                href: '/control-center/payments',
                icon: CreditCard,
                permission: 'saas.payments.view',
            },
        ],
    },
    {
        label: 'Producto',
        items: [
            {
                title: 'Módulos y funciones',
                href: '/control-center/features',
                icon: Layers,
                permission: 'saas.features.view',
            },
            {
                title: 'Límites y consumo',
                href: '/control-center/usage',
                icon: Gauge,
                permission: 'saas.usage.view',
            },
            {
                title: 'Feature Flags',
                href: '/control-center/feature-flags',
                icon: Flag,
                permission: 'saas.flags.view',
            },
        ],
    },
    {
        label: 'Operación',
        items: [
            {
                title: 'Servicios',
                href: '/control-center/services',
                icon: ServerCog,
                permission: 'saas.services.view',
            },
            {
                title: 'Jobs y colas',
                href: '/control-center/jobs',
                icon: Activity,
                permission: 'saas.jobs.view',
                badge: 'failed_jobs',
            },
            {
                title: 'Integraciones',
                href: '/control-center/integrations',
                icon: Plug,
                permission: 'saas.integrations.view',
            },
        ],
    },
    {
        label: 'Luzma',
        items: [
            {
                title: 'Estado de Luzma',
                href: '/control-center/luzma',
                icon: Bot,
                permission: 'saas.luzma.view',
            },
            {
                title: 'Consumo IA',
                href: '/control-center/luzma/usage',
                icon: Sparkles,
                permission: 'saas.luzma.view',
            },
            {
                title: 'Red neuronal',
                href: '/control-center/luzma/neural',
                icon: BrainCircuit,
                permission: 'saas.luzma.view',
            },
            {
                title: 'Herramientas',
                href: '/control-center/luzma/tools',
                icon: Wrench,
                permission: 'saas.luzma.view',
            },
        ],
    },
    {
        label: 'Seguridad',
        items: [
            {
                title: 'Centro de seguridad',
                href: '/control-center/security',
                icon: ShieldCheck,
                permission: 'saas.security.view',
            },
            {
                title: 'Accesos de soporte',
                href: '/control-center/support-access',
                icon: KeyRound,
                permission: 'saas.support_access.request',
                badge: 'support_access',
            },
        ],
    },
    {
        label: 'Soporte',
        items: [
            {
                title: 'Tickets',
                href: '/control-center/support/tickets',
                icon: LifeBuoy,
                permission: 'saas.tickets.view',
                badge: 'tickets',
            },
            {
                title: 'Incidentes',
                href: '/control-center/incidents',
                icon: Siren,
                permission: 'saas.incidents.view',
                badge: 'incidents',
            },
        ],
    },
    {
        label: 'Control',
        items: [
            {
                title: 'Auditoría SaaS',
                href: '/control-center/audit',
                icon: BookOpenCheck,
                permission: 'saas.audit.view',
            },
            {
                title: 'Logs del sistema',
                href: '/control-center/logs',
                icon: TerminalSquare,
                permission: 'saas.logs.view',
            },
        ],
    },
    {
        label: 'Configuración',
        items: [
            {
                title: 'Configuración global',
                href: '/control-center/settings',
                icon: Settings,
                permission: 'saas.settings.view',
            },
            {
                title: 'Documentos legales',
                href: '/control-center/legal',
                icon: Scale,
                permission: 'saas.settings.view',
            },
            {
                title: 'Documentación del proyecto',
                href: '/control-center/documentation',
                icon: FileText,
                permission: 'saas.settings.view',
            },
        ],
    },
];

function matches(item: Item, path: string): boolean {
    return item.href === '/control-center'
        ? path === item.href
        : path === item.href || path.startsWith(item.href + '/');
}

/**
 * Only the most specific entry is highlighted: on /control-center/luzma/usage "Consumo IA" lights up, not also
 * "Estado de Luzma", whose address is a prefix of it.
 */
function isActive(item: Item, path: string): boolean {
    return (
        matches(item, path) &&
        !groups.some((group) =>
            group.items.some(
                (other) =>
                    other.href.length > item.href.length &&
                    matches(other, path),
            ),
        )
    );
}

/**
 * One section of the menu, folded like the firms' sidebar (NavMain): the section holding the current page opens by
 * itself, a click on the heading opens or closes it, and moving to another section opens that one and closes the
 * one left behind. A closed section still shows the sum of its badges so pending work is not hidden.
 */
function NavGroup({
    group,
    path,
    badges,
}: {
    group: Group;
    path: string;
    badges: Record<string, number>;
}) {
    const hasActive = group.items.some((item) => isActive(item, path));
    const [choice, setChoice] = useState<{
        forActive: boolean;
        open: boolean;
    } | null>(null);
    const open =
        choice !== null && choice.forActive === hasActive
            ? choice.open
            : hasActive;
    const pending = group.items.reduce(
        (sum, item) => sum + (item.badge ? (badges[item.badge] ?? 0) : 0),
        0,
    );

    return (
        <div className="border-b border-white/10 pb-1.5 last:border-b-0">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setChoice({ forActive: hasActive, open: !open })}
                className="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 pt-2.5 pb-1 text-[12px] font-bold tracking-[0.06em] text-[#e8b04b] uppercase transition hover:text-[#f5cf7a]"
            >
                <span className="flex-1 truncate text-left">{group.label}</span>
                {!open && pending > 0 && (
                    <span className="rounded-full bg-[#c8102e] px-1.5 text-[10px] font-bold tracking-normal text-white">
                        {pending}
                    </span>
                )}
                <ChevronDown
                    className={`size-3.5 shrink-0 transition-transform ${open ? '' : '-rotate-90'}`}
                />
            </button>
            {open && (
                <div className="mt-0.5 space-y-0.5">
                    {group.items.map((item) => {
                        const active = isActive(item, path);
                        const badge = item.badge
                            ? (badges[item.badge] ?? 0)
                            : 0;

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-2.5 rounded-lg px-2.5 py-[7px] text-[14px] transition ${active ? 'bg-[#8b0f1a] font-semibold text-white shadow-[0_8px_20px_-12px_rgba(139,15,26,.9)]' : 'text-white/90 hover:bg-white/[0.07] hover:text-white'}`}
                            >
                                <item.icon className="size-[18px] shrink-0" />
                                <span className="flex-1 truncate">
                                    {item.title}
                                </span>
                                {badge > 0 && (
                                    <span className="rounded-full bg-[#c8102e] px-1.5 text-[10px] font-bold text-white">
                                        {badge}
                                    </span>
                                )}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function SupportBanner() {
    const platform = usePlatform();
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30000);

        return () => window.clearInterval(timer);
    }, []);

    if (platform.active_support_access.length === 0) {
        return null;
    }

    return (
        <div className="sticky top-0 z-30 flex flex-wrap items-center gap-3 border-b border-[#f59e0b]/40 bg-[#f59e0b] px-4 py-2 text-xs font-bold text-black">
            <KeyRound className="size-4" />
            MODO SOPORTE ACTIVO
            {platform.active_support_access.map((access) => {
                const minutes = Math.max(
                    0,
                    Math.round(
                        (new Date(access.expires_at).getTime() - now) / 60000,
                    ),
                );

                return (
                    <Link
                        key={access.id}
                        href={`/control-center/support-access`}
                        className="rounded-full bg-black/15 px-2 py-0.5"
                    >
                        {access.organization} · expira en {minutes} min
                    </Link>
                );
            })}
        </div>
    );
}

export default function ControlCenterLayout({
    children,
}: {
    children: ReactNode;
}) {
    const page = usePage();
    const platform = usePlatform();
    const user = (
        page.props as { auth: { user: { name: string; email: string } } }
    ).auth.user;
    const path = page.url.split('?')[0];
    const [search, setSearch] = useState('');
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [mobileOpen, setMobileOpen] = useState(false);
    const visible = groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    platform.permissions[item.permission] ||
                    (item.permission === 'saas.support_access.request' &&
                        platform.permissions['saas.support_access.approve']),
            ),
        }))
        .filter((group) => group.items.length > 0);
    const alerts =
        (platform.badges.incidents ?? 0) +
        (platform.badges.support_access ?? 0) +
        (platform.badges.failed_jobs > 0 ? 1 : 0);

    const initials =
        user.name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('') || 'A';
    const sidebar = (
        <>
            <Link
                href="/control-center"
                className="flex flex-col items-center gap-2 px-5 pt-5"
            >
                <img
                    src="/images/atlantia-finance-logo.png"
                    alt="Atlantia Finance"
                    className="mx-auto h-14 w-auto max-w-full object-contain"
                />
            </Link>
            <div className="mx-4 mt-3 border-t border-[#e8b04b]/70" />
            <p className="px-5 pt-3 pb-2 text-center text-[13px] leading-tight font-bold tracking-[0.04em] text-[#e8b04b] uppercase">
                Panel del Superadministrador
            </p>
            <nav className="flex-1 px-3 pb-6">
                {visible.map((group) => (
                    <NavGroup
                        key={group.label}
                        group={group}
                        path={path}
                        badges={platform.badges}
                    />
                ))}
            </nav>
        </>
    );

    return (
        <div className="cc-root min-h-screen bg-[#f6f3f1] text-slate-800">
            <SupportBanner />
            <div className="flex">
                <aside
                    className={`sticky top-0 hidden h-screen w-[250px] shrink-0 [scrollbar-width:thin] flex-col overflow-y-auto bg-[#0a0a0b] ${sidebarOpen ? 'lg:flex' : ''}`}
                >
                    {sidebar}
                </aside>
                {mobileOpen && (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <button
                            type="button"
                            aria-label="Cerrar menú"
                            className="absolute inset-0 bg-black/50"
                            onClick={() => setMobileOpen(false)}
                        />
                        <aside className="relative flex h-full w-[260px] flex-col overflow-y-auto bg-[#0a0a0b]">
                            {sidebar}
                        </aside>
                    </div>
                )}
                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-20 flex h-[60px] items-center gap-3 bg-[#6b0000] px-4 text-white shadow-[0_6px_18px_-12px_rgba(60,0,0,.8)]">
                        <button
                            type="button"
                            aria-label="Mostrar u ocultar el menú"
                            onClick={() => {
                                if (
                                    window.matchMedia('(min-width: 1024px)')
                                        .matches
                                ) {
                                    setSidebarOpen((open) => !open);
                                } else {
                                    setMobileOpen(true);
                                }
                            }}
                            className="grid size-9 shrink-0 place-items-center rounded-lg hover:bg-white/10"
                        >
                            <Menu className="size-6" />
                        </button>
                        <form
                            className="relative max-w-md flex-1"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.get('/control-center/organizations', {
                                    search,
                                });
                            }}
                        >
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-white/70" />
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Buscar organización, código o NIT…"
                                className="h-9 w-full rounded-lg border border-white/30 bg-white/10 pr-3 pl-9 text-sm text-white outline-none placeholder:text-white/70 focus:border-white/60 focus:bg-white/15"
                            />
                        </form>
                        <div className="ml-auto flex items-center gap-1.5">
                            <Link
                                href="/control-center"
                                className="relative grid size-9 place-items-center rounded-lg hover:bg-white/10"
                                aria-label="Alertas"
                            >
                                <Bell className="size-5" />
                                {alerts > 0 ? (
                                    <span className="absolute -top-0.5 -right-0.5 rounded-full bg-[#e8b04b] px-1.5 text-[10px] font-bold text-[#3b0000]">
                                        {alerts}
                                    </span>
                                ) : null}
                            </Link>
                            <a
                                href="mailto:soporte@atlantia.systems"
                                className="grid size-9 place-items-center rounded-lg hover:bg-white/10"
                                aria-label="Ayuda"
                            >
                                <CircleHelp className="size-5" />
                            </a>
                            <span className="mx-2 hidden h-8 w-px bg-white/30 sm:block" />
                            <div className="flex items-center gap-2.5">
                                <span className="grid size-9 place-items-center rounded-full bg-[#e8b04b] text-xs font-black text-[#3b0000]">
                                    {initials}
                                </span>
                                <span className="hidden leading-tight md:block">
                                    <span className="block text-sm font-bold">
                                        {user.name}
                                    </span>
                                    <span className="block text-[11px] text-white/75">
                                        {platform.role_label ??
                                            'Superadministrador'}
                                    </span>
                                </span>
                                <Link
                                    href="/settings/security"
                                    className="ml-1 grid size-8 place-items-center rounded-lg text-white/80 hover:bg-white/10 hover:text-white"
                                    aria-label="Seguridad de mi cuenta"
                                >
                                    <Users className="size-4" />
                                </Link>
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="grid size-8 place-items-center rounded-lg text-white/80 hover:bg-white/10 hover:text-white"
                                    aria-label="Cerrar sesión"
                                >
                                    <LogOut className="size-4" />
                                </Link>
                            </div>
                        </div>
                    </header>
                    <main className="mx-auto w-full max-w-[1700px] flex-1 p-4 lg:p-5">
                        {children}
                    </main>
                    <FeedbackModals />
                </div>
            </div>
        </div>
    );
}
