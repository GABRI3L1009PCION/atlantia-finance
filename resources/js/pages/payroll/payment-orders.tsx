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
    PeriodPicker,
    amount,
    orderTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    PaymentOrder,
    PayrollContext,
    PayrollPeriod,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function PayrollPaymentOrders({
    orders,
    periods,
    periodId,
    bankAccounts,
    canSend,
    notice,
}: {
    context: PayrollContext;
    orders: PaymentOrder[];
    periods: PayrollPeriod[];
    periodId: number | null;
    bankAccounts: { id: number; company_id: number; label: string }[];
    canSend: boolean;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const period = periods.find((row) => row.id === periodId) ?? null;
    const [bank, setBank] = useState('');
    const pending = orders.filter(
        (row) => row.status === 'pending' || row.status === 'failed',
    );

    return (
        <>
            <Head title="Órdenes de pago de nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Órdenes de pago"
                    subtitle="Lo que cada empleado debe recibir; Nómina las envía a Tesorería, que aprueba y ejecuta el pago"
                />
                <Card title="Nómina">
                    <div className="flex flex-wrap items-end gap-2">
                        <PeriodPicker
                            periods={periods}
                            periodId={periodId}
                            path="/payroll/payment-orders"
                            allowAll
                        />
                        {period &&
                            canSend &&
                            period.status === 'approved' &&
                            pending.length > 0 && (
                                <>
                                    <label className="grid min-w-56 gap-1 text-xs font-semibold">
                                        Cuenta de origen sugerida
                                        <select
                                            className={input}
                                            value={bank}
                                            onChange={(event) =>
                                                setBank(event.target.value)
                                            }
                                        >
                                            <option value="">
                                                La define Tesorería
                                            </option>
                                            {bankAccounts
                                                .filter(
                                                    (account) =>
                                                        account.company_id ===
                                                        period.company_id,
                                                )
                                                .map((account) => (
                                                    <option
                                                        key={account.id}
                                                        value={account.id}
                                                    >
                                                        {account.label}
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() =>
                                            router.post(
                                                `/payroll/periods/${period.id}/send`,
                                                bank
                                                    ? { bank_account_id: bank }
                                                    : {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Enviar {pending.length} orden(es) a
                                        Tesorería
                                    </button>
                                </>
                            )}
                    </div>
                </Card>
                <Card title={`${orders.length} orden(es)`}>
                    <Table
                        headers={[
                            'Empleado',
                            'Empresa',
                            'Nómina',
                            'Método',
                            'Cuenta',
                            'Monto',
                            'Estado',
                            'Pagada',
                            'Referencia',
                        ]}
                        empty={
                            orders.length === 0 &&
                            'Aún no hay órdenes de pago: se generan al aprobar una nómina.'
                        }
                    >
                        {orders.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2 font-bold">
                                    {row.employee}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {row.employee_code}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(
                                            `/payroll/periods/${row.period_id}`,
                                        )}
                                        className="hover:text-[#640000]"
                                    >
                                        {row.period}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {row.method_label}
                                </td>
                                <td className="px-2 py-2">
                                    {row.bank_name ?? ''}{' '}
                                    {row.bank_account_mask ?? ''}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(row.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            orderTone[row.status] ?? 'slate',
                                        )}
                                    >
                                        {row.status_label}
                                    </span>
                                    {row.failure_reason && (
                                        <p className="text-[11px] text-red-700">
                                            {row.failure_reason}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(row.paid_on)}
                                </td>
                                <td className="px-2 py-2">
                                    {row.reference ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
