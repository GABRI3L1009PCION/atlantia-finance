import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items = [],
    label = 'Espacio de trabajo',
    tourId,
}: {
    items: NavItem[];
    label?: string;
    /** Name of the group in the guided tour; the label by default. */
    tourId?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const { state, isMobile } = useSidebar();
    const hasActive = items.some(
        (item) => item.isActive ?? isCurrentUrl(item.href),
    );
    // A manual choice only lasts while the group keeps the same "contains the current page" state, so
    // going to another section opens it and closes the one that was left.
    const [choice, setChoice] = useState<{
        forActive: boolean;
        open: boolean;
    } | null>(null);
    const open =
        choice !== null && choice.forActive === hasActive
            ? choice.open
            : hasActive;
    const iconOnly = state === 'collapsed' && !isMobile;
    const expanded = open || iconOnly;

    return (
        <SidebarGroup
            className="px-2 py-0"
            data-tour={`group:${tourId ?? label}`}
        >
            <SidebarGroupLabel asChild>
                <button
                    type="button"
                    title={label}
                    aria-expanded={expanded}
                    onClick={() =>
                        setChoice({ forActive: hasActive, open: !open })
                    }
                    className="flex w-full cursor-pointer items-center justify-between hover:text-sidebar-foreground"
                >
                    <span className="truncate">{label}</span>
                    <ChevronDown
                        className={`size-4 shrink-0 transition-transform ${expanded ? '' : '-rotate-90'}`}
                    />
                </button>
            </SidebarGroupLabel>
            {expanded && (
                <SidebarMenu>
                    {items.map((item) => (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={
                                    item.isActive ?? isCurrentUrl(item.href)
                                }
                                tooltip={{ children: item.title }}
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && <item.icon />}
                                    <span className="flex-1">{item.title}</span>
                                    {item.badge !== undefined &&
                                        item.badge > 0 && (
                                            <span className="grid min-w-5 place-items-center rounded-full bg-red-600 px-1.5 text-[11px] font-black text-white">
                                                {item.badge > 99
                                                    ? '99+'
                                                    : item.badge}
                                            </span>
                                        )}
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            )}
        </SidebarGroup>
    );
}
