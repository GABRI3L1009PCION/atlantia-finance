import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    FileChartColumn,
    LayoutGrid,
    ReceiptText,
    ShoppingCart,
} from 'lucide-react';
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
import { dashboard } from '@/routes';
import * as accounting from '@/routes/accounting';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Empresas clientes',
        href: accounting.index(),
        icon: Building2,
    },
    {
        title: 'Mi organización',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const page = usePage();
    const company = page.props.company as
        { public_id?: string; name?: string } | undefined;
    const companyId = company?.public_id;
    const companyBase = companyId ? accounting.show.url(companyId) : undefined;
    const companyNavItems: NavItem[] = companyId
        ? [
              {
                  title: 'Libros y cuentas',
                  href: accounting.show(companyId),
                  icon: BookOpen,
                  isActive: page.url.split('?')[0] === companyBase,
              },
              {
                  title: 'Estados financieros',
                  href: accounting.reports(companyId),
                  icon: FileChartColumn,
                  isActive: page.url
                      .split('?')[0]
                      .startsWith(accounting.reports.url(companyId)),
              },
              {
                  title: 'Clientes y cobros',
                  href: accounting.sales(companyId),
                  icon: ReceiptText,
                  isActive: page.url
                      .split('?')[0]
                      .startsWith(accounting.sales.url(companyId)),
              },
              {
                  title: 'Compras y pagos',
                  href: accounting.purchases(companyId),
                  icon: ShoppingCart,
                  isActive: page.url
                      .split('?')[0]
                      .startsWith(accounting.purchases.url(companyId)),
              },
          ]
        : [];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
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

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {companyNavItems.length > 0 && (
                    <NavMain
                        items={companyNavItems}
                        label={company?.name ?? 'Empresa actual'}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
