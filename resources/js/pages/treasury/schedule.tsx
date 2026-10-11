import { Head, Link, router } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { amount, useTreasuryHref } from '@/components/treasury/kit';
import type { ScheduleRow, TreasuryContext } from '@/components/treasury/kit';

const kindTone: Record<string, string> = {
    payment: 'blue',
    payable: 'amber',
    receivable: 'green',
};

export default function TreasurySchedule({
    rows,
    days,
}: {
    context: TreasuryContext;
    rows: ScheduleRow[];
    days: number;
}) {
    const href = useTreasuryHref();

    return (
        <>
            <Head title="Programación de pagos" />
            <Shell>
                <PageHeader
                    title="Programación de pagos y cobros"
                    subtitle="Pagos preparados, facturas por pagar y cobros esperados por empresa; los vencidos aparecen primero"
                    actions={
                        <div className="flex gap-1">
                            {[7, 15, 30, 60].map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() =>
                                        router.get(href('/treasury/schedule'), {
                                            days: option,
                                        })
                                    }
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${days === option ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                                >
                                    {option} días
                                </button>
                            ))}
                        </div>
                    }
                />
                <Card title={`${rows.length} movimiento(s) previsto(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Tipo',
                            'Empresa',
                            'Tercero',
                            'Monto',
                            '',
                        ]}
                        empty={
                            rows.length === 0 && 'Nada programado en el rango.'
                        }
                    >
                        {rows.map((row, index) => (
                            <tr key={`${row.kind}-${index}`}>
                                <td
                                    className={`px-2 py-2 ${row.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(row.date)}
                                    {row.overdue && ' · vencido'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            kindTone[row.kind] ?? 'slate',
                                        )}
                                    >
                                        {row.kind_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    {row.party}
                                </td>
                                <td
                                    className={`px-2 py-2 font-semibold ${row.amount_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                >
                                    {amount(row.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(row.destination)}
                                        className="font-bold text-[#640000]"
                                    >
                                        Abrir
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
