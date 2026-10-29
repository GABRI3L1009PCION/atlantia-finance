import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ProgressBar, taskTone } from '@/components/essential/kit';
import {
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Action = {
    key: string;
    label: string;
    enabled: boolean;
    reason: string | null;
    needs_note: boolean;
    primary: boolean;
    wait_id?: number;
};

type Wait = {
    id: number;
    kind: string;
    status: string;
    reason: string;
    expected_on: string | null;
    overdue: boolean;
    via_portal: boolean;
    requested_by: string | null;
    resolution: string | null;
    file_name: string | null;
    created_at: string;
    resolved_at: string | null;
};

type Relation = {
    id: number;
    type: string;
    type_label: string;
    direction: string;
    task_id: number;
    title: string;
    status: string;
    finished: boolean;
    blocks_readiness: boolean;
};

type Props = {
    detail: {
        task: {
            id: number;
            title: string;
            description: string | null;
            status: string;
            status_label: string;
            priority: string;
            due_at: string | null;
            company: { name: string; public_id: string } | null;
            assignee: string | null;
            reviewer: string | null;
            created_by: string | null;
            started_at: string | null;
            completed_at: string | null;
            waiting_since: string | null;
            waiting_reason: string | null;
            blocked_reason: string | null;
            source_type: string | null;
            source_href: string | null;
        };
        source: {
            label: string;
            destination: string;
            status_label: string;
            progress: number;
            ready: boolean;
            pending: string[];
            blockers: string[];
            mirrored: boolean;
            result: string | null;
            error: string | null;
        } | null;
        readiness: { ready: boolean; pending: string[] };
        waits: Wait[];
        dependencies: Relation[];
        linkable: { id: number; title: string }[];
        can_link: boolean;
        comments: {
            kind: string;
            message: string;
            author: string;
            at: string;
        }[];
        history: { action: string; actor: string; at: string }[];
        actions: Action[];
    };
};

const historyLabels: Record<string, string> = {
    'task.created': 'Tarea creada',
    'task.in_progress': 'Iniciada o reanudada',
    'task.ready_for_review': 'Enviada a revisión',
    'task.in_review': 'Revisión iniciada',
    'task.changes_requested': 'Cambios solicitados',
    'task.approved': 'Aprobada',
    'task.completed': 'Completada',
    'task.blocked': 'Bloqueada',
    'task.cancelled': 'Cancelada',
    'task.reassigned': 'Reasignada',
    'task.priority_changed': 'Prioridad cambiada',
    'task.due_date_changed': 'Vencimiento cambiado',
    'task.waiting_client': 'En espera del cliente',
    'task.waiting_external': 'En espera de un tercero',
    'task.wait_opened': 'Espera registrada',
    'task.wait_resolved': 'Espera resuelta',
    'task.synced': 'Alineada con su origen',
    'task.dependency_added': 'Relación agregada',
    'task.dependency_removed': 'Relación quitada',
};

export default function EssentialTask({ detail }: Props) {
    const { task, source, readiness, actions } = detail;
    const [asking, setAsking] = useState<Action | null>(null);
    const noteForm = useForm({ note: '' });
    const linkForm = useForm({ depends_on: '', type: 'REQUIRES' });
    const post = (action: Action) =>
        router.post(
            `/essential/tasks/${task.id}/${action.key}`,
            {},
            { preserveScroll: true },
        );
    const addNote = (event: FormEvent) => {
        event.preventDefault();
        noteForm.post(`/essential/tasks/${task.id}/note`, {
            preserveScroll: true,
            onSuccess: () => noteForm.reset(),
        });
    };
    const canNote = actions.some((action) => action.key === 'note');
    const footer = actions.filter((action) => action.key !== 'note');

    return (
        <>
            <Head title={task.title} />
            <Shell>
                <Link
                    href="/essential/tasks"
                    className="inline-flex items-center gap-1 text-sm font-semibold text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Tareas
                </Link>
                <header className="rounded-xl border border-[#e7e1dd] bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-black text-[#061633]">
                                {task.title}
                            </h1>
                            <p className="text-sm text-[#526b92]">
                                {task.company?.name ?? 'Tarea interna'}
                            </p>
                        </div>
                        <span
                            className={toneBadge(
                                taskTone[task.status] ?? 'slate',
                            )}
                        >
                            {task.status_label}
                        </span>
                    </div>
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-4">
                        <Item label="Responsable" value={task.assignee} />
                        <Item label="Revisa" value={task.reviewer} />
                        <Item label="Vence" value={formatDate(task.due_at)} />
                        <Item
                            label="Iniciada"
                            value={
                                task.started_at
                                    ? formatDate(task.started_at, true)
                                    : 'Sin iniciar'
                            }
                        />
                    </dl>
                    {task.description && (
                        <p className="mt-4 rounded-lg bg-slate-50 p-3 text-sm whitespace-pre-line">
                            {task.description}
                        </p>
                    )}
                </header>

                {task.waiting_since && (
                    <p className="rounded-xl border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900">
                        <strong>
                            En espera desde {formatDate(task.waiting_since)}:
                        </strong>{' '}
                        {task.waiting_reason}. Esperar no cuenta como atraso de
                        quien trabaja la tarea.
                    </p>
                )}
                {task.blocked_reason && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                        <strong>Bloqueada:</strong> {task.blocked_reason}
                    </p>
                )}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="space-y-4">
                        {source && (
                            <Card title={`Origen · ${source.label}`}>
                                {source.error ? (
                                    <p className="text-sm font-semibold text-red-700">
                                        {source.error}
                                    </p>
                                ) : (
                                    <>
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <span className={toneBadge('blue')}>
                                                {source.status_label}
                                            </span>
                                            <ProgressBar
                                                value={source.progress}
                                            />
                                        </div>
                                        {source.result && (
                                            <p className="mt-3 text-sm font-semibold text-emerald-700">
                                                {source.result}
                                            </p>
                                        )}
                                        {source.pending.length > 0 && (
                                            <>
                                                <p className="mt-3 text-sm font-semibold">
                                                    Falta:
                                                </p>
                                                <ul className="list-disc space-y-1 pl-5 text-sm">
                                                    {source.pending.map(
                                                        (item) => (
                                                            <li key={item}>
                                                                {item}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </>
                                        )}
                                        {source.blockers.length > 0 && (
                                            <p className="mt-3 text-sm text-red-700">
                                                Bloqueos:{' '}
                                                {source.blockers.join('; ')}
                                            </p>
                                        )}
                                        <p className="mt-3 text-xs text-slate-500">
                                            {source.mirrored
                                                ? 'Esta tarea refleja el flujo de su módulo: se revisa y se aprueba allí.'
                                                : 'La tarea se cierra sola cuando el módulo muestra el resultado real.'}
                                        </p>
                                        <Link
                                            href={source.destination}
                                            className={`${primaryButton} mt-3`}
                                        >
                                            Continuar en el módulo
                                        </Link>
                                    </>
                                )}
                            </Card>
                        )}

                        {detail.waits.length > 0 && (
                            <Card title="Esperas">
                                <ul className="space-y-3">
                                    {detail.waits.map((wait) => (
                                        <li
                                            key={wait.id}
                                            className="rounded-lg border border-slate-200 p-3 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <strong>
                                                    {wait.kind === 'client'
                                                        ? 'Cliente'
                                                        : 'Tercero'}
                                                    {wait.via_portal
                                                        ? ' · por el portal'
                                                        : ''}
                                                </strong>
                                                <span
                                                    className={toneBadge(
                                                        wait.status === 'open'
                                                            ? wait.overdue
                                                                ? 'red'
                                                                : 'violet'
                                                            : 'green',
                                                    )}
                                                >
                                                    {wait.status === 'open'
                                                        ? wait.overdue
                                                            ? 'Vencida'
                                                            : 'Abierta'
                                                        : wait.status ===
                                                            'resolved'
                                                          ? 'Resuelta'
                                                          : 'Cancelada'}
                                                </span>
                                            </div>
                                            <p className="mt-1">
                                                {wait.reason}
                                            </p>
                                            <p className="mt-1 text-xs text-slate-500">
                                                {wait.requested_by} ·{' '}
                                                {formatDate(
                                                    wait.created_at,
                                                    true,
                                                )}
                                                {wait.expected_on
                                                    ? ` · esperado ${formatDate(wait.expected_on)}`
                                                    : ''}
                                            </p>
                                            {wait.resolution && (
                                                <p className="mt-1 text-xs text-emerald-700">
                                                    {wait.resolution}
                                                    {wait.file_name
                                                        ? ` · ${wait.file_name}`
                                                        : ''}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        )}

                        {!source && (
                            <Card title="Avance">
                                {canNote && (
                                    <form
                                        onSubmit={addNote}
                                        className="mb-4 space-y-2"
                                    >
                                        <textarea
                                            className={`${input} h-24 py-2`}
                                            placeholder="Anota lo que realizaste (queda en el historial y habilita el envío)"
                                            maxLength={1000}
                                            value={noteForm.data.note}
                                            onChange={(event) =>
                                                noteForm.setData(
                                                    'note',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {noteForm.errors.note && (
                                            <p className="text-xs text-red-700">
                                                {noteForm.errors.note}
                                            </p>
                                        )}
                                        <button
                                            type="submit"
                                            className={secondaryButton}
                                            disabled={
                                                noteForm.processing ||
                                                noteForm.data.note.trim() === ''
                                            }
                                        >
                                            {noteForm.processing && (
                                                <Loader2 className="size-3.5 animate-spin" />
                                            )}{' '}
                                            Guardar avance
                                        </button>
                                    </form>
                                )}
                                {detail.comments.filter(
                                    (comment) => comment.kind === 'comment',
                                ).length === 0 ? (
                                    <p className="text-sm text-slate-500">
                                        Todavía no hay notas de avance.
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {detail.comments.map(
                                            (comment, index) => (
                                                <li
                                                    key={index}
                                                    className="rounded-lg border border-slate-200 p-3 text-sm"
                                                >
                                                    <p className="whitespace-pre-line">
                                                        {comment.message}
                                                    </p>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        {comment.author} ·{' '}
                                                        {formatDate(
                                                            comment.at,
                                                            true,
                                                        )}
                                                        {comment.kind !==
                                                            'comment' &&
                                                            ` · ${{ observation: 'observación', blocker: 'bloqueo', wait: 'espera' }[comment.kind] ?? comment.kind}`}
                                                    </p>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </Card>
                        )}
                    </div>

                    <div className="space-y-4">
                        {!source &&
                            ['in_progress', 'changes_requested'].includes(
                                task.status,
                            ) && (
                                <Card title="Para enviar">
                                    {readiness.ready ? (
                                        <p className="text-sm font-semibold text-emerald-700">
                                            Ya puedes enviarla.
                                        </p>
                                    ) : (
                                        <ul className="list-disc space-y-1 pl-5 text-sm">
                                            {readiness.pending.map((item) => (
                                                <li key={item}>{item}</li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                            )}

                        <Card title="Dependencias">
                            {detail.dependencies.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    No depende de otras tareas.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {detail.dependencies.map((relation) => (
                                        <li
                                            key={`${relation.direction}-${relation.id}`}
                                            className="flex items-start justify-between gap-2"
                                        >
                                            <span>
                                                <span className="text-xs text-slate-500">
                                                    {relation.type_label}
                                                </span>
                                                <br />
                                                <Link
                                                    href={`/essential/tasks/${relation.task_id}`}
                                                    className="font-semibold hover:text-[#640000]"
                                                >
                                                    {relation.title}
                                                </Link>
                                                <span
                                                    className={`ml-2 ${toneBadge(relation.finished ? 'green' : relation.blocks_readiness ? 'amber' : 'slate')}`}
                                                >
                                                    {relation.finished
                                                        ? 'Terminada'
                                                        : 'Pendiente'}
                                                </span>
                                            </span>
                                            {detail.can_link &&
                                                relation.direction ===
                                                    'outgoing' && (
                                                    <button
                                                        type="button"
                                                        aria-label="Quitar relación"
                                                        className={
                                                            secondaryButton
                                                        }
                                                        onClick={() =>
                                                            router.delete(
                                                                `/essential/tasks/${task.id}/links/${relation.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </button>
                                                )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {detail.can_link && (
                                <form
                                    className="mt-3 space-y-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        linkForm.post(
                                            `/essential/tasks/${task.id}/links`,
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    linkForm.reset(),
                                            },
                                        );
                                    }}
                                >
                                    <select
                                        className={input}
                                        value={linkForm.data.type}
                                        onChange={(event) =>
                                            linkForm.setData(
                                                'type',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="REQUIRES">
                                            Requiere
                                        </option>
                                        <option value="BLOCKS">
                                            Bloquea a
                                        </option>
                                        <option value="RELATED_TO">
                                            Relacionada con
                                        </option>
                                    </select>
                                    <select
                                        className={input}
                                        required
                                        value={linkForm.data.depends_on}
                                        onChange={(event) =>
                                            linkForm.setData(
                                                'depends_on',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Elige la tarea…
                                        </option>
                                        {detail.linkable.map((other) => (
                                            <option
                                                key={other.id}
                                                value={other.id}
                                            >
                                                {other.title}
                                            </option>
                                        ))}
                                    </select>
                                    {linkForm.errors.depends_on && (
                                        <p className="text-xs text-red-700">
                                            {linkForm.errors.depends_on}
                                        </p>
                                    )}
                                    <button
                                        type="submit"
                                        className={secondaryButton}
                                        disabled={
                                            linkForm.processing ||
                                            linkForm.data.depends_on === ''
                                        }
                                    >
                                        Agregar relación
                                    </button>
                                </form>
                            )}
                        </Card>

                        <Card title="Historial">
                            <ol className="space-y-3 border-l-2 border-slate-100 pl-4 text-sm">
                                {detail.history.map((event, index) => (
                                    <li key={index}>
                                        <p className="font-semibold">
                                            {historyLabels[event.action] ??
                                                event.action}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {event.actor} ·{' '}
                                            {formatDate(event.at, true)}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </Card>
                    </div>
                </div>

                {footer.length > 0 && (
                    <footer className="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 rounded-xl border border-[#e7e1dd] bg-white p-3 shadow-lg">
                        {footer.map((action, index) => (
                            <button
                                key={`${action.key}-${index}`}
                                type="button"
                                className={
                                    action.primary
                                        ? primaryButton
                                        : secondaryButton
                                }
                                disabled={!action.enabled}
                                title={action.reason ?? undefined}
                                onClick={() =>
                                    action.needs_note ||
                                    action.key === 'receive'
                                        ? setAsking(action)
                                        : post(action)
                                }
                            >
                                {action.label}
                            </button>
                        ))}
                    </footer>
                )}
            </Shell>

            <ActionDialog
                action={asking}
                taskId={task.id}
                title={task.title}
                onClose={() => setAsking(null)}
            />
        </>
    );
}

function Item({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs font-semibold text-slate-500 uppercase">
                {label}
            </dt>
            <dd className="mt-1 font-semibold">{value ?? '—'}</dd>
        </div>
    );
}

function ActionDialog({
    action,
    taskId,
    title,
    onClose,
}: {
    action: Action | null;
    taskId: number;
    title: string;
    onClose: () => void;
}) {
    const form = useForm({
        note: '',
        expected_on: '',
        wait_id: '',
        file: null as File | null,
    });
    const waiting = action?.key.startsWith('wait_') ?? false;
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!action) {
            return;
        }

        form.transform((data) => ({
            note: data.note,
            expected_on: waiting && data.expected_on ? data.expected_on : null,
            wait_id: action.wait_id ?? null,
            file: action.key === 'receive' ? data.file : null,
        }));
        form.post(`/essential/tasks/${taskId}/${action.key}`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={action !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{action?.label}</DialogTitle>
                    <DialogDescription>{title}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <label className="block text-sm font-semibold">
                        {waiting
                            ? '¿Qué se espera y de quién?'
                            : action?.key === 'receive'
                              ? '¿Qué llegó?'
                              : 'Motivo'}
                        <textarea
                            className={`${input} mt-1 h-24 py-2`}
                            required
                            maxLength={1000}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                        {form.errors.note && (
                            <span className="text-xs text-red-700">
                                {form.errors.note}
                            </span>
                        )}
                    </label>
                    {waiting && (
                        <label className="block text-sm font-semibold">
                            Fecha esperada (opcional)
                            <input
                                type="date"
                                className={`${input} mt-1`}
                                min={new Date().toISOString().slice(0, 10)}
                                value={form.data.expected_on}
                                onChange={(event) =>
                                    form.setData(
                                        'expected_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                    )}
                    {action?.key === 'receive' && (
                        <label className="block text-sm font-semibold">
                            Archivo recibido (opcional)
                            <input
                                type="file"
                                className={`${input} mt-1 pt-1.5`}
                                onChange={(event) =>
                                    form.setData(
                                        'file',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                    )}
                    {(form.errors as Record<string, string>).action && (
                        <p className="text-xs text-red-700">
                            {(form.errors as Record<string, string>).action}
                        </p>
                    )}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={onClose}
                        >
                            Volver
                        </button>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={
                                form.processing ||
                                form.data.note.trim().length < 3
                            }
                        >
                            {form.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}{' '}
                            Confirmar
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
