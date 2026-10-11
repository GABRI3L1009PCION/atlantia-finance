import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { PeriodPicker, usePayrollHref } from '@/components/payroll/kit';
import type {
    EmployeeResult,
    PayrollContext,
    PayrollPeriod,
} from '@/components/payroll/kit';
import { ResultsTable } from '@/components/payroll/results-table';
import { Card } from '@/components/supervisor/kit';

export default function PayrollCalculation({
    periods,
    periodId,
    results,
}: {
    context: PayrollContext;
    periods: PayrollPeriod[];
    periodId: number | null;
    results: EmployeeResult[];
}) {
    const href = usePayrollHref();
    const period = periods.find((row) => row.id === periodId);

    return (
        <>
            <Head title="Cálculo de nómina" />
            <Shell>
                <PageHeader
                    title="Cálculo de nómina"
                    subtitle="Resultado guardado por empleado: conceptos, IGSS, ISR proyectado, aportes y validaciones"
                />
                <Card title="Período">
                    <div className="flex flex-wrap items-end gap-2">
                        <PeriodPicker
                            periods={periods}
                            periodId={periodId}
                            path="/payroll/calculation"
                        />
                        {period && (
                            <Link
                                href={href(period.destination)}
                                className={secondaryButton}
                            >
                                Calcular o recalcular en el período
                            </Link>
                        )}
                    </div>
                    {period?.needs_recalculation && (
                        <p className="mt-2 text-xs font-semibold text-amber-700">
                            Este cálculo está desactualizado:{' '}
                            {period.recalculation_reason}
                        </p>
                    )}
                </Card>
                <Card title={`Resultado (${results.length})`}>
                    <ResultsTable results={results} />
                </Card>
            </Shell>
        </>
    );
}
