import { Head } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';
import { amount } from '@/components/treasury/kit';
import type { CashFlowRow, TreasuryContext } from '@/components/treasury/kit';

function Net({ cents }: { cents: number }) {
    return (
        <span className={cents < 0 ? 'text-red-700' : 'text-emerald-700'}>
            {amount(cents)}
        </span>
    );
}

export default function TreasuryCashFlow({
    rows,
    from,
    to,
}: {
    context: TreasuryContext;
    rows: CashFlowRow[];
    from: string;
    to: string;
}) {
    return (
        <>
            <Head title="Flujo de caja · Tesorería" />
            <Shell>
                <PageHeader
                    title="Flujo de caja"
                    subtitle={`Real (movimientos bancarios, sin transferencias internas) contra proyectado (cobros y pagos por vencer) · ${formatDate(from)} al ${formatDate(to)}`}
                />
                <Card title="Por empresa">
                    <Table
                        headers={[
                            'Empresa',
                            'Entradas reales',
                            'Salidas reales',
                            'Neto real',
                            'Entradas proyectadas',
                            'Salidas proyectadas',
                            'Neto proyectado',
                        ]}
                        empty={rows.length === 0 && 'Sin empresas asignadas.'}
                    >
                        {rows.map((row) => (
                            <tr key={row.company_id}>
                                <td className="px-2 py-2 font-bold">
                                    {row.company}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(row.realized_in_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(row.realized_out_cents)}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    <Net cents={row.realized_net_cents} />
                                </td>
                                <td className="px-2 py-2">
                                    {amount(row.forecast_in_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(row.forecast_out_cents)}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    <Net cents={row.forecast_net_cents} />
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        Cada fila es una empresa independiente; no se muestran
                        totales combinados entre clientes.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
