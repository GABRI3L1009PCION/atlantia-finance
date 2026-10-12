import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AskLuzmaButton } from '@/components/luzma/luzma-button';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { amount, usePayablesHref } from '@/components/payables/kit';
import type { PayablesContext } from '@/components/payables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Result = {
    status: string;
    status_label: string;
    cutoff: string;
    control_account: string | null;
    auxiliary_cents: number | null;
    ledger_cents: number | null;
    difference_cents: number | null;
    items: {
        kind: string;
        document: string;
        supplier: string | null;
        date: string;
        expected_cents: number;
        ledger_cents: number;
        difference_cents: number;
        reason: string;
        destination: string | null;
    }[];
    message: string | null;
};

const statusTone: Record<string, string> = {
    matched: 'green',
    difference: 'red',
    not_applicable: 'slate',
    error: 'violet',
};

export default function PayablesReconciliation({
    context,
    companyId,
    cutoff,
    result,
}: {
    context: PayablesContext;
    companyId: number | null;
    cutoff: string;
    result: Result | null;
}) {
    const href = usePayablesHref();
    const [form, setForm] = useState({
        company_id: companyId ? String(companyId) : '',
        cutoff,
    });

    return (
        <>
            <Head title="Conciliación CxP - Mayor" />
            <Shell>
                <PageHeader
                    title="Conciliación CxP - Mayor"
                    subtitle="Auxiliar de cuentas por pagar contra la cuenta de control del mapeo contable; se recalcula en cada consulta y no existe un botón para cuadrar"
                />
                <Card title="Empresa y corte">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(href('/payables/reconciliation'), form);
                        }}
                    >
                        <label className="grid min-w-56 gap-1 text-xs font-semibold">
                            Empresa
                            <select
                                className={input}
                                value={form.company_id}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        company_id: event.target.value,
                                    })
                                }
                            >
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold">
                            Fecha de corte
                            <input
                                type="date"
                                className={input}
                                value={form.cutoff}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        cutoff: event.target.value,
                                    })
                                }
                            />
                        </label>
                        <button type="submit" className={secondaryButton}>
                            Investigar diferencia
                        </button>
                        {result && result.status !== 'matched' && (
                            <AskLuzmaButton
                                question="¿Por qué CxP no coincide con el Mayor?"
                                label="Investigar con Luzma"
                            />
                        )}
                    </form>
                </Card>
                {result && (
                    <>
                        <div className="grid gap-3 md:grid-cols-4">
                            <Card title="Auxiliar CxP">
                                <p className="text-2xl font-black">
                                    {amount(result.auxiliary_cents)}
                                </p>
                            </Card>
                            <Card title="Mayor">
                                <p className="text-2xl font-black">
                                    {amount(result.ledger_cents)}
                                </p>
                                <p className="text-xs text-[#526b92]">
                                    {result.control_account ??
                                        'Sin cuenta de control'}
                                </p>
                            </Card>
                            <Card title="Diferencia">
                                <p
                                    className={`text-2xl font-black ${result.difference_cents === 0 ? 'text-emerald-700' : 'text-red-700'}`}
                                >
                                    {result.status === 'error'
                                        ? 'No disponible'
                                        : amount(result.difference_cents)}
                                </p>
                            </Card>
                            <Card title="Estado">
                                <span
                                    className={toneBadge(
                                        statusTone[result.status] ?? 'slate',
                                    )}
                                >
                                    {result.status_label}
                                </span>
                                <p className="mt-1 text-xs text-[#526b92]">
                                    Corte {formatDate(cutoff)}
                                </p>
                            </Card>
                        </div>
                        {result.message && (
                            <p className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
                                {result.message}
                            </p>
                        )}
                        <Card
                            title={`Partidas que explican la diferencia (${result.items.length})`}
                        >
                            <Table
                                headers={[
                                    'Tipo',
                                    'Documento',
                                    'Proveedor',
                                    'Fecha',
                                    'Según CxP',
                                    'Según Mayor',
                                    'Diferencia',
                                    'Causa',
                                    '',
                                ]}
                                empty={
                                    result.items.length === 0 &&
                                    'CxP coincide con el Libro Mayor.'
                                }
                            >
                                {result.items.map((item, index) => (
                                    <tr key={index}>
                                        <td className="px-2 py-2">
                                            {item.kind}
                                        </td>
                                        <td className="px-2 py-2 font-bold">
                                            {item.document}
                                        </td>
                                        <td className="px-2 py-2">
                                            {item.supplier ?? '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(item.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(item.expected_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(item.ledger_cents)}
                                        </td>
                                        <td className="px-2 py-2 font-semibold text-red-700">
                                            {amount(item.difference_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {item.reason}
                                        </td>
                                        <td className="px-2 py-2">
                                            {item.destination && (
                                                <a
                                                    href={item.destination}
                                                    className={secondaryButton}
                                                >
                                                    Ver origen
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                            <p className="mt-2 text-[11px] text-[#526b92]">
                                Para eliminar una diferencia corrige su origen
                                (contabiliza o corrige el asiento, la compra, el
                                pago o el anticipo); la conciliación se
                                actualiza sola.
                            </p>
                        </Card>
                    </>
                )}
            </Shell>
        </>
    );
}
