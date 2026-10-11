import { Head, router } from '@inertiajs/react';
import { PageHeader, Shell, input } from '@/components/office-admin/kit';
import { amount, usePayrollHref } from '@/components/payroll/kit';
import type { PayrollContext } from '@/components/payroll/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Row = {
    id: number;
    employee: string;
    company: string;
    PROV_AGUINALDO: number;
    PROV_BONO14: number;
    PROV_INDEMNIZACION: number;
    PROV_VACACIONES: number;
};

const labels: [keyof Omit<Row, 'id' | 'employee' | 'company'>, string][] = [
    ['PROV_AGUINALDO', 'Aguinaldo'],
    ['PROV_BONO14', 'Bono 14'],
    ['PROV_INDEMNIZACION', 'Indemnización'],
    ['PROV_VACACIONES', 'Vacaciones'],
];

export default function PayrollProvisions({
    context,
    companyId,
    year,
    totals,
    employees,
    salaryVisible,
}: {
    context: PayrollContext;
    companyId: number | null;
    year: number;
    totals: Record<string, number>;
    employees: Row[];
    salaryVisible: boolean;
}) {
    const href = usePayrollHref();
    const current = new Date().getFullYear();

    return (
        <>
            <Head title="Provisiones y prestaciones" />
            <Shell>
                <PageHeader
                    title="Provisiones / prestaciones"
                    subtitle="Acumulado de las nóminas aprobadas del año; las tasas provienen de los parámetros legales"
                    actions={
                        <>
                            <select
                                className={`${input} w-56`}
                                value={companyId ?? ''}
                                onChange={(event) =>
                                    router.get(href('/payroll/provisions'), {
                                        year,
                                        company_id: event.target.value,
                                    })
                                }
                                aria-label="Empresa"
                            >
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                className={`${input} w-32`}
                                value={year}
                                onChange={(event) =>
                                    router.get(href('/payroll/provisions'), {
                                        year: event.target.value,
                                        company_id: companyId ?? '',
                                    })
                                }
                                aria-label="Año"
                            >
                                {[current, current - 1, current - 2].map(
                                    (option) => (
                                        <option key={option} value={option}>
                                            {option}
                                        </option>
                                    ),
                                )}
                            </select>
                        </>
                    }
                />
                <div className="grid gap-3 md:grid-cols-4">
                    {labels.map(([key, label]) => (
                        <Card key={key} title={label}>
                            <p className="text-xl font-black">
                                {amount(totals[key] ?? 0)}
                            </p>
                        </Card>
                    ))}
                </div>
                <Card title="Por empleado">
                    {!salaryVisible ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            El detalle por empleado está reservado para tu rol.
                        </p>
                    ) : (
                        <Table
                            headers={[
                                'Empleado',
                                'Empresa',
                                ...labels.map(([, label]) => label),
                                'Total',
                            ]}
                            empty={
                                employees.length === 0 &&
                                `No hay nóminas aprobadas en ${year}.`
                            }
                        >
                            {employees.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {row.employee}
                                    </td>
                                    <td className="px-2 py-2">{row.company}</td>
                                    {labels.map(([key]) => (
                                        <td key={key} className="px-2 py-2">
                                            {amount(row[key])}
                                        </td>
                                    ))}
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(
                                            labels.reduce(
                                                (sum, [key]) => sum + row[key],
                                                0,
                                            ),
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    )}
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        Montos de la empresa seleccionada; Atlantia no suma
                        provisiones de empresas distintas.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
