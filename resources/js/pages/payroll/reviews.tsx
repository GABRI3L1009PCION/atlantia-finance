import { Head, Link } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
} from '@/components/office-admin/kit';
import { amount, periodTone, usePayrollHref } from '@/components/payroll/kit';
import type { PayrollContext, PayrollPeriod } from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function PayrollReviews({
    periods,
    notice,
}: {
    context: PayrollContext;
    periods: PayrollPeriod[];
    notice: string | null;
}) {
    const href = usePayrollHref();
    const pending = periods.filter((period) => period.can_review);

    return (
        <>
            <Head title="Revisiones y aprobaciones" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Revisiones y aprobaciones"
                    subtitle="Quien calcula no aprueba: la nómina la aprueba otra persona autorizada de la empresa"
                />
                <Card title={`Por aprobar por ti (${pending.length})`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Nómina',
                            'Empleados',
                            'Neto',
                            'Advertencias',
                            '',
                        ]}
                        empty={
                            pending.length === 0 &&
                            'No tienes nóminas pendientes de tu aprobación.'
                        }
                    >
                        {pending.map((period) => (
                            <tr key={period.id}>
                                <td className="px-2 py-2">{period.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    {period.name}
                                </td>
                                <td className="px-2 py-2">
                                    {period.employees_count}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(period.net_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {period.warnings ?? 0}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(period.destination)}
                                        className="rounded-md bg-[#640000] px-3 py-1 text-xs font-bold text-white"
                                    >
                                        Revisar
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card title="Estado de revisiones">
                    <Table
                        headers={[
                            'Empresa',
                            'Nómina',
                            'Pago',
                            'Neto',
                            'Errores',
                            'Estado',
                            'Observaciones',
                        ]}
                        empty={
                            periods.length === 0 && 'No hay nóminas calculadas.'
                        }
                    >
                        {periods.map((period) => (
                            <tr key={period.id}>
                                <td className="px-2 py-2">{period.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(period.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {period.name}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(period.payment_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(period.net_cents)}
                                </td>
                                <td
                                    className={`px-2 py-2 ${(period.errors ?? 0) > 0 ? 'font-bold text-red-700' : ''}`}
                                >
                                    {period.errors ?? 0}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            periodTone[period.status] ??
                                                'slate',
                                        )}
                                    >
                                        {period.status_label}
                                    </span>
                                    {period.needs_recalculation && (
                                        <p className="text-[11px] text-amber-700">
                                            Requiere recálculo
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {period.review_note ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
