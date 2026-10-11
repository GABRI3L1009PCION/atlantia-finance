import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { observationTone, severityTone } from '@/components/accountant/kit';
import type { Observation } from '@/components/accountant/kit';
import {
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Table, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** Observation list with its flow actions: start correction, resolve (with the fix made) and reopen (with the reason). */
export function ObservationTable({
    observations,
    focus,
    showCompany = true,
}: {
    observations: Observation[];
    focus?: number | null;
    showCompany?: boolean;
}) {
    const [pending, setPending] = useState<{
        observation: Observation;
        status: 'resolved' | 'reopened';
    } | null>(null);
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const move = (
        observation: Observation,
        status: string,
        value: string | null = null,
    ) => {
        router.patch(
            `/accountant/observations/${observation.id}`,
            { status, note: value },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPending(null);
                    setNote('');
                    setError(null);
                },
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'No se pudo actualizar la observación.',
                    ),
            },
        );
    };

    return (
        <>
            <Table
                headers={[
                    ...(showCompany ? ['Empresa'] : []),
                    'Registro',
                    'Observación',
                    'Severidad',
                    'Responsable',
                    'Límite',
                    'Estado',
                    'Acciones',
                ]}
                empty={observations.length === 0 && 'Sin observaciones.'}
            >
                {observations.map((observation) => (
                    <tr
                        key={observation.id}
                        className={`align-top ${observation.id === focus ? 'bg-[#fbf6f3]' : ''}`}
                    >
                        {showCompany && (
                            <td className="px-2 py-2 font-bold">
                                {observation.company_name}
                                <p className="text-[11px] font-normal text-[#526b92]">
                                    {observation.period_name}
                                </p>
                            </td>
                        )}
                        <td className="px-2 py-2">
                            {observation.related_href ? (
                                <a
                                    href={observation.related_href}
                                    className="font-bold hover:text-[#640000]"
                                >
                                    {observation.related_type_label}{' '}
                                    {observation.related_label}
                                </a>
                            ) : (
                                <span>
                                    {observation.related_type_label}{' '}
                                    {observation.related_label}
                                </span>
                            )}
                        </td>
                        <td className="max-w-md px-2 py-2">
                            <p>{observation.message}</p>
                            {observation.resolution && (
                                <p className="text-[11px] text-emerald-700">
                                    Corrección: {observation.resolution}
                                </p>
                            )}
                            <p className="text-[11px] text-[#526b92]">
                                Por {observation.author_name ?? 'Sistema'} ·{' '}
                                {formatDate(observation.created_at)}
                            </p>
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    severityTone[observation.severity] ??
                                        'slate',
                                )}
                            >
                                {observation.severity_label}
                            </span>
                        </td>
                        <td className="px-2 py-2">
                            {observation.assignee_name ?? (
                                <span className="text-amber-700">
                                    Sin asignar
                                </span>
                            )}
                        </td>
                        <td
                            className={`px-2 py-2 ${observation.overdue ? 'font-bold text-red-700' : ''}`}
                        >
                            {observation.due_at
                                ? formatDate(observation.due_at)
                                : '—'}
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    observationTone[observation.status] ??
                                        'slate',
                                )}
                            >
                                {observation.status_label}
                            </span>
                        </td>
                        <td className="px-2 py-2">
                            <div className="flex flex-wrap gap-1">
                                {['open', 'reopened'].includes(
                                    observation.status,
                                ) && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() =>
                                            move(observation, 'in_progress')
                                        }
                                    >
                                        En corrección
                                    </button>
                                )}
                                {['open', 'in_progress', 'reopened'].includes(
                                    observation.status,
                                ) && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => {
                                            setNote('');
                                            setError(null);
                                            setPending({
                                                observation,
                                                status: 'resolved',
                                            });
                                        }}
                                    >
                                        Resolver
                                    </button>
                                )}
                                {observation.status === 'ready_for_review' && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() =>
                                            move(observation, 'resolved')
                                        }
                                    >
                                        Validar corrección
                                    </button>
                                )}
                                {['resolved', 'ready_for_review'].includes(
                                    observation.status,
                                ) && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => {
                                            setNote('');
                                            setError(null);
                                            setPending({
                                                observation,
                                                status: 'reopened',
                                            });
                                        }}
                                    >
                                        Reabrir
                                    </button>
                                )}
                            </div>
                        </td>
                    </tr>
                ))}
            </Table>
            <Dialog
                open={pending !== null}
                onOpenChange={(value) => !value && setPending(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {pending?.status === 'resolved'
                                ? 'Resolver observación'
                                : 'Reabrir observación'}
                        </DialogTitle>
                        <DialogDescription>
                            {pending?.status === 'resolved'
                                ? 'Describe la corrección realizada; queda en el historial.'
                                : 'Explica por qué la corrección no es suficiente.'}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="rounded-lg bg-slate-50 p-2 text-sm">
                        {pending?.observation.message}
                    </p>
                    <textarea
                        aria-label="Detalle"
                        className={`${input} h-24 py-2`}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                    {error && <p className="text-sm text-red-700">{error}</p>}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setPending(null)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={note.trim().length < 3}
                            onClick={() =>
                                pending &&
                                move(pending.observation, pending.status, note)
                            }
                        >
                            Guardar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

type NewObservationProps = {
    open: boolean;
    onClose: () => void;
    companyId: number;
    periodId: number | null;
    reviewId: number | null;
    assignees: { id: number; name: string }[];
    relatedTypes: Record<string, string>;
    severities: Record<string, string>;
    preset?: {
        related_type: string;
        related_id: number;
        related_label: string;
    } | null;
};

export function NewObservationDialog({
    open,
    onClose,
    companyId,
    periodId,
    reviewId,
    assignees,
    relatedTypes,
    severities,
    preset,
}: NewObservationProps) {
    const form = useForm({
        company_id: String(companyId),
        accounting_period_id: periodId === null ? '' : String(periodId),
        company_period_review_id: reviewId === null ? '' : String(reviewId),
        related_type: preset?.related_type ?? 'general',
        related_id: preset ? String(preset.related_id) : '',
        related_label: preset?.related_label ?? '',
        severity: 'medium',
        message: '',
        assigned_to: '',
        due_at: '',
    });
    const [lastPreset, setLastPreset] = useState(preset);

    if (lastPreset !== preset) {
        setLastPreset(preset);
        form.setData({
            ...form.data,
            related_type: preset?.related_type ?? 'general',
            related_id: preset ? String(preset.related_id) : '',
            related_label: preset?.related_label ?? '',
        });
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/accountant/observations', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('message', 'due_at');
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Nueva observación</DialogTitle>
                    <DialogDescription>
                        Queda ligada al registro, se asigna al responsable y se
                        notifica. Ej.: “Factura FEL 445 pertenece a agosto y
                        debe reclasificarse.”
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-semibold">
                        Tipo de registro
                        <select
                            className={`${input} mt-1`}
                            value={form.data.related_type}
                            onChange={(event) =>
                                form.setData('related_type', event.target.value)
                            }
                        >
                            {Object.entries(relatedTypes).map(
                                ([code, label]) => (
                                    <option key={code} value={code}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Referencia
                        <input
                            className={`${input} mt-1`}
                            placeholder="Ej. FEL 445, póliza 12"
                            value={form.data.related_label}
                            onChange={(event) =>
                                form.setData(
                                    'related_label',
                                    event.target.value,
                                )
                            }
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
                            {Object.entries(severities).map(([code, label]) => (
                                <option key={code} value={code}>
                                    {label}
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
                            <option value="">Sin asignar</option>
                            {assignees.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
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
                        />
                    </label>
                    <label className="text-sm font-semibold sm:col-span-2">
                        Observación
                        <textarea
                            className={`${input} mt-1 h-24 py-2`}
                            value={form.data.message}
                            onChange={(event) =>
                                form.setData('message', event.target.value)
                            }
                            required
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
                            disabled={
                                form.processing ||
                                form.data.message.trim().length < 5
                            }
                        >
                            Registrar observación
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
