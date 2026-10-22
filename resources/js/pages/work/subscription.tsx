import { Head, Link, router } from '@inertiajs/react';
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
        status_label: string;
        can_pay: boolean;
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
    canChangePlan: boolean;
    available: {
        monthly_price_id: number | null;
        annual_price_id: number | null;
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
// The label comes from the server; this only picks the badge colour.
const STATUS_STYLE: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-700',
    trialing: 'bg-blue-100 text-blue-700',
    past_due: 'bg-red-100 text-red-700',
    grace_period: 'bg-red-100 text-red-700',
    paused: 'bg-amber-100 text-amber-700',
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

type PlanPreview = {
    direction: 'upgrade' | 'downgrade' | 'lateral';
    current_plan: string;
    new_plan: string;
    new_monthly: number;
    proration: number;
    conflicts: { label: string; value: number; limit: number }[];
    policy: string;
    effective: string;
};

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
    canChangePlan,
    available,
    payments,
    billing,
}: Props) {
    const [period, setPeriod] = useState<'monthly' | 'annual'>(
        plan.billing_period,
    );
    const [target, setTarget] = useState<{
        name: string;
        priceId: number;
    } | null>(null);
    const [preview, setPreview] = useState<PlanPreview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [changing, setChanging] = useState(false);
    const askChange = async (name: string, priceId: number) => {
        setTarget({ name, priceId });
        setPreview(null);
        setPreviewError(null);
        const response = await fetch(
            `/office/subscription/plan-preview?plan_price_id=${priceId}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            setPreviewError(
                'No pudimos calcular el cambio. Inténtalo de nuevo.',
            );

            return;
        }

        setPreview(
            ((await response.json()) as { preview: PlanPreview }).preview,
        );
    };
    const status = {
        label: plan.status_label,
        style: STATUS_STYLE[plan.status] ?? 'bg-slate-100 text-slate-600',
    };
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
                                    {plan.can_pay && (
                                        <Link
                                            href="/billing/checkout"
                                            className="ml-auto rounded-lg bg-[#8a0b0b] px-4 py-2 text-xs font-bold text-white"
                                        >
                                            Pagar ahora
                                        </Link>
                                    )}
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
                                                {item.current ||
                                                !canChangePlan ? (
                                                    <p className="mt-3 rounded-lg bg-slate-50 py-2 text-center text-xs font-bold text-slate-600">
                                                        {item.current
                                                            ? '✓ Plan actual'
                                                            : 'Solo el propietario puede cambiar el plan'}
                                                    </p>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            (period === 'annual'
                                                                ? item.annual_price_id
                                                                : item.monthly_price_id) ===
                                                            null
                                                        }
                                                        onClick={() =>
                                                            askChange(
                                                                item.name,
                                                                Number(
                                                                    period ===
                                                                        'annual'
                                                                        ? item.annual_price_id
                                                                        : item.monthly_price_id,
                                                                ),
                                                            )
                                                        }
                                                        className="mt-3 w-full rounded-lg bg-[#8a0b0b] py-2 text-xs font-bold text-white disabled:opacity-50"
                                                    >
                                                        Cambiar a este plan
                                                    </button>
                                                )}
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
            {target && (
                <div
                    role="dialog"
                    aria-modal="true"
                    className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
                >
                    <div className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                        <h2 className="text-xl font-black">
                            Cambiar a {target.name}
                        </h2>
                        {previewError && (
                            <p className="text-sm text-red-700">
                                {previewError}
                            </p>
                        )}
                        {!preview && !previewError && (
                            <p className="animate-pulse text-sm text-slate-500">
                                Calculando el cambio…
                            </p>
                        )}
                        {preview && (
                            <div className="space-y-2 text-sm text-slate-700">
                                <p>
                                    De <b>{preview.current_plan}</b> a{' '}
                                    <b>{preview.new_plan}</b> (
                                    {money(Math.round(preview.new_monthly))}
                                    /mes).
                                </p>
                                {preview.direction === 'upgrade' && (
                                    <p>
                                        La subida se aplica de inmediato.
                                        {preview.proration > 0
                                            ? ` Se genera un cargo prorrateado de ${money(preview.proration)} que pagas con su factura.`
                                            : ' No hay cargo adicional hoy.'}
                                    </p>
                                )}
                                {preview.direction === 'downgrade' && (
                                    <p>
                                        La bajada se aplica al final del período
                                        actual ({formatDate(preview.effective)}
                                        ).
                                    </p>
                                )}
                                {preview.direction === 'lateral' && (
                                    <p>El cambio de periodo se aplica ahora.</p>
                                )}
                                {preview.conflicts.length > 0 && (
                                    <div className="rounded-lg bg-red-50 p-3 text-red-800">
                                        <p className="font-bold">
                                            No se puede bajar a este plan: tu
                                            uso actual lo excede.
                                        </p>
                                        <ul className="mt-1 list-disc pl-5">
                                            {preview.conflicts.map(
                                                (conflict) => (
                                                    <li key={conflict.label}>
                                                        {conflict.label}:{' '}
                                                        {conflict.value} de{' '}
                                                        {conflict.limit}{' '}
                                                        permitidos
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                        <p className="mt-1 text-xs">
                                            No se elimina nada: reduce tu uso y
                                            vuelve a intentarlo.
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                className="rounded-lg border px-4 py-2 text-sm font-semibold"
                                onClick={() => setTarget(null)}
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={
                                    !preview ||
                                    changing ||
                                    (preview.direction === 'downgrade' &&
                                        preview.conflicts.length > 0)
                                }
                                className="rounded-lg bg-[#8a0b0b] px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
                                onClick={() => {
                                    setChanging(true);
                                    router.post(
                                        '/office/subscription/plan',
                                        { plan_price_id: target.priceId },
                                        {
                                            onFinish: () => {
                                                setChanging(false);
                                                setTarget(null);
                                            },
                                        },
                                    );
                                }}
                            >
                                Confirmar cambio
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
