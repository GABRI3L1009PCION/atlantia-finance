import { useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Loader2, ShieldAlert, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';

export type PlatformProps = {
    role: string | null;
    role_label: string | null;
    permissions: Record<string, boolean>;
    two_factor: boolean;
    badges: Record<string, number>;
    active_support_access: {
        id: number;
        organization: string;
        expires_at: string;
    }[];
};

export type Unavailable = { unavailable: true; message: string };

export function isUnavailable(value: unknown): value is Unavailable {
    return (
        typeof value === 'object' &&
        value !== null &&
        'unavailable' in value &&
        (value as Unavailable).unavailable === true
    );
}

export function usePlatform(): PlatformProps {
    return (usePage().props as unknown as { platform: PlatformProps }).platform;
}

export function useCan(): (permission: string) => boolean {
    const platform = usePlatform();

    return (permission: string) => platform.permissions[permission] === true;
}

export const panel =
    'rounded-2xl border border-white/[0.07] bg-[#11141b]/95 shadow-[0_18px_40px_-28px_rgba(0,0,0,.9)]';
export const ccInput =
    'h-10 w-full rounded-lg border border-white/10 bg-[#0b0e14] px-3 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-[#c8102e]/60 focus:ring-2 focus:ring-[#c8102e]/20';
export const ccTextarea =
    'min-h-24 w-full rounded-lg border border-white/10 bg-[#0b0e14] px-3 py-2 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-[#c8102e]/60 focus:ring-2 focus:ring-[#c8102e]/20';
export const ccButton =
    'inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-gradient-to-b from-[#c8102e] to-[#8f0b20] px-3.5 text-xs font-bold text-white shadow-[0_8px_20px_-10px_rgba(200,16,46,.8)] transition hover:brightness-110 disabled:opacity-50';
export const ccGhost =
    'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.03] px-3 text-xs font-semibold text-slate-200 transition hover:border-[#c8102e]/50 hover:text-white disabled:opacity-40';

const tones: Record<string, string> = {
    green: 'bg-[#22c55e]/10 text-[#4ade80] ring-[#22c55e]/25',
    amber: 'bg-[#f59e0b]/10 text-[#fbbf24] ring-[#f59e0b]/25',
    red: 'bg-[#ef4444]/10 text-[#f87171] ring-[#ef4444]/30',
    blue: 'bg-[#3b82f6]/10 text-[#93c5fd] ring-[#3b82f6]/25',
    violet: 'bg-[#8b5cf6]/10 text-[#c4b5fd] ring-[#8b5cf6]/25',
    slate: 'bg-white/5 text-slate-300 ring-white/10',
};

export function Pill({
    tone = 'slate',
    children,
    dot = true,
}: {
    tone?: string;
    children: ReactNode;
    dot?: boolean;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold whitespace-nowrap ring-1 ${tones[tone] ?? tones.slate}`}
        >
            {dot && <span className="size-1.5 rounded-full bg-current" />}
            {children}
        </span>
    );
}

export const healthTone: Record<string, string> = {
    healthy: 'green',
    degraded: 'amber',
    down: 'red',
    unknown: 'slate',
    maintenance: 'blue',
};

export const statusTone: Record<string, string> = {
    active: 'green',
    trial: 'amber',
    trialing: 'amber',
    past_due: 'red',
    grace_period: 'amber',
    suspended: 'red',
    cancelled: 'slate',
    expired: 'slate',
    archived: 'slate',
    paused: 'blue',
    incomplete: 'slate',
    pending_payment: 'slate',
    open: 'amber',
    paid: 'green',
    void: 'slate',
    refunded: 'violet',
    draft: 'slate',
    succeeded: 'green',
    failed: 'red',
    pending: 'amber',
    published: 'green',
    retired: 'slate',
    requested: 'amber',
    approved: 'blue',
    rejected: 'slate',
    revoked: 'slate',
    running: 'blue',
    completed: 'green',
    retrying: 'amber',
    ok: 'green',
    warning: 'amber',
    high: 'red',
    at_limit: 'red',
    over_limit: 'red',
    unlimited: 'blue',
    not_included: 'slate',
    in_progress: 'blue',
    waiting_customer: 'violet',
    waiting_internal: 'amber',
    resolved: 'green',
    closed: 'slate',
    investigating: 'red',
    identified: 'amber',
    mitigating: 'amber',
    monitoring: 'blue',
    postmortem: 'violet',
    enabled: 'green',
    disabled: 'slate',
    beta: 'violet',
    deprecated: 'slate',
    misconfigured: 'red',
    critical: 'red',
    medium: 'amber',
    low: 'slate',
    normal: 'blue',
    urgent: 'red',
};

export function CCPage({
    title,
    subtitle,
    actions,
    children,
}: {
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="space-y-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="font-serif text-3xl font-bold tracking-tight text-white">
                        {title}
                    </h1>
                    {subtitle && (
                        <p className="mt-1 max-w-3xl text-sm text-slate-400">
                            {subtitle}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </header>
            <Notice />
            {children}
        </div>
    );
}

export function Notice() {
    const page = usePage();
    const notice = (page.props as { notice?: string | null }).notice;
    const errors = Object.values(
        (page.props.errors ?? {}) as Record<string, string>,
    );
    const reveal = (
        page.props as {
            reveal?: { kind: string; title: string; value: string } | null;
        }
    ).reveal;

    return (
        <>
            {notice && (
                <div className="flex items-center gap-2 rounded-xl border border-[#22c55e]/25 bg-[#22c55e]/10 px-3 py-2 text-sm text-[#86efac]">
                    <Check className="size-4" />
                    {notice}
                </div>
            )}
            {errors.length > 0 && (
                <div className="rounded-xl border border-[#ef4444]/30 bg-[#ef4444]/10 px-3 py-2 text-sm text-[#fca5a5]">
                    {errors.map((error) => (
                        <p key={error}>{error}</p>
                    ))}
                </div>
            )}
            {reveal && (
                <div className="rounded-xl border border-[#f59e0b]/30 bg-[#f59e0b]/10 p-3 text-sm text-[#fde68a]">
                    <p className="font-bold">{reveal.title}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <code className="rounded-md bg-black/40 px-2 py-1 font-mono text-xs break-all text-white">
                            {reveal.value}
                        </code>
                        <CopyButton value={reveal.value} />
                    </div>
                </div>
            )}
        </>
    );
}

export function CopyButton({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            className={ccGhost}
            onClick={() => {
                void navigator.clipboard?.writeText(value);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 2000);
            }}
        >
            {copied ? (
                <Check className="size-3.5" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {copied ? 'Copiado' : 'Copiar'}
        </button>
    );
}

export function Panel({
    title,
    icon,
    action,
    children,
    className = '',
}: {
    title?: ReactNode;
    icon?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={`${panel} min-w-0 p-4 ${className}`}>
            {(title || action) && (
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                        {icon}
                        {title}
                    </h2>
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

export function Table({
    headers,
    children,
    empty,
}: {
    headers: ReactNode[];
    children: ReactNode;
    empty?: string | false;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs text-slate-300">
                <thead>
                    <tr className="border-b border-white/[0.06] text-left text-[11px] tracking-wide text-slate-500 uppercase">
                        {headers.map((header, index) => (
                            <th
                                key={index}
                                className="px-2 py-2 font-semibold whitespace-nowrap"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-white/[0.04]">
                    {children}
                    {empty && (
                        <tr>
                            <td
                                colSpan={headers.length}
                                className="px-3 py-10 text-center text-sm text-slate-500"
                            >
                                {empty}
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

export function Sparkline({
    values,
    color = '#c8102e',
    height = 28,
    width = 90,
}: {
    values: number[];
    color?: string;
    height?: number;
    width?: number;
}) {
    if (values.length === 0) {
        return null;
    }

    const max = Math.max(1, ...values);
    const step = width / Math.max(1, values.length - 1);
    const points = values
        .map(
            (value, index) =>
                `${index * step},${height - (value / max) * (height - 2) - 1}`,
        )
        .join(' ');

    return (
        <svg width={width} height={height} className="overflow-visible">
            <polyline
                points={points}
                fill="none"
                stroke={color}
                strokeWidth="1.6"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function Bars({
    values,
    color = '#c8102e',
}: {
    values: number[];
    color?: string;
}) {
    const max = Math.max(1, ...values);

    return (
        <div className="flex h-8 items-end gap-[3px]">
            {values.map((value, index) => (
                <span
                    key={index}
                    className="w-[4px] rounded-sm"
                    style={{
                        height: `${Math.max(8, (value / max) * 100)}%`,
                        background: color,
                        opacity: 0.35 + (index / values.length) * 0.65,
                    }}
                />
            ))}
        </div>
    );
}

export function Progress({
    percent,
    tone,
}: {
    percent: number | null;
    tone?: string;
}) {
    const value = Math.min(100, Math.max(0, percent ?? 0));
    const color =
        tone ?? (value >= 95 ? '#ef4444' : value >= 80 ? '#f59e0b' : '#c8102e');

    return (
        <div className="h-2 w-full overflow-hidden rounded-full bg-white/[0.06]">
            <div
                className="h-full rounded-full"
                style={{
                    width: `${value}%`,
                    background: `linear-gradient(90deg, ${color}aa, ${color})`,
                }}
            />
        </div>
    );
}

export function money(
    value: number | null | undefined,
    currency = 'GTQ',
): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${currency === 'GTQ' ? 'Q' : currency + ' '}${new Intl.NumberFormat('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;
}

export function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${size.toFixed(size >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export function date(
    value: string | null | undefined,
    withTime = false,
): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(
        'es-GT',
        withTime
            ? {
                  day: '2-digit',
                  month: '2-digit',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
              }
            : { day: '2-digit', month: '2-digit', year: 'numeric' },
    ).format(
        /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? new Date(`${value}T12:00:00`)
            : new Date(value),
    );
}

export function ago(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const minutes = Math.round(
        (Date.now() - new Date(value).getTime()) / 60000,
    );

    if (minutes < 1) {
        return 'ahora';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    if (minutes < 1440) {
        return `hace ${Math.round(minutes / 60)} h`;
    }

    return `hace ${Math.round(minutes / 1440)} d`;
}

export function Unavailable({ text = 'No disponible' }: { text?: string }) {
    return <span className="text-sm text-slate-500 italic">{text}</span>;
}

/**
 * Sensitive action: written reason, current password and authenticator code. The backend re-checks everything; this
 * only collects the confirmation.
 */
export function SensitiveAction({
    url,
    method = 'post',
    label,
    title,
    description,
    data = {},
    danger = false,
    requireReason = true,
    children,
    buttonClassName,
}: {
    url: string;
    method?: 'post' | 'put';
    label: ReactNode;
    title: string;
    description?: ReactNode;
    data?: Record<string, unknown>;
    danger?: boolean;
    requireReason?: boolean;
    children?: ReactNode;
    buttonClassName?: string;
}) {
    const platform = usePlatform();
    const [open, setOpen] = useState(false);
    const form = useForm<{ reason: string; password: string; code: string }>({
        reason: '',
        password: '',
        code: '',
    });

    return (
        <>
            <button
                type="button"
                className={
                    buttonClassName ??
                    (danger
                        ? `${ccGhost} border-[#ef4444]/40 text-[#fca5a5]`
                        : ccGhost)
                }
                onClick={() => setOpen(true)}
            >
                {label}
            </button>
            {open && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-black/70 p-4 backdrop-blur-sm">
                    <form
                        className={`${panel} w-full max-w-md space-y-3 p-5`}
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((values) => ({
                                ...values,
                                ...data,
                            }));
                            const options = {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            };

                            if (method === 'put') {
                                form.put(url, options);
                            } else {
                                form.post(url, options);
                            }
                        }}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-start gap-2">
                                <ShieldAlert
                                    className={`mt-0.5 size-5 ${danger ? 'text-[#f87171]' : 'text-[#fbbf24]'}`}
                                />
                                <div>
                                    <p className="font-bold text-white">
                                        {title}
                                    </p>
                                    {description && (
                                        <div className="mt-1 text-xs text-slate-400">
                                            {description}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <button
                                type="button"
                                aria-label="Cerrar"
                                onClick={() => setOpen(false)}
                                className="text-slate-500 hover:text-white"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                        {children}
                        {requireReason && (
                            <label className="grid gap-1 text-xs font-semibold text-slate-300">
                                Motivo (queda en la auditoría)
                                <textarea
                                    className={ccTextarea}
                                    value={form.data.reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    minLength={5}
                                />
                            </label>
                        )}
                        <label className="grid gap-1 text-xs font-semibold text-slate-300">
                            Tu contraseña
                            <input
                                type="password"
                                autoComplete="current-password"
                                className={ccInput}
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                required
                            />
                        </label>
                        {platform.two_factor && (
                            <label className="grid gap-1 text-xs font-semibold text-slate-300">
                                Código de autenticación (2FA)
                                <input
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    className={ccInput}
                                    value={form.data.code}
                                    onChange={(event) =>
                                        form.setData('code', event.target.value)
                                    }
                                    required
                                />
                            </label>
                        )}
                        {Object.values(form.errors).length > 0 && (
                            <div className="rounded-lg bg-[#ef4444]/10 p-2 text-xs text-[#fca5a5]">
                                {Object.values(form.errors).map((error) => (
                                    <p key={error}>{error}</p>
                                ))}
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                className={ccGhost}
                                onClick={() => setOpen(false)}
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                className={ccButton}
                                disabled={form.processing}
                            >
                                {form.processing && (
                                    <Loader2 className="size-3.5 animate-spin" />
                                )}
                                Confirmar
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}

export function Field({
    label,
    children,
    wide = false,
}: {
    label: string;
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <label
            className={`grid gap-1 text-xs font-semibold text-slate-300 ${wide ? 'md:col-span-2' : ''}`}
        >
            {label}
            {children}
        </label>
    );
}

export function Kpi({
    label,
    value,
    icon,
    hint,
    chart,
    tone = '#c8102e',
}: {
    label: string;
    value: ReactNode;
    icon: ReactNode;
    hint?: ReactNode;
    chart?: ReactNode;
    tone?: string;
}) {
    return (
        <div className={`${panel} relative overflow-hidden p-4`}>
            <div
                className="absolute -top-10 -right-10 size-28 rounded-full opacity-20 blur-2xl"
                style={{ background: tone }}
            />
            <div className="flex items-start gap-3">
                <span
                    className="grid size-11 shrink-0 place-items-center rounded-xl"
                    style={{ background: `${tone}22`, color: tone }}
                >
                    {icon}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold text-slate-300">
                        {label}
                    </p>
                    <p className="mt-1 text-[26px] leading-none font-black text-white">
                        {value}
                    </p>
                </div>
            </div>
            <div className="mt-3 flex items-end justify-between gap-2">
                <span className="text-[11px] text-slate-400">{hint}</span>
                {chart}
            </div>
        </div>
    );
}
