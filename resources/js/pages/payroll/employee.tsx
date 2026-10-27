import { Head, Link, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { EmployeeForm } from '@/components/payroll/employee-form';
import {
    amount,
    resultLabel,
    resultTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    PayrollContext,
    PayrollPermissions,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import type { Employee } from './employees';

type Result = {
    period_id: number;
    period: string;
    period_status: string;
    days_worked: number;
    gross_cents: number | null;
    deductions_cents: number | null;
    net_cents: number | null;
    status: string;
};

type Loan = {
    id: number;
    kind_label: string;
    description: string;
    principal_cents: number;
    installment_cents: number;
    balance_cents: number;
    status_label: string;
};

const cents = (value: number | null) =>
    value === null ? '' : (value / 100).toFixed(2);

export default function PayrollEmployee({
    employee,
    results,
    loans,
    permissions,
    notice,
}: {
    context: PayrollContext;
    employee: Employee;
    results: Result[];
    loans: Loan[];
    permissions: PayrollPermissions;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const status = useForm({
        status: employee.status,
        termination_date: employee.termination_date ?? '',
    });
    const canEdit = permissions.canManageEmployees && employee.salary_visible;

    return (
        <>
            <Head title={`${employee.name} · Nómina`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={employee.name}
                    subtitle={`${employee.code} · ${employee.company} · ${employee.position ?? 'Sin puesto'}`}
                    actions={
                        <Link
                            href={href('/payroll/employees')}
                            className={secondaryButton}
                        >
                            Volver
                        </Link>
                    }
                />
                <div className="grid gap-3 md:grid-cols-4">
                    <Card title="Salario base">
                        <p className="text-lg font-black">
                            {employee.salary_visible
                                ? amount(employee.base_salary_cents)
                                : 'Reservado'}
                        </p>
                        {employee.salary_visible && (
                            <p className="text-xs text-[#526b92]">
                                Bonificación incentivo{' '}
                                {amount(employee.incentive_bonus_cents)}
                            </p>
                        )}
                    </Card>
                    <Card title="Ingreso">
                        <p className="text-lg font-black">
                            {formatDate(employee.hire_date)}
                        </p>
                        {employee.termination_date && (
                            <p className="text-xs text-red-700">
                                Baja {formatDate(employee.termination_date)}
                            </p>
                        )}
                    </Card>
                    <Card title="IGSS">
                        <p className="text-lg font-black">
                            {employee.igss_affiliated
                                ? (employee.igss_number ?? 'Sin número')
                                : 'No afiliado'}
                        </p>
                    </Card>
                    <Card title="Forma de pago">
                        <p className="text-lg font-black">
                            {employee.payment_method_label}
                        </p>
                        <p className="text-xs text-[#526b92]">
                            {employee.bank_name ?? ''}{' '}
                            {employee.bank_account ?? ''}
                        </p>
                    </Card>
                </div>
                {canEdit && (
                    <Card title="Editar ficha">
                        <EmployeeForm
                            initial={{
                                company_id: String(employee.company_id),
                                code: employee.code,
                                first_name: employee.first_name,
                                last_name: employee.last_name,
                                dpi: employee.dpi ?? '',
                                nit: employee.nit ?? '',
                                igss_number: employee.igss_number ?? '',
                                email: employee.email ?? '',
                                position: employee.position ?? '',
                                department: employee.department ?? '',
                                hire_date: employee.hire_date,
                                pay_frequency: employee.pay_frequency,
                                base_salary: cents(employee.base_salary_cents),
                                incentive_bonus: cents(
                                    employee.incentive_bonus_cents,
                                ),
                                igss_affiliated: employee.igss_affiliated,
                                payment_method: employee.payment_method,
                                bank_name: employee.bank_name ?? '',
                                bank_account_number:
                                    employee.bank_account ?? '',
                                bank_account_type:
                                    employee.bank_account_type ?? '',
                                sex: employee.sex ?? '',
                                birth_date: employee.birth_date ?? '',
                                nationality: employee.nationality ?? '',
                            }}
                            action={`/payroll/employees/${employee.id}`}
                            method="put"
                            submitLabel="Guardar cambios"
                        />
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Un cambio en la ficha envía a recálculo las nóminas
                            calculadas que aún no están aprobadas.
                        </p>
                    </Card>
                )}
                {permissions.canManageEmployees && (
                    <Card title="Estado laboral">
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                status.post(
                                    `/payroll/employees/${employee.id}/status`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <label className="grid gap-1 text-xs font-semibold">
                                Estado
                                <select
                                    className={input}
                                    value={status.data.status}
                                    onChange={(event) =>
                                        status.setData(
                                            'status',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="active">Activo</option>
                                    <option value="suspended">
                                        Suspendido
                                    </option>
                                    <option value="terminated">
                                        Dado de baja
                                    </option>
                                </select>
                            </label>
                            {status.data.status === 'terminated' && (
                                <label className="grid gap-1 text-xs font-semibold">
                                    Fecha de baja
                                    <input
                                        type="date"
                                        className={input}
                                        value={status.data.termination_date}
                                        onChange={(event) =>
                                            status.setData(
                                                'termination_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            )}
                            <button
                                type="submit"
                                className={secondaryButton}
                                disabled={status.processing}
                            >
                                Actualizar estado
                            </button>
                        </form>
                    </Card>
                )}
                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Historial de nóminas">
                        <Table
                            headers={[
                                'Nómina',
                                'Días',
                                'Devengado',
                                'Descuentos',
                                'Líquido',
                                'Cálculo',
                            ]}
                            empty={
                                results.length === 0 &&
                                'Aún no hay nóminas calculadas para este empleado.'
                            }
                        >
                            {results.map((result) => (
                                <tr key={result.period_id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(
                                                `/payroll/periods/${result.period_id}`,
                                            )}
                                            className="hover:text-[#640000]"
                                        >
                                            {result.period}
                                        </Link>
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {result.period_status}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {result.days_worked}
                                    </td>
                                    <td className="px-2 py-2">
                                        {result.gross_cents === null
                                            ? 'Reservado'
                                            : amount(result.gross_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {result.deductions_cents === null
                                            ? 'Reservado'
                                            : amount(result.deductions_cents)}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {result.net_cents === null
                                            ? 'Reservado'
                                            : amount(result.net_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                resultTone[result.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {resultLabel[result.status] ??
                                                result.status}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Anticipos y préstamos">
                        <Table
                            headers={[
                                'Tipo',
                                'Descripción',
                                'Monto',
                                'Cuota',
                                'Saldo',
                                'Estado',
                            ]}
                            empty={
                                loans.length === 0 &&
                                'Sin anticipos ni préstamos.'
                            }
                        >
                            {loans.map((loan) => (
                                <tr key={loan.id}>
                                    <td className="px-2 py-2">
                                        {loan.kind_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {loan.description}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(loan.principal_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(loan.installment_cents)}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(loan.balance_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {loan.status_label}
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
