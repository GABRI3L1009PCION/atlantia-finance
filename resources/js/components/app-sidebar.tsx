import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BadgeCheck,
    BadgeDollarSign,
    Banknote,
    Bell,
    BookMarked,
    BookOpen,
    Boxes,
    Building2,
    Calculator,
    CalendarDays,
    ChartNoAxesCombined,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardList,
    Contact,
    FileChartColumn,
    FileMinus2,
    FileSignature,
    FileText,
    FolderKanban,
    Gauge,
    Globe2,
    History,
    Landmark,
    LibraryBig,
    ListTree,
    LockKeyhole,
    PackageSearch,
    ReceiptText,
    ScrollText,
    Scale,
    Settings2,
    ShoppingCart,
    SlidersHorizontal,
    Users,
    UsersRound,
    WalletCards,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { hasPlanFeature, hasPlanModule } from '@/lib/plan-capabilities';
import type { PlanModule } from '@/lib/plan-capabilities';
import { dashboard } from '@/routes';
import * as accounting from '@/routes/accounting';
import * as fel from '@/routes/accounting/fel';
import type { NavItem } from '@/types';

const organizationNavItems: NavItem[] = [
    { title: 'Panel ejecutivo', href: dashboard(), icon: Gauge },
    { title: 'Empresas clientes', href: accounting.index(), icon: Building2 },
];

type WorkspaceNavigation = {
    organization: { name: string; role: string };
    plan: { code: string; name: string; features: string[] } | null;
    currentCompany: {
        public_id: string;
        name: string;
        tax_id: string;
    } | null;
};

function anchored(path: string, section: string): string {
    return `${path}#${section}`;
}

function moduleLink(path: string, module: string): string {
    return `${path}?module=${module}`;
}

function future(
    path: string,
    title: string,
    module: string,
    icon: LucideIcon,
): NavItem {
    return { title, href: moduleLink(path, module), icon, isActive: false };
}

function availableFutureModules(
    features: readonly string[],
    path: string,
    modules: {
        title: string;
        module: PlanModule;
        icon: LucideIcon;
    }[],
): NavItem[] {
    return modules
        .filter((item) => hasPlanModule(features, item.module))
        .map((item) => future(path, item.title, item.module, item.icon));
}

