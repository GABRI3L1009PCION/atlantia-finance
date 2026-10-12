import { Head } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import type { Payable, PayablesContext } from '@/components/payables/kit';
import { DocumentsTable } from '@/components/payables/tables';
import { Card } from '@/components/supervisor/kit';

export default function PayablesOverdue({
    rows,
}: {
    context: PayablesContext;
    rows: Payable[];
}) {
    return (
        <>
            <Head title="Obligaciones vencidas" />
            <Shell>
                <PageHeader
                    title="Obligaciones vencidas"
                    subtitle="Ordenadas por días de atraso y saldo; una deuda vencida normal no bloquea el cierre, pero sí genera tareas e incidencias"
                />
                <Card title={`${rows.length} obligación(es) vencida(s)`}>
                    <DocumentsTable
                        documents={rows}
                        empty="No existen obligaciones vencidas."
                    />
                </Card>
            </Shell>
        </>
    );
}
