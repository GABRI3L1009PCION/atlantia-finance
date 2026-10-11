import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { switchMethod as switchOrganization } from '@/routes/workspace/organization';

export type SwitchableOrganization = {
    public_id: string;
    name: string;
    role: string;
    is_subscribed: boolean;
    is_current: boolean;
};

const roleLabels: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    member: 'Colaborador',
};

type OrganizationSwitcherProps = {
    planName?: string | null;
};

export function OrganizationSwitcher({ planName }: OrganizationSwitcherProps) {
    const organizations = (usePage().props.organizationSwitcher ??
        []) as SwitchableOrganization[];
    const current = organizations.find(
        (organization) => organization.is_current,
    );

    if (!planName && !current) {
        return null;
    }

    const summary = (
        <>
            <p className="truncate text-xs font-semibold text-[#e2bd68]">
                {planName ? `Plan ${planName}` : 'Sin plan activo'}
            </p>
            <p className="mt-0.5 truncate text-[11px] text-white/55">
                {current?.name ?? ''}
            </p>
        </>
    );

    if (organizations.length < 2) {
        return (
            <div className="mx-2 mb-1 rounded-xl border border-white/10 bg-white/5 px-3 py-2 group-data-[collapsible=icon]:hidden">
                {summary}
            </div>
        );
    }

    const switchTo = (organization: SwitchableOrganization) => {
        if (organization.is_current) {
            return;
        }

        router.post(switchOrganization.url(), {
            organization: organization.public_id,
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="mx-2 mb-1 flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-left transition group-data-[collapsible=icon]:hidden hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-[#c99b43] focus-visible:outline-none"
                    aria-label="Cambiar de organización"
                    data-test="organization-switcher"
                >
                    <span className="min-w-0 flex-1">{summary}</span>
                    <ChevronsUpDown className="size-4 shrink-0 text-white/60" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-72 rounded-lg"
                align="start"
                side="top"
            >
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Tus organizaciones
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {organizations.map((organization) => (
                    <DropdownMenuItem
                        key={organization.public_id}
                        onSelect={() => switchTo(organization)}
                        className="flex items-start gap-2 py-2"
                    >
                        <Building2 className="mt-0.5 size-4 shrink-0" />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium">
                                {organization.name}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {roleLabels[organization.role] ??
                                    organization.role}
                                {!organization.is_subscribed &&
                                    ' · Pendiente de pago'}
                            </span>
                        </span>
                        {organization.is_current && (
                            <Check className="mt-0.5 size-4 shrink-0" />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
