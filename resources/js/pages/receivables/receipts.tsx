import { Head } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { amount } from '@/components/receivables/kit';
import type {
    ReceivablesContext,
    SharedReceipt,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

const tone: Record<string, string> = {
    applied: 'green',
    partially_applied: 'amber',
    unapplied: 'red',
};

export default function ReceivablesReceipts({
    receipts,
}: {
    context: ReceivablesContext;
    receipts: SharedReceipt[];
}) {
    return (
        <>
            <Head title="Cobros · CxC" />
            <Shell>
                <PageHeader
                    title="Cobros"
                    subtitle="Los mismos cobros que registra Tesorería; CxC se encarga de aplicarlos a los documentos"
                />
                <Card title={`${receipts.length} cobro(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Cliente',
                            'Cuenta',
                            'Referencia',
                            'Recibido',
                            'Aplicado',
                            'Sin aplicar',
                            'Aplicado a',
                            'Estado',
                        ]}
                        empty={
                            receipts.length === 0 && 'Sin cobros registrados.'
                        }
                    >
                        {receipts.map((receipt) => (
                            <tr key={receipt.id}>
                                <td className="px-2 py-2">
                                    {formatDate(receipt.received_on)}
                                </td>
                                <td className="px-2 py-2">{receipt.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    {receipt.customer}
                                </td>
                                <td className="px-2 py-2">{receipt.account}</td>
                                <td className="px-2 py-2">
                                    {receipt.reference}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(receipt.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(receipt.applied_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(receipt.unapplied_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {receipt.applications
                                        .map(
                                            (row) =>
                                                `${row.document} ${amount(row.amount_cents)}`,
                                        )
                                        .join(', ') || '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            tone[receipt.status] ?? 'slate',
                                        )}
                                    >
                                        {receipt.status_label}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
