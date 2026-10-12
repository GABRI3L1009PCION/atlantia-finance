import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    icon: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    danger?: boolean;
    children?: ReactNode;
};

export function SettingsCard({
    icon: Icon,
    title,
    description,
    action,
    danger,
    children,
}: Props) {
    return (
        <section
            className={cn(
                'rounded-2xl border bg-card p-5 shadow-sm sm:p-6',
                danger
                    ? 'border-red-300/60 bg-red-50/60 dark:border-red-500/30 dark:bg-red-950/20'
                    : 'border-border',
            )}
        >
            <header className="flex items-start gap-4">
                <span
                    className={cn(
                        'grid size-11 shrink-0 place-items-center rounded-xl',
                        danger
                            ? 'bg-red-100 text-red-600 dark:bg-red-500/15'
                            : 'bg-[#f6e6e4] text-[#8a0b0b] dark:bg-[#8a0b0b]/20 dark:text-red-300',
                    )}
                >
                    <Icon className="size-5" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg leading-tight font-bold">{title}</h2>
                    {description && (
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {action}
            </header>
            {children && <div className="mt-5">{children}</div>}
        </section>
    );
}
