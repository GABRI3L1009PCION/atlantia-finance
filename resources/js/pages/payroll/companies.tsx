import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { amount, companyTone, usePayrollHref } from '@/components/payroll/kit';
import type { PayrollCompany, PayrollContext } from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function PayrollCompanies({
    companies,
}: {
    context: PayrollContext;
    companies: PayrollCompany[];
}) {
    const href = usePayrollHref();

    return (
        <>
            <Head title="Mis empresas · Nómina" />
            <Shell>
                <PageHeader
                    title="Mis empresas"
                    subtitle="Empresas asignadas a nómina con su período en curso"
                />
                <Card title={`${companies.length} empresa(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Empleados activos',
                            'Período',
                            'Pago',
                            'Novedades',
                            'Errores',
                            'Neto a pagar',
                            'Incidencias',
                            'Estado',
                        ]}
                        empty={
                            companies.length === 0 &&
                            'No tienes empresas asignadas para nómina.'
                        }
                    >
                        {companies.map((company) => (
                            <tr key={company.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(company.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {company.name}
                                    </Link>
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        NIT {company.tax_id}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {company.employees}
                                </td>
                                <td className="px-2 py-2">
                                    {company.period ? (
                                        <Link
                                            href={href(
                                                `/payroll/periods/${company.period.id}`,
                                            )}
                                            className="font-semibold hover:text-[#640000]"
                                        >
                                            {company.period.name}
                                        </Link>
                                    ) : (
                                        'Sin período'
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(company.period?.payment_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {company.novelties ?? 'No disponible'}
                                    {(company.pending_novelties ?? 0) > 0 && (
                                        <span className="ml-1 text-amber-700">
                                            ({company.pending_novelties} pend.)
                                        </span>
                                    )}
                                </td>
                                <td
                                    className={`px-2 py-2 ${(company.errors ?? 0) > 0 ? 'font-bold text-red-700' : ''}`}
                                >
                                    {company.errors ?? 'No disponible'}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {company.net_cents === null
                                        ? company.period
                                            ? 'Sin calcular'
                                            : '—'
                                        : amount(company.net_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {company.incidents}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            companyTone[company.status] ??
                                                'slate',
                                        )}
                                        title={company.error ?? undefined}
                                    >
                                        {company.status_label}
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
