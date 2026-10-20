import { Head, Link, router, useForm } from '@inertiajs/react';
import { Loader2, Plus, UserRoundCog } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    Pager,
    PrimaryActionButton,
    ProgressBar,
    SearchBox,
    Stat,
    priorityTone,
    selectClass,
    taskTone,
    useServerFilters,
} from '@/components/essential/kit';
import type { PrimaryAction } from '@/components/essential/kit';
import type { EssentialContext, Paged } from '@/components/essential/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Task = {
    id: number;
    title: string;
    description: string | null;
    module: string;
    status: string;
    status_label: string;
    priority: { key: string; label: string; reason: string | null };
    period: string | null;
    due_at: string | null;
    overdue: boolean;
    days_overdue: number;
    company: string | null;
    assignee: string | null;
    assigned_to: number | null;
    reviewer: string | null;
    source: string;
    progress: number | null;
    blockers_count: number;
    readiness_error: string | null;
    primary_action: PrimaryAction;
    href: string;
};

type Props = {
    essential: EssentialContext;
    tasks: {
        rows: Task[];
        counts: {
            open: number;
            overdue: number;
            review: number;
            unassigned: number;
        };
    } & Paged;
    form: {
        office_id: number;
        companies: { id: number; name: string }[];
        people: { id: number; name: string; role: string }[];
        modules: { code: string; name: string }[];
    };
    filters: Record<string, string>;
};

