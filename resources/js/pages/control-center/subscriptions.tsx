import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    SensitiveAction,
    Table,
    ccButton,
    ccGhost,
    ccInput,
    date,
    money,
    statusTone,
} from '@/components/control-center/kit';

type Subscription = {
    id: number;
    organization_id: number;
    organization: string | null;
    plan: string | null;
    plan_version: number | null;
    billing_cycle: string | null;
    currency: string;
    base_price: number | null;
    discount_percent: number;
    status: string;
    status_label: string;
    provider: string;
    start_date: string | null;
    current_period_start: string | null;
    current_period_end: string | null;
    trial_ends_at: string | null;
    cancel_at_period_end: boolean;
    cancel_reason: string | null;
    scheduled_change_at: string | null;
    over_limit: boolean;
    past_due_since: string | null;
    grace_ends_at: string | null;
};
type Preview = {
    direction: string;
    current_plan: string;
    new_plan: string;
    current_monthly: number;
    new_monthly: number;
    proration: number;
    conflicts: { label: string; value: number; limit: number }[];
    policy: string;
    effective: string;
};

function ChangePlan({
    subscription,
    prices,
}: {
    subscription: Subscription;
    prices: { id: number; label: string }[];
}) {
    const [price, setPrice] = useState(prices[0]?.id ?? 0);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [reason, setReason] = useState('');

    return (
        <div className="space-y-2 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
            <div className="flex flex-wrap gap-2">
                <select
                    className={`${ccInput} h-8 w-auto`}
                    value={price}
                    onChange={(e) => {
                        setPrice(Number(e.target.value));
                        setPreview(null);
                    }}
                >
                    {prices.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.label}
                        </option>
                    ))}
                </select>
                <button
                    type="button"
                    className={ccGhost}
                    onClick={async () =>
                        setPreview(
                            await (
                                await fetch(
                                    `/control-center/subscriptions/${subscription.id}/preview?plan_price_id=${price}`,
                                    { headers: { Accept: 'application/json' } },
                                )
                            ).json(),
                        )
                    }
                >
                    Vista previa
                </button>
            </div>
            {preview && (
                <div className="space-y-1 text-xs text-slate-300">
                    <p>
                        {preview.current_plan} →{' '}
                        <b className="text-white">{preview.new_plan}</b> (
                        {
                            {
                                upgrade: 'mejora',
                                downgrade: 'reducción',
                                lateral: 'mismo nivel',
                            }[preview.direction]
                        }
                        ) · {money(preview.current_monthly)}/mes →{' '}
                        {money(preview.new_monthly)}/mes
                    </p>
                    {preview.proration > 0 && (
                        <p>
                            Ajuste prorrateado a facturar:{' '}
                            <b>{money(preview.proration)}</b>
                        </p>
                    )}
                    {preview.direction === 'downgrade' && (
                        <p>
                            Política:{' '}
                            {preview.policy === 'next_cycle'
                                ? `se aplicará al final del período (${date(preview.effective)})`
                                : 'inmediata si cabe en los límites'}
                        </p>
                    )}
                    {preview.conflicts.length > 0 ? (
                        <div className="rounded-lg bg-[#ef4444]/10 p-2 text-[#fca5a5]">
                            OVER_LIMIT — la organización excede:{' '}
                            {preview.conflicts
                                .map((c) => `${c.label} ${c.value}/${c.limit}`)
                                .join(', ')}
                            . No se elimina nada; debe reducir su consumo
                            primero.
                        </div>
                    ) : (
                        <div className="flex gap-2">
                            <input
                                className={`${ccInput} h-8`}
                                placeholder="Motivo"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                            <button
                                type="button"
                                className={ccButton}
                                disabled={reason.length < 5}
                                onClick={() =>
                                    router.post(
                                        `/control-center/subscriptions/${subscription.id}/change`,
                                        { plan_price_id: price, reason },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Aplicar
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Subscriptions({
    subscriptions,
    filters,
    statuses,
    prices,
    can_manage,
}: {
    subscriptions: Subscription[];
    filters: { status?: string; search?: string };
    statuses: Record<string, string>;
    prices: { id: number; label: string }[];
    can_manage: boolean;
}) {
    const [open, setOpen] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    return (
        <>
            <Head title="Suscripciones · Control Center" />
            <CCPage
                title="Suscripciones"
                subtitle="Ciclo de vida: prueba, activa, pago vencido, período de gracia, pausada, cancelada y vencida. Mejorar el plan aplica de inmediato con prorrateo; reducirlo se valida contra el consumo real."
            >
                <Panel>
                    <form
                        className="mb-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/subscriptions',
                                { ...filters, search },
                                { preserveState: true },
                            );
                        }}
                    >
                        <input
                            className={`${ccInput} max-w-xs`}
                            placeholder="Organización"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <select
                            className={`${ccInput} max-w-[200px]`}
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get('/control-center/subscriptions', {
                                    ...filters,
                                    status: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Todos los estados</option>
                            {Object.entries(statuses).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </form>
                    <Table
                        headers={[
                            'Organización',
                            'Plan',
                            'Ciclo',
                            'Precio',
                            'Descuento',
                            'Período',
                            'Renovación',
                            'Estado',
                            '',
                        ]}
                        empty={
                            subscriptions.length === 0 &&
                            'No hay suscripciones.'
                        }
                    >
                        {subscriptions.map((s) => (
                            <Fragment key={s.id}>
                                <tr key={s.id}>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/organizations/${s.organization_id}?tab=subscription`}
                                            className="font-semibold text-white"
                                        >
                                            {s.organization}
                                        </Link>
                                        <span className="block text-[10px] text-slate-500">
                                            {s.provider}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {s.plan ?? '—'}
                                        {s.plan_version !== null && (
                                            <span className="text-slate-500">
                                                {' '}
                                                v{s.plan_version}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {s.billing_cycle === 'annual'
                                            ? 'Anual'
                                            : 'Mensual'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(s.base_price, s.currency)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {s.discount_percent > 0
                                            ? `${s.discount_percent} %`
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {date(s.current_period_start)} –{' '}
                                        {date(s.current_period_end)}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {s.cancel_at_period_end
                                            ? 'Cancela al final'
                                            : s.scheduled_change_at
                                              ? `Cambio ${date(s.scheduled_change_at)}`
                                              : date(s.current_period_end)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[s.status]}>
                                            {s.status_label}
                                        </Pill>
                                        {s.over_limit && (
                                            <span className="mt-0.5 block text-[10px] text-[#f87171]">
                                                OVER_LIMIT
                                            </span>
                                        )}
                                        {s.grace_ends_at && (
                                            <span className="block text-[10px] text-[#fbbf24]">
                                                Gracia hasta{' '}
                                                {date(s.grace_ends_at)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {can_manage && (
                                            <button
                                                type="button"
                                                className={ccGhost}
                                                onClick={() =>
                                                    setOpen(
                                                        open === s.id
                                                            ? null
                                                            : s.id,
                                                    )
                                                }
                                            >
                                                Gestionar
                                            </button>
                                        )}
                                    </td>
                                </tr>
                                {open === s.id && (
                                    <tr key={`${s.id}-actions`}>
                                        <td
                                            colSpan={9}
                                            className="space-y-2 bg-black/20 p-3"
                                        >
                                            <ChangePlan
                                                subscription={s}
                                                prices={prices}
                                            />
                                            <div className="flex flex-wrap gap-2">
                                                {s.status === 'trialing' && (
                                                    <button
                                                        type="button"
                                                        className={ccGhost}
                                                        onClick={() => {
                                                            const until =
                                                                window.prompt(
                                                                    'Extender la prueba hasta (AAAA-MM-DD)',
                                                                );
                                                            const reason =
                                                                until &&
                                                                window.prompt(
                                                                    'Motivo',
                                                                );

                                                            if (
                                                                until &&
                                                                reason
                                                            ) {
                                                                router.post(
                                                                    `/control-center/subscriptions/${s.id}/extend-trial`,
                                                                    {
                                                                        until,
                                                                        reason,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Extender prueba
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    className={ccGhost}
                                                    onClick={() => {
                                                        const discount =
                                                            window.prompt(
                                                                'Descuento % (0-100)',
                                                                String(
                                                                    s.discount_percent,
                                                                ),
                                                            );
                                                        const reason =
                                                            discount !== null &&
                                                            window.prompt(
                                                                'Motivo',
                                                            );

                                                        if (
                                                            discount !== null &&
                                                            reason
                                                        ) {
                                                            router.post(
                                                                `/control-center/subscriptions/${s.id}/discount`,
                                                                {
                                                                    discount_percent:
                                                                        discount,
                                                                    reason,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    Descuento
                                                </button>
                                                {s.status === 'active' && (
                                                    <SensitiveAction
                                                        url={`/control-center/subscriptions/${s.id}/pause`}
                                                        label="Pausar"
                                                        title="Pausar suscripción"
                                                    />
                                                )}
                                                {s.status === 'paused' && (
                                                    <button
                                                        type="button"
                                                        className={ccGhost}
                                                        onClick={() => {
                                                            const reason =
                                                                window.prompt(
                                                                    'Motivo',
                                                                );

                                                            if (reason) {
                                                                router.post(
                                                                    `/control-center/subscriptions/${s.id}/resume`,
                                                                    { reason },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Reanudar
                                                    </button>
                                                )}
                                                {![
                                                    'cancelled',
                                                    'expired',
                                                ].includes(s.status) && (
                                                    <>
                                                        <SensitiveAction
                                                            danger
                                                            url={`/control-center/subscriptions/${s.id}/cancel`}
                                                            data={{
                                                                immediately: false,
                                                            }}
                                                            label="Cancelar al final del período"
                                                            title="Cancelar al final del período"
                                                            description={`Seguirá activa hasta ${date(s.current_period_end)}.`}
                                                        />
                                                        <SensitiveAction
                                                            danger
                                                            url={`/control-center/subscriptions/${s.id}/cancel`}
                                                            data={{
                                                                immediately: true,
                                                            }}
                                                            label="Cancelar de inmediato"
                                                            title="Cancelar de inmediato"
                                                            description="La organización pierde acceso ahora; sus datos se conservan."
                                                        />
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
