import { router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Plus, Save } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    input,
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

export type Context = {
    scope: {
        global: boolean;
        offices: { id: number; name: string }[];
        selected: number | null;
    };
    periods: {
        key: string;
        name: string;
        starts_on: string;
        ends_on: string;
    }[];
    period: { key: string; name: string } | null;
};

export type TeamUser = {
    id: number;
    name: string;
    office_ids: number[];
    role: string | null;
    open_tasks: number;
};

export type TaskOptions = {
    companies: {
        id: number;
        name: string;
        office_id: number;
        period_id: number | null;
    }[];
    users: TeamUser[];
    modules: { code: string; name: string }[];
    incident_types: { code: string; name: string }[];
};

export type TaskRow = {
    id: number;
    office_id: number;
    company_id: number | null;
    company_name: string | null;
    period_name: string | null;
    module: string;
    module_label: string;
    title: string;
    priority: string;
    status: string;
    status_label: string;
    due_at: string | null;
    overdue_days: number;
    remaining_days: number | null;
    assigned_to: number | null;
    assignee_name: string | null;
    reviewer_id: number | null;
    reviewer_name: string | null;
    source: string;
    destination: string;
    module_destination: string | null;
};

export const companyStatusTone: Record<string, Tone> = {
    not_started: 'slate',
    in_progress: 'blue',
    ready_for_review: 'green',
    in_review: 'blue',
    changes_requested: 'amber',
    approved: 'green',
    ready_to_close: 'green',
    closed: 'slate',
};

export const taskStatusTone: Record<string, Tone> = {
    pending: 'slate',
    in_progress: 'blue',
    ready_for_review: 'green',
    in_review: 'blue',
    changes_requested: 'amber',
    approved: 'green',
    completed: 'green',
    blocked: 'red',
    cancelled: 'slate',
};

export const severityLabel: Record<string, [string, Tone]> = {
    low: ['Baja', 'green'],
    medium: ['Media', 'amber'],
    high: ['Alta', 'red'],
    critical: ['Crítica', 'red'],
};

export const incidentStatusLabel: Record<string, [string, Tone]> = {
    open: ['Abierta', 'amber'],
    in_progress: ['En proceso', 'blue'],
    blocked: ['Bloqueada', 'red'],
    resolved: ['Resuelta', 'green'],
    dismissed: ['Descartada', 'slate'],
};

/** Keeps office and period filters on every operations link. */
export function useScopedHref(): (href: string) => string {
    const page = usePage();
    const url = new URL(page.url, 'https://atlantia.local');
    const officeId = url.searchParams.get('office_id');
    const period = url.searchParams.get('period');

    return (href: string) => {
        if (!officeId && !period) {
            return href;
        }

        const [path, hash] = href.split('#');
        const params = new URLSearchParams();

        if (officeId) {
            params.set('office_id', officeId);
        }

        if (period) {
            params.set('period', period);
        }

        return `${path}${path.includes('?') ? '&' : '?'}${params.toString()}${hash ? `#${hash}` : ''}`;
    };
}

export function dueLabel(
    task: Pick<TaskRow, 'overdue_days' | 'remaining_days'>,
): [string, Tone] {
    if (task.overdue_days > 0) {
        return [`${task.overdue_days} día(s) vencida`, 'red'];
    }

    if (task.remaining_days === null) {
        return ['Sin fecha', 'slate'];
    }

    if (task.remaining_days === 0) {
        return ['Hoy', 'red'];
    }

    return [
        `${task.remaining_days} día(s)`,
        task.remaining_days <= 1 ? 'amber' : 'green',
    ];
}

