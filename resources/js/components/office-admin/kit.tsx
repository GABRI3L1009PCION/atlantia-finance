import { useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Loader2, Plus, Trash2, UserPlus } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type Tone = 'green' | 'amber' | 'red' | 'blue' | 'slate';

export type OfficeScope = {
    global: boolean;
    offices: {
        id: number;
        name: string;
        code?: string;
        city?: string | null;
        status?: string;
    }[];
    selected: number | null;
};

export type CompatibleUser = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    assigned_companies: number;
    open_tasks: number;
};

export const panel =
    'rounded-xl border border-[#e7e1dd] bg-white shadow-[0_8px_22px_-18px_rgba(49,11,8,.45)]';
export const chip =
    'rounded-full border border-red-100 bg-red-50 px-3 py-1.5 text-xs font-bold text-[#640000]';
export const input =
    'h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15';
export const primaryButton =
    'inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-[#640000] px-4 text-sm font-bold text-white transition hover:bg-[#7a0a0a] disabled:opacity-60';
export const secondaryButton =
    'inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-xs font-bold text-[#172033] transition hover:border-[#c9a36a] disabled:opacity-50';

export function badge(tone: Tone) {
    const styles: Record<Tone, string> = {
        green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        amber: 'bg-amber-50 text-amber-700 ring-amber-200',
        red: 'bg-red-50 text-red-700 ring-red-200',
        blue: 'bg-blue-50 text-blue-700 ring-blue-200',
        slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    };

    return `inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${styles[tone]}`;
}

export const priorityLabel: Record<string, [string, Tone]> = {
    high: ['Alta', 'red'],
    medium: ['Media', 'amber'],
    low: ['Baja', 'green'],
    urgent: ['Urgente', 'red'],
    normal: ['Normal', 'amber'],
};

export function formatDate(
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
        // A plain date is a calendar day, not UTC midnight (which shows the day before in Guatemala).
        /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? new Date(`${value}T12:00:00`)
            : new Date(value),
    );
}

export function relativeTime(value: string): string {
    const minutes = Math.round(
        (Date.now() - new Date(value).getTime()) / 60000,
    );

    if (minutes < 1) {
        return 'Ahora';
    }

    if (minutes < 60) {
        return `Hace ${minutes} min`;
    }

    if (minutes < 1440) {
        return `Hace ${Math.round(minutes / 60)} h`;
    }

    return formatDate(value);
}

/** Current branch filter of the page, to keep it on links. */
export function useOfficeQuery(): string | null {
    const page = usePage();

    return new URL(page.url, 'https://atlantia.local').searchParams.get(
        'office_id',
    );
}

export function scoped(href: string, officeId: string | null): string {
    if (!officeId) {
        return href;
    }

    const [path, hash] = href.split('#');

    return `${path}${path.includes('?') ? '&' : '?'}office_id=${encodeURIComponent(officeId)}${hash ? `#${hash}` : ''}`;
}

