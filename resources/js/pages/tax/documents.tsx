import { Head, Link } from '@inertiajs/react';
import { Send } from 'lucide-react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import type {
    FiscalDocument,
    TaxContext,
    TaxPermissions,
} from '@/components/tax/kit';

export default function TaxDocuments({
    context,
    documents,
    permissions,
}: {
    context: TaxContext;
    documents: FiscalDocument[];
    permissions: TaxPermissions;
}) {
    return (
        <>
            <Head title="Documentos fiscales" />
            <Shell>
                <PageHeader
                    title="Documentos fiscales"
                    subtitle={`Documentos solicitados sin recibir y compras sin soporte · ${context.period?.name ?? 'Sin período'}`}
                    actions={
                        permissions.canRequestDocuments && (
                            <Link
                                href="/communication"
                                className={secondaryButton}
                            >
                                <Send className="size-4" /> Solicitar documento
                            </Link>
                        )
                    }
                />
                <Card title={`${documents.length} pendiente(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Tipo',
                            'Descripción',
                            'Origen',
                            'Fecha',
                            'Estado',
                            '',
                        ]}
                        empty={
                            documents.length === 0 &&
                            'No existen documentos fiscales pendientes.'
                        }
                    >
                        {documents.map((document) => (
                            <tr key={document.key}>
                                <td className="px-2 py-2 font-bold">
                                    {document.company}
                                </td>
                                <td className="px-2 py-2">{document.type}</td>
                                <td className="max-w-md px-2 py-2">
                                    {document.description}
                                </td>
                                <td className="px-2 py-2">{document.source}</td>
                                <td className="px-2 py-2">
                                    {formatDate(document.date)}
                                </td>
                                <td className="px-2 py-2">
                                    <span className={toneBadge('amber')}>
                                        {document.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={document.destination}
                                        className={secondaryButton}
                                    >
                                        Ir al origen
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
