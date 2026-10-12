import { Head, Link } from '@inertiajs/react';
import { reportTone, useAuditorHref } from '@/components/auditor/kit';
import type {
    AuditorContext,
    AuditorPermissions,
    Report,
} from '@/components/auditor/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

const exports: [string, string, string][] = [
    [
        'engagements',
        'Auditorías por empresa',
        'Avance, hallazgos, evidencia pendiente, estado y fechas.',
    ],
    [
        'procedures',
        'Procedimientos pendientes y vencidos',
        'Responsable, fecha límite y estado.',
    ],
    [
        'evidence',
        'Evidencia pendiente y vencida',
        'Solicitudes abiertas por responsable.',
    ],
    [
        'findings',
        'Hallazgos por severidad y área',
        'Estado, responsable, días abiertos y vencimiento.',
    ],
    ['risks', 'Riesgos', 'Riesgos identificados por auditoría.'],
    [
        'controls',
        'Controles',
        'Evaluación de diseño, existencia, ejecución y efectividad.',
    ],
];

export default function AuditorReports({
    reports,
    permissions,
}: {
    context: AuditorContext;
    reports: Report[];
    permissions: AuditorPermissions;
}) {
    const href = useAuditorHref();

    return (
        <>
            <Head title="Reportes de auditoría" />
            <Shell>
                <PageHeader
                    title="Reportes de auditoría"
                    subtitle="Informes versionados de cada auditoría y reportes de gestión exportables"
                />
                <Card title={`Informes de auditoría (${reports.length})`}>
                    <Table
                        headers={[
                            'Auditoría',
                            'Empresa',
                            'Versión',
                            'Estado',
                            'Generó',
                            'Aprobó',
                            'Emitió',
                            'Destinatarios',
                            '',
                        ]}
                        empty={
                            reports.length === 0 &&
                            'Aún no hay informes de auditoría.'
                        }
                    >
                        {reports.map((report) => (
                            <tr key={report.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(
                                            `/work/audit/engagements/${report.engagement_id}?tab=report`,
                                        )}
                                        className="text-blue-700 hover:underline"
                                    >
                                        {report.engagement}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{report.company}</td>
                                <td className="px-2 py-2">v{report.version}</td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            reportTone[report.status] ??
                                                'slate',
                                        )}
                                    >
                                        {report.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {report.generated_by} ·{' '}
                                    {formatDate(report.generated_at)}
                                </td>
                                <td className="px-2 py-2">
                                    {report.reviewed_by ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {report.issued_by
                                        ? `${report.issued_by} · ${formatDate(report.issued_at)}`
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {report.recipients ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={report.download}
                                        className="text-xs font-bold text-[#640000]"
                                    >
                                        PDF
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                {permissions.canExport && (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {exports.map(([key, title, description]) => (
                            <Card key={key} title={title}>
                                <p className="mb-3 text-xs text-[#526b92]">
                                    {description}
                                </p>
                                <div className="flex gap-2">
                                    <a
                                        href={`/work/audit/exports/${key}?format=xlsx`}
                                        className={secondaryButton}
                                    >
                                        Excel
                                    </a>
                                    <a
                                        href={`/work/audit/exports/${key}?format=pdf`}
                                        className={secondaryButton}
                                    >
                                        PDF
                                    </a>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </Shell>
        </>
    );
}
