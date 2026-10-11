import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    amount,
    companyTone,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    PayablesCompany,
    PayablesContext,
} from '@/components/payables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function PayablesCompanies({
    companies,
}: {
    context: PayablesContext;
    companies: PayablesCompany[];
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Mis empresas · CxP" />
            <Shell>
                <PageHeader
                    title="Mis empresas"
                    subtitle="Obligaciones de cada empresa por separado; no se suman deudas de entidades distintas"
                />
                <Card title={`${companies.length} empresa(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Proveedores con saldo',
                            'Documentos',
                            'Por vencer',
                            'Vencido',
                            'Total',
                            'Propuestas por aprobar',
                            'Anticipos',
                            'CxP vs Mayor',
                            'Incidencias',
                            'Estado',
                            '',
                        ]}
                        empty={
                            companies.length === 0 &&
                            'No tienes empresas asignadas para cuentas por pagar.'
                        }
                    >
                        {companies.map((company) => (
                            <tr key={company.id}>
                                <td className="px-2 py-2 font-bold">
                                    {company.name}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        NIT {company.tax_id}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {company.suppliers_with_balance ??
                                        'No disponible'}
                                </td>
                                <td className="px-2 py-2">
                                    {company.open_documents ?? 'No disponible'}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(company.current_cents)}
                                </td>
                                <td className="px-2 py-2 text-red-700">
                                    {amount(company.overdue_cents)}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(company.total_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {company.proposals_waiting_approval}
                                </td>
                                <td className="px-2 py-2">
                                    {company.unapplied_advances}
                                </td>
                                <td className="px-2 py-2">
                                    {company.reconciliation?.status_label ??
                                        'No disponible'}
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
                                    >
                                        {company.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(company.destination)}
                                        className={secondaryButton}
                                    >
                                        Detalle
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
