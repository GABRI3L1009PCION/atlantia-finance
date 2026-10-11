import { router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Send } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import {
    input,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type SupervisorContext = {
    periods: {
        key: string;
        name: string;
        starts_on: string;
        ends_on: string;
    }[];
    period: {
        key: string;
        name: string;
        starts_on: string;
        ends_on: string;
    } | null;
    companies: {
        id: number;
        name: string;
        office_id: number;
        period_id: number | null;
    }[];
    team: { id: number; name: string; role: string; utilization: number }[];
    badges: Record<string, number>;
};

export type SupervisorPermissions = Record<string, boolean>;

export type SupervisedCompany = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    office_name: string;
    accountant: { id: number; name: string } | null;
    assistant: { id: number; name: string } | null;
    period_id: number | null;
    period_name: string | null;
    status: string;
    status_label: string;
    progress: number;
    accounting_progress: number;
    fiscal_label: string;
    fiscal_tone: string;
    documents_done: number;
    documents_total: number;
    open_tasks: number;
    overdue: boolean;
    blocked: boolean;
    due_at: string | null;
    destination: string;
    expediente: string;
};

/** Visual code of the supervision states: green done, blue in progress, amber in review, red pending/late, violet correction. */
export const statusTone: Record<string, Tone | 'violet'> = {
    closed: 'green',
    ready_to_close: 'green',
    approved: 'green',
    fiscal_pending: 'amber',
    in_progress: 'blue',
    ready_for_review: 'amber',
    in_review: 'amber',
    first_level_approved: 'amber',
    changes_requested: 'violet',
    not_started: 'red',
};

export function toneBadge(tone: string): string {
    const styles: Record<string, string> = {
        green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        amber: 'bg-amber-50 text-amber-700 ring-amber-200',
        red: 'bg-red-50 text-red-700 ring-red-200',
        blue: 'bg-blue-50 text-blue-700 ring-blue-200',
        violet: 'bg-violet-50 text-violet-700 ring-violet-200',
        slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    };

    return `inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${styles[tone] ?? styles.slate}`;
}

export function money(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) {
        return '—';
    }

    return `Q ${new Intl.NumberFormat('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100)}`;
}

/** Keeps period and filters when moving between supervisor screens. */
export function useSupervisorHref(): (href: string) => string {
    const page = usePage();
    const period = new URL(page.url, 'https://atlantia.local').searchParams.get(
        'period',
    );

    return (href: string) =>
        period
            ? `${href}${href.includes('?') ? '&' : '?'}period=${encodeURIComponent(period)}`
            : href;
}

