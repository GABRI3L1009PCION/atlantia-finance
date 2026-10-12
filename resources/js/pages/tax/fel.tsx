import { Head, Link, usePage } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import { felTone, useTaxHref } from '@/components/tax/kit';
import type { FelDocument, TaxContext } from '@/components/tax/kit';

export default function TaxFel({
    documents,
}: {
    context: TaxContext;
    documents: FelDocument[];
}) {
    const href = useTaxHref();
    const all =
        new URL(usePage().url, 'https://atlantia.local').searchParams.get(
            'all',
        ) === '1';

    return (
        <>
            <Head title="Facturación electrónica" />
            <Shell>
                <PageHeader
                    title="Facturación electrónica"
                    subtitle="Documentos del módulo FEL; la certificación nunca se edita a mano, se corrige y reintenta desde el documento"
                    actions={
                        <Link
                            href={href(all ? '/tax/fel' : '/tax/fel?all=1')}
                            className={secondaryButton}
                        >
                            {all ? 'Solo incidencias' : 'Ver todos'}
                        </Link>
                    }
                />
                <Card title={`${documents.length} documento(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'DTE',
                            'Documento',
                            'Fecha',
                            'Receptor',
                            'Total',
                            'Ambiente',
                            'Autorización',
                            'Estado',
                            '',
                        ]}
                        empty={
                            documents.length === 0 &&
                            'No existen incidencias FEL que requieran atención.'
                        }
                    >
                        {documents.map((document) => (
                            <tr key={document.id}>
                                <td className="px-2 py-2 font-bold">
                                    {document.company}
                                </td>
                                <td className="px-2 py-2">{document.type}</td>
                                <td className="px-2 py-2">
                                    {document.document}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(document.issued_on)}
                                </td>
                                <td className="px-2 py-2">
                                    {document.receiver}
                                </td>
                                <td className="px-2 py-2">
                                    {money(document.total_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            document.environment ===
                                                'production'
                                                ? 'blue'
                                                : 'slate',
                                        )}
                                    >
                                        {document.environment === 'production'
                                            ? 'Producción'
                                            : 'Pruebas'}
                                    </span>
                                </td>
                                <td className="px-2 py-2 font-mono text-[11px]">
                                    {document.authorization ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            felTone[document.status] ?? 'slate',
                                        )}
                                    >
                                        {document.status_label}
                                    </span>
                                    {document.error_code && (
                                        <p className="text-[11px] text-red-700">
                                            {document.error_code}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={document.destination}
                                        className={secondaryButton}
                                    >
                                        Abrir
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
