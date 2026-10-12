import { useState } from 'react';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import { input } from '@/components/office-admin/kit';
import { money } from '@/components/supervisor/kit';

type FelDocument = {
    id: number;
    issued_on: string;
    type: string;
    number: string;
    authorization: string | null;
    receiver: string;
    currency: string;
    total_cents: number;
    status: string;
    status_label: string;
    cancellation_reason: string | null;
    download: string | null;
};

export default function ClientFel({ documents }: { documents: FelDocument[] }) {
    const [search, setSearch] = useState('');
    const term = search.trim().toLowerCase();
    const visible = documents.filter(
        (document) =>
            !term ||
            document.number.toLowerCase().includes(term) ||
            document.receiver.toLowerCase().includes(term),
    );

    return (
        <ClientPage
            title="Facturación FEL"
            subtitle="Documentos electrónicos certificados de tu empresa"
        >
            <Card
                title={`${visible.length} documento(s)`}
                action={
                    <input
                        className={`${input} h-8 w-64`}
                        placeholder="Buscar número o receptor"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                }
            >
                <Table
                    headers={[
                        'Fecha',
                        'Tipo',
                        'Número',
                        'Receptor',
                        'Total',
                        'Estado',
                        '',
                    ]}
                    empty={
                        visible.length === 0 &&
                        'No hay documentos FEL certificados.'
                    }
                >
                    {visible.map((document) => (
                        <tr key={document.id}>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(document.issued_on)}
                            </td>
                            <td className="px-2 py-2">{document.type}</td>
                            <td className="px-2 py-2 font-bold">
                                {document.number}
                                {document.authorization && (
                                    <span className="block font-normal text-[#526b92]">
                                        {document.authorization}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">{document.receiver}</td>
                            <td className="px-2 py-2 text-right whitespace-nowrap">
                                {document.currency === 'GTQ'
                                    ? money(document.total_cents)
                                    : `${document.currency} ${(document.total_cents / 100).toFixed(2)}`}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        document.status === 'certified'
                                            ? 'green'
                                            : 'slate',
                                    )}
                                >
                                    {document.status_label}
                                </span>
                                {document.cancellation_reason && (
                                    <span className="block text-[#526b92]">
                                        {document.cancellation_reason}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {document.download && (
                                    <a
                                        href={document.download}
                                        className="font-bold text-[#640000]"
                                    >
                                        PDF
                                    </a>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </ClientPage>
    );
}
