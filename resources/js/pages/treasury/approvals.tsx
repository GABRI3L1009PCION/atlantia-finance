import { Head, Link } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';
import { amount } from '@/components/treasury/kit';
import type {
    Payment,
    Reconciliation,
    TreasuryContext,
} from '@/components/treasury/kit';

export default function TreasuryApprovals({
    approvals,
    notice,
}: {
    context: TreasuryContext;
    approvals: { payments: Payment[]; reconciliations: Reconciliation[] };
    notice: string | null;
}) {
    return (
        <>
            <Head title="Aprobaciones de tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Aprobaciones de tesorería"
                    subtitle="Pagos y conciliaciones que esperan tu aprobación; nunca aparecen los que preparaste tú"
                />
                <Card
                    title={`Pagos por aprobar (${approvals.payments.length})`}
                >
                    <Table
                        headers={[
                            'Proveedor',
                            'Empresa',
                            'Monto',
                            'Aprobaciones',
                            'Preparó',
                            '',
                        ]}
                        empty={
                            approvals.payments.length === 0 &&
                            'No tienes pagos por aprobar.'
                        }
                    >
                        {approvals.payments.map((payment) => (
                            <tr key={payment.id}>
                                <td className="px-2 py-2 font-bold">
                                    {payment.supplier}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {payment.concept}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{payment.company}</td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(payment.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {payment.approvals} /{' '}
                                    {payment.required_approvals}
                                </td>
                                <td className="px-2 py-2">
                                    {payment.preparer ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={payment.destination}
                                        className={secondaryButton}
                                    >
                                        Revisar
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card
                    title={`Conciliaciones por aprobar (${approvals.reconciliations.length})`}
                >
                    <Table
                        headers={[
                            'Cuenta',
                            'Empresa',
                            'Corte',
                            'Diferencia',
                            'Preparó',
                            '',
                        ]}
                        empty={
                            approvals.reconciliations.length === 0 &&
                            'No tienes conciliaciones por aprobar.'
                        }
                    >
                        {approvals.reconciliations.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2 font-bold">
                                    {row.account}
                                </td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td className="px-2 py-2">
                                    {formatDate(row.cutoff_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(row.difference_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {row.preparer ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={row.destination}
                                        className={secondaryButton}
                                    >
                                        Revisar
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