export function NewTaskDialog({
    open,
    onClose,
    options,
    defaultCompanyId,
}: {
    open: boolean;
    onClose: () => void;
    options: TaskOptions;
    defaultCompanyId?: number;
}) {
    const first =
        options.companies.find((company) => company.id === defaultCompanyId) ??
        options.companies[0];
    const form = useForm({
        company_id: first?.id.toString() ?? '',
        accounting_period_id: first?.period_id?.toString() ?? '',
        module: 'accounting',
        title: '',
        description: '',
        assigned_to: '',
        reviewer_id: '',
        priority: 'normal',
        due_at: '',
    });
    const company = options.companies.find(
        (item) => item.id.toString() === form.data.company_id,
    );
    const people = options.users.filter(
        (user) => company && user.office_ids.includes(company.office_id),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/operations/tasks', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('title', 'description', 'due_at');
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Nueva tarea</DialogTitle>
                    <DialogDescription>
                        El responsable la recibe en Mi trabajo con una
                        notificación. Solo aparecen colaboradores activos de la
                        oficina de la empresa.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-semibold">
                        Empresa
                        <select
                            className={`${input} mt-1`}
                            value={form.data.company_id}
                            onChange={(event) => {
                                const selected = options.companies.find(
                                    (item) =>
                                        item.id.toString() ===
                                        event.target.value,
                                );
                                form.setData({
                                    ...form.data,
                                    company_id: event.target.value,
                                    accounting_period_id:
                                        selected?.period_id?.toString() ?? '',
                                    assigned_to: '',
                                    reviewer_id: '',
                                });
                            }}
                        >
                            {options.companies.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Módulo
                        <select
                            className={`${input} mt-1`}
                            value={form.data.module}
                            onChange={(event) =>
                                form.setData('module', event.target.value)
                            }
                        >
                            {options.modules.map((module) => (
                                <option key={module.code} value={module.code}>
                                    {module.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Tarea
                        <input
                            className={`${input} mt-1`}
                            placeholder="Ej. Conciliar Banco Industrial"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                        {form.errors.title && (
                            <span className="text-xs text-red-700">
                                {form.errors.title}
                            </span>
                        )}
                    </label>
                    <label className="text-sm font-semibold">
                        Responsable
                        <select
                            className={`${input} mt-1`}
                            value={form.data.assigned_to}
                            onChange={(event) =>
                                form.setData('assigned_to', event.target.value)
                            }
                        >
                            <option value="">Sin asignar</option>
                            {people.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name} · {user.open_tasks} abiertas
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Revisor
                        <select
                            className={`${input} mt-1`}
                            value={form.data.reviewer_id}
                            onChange={(event) =>
                                form.setData('reviewer_id', event.target.value)
                            }
                        >
                            <option value="">Sin revisor</option>
                            {people.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
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
                            <option value="normal">Normal</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Vencimiento
                        <input
                            type="datetime-local"
                            className={`${input} mt-1`}
                            value={form.data.due_at}
                            onChange={(event) =>
                                form.setData('due_at', event.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Descripción
                        <textarea
                            className={`${input} mt-1 h-20 py-2`}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
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
                            disabled={form.processing || !form.data.company_id}
                        >
                            {form.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Plus className="size-4" />
                            )}{' '}
                            Crear tarea
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Reassign, reprioritize or reschedule a task; every change is audited and the assignee notified. */
export function TaskManageDialog({
    task,
    users,
    permissions,
    onClose,
}: {
    task: TaskRow | null;
    users: TeamUser[];
    permissions: Record<string, boolean>;
    onClose: () => void;
}) {
    const [assignee, setAssignee] = useState('');
    const [reason, setReason] = useState('');
    const [priority, setPriority] = useState('');
    const [dueAt, setDueAt] = useState('');
    const [key, setKey] = useState<number | null>(null);

    if (task && key !== task.id) {
        setKey(task.id);
        setAssignee(task.assigned_to?.toString() ?? '');
        setPriority(task.priority);
        setDueAt(task.due_at ? task.due_at.slice(0, 16).replace(' ', 'T') : '');
        setReason('');
    }

    const people = users.filter(
        (user) => task && user.office_ids.includes(task.office_id),
    );
    const post = (url: string, data: Record<string, string | null>) =>
        router.post(url, data, { preserveScroll: true, onSuccess: onClose });

    return (
        <Dialog
            open={task !== null}
            onOpenChange={(value) => !value && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Gestionar tarea</DialogTitle>
                    <DialogDescription>
                        {task?.title} · {task?.company_name ?? 'Sin empresa'}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    {permissions.canReassignTasks && (
                        <div className="space-y-1.5">
                            <p className="text-sm font-bold">Responsable</p>
                            <select
                                className={input}
                                value={assignee}
                                onChange={(event) =>
                                    setAssignee(event.target.value)
                                }
                            >
                                <option value="">Sin asignar</option>
                                {people.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name} · {user.open_tasks} abiertas
                                    </option>
                                ))}
                            </select>
                            <input
                                className={input}
                                placeholder="Motivo del cambio (queda en auditoría)"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                            <button
                                type="button"
                                className={secondaryButton}
                                disabled={
                                    assignee ===
                                    (task?.assigned_to?.toString() ?? '')
                                }
                                onClick={() =>
                                    task &&
                                    post(
                                        `/operations/tasks/${task.id}/reassign`,
                                        {
                                            assigned_to: assignee || null,
                                            reason,
                                        },
                                    )
                                }
                            >
                                <Save className="size-3.5" /> Reasignar
                            </button>
                        </div>
                    )}
                    {permissions.canPrioritize && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <p className="text-sm font-bold">Prioridad</p>
                                <select
                                    className={input}
                                    value={priority}
                                    onChange={(event) =>
                                        setPriority(event.target.value)
                                    }
                                >
                                    <option value="low">Baja</option>
                                    <option value="normal">Normal</option>
                                    <option value="high">Alta</option>
                                    <option value="urgent">Urgente</option>
                                </select>
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    disabled={priority === task?.priority}
                                    onClick={() =>
                                        task &&
                                        post(
                                            `/operations/tasks/${task.id}/priority`,
                                            { priority },
                                        )
                                    }
                                >
                                    Guardar prioridad
                                </button>
                            </div>
                            <div className="space-y-1.5">
                                <p className="text-sm font-bold">Vencimiento</p>
                                <input
                                    type="datetime-local"
                                    className={input}
                                    value={dueAt}
                                    onChange={(event) =>
                                        setDueAt(event.target.value)
                                    }
                                />
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    onClick={() =>
                                        task &&
                                        post(
                                            `/operations/tasks/${task.id}/due-date`,
                                            { due_at: dueAt || null, reason },
                                        )
                                    }
                                >
                                    Guardar fecha
                                </button>
                            </div>
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <a href={task?.destination} className={secondaryButton}>
                        Abrir tarea
                    </a>
                    {task?.module_destination && (
                        <a
                            href={task.module_destination}
                            className={primaryButton}
                        >
                            Continuar en el módulo
                        </a>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function NewIncidentDialog({
    open,
    onClose,
    options,
    defaultCompanyId,
}: {
    open: boolean;
    onClose: () => void;
    options: TaskOptions;
    defaultCompanyId?: number;
}) {
    const form = useForm({
        company_id: (
            defaultCompanyId ??
            options.companies[0]?.id ??
            ''
        ).toString(),
        type: 'document',
        severity: 'medium',
        title: '',
        description: '',
        responsible_id: '',
        blocks_period: false,
        due_at: '',
    });
    const company = options.companies.find(
        (item) => item.id.toString() === form.data.company_id,
    );
    const people = options.users.filter(
        (user) => company && user.office_ids.includes(company.office_id),
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/operations/incidents', {
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
                    <DialogTitle>Registrar incidencia</DialogTitle>
                    <DialogDescription>
                        Las incidencias críticas o que bloquean el período
                        marcan la empresa como bloqueada hasta resolverse.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-semibold">
                        Empresa
                        <select
                            className={`${input} mt-1`}
                            value={form.data.company_id}
                            onChange={(event) =>
                                form.setData({
                                    ...form.data,
                                    company_id: event.target.value,
                                    responsible_id: '',
                                })
                            }
                        >
                            {options.companies.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Tipo
                        <select
                            className={`${input} mt-1`}
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                        >
                            {options.incident_types.map((type) => (
                                <option key={type.code} value={type.code}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Descripción corta
                        <input
                            className={`${input} mt-1`}
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </label>
                    <label className="text-sm font-semibold">
                        Severidad
                        <select
                            className={`${input} mt-1`}
                            value={form.data.severity}
                            onChange={(event) =>
                                form.setData('severity', event.target.value)
                            }
                        >
                            <option value="low">Baja</option>
                            <option value="medium">Media</option>
                            <option value="high">Alta</option>
                            <option value="critical">Crítica</option>
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Responsable
                        <select
                            className={`${input} mt-1`}
                            value={form.data.responsible_id}
                            onChange={(event) =>
                                form.setData(
                                    'responsible_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Sin asignar</option>
                            {people.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Detalle
                        <textarea
                            className={`${input} mt-1 h-20 py-2`}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </label>
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={form.data.blocks_period}
                            onChange={(event) =>
                                form.setData(
                                    'blocks_period',
                                    event.target.checked,
                                )
                            }
                        />
                        Bloquea el avance o cierre del período
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
                            Registrar
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
