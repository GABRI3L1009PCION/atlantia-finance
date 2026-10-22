import { Check, X } from 'lucide-react';
import { passwordChecks } from '@/lib/password-rules';
import { cn } from '@/lib/utils';

/** Live list of what the password still needs, plus whether the confirmation matches. */
export default function PasswordRequirements({
    password,
    confirmation,
    className,
}: {
    password: string;
    confirmation?: string;
    className?: string;
}) {
    const checks = passwordChecks(password);

    if (confirmation !== undefined) {
        checks.push({
            key: 'match',
            label: 'Las dos coinciden',
            ok: password !== '' && password === confirmation,
        });
    }

    const typed = password !== '';

    return (
        <ul
            className={cn(
                'flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] leading-4',
                className,
            )}
            aria-label="Requisitos de la contraseña"
        >
            {checks.map((check) => (
                <li
                    key={check.key}
                    className={cn(
                        'flex items-center gap-1',
                        check.ok
                            ? 'text-emerald-700'
                            : typed
                              ? 'text-red-700'
                              : 'text-slate-500',
                    )}
                >
                    {check.ok ? (
                        <Check className="size-3 shrink-0" />
                    ) : (
                        <X className="size-3 shrink-0" />
                    )}
                    {check.label}
                </li>
            ))}
        </ul>
    );
}
