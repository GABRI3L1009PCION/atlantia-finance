import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    Building2,
    ClipboardCheck,
    ClipboardPen,
    Cog,
    Send,
    Users,
} from 'lucide-react';
import { LuzmaInsightsCard } from '@/components/luzma/luzma-insights-card';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    relativeTime,
} from '@/components/office-admin/kit';
import {
    Metric,
    ViewAll,
    amount,
    companyTone,
    noveltyTone,
    periodTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    Novelty,
    PayrollCompany,
    PayrollContext,
    PayrollPeriod,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Panel = {
    metrics: Record<string, number | null>;
    companies: PayrollCompany[];
    selected_company: PayrollCompany | null;
    summary: {
        period: PayrollPeriod;
        preliminary: boolean;
        message: string | null;
        composition:
            { key: string; label: string; amount_cents: number }[] | null;
        days: number;
        novelties: number;
        pending_novelties: number;
        errors: number;
    } | null;
    activities: {
        date: string;
        title: string;
        company: string;
        period: string;
        overdue: boolean;
        destination: string;
    }[];
    novelties: Novelty[];
    last_payrolls: PayrollPeriod[];
    alerts: {
        severity: string;
        title: string;
        company: string;
        period: string | null;
        kind: string;
        destination: string;
    }[];
};

const compositionColor: Record<string, string> = {
    salaries: '#1f4e9c',
    bonuses: '#15924a',
    deductions: '#ef3b3b',
    employer: '#c7972d',
};

const severityTone: Record<string, string> = {
    critical: 'red',
    high: 'red',
    medium: 'amber',
    low: 'blue',
};

function Trend({
    current,
    previous,
}: {
    current: number | null;
    previous: number | null;
}) {
    if (current === null || previous === null) {
        return null;
    }

    const diff = current - previous;

    return (
        <span
            className={
                diff > 0 ? 'text-emerald-700' : diff < 0 ? 'text-red-700' : ''
            }
        >
            {diff === 0 ? 'Igual que' : `${diff > 0 ? '+' : ''}${diff} vs.`} mes
            anterior
        </span>
    );
}

function Composition({
    items,
}: {
    items: { key: string; label: string; amount_cents: number }[];
}) {
    const total = Math.max(
        1,
        items.reduce((sum, item) => sum + item.amount_cents, 0),
    );

    return (
        <div className="grid gap-2">
            <div className="flex h-5 overflow-hidden rounded-md">
                {items.map((item) =>
                    item.amount_cents > 0 ? (
                        <span
                            key={item.key}
                            title={`${item.label}: ${amount(item.amount_cents)}`}
                            style={{
                                width: `${(item.amount_cents / total) * 100}%`,
                                backgroundColor: compositionColor[item.key],
                            }}
                        />
                    ) : null,
                )}
            </div>
            <div className="grid gap-1 sm:grid-cols-2">
                {items.map((item) => (
                    <p
                        key={item.key}
                        className="flex items-center gap-2 text-xs"
                    >
                        <span
                            className="size-2.5 rounded-full"
                            style={{
                                backgroundColor: compositionColor[item.key],
                            }}
                        />
                        <span className="text-[#526b92]">{item.label}</span>
                        <b className="ml-auto">{amount(item.amount_cents)}</b>
                        <span className="w-10 text-right text-[#526b92]">
                            {Math.round((item.amount_cents / total) * 1000) /
                                10}
                            %
                        </span>
                    </p>
                ))}
            </div>
        </div>
    );
}

export default function PayrollDashboard({
    context,
    panel,
    notice,
}: {
    context: PayrollContext;
    panel: Panel;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const selected = panel.selected_company;
    const summary = panel.summary;
    const metrics = panel.metrics;

    return (
        <>
            <Head title="Panel de Nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Panel de Nómina"
                    subtitle={`Nóminas de mis empresas asignadas · ${context.period?.name ?? 'Sin período'}`}
                />
                <LuzmaInsightsCard module="payroll" />

                <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-8">
                    <Metric
                        label="Empresas asignadas"
                        value={metrics.assigned_companies}
                        icon={Building2}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/payroll/companies')}
                    />
                    <Metric
                        label="Empleados activos"
                        value={metrics.active_employees}
                        icon={Users}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/payroll/employees?status=active')}
                        hint={
                            <Trend
                                current={metrics.active_employees}
                                previous={metrics.active_employees_previous}
                            />
                        }
                    />
                    <Metric
                        label="Nóminas por procesar"
                        value={metrics.to_process}
                        icon={Cog}
                        tone="bg-violet-50 text-violet-700"
                        href={href('/payroll/periods?status=open')}
                    />
                    <Metric
                        label="Novedades pendientes"
                        value={metrics.pending_novelties}
                        icon={ClipboardPen}
                        tone="bg-amber-50 text-amber-700"
                        href={href('/payroll/novelties?status=pending')}
                    />
                    <Metric
                        label="Nóminas por revisar"
                        value={metrics.to_review}
                        icon={ClipboardCheck}
                        tone="bg-blue-50 text-blue-700"
                        href={href('/payroll/reviews')}
                    />
                    <Metric
                        label="Nóminas por aprobar"
                        value={metrics.to_approve}
                        icon={BadgeCheck}
                        tone="bg-emerald-50 text-emerald-700"
                        href={href('/payroll/reviews')}
                    />
                    <Metric
                        label="Órdenes de pago pendientes"
                        value={metrics.pending_payment_orders}
                        icon={Send}
                        tone="bg-red-50 text-[#640000]"
                        href={href('/payroll/payment-orders')}
                    />
                    <Metric
                        label="Incidencias"
                        value={metrics.incidents}
                        icon={AlertTriangle}
                        tone="bg-red-50 text-red-700"
                        href={href('/payroll/incidents')}
                        hint={
                            <Trend
                                current={metrics.incidents_this_month}
                                previous={metrics.incidents_previous_month}
                            />
                        }
                    />
                </section>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <Card
                        title="Estado de nómina por empresa"
                        action={
                            <ViewAll
                                href={href('/payroll/companies')}
                                label="Ver detalle"
                            />
                        }
                    >
                        <Table
                            headers={[
                                'Empresa',
                                'Empleados',
                                'Período actual',
                                'Novedades',
                                'Neto a pagar',
                                'Estado',
                                'Acciones',
                            ]}
                            empty={
                                panel.companies.length === 0 &&
                                'No tienes empresas asignadas para nómina.'
                            }
                        >
                            {panel.companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(company.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {company.name}
                                        </Link>
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            NIT {company.tax_id}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.employees}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.period ? (
                                            <>
                                                {company.period.name}
                                                <p className="text-[11px] text-[#526b92]">
                                                    Pago{' '}
                                                    {formatDate(
                                                        company.period
                                                            .payment_date,
                                                    )}
                                                </p>
                                            </>
                                        ) : (
                                            'Sin período'
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {company.novelties ?? 'No disponible'}
                                        {(company.pending_novelties ?? 0) >
                                            0 && (
                                            <p className="text-[11px] text-amber-700">
                                                {company.pending_novelties}{' '}
                                                pendiente(s)
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {company.net_cents === null
                                            ? company.period
                                                ? 'Sin calcular'
                                                : '—'
                                            : amount(company.net_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                companyTone[company.status] ??
                                                    'slate',
                                            )}
                                            title={company.error ?? undefined}
                                        >
                                            {company.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={href(
                                                company.period
                                                    ? `/payroll/periods/${company.period.id}`
                                                    : company.destination,
                                            )}
                                            className="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-bold hover:border-[#c9a36a]"
                                        >
                                            {company.period ? 'Abrir' : 'Ver'}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <Card
                        title="Resumen del período (empresa seleccionada)"
                        action={
                            panel.companies.length > 0 && (
                                <select
                                    aria-label="Empresa"
                                    className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                                    value={selected?.id ?? ''}
                                    onChange={(event) =>
                                        router.get(
                                            href('/payroll/dashboard'),
                                            { company_id: event.target.value },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {panel.companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                            )
                        }
                    >
                        {summary === null ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                {selected
                                    ? 'La empresa aún no tiene un período de nómina abierto.'
                                    : 'Sin empresas asignadas.'}
                            </p>
                        ) : (
                            <div className="grid gap-3">
                                <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                                    <b className="text-sm">
                                        {summary.period.name}
                                    </b>
                                    <span
                                        className={toneBadge(
                                            periodTone[summary.period.status] ??
                                                'slate',
                                        )}
                                    >
                                        {summary.period.status_label}
                                    </span>
                                </div>
                                {summary.preliminary && (
                                    <p className="rounded-md bg-blue-50 px-2 py-1 text-[11px] text-blue-800">
                                        Cifras preliminares (pre-nómina sin
                                        guardar); se fijan al calcular.
                                    </p>
                                )}
                                {summary.message && (
                                    <p className="rounded-md bg-violet-50 px-2 py-1 text-[11px] text-violet-800">
                                        {summary.message}
                                    </p>
                                )}
                                <div className="grid grid-cols-3 gap-2 text-center">
                                    <div>
                                        <p className="text-lg font-black text-[#061633]">
                                            {amount(summary.period.gross_cents)}
                                        </p>
                                        <p className="text-[11px] text-[#526b92]">
                                            Devengado bruto
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-lg font-black text-red-600">
                                            {amount(
                                                summary.period.deductions_cents,
                                            )}
                                        </p>
                                        <p className="text-[11px] text-red-600">
                                            Deducciones
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-lg font-black text-emerald-700">
                                            {amount(summary.period.net_cents)}
                                        </p>
                                        <p className="text-[11px] text-emerald-700">
                                            Neto a pagar
                                        </p>
                                    </div>
                                </div>
                                <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                    <dt className="text-[#526b92]">
                                        Empleados
                                    </dt>
                                    <dd className="text-right font-semibold">
                                        {summary.period.employees_count}
                                    </dd>
                                    <dt className="text-[#526b92]">
                                        Días del período
                                    </dt>
                                    <dd className="text-right font-semibold">
                                        {summary.days}
                                    </dd>
                                    <dt className="text-[#526b92]">
                                        Novedades
                                    </dt>
                                    <dd className="text-right font-semibold">
                                        {summary.novelties}
                                        {summary.pending_novelties > 0 &&
                                            ` (${summary.pending_novelties} pendientes)`}
                                    </dd>
                                    <dt className="text-[#526b92]">
                                        Fecha de pago
                                    </dt>
                                    <dd className="text-right font-semibold">
                                        {formatDate(
                                            summary.period.payment_date,
                                        )}
                                    </dd>
                                </dl>
                                {summary.composition && (
                                    <div>
                                        <p className="mb-2 text-xs font-bold text-[#061633]">
                                            Composición de la nómina
                                        </p>
                                        <Composition
                                            items={summary.composition}
                                        />
                                    </div>
                                )}
                                <Link
                                    href={href(summary.period.destination)}
                                    className="text-center text-xs font-bold text-[#640000]"
                                >
                                    Ver detalle del período
                                </Link>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="grid gap-3 xl:grid-cols-3">
                    <Card
                        title="Próximas actividades de nómina"
                        action={
                            <ViewAll
                                href={href('/payroll/calendar')}
                                label="Ver calendario"
                            />
                        }
                    >
                        <Table
                            headers={['Fecha', 'Actividad', 'Empresa']}
                            empty={
                                panel.activities.length === 0 &&
                                'No hay actividades próximas: abre un período de nómina.'
                            }
                        >
                            {panel.activities.map((activity, index) => (
                                <tr key={index}>
                                    <td
                                        className={`px-2 py-2 whitespace-nowrap ${activity.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(activity.date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={href(activity.destination)}
                                            className="font-semibold hover:text-[#640000]"
                                        >
                                            {activity.title}
                                        </Link>
                                        {activity.overdue && (
                                            <span
                                                className={`ml-1 ${toneBadge('red')}`}
                                            >
                                                Atrasada
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {activity.company}
                                        <p className="text-[11px] text-[#526b92]">
                                            {activity.period}
                                        </p>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card
                        title="Novedades recientes"
                        action={<ViewAll href={href('/payroll/novelties')} />}
                    >
                        <Table
                            headers={['Empleado', 'Novedad', 'Estado']}
                            empty={
                                panel.novelties.length === 0 &&
                                'Aún no hay novedades registradas.'
                            }
                        >
                            {panel.novelties.map((novelty) => (
                                <tr key={novelty.id}>
                                    <td className="px-2 py-2">
                                        {novelty.employee}
                                        <p className="text-[11px] text-[#526b92]">
                                            {novelty.company}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {novelty.concept}
                                        <p className="text-[11px] text-[#526b92]">
                                            {novelty.measure === 'quantity'
                                                ? `${novelty.quantity} ${novelty.unit ?? ''}`
                                                : amount(
                                                      novelty.amount_cents,
                                                  )}{' '}
                                            · {relativeTime(novelty.created_at)}
                                        </p>
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
                    <Card
                        title="Últimas nóminas procesadas"
                        action={
                            <ViewAll
                                href={href('/payroll/periods')}
                                label="Ver todas"
                            />
                        }
                    >
                        <Table
                            headers={['Empresa', 'Período', 'Neto', 'Estado']}
                            empty={
                                panel.last_payrolls.length === 0 &&
                                'Aún no hay nóminas aprobadas.'
                            }
                        >
                            {panel.last_payrolls.map((period) => (
                                <tr key={period.id}>
                                    <td className="px-2 py-2">
                                        {period.company}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(period.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {period.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(period.net_cents)}
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
                </div>

                <Card
                    title="Incidencias y alertas"
                    action={<ViewAll href={href('/payroll/incidents')} />}
                >
                    <Table
                        headers={['Severidad', 'Alerta', 'Empresa', '']}
                        empty={
                            panel.alerts.length === 0 &&
                            'Sin incidencias ni alertas de nómina.'
                        }
                    >
                        {panel.alerts.map((alert, index) => (
                            <tr key={index}>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            severityTone[alert.severity] ??
                                                'slate',
                                        )}
                                    >
                                        {alert.severity === 'high' ||
                                        alert.severity === 'critical'
                                            ? 'Alta'
                                            : alert.severity === 'medium'
                                              ? 'Media'
                                              : 'Baja'}
                                    </span>
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {alert.title}
                                </td>
                                <td className="px-2 py-2">
                                    {alert.company}
                                    {alert.period && (
                                        <p className="text-[11px] text-[#526b92]">
                                            {alert.period}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(alert.destination)}
                                        className="text-xs font-bold text-[#640000]"
                                    >
                                        Revisar
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <p className="text-[11px] text-[#526b92]">
                    Los montos se presentan por empresa y período; Atlantia no
                    suma nóminas de empresas distintas. Las tasas (IGSS, IRTRA,
                    INTECAP, ISR, bonificación incentivo) provienen de los
                    parámetros legales vigentes; Nómina calcula, otra persona
                    aprueba y Tesorería ejecuta los pagos.
                </p>
            </Shell>
        </>
    );
}