export function AppSidebar() {
    const page = usePage();
    const [currentHash, setCurrentHash] = useState(() =>
        typeof window === 'undefined' ? '' : window.location.hash,
    );

    useEffect(() => {
        const updateHash = () => setCurrentHash(window.location.hash);

        updateHash();
        window.addEventListener('hashchange', updateHash);

        return () => window.removeEventListener('hashchange', updateHash);
    }, [page.url]);

    const navigation = page.props.workspaceNavigation as
        WorkspaceNavigation | null | undefined;
    const pageCompany = page.props.company as
        { public_id?: string; name?: string } | undefined;
    const company = pageCompany ?? navigation?.currentCompany ?? undefined;
    const companyId = company?.public_id;
    const planFeatures = navigation?.plan?.features ?? [];
    const hasAccounting = hasPlanFeature(planFeatures, 'basic_accounting');
    const hasInvoicing = hasPlanFeature(planFeatures, 'basic_invoicing');
    const hasObligations = hasPlanFeature(
        planFeatures,
        'obligation_management',
    );
    const currentPath = page.url.split('?')[0].split('#')[0];
    const dashboardPath = companyId
        ? accounting.companyDashboard.url(companyId)
        : '';
    const booksPath = companyId ? accounting.show.url(companyId) : '';
    const salesPath = companyId ? accounting.sales.url(companyId) : '';
    const customersPath = companyId
        ? accounting.salesCustomers.url(companyId)
        : '';
    const receivablesPath = companyId
        ? accounting.receivables.url(companyId)
        : '';
    const purchasesPath = companyId ? accounting.purchases.url(companyId) : '';
    const suppliersPath = companyId ? accounting.suppliers.url(companyId) : '';
    const payablesPath = companyId ? accounting.payables.url(companyId) : '';
    const banksPath = companyId ? accounting.banks.url(companyId) : '';
    const fiscalPath = companyId
        ? `/accounting/companies/${companyId}/fiscal-settings`
        : '';

    const home: NavItem[] = companyId
        ? [
              {
                  title: 'Inicio',
                  href: accounting.companyDashboard(companyId),
                  icon: Gauge,
                  isActive:
                      currentPath === dashboardPath &&
                      !page.url.includes('?module='),
              },
          ]
        : [];
    const accountingItems: NavItem[] =
        companyId && hasAccounting
            ? [
                  {
                      title: 'Contabilidad General',
                      href: accounting.show(companyId),
                      icon: Calculator,
                      isActive: currentPath === booksPath && currentHash === '',
                  },
                  {
                      title: 'Libro Diario',
                      href: anchored(booksPath, 'libro-diario'),
                      icon: ScrollText,
                      isActive:
                          currentPath === booksPath &&
                          currentHash === '#libro-diario',
                  },
                  {
                      title: 'Libro Mayor',
                      href: anchored(booksPath, 'libro-mayor'),
                      icon: BookOpen,
                      isActive:
                          currentPath === booksPath &&
                          currentHash === '#libro-mayor',
                  },
                  {
                      title: 'Balance de Comprobación',
                      href: anchored(booksPath, 'balance-comprobacion'),
                      icon: Scale,
                      isActive:
                          currentPath === booksPath &&
                          currentHash === '#balance-comprobacion',
                  },
                  {
                      title: 'Plan de Cuentas',
                      href: anchored(booksPath, 'plan-cuentas'),
                      icon: ListTree,
                      isActive:
                          currentPath === booksPath &&
                          currentHash === '#plan-cuentas',
                  },
                  {
                      title: 'Períodos Contables',
                      href: anchored(booksPath, 'periodos-contables'),
                      icon: CalendarDays,
                      isActive:
                          currentPath === booksPath &&
                          currentHash === '#periodos-contables',
                  },
              ]
            : [];
    const operationItems: NavItem[] = companyId
        ? [
              ...(hasInvoicing
                  ? [
                        {
                            title: 'Facturación electrónica',
                            href: fel.index(companyId),
                            icon: ShoppingCart,
                            isActive: currentPath.includes('/fel'),
                        },
                        {
                            title: 'Ventas',
                            href: accounting.sales(companyId),
                            icon: ShoppingCart,
                            isActive: currentPath === salesPath,
                        },
                        {
                            title: 'Clientes',
                            href: accounting.salesCustomers(companyId),
                            icon: UsersRound,
                            isActive: currentPath === customersPath,
                        },
                    ]
                  : []),
              ...(hasObligations
                  ? [
                        {
                            title: 'Cuentas por Cobrar',
                            href: accounting.receivables(companyId),
                            icon: CircleDollarSign,
                            isActive: currentPath === receivablesPath,
                        },
                    ]
                  : []),
              ...(hasAccounting
                  ? [
                        {
                            title: 'Compras',
                            href: accounting.purchases(companyId),
                            icon: BadgeDollarSign,
                            isActive: currentPath === purchasesPath,
                        },
                        {
                            title: 'Proveedores',
                            href: accounting.suppliers(companyId),
                            icon: PackageSearch,
                            isActive: currentPath === suppliersPath,
                        },
                    ]
                  : []),
              ...(hasObligations
                  ? [
                        {
                            title: 'Cuentas por Pagar',
                            href: accounting.payables(companyId),
                            icon: WalletCards,
                            isActive: currentPath === payablesPath,
                        },
                    ]
                  : []),
          ]
        : [];
    const financeItems: NavItem[] = companyId
        ? [
              ...(hasAccounting
                  ? [
                        {
                            title: 'Bancos',
                            href: accounting.banks(companyId),
                            icon: Landmark,
                            isActive: currentPath === banksPath,
                        },
                    ]
                  : []),
              ...availableFutureModules(planFeatures, dashboardPath, [
                  {
                      title: 'Conciliación Bancaria',
                      module: 'conciliacion',
                      icon: SlidersHorizontal,
                  },
                  {
                      title: 'Reglas Bancarias',
                      module: 'reglas',
                      icon: WandSparkles,
                  },
                  { title: 'Caja', module: 'caja', icon: Banknote },
                  { title: 'Inventario', module: 'inventario', icon: Boxes },
                  { title: 'Kardex', module: 'kardex', icon: ArrowLeftRight },
                  {
                      title: 'Inventario Físico',
                      module: 'inventario-fisico',
                      icon: ClipboardCheck,
                  },
                  {
                      title: 'Activos Fijos',
                      module: 'activos-fijos',
                      icon: Building2,
                  },
                  { title: 'Nómina', module: 'nomina', icon: Users },
                  {
                      title: 'Proyectos',
                      module: 'proyectos',
                      icon: FolderKanban,
                  },
                  {
                      title: 'Presupuestos',
                      module: 'presupuestos',
                      icon: ChartNoAxesCombined,
                  },
              ]),
          ]
        : [];
    const systemItems: NavItem[] = companyId
        ? [
              ...(hasAccounting
                  ? [
                        {
                            title: 'Reportes',
                            href: `${dashboardPath}?module=reportes`,
                            icon: FileChartColumn,
                        },
                        {
                            title: 'Parámetros fiscales',
                            href: fiscalPath,
                            icon: ReceiptText,
                            isActive: currentPath === fiscalPath,
                        },
                    ]
                  : []),
              ...availableFutureModules(planFeatures, dashboardPath, [
                  {
                      title: 'Biblioteca Normativa',
                      module: 'normativa',
                      icon: LibraryBig,
                  },
                  { title: 'Documentos', module: 'documentos', icon: FileText },
                  {
                      title: 'Cotizaciones',
                      module: 'cotizaciones',
                      icon: FileSignature,
                  },
                  { title: 'FEL / SAT', module: 'fel', icon: BadgeCheck },
                  {
                      title: 'Portal Cliente',
                      module: 'portal-cliente',
                      icon: Globe2,
                  },
                  {
                      title: 'Notas de Crédito',
                      module: 'notas-credito',
                      icon: FileMinus2,
                  },
                  {
                      title: 'Órdenes de Compra',
                      module: 'ordenes-compra',
                      icon: ClipboardList,
                  },
                  {
                      title: 'Libros IVA',
                      module: 'libros-iva',
                      icon: BookMarked,
                  },
                  {
                      title: 'Cierre de Período',
                      module: 'cierre-periodo',
                      icon: LockKeyhole,
                  },
                  {
                      title: 'Estados de Cuenta',
                      module: 'estados-cuenta',
                      icon: Contact,
                  },
                  {
                      title: 'Recordatorios',
                      module: 'recordatorios',
                      icon: Bell,
                  },
                  { title: 'Auditoría', module: 'auditoria', icon: History },
              ]),
              ...(hasPlanFeature(planFeatures, 'roles_and_permissions')
                  ? [
                        {
                            title: 'Usuarios y Roles',
                            href: `${dashboardPath}?module=usuarios-roles`,
                            icon: UsersRound,
                            isActive: page.url.includes(
                                'module=usuarios-roles',
                            ),
                        },
                    ]
                  : []),
              {
                  title: 'Configuración',
                  href: `${dashboardPath}?module=configuracion`,
                  icon: Settings2,
                  isActive: page.url.includes('module=configuracion'),
              },
          ]
        : [];

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="atlantia-plan-sidebar"
        >
            <SidebarHeader className="border-b border-white/10 px-2 py-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent className="py-2">
                {companyId ? (
                    <>
                        <NavMain items={home} label={company?.name} />
                        <NavMain items={accountingItems} label="Contabilidad" />
                        <NavMain items={operationItems} label="Operaciones" />
                        {financeItems.length > 0 && (
                            <NavMain items={financeItems} label="Finanzas" />
                        )}
                        <NavMain items={systemItems} label="Sistema" />
                    </>
                ) : (
                    <NavMain
                        items={organizationNavItems}
                        label="Organización"
                    />
                )}
            </SidebarContent>
            <SidebarFooter className="border-t border-white/10 pt-2">
                {navigation?.plan && (
                    <div className="mx-2 mb-1 rounded-xl border border-white/10 bg-white/5 px-3 py-2 group-data-[collapsible=icon]:hidden">
                        <p className="truncate text-xs font-semibold text-[#e2bd68]">
                            Plan {navigation.plan.name}
                        </p>
                        <p className="mt-0.5 truncate text-[11px] text-white/55">
                            {navigation.organization.name}
                        </p>
                    </div>
                )}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