export function PageHeader({
    title,
    subtitle,
    actions,
}: {
    title: string;
    subtitle: string;
    actions?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="text-3xl font-black tracking-tight text-[#061633]">
                    {title}
                </h1>
                <p className="mt-0.5 text-base text-[#526b92]">{subtitle}</p>
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}

export function SectionHeader({
    title,
    action,
    id,
}: {
    title: string;
    action?: ReactNode;
    id?: string;
}) {
    return (
        <div
            id={id}
            className="mb-3 flex scroll-mt-24 flex-wrap items-center justify-between gap-2"
        >
            <h2 className="text-lg font-black text-[#061633]">{title}</h2>
            {action}
        </div>
    );
}

export function Notice({ message }: { message: string | null }) {
    const page = usePage();
    const errors = Object.values(
        (page.props.errors ?? {}) as Record<string, string>,
    );

    return (
        <>
            {message && (
                <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">
                    <CheckCircle2 className="size-5 shrink-0" />
                    {message}
                </div>
            )}
            {errors.length > 0 && (
                <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">
                    {errors.map((error) => (
                        <p key={error}>{error}</p>
                    ))}
                </div>
            )}
        </>
    );
}

export function EmptyRow({ colSpan, text }: { colSpan: number; text: string }) {
    return (
        <tr>
            <td
                colSpan={colSpan}
                className="px-3 py-8 text-center text-sm text-slate-500"
            >
                {text}
            </td>
        </tr>
    );
}

export function Shell({ children }: { children: ReactNode }) {
    return (
        <main className="min-h-full flex-1 overflow-x-hidden bg-[#f6f8fb] p-2 text-[#0b1733] sm:p-4">
            <div className="w-full space-y-4">{children}</div>
        </main>
    );
}

/**
 * Centered confirmation modal that replaces the browser's confirm/prompt boxes. With `noteLabel` it also asks for a
 * written reason (required when `noteRequired`), which is handed to `onConfirm`.
 */
export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel,
    tone = 'primary',
    icon,
    noteLabel,
    noteRequired = false,
    processing = false,
    onConfirm,
    onClose,
    children,
}: {
    open: boolean;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    tone?: 'primary' | 'danger';
    icon?: ReactNode;
    noteLabel?: string;
    noteRequired?: boolean;
    processing?: boolean;
    onConfirm: (note: string) => void;
    onClose: () => void;
    children?: ReactNode;
}) {
    const [note, setNote] = useState('');
    const blocked =
        processing ||
        (noteLabel !== undefined && noteRequired && note.trim().length < 3);

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) {
                    setNote('');
                    onClose();
                }
            }}
        >
            <DialogContent className="overflow-hidden border-0 p-0 sm:max-w-md">
                <div
                    className={`h-1.5 ${tone === 'danger' ? 'bg-red-600' : 'bg-[#640000]'}`}
                />
                <div className="px-6 pt-3 pb-6">
                    <div
                        className={`mx-auto mb-4 grid size-14 place-items-center rounded-full ${tone === 'danger' ? 'bg-red-50 text-red-700' : 'bg-[#f6e4e4] text-[#640000]'}`}
                    >
                        {icon}
                    </div>
                    <DialogHeader className="text-center sm:text-center">
                        <DialogTitle className="text-xl text-slate-950">
                            {title}
                        </DialogTitle>
                        <DialogDescription className="mt-1.5 text-sm leading-6 text-slate-600">
                            {description}
                        </DialogDescription>
                    </DialogHeader>
                    {children}
                    {noteLabel !== undefined && (
                        <label className="mt-4 block text-xs font-bold tracking-wide text-slate-600 uppercase">
                            {noteLabel}
                            <textarea
                                value={note}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                                maxLength={500}
                                rows={3}
                                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal tracking-normal text-slate-900 normal-case outline-none focus:border-[#8a5b32] focus:ring-2 focus:ring-[#b8893f]/15"
                            />
                        </label>
                    )}
                    <DialogFooter className="mt-5 gap-2 sm:justify-center">
                        <button
                            type="button"
                            onClick={() => {
                                setNote('');
                                onClose();
                            }}
                            className="inline-flex h-10 items-center justify-center rounded-lg border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            disabled={blocked}
                            onClick={() => {
                                onConfirm(note.trim());
                                setNote('');
                            }}
                            className={`inline-flex h-10 items-center justify-center gap-2 rounded-lg px-5 text-sm font-bold text-white transition disabled:opacity-50 ${tone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-[#640000] hover:bg-[#7a0a0a]'}`}
                        >
                            {confirmLabel}
                        </button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export type AssignTarget = {
    company_id: number;
    company_name: string;
    office_name?: string;
    missing_role: string;
    missing_label: string;
    compatible_users?: CompatibleUser[];
    office_id: number;
};

/**
 * Assignment form: only collaborators of the company's office whose role allows the responsibility are offered;
 * the server validates the same rule again.
 */
export function AssignDialog({
    target,
    onClose,
}: {
    target: AssignTarget | null;
    onClose: () => void;
}) {
    const [loaded, setLoaded] = useState<{
        key: string;
        users: CompatibleUser[];
    } | null>(null);
    const form = useForm({
        company_id: 0,
        user_id: '',
        assignment_role: '',
        primary: true,
    });
    const key = target ? `${target.office_id}|${target.missing_role}` : '';
    const users =
        target?.compatible_users ?? (loaded?.key === key ? loaded.users : null);

    const needsLookup = target !== null && !target.compatible_users;

    useEffect(() => {
        if (!needsLookup || key === '') {
            return;
        }

        const [officeId, role] = key.split('|');
        const controller = new AbortController();
        fetch(`/office/assignable-users?office_id=${officeId}&role=${role}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => response.json())
            .then((payload: { users: CompatibleUser[] }) =>
                setLoaded({ key, users: payload.users ?? [] }),
            )
            .catch(() => {
                if (!controller.signal.aborted) {
                    setLoaded({ key, users: [] });
                }
            });

        return () => controller.abort();
    }, [key, needsLookup]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!target) {
            return;
        }

        form.transform((data) => ({
            ...data,
            company_id: target.company_id,
            assignment_role: target.missing_role,
            primary:
                target.missing_role === 'accountant_in_charge'
                    ? true
                    : data.primary,
        }));
        form.post('/office/assignments', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Asignar {target?.missing_label.toLowerCase()}
                    </DialogTitle>
                    <DialogDescription>
                        {target?.company_name}
                        {target?.office_name ? ` · ${target.office_name}` : ''}.
                        Solo aparecen colaboradores activos de la oficina cuyo
                        rol permite esta responsabilidad.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {users === null && (
                        <p className="flex items-center gap-2 text-sm text-slate-500">
                            <Loader2 className="size-4 animate-spin" /> Buscando
                            colaboradores compatibles...
                        </p>
                    )}
                    {users !== null && users.length === 0 && (
                        <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                            No hay colaboradores compatibles en esta oficina.
                            Invita a alguien con el rol adecuado o cambia el rol
                            de un colaborador.
                        </p>
                    )}
                    <div className="max-h-72 space-y-1.5 overflow-y-auto">
                        {users?.map((user) => (
                            <label
                                key={user.id}
                                className={`flex cursor-pointer items-center justify-between gap-3 rounded-lg border p-2.5 text-sm ${form.data.user_id === user.id.toString() ? 'border-[#640000] bg-red-50/40' : 'border-slate-200'}`}
                            >
                                <span className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="user_id"
                                        value={user.id}
                                        checked={
                                            form.data.user_id ===
                                            user.id.toString()
                                        }
                                        onChange={() =>
                                            form.setData(
                                                'user_id',
                                                user.id.toString(),
                                            )
                                        }
                                    />
                                    <span>
                                        <span className="block font-bold">
                                            {user.name}
                                        </span>
                                        <span className="block text-xs text-slate-500">
                                            {user.role ?? 'Sin rol operativo'}
                                        </span>
                                    </span>
                                </span>
                                <span className="text-right text-xs text-slate-500">
                                    {user.assigned_companies} empresa(s)
                                    <br />
                                    {user.open_tasks} tarea(s) abiertas
                                </span>
                            </label>
                        ))}
                    </div>
                    {target &&
                        target.missing_role !== 'accountant_in_charge' && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.primary}
                                    onChange={(event) =>
                                        form.setData(
                                            'primary',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Responsable principal de este rol (reemplaza al
                                principal actual y conserva el historial)
                            </label>
                        )}
                    {form.errors.user_id && (
                        <p className="text-sm text-red-700">
                            {form.errors.user_id}
                        </p>
                    )}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={!form.data.user_id || form.processing}
                        >
                            {form.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <CheckCircle2 className="size-4" />
                            )}{' '}
                            Confirmar asignación
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export type FormOptions = {
    roles: { code: string; name: string }[];
    assignment_roles: { code: string; name: string }[];
    companies: { id: number; name: string; office_id: number }[];
};

export function InviteDialog({
    open,
    onClose,
    scope,
    options,
}: {
    open: boolean;
    onClose: () => void;
    scope: OfficeScope;
    options: FormOptions;
}) {
    const defaultOffice = (
        scope.selected ??
        scope.offices[0]?.id ??
        ''
    ).toString();
    const form = useForm({
        name: '',
        email: '',
        office_id: defaultOffice,
        role_code:
            options.roles.find((role) => role.code === 'accounting_assistant')
                ?.code ??
            options.roles[0]?.code ??
            '',
        expires_in_days: '3',
        companies: [] as { company_id: string; assignment_role: string }[],
    });
    const officeCompanies = options.companies.filter(
        (company) => company.office_id.toString() === form.data.office_id,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/office/invitations', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[#f5e7e7] text-[#640000]">
                            <UserPlus className="size-5" />
                        </span>
                        <div>
                            <DialogTitle className="text-xl font-black">
                                Nuevo colaborador
                            </DialogTitle>
                            <DialogDescription className="mt-0.5">
                                Recibirá un correo con un enlace seguro. Al
                                aceptarlo quedará en la oficina con el rol y las
                                empresas que elijas.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="grid gap-4 text-slate-700 sm:grid-cols-2 [&_input]:text-[#142135] [&_input]:normal-case [&_label]:text-xs [&_label]:font-bold [&_label]:tracking-wide [&_label]:text-slate-600 [&_label]:uppercase [&_select]:text-[#142135] [&_select]:normal-case"
                >
                    <label className="text-sm font-semibold">
                        Nombre
                        <input
                            className={`${input} mt-1`}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                        />
                        {form.errors.name && (
                            <span className="text-xs text-red-700">
                                {form.errors.name}
                            </span>
                        )}
                    </label>
                    <label className="text-sm font-semibold">
                        Correo
                        <input
                            type="email"
                            className={`${input} mt-1`}
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            required
                        />
                        {form.errors.email && (
                            <span className="text-xs text-red-700">
                                {form.errors.email}
                            </span>
                        )}
                    </label>
                    <label className="text-sm font-semibold">
                        Oficina
                        <select
                            className={`${input} mt-1`}
                            value={form.data.office_id}
                            onChange={(event) =>
                                form.setData({
                                    ...form.data,
                                    office_id: event.target.value,
                                    companies: [],
                                })
                            }
                        >
                            {scope.offices.map((office) => (
                                <option key={office.id} value={office.id}>
                                    {office.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Rol inicial
                        <select
                            className={`${input} mt-1`}
                            value={form.data.role_code}
                            onChange={(event) =>
                                form.setData('role_code', event.target.value)
                            }
                        >
                            {options.roles.map((role) => (
                                <option key={role.code} value={role.code}>
                                    {role.name}
                                </option>
                            ))}
                        </select>
                        {form.errors.role_code && (
                            <span className="text-xs text-red-700">
                                {form.errors.role_code}
                            </span>
                        )}
                    </label>
                    <label className="text-sm font-semibold">
                        Vence en
                        <select
                            className={`${input} mt-1`}
                            value={form.data.expires_in_days}
                            onChange={(event) =>
                                form.setData(
                                    'expires_in_days',
                                    event.target.value,
                                )
                            }
                        >
                            {['1', '3', '7', '14'].map((days) => (
                                <option key={days} value={days}>
                                    {days} día(s)
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-black text-[#142135]">
                                    Empresas asignadas
                                </p>
                                <p className="text-xs text-slate-500">
                                    Opcional. Puedes asignarlas después.
                                </p>
                            </div>
                            <button
                                type="button"
                                className={secondaryButton}
                                disabled={officeCompanies.length === 0}
                                onClick={() =>
                                    form.setData('companies', [
                                        ...form.data.companies,
                                        {
                                            company_id:
                                                officeCompanies[0]?.id.toString() ??
                                                '',
                                            assignment_role:
                                                'accounting_assistant',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-3.5" /> Agregar
                            </button>
                        </div>
                        <div className="mt-2 space-y-2">
                            {form.data.companies.map((item, index) => (
                                <div
                                    key={index}
                                    className="grid grid-cols-[1fr_1fr_auto] gap-2"
                                >
                                    <select
                                        className={input}
                                        value={item.company_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'companies',
                                                form.data.companies.map(
                                                    (row, position) =>
                                                        position === index
                                                            ? {
                                                                  ...row,
                                                                  company_id:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : row,
                                                ),
                                            )
                                        }
                                    >
                                        {officeCompanies.map((company) => (
                                            <option
                                                key={company.id}
                                                value={company.id}
                                            >
                                                {company.name}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        className={input}
                                        value={item.assignment_role}
                                        onChange={(event) =>
                                            form.setData(
                                                'companies',
                                                form.data.companies.map(
                                                    (row, position) =>
                                                        position === index
                                                            ? {
                                                                  ...row,
                                                                  assignment_role:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : row,
                                                ),
                                            )
                                        }
                                    >
                                        {options.assignment_roles.map(
                                            (role) => (
                                                <option
                                                    key={role.code}
                                                    value={role.code}
                                                >
                                                    {role.name}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <button
                                        type="button"
                                        aria-label="Quitar"
                                        className={secondaryButton}
                                        onClick={() =>
                                            form.setData(
                                                'companies',
                                                form.data.companies.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                    <DialogFooter className="border-t border-slate-100 pt-4 sm:col-span-2">
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            {form.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <UserPlus className="size-4" />
                            )}{' '}
                            Enviar invitación
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function NewCompanyDialog({
    open,
    onClose,
    scope,
}: {
    open: boolean;
    onClose: () => void;
    scope: OfficeScope;
}) {
    const form = useForm({
        name: '',
        tax_id: '',
        email: '',
        office_id: (scope.selected ?? scope.offices[0]?.id ?? '').toString(),
        accountant_id: '',
        assistant_id: '',
    });
    const [candidates, setCandidates] = useState<{
        office: string;
        accountants: CompatibleUser[];
        assistants: CompatibleUser[];
    } | null>(null);

    const office = form.data.office_id;

    useEffect(() => {
        if (!open || !office) {
            return;
        }

        let cancelled = false;
        Promise.all(
            ['accountant_in_charge', 'accounting_assistant'].map((role) =>
                fetch(
                    `/office/assignable-users?office_id=${office}&role=${role}`,
                    { headers: { Accept: 'application/json' } },
                ).then(
                    (response) =>
                        response.json() as Promise<{ users: CompatibleUser[] }>,
                ),
            ),
        )
            .then(([accountants, assistants]) => {
                if (!cancelled) {
                    setCandidates({
                        office,
                        accountants: accountants.users ?? [],
                        assistants: assistants.users ?? [],
                    });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setCandidates({ office, accountants: [], assistants: [] });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open, office]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/office/companies', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Nuevo cliente</DialogTitle>
                    <DialogDescription>
                        La empresa a la que le llevarás la contabilidad. Se crea
                        con su catálogo contable inicial; los parámetros
                        fiscales y períodos los configura el equipo contable.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-semibold sm:col-span-2">
                        Razón social
                        <input
                            className={`${input} mt-1`}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                        />
                    </label>
                    <label className="text-sm font-semibold">
                        NIT
                        <input
                            className={`${input} mt-1`}
                            value={form.data.tax_id}
                            onChange={(event) =>
                                form.setData('tax_id', event.target.value)
                            }
                            required
                        />
                        {form.errors.tax_id && (
                            <span className="text-xs text-red-700">
                                {form.errors.tax_id}
                            </span>
                        )}
                    </label>
                    <label className="text-sm font-semibold">
                        Correo (opcional)
                        <input
                            type="email"
                            className={`${input} mt-1`}
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                        />
                    </label>
                    {scope.offices.length > 1 && (
                        <label className="text-sm font-semibold sm:col-span-2">
                            Oficina que lo atiende
                            <select
                                className={`${input} mt-1`}
                                value={form.data.office_id}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        office_id: event.target.value,
                                        accountant_id: '',
                                        assistant_id: '',
                                    })
                                }
                            >
                                {scope.offices.map((office) => (
                                    <option key={office.id} value={office.id}>
                                        {office.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <label className="text-sm font-semibold">
                        Contador encargado
                        <select
                            className={`${input} mt-1`}
                            value={form.data.accountant_id}
                            onChange={(event) =>
                                form.setData(
                                    'accountant_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Asignar después</option>
                            {candidates?.accountants.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Auxiliar contable
                        <select
                            className={`${input} mt-1`}
                            value={form.data.assistant_id}
                            onChange={(event) =>
                                form.setData('assistant_id', event.target.value)
                            }
                        >
                            <option value="">Asignar después</option>
                            {candidates?.assistants.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <DialogFooter className="sm:col-span-2">
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            {form.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Plus className="size-4" />
                            )}{' '}
                            Crear cliente
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