export default function EssentialTasks({
    essential,
    tasks,
    form,
    filters,
}: Props) {
    const apply = useServerFilters('/essential/tasks');
    const [creating, setCreating] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('new') === '1',
    );
    const [reassigning, setReassigning] = useState<Task | null>(null);
    const status = filters.status ?? 'open';

    return (
        <>
            <Head title={essential.owner ? 'Tareas' : 'Mis tareas'} />
            <Shell>
                <PageHeader
                    title={essential.owner ? 'Tareas' : 'Mis tareas'}
                    subtitle="Aquí se coordina el trabajo. Iniciar solo asume la tarea: se trabaja en su módulo y se envía a revisión cuando Atlantia valida que está completa"
                    actions={
                        <button
                            type="button"
                            className={primaryButton}
                            onClick={() => setCreating(true)}
                        >
                            <Plus className="size-4" /> Nueva tarea
                        </button>
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        label="Abiertas"
                        value={tasks.counts.open}
                        href="/essential/tasks"
                    />
                    <Stat
                        label="Vencidas"
                        value={tasks.counts.overdue}
                        tone={tasks.counts.overdue > 0 ? 'red' : 'slate'}
                        href="/essential/tasks?status=overdue"
                    />
                    <Stat
                        label="Para revisión"
                        value={tasks.counts.review}
                        tone={tasks.counts.review > 0 ? 'amber' : 'slate'}
                        href="/essential/tasks?status=review"
                    />
                    <Stat
                        label="Sin responsable"
                        value={tasks.counts.unassigned}
                        tone={tasks.counts.unassigned > 0 ? 'amber' : 'slate'}
                    />
                </section>

                <Card
                    title={`${tasks.total} tarea(s)`}
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <SearchBox
                                value={filters.search ?? ''}
                                placeholder="Buscar tarea…"
                                onSubmit={(value) =>
                                    apply({ search: value || null })
                                }
                            />
                            <select
                                aria-label="Estado"
                                className={selectClass}
                                value={status}
                                onChange={(event) =>
                                    apply({ status: event.target.value })
                                }
                            >
                                <option value="open">Abiertas</option>
                                <option value="pending">Pendientes</option>
                                <option value="waiting">Esperando</option>
                                <option value="in_progress">En proceso</option>
                                <option value="review">Para revisión</option>
                                <option value="changes_requested">
                                    Cambios solicitados
                                </option>
                                <option value="blocked">Bloqueadas</option>
                                <option value="overdue">Vencidas</option>
                                <option value="closed">Cerradas</option>
                                <option value="all">Todas</option>
                            </select>
                            <select
                                aria-label="Cliente"
                                className={selectClass}
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    apply({
                                        company_id: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Todos los clientes</option>
                                {form.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            {essential.owner && (
                                <select
                                    aria-label="Responsable"
                                    className={selectClass}
                                    value={filters.assigned_to ?? ''}
                                    onChange={(event) =>
                                        apply({
                                            assigned_to:
                                                event.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">Todos</option>
                                    <option value="none">
                                        Sin responsable
                                    </option>
                                    {form.people.map((person) => (
                                        <option
                                            key={person.id}
                                            value={person.id}
                                        >
                                            {person.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Tarea',
                            'Cliente',
                            'Período',
                            'Vence',
                            'Responsable',
                            'Estado',
                            'Progreso',
                            'Prioridad',
                            'Bloqueos',
                            'Acción',
                        ]}
                        empty={
                            tasks.rows.length === 0 &&
                            'No tienes tareas con estos filtros.'
                        }
                    >
                        {tasks.rows.map((task) => (
                            <tr key={task.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={task.href}
                                        className="font-bold hover:text-[#640000]"
                                    >
                                        {task.title}
                                    </Link>
                                    <p className="text-[11px] text-slate-500">
                                        {task.source}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {task.company ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {task.period ?? '—'}
                                </td>
                                <td
                                    className={`px-2 py-2 whitespace-nowrap ${task.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(task.due_at)}
                                    {task.overdue && (
                                        <p className="text-[11px] font-normal">
                                            Vencida hace {task.days_overdue} d
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {task.assignee ?? (
                                        <span className="text-amber-700">
                                            Sin asignar
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            taskTone[task.status] ?? 'slate',
                                        )}
                                    >
                                        {task.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {task.readiness_error ? (
                                        <span className="text-[11px] text-red-700">
                                            No fue posible validar
                                        </span>
                                    ) : (
                                        <ProgressBar value={task.progress} />
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            priorityTone[task.priority.key] ??
                                                'slate',
                                        )}
                                    >
                                        {task.priority.label}
                                    </span>
                                    {task.priority.reason && (
                                        <p className="mt-1 max-w-36 text-[11px] text-slate-500">
                                            {task.priority.reason}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {task.blockers_count > 0 ? (
                                        <span className={toneBadge('red')}>
                                            {task.blockers_count}
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex items-center gap-1">
                                        <PrimaryActionButton
                                            action={task.primary_action}
                                            startUrl={`/essential/tasks/${task.id}/start`}
                                        />
                                        {essential.owner &&
                                            ![
                                                'completed',
                                                'cancelled',
                                            ].includes(task.status) && (
                                                <button
                                                    type="button"
                                                    aria-label="Reasignar"
                                                    title="Reasignar"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        setReassigning(task)
                                                    }
                                                >
                                                    <UserRoundCog className="size-3.5" />
                                                </button>
                                            )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <Pager paged={tasks} onPage={(page) => apply({ page })} />
                </Card>
            </Shell>

            <NewTaskDialog
                open={creating}
                onClose={() => setCreating(false)}
                form={form}
                owner={essential.owner}
            />
            <ReassignDialog
                task={reassigning}
                people={form.people}
                onClose={() => setReassigning(null)}
            />
        </>
    );
}
function NewTaskDialog({
    open,
    onClose,
    form: options,
    owner,
}: {
    open: boolean;
    onClose: () => void;
    form: Props['form'];
    owner: boolean;
}) {
    const form = useForm({
        office_id: options.office_id,
        title: '',
        description: '',
        company_id: '',
        module: options.modules[0]?.code ?? 'general',
        task_type: 'manual',
        assigned_to: '',
        reviewer_id: '',
        priority: 'normal',
        due_at: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/work/tasks', {
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
                    <DialogTitle>Nueva tarea</DialogTitle>
                    <DialogDescription>
                        La persona responsable recibe una notificación y queda
                        registro de cada cambio.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-semibold sm:col-span-2">
                        Título
                        <input
                            className={`${input} mt-1`}
                            value={form.data.title}
                            maxLength={180}
                            required
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        {form.errors.title && (
                            <span className="text-xs text-red-700">
                                {form.errors.title}
                            </span>
                        )}
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Descripción (opcional)
                        <textarea
                            className={`${input} mt-1 h-20 py-2`}
                            value={form.data.description}
                            maxLength={1000}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm font-semibold">
                        Cliente
                        <select
                            className={`${input} mt-1`}
                            value={form.data.company_id}
                            onChange={(event) =>
                                form.setData('company_id', event.target.value)
                            }
                        >
                            <option value="">Sin cliente (interna)</option>
                            {options.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Área
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
                    <label className="text-sm font-semibold">
                        Responsable
                        <select
                            className={`${input} mt-1`}
                            value={form.data.assigned_to}
                            onChange={(event) =>
                                form.setData('assigned_to', event.target.value)
                            }
                        >
                            <option value="">Asignar después</option>
                            {options.people.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name} · {person.role}
                                </option>
                            ))}
                        </select>
                    </label>
                    {owner && (
                        <label className="text-sm font-semibold">
                            Revisa
                            <select
                                className={`${input} mt-1`}
                                value={form.data.reviewer_id}
                                onChange={(event) =>
                                    form.setData(
                                        'reviewer_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Sin revisor</option>
                                {options.people.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
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
                        Vence
                        <input
                            type="date"
                            className={`${input} mt-1`}
                            value={form.data.due_at}
                            onChange={(event) =>
                                form.setData('due_at', event.target.value)
                            }
                        />
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
                            Crear tarea
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ReassignDialog({
    task,
    people,
    onClose,
}: {
    task: Task | null;
    people: Props['form']['people'];
    onClose: () => void;
}) {
    const [choice, setChoice] = useState<{ id: number; value: string } | null>(
        null,
    );
    const value =
        choice && task && choice.id === task.id
            ? choice.value
            : (task?.assigned_to?.toString() ?? '');

    return (
        <Dialog
            open={task !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reasignar tarea</DialogTitle>
                    <DialogDescription>{task?.title}</DialogDescription>
                </DialogHeader>
                <select
                    className={input}
                    value={value}
                    onChange={(event) =>
                        task &&
                        setChoice({ id: task.id, value: event.target.value })
                    }
                >
                    <option value="">Sin responsable</option>
                    {people.map((person) => (
                        <option key={person.id} value={person.id}>
                            {person.name} · {person.role}
                        </option>
                    ))}
                </select>
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
                        onClick={() =>
                            task &&
                            router.post(
                                `/work/tasks/${task.id}/reassign`,
                                { assigned_to: value || null },
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                    >
                        Guardar
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
