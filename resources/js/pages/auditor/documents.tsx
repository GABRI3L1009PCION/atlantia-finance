import { Head } from '@inertiajs/react';
import { ReviewFilter, amount } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Document = {
    type: string;
    kind: string;
    id: number;
    date: string;
    party: string | null;
    reference: string;
    amount_cents: number | null;
    status: string;
    trace: string;
};

export default function AuditorDocuments({
    companyId,
    from,
    to,
    search,
    documents,
}: {
    context: AuditorContext;
    companyId: number | null;
    from: string;
    to: string;
    search: string;
    documents: Document[];
}) {
    return (
        <>
            <Head title="Documentos soporte" />
            <Shell>
                <PageHeader
                    title="Documentos soporte"
                    subtitle="Búsqueda transversal de documentos dentro del alcance autorizado; los documentos originales no se modifican"
                />
                <Card title="Empresa, período y búsqueda">
                    <ReviewFilter
                        path="/work/audit/supporting-documents"
                        companyId={companyId}
                        from={from}
                        to={to}
                        extra={{ search }}
                    />
                </Card>
                <Card title={`${documents.length} documento(s)`}>
                    <Table
                        headers={[
                            'Tipo',
                            'Fecha',
                            'Tercero',
                            'Referencia',
                            'Importe',
                            'Estado',
                            '',
                        ]}
                        empty={
                            documents.length === 0 &&
                            'No se encontraron documentos en el período.'
                        }
                    >
                        {documents.map((doc) => (
                            <tr key={`${doc.type}-${doc.id}-${doc.reference}`}>
                                <td className="px-2 py-2">{doc.kind}</td>
                                <td className="px-2 py-2">
                                    {formatDate(doc.date)}
                                </td>
                                <td className="px-2 py-2">
                                    {doc.party ?? '—'}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {doc.reference}
                                </td>
                                <td className="px-2 py-2 text-right">
                                    {doc.amount_cents === null
                                        ? '—'
                                        : amount(doc.amount_cents)}
                                </td>
                                <td className="px-2 py-2">{doc.status}</td>
                                <td className="px-2 py-2">
                                    <a
                                        href={doc.trace}
                                        className="text-xs font-bold text-[#640000]"
                                    >
                                        Trazar
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
