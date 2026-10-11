import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    FilterChips,
    amount,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    Advance,
    Payable,
    PayablesContext,
    PayablesPermissions,
} from '@/components/payables/kit';
import { AdvancesTable } from '@/components/payables/tables';
import { Card } from '@/components/supervisor/kit';

/** Applies an advance (or unapplied payment) to open invoices of the same supplier and company; no money moves. */
export function ApplyAdvanceForm({
    advance,
    documents,
    onDone,
}: {
    advance: Advance;
    documents: Payable[];
    onDone?: () => void;
}) {
    const candidates = documents.filter(
        (document) =>
            document.supplier_id === advance.supplier_id &&
            document.company_id === advance.company_id &&
            document.outstanding_cents - document.committed_cents > 0,
    );
    const form = useForm<{
        applications: { purchase_document_id: number; amount: string }[];
    }>({
        applications: candidates.map((document) => ({
            purchase_document_id: document.id,
            amount: '',
        })),
    });
    const total = form.data.applications.reduce(
        (sum, row) => sum + Math.round(Number(row.amount || 0) * 100),
        0,
    );

    if (candidates.length === 0) {
        return (
            <p className="text-sm text-slate-500">
                {advance.supplier} no tiene facturas abiertas en{' '}
                {advance.company} para aplicar este saldo.
            </p>
        );
    }

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((values) => ({
                    applications: values.applications.filter(
                        (row) => Number(row.amount) > 0,
                    ),
                }));
                form.post(`/payables/advances/${advance.id}/apply`, {
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                });
            }}
        >
            <p className="text-xs text-[#526b92]">
                Disponible {amount(advance.outstanding_cents)} ·{' '}
                {advance.supplier} · {advance.company}. Aplicar no mueve dinero
                ni genera asiento: el pago ya se contabilizó contra CxP.
            </p>
            {candidates.map((document, index) => (
                <label
                    key={document.id}
                    className="grid grid-cols-[minmax(0,1fr)_8rem] items-center gap-2 text-xs"
                >
                    <span>
                        <b>{document.document}</b> · saldo disponible{' '}
                        {amount(
                            document.outstanding_cents -
                                document.committed_cents,
                        )}
                    </span>
                    <input
                        aria-label={`Monto para ${document.document}`}
                        className={input}
                        inputMode="decimal"
                        placeholder="0.00"
                        value={form.data.applications[index]?.amount ?? ''}
                        onChange={(event) =>
                            form.setData(
                                'applications',
                                form.data.applications.map((row, position) =>
                                    position === index
                                        ? { ...row, amount: event.target.value }
                                        : row,
                                ),
                            )
                        }
                    />
                </label>
            ))}
            <div className="flex items-center justify-between gap-2">
                <span
                    className={`text-xs font-bold ${total > advance.outstanding_cents ? 'text-red-700' : 'text-[#061633]'}`}
                >
                    A aplicar: {amount(total)}
                </span>
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={
                        form.processing ||
                        total <= 0 ||
                        total > advance.outstanding_cents
                    }
                >
                    Aplicar
                </button>
            </div>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

export default function PayablesAdvances({
    advances,
    openDocuments,
    filters,
    focus,
    permissions,
    notice,
}: {
    context: PayablesContext;
    advances: Advance[];
    openDocuments: Payable[];
    filters: { status?: string };
    focus: number | null;
    permissions: PayablesPermissions;
    notice: string | null;
}) {
    const href = usePayablesHref();
    const [selected, setSelected] = useState<Advance | null>(
        advances.find(
            (advance) => advance.id === focus && advance.outstanding_cents > 0,
        ) ?? null,
    );

    return (
        <>
            <Head title="Anticipos a proveedores" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Anticipos a proveedores"
                    subtitle="Anticipos pagados por Tesorería y pagos desaplicados; se aplican a facturas reales del mismo proveedor, nunca a facturas ficticias"
                />
                {selected && permissions.canApplyAdvances && (
                    <Card
                        title={`Aplicar ${selected.origin_label.toLowerCase()} ${selected.reference}`}
                        action={
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setSelected(null)}
                            >
                                Cerrar
                            </button>
                        }
                    >
                        <ApplyAdvanceForm
                            key={selected.id}
                            advance={selected}
                            documents={openDocuments}
                            onDone={() => setSelected(null)}
                        />
                    </Card>
                )}
                <Card title={`${advances.length} anticipo(s)`}>
                    <FilterChips
                        options={[
                            ['open', 'Con saldo'],
                            ['all', 'Todos'],
                        ]}
                        current={filters.status ?? 'all'}
                        onSelect={(status) =>
                            router.get(href('/payables/advances'), { status })
                        }
                    />
                    <AdvancesTable
                        advances={advances}
                        empty="No existen anticipos a proveedores."
                        canApply={permissions.canApplyAdvances}
                        onApply={setSelected}
                    />
                </Card>
            </Shell>
        </>
    );
}
