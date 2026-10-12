import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { amount, periodTone, usePayrollHref } from '@/components/payroll/kit';
import type {
    PayrollContext,
    PayrollPeriod,
    PayrollPermissions,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function PayrollPeriods({
    context,
    periods,
    filters,
    statuses,
    permissions,
    notice,
}: {
    context: PayrollContext;
    periods: PayrollPeriod[];
    filters: { company_id?: string; status?: string };
    statuses: Record<string, string>;
    permissions: PayrollPermissions;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const today = new Date();
    const form = useForm({
        company_id:
            context.companies.length === 1
                ? String(context.companies[0].id)
                : '',
        month: `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`,
        payment_date: '',
    });
    const filter = (key: string, value: string) =>
        router.get(
            href('/payroll/periods'),
            { ...filters, [key]: value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Períodos de nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Períodos de nómina"
                    subtitle="Nómina mensual por empresa: preparación, cálculo, revisión, aprobación, pago y cierre"
                />
                {permissions.canCalculate && (
                    <Card title="Abrir período">
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/payroll/periods', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="grid min-w-56 gap-1 text-xs font-semibold">
                                Empresa
                                <select
                                    className={input}
                                    value={form.data.company_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'company_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {context.companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Mes
                                <input
                                    type="month"
                                    className={input}
                                    value={form.data.month}
                                    onChange={(event) =>
                                        form.setData(
                                            'month',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Fecha de pago (opcional)
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.payment_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'payment_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Abrir período
                            </button>
                        </form>
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Por defecto la fecha de pago es el último día del
                            mes. En esta fase la nómina es mensual.
                        </p>
                    </Card>
                )}
                <Card
                    title={`${periods.length} período(s)`}
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
                                className={`${input} h-8 w-40 text-xs`}
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    filter('status', event.target.value)
                                }
                                aria-label="Estado"
                            >
                                <option value="">Todos los estados</option>
                                {Object.entries(statuses).map(
                                    ([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Empresa',
                            'Período',
                            'Pago',
                            'Empleados',
                            'Devengado',
                            'Descuentos',
                            'Neto',
                            'Estado',
                        ]}
                        empty={
                            periods.length === 0 &&
                            'No hay períodos con estos filtros.'
                        }
                    >
                        {periods.map((period) => (
                            <tr key={period.id}>
                                <td className="px-2 py-2">{period.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(period.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {period.name}
                                    </Link>
                                    {period.needs_recalculation && (
                                        <p className="text-[11px] font-semibold text-amber-700">
                                            Requiere recálculo
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(period.payment_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {period.employees_count}
                                </td>
                                <td className="px-2 py-2">
                                    {period.calculated_at
                                        ? amount(period.gross_cents)
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {period.calculated_at
                                        ? amount(period.deductions_cents)
                                        : '—'}
                                </td>
                                <td className="px-2 py-2 font-semibold">
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
            </Shell>
        </>
    );
}
