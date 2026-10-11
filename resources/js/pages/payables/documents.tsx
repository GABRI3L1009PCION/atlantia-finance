import { Head, router } from '@inertiajs/react';
import { PageHeader, Shell, input } from '@/components/office-admin/kit';
import { FilterChips, usePayablesHref } from '@/components/payables/kit';
import type { Payable, PayablesContext } from '@/components/payables/kit';
import { DocumentsTable } from '@/components/payables/tables';
import { Card } from '@/components/supervisor/kit';

export default function PayablesDocuments({
    context,
    documents,
    filters,
}: {
    context: PayablesContext;
    documents: Payable[];
    filters: { company_id?: string; status?: string };
}) {
    const href = usePayablesHref();
    const go = (changes: Record<string, string>) =>
        router.get(href('/payables/documents'), { ...filters, ...changes });

    return (
        <>
            <Head title="Documentos por pagar" />
            <Shell>
                <PageHeader
                    title="Documentos por pagar"
                    subtitle="Compras aprobadas con saldo; el saldo se deriva de pagos, notas de crédito, anticipos aplicados y ajustes aprobados"
                />
                <Card
                    title={`${documents.length} documento(s)`}
                    action={
                        <select
                            aria-label="Empresa"
                            className={`${input} h-8 text-xs`}
                            value={filters.company_id ?? ''}
                            onChange={(event) =>
                                go({ company_id: event.target.value })
                            }
                        >
                            <option value="">Todas las empresas</option>
                            {context.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    }
                >
                    <FilterChips
                        options={[
                            ['open', 'Con saldo'],
                            ['overdue', 'Vencidos'],
                            ['upcoming', 'Por vencer (15 días)'],
                            ['disputed', 'En disputa'],
                            ['all', 'Todos'],
                        ]}
                        current={filters.status ?? 'open'}
                        onSelect={(status) => go({ status })}
                    />
                    <DocumentsTable
                        documents={documents}
                        empty="No existen documentos con este filtro."
                    />
                </Card>
            </Shell>
        </>
    );
}
