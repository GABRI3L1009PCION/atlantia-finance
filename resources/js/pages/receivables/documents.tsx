import { Head, router } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { useReceivablesHref } from '@/components/receivables/kit';
import type {
    Receivable,
    ReceivablesContext,
} from '@/components/receivables/kit';
import { DocumentsTable } from '@/components/receivables/tables';
import { Card } from '@/components/supervisor/kit';

const statuses: [string, string][] = [
    ['open', 'Abiertos'],
    ['overdue', 'Vencidos'],
    ['upcoming', 'Por vencer'],
    ['disputed', 'En disputa'],
    ['all', 'Todos'],
];

export default function ReceivablesDocuments({
    context,
    documents,
    filters,
}: {
    context: ReceivablesContext;
    documents: Receivable[];
    filters: { company_id?: string; status?: string };
}) {
    const href = useReceivablesHref();
    const status = filters.status ?? 'open';
    const filter = (changes: Record<string, string>) =>
        router.get(href('/receivables/documents'), { ...filters, ...changes });

    return (
        <>
            <Head title="Documentos por cobrar" />
            <Shell>
                <PageHeader
                    title="Documentos por cobrar"
                    subtitle="Facturas emitidas en Ventas con su saldo pendiente; CxC no crea facturas"
                />
                <Card title={`${documents.length} documento(s)`}>
                    <div className="mb-3 flex flex-wrap items-center gap-1.5">
                        {statuses.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => filter({ status: key })}
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${status === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                        <select
                            aria-label="Empresa"
                            className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                            value={filters.company_id ?? ''}
                            onChange={(event) =>
                                filter({ company_id: event.target.value })
                            }
                        >
                            <option value="">Todas las empresas</option>
                            {context.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <DocumentsTable
                        documents={documents}
                        empty={
                            status === 'overdue'
                                ? 'No existen documentos vencidos.'
                                : 'No existen cuentas por cobrar abiertas.'
                        }
                    />
                </Card>
            </Shell>
        </>
    );
}
