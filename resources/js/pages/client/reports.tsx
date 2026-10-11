import type { PublishedReport } from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import { secondaryButton } from '@/components/office-admin/kit';

export default function ClientReports({
    reports,
}: {
    reports: PublishedReport[];
}) {
    return (
        <ClientPage
            title="Reportes y estados"
            subtitle="Reportes que tu oficina contable aprobó y publicó para ti"
        >
            <Card title={`${reports.length} reporte(s) publicado(s)`}>
                <Table
                    headers={['Reporte', 'Período', 'Versión', 'Publicado', '']}
                    empty={
                        reports.length === 0 &&
                        'Tu oficina aún no ha publicado reportes.'
                    }
                >
                    {reports.map((report) => (
                        <tr
                            key={report.id}
                            className={report.replaced ? 'opacity-60' : ''}
                        >
                            <td className="px-2 py-2 font-bold">
                                {report.title}
                            </td>
                            <td className="px-2 py-2">{report.period}</td>
                            <td className="px-2 py-2">
                                v{report.version}{' '}
                                {report.replaced && (
                                    <span className={toneBadge('slate')}>
                                        Reemplazado por una versión más reciente
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {formatDate(report.published_at)}
                            </td>
                            <td className="px-2 py-2">
                                {report.downloadable && (
                                    <a
                                        href={report.destination}
                                        className={secondaryButton}
                                    >
                                        Descargar PDF
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
