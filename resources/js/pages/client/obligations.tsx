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
            subtitle="Fechas, estado y monto a pagar de tus impuestos. Paga en tu banco con el número de acceso antes del vencimiento; las constancias de presentación se descargan aquí."
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
                            'A pagar',
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
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {row.payment_amount_cents === null ? (
                                        '—'
                                    ) : (
                                        <>
                                            <span className="block font-bold">
                                                Q
                                                {(
                                                    row.payment_amount_cents /
                                                    100
                                                ).toLocaleString('es-GT', {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2,
                                                })}
                                            </span>
                                            {row.payment_access_number && (
                                                <span className="block text-[#526b92]">
                                                    Acceso{' '}
                                                    {row.payment_access_number}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(row.filed_on)}
                                    {row.has_evidence && (
                                        <a
                                            className="block font-bold text-[#7a0010] underline"
                                            href={`/client/obligations/${row.id}/evidence`}
                                        >
                                            Constancia
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
        </ClientPage>
    );
}
