import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    CalendarDays,
    ChevronDown,
    CircleHelp,
    MessageSquare,
    Plus,
    Search,
} from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import * as accounting from '@/routes/accounting';
import type { BreadcrumbItem as BreadcrumbItemType, User } from '@/types';

type WorkspaceNavigation = {
    organization: { name: string; role: string };
    companies: { public_id: string; name: string; tax_id: string }[];
    currentCompany: {
        public_id: string;
        name: string;
        tax_id: string;
    } | null;
};

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const page = usePage();
    const navigation = page.props.workspaceNavigation as
        WorkspaceNavigation | null | undefined;
    const pageCompany = page.props.company as
        { public_id?: string; name?: string } | undefined;
    const company = pageCompany ?? navigation?.currentCompany ?? undefined;
    const user = page.props.auth.user as User;
    const companyId = company?.public_id;
    const period = new Intl.DateTimeFormat('es-GT', {
        month: 'long',
        year: 'numeric',
    }).format(new Date());

    if (!companyId) {
        return (
            <header className="flex h-16 shrink-0 items-center gap-2 border-b border-[#e8e2df] bg-white px-4 md:px-6">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </header>
        );
    }

    return (
        <header className="sticky top-0 z-30 flex min-h-16 shrink-0 items-center gap-3 border-b border-[#e8e2df] bg-white/95 px-3 backdrop-blur md:px-5">
            <SidebarTrigger className="shrink-0" />
            <div className="relative hidden max-w-sm min-w-40 flex-1 lg:block">
                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input
                    aria-label="Buscar en Atlantia Finance"
                    placeholder="Buscar en Atlantia Finance..."
                    className="h-10 w-full rounded-xl border border-[#ded8d4] bg-[#fbfaf9] pr-14 pl-10 text-sm transition outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15"
                />
                <kbd className="absolute top-1/2 right-3 -translate-y-1/2 rounded-md bg-white px-2 py-1 text-[10px] font-semibold text-slate-400 shadow-sm ring-1 ring-slate-200">
                    Ctrl K
                </kbd>
            </div>

            <label className="relative hidden min-w-44 xl:block">
                <Building2 className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#7a4f2c]" />
                <select
                    aria-label="Empresa actual"
                    value={companyId}
                    onChange={(event) =>
                        router.visit(
                            accounting.companyDashboard.url(event.target.value),
                        )
                    }
                    className="h-10 w-full appearance-none rounded-xl border border-[#ded8d4] bg-white pr-8 pl-9 text-xs font-semibold text-[#172033] outline-none"
                >
                    {(navigation?.companies ?? []).map((item) => (
                        <option key={item.public_id} value={item.public_id}>
                            {item.name}
                        </option>
                    ))}
                </select>
                <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-400" />
            </label>

            <div className="hidden h-10 items-center gap-2 rounded-xl border border-[#ded8d4] px-3 text-xs font-semibold text-[#172033] 2xl:flex">
                <CalendarDays className="size-4 text-[#7a4f2c]" />
                <span className="capitalize">{period}</span>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button className="ml-auto flex h-10 items-center gap-2 rounded-xl bg-gradient-to-r from-[#9a6a19] to-[#c7972d] px-3 text-xs font-semibold text-white shadow-[0_8px_22px_-12px_#8c5e00] transition hover:brightness-105 sm:px-4">
                        <Plus className="size-4" />
                        <span className="hidden sm:inline">
                            Nueva transacción
                        </span>
                        <ChevronDown className="hidden size-4 sm:block" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="w-56 rounded-xl p-2"
                >
                    <DropdownMenuLabel>Registrar movimiento</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild>
                        <Link href={accounting.sales(companyId)}>
                            Nueva venta
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={accounting.purchases(companyId)}>
                            Nueva compra
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={accounting.show(companyId)}>
                            Nuevo asiento
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <div className="hidden items-center gap-1 md:flex">
                {[Bell, MessageSquare, CircleHelp].map((Icon, index) => (
                    <button
                        key={index}
                        type="button"
                        aria-label={
                            ['Notificaciones', 'Mensajes', 'Ayuda'][index]
                        }
                        className="grid size-9 place-items-center rounded-lg text-slate-500 transition hover:bg-[#f4efec] hover:text-[#640000]"
                    >
                        <Icon className="size-[18px]" />
                    </button>
                ))}
            </div>

            <div className="hidden max-w-52 min-w-0 items-center border-l border-[#e6dfdc] pl-3 xl:flex">
                <UserInfo user={user} />
            </div>
        </header>
    );
}
