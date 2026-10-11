import { Head, router } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { FilterChips, usePayablesHref } from '@/components/payables/kit';
import type { Payable, PayablesContext } from '@/components/payables/kit';
import { DocumentsTable } from '@/components/payables/tables';
import { Card } from '@/components/supervisor/kit';

export default function PayablesDueDates({
    rows,
    days,
}: {
    context: PayablesContext;
    rows: Payable[];
    days: number;
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Vencimientos" />
            <Shell>
                <PageHeader
                    title="Vencimientos"
                    subtitle="Obligaciones vencidas y por vencer, con la fecha de la zona horaria de cada empresa"
                />
                <Card title={`${rows.length} documento(s)`}>
                    <FilterChips
                        options={[
                            ['7', '7 días'],
                            ['15', '15 días'],
                            ['30', '30 días'],
                            ['60', '60 días'],
                        ]}
                        current={String(days)}
                        onSelect={(value) =>
                            router.get(href('/payables/due-dates'), {
                                days: value,
                            })
                        }
                    />
                    <DocumentsTable
                        documents={rows}
                        empty="No hay vencimientos en este horizonte."
                    />
                </Card>
            </Shell>
        </>
    );
}
