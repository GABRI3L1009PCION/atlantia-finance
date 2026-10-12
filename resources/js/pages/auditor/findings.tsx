import { Head, Link, router } from '@inertiajs/react';
import {
    findingTone,
    severityTone,
    useAuditorHref,
} from '@/components/auditor/kit';
import type {
    AuditorContext,
    AuditorPermissions,
    Finding,
} from '@/components/auditor/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

const tabs: [string, string][] = [
    ['all', 'Todos'],
    ['open', 'Abiertos'],
    ['awaiting_response', 'Esperando respuesta'],
    ['remediation', 'En corrección'],
    ['to_verify', 'Por verificar'],
    ['overdue', 'Vencidos'],
    ['closed', 'Cerrados'],
    ['accepted_risk', 'Riesgo aceptado'],
    ['draft', 'Borradores'],
];

export default function AuditorFindings({
    findings,
    tab,
    severity,
    levels,
    permissions,
}: {
    context: AuditorContext;
    findings: Finding[];
    tab: string;
    severity: string;
    levels: Record<string, string>;
    permissions: AuditorPermissions;
}) {
    const href = useAuditorHref();
    const go = (params: Record<string, string>) =>
        router.get(
            href('/work/audit/findings'),
            { tab, severity, ...params },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Hallazgos" />
            <Shell>
                <PageHeader
                    title="Hallazgos"
                    subtitle="Seguimiento de hallazgos: el responsable corrige en el módulo de origen y el auditor verifica antes de cerrar"
                    actions={
                        permissions.canExport && (
                            <a
                                href="/work/audit/exports/findings"
                                className={secondaryButton}
                            >
                                Exportar
                            </a>
                        )
                    }
                />
                <div className="flex flex-wrap gap-1.5">
                    {tabs.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => go({ tab: key })}
                            className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${tab === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <Card
                    title={`${findings.length} hallazgo(s)`}
                    action={
                        <select
                            aria-label="Severidad"
                            className={`${input} h-8 w-40 text-xs`}
                            value={severity}
                            onChange={(event) =>
                                go({ severity: event.target.value })
                            }
                        >
                            <option value="">Todas las severidades</option>
                            {Object.entries(levels).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    }
                >
                    <Table
                        headers={[
                            'Código',
                            'Auditoría',
                            'Empresa',
                            'Área',
                            'Título',
                            'Severidad',
                            'Responsable',
                            'Fecha compromiso',
                            'Días abierto',
                            'Estado',
                        ]}
                        empty={
                            findings.length === 0 &&
                            'No existen hallazgos abiertos.'
                        }
                    >
                        {findings.map((finding) => (
                            <tr key={finding.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(finding.destination)}
                                        className="text-blue-700 hover:underline"
                                    >
                                        {finding.code}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {finding.engagement}
                                </td>
                                <td className="px-2 py-2">{finding.company}</td>
                                <td className="px-2 py-2">
                                    {finding.area_label}
                                </td>
                                <td className="px-2 py-2">{finding.title}</td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            severityTone[finding.severity] ??
                                                'slate',
                                        )}
                                    >
                                        {finding.severity_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {finding.responsible ?? '—'}
                                </td>
                                <td
                                    className={`px-2 py-2 ${finding.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(finding.due_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {finding.days_open ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            findingTone[finding.status] ??
                                                'slate',
                                        )}
                                    >
                                        {finding.status_label}
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
