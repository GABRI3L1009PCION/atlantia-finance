import { Head } from '@inertiajs/react';
import type { AccountantContext } from '@/components/accountant/kit';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type File = {
    id: number;
    category: string;
    name: string;
    company: string;
    received_at: string;
    status: string;
    status_label: string;
};
type Stat = {
    type: string;
    received: number;
    processed: number;
    pending: number;
    rejected: number;
};

const tone = (status: string) =>
    (
        ({
            processed: 'green',
            duplicate: 'violet',
            rejected: 'red',
        }) as Record<string, string>
    )[status] ?? 'amber';

export default function AccountantDocuments({
    context,
    files,
    stats,
}: {
    context: AccountantContext;
    files: File[];
    stats: Stat[];
}) {
    return (
        <>
            <Head title="Documentos" />
            <Shell>
                <PageHeader
                    title="Documentos"
                    subtitle={`Documentos recibidos y pendientes de tus empresas · ${context.period?.name ?? 'Sin período'}`}
                />
                <section className="grid gap-3 md:grid-cols-5">
                    {stats.map((row) => (
                        <div
                            key={row.type}
                            className="rounded-xl border border-[#e7e1dd] bg-white p-3 text-sm"
                        >
                            <p className="font-bold">{row.type}</p>
                            <p className="text-2xl font-black">
                                {row.received}
                            </p>
                            <p className="text-xs text-[#526b92]">
                                Procesados {row.processed} · Pendientes{' '}
                                {row.pending} · Rechazados {row.rejected}
                            </p>
                        </div>
                    ))}
                </section>
                <Card title={`${files.length} documento(s)`}>
                    <Table
                        headers={[
                            'Documento',
                            'Empresa',
                            'Clasificación',
                            'Recibido',
                            'Estado',
                        ]}
                        empty={files.length === 0 && 'Sin documentos cargados.'}
                    >
                        {files.map((file) => (
                            <tr key={`${file.category}-${file.id}`}>
                                <td className="px-2 py-2">{file.name}</td>
                                <td className="px-2 py-2">{file.company}</td>
                                <td className="px-2 py-2">{file.category}</td>
                                <td className="px-2 py-2">
                                    {formatDate(file.received_at)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(tone(file.status))}
                                    >
                                        {file.status_label}
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
