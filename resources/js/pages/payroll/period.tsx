import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    amount,
    noveltyTone,
    orderTone,
    periodTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    EmployeeResult,
    Novelty,
    PaymentOrder,
    PayrollContext,
    PayrollPeriod,
} from '@/components/payroll/kit';
import { ResultsTable } from '@/components/payroll/results-table';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

const steps = [
    ['open', 'Novedades'],
    ['calculated', 'Cálculo'],
    ['in_review', 'Revisión'],
    ['approved', 'Aprobación'],
    ['paid', 'Pago'],
    ['closed', 'Cierre'],
] as const;

const order = [
    'open',
    'changes_requested',
    'calculated',
    'in_review',
    'approved',
    'paid',
    'closed',
];

export default function PayrollPeriodPage({
    period,
    results,
    novelties,
    orders,
    receipts,
    entry,
    history,
    can,
    notice,
}: {
    context: PayrollContext;
    period: PayrollPeriod;
    results: EmployeeResult[];
    novelties: Novelty[];
    orders: PaymentOrder[];
    receipts: number;
    entry: {
        number: number | null;
        status: string;
        destination: string;
    } | null;
    history: { action: string; user: string; occurred_at: string }[];
    can: Record<string, boolean>;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const [note, setNote] = useState('');
    const act = (action: string, data: Record<string, string> = {}) =>
        router.post(`/payroll/periods/${period.id}/${action}`, data, {
            preserveScroll: true,
        });
    const current = order.indexOf(period.status);
    const errors = results.filter((result) => result.status === 'error').length;

    return (
        <>
            <Head title={`Nómina ${period.name}`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={`Nómina ${period.name}`}
                    subtitle={`${period.company} · ${formatDate(period.starts_on)} al ${formatDate(period.ends_on)} · pago ${formatDate(period.payment_date)}`}
                    actions={
                        <>
                            <span
                                className={toneBadge(
                                    periodTone[period.status] ?? 'slate',
                                )}
                            >
                                {period.status_label}
                            </span>
                            <Link
                                href={href(
                                    `/payroll/novelties?period_id=${period.id}`,
                                )}
                                className={secondaryButton}
                            >
                                Novedades
                            </Link>
                            <Link
                                href={href(
                                    `/payroll/pre-payroll?period_id=${period.id}`,
                                )}
                                className={secondaryButton}
                            >
                                Pre-nómina
                            </Link>
                        </>
                    }
                />

                <ol className="grid grid-cols-3 gap-1 md:grid-cols-6">
                    {steps.map(([status, label], index) => {
                        const reached = current >= order.indexOf(status);

                        return (
                            <li
                                key={status}
                                className={`rounded-lg border px-2 py-1.5 text-center text-xs font-bold ${reached ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-500'}`}
                            >
                                {index + 1}. {label}
                            </li>
                        );
                    })}
                </ol>

                {period.needs_recalculation && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">
                        Requiere recálculo: {period.recalculation_reason}
                    </p>
                )}
                {period.status === 'changes_requested' &&
                    period.review_note && (
                        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                            <b>Observaciones de revisión:</b>{' '}
                            {period.review_note}
                        </p>
                    )}

                <div className="grid gap-3 md:grid-cols-5">
                    <Card title="Devengado bruto">
                        <p className="text-xl font-black">
                            {period.calculated_at
                                ? amount(period.gross_cents)
                                : '—'}
                        </p>
                    </Card>
                    <Card title="Deducciones">
                        <p className="text-xl font-black text-red-700">
                            {period.calculated_at
                                ? amount(period.deductions_cents)
                                : '—'}
                        </p>
                    </Card>
                    <Card title="Neto a pagar">
                        <p className="text-xl font-black text-emerald-700">
                            {period.calculated_at
                                ? amount(period.net_cents)
                                : '—'}
                        </p>
                    </Card>
                    <Card title="Aportes empresa">
                        <p className="text-xl font-black">
                            {period.calculated_at
                                ? amount(period.employer_cents)
                                : '—'}
                        </p>
                    </Card>
                    <Card title="Provisiones">
                        <p className="text-xl font-black">
                            {period.calculated_at
                                ? amount(period.provisions_cents)
                                : '—'}
                        </p>
                    </Card>
                </div>

                <Card title="Acciones">
                    <div className="flex flex-wrap items-end gap-2">
                        {can.calculate && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => act('calculate')}
                            >
                                {period.calculated_at
                                    ? 'Recalcular nómina'
                                    : 'Calcular nómina'}
                            </button>
                        )}
                        {can.submit && (
                            <button
                                type="button"
                                className={primaryButton}
                                disabled={errors > 0}
                                onClick={() => act('submit')}
                            >
                                Enviar a aprobación
                            </button>
                        )}
                        {can.review && (
                            <>
                                <label className="grid min-w-72 flex-1 gap-1 text-xs font-semibold">
                                    Nota de revisión
                                    <input
                                        className={input}
                                        value={note}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                        placeholder="Obligatoria para devolver con observaciones"
                                    />
                                </label>
                                <button
                                    type="button"
                                    className={primaryButton}
                                    onClick={() => act('approve', { note })}
                                >
                                    Aprobar nómina
                                </button>
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    onClick={() => act('changes', { note })}
                                >
                                    Devolver con observaciones
                                </button>
                            </>
                        )}
                        {can.send &&
                            orders.some(
                                (row) =>
                                    row.status === 'pending' ||
                                    row.status === 'failed',
                            ) && (
                                <button
                                    type="button"
                                    className={primaryButton}
                                    onClick={() => act('send')}
                                >
                                    Enviar órdenes a Tesorería
                                </button>
                            )}
                        {can.close && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => act('close')}
                            >
                                Cerrar nómina
                            </button>
                        )}
                        {!Object.entries(can).some(
                            ([key, value]) => key !== 'view_salary' && value,
                        ) && (
                            <p className="text-sm text-[#526b92]">
                                {period.status === 'in_review'
                                    ? 'En revisión: la aprueba una persona autorizada distinta de quien la calculó.'
                                    : period.status === 'approved'
                                      ? 'Aprobada: Tesorería ejecuta los pagos enviados.'
                                      : 'No hay acciones disponibles para ti en este estado.'}
                            </p>
                        )}
                    </div>
                    {errors > 0 && (
                        <p className="mt-2 text-xs font-semibold text-red-700">
                            {errors} empleado(s) con errores: corrige los datos
                            y recalcula antes de enviar.
                        </p>
                    )}
                </Card>

                <Card title={`Cálculo por empleado (${results.length})`}>
                    {!can.view_salary && results.length > 0 && (
                        <p className="mb-2 text-xs text-[#526b92]">
                            Los montos individuales están reservados para tu
                            rol.
                        </p>
                    )}
                    <ResultsTable results={results} />
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card
                        title={`Novedades (${novelties.length})`}
                        action={
                            <Link
                                href={href(
                                    `/payroll/novelties?period_id=${period.id}`,
                                )}
                                className="text-xs font-bold text-[#640000]"
                            >
                                Gestionar
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Empleado',
                                'Concepto',
                                'Cantidad / monto',
                                'Estado',
                            ]}
                            empty={
                                novelties.length === 0 &&
                                'Sin novedades en este período.'
                            }
                        >
                            {novelties.map((novelty) => (
                                <tr key={novelty.id}>
                                    <td className="px-2 py-2">
                                        {novelty.employee}
                                    </td>
                                    <td className="px-2 py-2">
                                        {novelty.concept}
                                        {novelty.description && (
                                            <p className="text-[11px] text-[#526b92]">
                                                {novelty.description}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {novelty.measure === 'quantity'
                                            ? `${novelty.quantity} ${novelty.unit ?? ''}`
                                            : amount(novelty.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                noveltyTone[novelty.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {novelty.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Resultado de la aprobación">
                        <dl className="grid grid-cols-[1fr_auto] gap-y-2 text-sm">
                            <dt className="text-[#526b92]">Recibos de pago</dt>
                            <dd>
                                {receipts > 0 ? (
                                    <Link
                                        href={href(
                                            `/payroll/receipts?period_id=${period.id}`,
                                        )}
                                        className="font-bold text-[#640000]"
                                    >
                                        {receipts} recibo(s)
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </dd>
                            <dt className="text-[#526b92]">Asiento contable</dt>
                            <dd>
                                {entry ? (
                                    <a
                                        href={entry.destination}
                                        className="font-bold text-[#640000]"
                                    >
                                        {entry.number
                                            ? `#${entry.number}`
                                            : 'Borrador'}{' '}
                                        ·{' '}
                                        {entry.status === 'posted'
                                            ? 'Contabilizado'
                                            : entry.status === 'draft'
                                              ? 'En borrador'
                                              : entry.status}
                                    </a>
                                ) : (
                                    '—'
                                )}
                            </dd>
                            <dt className="text-[#526b92]">Órdenes de pago</dt>
                            <dd>
                                {orders.length > 0 ? (
                                    <Link
                                        href={href(
                                            `/payroll/payment-orders?period_id=${period.id}`,
                                        )}
                                        className="font-bold text-[#640000]"
                                    >
                                        {
                                            orders.filter(
                                                (row) => row.status === 'paid',
                                            ).length
                                        }{' '}
                                        de {orders.length} pagadas
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </dl>
                        <p className="mt-3 text-[11px] text-[#526b92]">
                            El asiento queda en borrador para Contabilidad; los
                            pagos los aprueba y ejecuta Tesorería.
                        </p>
                    </Card>
                </div>

                {orders.length > 0 && (
                    <Card title="Órdenes de pago">
                        <Table
                            headers={[
                                'Empleado',
                                'Método',
                                'Cuenta',
                                'Monto',
                                'Estado',
                                'Referencia',
                            ]}
                        >
                            {orders.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2">
                                        {row.employee}
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
                                                orderTone[row.status] ??
                                                    'slate',
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
                                        {row.reference ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}

                <Card title="Historial">
                    <Table
                        headers={['Fecha', 'Acción', 'Usuario']}
                        empty={history.length === 0 && 'Sin eventos.'}
                    >
                        {history.map((event, index) => (
                            <tr key={index}>
                                <td className="px-2 py-2">
                                    {formatDate(event.occurred_at, true)}
                                </td>
                                <td className="px-2 py-2">{event.action}</td>
                                <td className="px-2 py-2">{event.user}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
