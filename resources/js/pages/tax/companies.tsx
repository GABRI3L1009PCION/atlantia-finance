import { Head, Link } from '@inertiajs/react';
import {
    ProgressValue,
    StageIcon,
    StageLegend,
} from '@/components/assistant/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { fiscalStatusTone, useTaxHref } from '@/components/tax/kit';
import type { FiscalCompany, TaxContext } from '@/components/tax/kit';

export default function TaxCompanies({
    context,
    companies,
}: {
    context: TaxContext;
    companies: FiscalCompany[];
}) {
    const href = useTaxHref();

    return (
        <>
            <Head title="Estado por empresa" />
            <Shell>
                <PageHeader
                    title="Estado fiscal por empresa"
                    subtitle={`Obligaciones, libros, retenciones, FEL, documentos, conciliación y revisión · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card
                    title={`${companies.length} empresa(s)`}
                    action={<StageLegend />}
                >
                    <Table
                        headers={[
                            'Empresa',
                            ...(companies[0]?.stages.map(
                                (stage) => stage.label,
                            ) ?? []),
                            'Avance',
                            'Estado',
                            'Próximo vencimiento',
                            '',
                        ]}
                        empty={
                            companies.length === 0 &&
                            'No tienes empresas asignadas para gestión fiscal.'
                        }
                    >
                        {companies.map((company) => (
                            <tr key={company.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(company.destination)}
                                        className="font-bold hover:text-[#640000]"
                                    >
                                        {company.name}
                                    </Link>
                                    <p className="text-[11px] text-[#526b92]">
                                        NIT {company.tax_id}
                                    </p>
                                </td>
                                {company.stages.map((stage) => (
                                    <td
                                        key={stage.key}
                                        className="px-2 py-2 text-center"
                                    >
                                        <StageIcon stage={stage} />
                                    </td>
                                ))}
                                <td className="px-2 py-2">
                                    <ProgressValue value={company.progress} />
                                </td>
                                <td className="px-2 py-2">
                                    {company.error ? (
                                        <span className="text-xs text-violet-700">
                                            {company.error}
                                        </span>
                                    ) : (
                                        <span
                                            className={toneBadge(
                                                company.overdue
                                                    ? 'red'
                                                    : (fiscalStatusTone[
                                                          company.status
                                                      ] ?? 'slate'),
                                            )}
                                        >
                                            {company.overdue
                                                ? 'Atrasada'
                                                : company.status_label}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(company.due_on)}
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={company.company_dashboard}
                                        className={secondaryButton}
                                    >
                                        Ver empresa
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
