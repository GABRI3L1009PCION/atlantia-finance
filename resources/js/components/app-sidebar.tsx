import { Link, usePage } from '@inertiajs/react';
import {
    Handshake,
    MessagesSquare,
    ListChecks,
    ChartColumnBig,
    AlertCircle,
    Link2,
    Clock3,
    ArrowLeftRight,
    BadgeCheck,
    BadgeDollarSign,
    Banknote,
    Bell,
    BookMarked,
    BookOpen,
    Boxes,
    BriefcaseBusiness,
    Building2,
    Calculator,
    CalendarDays,
    ChartNoAxesCombined,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardList,
    Contact,
    Copy,
    FileChartColumn,
    FileMinus2,
    FileSignature,
    FileSearch,
    FileText,
    FolderKanban,
    HandCoins,
    GraduationCap,
    Gauge,
    Globe2,
    History,
    Home,
    Landmark,
    LibraryBig,
    ListTree,
    LockKeyhole,
    Mail,
    MessageSquare,
    PackageSearch,
    Plug,
    ReceiptText,
    ScrollText,
    Scale,
    Send,
    Settings2,
    ShieldCheck,
    ShieldAlert,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    Truck,
    Users,
    UsersRound,
    WalletCards,
    WandSparkles,
    ClipboardPen,
    FileCheck2,
    PiggyBank,
    Route as RouteIcon,
    Microscope,
    FolderSearch,
    FileWarning,
    Tags,
    UserRound,
    FileUp,
    Receipt,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { OrganizationSwitcher } from '@/components/organization-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    EXECUTIVE_HOME,
    OFFICE_ADMIN_HOME,
    OPERATIONS_HOME,
    SUPERVISOR_HOME,
    ACCOUNTANT_HOME,
    ASSISTANT_HOME,
    TAX_HOME,
    TREASURY_HOME,
    RECEIVABLES_HOME,
    PAYABLES_HOME,
    PAYROLL_HOME,
    AUDITOR_HOME,
    CLIENT_HOME,
    firmWorkspace,
    withOfficeScope,
} from '@/lib/executive-workspace';
import { hasPlanFeature, hasPlanModule } from '@/lib/plan-capabilities';
import type { PlanModule } from '@/lib/plan-capabilities';
import { dashboard } from '@/routes';
import * as accounting from '@/routes/accounting';
import * as fel from '@/routes/accounting/fel';
import type { NavItem } from '@/types';

const organizationNavItems: NavItem[] = [
    {
        title: 'Panel ejecutivo',
        href: '/office/executive-dashboard',
        icon: Gauge,
    },
    { title: 'Administración', href: '/office/dashboard', icon: Settings2 },
    { title: 'Oficinas', href: '/office/offices', icon: Building2 },
    {
        title: 'Usuarios y Roles',
        href: '/office/users-roles',
        icon: UsersRound,
    },
    {
        title: 'Asignaciones',
        href: '/office/company-assignments',
        icon: Contact,
    },
    {
        title: 'Operaciones',
        href: '/operations/dashboard',
        icon: ClipboardList,
    },
    { title: 'Mi trabajo', href: '/work/dashboard', icon: ListTree },
    { title: 'Plantillas', href: '/work/task-templates', icon: ClipboardCheck },
    { title: 'Revisiones', href: '/work/reviews', icon: BadgeCheck },
    { title: 'Cierres', href: '/work/closings', icon: CalendarDays },
    {
        title: 'Revisión contable',
        href: '/work/accounting-review',
        icon: BadgeCheck,
    },
    {
        title: 'Supervisor contable',
        href: '/work/supervisor',
        icon: ClipboardList,
    },
    { title: 'Fiscal', href: '/work/tax', icon: ReceiptText },
    { title: 'Tesorería', href: '/work/treasury', icon: Landmark },
    {
        title: 'Cuentas por Cobrar',
        href: '/work/receivables',
        icon: CircleDollarSign,
    },
    { title: 'Cuentas por Pagar', href: '/work/payables', icon: WalletCards },
    { title: 'Nómina', href: '/work/payroll', icon: Users },
    { title: 'Auditoría', href: '/audit/dashboard', icon: History },
    { title: 'Biblioteca normativa', href: '/library', icon: LibraryBig },
    { title: 'Empresas clientes', href: accounting.index(), icon: Building2 },
    { title: 'Organización clásica', href: dashboard(), icon: Globe2 },
];

