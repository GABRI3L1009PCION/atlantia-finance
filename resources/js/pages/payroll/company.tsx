import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import {
    amount,
    companyTone,
    periodTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    PayrollCompany,
    PayrollContext,
    PayrollPeriod,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import type { Employee } from './employees';

type Reconciliation = {
    status: string;
    status_label: string;
    control_account: string | null;
    auxiliary_cents: number | null;
    ledger_cents: number | null;
    difference_cents: number | null;
    message: string | null;
};

export default function PayrollCompany({
    company,
    summary,
    periods,
    employees,
    reconciliation,
}: {
    context: PayrollContext;
    company: PayrollCompany;
    summary: {
        period: PayrollPeriod;
        preliminary: boolean;
        days: number;
        novelties: number;
    } | null;
    periods: PayrollPeriod[];
    employees: Employee[];
    reconciliation: Reconciliation;
}) {
    const href = usePayrollHref();

    return (
        <>
            <Head title={`${company.name} · Nómina`} />
            <Shell>
                <PageHeader
                    title={company.name}
                    subtitle={`NIT ${company.tax_id} · ${company.employees} empleado(s) activo(s)`}
                    actions={
                        <span
                            className={toneBadge(
                                companyTone[company.status] ?? 'slate',
                            )}
                        >
                            {company.status_label}
                        </span>
                    }
                />
                <div className="grid gap-3 md:grid-cols-4">
                    <Card title="Período en curso">
                        <p className="text-lg font-black">
                            {summary?.period.name ?? 'Sin período'}
                        </p>
                        {summary && (
                            <p className="text-xs text-[#526b92]">
                                {summary.period.status_label} · pago{' '}
                                {formatDate(summary.period.payment_date)}
                            </p>
                        )}
                    </Card>
                    <Card title="Devengado bruto">
                        <p className="text-lg font-black">
                            {summary ? amount(summary.period.gross_cents) : '—'}
                        </p>
                        {summary?.preliminary && (
                            <p className="text-[11px] text-blue-700">
                                Preliminar
                            </p>
                        )}
                    </Card>
                    <Card title="Neto a pagar">
                        <p className="text-lg font-black text-emerald-700">
                            {summary ? amount(summary.period.net_cents) : '—'}
                        </p>
                    </Card>
                    <Card title="Nómina vs Mayor">
                        <p
                            className={`text-lg font-black ${reconciliation.status === 'matched' ? 'text-emerald-700' : reconciliation.status === 'difference' ? 'text-red-700' : ''}`}
                        >
                            {reconciliation.status_label}
                        </p>
                        <p className="text-[11px] text-[#526b92]">
                            {reconciliation.difference_cents
                                ? `Diferencia ${amount(reconciliation.difference_cents)}`
                                : (reconciliation.message ?? '')}
                        </p>
                        <Link
                            href={href(
                                `/payroll/reconciliation?company_id=${company.id}`,
                            )}
                            className="text-xs font-bold text-[#640000]"
                        >
                            Ver conciliación
                        </Link>
                    </Card>
                </div>
                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Períodos de nómina">
                        <Table
                            headers={[
                                'Período',
                                'Pago',
                                'Empleados',
                                'Neto',
                                'Estado',
                            ]}
                            empty={
                                periods.length === 0 &&
                                'Aún no hay períodos de nómina.'
                            }
                        >
                            {periods.map((period) => (
                                <tr key={period.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(period.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {period.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(period.payment_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {period.employees_count}
                                    </td>
                                    <td className="px-2 py-2">
                                        {period.calculated_at
                                            ? amount(period.net_cents)
                                            : 'Sin calcular'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                periodTone[period.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {period.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Empleados">
                        <Table
                            headers={[
                                'Empleado',
                                'Puesto',
                                'Ingreso',
                                'Estado',
                            ]}
                            empty={
                                employees.length === 0 &&
                                'La empresa aún no tiene empleados registrados.'
                            }
                        >
                            {employees.map((employee) => (
                                <tr key={employee.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(
                                                `/payroll/employees/${employee.id}`,
                                            )}
                                            className="hover:text-[#640000]"
                                        >
                                            {employee.name}
                                        </Link>
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {employee.code}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {employee.position ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(employee.hire_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {employee.status_label}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
            </Shell>
        </>
    );
}
