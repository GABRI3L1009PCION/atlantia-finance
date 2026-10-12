import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { usePayrollHref } from '@/components/payroll/kit';
import type { PayrollContext } from '@/components/payroll/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Receipt = {
    id: number;
    number: string;
    employee: string;
    period: string;
    period_id: number;
    company: string;
    issued_at: string;
};

export default function PayrollReceipts({
    receipts,
}: {
    context: PayrollContext;
    receipts: Receipt[];
}) {
    const href = usePayrollHref();

    return (
        <>
            <Head title="Recibos de pago" />
            <Shell>
                <PageHeader
                    title="Recibos de pago"
                    subtitle="Generados al aprobar cada nómina; se descargan en PDF con el detalle de ingresos y descuentos"
                />
                <Card title={`${receipts.length} recibo(s)`}>
                    <Table
                        headers={[
                            'Recibo',
                            'Empleado',
                            'Empresa',
                            'Nómina',
                            'Emitido',
                            '',
                        ]}
                        empty={
                            receipts.length === 0 &&
                            'Aún no hay recibos: se generan al aprobar una nómina.'
                        }
                    >
                        {receipts.map((receipt) => (
                            <tr key={receipt.id}>
                                <td className="px-2 py-2 font-mono font-bold">
                                    {receipt.number}
                                </td>
                                <td className="px-2 py-2">
                                    {receipt.employee}
                                </td>
                                <td className="px-2 py-2">{receipt.company}</td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(
                                            `/payroll/periods/${receipt.period_id}`,
                                        )}
                                        className="hover:text-[#640000]"
                                    >
                                        {receipt.period}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(receipt.issued_at)}
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={`/payroll/receipts/${receipt.id}`}
                                        className="text-xs font-bold text-[#640000]"
                                    >
                                        Descargar PDF
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
