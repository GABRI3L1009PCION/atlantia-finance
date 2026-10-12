import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    IssueList,
    PeriodPicker,
    amount,
    resultLabel,
    resultTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    Issue,
    PayrollContext,
    PayrollPeriod,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Preview = {
    period_issues: Issue[];
    totals: {
        employees: number;
        gross_cents: number;
        deductions_cents: number;
        net_cents: number;
        employer_cents: number;
        provisions_cents: number;
    };
    results: {
        employee: string;
        code: string;
        days_worked: number;
        status: string;
        issues: Issue[];
        gross_cents: number | null;
        deductions_cents: number | null;
        net_cents: number | null;
    }[];
};

export default function PayrollPrePayroll({
    periods,
    periodId,
    preview,
    error,
}: {
    context: PayrollContext;
    periods: PayrollPeriod[];
    periodId: number | null;
    preview: Preview | null;
    error: string | null;
}) {
    const href = usePayrollHref();
    const errors =
        preview?.results.filter((row) => row.status === 'error').length ?? 0;
    const warnings =
        preview?.results.filter((row) => row.status === 'warning').length ?? 0;

    return (
        <>
            <Head title="Pre-nómina" />
            <Shell>
                <PageHeader
                    title="Pre-nómina"
                    subtitle="Cálculo preliminar sin guardar: valida datos y novedades antes de calcular"
                />
                <Card title="Período">
                    <div className="flex flex-wrap items-end gap-2">
                        <PeriodPicker
                            periods={periods}
                            periodId={periodId}
                            path="/payroll/pre-payroll"
                        />
                        {periodId && (
                            <Link
                                href={href(`/payroll/periods/${periodId}`)}
                                className={secondaryButton}
                            >
                                Ir al período
                            </Link>
                        )}
                    </div>
                </Card>
                {error && (
                    <p className="rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm font-semibold text-violet-800">
                        {error}
                    </p>
                )}
                {preview && (
                    <>
                        <div className="grid gap-3 md:grid-cols-5">
                            <Card title="Empleados">
                                <p className="text-xl font-black">
                                    {preview.totals.employees}
                                </p>
                            </Card>
                            <Card title="Devengado">
                                <p className="text-xl font-black">
                                    {amount(preview.totals.gross_cents)}
                                </p>
                            </Card>
                            <Card title="Deducciones">
                                <p className="text-xl font-black text-red-700">
                                    {amount(preview.totals.deductions_cents)}
                                </p>
                            </Card>
                            <Card title="Neto">
                                <p className="text-xl font-black text-emerald-700">
                                    {amount(preview.totals.net_cents)}
                                </p>
                            </Card>
                            <Card title="Validaciones">
                                <p
                                    className={`text-xl font-black ${errors > 0 ? 'text-red-700' : warnings > 0 ? 'text-amber-700' : 'text-emerald-700'}`}
                                >
                                    {errors > 0
                                        ? `${errors} con errores`
                                        : warnings > 0
                                          ? `${warnings} con advertencias`
                                          : 'Listo para calcular'}
                                </p>
                            </Card>
                        </div>
                        {preview.period_issues.length > 0 && (
                            <Card title="Observaciones del período">
                                <IssueList issues={preview.period_issues} />
                            </Card>
                        )}
                        <Card title="Detalle preliminar">
                            <Table
                                headers={[
                                    'Empleado',
                                    'Días',
                                    'Devengado',
                                    'Descuentos',
                                    'Líquido',
                                    'Validación',
                                ]}
                                empty={
                                    preview.results.length === 0 &&
                                    'No hay empleados que laboren en el período.'
                                }
                            >
                                {preview.results.map((row) => (
                                    <tr key={row.code}>
                                        <td className="px-2 py-2 font-bold">
                                            {row.employee}
                                            <p className="text-[11px] font-normal text-[#526b92]">
                                                {row.code}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.days_worked}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.gross_cents === null
                                                ? 'Reservado'
                                                : amount(row.gross_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.deductions_cents === null
                                                ? 'Reservado'
                                                : amount(row.deductions_cents)}
                                        </td>
                                        <td className="px-2 py-2 font-semibold">
                                            {row.net_cents === null
                                                ? 'Reservado'
                                                : amount(row.net_cents)}
                                        </td>
                                        <td className="max-w-96 px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    resultTone[row.status] ??
                                                        'slate',
                                                )}
                                            >
                                                {resultLabel[row.status] ??
                                                    row.status}
                                            </span>
                                            {row.issues.length > 0 && (
                                                <div className="mt-1 text-[11px]">
                                                    <IssueList
                                                        issues={row.issues}
                                                    />
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    </>
                )}
            </Shell>
        </>
    );
}
