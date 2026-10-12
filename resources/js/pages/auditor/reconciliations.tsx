import { Head, Link } from '@inertiajs/react';
import { ReviewFilter, amount, useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext, Engagement } from '@/components/auditor/kit';
import { AskLuzmaButton } from '@/components/luzma/luzma-button';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export type ReconciliationRow = {
    key: string;
    area: string;
    name: string;
    auxiliary_cents: number | null;
    ledger_cents: number | null;
    difference_cents: number | null;
    items: number;
    status: string;
    message: string | null;
    finding_source: string | null;
    destination: string | null;
};

const statusTone: Record<string, [string, string]> = {
    matched: ['Cuadra', 'green'],
    difference: ['Con diferencia', 'red'],
    not_applicable: ['No aplica', 'slate'],
    error: ['No fue posible verificar', 'violet'],
};

/** Read-only: the auditor investigates and documents; there is no way to square a difference here. */
export function ReconciliationTable({
    rows,
    engagements,
}: {
    rows: ReconciliationRow[];
    engagements: Engagement[];
}) {
    const href = useAuditorHref();
    const engagement = engagements[0];

    return (
        <Table
            headers={[
                'Conciliación',
                'Cuenta / auxiliar',
                'Auxiliar',
                'Mayor',
                'Diferencia',
                'Partidas',
                'Estado',
                'Investigar',
            ]}
            empty={rows.length === 0 && 'Sin conciliaciones para la empresa.'}
        >
            {rows.map((row) => {
                const [label, tone] = statusTone[row.status] ?? [
                    row.status,
                    'slate',
                ];

                return (
                    <tr key={row.key}>
                        <td className="px-2 py-2 font-bold">{row.area}</td>
                        <td className="px-2 py-2">{row.name}</td>
                        <td className="px-2 py-2 text-right">
                            {amount(row.auxiliary_cents)}
                        </td>
                        <td className="px-2 py-2 text-right">
                            {amount(row.ledger_cents)}
                        </td>
                        <td
                            className={`px-2 py-2 text-right font-semibold ${row.difference_cents ? 'text-red-700' : ''}`}
                        >
                            {row.status === 'error'
                                ? 'No disponible'
                                : amount(row.difference_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {row.items}
                            {row.message && (
                                <p className="text-[11px] text-[#526b92]">
                                    {row.message}
                                </p>
                            )}
                        </td>
                        <td className="px-2 py-2">
                            <span className={toneBadge(tone)}>{label}</span>
                        </td>
                        <td className="px-2 py-2 text-[11px] whitespace-nowrap">
                            {row.destination && (
                                <a
                                    href={row.destination}
                                    className="mr-2 font-bold text-blue-700"
                                >
                                    Ver origen
                                </a>
                            )}
                            {row.status === 'difference' && engagement && (
                                <Link
                                    href={href(
                                        `/work/audit/engagements/${engagement.id}?tab=findings`,
                                    )}
                                    className="font-bold text-[#640000]"
                                >
                                    Documentar en {engagement.code}
                                </Link>
                            )}
                        </td>
                    </tr>
                );
            })}
        </Table>
    );
}

export default function AuditorReconciliations({
    companyId,
    cutoff,
    rows,
    engagements,
}: {
    context: AuditorContext;
    companyId: number | null;
    cutoff: string;
    rows: ReconciliationRow[];
    engagements: Engagement[];
}) {
    return (
        <>
            <Head title="Conciliaciones" />
            <Shell>
                <PageHeader
                    title="Conciliaciones"
                    subtitle="Bancos, CxC, CxP y Nómina contra el Mayor; se recalculan en cada consulta y no existe un botón para cuadrar"
                />
                <Card title="Empresa y corte">
                    <ReviewFilter
                        path="/work/audit/reconciliations"
                        companyId={companyId}
                        to={cutoff}
                    />
                </Card>
                <Card
                    title="Conciliaciones"
                    action={
                        rows.some((row) => row.status === 'difference') && (
                            <AskLuzmaButton
                                question="¿Qué conciliaciones tienen diferencia y por qué?"
                                label="Investigar con Luzma"
                            />
                        )
                    }
                >
                    <ReconciliationTable
                        rows={rows}
                        engagements={engagements}
                    />
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        Para registrar una diferencia como hallazgo usa la
                        pestaña de hallazgos de la auditoría y vincula la
                        conciliación: la corrección se comprobará en el módulo
                        de origen.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
