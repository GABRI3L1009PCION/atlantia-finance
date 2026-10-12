import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { AskLuzmaButton } from '@/components/luzma/luzma-button';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { amount, reconciliationTone } from '@/components/treasury/kit';
import type {
    Reconciliation,
    TreasuryContext,
} from '@/components/treasury/kit';

type Item = {
    side: 'bank' | 'books';
    date: string;
    reference: string;
    description: string;
    amount_cents: number;
    reason: string;
};
type Figures = {
    bank_balance_cents: number;
    ledger_balance_cents: number;
    bank_not_in_books_cents: number;
    books_not_in_bank_cents: number;
    adjusted_bank_cents: number;
    adjusted_ledger_cents: number;
    difference_cents: number;
    items: Item[];
    items_count: number;
};

function Row({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: number;
    strong?: boolean;
}) {
    return (
        <div
            className={`flex justify-between border-b border-slate-100 py-1.5 ${strong ? 'font-black text-[#061633]' : ''}`}
        >
            <span>{label}</span>
            <span>{amount(value)}</span>
        </div>
    );
}

export default function TreasuryReconciliationDetail({
    reconciliation,
    figures,
    history,
    can,
    notice,
}: {
    context: TreasuryContext;
    reconciliation: Reconciliation;
    figures: Figures;
    history: { action: string; user: string; occurred_at: string }[];
    can: { prepare: boolean; approve: boolean };
    notice: string | null;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [note, setNote] = useState('');
    const act = (action: string, data: Record<string, string> = {}) =>
        router.post(
            `/treasury/reconciliations/${reconciliation.id}/${action}`,
            data,
            { preserveScroll: true },
        );
    const sections: [Item['side'], string][] = [
        ['bank', 'Movimientos del banco no registrados en libros'],
        ['books', 'Partidas en libros no reflejadas en el banco'],
    ];

    return (
        <>
            <Head title={`Conciliación ${reconciliation.account}`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={`Conciliación ${reconciliation.account}`}
                    subtitle={`${reconciliation.company} · Corte ${formatDate(reconciliation.cutoff_date)} · Preparó ${reconciliation.preparer ?? '—'}`}
                    actions={
                        <div className="flex items-center gap-2">
                            <AskLuzmaButton
                                question="¿Por qué no concilia este banco?"
                                label="Investigar con Luzma"
                            />
                            <span
                                className={toneBadge(
                                    reconciliation.stale
                                        ? 'amber'
                                        : (reconciliationTone[
                                              reconciliation.status
                                          ] ?? 'slate'),
                                )}
                            >
                                {reconciliation.stale
                                    ? 'Desactualizada'
                                    : reconciliation.status_label}
                            </span>
                        </div>
                    }
                />
                {errors.reconciliation && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {errors.reconciliation}
                    </p>
                )}
                {reconciliation.stale && (
                    <p className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <AlertTriangle className="size-4" /> Los movimientos o
                        asientos cambiaron después del cálculo guardado. Las
                        cifras de abajo son las actuales; recalcula antes de
                        enviar o aprobar.
                    </p>
                )}
                {reconciliation.review_note && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        Observación del revisor: {reconciliation.review_note}
                    </p>
                )}

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <Card title="Cálculo">
                        <div className="text-sm">
                            <Row
                                label="Saldo según banco al corte"
                                value={figures.bank_balance_cents}
                            />
                            <Row
                                label="(+/−) Partidas en libros no en banco"
                                value={figures.books_not_in_bank_cents}
                            />
                            <Row
                                label="Saldo bancario ajustado"
                                value={figures.adjusted_bank_cents}
                                strong
                            />
                            <div className="h-3" />
                            <Row
                                label="Saldo según libros al corte"
                                value={figures.ledger_balance_cents}
                            />
                            <Row
                                label="(+/−) Movimientos del banco no en libros"
                                value={figures.bank_not_in_books_cents}
                            />
                            <Row
                                label="Saldo contable ajustado"
                                value={figures.adjusted_ledger_cents}
                                strong
                            />
                            <div
                                className={`mt-3 flex justify-between rounded-lg p-3 font-black ${figures.difference_cents === 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                            >
                                <span>Diferencia sin explicar</span>
                                <span>{amount(figures.difference_cents)}</span>
                            </div>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {can.prepare &&
                                !['submitted', 'approved'].includes(
                                    reconciliation.status,
                                ) && (
                                    <>
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            onClick={() => act('recalculate')}
                                        >
                                            Recalcular
                                        </button>
                                        <button
                                            type="button"
                                            className={primaryButton}
                                            onClick={() => act('submit')}
                                        >
                                            Enviar a aprobación
                                        </button>
                                    </>
                                )}
                            {can.prepare &&
                                reconciliation.status === 'approved' &&
                                reconciliation.stale && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => act('recalculate')}
                                    >
                                        Recalcular
                                    </button>
                                )}
                        </div>
                        {can.approve &&
                            reconciliation.status === 'submitted' && (
                                <div className="mt-4 grid gap-2 border-t border-slate-100 pt-3">
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() => act('approve')}
                                    >
                                        Aprobar conciliación
                                    </button>
                                    <textarea
                                        className={`${input} h-20 py-2`}
                                        placeholder="Qué debe corregirse…"
                                        value={note}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                    />
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        disabled={note.trim().length < 5}
                                        onClick={() => act('changes', { note })}
                                    >
                                        Devolver con observaciones
                                    </button>
                                </div>
                            )}
                        {reconciliation.approver && (
                            <p className="mt-3 text-xs text-[#526b92]">
                                Aprobó {reconciliation.approver} ·{' '}
                                {formatDate(reconciliation.approved_at)}
                            </p>
                        )}
                    </Card>

                    <div className="grid gap-3">
                        {sections.map(([side, title]) => {
                            const items = figures.items.filter(
                                (item) => item.side === side,
                            );

                            return (
                                <Card key={side} title={title}>
                                    <Table
                                        headers={[
                                            'Fecha',
                                            'Referencia',
                                            'Descripción',
                                            'Motivo',
                                            'Monto',
                                        ]}
                                        empty={
                                            items.length === 0 &&
                                            'Sin partidas.'
                                        }
                                    >
                                        {items.map((item, index) => (
                                            <tr
                                                key={`${item.reference}-${index}`}
                                            >
                                                <td className="px-2 py-2">
                                                    {formatDate(item.date)}
                                                </td>
                                                <td className="px-2 py-2 font-bold">
                                                    {item.reference}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {item.description}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {item.reason}
                                                </td>
                                                <td
                                                    className={`px-2 py-2 font-semibold ${item.amount_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                                >
                                                    {amount(item.amount_cents)}
                                                </td>
                                            </tr>
                                        ))}
                                    </Table>
                                </Card>
                            );
                        })}
                        {figures.items_count > figures.items.length && (
                            <p className="text-xs text-[#526b92]">
                                Se muestran {figures.items.length} de{' '}
                                {figures.items_count} partidas; los totales
                                incluyen todas.
                            </p>
                        )}
                        <Card title="Historial">
                            <ul className="space-y-1 text-xs">
                                {history.length === 0 && <li>Sin eventos.</li>}
                                {history.map((event, index) => (
                                    <li key={index}>
                                        <b>{event.user}</b> · {event.action} ·{' '}
                                        {formatDate(event.occurred_at)}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </div>
                </div>
            </Shell>
        </>
    );
}
