import type { Obligation } from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    obligationTone,
    toneBadge,
} from '@/components/client-portal/kit';

export default function ClientObligations({
    obligations,
}: {
    obligations: { error: boolean; rows: Obligation[] };
}) {
    return (
        <ClientPage
            title="Obligaciones"
            subtitle="Fechas y estado de tus obligaciones fiscales. Los montos se informan cuando tu oficina los aprueba."
        >
            <Card title="Obligaciones fiscales">
                {obligations.error ? (
                    <p className="py-6 text-center text-sm text-red-700">
                        No fue posible cargar tus obligaciones. Intenta de nuevo
                        más tarde.
                    </p>
                ) : (
                    <Table
                        headers={[
                            'Obligación',
                            'Período',
                            'Vence',
                            'Estado',
                            'Documentación',
                            'Presentada',
                        ]}
                        empty={
                            obligations.rows.length === 0 &&
                            'No hay obligaciones visibles.'
                        }
                    >
                        {obligations.rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2">
                                    <span className="block font-bold">
                                        {row.name}
                                    </span>
                                    {row.note && (
                                        <span className="block text-[#526b92]">
                                            {row.note}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">{row.period}</td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(row.due_date)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            obligationTone[row.status] ??
                                                'slate',
                                        )}
                                    >
                                        {row.status_label}
                                    </span>
                                </td>
                                <td
                                    className={`px-2 py-2 ${row.missing > 0 ? 'font-bold text-amber-700' : ''}`}
                                >
                                    {row.missing > 0 ? (
                                        <a href="/client/document-requests">
                                            {row.documentation}
                                        </a>
                                    ) : (
                                        row.documentation
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(row.filed_on)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
        </ClientPage>
    );
}
