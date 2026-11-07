import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type PasswordProps = Omit<ComponentProps<'input'>, 'type'> & {
    ref?: Ref<HTMLInputElement>;
};

function RevealButton({
    visible,
    onToggle,
}: {
    visible: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            className="absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
            aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
            tabIndex={-1}
        >
            {visible ? (
                <EyeOff className="size-4" />
            ) : (
                <Eye className="size-4" />
            )}
        </button>
    );
}

export default function PasswordInput({
    className,
    ref,
    ...props
}: PasswordProps) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn('pr-10', className)}
                ref={ref}
                {...props}
            />
            <RevealButton
                visible={showPassword}
                onToggle={() => setShowPassword((prev) => !prev)}
            />
        </div>
    );
}

/**
 * The same eye toggle for screens that style their own inputs (public pages, the Control Center, dialogs): the
 * input keeps exactly the classes it is given and the wrapper takes the layout classes (margins, widths).
 */
export function PlainPasswordInput({
    className,
    wrapperClassName,
    ref,
    ...props
}: PasswordProps & { wrapperClassName?: string }) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className={cn('relative', wrapperClassName)}>
            <input
                type={showPassword ? 'text' : 'password'}
                className={cn(className, 'pr-10')}
                ref={ref}
                {...props}
            />
            <RevealButton
                visible={showPassword}
                onToggle={() => setShowPassword((prev) => !prev)}
            />
        </div>
    );
}
