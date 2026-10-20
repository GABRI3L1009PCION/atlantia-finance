import { Link } from '@inertiajs/react';
import { Bell, Monitor, Settings, Shield, User } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const sidebarNavItems = [
    { title: 'Perfil', href: edit(), icon: User },
    { title: 'Seguridad', href: editSecurity(), icon: Shield },
    { title: 'Alertas', href: '/settings/notifications', icon: Bell },
    { title: 'Apariencia', href: editAppearance(), icon: Monitor },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6">
            <div className="mb-6 flex items-center gap-3">
                <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b] dark:bg-[#8a0b0b]/20 dark:text-red-300">
                    <Settings className="size-6" />
                </span>
                <div>
                    <h1 className="text-3xl font-black tracking-tight">
                        Configuración
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Administra tu perfil y los ajustes de tu cuenta.
                    </p>
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
                <aside>
                    <nav
                        className="flex gap-1 rounded-2xl border border-border bg-card p-2 shadow-sm lg:flex-col"
                        aria-label="Configuración"
                    >
                        {sidebarNavItems.map((item) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={toUrl(item.href)}
                                    href={item.href}
                                    className={cn(
                                        'flex flex-1 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors lg:flex-none',
                                        active
                                            ? 'bg-[#f6e6e4] text-[#8a0b0b] dark:bg-[#8a0b0b]/25 dark:text-red-200'
                                            : 'text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    <item.icon className="size-4" />
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <div className="min-w-0 space-y-6">{children}</div>
            </div>
        </div>
    );
}
