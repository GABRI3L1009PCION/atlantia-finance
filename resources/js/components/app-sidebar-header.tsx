import { Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    ChevronDown,
    Plus,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import {
    ExecutiveFilters,
    ExecutiveSearch,
    HelpMenu,
    MessagesMenu,
    NotificationsMenu,
    OfficeScopeSelector,
    OperationsPeriodSelector,
} from '@/components/executive-header-tools';
import type { Inbox } from '@/components/executive-header-tools';
import { LuzmaButton } from '@/components/luzma/luzma-button';
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
import { firmWorkspace } from '@/lib/executive-workspace';
import * as accounting from '@/routes/accounting';
import type { BreadcrumbItem as BreadcrumbItemType, User } from '@/types';

type WorkspaceNavigation = {
    organization: { name: string; role: string };
    homePath?: string;
    inbox?: Inbox;
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
    const currentPath = page.url.split('?')[0].split('#')[0];
    const workspace = firmWorkspace(
        currentPath,
        navigation?.homePath,
        pageCompany,
    );
    const isExecutiveWorkspace = workspace !== null;
    const company = isExecutiveWorkspace
        ? undefined
        : (pageCompany ?? navigation?.currentCompany ?? undefined);
    const user = page.props.auth.user as User;
    const companyId = company?.public_id;
    const isClientPortal = page.url.includes('module=portal-cliente');
    const isPurchaseOrders = page.url.includes('module=ordenes-compra');
    const isVatBooks = page.url.includes('module=libros-iva');
    const isPeriodClose = page.url.includes('module=cierre-periodo');
    const isAccountStatements = page.url.includes('module=estados-cuenta');
    const isReminders = page.url.includes('module=recordatorios');
    const isAudit = page.url.includes('module=auditoria');
    const isUsersRoles = page.url.includes('module=usuarios-roles');
    const currentUrl = useMemo(
        () => new URL(page.url, 'https://atlantia.local'),
        [page.url],
    );
    const currentSearch = currentUrl.searchParams.get('q') ?? '';
    const currentModule = currentUrl.searchParams.get('module');
    const [search, setSearch] = useState(currentSearch);
    const [syncedSearch, setSyncedSearch] = useState(currentSearch);
    // On the accounting screens the chip names the period being worked on, not the month of the calendar.
    const pagePeriods = page.props.periods as
        { id: number; starts_on: string }[] | undefined;
    const pageFilters = page.props.filters as
        { period?: string | number } | undefined;
    const workingPeriod = Array.isArray(pagePeriods)
        ? pagePeriods.find(
              (candidate) =>
                  String(candidate.id) === String(pageFilters?.period),
          )
        : undefined;
    const period = new Intl.DateTimeFormat('es-GT', {
        month: 'long',
        year: 'numeric',
    }).format(
        workingPeriod
            ? new Date(`${workingPeriod.starts_on.slice(0, 10)}T12:00:00`)
            : new Date(),
    );

    if (syncedSearch !== currentSearch) {
        setSyncedSearch(currentSearch);
        setSearch(currentSearch);
    }

    const submitSearch = () => {
        if (!companyId) {
            return;
        }

        const query: Record<string, string> = {};
        const term = search.trim();

        if (currentModule) {
            query.module = currentModule;
        }

        if (term) {
            query.q = term;
        }

        router.visit(accounting.companyDashboard.url(companyId, { query }), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    if (!companyId && isExecutiveWorkspace) {
        return (
            <header className="sticky top-0 z-30 flex min-h-14 shrink-0 items-center gap-2 border-b border-[#dfe6ee] bg-white/95 px-3 backdrop-blur md:px-4">
                <SidebarTrigger className="shrink-0 text-[#365173]" />
                <ExecutiveSearch />
                {workspace === 'office-admin' ? (
                    <OfficeScopeSelector />
                ) : workspace === 'supervisor' ||
                  workspace === 'accountant' ||
                  workspace === 'assistant' ||
                  workspace === 'tax' ||
                  workspace === 'treasury' ||
                  workspace === 'receivables' ||
                  workspace === 'payables' ||
                  workspace === 'payroll' ||
                  workspace === 'auditor' ? (
                    <OperationsPeriodSelector />
                ) : workspace === 'operations' ? (
                    <>
                        <OfficeScopeSelector />
                        <OperationsPeriodSelector />
                    </>
                ) : (
                    <ExecutiveFilters />
                )}

                <div className="ml-auto flex items-center gap-1">
                    <LuzmaButton />
                    <NotificationsMenu inbox={navigation?.inbox} />
                    <MessagesMenu inbox={navigation?.inbox} />
                    <HelpMenu />
                </div>

                <div className="hidden max-w-44 min-w-0 items-center border-l border-[#dfe6ee] pl-3 xl:flex">
                    <UserInfo user={user} />
                </div>
            </header>
        );
    }

    if (!companyId) {
        return (
            <header className="flex h-16 shrink-0 items-center gap-2 border-b border-[#e8e2df] bg-white px-4 md:px-6">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
                <div className="ml-auto">
                    <LuzmaButton />
                </div>
            </header>
        );
    }

    return (
        <header className="sticky top-0 z-30 flex min-h-16 shrink-0 items-center gap-3 border-b border-[#e8e2df] bg-white/95 px-3 backdrop-blur md:px-5">
            <SidebarTrigger className="shrink-0" />
            <form
                className="relative hidden max-w-sm min-w-40 flex-1 lg:block"
                onSubmit={(event) => {
                    event.preventDefault();
                    submitSearch();
                }}
            >
                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input
                    aria-label="Buscar en Atlantia Finance"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={
                        isClientPortal
                            ? 'Buscar documentos, clientes, reportes...'
                            : isPurchaseOrders
                              ? 'Buscar documentos, proveedores, órdenes...'
                              : isVatBooks
                                ? 'Buscar documentos, clientes, proveedores...'
                                : isPeriodClose
                                  ? 'Buscar en Atlantia Finance...'
                                  : isAccountStatements
                                    ? 'Buscar en Atlantia Finance...'
                                    : isReminders
                                      ? 'Buscar en Atlantia Finance...'
                                      : isAudit
                                        ? 'Buscar en Atlantia Finance...'
                                        : isUsersRoles
                                          ? 'Buscar en Atlantia Finance...'
                                          : 'Buscar en Atlantia Finance...'
                    }
                    className="h-10 w-full rounded-xl border border-[#ded8d4] bg-[#fbfaf9] pr-14 pl-10 text-sm transition outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15"
                />
                <kbd className="absolute top-1/2 right-3 -translate-y-1/2 rounded-md bg-white px-2 py-1 text-[10px] font-semibold text-slate-400 shadow-sm ring-1 ring-slate-200">
                    Ctrl K
                </kbd>
            </form>

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
                <span>
                    {isClientPortal
                        ? `Período: ${period}`
                        : period.charAt(0).toUpperCase() + period.slice(1)}
                </span>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        className={`ml-auto flex h-10 items-center gap-2 rounded-xl px-3 text-xs font-semibold text-white transition hover:brightness-105 sm:px-4 ${
                            isClientPortal
                                ? 'bg-[#730009] shadow-[0_8px_22px_-12px_#730009]'
                                : isPurchaseOrders
                                  ? 'bg-[#730009] shadow-[0_8px_22px_-12px_#730009]'
                                  : isVatBooks ||
                                      isPeriodClose ||
                                      isAccountStatements ||
                                      isReminders ||
                                      isAudit ||
                                      isUsersRoles
                                    ? 'bg-[#730009] shadow-[0_8px_22px_-12px_#730009]'
                                    : 'bg-gradient-to-r from-[#9a6a19] to-[#c7972d] shadow-[0_8px_22px_-12px_#8c5e00]'
                        }`}
                    >
                        <Plus className="size-4" />
                        <span className="hidden sm:inline">
                            {isClientPortal
                                ? 'Subir documentos'
                                : isPurchaseOrders
                                  ? 'Nueva Orden de Compra'
                                  : isVatBooks
                                    ? 'Registrar documento'
                                    : isPeriodClose
                                      ? 'Generar reporte'
                                      : isAccountStatements
                                        ? 'Generar reporte'
                                        : isReminders
                                          ? 'Generar reporte'
                                          : isAudit
                                            ? 'Generar reporte'
                                            : isUsersRoles
                                              ? 'Generar reporte'
                                              : 'Nueva transacción'}
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

            <LuzmaButton />

            <div className="hidden items-center gap-1 md:flex">
                <NotificationsMenu inbox={navigation?.inbox} />
                <MessagesMenu inbox={navigation?.inbox} />
            </div>

            <div className="hidden max-w-52 min-w-0 items-center border-l border-[#e6dfdc] pl-3 xl:flex">
                <UserInfo user={user} />
            </div>
        </header>
    );
}