type WorkspaceNavigation = {
    organization: { name: string; role: string };
    homePath?: string;
    canViewExecutive?: boolean;
    canAdministerOffice?: boolean;
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

    const isPlatformStaff = page.props.isPlatformStaff === true;
    const executiveReadOnly = Boolean(page.props.executiveReadOnly);
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
    const isExecutiveWorkspace = workspace === 'executive';
    const isOfficeAdminWorkspace = workspace === 'office-admin';
    const selectedOfficeId = new URL(
        page.url,
        'https://atlantia.local',
    ).searchParams.get('office_id');
    const company =
        workspace !== null
            ? undefined
            : (pageCompany ?? navigation?.currentCompany ?? undefined);
    const companyId = company?.public_id;
    const planFeatures = navigation?.plan?.features ?? [];
    const hasAccounting = hasPlanFeature(planFeatures, 'basic_accounting');
    const hasInvoicing = hasPlanFeature(planFeatures, 'basic_invoicing');
    const hasObligations = hasPlanFeature(
        planFeatures,
        'obligation_management',
    );
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
    const vatBooksPath = companyId ? accounting.vatBooks.url(companyId) : '';

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
              ...(hasPlanModule(planFeatures, 'libros-iva')
                  ? [
                        {
                            title: 'Libros IVA',
                            href: accounting.vatBooks(companyId),
                            icon: BookMarked,
                            isActive: currentPath === vatBooksPath,
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
    const libraryLink: NavItem = {
        title: 'Biblioteca normativa',
        href: '/library',
        icon: LibraryBig,
        isActive:
            currentPath === '/library' || currentPath.startsWith('/library/'),
    };
    const libraryGroup = { label: 'Consulta', items: [libraryLink] };
    const executiveDirectionItems: NavItem[] = [
        {
            title: 'Inicio ejecutivo',
            href: EXECUTIVE_HOME,
            icon: Home,
            isActive: currentPath === EXECUTIVE_HOME && currentHash === '',
        },
        { title: 'Oficinas', href: '/office/offices', icon: Building2 },
        { title: 'Empresas clientes', href: '/accounting', icon: UsersRound },
        {
            title: 'Indicadores',
            href: '/office/indicators',
            icon: ChartNoAxesCombined,
        },
        {
            title: 'Cumplimiento',
            href: '/office/compliance',
            icon: ShieldCheck,
        },
        { title: 'Cierres', href: '/work/closings', icon: CalendarDays },
        { title: 'Mapa de riesgos', href: '/office/risk-map', icon: ShieldAlert },
    ];
    const executiveTeamItems: NavItem[] = [
        { title: 'Personal', href: '/office/users-roles', icon: Users },
        {
            title: 'Carga de trabajo',
            href: '/office/workload',
            icon: ListTree,
        },
        { title: 'Productividad', href: '/office/productivity', icon: Gauge },
        { title: 'Capacitación', href: '/office/training', icon: GraduationCap },
    ];
    const executiveBusinessItems: NavItem[] = [
        { title: 'Clientes (CRM)', href: '/office/crm', icon: Handshake },
        { title: 'Crecimiento', href: '/office/growth', icon: TrendingUp },
        {
            title: 'Proyecciones',
            href: '/office/projections',
            icon: ChartNoAxesCombined,
        },
        {
            title: 'Ingresos de la firma',
            href: '/office/revenue',
            icon: CircleDollarSign,
        },
    ];
    const executiveManagementItems: NavItem[] = [
        {
            title: 'Asignaciones',
            href: '/office/company-assignments',
            icon: Contact,
        },
        { title: 'Revisiones', href: '/work/reviews', icon: BadgeCheck },
        {
            title: 'Reportes ejecutivos',
            href: '/office/reports',
            icon: FileChartColumn,
        },
        { title: 'Auditoría', href: '/audit/dashboard', icon: ShieldCheck },
        libraryLink,
        { title: 'Luzma IA', href: '/luzma', icon: WandSparkles },
    ];
    // Once an Administrador de Oficina exists the director only supervises; until then they set the firm up.
    const executiveAccountItems: NavItem[] = [
        ...(!executiveReadOnly && navigation?.canAdministerOffice
            ? [
                  {
                      title: 'Administrar oficina',
                      href: OFFICE_ADMIN_HOME,
                      icon: Settings2,
                  },
              ]
            : []),
        {
            title: 'Integraciones',
            href: '/office/integrations',
            icon: Plug,
        },
        {
            title: 'Perfil de la firma',
            href: '/office/firm-profile',
            icon: Settings2,
        },
        {
            title: 'Plan y suscripción',
            href: '/office/subscription',
            icon: BadgeCheck,
        },
    ];
    const adminLink = (
        title: string,
        href: string,
        icon: LucideIcon,
    ): NavItem => {
        const [path, hash] = href.split('#');

        return {
            title,
            href: withOfficeScope(href, selectedOfficeId),
            icon,
            isActive:
                currentPath === path &&
                (hash ? currentHash === `#${hash}` : currentHash === ''),
        };
    };
    const selectedPeriod = new URL(
        page.url,
        'https://atlantia.local',
    ).searchParams.get('period');
    const operationsLink = (
        title: string,
        href: string,
        icon: LucideIcon,
    ): NavItem => {
        const [path, hash] = href.split('#');

        return {
            title,
            href: withOfficeScope(href, selectedOfficeId, selectedPeriod),
            icon,
            isActive:
                currentPath === path &&
                (hash ? currentHash === `#${hash}` : currentHash === ''),
        };
    };
    const supervisorBadges = (
        page.props as { context?: { badges?: Record<string, number> } }
    ).context?.badges;
    const supervisorLink = (
        title: string,
        href: string,
        icon: LucideIcon,
        badge?: number,
    ): NavItem => ({
        ...operationsLink(title, href, icon),
        isActive: currentPath === href.split('?')[0].split('#')[0],
        badge,
    });
    const supervisorGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Dashboard',
            items: [supervisorLink('Dashboard', SUPERVISOR_HOME, Home)],
        },
        {
            label: 'Empresas',
            items: [
                supervisorLink(
                    'Empresas asignadas',
                    '/supervisor/companies',
                    Building2,
                ),
                supervisorLink(
                    'Estado de empresas',
                    '/supervisor/companies?view=status',
                    ChartNoAxesCombined,
                ),
                supervisorLink(
                    'Expediente contable',
                    '/supervisor/companies?view=files',
                    FileText,
                ),
            ],
        },
        {
            label: 'Operaciones',
            items: [
                supervisorLink(
                    'Contabilidad',
                    '/supervisor/operations/accounting',
                    BookOpen,
                ),
                supervisorLink(
                    'Cuentas por cobrar',
                    '/supervisor/operations/receivables',
                    CircleDollarSign,
                ),
                supervisorLink(
                    'Cuentas por pagar',
                    '/supervisor/operations/payables',
                    WalletCards,
                ),
                supervisorLink(
                    'Bancos',
                    '/supervisor/operations/banks',
                    Landmark,
                ),
                supervisorLink(
                    'Documentos',
                    '/supervisor/operations/documents',
                    FileText,
                ),
            ],
        },
        {
            label: 'Supervisión',
            items: [
                supervisorLink('Equipo contable', '/supervisor/team', Users),
                supervisorLink(
                    'Asignación de trabajo',
                    '/supervisor/assignments',
                    ClipboardList,
                ),
                supervisorLink(
                    'Tareas pendientes',
                    '/supervisor/tasks',
                    ListTree,
                ),
                supervisorLink(
                    'Revisión y aprobación',
                    '/supervisor/reviews',
                    BadgeCheck,
                    supervisorBadges?.reviews,
                ),
                supervisorLink(
                    'Incidencias',
                    '/supervisor/incidents',
                    ShieldCheck,
                    supervisorBadges?.incidents,
                ),
            ],
        },
        {
            label: 'Fiscal',
            items: [
                supervisorLink(
                    'Obligaciones fiscales',
                    '/supervisor/fiscal/obligations',
                    ReceiptText,
                ),
                supervisorLink(
                    'Declaraciones',
                    '/supervisor/fiscal/declarations',
                    ScrollText,
                ),
                supervisorLink(
                    'FEL (Facturación)',
                    '/supervisor/fiscal/fel',
                    BadgeDollarSign,
                ),
                supervisorLink(
                    'Calendario fiscal',
                    '/supervisor/fiscal/calendar',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink(
                    'Alertas',
                    '/supervisor/alerts',
                    Bell,
                    supervisorBadges?.alerts,
                ),
                supervisorLink(
                    'Reportes',
                    '/supervisor/reports',
                    FileChartColumn,
                ),
                {
                    title: 'Comunicación',
                    href: '/communication',
                    icon: MessageSquare,
                    isActive: currentPath === '/communication',
                },
                supervisorLink(
                    'Portal del cliente',
                    '/work/client-portal',
                    Handshake,
                ),
                supervisorLink(
                    'Historial de auditoría',
                    '/supervisor/audit',
                    History,
                ),
            ],
        },
        libraryGroup,
    ];
    const assistantModules =
        (
            page.props as {
                context?: { modules?: { key: string; label: string }[] };
            }
        ).context?.modules ?? [];
    const assistantModuleIcons: Record<string, LucideIcon> = {
        documentos: FileText,
        ventas: ReceiptText,
        compras: ShoppingCart,
        clientes: Contact,
        proveedores: UsersRound,
        cxc: CircleDollarSign,
        cxp: WalletCards,
        bancos: Landmark,
        conciliacion: Scale,
        caja: Banknote,
        contabilidad: Calculator,
        diario: BookOpen,
        mayor: BookMarked,
        balance: ChartNoAxesCombined,
        inventario: Boxes,
        kardex: ArrowLeftRight,
        'activos-fijos': PackageSearch,
        nomina: Users,
        proyectos: FolderKanban,
        fel: BadgeCheck,
        'libros-iva': ScrollText,
        normativa: LibraryBig,
    };
    const planGatedModules = [
        'documentos',
        'conciliacion',
        'caja',
        'inventario',
        'kardex',
        'activos-fijos',
        'nomina',
        'proyectos',
        'fel',
        'libros-iva',
        'normativa',
    ];
    const assistantModuleLinks = (keys: string[]): NavItem[] =>
        assistantModules
            .filter((module) => keys.includes(module.key))
            .filter(
                (module) =>
                    !planGatedModules.includes(module.key) ||
                    hasPlanModule(planFeatures, module.key),
            )
            .map((module) =>
                supervisorLink(
                    module.label,
                    `/assistant/modules/${module.key}`,
                    assistantModuleIcons[module.key] ?? FileText,
                ),
            );
    const assistantGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio', ASSISTANT_HOME, Home),
                supervisorLink(
                    'Mis empresas',
                    '/assistant/companies',
                    Building2,
                ),
                supervisorLink(
                    'Mis tareas',
                    '/assistant/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Observaciones',
                    '/assistant/observations',
                    ClipboardCheck,
                    supervisorBadges?.observations,
                ),
                supervisorLink(
                    'Calendario',
                    '/assistant/calendar',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Operación',
            items: assistantModuleLinks([
                'documentos',
                'ventas',
                'compras',
                'clientes',
                'proveedores',
                'cxc',
                'cxp',
                'bancos',
                'conciliacion',
                'caja',
            ]),
        },
        {
            label: 'Contabilidad',
            items: assistantModuleLinks([
                'contabilidad',
                'diario',
                'mayor',
                'balance',
            ]),
        },
        {
            label: 'Otros procesos',
            items: assistantModuleLinks([
                'inventario',
                'kardex',
                'activos-fijos',
                'nomina',
                'proyectos',
            ]),
        },
        {
            label: 'Fiscal',
            items: [
                ...assistantModuleLinks(['fel', 'libros-iva']),
                supervisorLink(
                    'Obligaciones',
                    '/assistant/calendar',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink('Pendientes', '/assistant/pending', ListTree),
                supervisorLink(
                    'Documentos pendientes',
                    '/assistant/documents',
                    FileText,
                    supervisorBadges?.documents,
                ),
                supervisorLink(
                    'Conciliaciones',
                    '/assistant/reconciliations',
                    Scale,
                ),
                supervisorLink(
                    'Incidencias',
                    '/assistant/incidents',
                    ShieldCheck,
                    supervisorBadges?.incidents,
                ),
                {
                    title: 'Comunicación',
                    href: '/communication',
                    icon: MessageSquare,
                    isActive: currentPath === '/communication',
                },
                supervisorLink(
                    'Portal del cliente',
                    '/work/client-portal',
                    Handshake,
                ),
                supervisorLink(
                    'Reportes',
                    '/assistant/reports',
                    FileChartColumn,
                ),
                supervisorLink('Actividad', '/assistant/activity', History),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                {
                    title: 'Preferencias',
                    href: '/settings/profile',
                    icon: Settings2,
                    isActive: false,
                },
            ],
        },
    ].filter((group) => group.items.length > 0);
    const taxGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio fiscal', TAX_HOME, Home),
                supervisorLink('Mis empresas', '/tax/companies', Building2),
                supervisorLink(
                    'Mis tareas fiscales',
                    '/tax/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Calendario fiscal',
                    '/tax/calendar',
                    CalendarDays,
                    supervisorBadges?.overdue,
                ),
            ],
        },
        {
            label: 'Fiscal',
            items: [
                supervisorLink(
                    'Obligaciones',
                    '/tax/obligations',
                    ScrollText,
                    supervisorBadges?.obligations,
                ),
                supervisorLink('Libros IVA', '/tax/vat-books', BookOpen),
                supervisorLink(
                    'Facturación electrónica',
                    '/tax/fel',
                    BadgeCheck,
                    supervisorBadges?.fel,
                ),
                supervisorLink('Retenciones', '/tax/withholdings', ReceiptText),
                supervisorLink(
                    'Documentos fiscales',
                    '/tax/documents',
                    FileText,
                    supervisorBadges?.documents,
                ),
                supervisorLink(
                    'Conciliación fiscal-contable',
                    '/tax/reconciliation',
                    Scale,
                ),
            ],
        },
        {
            label: 'Revisión',
            items: [
                supervisorLink(
                    'Pendientes de revisión',
                    '/tax/reviews',
                    ClipboardCheck,
                    supervisorBadges?.reviews,
                ),
                supervisorLink(
                    'Observaciones',
                    '/tax/observations',
                    MessageSquare,
                    supervisorBadges?.observations,
                ),
                supervisorLink(
                    'Incidencias fiscales',
                    '/tax/incidents',
                    ShieldCheck,
                    supervisorBadges?.incidents,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink(
                    'Estado por empresa',
                    '/tax/companies',
                    ChartNoAxesCombined,
                ),
                supervisorLink(
                    'Reportes fiscales',
                    '/tax/reports',
                    FileChartColumn,
                ),
                supervisorLink('Actividad', '/tax/activity', History),
            ],
        },
        {
            label: 'Consulta',
            items: [
                supervisorLink(
                    'Biblioteca normativa',
                    '/tax/library',
                    LibraryBig,
                ),
            ],
        },
        {
            label: 'Configuración',
            items: [
                {
                    title: 'Preferencias',
                    href: '/settings/profile',
                    icon: Settings2,
                    isActive: false,
                },
            ],
        },
    ];
    const receivablesGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio CxC', RECEIVABLES_HOME, Home),
                supervisorLink(
                    'Mis empresas',
                    '/receivables/companies',
                    Building2,
                ),
                supervisorLink(
                    'Mis tareas de cobranza',
                    '/receivables/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Calendario de cobros',
                    '/receivables/calendar',
                    CalendarDays,
                    supervisorBadges?.followups,
                ),
            ],
        },
        {
            label: 'Cuentas por cobrar',
            items: [
                supervisorLink(
                    'Cartera de clientes',
                    '/receivables/customers',
                    Contact,
                ),
                supervisorLink(
                    'Documentos por cobrar',
                    '/receivables/documents',
                    FileText,
                ),
                supervisorLink(
                    'Vencimientos',
                    '/receivables/due-dates',
                    Clock3,
                ),
                supervisorLink('Cobros', '/receivables/receipts', HandCoins),
                supervisorLink(
                    'Cobros sin aplicar',
                    '/receivables/unapplied-receipts',
                    Link2,
                    supervisorBadges?.unapplied,
                ),
                supervisorLink(
                    'Promesas de pago',
                    '/receivables/promises',
                    Handshake,
                    supervisorBadges?.promises,
                ),
                supervisorLink(
                    'Gestiones de cobranza',
                    '/receivables/collections',
                    MessagesSquare,
                ),
                supervisorLink(
                    'Estados de cuenta',
                    '/receivables/statements',
                    ScrollText,
                ),
                supervisorLink(
                    'Disputas',
                    '/receivables/disputes',
                    AlertCircle,
                    supervisorBadges?.disputes,
                ),
                supervisorLink(
                    'Ajustes y castigos',
                    '/receivables/adjustments',
                    SlidersHorizontal,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink(
                    'Antigüedad de saldos',
                    '/receivables/aging',
                    ChartColumnBig,
                ),
                supervisorLink(
                    'Clientes vencidos',
                    '/receivables/overdue-customers',
                    ListChecks,
                ),
                supervisorLink(
                    'Conciliación CxC - Mayor',
                    '/receivables/reconciliation',
                    ArrowLeftRight,
                ),
                supervisorLink(
                    'Incidencias',
                    '/receivables/incidents',
                    ShieldAlert,
                    supervisorBadges?.incidents,
                ),
                supervisorLink(
                    'Reportes',
                    '/receivables/reports',
                    FileChartColumn,
                ),
                supervisorLink('Actividad', '/receivables/activity', History),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                {
                    title: 'Preferencias',
                    href: '/settings/profile',
                    icon: Settings2,
                    isActive: false,
                },
            ],
        },
    ];
    const payablesGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio CxP', PAYABLES_HOME, Home),
                supervisorLink(
                    'Mis empresas',
                    '/payables/companies',
                    Building2,
                ),
                supervisorLink(
                    'Mis tareas CxP',
                    '/payables/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Calendario de pagos',
                    '/payables/calendar',
                    CalendarDays,
                    supervisorBadges?.calendar,
                ),
            ],
        },
        {
            label: 'Cuentas por pagar',
            items: [
                supervisorLink(
                    'Proveedores con saldo',
                    '/payables/suppliers',
                    Truck,
                ),
                supervisorLink(
                    'Documentos por pagar',
                    '/payables/documents',
                    FileText,
                ),
                supervisorLink('Vencimientos', '/payables/due-dates', Clock3),
                supervisorLink(
                    'Propuestas de pago',
                    '/payables/proposals',
                    Send,
                    supervisorBadges?.proposals,
                ),
                supervisorLink('Pagos', '/payables/payments', Banknote),
                supervisorLink(
                    'Pagos sin aplicar',
                    '/payables/unapplied-payments',
                    Link2,
                    supervisorBadges?.unapplied,
                ),
                supervisorLink(
                    'Anticipos a proveedores',
                    '/payables/advances',
                    WalletCards,
                    supervisorBadges?.advances,
                ),
                supervisorLink(
                    'Notas de crédito',
                    '/payables/credit-notes',
                    FileMinus2,
                ),
                supervisorLink(
                    'Disputas',
                    '/payables/disputes',
                    AlertCircle,
                    supervisorBadges?.disputes,
                ),
                supervisorLink(
                    'Ajustes de CxP',
                    '/payables/adjustments',
                    SlidersHorizontal,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink('Antigüedad', '/payables/aging', ChartColumnBig),
                supervisorLink(
                    'Obligaciones vencidas',
                    '/payables/overdue',
                    ListChecks,
                ),
                supervisorLink(
                    'Posibles duplicados',
                    '/payables/duplicates',
                    Copy,
                ),
                supervisorLink(
                    'Conciliación CxP - Mayor',
                    '/payables/reconciliation',
                    ArrowLeftRight,
                ),
                supervisorLink(
                    'Incidencias',
                    '/payables/incidents',
                    ShieldAlert,
                    supervisorBadges?.incidents,
                ),
                supervisorLink(
                    'Reportes',
                    '/payables/reports',
                    FileChartColumn,
                ),
                supervisorLink('Actividad', '/payables/activity', History),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                {
                    title: 'Preferencias',
                    href: '/settings/profile',
                    icon: Settings2,
                    isActive: false,
                },
            ],
        },
    ];
    const auditorGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio Auditor', AUDITOR_HOME, Home),
                supervisorLink(
                    'Mis auditorías',
                    '/work/audit/engagements',
                    BriefcaseBusiness,
                    supervisorBadges?.engagements,
                ),
                supervisorLink(
                    'Mis tareas',
                    '/work/audit/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Calendario',
                    '/work/audit/calendar',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Auditoría',
            items: [
                supervisorLink(
                    'Planes de auditoría',
                    '/work/audit/plans',
                    ClipboardCheck,
                ),
                supervisorLink(
                    'Procedimientos',
                    '/work/audit/procedures',
                    ListChecks,
                ),
                supervisorLink(
                    'Evidencias',
                    '/work/audit/evidence',
                    FolderSearch,
                    supervisorBadges?.evidence,
                ),
                supervisorLink(
                    'Hallazgos',
                    '/work/audit/findings',
                    FileWarning,
                    supervisorBadges?.findings,
                ),
            ],
        },
        {
            label: 'Revisión',
            items: [
                supervisorLink(
                    'Revisión contable',
                    '/work/audit/accounting-review',
                    BookOpen,
                ),
                supervisorLink(
                    'Conciliaciones',
                    '/work/audit/reconciliations',
                    ArrowLeftRight,
                ),
                supervisorLink(
                    'Cierres contables',
                    '/work/audit/accounting-closes',
                    LockKeyhole,
                ),
                supervisorLink(
                    'Documentos soporte',
                    '/work/audit/supporting-documents',
                    FileText,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink(
                    'Riesgos y controles',
                    '/work/audit/risks-controls',
                    ShieldCheck,
                ),
                supervisorLink(
                    'Trazabilidad',
                    '/work/audit/traceability',
                    RouteIcon,
                ),
                supervisorLink(
                    'Registro de auditoría',
                    '/work/audit/system-events',
                    History,
                ),
                supervisorLink(
                    'Cambios sensibles',
                    '/work/audit/sensitive-changes',
                    ShieldAlert,
                ),
            ],
        },
        {
            label: 'Reportes',
            items: [
                supervisorLink(
                    'Reportes de auditoría',
                    '/work/audit/reports',
                    FileChartColumn,
                ),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                supervisorLink(
                    'Preferencias',
                    '/work/audit/preferences',
                    Settings2,
                ),
                supervisorLink('Actividad', '/work/audit/activity', Microscope),
            ],
        },
    ];
    const clientProps = page.props as {
        permissions?: Record<string, boolean>;
        context?: { fel_enabled?: boolean };
    };
    const clientCan = (permission: string) =>
        clientProps.permissions?.[permission] === true;
    const clientLink = (
        title: string,
        href: string,
        icon: LucideIcon,
        permission: string,
        badge?: number,
    ): NavItem[] =>
        clientCan(permission)
            ? [
                  {
                      title,
                      href,
                      icon,
                      isActive: currentPath === href,
                      badge: badge || undefined,
                  },
              ]
            : [];
    const clientGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi empresa',
            items: [
                ...clientLink(
                    'Inicio',
                    CLIENT_HOME,
                    Home,
                    'client.dashboard.view',
                ),
                ...clientLink(
                    'Mi empresa',
                    '/client/company',
                    Building2,
                    'client.company.view',
                ),
                ...clientLink(
                    'Pendientes',
                    '/client/pending',
                    ClipboardList,
                    'client.pending.view',
                    supervisorBadges?.pending,
                ),
                ...clientLink(
                    'Calendario',
                    '/client/calendar',
                    CalendarDays,
                    'client.calendar.view',
                ),
            ],
        },
        {
            label: 'Documentos',
            items: [
                ...clientLink(
                    'Documentos',
                    '/client/documents',
                    FileText,
                    'client.documents.view',
                ),
                ...clientLink(
                    'Solicitudes de documentos',
                    '/client/document-requests',
                    FileUp,
                    'client.document_requests.view',
                    supervisorBadges?.document_requests,
                ),
            ],
        },
        {
            label: 'Gestión',
            items: [
                ...clientLink(
                    'Autorizaciones',
                    '/client/approvals',
                    BadgeCheck,
                    'client.approvals.view',
                    supervisorBadges?.approvals,
                ),
                ...clientLink(
                    'Obligaciones',
                    '/client/obligations',
                    ScrollText,
                    'client.obligations.view',
                ),
                ...(clientProps.context?.fel_enabled
                    ? clientLink(
                          'Facturación / FEL',
                          '/client/fel',
                          Receipt,
                          'client.fel.view',
                      )
                    : []),
            ],
        },
        {
            label: 'Información',
            items: [
                ...clientLink(
                    'Reportes y estados',
                    '/client/reports',
                    FileChartColumn,
                    'client.reports.view',
                ),
                ...clientLink(
                    'Historial',
                    '/client/history',
                    History,
                    'client.history.view',
                ),
            ],
        },
        {
            label: 'Comunicación',
            items: clientLink(
                'Mensajes',
                '/client/messages',
                MessagesSquare,
                'client.messages.view',
                supervisorBadges?.messages,
            ),
        },
        {
            label: 'Configuración',
            items: clientLink(
                'Preferencias',
                '/client/preferences',
                Settings2,
                'client.dashboard.view',
            ),
        },
    ].filter((group) => group.items.length > 0);
    const payrollGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio Nómina', PAYROLL_HOME, Home),
                supervisorLink('Mis empresas', '/payroll/companies', Building2),
                supervisorLink(
                    'Mis tareas',
                    '/payroll/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Calendario de nómina',
                    '/payroll/calendar',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Nómina',
            items: [
                supervisorLink('Empleados', '/payroll/employees', UserRound),
                supervisorLink(
                    'Períodos de nómina',
                    '/payroll/periods',
                    CalendarDays,
                ),
                supervisorLink(
                    'Novedades',
                    '/payroll/novelties',
                    ClipboardPen,
                    supervisorBadges?.novelties,
                ),
                supervisorLink(
                    'Conceptos de nómina',
                    '/payroll/concepts',
                    Tags,
                ),
                supervisorLink(
                    'Pre-nómina',
                    '/payroll/pre-payroll',
                    FileSearch,
                ),
                supervisorLink(
                    'Cálculo de nómina',
                    '/payroll/calculation',
                    Calculator,
                ),
                supervisorLink(
                    'Revisiones y aprobaciones',
                    '/payroll/reviews',
                    BadgeCheck,
                    supervisorBadges?.reviews,
                ),
                supervisorLink(
                    'Recibos de pago',
                    '/payroll/receipts',
                    FileCheck2,
                ),
                supervisorLink(
                    'Órdenes de pago',
                    '/payroll/payment-orders',
                    Send,
                    supervisorBadges?.orders,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink(
                    'Anticipos y préstamos',
                    '/payroll/loans',
                    HandCoins,
                ),
                supervisorLink(
                    'Provisiones / prestaciones',
                    '/payroll/provisions',
                    PiggyBank,
                ),
                supervisorLink(
                    'Conciliación Nómina - Mayor',
                    '/payroll/reconciliation',
                    ArrowLeftRight,
                ),
                supervisorLink(
                    'Incidencias',
                    '/payroll/incidents',
                    ShieldAlert,
                    supervisorBadges?.incidents,
                ),
                supervisorLink('Reportes', '/payroll/reports', FileChartColumn),
                supervisorLink('Actividad', '/payroll/activity', History),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                supervisorLink(
                    'Preferencias',
                    '/payroll/preferences',
                    Settings2,
                ),
            ],
        },
    ];
    const treasuryGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Mi trabajo',
            items: [
                supervisorLink('Inicio tesorería', TREASURY_HOME, Home),
                supervisorLink(
                    'Mis empresas',
                    '/treasury/companies',
                    Building2,
                ),
                supervisorLink(
                    'Mis tareas',
                    '/treasury/tasks',
                    ClipboardList,
                    supervisorBadges?.tasks,
                ),
                supervisorLink(
                    'Calendario',
                    '/treasury/calendar',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Bancos',
            items: [
                supervisorLink(
                    'Bancos y cuentas',
                    '/treasury/accounts',
                    Landmark,
                ),
                supervisorLink(
                    'Movimientos por conciliar',
                    '/treasury/movements',
                    FileSearch,
                    supervisorBadges?.movements,
                ),
                supervisorLink(
                    'Conciliación bancaria',
                    '/treasury/reconciliations',
                    Scale,
                    supervisorBadges?.reconciliations,
                ),
                supervisorLink(
                    'Transferencias',
                    '/treasury/transfers',
                    ArrowLeftRight,
                ),
                supervisorLink('Caja y arqueos', '/treasury/cash', WalletCards),
            ],
        },
        {
            label: 'Cobros y pagos',
            items: [
                supervisorLink(
                    'Cobros',
                    '/treasury/receipts',
                    HandCoins,
                    supervisorBadges?.receipts,
                ),
                supervisorLink(
                    'Pagos',
                    '/treasury/payments',
                    Send,
                    supervisorBadges?.payments,
                ),
                supervisorLink(
                    'Programación de pagos',
                    '/treasury/schedule',
                    CalendarDays,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                supervisorLink(
                    'Flujo de caja',
                    '/treasury/cash-flow',
                    TrendingUp,
                ),
                supervisorLink(
                    'Incidencias',
                    '/treasury/incidents',
                    ShieldAlert,
                    supervisorBadges?.incidents,
                ),
                supervisorLink(
                    'Reportes',
                    '/treasury/reports',
                    FileChartColumn,
                ),
                supervisorLink('Actividad', '/treasury/activity', History),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                {
                    title: 'Preferencias',
                    href: '/settings/profile',
                    icon: Settings2,
                    isActive: false,
                },
            ],
        },
    ];
    const accountantGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Inicio',
            items: [supervisorLink('Dashboard', ACCOUNTANT_HOME, Home)],
        },
        {
            label: 'Empresas',
            items: [
                supervisorLink(
                    'Empresas a cargo',
                    '/accountant/companies',
                    Building2,
                ),
                supervisorLink(
                    'Revisión de empresas',
                    '/accountant/reviews',
                    BadgeCheck,
                    supervisorBadges?.reviews,
                ),
                supervisorLink(
                    'Observaciones',
                    '/accountant/observations',
                    ClipboardCheck,
                    supervisorBadges?.observations,
                ),
                supervisorLink(
                    'Aprobaciones de tesorería',
                    '/treasury/approvals',
                    Banknote,
                ),
                supervisorLink(
                    'Ajustes de cartera',
                    '/receivables/adjustments',
                    SlidersHorizontal,
                ),
                supervisorLink(
                    'Propuestas de pago',
                    '/payables/proposals',
                    Send,
                ),
                supervisorLink(
                    'Ajustes de CxP',
                    '/payables/adjustments',
                    SlidersHorizontal,
                ),
            ],
        },
        {
            label: 'Contabilidad',
            items: [
                supervisorLink(
                    'Pólizas pendientes',
                    '/accountant/entries',
                    BookOpen,
                ),
                supervisorLink(
                    'Conciliaciones',
                    '/accountant/reconciliations',
                    Scale,
                ),
                supervisorLink(
                    'Ajustes contables',
                    '/accountant/adjustments',
                    SlidersHorizontal,
                ),
                supervisorLink('Cierres', '/accountant/closings', LockKeyhole),
            ],
        },
        {
            label: 'Seguimiento',
            items: [
                supervisorLink(
                    'Incidencias',
                    '/accountant/incidents',
                    ShieldCheck,
                    supervisorBadges?.incidents,
                ),
                supervisorLink('Documentos', '/accountant/documents', FileText),
                supervisorLink(
                    'Calendario',
                    '/accountant/calendar',
                    CalendarDays,
                ),
                {
                    title: 'Comunicación',
                    href: '/communication',
                    icon: MessageSquare,
                    isActive: currentPath === '/communication',
                },
                supervisorLink(
                    'Portal del cliente',
                    '/work/client-portal',
                    Handshake,
                ),
                supervisorLink(
                    'Reportes',
                    '/accountant/reports',
                    FileChartColumn,
                ),
                supervisorLink('Actividad', '/accountant/activity', History),
            ],
        },
        libraryGroup,
    ];
    const operationsGroups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Operación',
            items: [
                operationsLink('Inicio', OPERATIONS_HOME, Home),
                operationsLink('Empresas', '/operations/companies', Building2),
                operationsLink('Tareas', '/operations/tasks', ListTree),
                operationsLink('Revisiones', '/operations/reviews', BadgeCheck),
                operationsLink('Cierres', '/operations/closings', LockKeyhole),
                operationsLink(
                    'Calendario',
                    '/operations/calendar',
                    CalendarDays,
                ),
                operationsLink(
                    'Incidencias',
                    '/operations/incidents',
                    ShieldCheck,
                ),
                operationsLink(
                    'Ajustes de cartera',
                    '/receivables/adjustments',
                    SlidersHorizontal,
                ),
                operationsLink(
                    'Propuestas de pago',
                    '/payables/proposals',
                    Send,
                ),
                operationsLink(
                    'Ajustes de CxP',
                    '/payables/adjustments',
                    SlidersHorizontal,
                ),
            ],
        },
        {
            label: 'Equipo',
            items: [
                operationsLink('Mi equipo', '/operations/team', Users),
                operationsLink(
                    'Carga de trabajo',
                    '/operations/team#carga',
                    Gauge,
                ),
                operationsLink(
                    'Asignaciones operativas',
                    '/operations/assignments',
                    Contact,
                ),
            ],
        },
        {
            label: 'Control',
            items: [
                operationsLink(
                    'Avance',
                    '/operations/progress',
                    ChartNoAxesCombined,
                ),
                operationsLink(
                    'Reportes operativos',
                    '/operations/reports',
                    FileChartColumn,
                ),
                operationsLink('Actividad', '/operations/activity', History),
            ],
        },
        libraryGroup,
        {
            label: 'Configuración',
            items: [
                {
                    title: 'Preferencias',
                    href: '/settings/profile',
                    icon: Settings2,
                },
            ],
        },
    ];
    const officeAdminGroups: { label: string; items: NavItem[] }[] = [
        ...(navigation?.canViewExecutive
            ? [
                  {
                      label: 'Dirección',
                      items: [
                          {
                              title: 'Panel ejecutivo',
                              href: EXECUTIVE_HOME,
                              icon: Gauge,
                          },
                      ],
                  },
              ]
            : []),
        {
            label: 'Administración',
            items: [
                adminLink('Inicio', OFFICE_ADMIN_HOME, Home),
                { title: 'Oficinas', href: '/office/offices', icon: Building2 },
                adminLink('Empresas', '/office/companies', BriefcaseBusiness),
                adminLink('Equipo', '/office/team', Users),
                adminLink(
                    'Asignaciones',
                    '/office/assignments',
                    Contact,
                ),
            ],
        },
        {
            label: 'Usuarios',
            items: [
                adminLink('Usuarios', '/office/users', UsersRound),
                {
                    title: 'Roles y Permisos',
                    href: '/office/users-roles',
                    icon: ShieldCheck,
                },
                adminLink('Invitaciones', '/office/users#invitaciones', Mail),
            ],
        },
        {
            label: 'Operación',
            items: [
                adminLink(
                    'Carga de trabajo',
                    '/office/team-workload',
                    ListTree,
                ),
                adminLink(
                    'Tareas sin asignar',
                    '/office/unassigned-tasks',
                    ClipboardList,
                ),
                {
                    title: 'Capacitación',
                    href: '/office/training',
                    icon: GraduationCap,
                },
                { title: 'Clientes (CRM)', href: '/office/crm', icon: Handshake },
                {
                    title: 'Perfil de la firma',
                    href: '/office/firm-profile',
                    icon: Settings2,
                },
            ],
        },
        {
            label: 'Control',
            items: [
                adminLink('Auditoría', '/office/audit', History),
                adminLink('Seguridad', '/office/security', LockKeyhole),
            ],
        },
        {
            label: 'Sistema',
            items: [
                adminLink('Integraciones', '/office/integrations', Plug),
                adminLink('Configuración', '/office/settings', Settings2),
                {
                    title: 'Plan y Suscripción',
                    href: '/billing/plans',
                    icon: WalletCards,
                },
            ],
        },
        libraryGroup,
    ];

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="atlantia-plan-sidebar"
        >
            <SidebarHeader className="border-b border-white/10 px-3 py-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={
                                    isPlatformStaff
                                        ? '/control-center'
                                        : dashboard()
                                }
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent className="py-2">
                {isPlatformStaff ? (
                    <NavMain
                        items={[
                            {
                                title: 'Control Center',
                                href: '/control-center',
                                icon: ShieldCheck,
                                isActive: false,
                            },
                        ]}
                        label="Atlantia Systems"
                    />
                ) : companyId ? (
                    <>
                        <NavMain items={home} label={company?.name} />
                        <NavMain items={accountingItems} label="Contabilidad" />
                        <NavMain items={operationItems} label="Operaciones" />
                        {financeItems.length > 0 && (
                            <NavMain items={financeItems} label="Finanzas" />
                        )}
                        <NavMain items={systemItems} label="Sistema" />
                    </>
                ) : workspace === 'receivables' ? (
                    <>
                        {receivablesGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'payables' ? (
                    <>
                        {payablesGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'client' ? (
                    <>
                        {clientGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'auditor' ? (
                    <>
                        {auditorGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'payroll' ? (
                    <>
                        {payrollGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'treasury' ? (
                    <>
                        {treasuryGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'tax' ? (
                    <>
                        {taxGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'assistant' ? (
                    <>
                        {assistantGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'accountant' ? (
                    <>
                        {accountantGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'supervisor' ? (
                    <>
                        {supervisorGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : workspace === 'operations' ? (
                    <>
                        {operationsGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : isOfficeAdminWorkspace ? (
                    <>
                        {officeAdminGroups.map((group) => (
                            <NavMain
                                key={group.label}
                                items={group.items}
                                label={group.label}
                            />
                        ))}
                    </>
                ) : isExecutiveWorkspace ? (
                    <>
                        <NavMain
                            items={executiveDirectionItems}
                            label="Dirección"
                        />
                        <NavMain items={executiveTeamItems} label="Equipo" />
                        <NavMain
                            items={executiveBusinessItems}
                            label="Negocio"
                        />
                        <NavMain
                            items={executiveManagementItems}
                            label="Gestión"
                        />
                        <NavMain items={executiveAccountItems} label="Cuenta" />
                    </>
                ) : (
                    <NavMain
                        items={organizationNavItems}
                        label="Organización"
                    />
                )}
            </SidebarContent>
            <SidebarFooter className="border-t border-white/10 pt-2">
                {!isPlatformStaff && (
                    <OrganizationSwitcher planName={navigation?.plan?.name} />
                )}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
