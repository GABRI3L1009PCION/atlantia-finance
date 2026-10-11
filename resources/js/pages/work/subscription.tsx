import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    CircleX,
    CreditCard,
    FileText,
    Layers,
    Settings,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Props = {
    plan: {
        name: string;
        tagline: string | null;
        status: string;
        billing_period: 'monthly' | 'annual';
        price: number;
        currency: string;
        period_ends_at: string | null;
        period_starts_at: string | null;
        cancel_at_period_end: boolean;
        provider: string | null;
        discount_percent: number;
    };
    usage: {
        key: string;
        label: string;
        used: number;
        limit: number | null;
        unit: string;
    }[];
    modules: { name: string; included: boolean }[];
    available: {
        name: string;
        tagline: string | null;
        monthly: number | null;
        annual: number | null;
        highlights: string[];
        current: boolean;
    }[];
    payments: {
        date: string;
        amount: number;
        currency: string;
        status: string;
        reference: string | null;
    }[];
    billing: {
        name: string | null;
        nit: string | null;
        address: string | null;
        email: string | null;
    } | null;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-6 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const STATUS: Record<string, { label: string; style: string }> = {
    active: { label: 'Activo', style: 'bg-emerald-100 text-emerald-700' },
    trialing: { label: 'En prueba', style: 'bg-blue-100 text-blue-700' },
    past_due: { label: 'Pago vencido', style: 'bg-red-100 text-red-700' },
    paused: { label: 'Pausado', style: 'bg-amber-100 text-amber-700' },
    canceled: { label: 'Cancelado', style: 'bg-slate-100 text-slate-600' },
    none: { label: 'Sin suscripción', style: 'bg-slate-100 text-slate-600' },
};
const PAYMENT: Record<string, { label: string; style: string }> = {
    paid: { label: 'Pagada', style: 'bg-emerald-50 text-emerald-700' },
    succeeded: { label: 'Pagada', style: 'bg-emerald-50 text-emerald-700' },
    pending: { label: 'Pendiente', style: 'bg-amber-50 text-amber-700' },
    failed: { label: 'Fallida', style: 'bg-red-50 text-red-700' },
};
const money = (value: number, currency = 'GTQ') =>
    `${currency === 'GTQ' ? 'Q' : `${currency} `}${value.toLocaleString('es-GT', { maximumFractionDigits: 2 })}`;
const formatDate = (value: string | null) =>
    value
        ? new Date(value).toLocaleDateString('es-GT', {
              day: '2-digit',
              month: 'long',
              year: 'numeric',
          })
        : '—';

function UsageRow({ item }: { item: Props['usage'][number] }) {
    const percent = item.limit
        ? Math.min(100, Math.round((item.used / item.limit) * 100))
        : null;
    const color =
        percent === null
            ? '#16a34a'
            : percent >= 90
              ? '#dc2626'
              : percent >= 75
                ? '#f0a93b'
                : '#16a34a';

    return (
        <li className="grid grid-cols-[9rem_1fr_auto] items-center gap-3 text-sm">
            <span className="font-semibold">{item.label}</span>
            <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                <div
                    className="h-full rounded-full"
                    style={{ width: `${percent ?? 8}%`, background: color }}
                />
            </div>
            <span className="text-right text-xs text-slate-600">
                <b className="text-[#061633]">
                    {item.used.toLocaleString('es-GT')}
                    {item.unit ? ` ${item.unit}` : ''}
                </b>{' '}
                /{' '}
                {item.limit === null
                    ? 'Ilimitado'
                    : `${item.limit.toLocaleString('es-GT')}${item.unit ? ` ${item.unit}` : ''}`}
                {percent !== null && (
                    <span className="ml-2 font-bold">{percent}%</span>
                )}
            </span>
        </li>
    );
}

function Stat({
    label,
    value,
    hint,
}: {
    label: string;
    value: ReactNode;
    hint?: string;
}) {
    return (
        <div>
            <p className="text-xs text-slate-500">{label}</p>
            <p className="text-2xl font-black text-[#061633]">{value}</p>
            {hint && <p className="text-xs text-slate-400">{hint}</p>}
        </div>
    );
}

export default function Subscription({
    plan,
    usage,
    modules,
    available,
    payments,
    billing,
}: Props) {
    const [period, setPeriod] = useState<'monthly' | 'annual'>(
        plan.billing_period,
    );
    const status = STATUS[plan.status] ?? STATUS.none;
    const monthlyShown =
        plan.billing_period === 'annual' ? plan.price / 12 : plan.price;

    return (
        <>
            <Head title="Plan y suscripción" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1500px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <Settings className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Plan y suscripción
                            </h1>
                            <p className="text-sm text-slate-600">
                                Tu plan, el uso de recursos y la facturación de
                                la firma.
                            </p>
                        </div>
                    </header>

                    <div className="grid gap-4 xl:grid-cols-[1.7fr_1fr]">
                        <div className="space-y-4">
                            <section className={card}>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h2 className="text-lg font-black">
                                        Tu plan actual
                                    </h2>
                                    <span
                                        className={`rounded-full px-3 py-1 text-xs font-bold ${status.style}`}
                                    >
                                        {status.label}
                                    </span>
                                </div>
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-6">
                                    <div className="flex items-center gap-4">
                                        <span className="grid size-20 place-items-center rounded-2xl bg-[#f6e6e4] text-[#8a0b0b]">
                                            <Layers className="size-10" />
                                        </span>
                                        <div>
                                            <p className="text-4xl font-black">
                                                {plan.name}
                                            </p>
                                            <p className="text-sm text-slate-600">
                                                {plan.tagline ??
                                                    'Plan contratado por la firma.'}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 items-end gap-6">
                                        <Stat
                                            label="Ciclo de facturación"
                                            value={
                                                plan.billing_period === 'annual'
                                                    ? 'Anual'
                                                    : 'Mensual'
                                            }
                                        />
                                        <div className="text-right">
                                            <p className="text-4xl font-black text-[#8a0b0b]">
                                                {money(
                                                    monthlyShown,
                                                    plan.currency,
                                                )}
                                                <span className="text-lg text-slate-500">
                                                    /mes
                                                </span>
                                            </p>
                                            {plan.billing_period ===
                                                'annual' && (
                                                <p className="text-xs text-slate-500">
                                                    Facturado{' '}
                                                    {money(
                                                        plan.price,
                                                        plan.currency,
                                                    )}{' '}
                                                    al año
                                                </p>
                                            )}
                                        </div>
                                        <Stat
                                            label="Vigente hasta"
                                            value={
                                                <span className="text-base">
                                                    {formatDate(
                                                        plan.period_ends_at,
                                                    )}
                                                </span>
                                            }
                                            hint={
                                                plan.cancel_at_period_end
                                                    ? 'No se renovará'
                                                    : undefined
                                            }
                                        />
                                    </div>
                                </div>
                            </section>

                            <div className="grid gap-4 xl:grid-cols-2">
                                <section className={card}>
                                    <h2 className="mb-4 text-lg font-black">
                                        Módulos incluidos en tu plan
                                    </h2>
                                    <ul className="grid grid-cols-2 gap-3">
                                        {modules.map((module) => (
                                            <li
                                                key={module.name}
                                                className={`flex items-center gap-2 rounded-xl border p-3 text-sm font-bold ${module.included ? 'border-emerald-100 bg-emerald-50/40' : 'border-slate-100 text-slate-400'}`}
                                            >
                                                {module.included ? (
                                                    <CheckCircle2 className="size-5 shrink-0 text-emerald-600" />
                                                ) : (
                                                    <CircleX className="size-5 shrink-0 text-slate-300" />
                                                )}
                                                <span>{module.name}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                                <section className={card}>
                                    <h2 className="mb-4 text-lg font-black">
                                        Uso actual de tu plan
                                    </h2>
                                    <ul className="space-y-4">
                                        {usage.map((item) => (
                                            <UsageRow
                                                key={item.key}
                                                item={item}
                                            />
                                        ))}
                                    </ul>
                                </section>
                            </div>

                            <section className={card}>
                                <div className="mb-4 flex items-center justify-between">
                                    <h2 className="text-lg font-black">
                                        Planes disponibles
                                    </h2>
                                    <div className="flex rounded-xl border border-[#e4dbd6] p-1 text-sm font-bold">
                                        {(['monthly', 'annual'] as const).map(
                                            (key) => (
                                                <button
                                                    key={key}
                                                    type="button"
                                                    onClick={() =>
                                                        setPeriod(key)
                                                    }
                                                    className={`rounded-lg px-4 py-1.5 ${period === key ? 'bg-[#8a0b0b] text-white' : 'text-slate-600'}`}
                                                >
                                                    {key === 'monthly'
                                                        ? 'Mensual'
                                                        : 'Anual'}
                                                </button>
                                            ),
                                        )}
                                    </div>
                                </div>
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    {available.map((item) => {
                                        const price =
                                            period === 'annual'
                                                ? item.annual !== null
                                                    ? item.annual / 12
                                                    : null
                                                : item.monthly;

                                        return (
                                            <div
                                                key={item.name}
                                                className={`rounded-2xl border p-4 ${item.current ? 'border-[#c0454a] bg-[#fdf3f2]' : 'border-slate-200'}`}
                                            >
                                                {item.current && (
                                                    <p className="mb-1 text-xs font-bold text-[#8a0b0b]">
                                                        Tu plan actual
                                                    </p>
                                                )}
                                                <p className="text-lg font-black">
                                                    {item.name}
                                                </p>
                                                <p className="min-h-8 text-xs text-slate-500">
                                                    {item.tagline}
                                                </p>
                                                <p className="mt-2 text-2xl font-black">
                                                    {price === null
                                                        ? '—'
                                                        : money(
                                                              Math.round(price),
                                                          )}
                                                    <span className="text-sm font-semibold text-slate-500">
                                                        /mes
                                                    </span>
                                                </p>
                                                <ul className="mt-2 space-y-1 text-xs text-slate-600">
                                                    {item.highlights
                                                        .slice(0, 5)
                                                        .map((highlight) => (
                                                            <li
                                                                key={highlight}
                                                                className="flex gap-1.5"
                                                            >
                                                                <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-emerald-600" />
                                                                {highlight}
                                                            </li>
                                                        ))}
                                                </ul>
                                                <p className="mt-3 rounded-lg bg-slate-50 py-2 text-center text-xs font-bold text-slate-600">
                                                    {item.current
                                                        ? '✓ Plan actual'
                                                        : 'Para cambiar de plan, contacta a ventas'}
                                                </p>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        </div>

                        <div className="space-y-4">
                            <section
                                className={`${card} flex items-start gap-4`}
                            >
                                <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                    <CalendarClock className="size-7" />
                                </span>
                                <div>
                                    <p className="text-sm font-bold text-[#8a0b0b]">
                                        Próxima renovación
                                    </p>
                                    <p className="text-2xl font-black">
                                        {formatDate(plan.period_ends_at)}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {plan.provider === 'manual'
                                            ? 'Suscripción gestionada manualmente por Atlantia.'
                                            : `Cobro de ${money(plan.price, plan.currency)} con tu método de pago.`}
                                    </p>
                                </div>
                            </section>

                            <section className={card}>
                                <div className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-lg bg-slate-100">
                                        <CreditCard className="size-5 text-slate-600" />
                                    </span>
                                    <div>
                                        <h2 className="font-black">
                                            Método de pago
                                        </h2>
                                        <p className="text-xs text-slate-500">
                                            {plan.provider === 'manual'
                                                ? 'Pago gestionado manualmente (sin tarjeta registrada).'
                                                : 'Procesado de forma segura por el proveedor de pagos; Atlantia no guarda tu tarjeta.'}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section className={card}>
                                <div className="mb-3 flex items-center justify-between">
                                    <h2 className="flex items-center gap-2 font-black">
                                        <FileText className="size-5 text-[#8a0b0b]" />{' '}
                                        Datos de facturación
                                    </h2>
                                    <Link
                                        href="/billing/details"
                                        className="rounded-lg border border-[#e4dbd6] px-3 py-1.5 text-xs font-bold"
                                    >
                                        Ver datos fiscales
                                    </Link>
                                </div>
                                {billing ? (
                                    <dl className="space-y-1 text-sm">
                                        <dd className="font-bold">
                                            {billing.name}
                                        </dd>
                                        <dd className="text-slate-600">
                                            NIT: {billing.nit ?? '—'}
                                        </dd>
                                        <dd className="text-slate-600">
                                            {billing.address ?? '—'}
                                        </dd>
                                        <dd className="text-slate-600">
                                            {billing.email ?? '—'}
                                        </dd>
                                    </dl>
                                ) : (
                                    <p className="text-sm text-slate-500">
                                        Aún no hay datos de facturación
                                        registrados.
                                    </p>
                                )}
                            </section>

                            <section className={`${card} overflow-x-auto`}>
                                <h2 className="mb-3 font-black">
                                    Historial de facturación
                                </h2>
                                <table className="w-full min-w-[320px] text-sm">
                                    <thead>
                                        <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                            <th className="px-2 py-2">Fecha</th>
                                            <th className="px-2 py-2">
                                                Referencia
                                            </th>
                                            <th className="px-2 py-2">Monto</th>
                                            <th className="px-2 py-2">
                                                Estado
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {payments.map((payment, index) => (
                                            <tr key={index}>
                                                <td className="px-2 py-2.5">
                                                    {formatDate(payment.date)}
                                                </td>
                                                <td className="px-2 py-2.5 text-slate-600">
                                                    {payment.reference ?? '—'}
                                                </td>
                                                <td className="px-2 py-2.5 font-bold">
                                                    {money(
                                                        payment.amount,
                                                        payment.currency,
                                                    )}
                                                </td>
                                                <td className="px-2 py-2.5">
                                                    <span
                                                        className={`rounded-md px-2 py-0.5 text-xs font-bold ${PAYMENT[payment.status]?.style ?? 'bg-slate-100 text-slate-600'}`}
                                                    >
                                                        {PAYMENT[payment.status]
                                                            ?.label ??
                                                            payment.status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                        {payments.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={4}
                                                    className="px-2 py-6 text-center text-slate-500"
                                                >
                                                    Aún no hay cobros de la
                                                    suscripción.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </section>
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}
