import { Link, router } from '@inertiajs/react';
import {
    ChevronRight,
    LifeBuoy,
    LogOut,
    MonitorDown,
    Settings,
} from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const itemClass =
    'flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium whitespace-nowrap text-white focus:bg-white/10 focus:text-white';

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const getInitials = useInitials();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <div className="mx-1 mt-1 flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-3">
                <Avatar className="size-11 shrink-0 overflow-hidden rounded-full ring-2 ring-[#c0392b]/70">
                    <AvatarImage src={user.avatar} alt={user.name} />
                    <AvatarFallback className="bg-gradient-to-br from-[#8a0b0b] to-[#3b0a0a] text-lg font-semibold text-white">
                        {getInitials(user.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <p className="truncate text-base font-bold text-white">
                        {user.name}
                    </p>
                    <p className="truncate text-xs text-white/55">
                        {user.email}
                    </p>
                </div>
            </div>
            <DropdownMenuSeparator className="mx-3 bg-[#c0392b]/30" />
            <DropdownMenuGroup className="px-1">
                <DropdownMenuItem asChild className={itemClass}>
                    <Link href={edit()} prefetch onClick={cleanup}>
                        <Settings className="size-5 text-white/80" />
                        <span className="flex-1">Configuración</span>
                        <ChevronRight className="size-5 text-white/80" />
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild className={itemClass}>
                    <Link href="/desktop" onClick={cleanup}>
                        <MonitorDown className="size-5 text-white/80" />
                        <span className="flex-1">App de escritorio</span>
                        <ChevronRight className="size-5 text-white/80" />
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild className={itemClass}>
                    <Link href="/support" onClick={cleanup}>
                        <LifeBuoy className="size-5 text-white/80" />
                        <span className="flex-1">Soporte Atlantia</span>
                        <ChevronRight className="size-5 text-white/80" />
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator className="mx-3 bg-[#c0392b]/30" />
            <div className="px-1 pb-1">
                <DropdownMenuItem asChild className={itemClass}>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={handleLogout}
                        data-test="logout-button"
                    >
                        <LogOut className="size-5 text-[#f0585a]" />
                        Cerrar sesión
                    </Link>
                </DropdownMenuItem>
            </div>
        </>
    );
}
