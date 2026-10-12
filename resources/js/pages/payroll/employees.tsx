import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import {
    EmployeeForm,
    emptyEmployee,
} from '@/components/payroll/employee-form';
import { amount, usePayrollHref } from '@/components/payroll/kit';
import type {
    PayrollContext,
    PayrollPermissions,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export type Employee = {
    id: number;
    company_id: number;
    company: string;
    code: string;
    first_name: string;
    last_name: string;
    name: string;
    position: string | null;
    department: string | null;
    email: string | null;
    dpi: string | null;
    nit: string | null;
    igss_number: string | null;
    hire_date: string;
    termination_date: string | null;
    status: string;
    status_label: string;
    pay_frequency: string;
    igss_affiliated: boolean;
    payment_method: string;
    payment_method_label: string;
    bank_name: string | null;
    bank_account: string | null;
    bank_account_type: string | null;
    base_salary_cents: number | null;
    incentive_bonus_cents: number | null;
    has_bank_account: boolean;
    salary_visible: boolean;
};

const statusTone: Record<string, string> = {
    active: 'green',
    suspended: 'amber',
    terminated: 'slate',
};

export default function PayrollEmployees({
    context,
    employees,
    filters,
    permissions,
    notice,
}: {
    context: PayrollContext;
    employees: Employee[];
    filters: { company_id?: string; status?: string };
    permissions: PayrollPermissions;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const [creating, setCreating] = useState(false);
    const filter = (key: string, value: string) =>
        router.get(
            href('/payroll/employees'),
            { ...filters, [key]: value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Empleados · Nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Empleados"
                    subtitle="Fichas laborales de las empresas asignadas; salarios y cuentas completas solo con permiso"
                    actions={
                        permissions.canManageEmployees && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setCreating(!creating)}
                            >
                                {creating
                                    ? 'Cerrar formulario'
                                    : 'Nuevo empleado'}
                            </button>
                        )
                    }
                />
                {creating && (
                    <Card title="Nuevo empleado">
                        <EmployeeForm
                            initial={{
                                ...emptyEmployee,
                                company_id:
                                    filters.company_id ??
                                    (context.companies.length === 1
                                        ? String(context.companies[0].id)
                                        : ''),
                            }}
                            companies={context.companies}
                            action="/payroll/employees"
                            method="post"
                            submitLabel="Registrar empleado"
                        />
                    </Card>
                )}
                <Card
                    title={`${employees.length} empleado(s)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                className={`${input} h-8 w-48 text-xs`}
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    filter('company_id', event.target.value)
                                }
                                aria-label="Empresa"
                            >
                                <option value="">Todas las empresas</option>
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                className={`${input} h-8 w-36 text-xs`}
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    filter('status', event.target.value)
                                }
                                aria-label="Estado"
                            >
                                <option value="">Todos</option>
                                <option value="active">Activos</option>
                                <option value="suspended">Suspendidos</option>
                                <option value="terminated">
                                    Dados de baja
                                </option>
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Empleado',
                            'Empresa',
                            'Puesto',
                            'Ingreso',
                            'Salario base',
                            'Pago',
                            'IGSS',
                            'Estado',
                        ]}
                        empty={
                            employees.length === 0 &&
                            'No hay empleados con estos filtros.'
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
                                    {employee.company}
                                </td>
                                <td className="px-2 py-2">
                                    {employee.position ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(employee.hire_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {employee.salary_visible
                                        ? amount(employee.base_salary_cents)
                                        : 'Reservado'}
                                </td>
                                <td className="px-2 py-2">
                                    {employee.payment_method_label}
                                    {employee.payment_method === 'transfer' && (
                                        <p
                                            className={`text-[11px] ${employee.has_bank_account ? 'text-[#526b92]' : 'font-bold text-red-700'}`}
                                        >
                                            {employee.has_bank_account
                                                ? `${employee.bank_name ?? ''} ${employee.bank_account ?? ''}`
                                                : 'Sin cuenta bancaria'}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {employee.igss_affiliated
                                        ? (employee.igss_number ?? (
                                              <span className="text-amber-700">
                                                  Sin número
                                              </span>
                                          ))
                                        : 'No afiliado'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            statusTone[employee.status] ??
                                                'slate',
                                        )}
                                    >
                                        {employee.status_label}
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
