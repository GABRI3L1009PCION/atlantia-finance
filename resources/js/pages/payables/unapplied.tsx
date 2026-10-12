import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { amount, usePayablesHref } from '@/components/payables/kit';
import type {
    Advance,
    PayablesContext,
    PaymentRow,
} from '@/components/payables/kit';
import { AdvancesTable, PaymentsTable } from '@/components/payables/tables';
import { Card, Table } from '@/components/supervisor/kit';

export default function PayablesUnapplied({
    payments,
    balances,
    outflows,
}: {
    context: PayablesContext;
    payments: PaymentRow[];
    balances: Advance[];
    outflows: {
        id: number;
        date: string;
        reference: string;
        description: string;
        amount_cents: number;
        account: string;
        company: string;
    }[];
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Pagos sin aplicar" />
            <Shell>
                <PageHeader
                    title="Pagos sin aplicar"
                    subtitle="Pagos ejecutados con saldo no aplicado a facturas y salidas bancarias aún no identificadas en Tesorería"
                />
                <Card
                    title={`Saldos a favor por aplicar (${balances.length})`}
                    action={
                        <Link
                            href={href('/payables/advances?status=open')}
                            className="text-xs font-bold text-[#640000]"
                        >
                            Aplicar en Anticipos
                        </Link>
                    }
                >
                    <AdvancesTable
                        advances={balances}
                        empty="No existen pagos desaplicados pendientes."
                    />
                </Card>
                <Card
                    title={`Pagos con monto sin aplicar (${payments.length})`}
                >
                    <PaymentsTable
                        payments={payments}
                        empty="Todos los pagos ejecutados están aplicados."
                    />
                </Card>
                <Card
                    title={`Salidas bancarias sin identificar (${outflows.length})`}
                >
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Cuenta',
                            'Referencia',
                            'Descripción',
                            'Monto',
                        ]}
                        empty={
                            outflows.length === 0 &&
                            'Tesorería no tiene salidas pendientes de identificar.'
                        }
                    >
                        {outflows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2">
                                    {formatDate(row.date)}
                                </td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td className="px-2 py-2">{row.account}</td>
                                <td className="px-2 py-2">{row.reference}</td>
                                <td className="px-2 py-2">{row.description}</td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(row.amount_cents)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        La identificación de movimientos bancarios la hace
                        Tesorería desde Movimientos; CxP solo los consulta.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
