import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { amount, usePayablesHref } from '@/components/payables/kit';
import type { CreditNote, PayablesContext } from '@/components/payables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function PayablesCreditNotes({
    creditNotes,
}: {
    context: PayablesContext;
    creditNotes: CreditNote[];
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Notas de crédito de proveedores" />
            <Shell>
                <PageHeader
                    title="Notas de crédito de proveedores"
                    subtitle="Registradas en Compras sobre la compra original; reducen el saldo sin superarlo. Para solicitar una, entra al documento por pagar"
                />
                <Card title={`${creditNotes.length} nota(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Proveedor',
                            'Nota',
                            'Compra',
                            'Monto',
                            'Motivo',
                            'Estado',
                        ]}
                        empty={
                            creditNotes.length === 0 &&
                            'No existen notas de crédito de proveedores.'
                        }
                    >
                        {creditNotes.map((note) => (
                            <tr key={note.id}>
                                <td className="px-2 py-2">
                                    {formatDate(note.date)}
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {note.supplier}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {note.company}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{note.number}</td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(note.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {note.document}
                                    </Link>
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(note.amount_cents)}
                                </td>
                                <td className="px-2 py-2">{note.reason}</td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            note.status === 'Aplicada'
                                                ? 'green'
                                                : 'slate',
                                        )}
                                    >
                                        {note.status}
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