export function Card({
    title,
    action,
    children,
    className = '',
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={`${panel} min-w-0 p-3 ${className}`}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-black text-[#061633]">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

export function Table({
    headers,
    children,
    empty,
    colSpan,
}: {
    headers: string[];
    children: ReactNode;
    empty?: string | false;
    colSpan?: number;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs">
                <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                    <tr>
                        {headers.map((header) => (
                            <th
                                key={header}
                                className="px-2 py-2 whitespace-nowrap"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {children}
                    {empty && (
                        <tr>
                            <td
                                colSpan={colSpan ?? headers.length}
                                className="px-3 py-8 text-center text-sm text-slate-500"
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

export function ProgressBar({ value }: { value: number }) {
    return (
        <div className="flex items-center gap-1.5">
            <div className="h-2 w-16 overflow-hidden rounded bg-slate-100">
                <div
                    className={`h-full ${value >= 100 ? 'bg-emerald-500' : value >= 60 ? 'bg-blue-500' : value >= 30 ? 'bg-amber-400' : 'bg-red-500'}`}
                    style={{ width: `${Math.min(100, value)}%` }}
                />
            </div>
            <span className="w-9 font-black">{value}%</span>
        </div>
    );
}

/** Work assignment form: company, period, responsible, type, priority, dates and notes. */
export function AssignWorkDialog({
    open,
    onClose,
    context,
    workTypes,
    defaultCompanyId,
}: {
    open: boolean;
    onClose: () => void;
    context: SupervisorContext;
    workTypes: Record<string, string>;
    defaultCompanyId?: number;
}) {
    const first =
        context.companies.find((company) => company.id === defaultCompanyId) ??
        context.companies[0];
    const form = useForm({
        company_id: first?.id.toString() ?? '',
        accounting_period_id: first?.period_id?.toString() ?? '',
        assigned_to: '',
        work_type: 'accounting',
        priority: 'normal',
        starts_on: new Date().toISOString().slice(0, 10),
        due_at: '',
        description: '',
        observations: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/supervisor/assignments', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('description', 'observations', 'due_at');
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Asignar trabajo</DialogTitle>
                    <DialogDescription>
                        Se crea la tarea, se notifica al responsable, se
                        registra en auditoría y se actualiza su carga.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-semibold">
                        Empresa
                        <select
                            className={`${input} mt-1`}
                            value={form.data.company_id}
                            onChange={(event) => {
                                const company = context.companies.find(
                                    (item) =>
                                        item.id.toString() ===
                                        event.target.value,
                                );
                                form.setData({
                                    ...form.data,
                                    company_id: event.target.value,
                                    accounting_period_id:
                                        company?.period_id?.toString() ?? '',
                                });
                            }}
                        >
                            {context.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Responsable
                        <select
                            className={`${input} mt-1`}
                            value={form.data.assigned_to}
                            onChange={(event) =>
                                form.setData('assigned_to', event.target.value)
                            }
                            required
                        >
                            <option value="">Elegir</option>
                            {context.team.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name} · {member.role} ·{' '}
                                    {member.utilization}%
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Tipo de trabajo
                        <select
                            className={`${input} mt-1`}
                            value={form.data.work_type}
                            onChange={(event) =>
                                form.setData('work_type', event.target.value)
                            }
                        >
                            {Object.entries(workTypes).map(([code, label]) => (
                                <option key={code} value={code}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Prioridad
                        <select
                            className={`${input} mt-1`}
                            value={form.data.priority}
                            onChange={(event) =>
                                form.setData('priority', event.target.value)
                            }
                        >
                            <option value="low">Baja</option>
                            <option value="normal">Media</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Fecha de inicio
                        <input
                            type="date"
                            className={`${input} mt-1`}
                            value={form.data.starts_on}
                            onChange={(event) =>
                                form.setData('starts_on', event.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm font-semibold">
                        Fecha límite
                        <input
                            type="date"
                            className={`${input} mt-1`}
                            value={form.data.due_at}
                            onChange={(event) =>
                                form.setData('due_at', event.target.value)
                            }
                            required
                        />
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Descripción
                        <textarea
                            className={`${input} mt-1 h-16 py-2`}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Observaciones
                        <textarea
                            className={`${input} mt-1 h-16 py-2`}
                            value={form.data.observations}
                            onChange={(event) =>
                                form.setData('observations', event.target.value)
                            }
                        />
                    </label>
                    {Object.values(form.errors).length > 0 && (
                        <p className="text-sm text-red-700 sm:col-span-2">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
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
                                <Send className="size-4" />
                            )}{' '}
                            Asignar trabajo
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Asks for a mandatory observation before sending a review back for correction. */
export function ObservationDialog({
    open,
    title,
    onClose,
    onSubmit,
}: {
    open: boolean;
    title: string;
    onClose: () => void;
    onSubmit: (message: string) => void;
}) {
    const form = useForm({ message: '' });

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        La observación es obligatoria y queda en el historial.
                        Ej.: “Factura FEL 445 pertenece a agosto y debe
                        reclasificarse.”
                    </DialogDescription>
                </DialogHeader>
                <textarea
                    className={`${input} h-28 py-2`}
                    value={form.data.message}
                    onChange={(event) =>
                        form.setData('message', event.target.value)
                    }
                />
                <DialogFooter>
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={onClose}
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        className={primaryButton}
                        disabled={form.data.message.trim().length < 5}
                        onClick={() => {
                            onSubmit(form.data.message);
                            form.reset();
                        }}
                    >
                        Enviar corrección
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function post(
    url: string,
    data: Record<string, string | number | null> = {},
    onSuccess?: () => void,
): void {
    router.post(url, data, { preserveScroll: true, onSuccess });
}
