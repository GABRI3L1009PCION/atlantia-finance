import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { obligationTone, useTaxHref } from '@/components/tax/kit';
import type { Obligation, TaxContext } from '@/components/tax/kit';

export default function TaxReviews({
    obligations,
}: {
    context: TaxContext;
    obligations: Obligation[];
}) {
    const href = useTaxHref();

    return (
        <>
            <Head title="Pendientes de revisión" />
            <Shell>
                <PageHeader
                    title="Pendientes de revisión"
                    subtitle="Trabajo fiscal enviado al revisor, devuelto con cambios o aprobado sin presentar"
                />
                <Card title={`${obligations.length} obligación(es)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Obligación',
                            'Período',
                            'Preparó',
                            'Revisor',
                            'Límite de revisión',
                            'Estado',
                            'Observación',
                        ]}
                        empty={
                            obligations.length === 0 &&
                            'No tienes trabajo fiscal en revisión.'
                        }
                    >
                        {obligations.map((obligation) => (
                            <tr key={obligation.id}>
                                <td className="px-2 py-2 font-bold">
                                    {obligation.company_name}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(obligation.destination)}
                                        className="font-semibold hover:text-[#640000]"
                                    >
                                        {obligation.name}
                                    </Link>
                                    {obligation.stale && (
                                        <p className="text-[11px] font-semibold text-red-700">
                                            Datos cambiados: recalcular
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.reference_period}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.assignee_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.reviewer_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(obligation.review_due_on)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            obligationTone[obligation.status] ??
                                                'slate',
                                        )}
                                    >
                                        {obligation.status_label}
                                    </span>
                                </td>
                                <td className="max-w-xs px-2 py-2 text-xs text-violet-700">
                                    {obligation.status === 'changes_requested'
                                        ? obligation.last_observation
                                        : ''}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
