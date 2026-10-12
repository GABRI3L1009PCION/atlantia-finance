import { usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-10 shrink-0 items-center justify-center rounded-lg bg-[#640000] p-1">
                <img
                    src="/images/atlantia-finance-logo.png"
                    alt=""
                    className="w-full object-contain"
                />
            </div>
            <div className="ml-2 grid min-w-0 flex-1 text-left">
                <span className="truncate text-sm leading-tight font-bold">
                    {name}
                </span>
                <span className="truncate text-xs leading-tight text-white/65">
                    Firma Contable
                </span>
            </div>
            <ChevronDown className="size-4 shrink-0 text-white/70 group-data-[collapsible=icon]:hidden" />
        </>
    );
}
