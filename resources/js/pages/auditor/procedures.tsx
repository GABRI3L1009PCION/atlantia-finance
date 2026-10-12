import { Head, Link, router } from '@inertiajs/react';
import { procedureTone, useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext, Procedure } from '@/components/auditor/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function AuditorProcedures({
    procedures,
    status,
    mine,
    statuses,
}: {
    context: AuditorContext;
    procedures: Procedure[];
    status: string;
    mine: boolean;
    statuses: Record<string, string>;
}) {
    const href = useAuditorHref();
    const go = (params: Record<string, string | boolean>) =>
        router.get(
            href('/work/audit/procedures'),
            { status, mine: mine ? 1 : 0, ...params },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Procedimientos" />
            <Shell>
                <PageHeader
                    title="Procedimientos"
                    subtitle="Procedimientos de tus auditorías: un procedimiento se completa con resultado, conclusión y su muestra probada"
                />
                <Card
                    title={`${procedures.length} procedimiento(s)`}
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                aria-label="Estado"
                                className={`${input} h-8 w-48 text-xs`}
                                value={status}
                                onChange={(event) =>
                                    go({ status: event.target.value })
                                }
                            >
                                <option value="pending">Pendientes</option>
                                {Object.entries(statuses).map(
                                    ([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <label className="flex items-center gap-1 text-xs font-semibold">
                                <input
                                    type="checkbox"
                                    checked={mine}
                                    onChange={(event) =>
                                        go({
                                            mine: event.target.checked ? 1 : 0,
                                        } as never)
                                    }
                                />
                                Solo los míos
                            </label>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Código',
                            'Auditoría',
                            'Empresa',
                            'Área',
                            'Procedimiento',
                            'Responsable',
                            'Fecha límite',
                            'Muestra',
                            'Evidencia',
                            'Resultado',
                            'Estado',
                            '',
                        ]}
                        empty={
                            procedures.length === 0 &&
                            'No existen procedimientos pendientes.'
                        }
                    >
                        {procedures.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2 font-bold">
                                    {row.code}
                                </td>
                                <td className="px-2 py-2">{row.engagement}</td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td className="px-2 py-2">{row.area_label}</td>
                                <td className="px-2 py-2">{row.title}</td>
                                <td className="px-2 py-2">
                                    {row.responsible ?? '—'}
                                </td>
                                <td
                                    className={`px-2 py-2 ${row.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(row.due_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {row.sample
                                        ? `${row.sample.count}${row.sample.exceptions > 0 ? ` · ${row.sample.exceptions} exc.` : ''}`
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {row.evidence.pending > 0
                                        ? `${row.evidence.pending} pendiente(s)`
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {row.result_label ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            procedureTone[row.status] ??
                                                'slate',
                                        )}
                                    >
                                        {row.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(row.destination)}
                                        className="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-bold"
                                    >
                                        Abrir
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
