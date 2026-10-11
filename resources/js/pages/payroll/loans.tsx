import { Head, router, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { amount } from '@/components/payroll/kit';
import type { PayrollContext } from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Loan = {
    id: number;
    company: string;
    employee: string;
    kind: string;
    kind_label: string;
    description: string;
    principal_cents: number;
    installment_cents: number;
    balance_cents: number;
    starts_on: string;
    status: string;
    status_label: string;
};

const tone: Record<string, string> = {
    active: 'blue',
    paid: 'green',
    cancelled: 'slate',
};

export default function PayrollLoans({
    loans,
    employees,
    canManage,
    notice,
}: {
    context: PayrollContext;
    loans: Loan[];
    employees: { id: number; company_id: number; name: string }[];
    canManage: boolean;
    notice: string | null;
}) {
    const form = useForm({
        payroll_employee_id: '',
        kind: 'advance',
        principal: '',
        installment: '',
        starts_on: new Date().toISOString().slice(0, 10),
        description: '',
    });

    return (
        <>
            <Head title="Anticipos y préstamos" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Anticipos y préstamos"
                    subtitle="Se descuentan en cada nómina hasta cubrir el saldo; nunca dejan el neto en negativo"
                />
                {canManage && (
                    <Card title="Registrar anticipo o préstamo">
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/payroll/loans', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                });
                            }}
                        >
                            <label className="grid min-w-56 gap-1 text-xs font-semibold">
                                Empleado
                                <select
                                    className={input}
                                    value={form.data.payroll_employee_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'payroll_employee_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {employees.map((employee) => (
                                        <option
                                            key={employee.id}
                                            value={employee.id}
                                        >
                                            {employee.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Tipo
                                <select
                                    className={input}
                                    value={form.data.kind}
                                    onChange={(event) =>
                                        form.setData('kind', event.target.value)
                                    }
                                >
                                    <option value="advance">Anticipo</option>
                                    <option value="loan">Préstamo</option>
                                </select>
                            </label>
                            <label className="grid w-32 gap-1 text-xs font-semibold">
                                Monto (Q)
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    className={input}
                                    value={form.data.principal}
                                    onChange={(event) =>
                                        form.setData(
                                            'principal',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </label>
                            <label className="grid w-32 gap-1 text-xs font-semibold">
                                Cuota (Q)
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    className={input}
                                    value={form.data.installment}
                                    onChange={(event) =>
                                        form.setData(
                                            'installment',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Descontar desde
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.starts_on}
                                    onChange={(event) =>
                                        form.setData(
                                            'starts_on',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </label>
                            <label className="grid min-w-56 flex-1 gap-1 text-xs font-semibold">
                                Descripción
                                <input
                                    className={input}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </label>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Registrar
                            </button>
                        </form>
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            El desembolso al empleado se realiza por Tesorería;
                            aquí solo se controla la recuperación en nómina.
                        </p>
                    </Card>
                )}
                <Card title={`${loans.length} registro(s)`}>
                    <Table
                        headers={[
                            'Empleado',
                            'Empresa',
                            'Tipo',
                            'Descripción',
                            'Monto',
                            'Cuota',
                            'Saldo',
                            'Desde',
                            'Estado',
                            '',
                        ]}
                        empty={
                            loans.length === 0 &&
                            'No hay anticipos ni préstamos registrados.'
                        }
                    >
                        {loans.map((loan) => (
                            <tr key={loan.id}>
                                <td className="px-2 py-2 font-bold">
                                    {loan.employee}
                                </td>
                                <td className="px-2 py-2">{loan.company}</td>
                                <td className="px-2 py-2">{loan.kind_label}</td>
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
                                    {formatDate(loan.starts_on)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            tone[loan.status] ?? 'slate',
                                        )}
                                    >
                                        {loan.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {canManage && loan.status === 'active' && (
                                        <button
                                            type="button"
                                            className="text-xs font-bold text-red-700"
                                            onClick={() => {
                                                const reason = window.prompt(
                                                    'Motivo de la anulación',
                                                );

                                                if (reason) {
                                                    router.post(
                                                        `/payroll/loans/${loan.id}/cancel`,
                                                        { reason },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Anular
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
