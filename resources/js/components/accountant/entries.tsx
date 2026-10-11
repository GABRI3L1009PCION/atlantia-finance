import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Paperclip } from 'lucide-react';
import { useState } from 'react';
import type { AccountantPermissions, Entry } from '@/components/accountant/kit';
import {
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Table, money, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const entryTone: Record<string, string> = {
    draft: 'slate',
    pending_review: 'amber',
    reviewed: 'blue',
    approved: 'green',
    posted: 'green',
    rejected: 'red',
};

/**
 * Journal entries with the accounting actions the user is allowed to run. Adjustments created by the same user cannot be
 * approved by them (segregation of duties); the server enforces it too.
 */
export function EntriesTable({
    entries,
    permissions,
    userId,
    showCompany = true,
    onObserve,
}: {
    entries: Entry[];
    permissions: AccountantPermissions;
    userId?: number;
    showCompany?: boolean;
    onObserve?: (entry: Entry) => void;
}) {
    const [rejecting, setRejecting] = useState<Entry | null>(null);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const run = (
        entry: Entry,
        action: string,
        data: Record<string, string> = {},
    ) => {
        router.post(`/accountant/entries/${entry.id}/${action}`, data, {
            preserveScroll: true,
            onSuccess: () => {
                setRejecting(null);
                setReason('');
                setError(null);
            },
            onError: (errors) =>
                setError(
                    Object.values(errors)[0] ??
                        'No se pudo procesar la póliza.',
                ),
        });
    };

    return (
        <>
            {error && !rejecting && (
                <p className="mb-2 rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-700">
                    {error}
                </p>
            )}
            <Table
                headers={[
                    ...(showCompany ? ['Empresa'] : []),
                    'Fecha',
                    'Descripción',
                    'Tipo',
                    'Monto',
                    'Soporte',
                    'Creó',
                    'Estado',
                    'Acciones',
                ]}
                empty={entries.length === 0 && 'Sin pólizas pendientes.'}
            >
                {entries.map((entry) => {
                    const ownAdjustment =
                        userId !== undefined &&
                        entry.created_by === userId &&
                        [
                            'adjustment',
                            'reclassification',
                            'provision',
                            'closing',
                        ].includes(entry.kind);
                    const open = [
                        'draft',
                        'pending_review',
                        'reviewed',
                        'approved',
                    ].includes(entry.status);

                    return (
                        <tr key={entry.id} className="align-top">
                            {showCompany && (
                                <td className="px-2 py-2 font-bold">
                                    {entry.company_name}
                                </td>
                            )}
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(entry.date)}
                            </td>
                            <td className="px-2 py-2">
                                <a
                                    href={entry.destination}
                                    className="font-bold hover:text-[#640000]"
                                >
                                    {entry.description}
                                </a>
                                {entry.reference && (
                                    <p className="text-[11px] text-[#526b92]">
                                        Ref. {entry.reference}
                                    </p>
                                )}
                            </td>
                            <td className="px-2 py-2">{entry.kind_label}</td>
                            <td className="px-2 py-2 font-bold whitespace-nowrap">
                                {money(entry.amount_cents)}
                                {!entry.balanced && (
                                    <span className="ml-1 inline-flex items-center gap-0.5 text-[11px] text-red-700">
                                        <AlertTriangle className="size-3" />{' '}
                                        descuadrada
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {entry.documents > 0 ? (
                                    <span className="inline-flex items-center gap-1 text-emerald-700">
                                        <Paperclip className="size-3" />
                                        {entry.documents}
                                    </span>
                                ) : (
                                    <span className="text-amber-700">
                                        Sin soporte
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {entry.creator_name ?? '—'}
                                {entry.approver_name && (
                                    <p className="text-[11px] text-[#526b92]">
                                        Aprobó {entry.approver_name}
                                    </p>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        entryTone[entry.status] ?? 'slate',
                                    )}
                                >
                                    {entry.status_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {open && (
                                    <div className="flex flex-wrap gap-1">
                                        {permissions.canReviewEntries &&
                                            [
                                                'draft',
                                                'pending_review',
                                            ].includes(entry.status) && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        run(entry, 'review')
                                                    }
                                                >
                                                    Revisar
                                                </button>
                                            )}
                                        {permissions.canApproveEntries &&
                                            entry.status !== 'approved' &&
                                            (ownAdjustment ? (
                                                <span className="text-[11px] text-amber-700">
                                                    Otra persona debe aprobar tu
                                                    ajuste
                                                </span>
                                            ) : (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        run(entry, 'approve')
                                                    }
                                                >
                                                    <CheckCircle2 className="size-3.5 text-emerald-600" />{' '}
                                                    Aprobar
                                                </button>
                                            ))}
                                        {permissions.canPostEntries &&
                                            entry.balanced && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        window.confirm(
                                                            '¿Contabilizar la póliza? Después solo podrá corregirse con un reverso.',
                                                        ) && run(entry, 'post')
                                                    }
                                                >
                                                    Contabilizar
                                                </button>
                                            )}
                                        {permissions.canRejectEntries && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() => {
                                                    setReason('');
                                                    setError(null);
                                                    setRejecting(entry);
                                                }}
                                            >
                                                Rechazar
                                            </button>
                                        )}
                                        {onObserve && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() => onObserve(entry)}
                                            >
                                                Observar
                                            </button>
                                        )}
                                    </div>
                                )}
                            </td>
                        </tr>
                    );
                })}
            </Table>
            <Dialog
                open={rejecting !== null}
                onOpenChange={(value) => !value && setRejecting(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Rechazar póliza</DialogTitle>
                        <DialogDescription>
                            {rejecting?.description}. El motivo queda en la
                            bitácora contable.
                        </DialogDescription>
                    </DialogHeader>
                    <textarea
                        aria-label="Motivo"
                        className={`${input} h-24 py-2`}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                    />
                    {error && <p className="text-sm text-red-700">{error}</p>}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setRejecting(null)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={reason.trim().length < 3}
                            onClick={() =>
                                rejecting &&
                                run(rejecting, 'reject', { reason })
                            }
                        >
                            Rechazar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
