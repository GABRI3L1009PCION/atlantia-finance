import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BadgeDollarSign,
    Bell,
    BookOpenCheck,
    Boxes,
    Bot,
    Building2,
    ChevronRight,
    CircleHelp,
    CreditCard,
    FileClock,
    Flag,
    Gauge,
    Globe2,
    Home,
    KeyRound,
    Layers,
    LifeBuoy,
    LogOut,
    Plug,
    ReceiptText,
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
import { ago, usePlatform } from '@/components/control-center/kit';
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
        ],
    },
];

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

    return (
        <div className="cc-root min-h-screen bg-[#07090d] text-slate-200">
            <SupportBanner />
            <div className="flex">
                <aside className="sticky top-0 hidden h-screen w-[240px] shrink-0 flex-col overflow-y-auto border-r border-white/[0.06] bg-[#0a0c11] lg:flex">
                    <Link
                        href="/control-center"
                        className="flex items-center gap-2.5 px-5 py-4"
                    >
                        <span className="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-[#c8102e] to-[#5c0612] font-serif text-lg font-black text-white shadow-[0_8px_24px_-8px_rgba(200,16,46,.9)]">
                            A
                        </span>
                        <span className="font-serif text-lg font-bold text-white">
                            Atlantia{' '}
                            <span className="text-[#e8b04b]">Finance</span>
                        </span>
                    </Link>
                    <nav className="flex-1 space-y-4 px-3 pb-6">
                        {visible.map((group) => (
                            <div key={group.label}>
                                <p className="mb-1 px-2 text-[10px] font-bold tracking-[0.14em] text-slate-500 uppercase">
                                    {group.label}
                                </p>
                                {group.items.map((item) => {
                                    const active =
                                        item.href === '/control-center'
                                            ? path === item.href
                                            : path === item.href ||
                                              path.startsWith(item.href + '/');
                                    const badge = item.badge
                                        ? (platform.badges[item.badge] ?? 0)
                                        : 0;

                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            className={`group flex items-center gap-2.5 rounded-lg px-2.5 py-[7px] text-[13px] transition ${active ? 'bg-gradient-to-r from-[#c8102e]/90 to-[#7a0a1b]/80 font-semibold text-white shadow-[0_8px_22px_-12px_rgba(200,16,46,.9)]' : 'text-slate-400 hover:bg-white/[0.04] hover:text-white'}`}
                                        >
                                            <item.icon className="size-4 shrink-0" />
                                            <span className="flex-1 truncate">
                                                {item.title}
                                            </span>
                                            {badge > 0 && (
                                                <span className="rounded-full bg-[#c8102e] px-1.5 text-[10px] font-bold text-white">
                                                    {badge}
                                                </span>
                                            )}
                                            {active && (
                                                <ChevronRight className="size-3.5" />
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>
                </aside>
                <div className="min-w-0 flex-1">
                    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-white/[0.06] bg-[#07090d]/85 px-4 py-3 backdrop-blur">
                        <form
                            className="relative max-w-md flex-1"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.get('/control-center/organizations', {
                                    search,
                                });
                            }}
                        >
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Buscar organización, código o NIT…"
                                className="h-10 w-full rounded-xl border border-white/[0.08] bg-white/[0.03] pr-3 pl-9 text-sm text-slate-200 outline-none placeholder:text-slate-500 focus:border-[#c8102e]/50"
                            />
                        </form>
                        <span className="hidden items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-xs text-slate-300 md:flex">
                            <Globe2 className="size-4" /> Toda la plataforma
                        </span>
                        <span className="hidden items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-xs text-slate-300 xl:flex">
                            <FileClock className="size-4" />{' '}
                            {new Intl.DateTimeFormat('es-GT', {
                                day: 'numeric',
                                month: 'short',
                                year: 'numeric',
                            }).format(new Date())}
                        </span>
                        <div className="ml-auto flex items-center gap-2">
                            <Link
                                href="/control-center"
                                className="relative grid size-9 place-items-center rounded-xl text-slate-300 hover:bg-white/[0.05]"
                                aria-label="Alertas"
                            >
                                <Bell className="size-5" />
                                {alerts > 0 && (
                                    <span className="absolute -top-0.5 -right-0.5 rounded-full bg-[#c8102e] px-1.5 text-[10px] font-bold text-white">
                                        {alerts}
                                    </span>
                                )}
                            </Link>
                            <a
                                href="mailto:soporte@atlantia.systems"
                                className="grid size-9 place-items-center rounded-xl text-slate-300 hover:bg-white/[0.05]"
                                aria-label="Ayuda"
                            >
                                <CircleHelp className="size-5" />
                            </a>
                            <div className="flex items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] py-1 pr-2 pl-1">
                                <span className="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-[#c8102e] to-[#5c0612] text-xs font-black text-white">
                                    {user.name.slice(0, 1).toUpperCase()}
                                </span>
                                <span className="hidden leading-tight md:block">
                                    <span className="block text-xs font-bold text-white">
                                        {platform.role_label ??
                                            'Superadministrador'}
                                    </span>
                                    <span className="block text-[10px] text-slate-400">
                                        {user.name}
                                    </span>
                                </span>
                                <Link
                                    href="/settings/security"
                                    className="ml-1 grid size-7 place-items-center rounded-lg text-slate-400 hover:text-white"
                                    aria-label="Seguridad de mi cuenta"
                                >
                                    <Users className="size-4" />
                                </Link>
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="grid size-7 place-items-center rounded-lg text-slate-400 hover:text-white"
                                    aria-label="Cerrar sesión"
                                >
                                    <LogOut className="size-4" />
                                </Link>
                            </div>
                        </div>
                    </header>
                    <main className="mx-auto max-w-[1700px] p-4 lg:p-6">
                        {children}
                    </main>
                    <FeedbackModals />
                    <footer className="px-6 pb-6 text-[11px] text-slate-600">
                        Atlantia Control Center · actualizado{' '}
                        {ago(new Date().toISOString())}
                    </footer>
                </div>
            </div>
        </div>
    );
}
