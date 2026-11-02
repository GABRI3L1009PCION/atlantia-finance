import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Building2,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Download,
    Eye,
    FlaskConical,
    Layers,
    Pencil,
    Plus,
    Search,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    Field,
    Notice,
    Pill,
    RowMenu,
    SensitiveAction,
    ccButton,
    ccGhost,
    ccInput,
    date,
    money,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Subscription = {
    id: number;
    organization_id: number;
    organization_public_id: string | null;
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
        <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
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
                <div className="space-y-1 text-xs text-slate-700">
                    <p>
                        {preview.current_plan} →{' '}
                        <b className="text-slate-900">{preview.new_plan}</b> (
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
                        <div className="rounded-lg bg-[#ef4444]/10 p-2 text-[#b91c1c]">
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

const PAGE_SIZE = 10;

const cycleLabel = (cycle: string | null): string =>
    cycle === 'annual' ? 'Anual' : 'Mensual';

function Modal({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div className="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <div className={`${panel} w-full max-w-2xl space-y-4 p-5`}>
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="text-slate-500 hover:text-slate-900"
                    >
                        <X className="size-5" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

/** Creates the subscription of a client organization that has none in force. */
function NewSubscription({
    organizations,
    prices,
    onClose,
}: {
    organizations: { id: number; public_id: string; name: string }[];
    prices: { id: number; label: string }[];
    onClose: () => void;
}) {
    const form = useForm({
        organization_id: organizations[0]?.public_id ?? '',
        plan_price_id: prices[0]?.id ?? 0,
        trial: true,
        discount_percent: '',
    });

    return (
        <Modal title="Nueva suscripción" onClose={onClose}>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(
                        ({ organization_id: organization, ...data }) => {
                            void organization;

                            return data;
                        },
                    );
                    form.post(
                        `/control-center/organizations/${form.data.organization_id}/subscriptions`,
                        { preserveScroll: true, onSuccess: onClose },
                    );
                }}
            >
                <div className="grid gap-3 md:grid-cols-2">
                    <Field label="Organización" wide>
                        <select
                            className={ccInput}
                            value={form.data.organization_id}
                            onChange={(e) =>
                                form.setData('organization_id', e.target.value)
                            }
                        >
                            {organizations.map((organization) => (
                                <option
                                    key={organization.public_id}
                                    value={organization.public_id}
                                >
                                    {organization.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Plan y ciclo" wide>
                        <select
                            className={ccInput}
                            value={form.data.plan_price_id}
                            onChange={(e) =>
                                form.setData(
                                    'plan_price_id',
                                    Number(e.target.value),
                                )
                            }
                        >
                            {prices.map((price) => (
                                <option key={price.id} value={price.id}>
                                    {price.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Descuento % (opcional)">
                        <input
                            type="number"
                            min={0}
                            max={100}
                            className={ccInput}
                            value={form.data.discount_percent}
                            onChange={(e) =>
                                form.setData('discount_percent', e.target.value)
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.trial}
                            onChange={(e) =>
                                form.setData('trial', e.target.checked)
                            }
                        />
                        Empezar con período de prueba
                    </label>
                </div>
                <p className="text-xs text-slate-500">
                    Solo para organizaciones sin una suscripción vigente; si ya
                    tiene una, cambia su plan desde el lápiz.
                </p>
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-[#b91c1c]">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ccGhost} onClick={onClose}>
                        Cancelar
                    </button>
                    <button
                        className={ccButton}
                        disabled={form.processing || organizations.length === 0}
                    >
                        Crear suscripción
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/** Everything that can be done to one subscription: plan, discount, trial, pause and cancellation. */
function ManageSubscription({
    subscription: s,
    prices,
    onClose,
}: {
    subscription: Subscription;
    prices: { id: number; label: string }[];
    onClose: () => void;
}) {
    const [discount, setDiscount] = useState(String(s.discount_percent));
    const [discountReason, setDiscountReason] = useState('');
    const [until, setUntil] = useState('');
    const [trialReason, setTrialReason] = useState('');
    const [resumeReason, setResumeReason] = useState('');
    const post = (url: string, data: Record<string, unknown>) =>
        router.post(url, data as never, { preserveScroll: true });
    const section = 'space-y-2 border-t border-slate-100 pt-3';
    const heading = 'text-sm font-bold text-slate-900';

    return (
        <Modal
            title={`Gestionar suscripción · ${s.organization ?? ''}`}
            onClose={onClose}
        >
            <p className="text-sm text-slate-600">
                {s.plan ?? '—'}
                {s.plan_version !== null && ` v${s.plan_version}`} ·{' '}
                {cycleLabel(s.billing_cycle)} ·{' '}
                {money(s.base_price, s.currency)} · {s.status_label}
            </p>
            <div className="space-y-2">
                <p className={heading}>Cambiar de plan</p>
                <ChangePlan subscription={s} prices={prices} />
            </div>
            <div className={section}>
                <p className={heading}>Descuento</p>
                <div className="flex flex-wrap gap-2">
                    <input
                        type="number"
                        min={0}
                        max={100}
                        className={`${ccInput} h-9 max-w-28`}
                        value={discount}
                        onChange={(e) => setDiscount(e.target.value)}
                        aria-label="Descuento %"
                    />
                    <input
                        className={`${ccInput} h-9 flex-1`}
                        placeholder="Motivo"
                        value={discountReason}
                        onChange={(e) => setDiscountReason(e.target.value)}
                    />
                    <button
                        type="button"
                        className={ccButton}
                        disabled={discountReason.trim().length < 3}
                        onClick={() =>
                            post(
                                `/control-center/subscriptions/${s.id}/discount`,
                                {
                                    discount_percent: discount,
                                    reason: discountReason,
                                },
                            )
                        }
                    >
                        Aplicar
                    </button>
                </div>
            </div>
            {s.status === 'trialing' && (
                <div className={section}>
                    <p className={heading}>Extender la prueba</p>
                    <div className="flex flex-wrap gap-2">
                        <input
                            type="date"
                            className={`${ccInput} h-9 max-w-44`}
                            value={until}
                            onChange={(e) => setUntil(e.target.value)}
                            aria-label="Hasta"
                        />
                        <input
                            className={`${ccInput} h-9 flex-1`}
                            placeholder="Motivo"
                            value={trialReason}
                            onChange={(e) => setTrialReason(e.target.value)}
                        />
                        <button
                            type="button"
                            className={ccButton}
                            disabled={
                                until === '' || trialReason.trim().length < 3
                            }
                            onClick={() =>
                                post(
                                    `/control-center/subscriptions/${s.id}/extend-trial`,
                                    { until, reason: trialReason },
                                )
                            }
                        >
                            Extender
                        </button>
                    </div>
                </div>
            )}
            {s.status === 'paused' && (
                <div className={section}>
                    <p className={heading}>Reanudar</p>
                    <div className="flex gap-2">
                        <input
                            className={`${ccInput} h-9 flex-1`}
                            placeholder="Motivo"
                            value={resumeReason}
                            onChange={(e) => setResumeReason(e.target.value)}
                        />
                        <button
                            type="button"
                            className={ccButton}
                            disabled={resumeReason.trim().length < 3}
                            onClick={() =>
                                post(
                                    `/control-center/subscriptions/${s.id}/resume`,
                                    { reason: resumeReason },
                                )
                            }
                        >
                            Reanudar
                        </button>
                    </div>
                </div>
            )}
            {(s.status === 'active' ||
                !['cancelled', 'expired'].includes(s.status)) && (
                <div className={`${section} flex flex-wrap gap-2`}>
                    {s.status === 'active' && (
                        <SensitiveAction
                            url={`/control-center/subscriptions/${s.id}/pause`}
                            label="Pausar"
                            title="Pausar suscripción"
                        />
                    )}
                    {!['cancelled', 'expired'].includes(s.status) && (
                        <>
                            <SensitiveAction
                                danger
                                url={`/control-center/subscriptions/${s.id}/cancel`}
                                data={{ immediately: false }}
                                label="Cancelar al final del período"
                                title="Cancelar al final del período"
                                description={`Seguirá activa hasta ${date(s.current_period_end)}.`}
                            />
                            <SensitiveAction
                                danger
                                url={`/control-center/subscriptions/${s.id}/cancel`}
                                data={{ immediately: true }}
                                label="Cancelar de inmediato"
                                title="Cancelar de inmediato"
                                description="La organización pierde acceso ahora; sus datos se conservan."
                            />
                        </>
                    )}
                </div>
            )}
        </Modal>
    );
}

/** Downloads the subscriptions on screen as a CSV that Excel opens with accents intact. */
function exportCsv(rows: Subscription[]): void {
    const quote = (value: string | number | null): string =>
        `"${String(value ?? '').replaceAll('"', '""')}"`;
    const lines = [
        [
            'Organización',
            'Plan',
            'Ciclo',
            'Precio',
            'Moneda',
            'Descuento %',
            'Inicio del período',
            'Fin del período',
            'Estado',
            'Proveedor',
        ],
        ...rows.map((s) => [
            s.organization,
            s.plan,
            cycleLabel(s.billing_cycle),
            s.base_price,
            s.currency,
            s.discount_percent,
            s.current_period_start?.slice(0, 10) ?? '',
            s.current_period_end?.slice(0, 10) ?? '',
            s.status_label,
            s.provider,
        ]),
    ];
    const csv =
        '﻿' + lines.map((line) => line.map(quote).join(',')).join('\r\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    );
    link.download = `suscripciones-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function Subscriptions({
    subscriptions,
    filters,
    statuses,
    prices,
    organizations,
    can_manage,
}: {
    subscriptions: Subscription[];
    filters: { status?: string; search?: string };
    statuses: Record<string, string>;
    prices: { id: number; label: string }[];
    organizations: { id: number; public_id: string; name: string }[];
    can_manage: boolean;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [plan, setPlan] = useState('');
    const [cycle, setCycle] = useState('');
    const [page, setPage] = useState(1);
    const [creating, setCreating] = useState(false);
    const [managing, setManaging] = useState<Subscription | null>(null);
    const plans = [
        ...new Set(
            subscriptions
                .map((s) => s.plan)
                .filter((value): value is string => Boolean(value)),
        ),
    ].sort((a, b) => a.localeCompare(b, 'es'));
    const visible = subscriptions.filter(
        (s) =>
            (plan === '' || s.plan === plan) &&
            (cycle === '' ||
                (s.billing_cycle === 'annual' ? 'annual' : 'monthly') ===
                    cycle),
    );
    const pages = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
    const current = Math.min(page, pages);
    const shown = visible.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
    const count = (...states: string[]) =>
        subscriptions.filter((s) => states.includes(s.status)).length;
    const kpis = [
        {
            label: 'Total de suscripciones',
            value: subscriptions.length,
            icon: Layers,
            tile: 'bg-[#7a0010] text-white',
        },
        {
            label: 'Activas',
            value: count('active'),
            icon: CircleCheck,
            tile: 'bg-[#15803d] text-white',
        },
        {
            label: 'En prueba',
            value: count('trialing', 'trial'),
            icon: FlaskConical,
            tile: 'bg-[#d39a2c] text-white',
        },
        {
            label: 'Canceladas',
            value: count('cancelled'),
            icon: Ban,
            tile: 'bg-slate-500 text-white',
        },
        {
            label: 'Pago vencido',
            value: count('past_due', 'grace_period'),
            icon: AlertTriangle,
            tile: 'bg-[#fde2e2] text-[#b91c1c]',
        },
    ];
    const iconButton =
        'grid size-8 place-items-center rounded-lg border border-[#7a0010]/40 text-[#7a0010] hover:bg-[#7a0010]/5';
    const cell = 'px-2.5 py-2.5 whitespace-nowrap';

    return (
        <>
            <Head title="Suscripciones · Control Center" />
            <div className="space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="border-l-4 border-[#e0a526] pl-5">
                        <h1 className="font-serif text-4xl font-bold text-slate-900">
                            Suscripciones
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600">
                            Ciclo de vida: prueba, activa, pago vencido, período
                            de gracia, pausada, cancelada y vencida. Mejorar el
                            plan aplica de inmediato con prorrateo; reducirlo se
                            valida contra el consumo real.
                        </p>
                    </div>
                    {can_manage && (
                        <button
                            type="button"
                            onClick={() => setCreating(true)}
                            className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#7a0010] px-5 text-sm font-bold text-white shadow-[0_10px_24px_-14px_rgba(122,0,16,.9)] transition hover:bg-[#8f0b20]"
                        >
                            <Plus className="size-5" /> Nueva suscripción
                        </button>
                    )}
                </header>
                <Notice />

                <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.label}
                            className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]"
                        >
                            <span
                                className={`grid size-12 shrink-0 place-items-center rounded-xl ${kpi.tile}`}
                            >
                                <kpi.icon className="size-6" />
                            </span>
                            <span>
                                <span className="block font-serif text-2xl leading-tight font-bold text-slate-900">
                                    {kpi.value}
                                </span>
                                <span className="block text-sm text-slate-700">
                                    {kpi.label}
                                </span>
                            </span>
                        </div>
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]">
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <form
                            className="relative w-full max-w-xs"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(
                                    '/control-center/subscriptions',
                                    { ...filters, search },
                                    { preserveState: true },
                                );
                            }}
                        >
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                className={`${ccInput} pl-9`}
                                placeholder="Buscar organización…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </form>
                        <select
                            className={`${ccInput} max-w-[190px]`}
                            value={plan}
                            onChange={(e) => {
                                setPlan(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Todos los planes</option>
                            {plans.map((name) => (
                                <option key={name} value={name}>
                                    {name}
                                </option>
                            ))}
                        </select>
                        <select
                            className={`${ccInput} max-w-[190px]`}
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
                        <select
                            className={`${ccInput} max-w-[170px]`}
                            value={cycle}
                            onChange={(e) => {
                                setCycle(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Todos los ciclos</option>
                            <option value="monthly">Mensual</option>
                            <option value="annual">Anual</option>
                        </select>
                        <button
                            type="button"
                            onClick={() => exportCsv(visible)}
                            disabled={visible.length === 0}
                            className="ml-auto inline-flex h-10 items-center gap-2 rounded-lg border-2 border-[#7a0010]/70 px-4 text-sm font-bold text-[#7a0010] transition hover:bg-[#7a0010]/5 disabled:opacity-40"
                        >
                            <Download className="size-4" /> Exportar
                        </button>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="min-w-full text-[13px] text-slate-800">
                            <thead>
                                <tr className="bg-[#7a0010] text-left font-semibold whitespace-nowrap text-white">
                                    {[
                                        'Organización',
                                        'Plan',
                                        'Ciclo',
                                        'Precio',
                                        'Descuento',
                                        'Período',
                                        'Renovación',
                                        'Estado',
                                    ].map((header) => (
                                        <th key={header} className="px-3 py-3">
                                            {header}
                                        </th>
                                    ))}
                                    <th className="px-3 py-3 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {shown.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-4 py-10 text-center text-slate-500"
                                        >
                                            {subscriptions.length === 0
                                                ? 'No hay suscripciones.'
                                                : 'Ninguna suscripción coincide con los filtros.'}
                                        </td>
                                    </tr>
                                )}
                                {shown.map((s) => {
                                    const organizationUrl = `/control-center/organizations/${s.organization_public_id}`;

                                    return (
                                        <tr
                                            key={s.id}
                                            className="hover:bg-slate-50"
                                        >
                                            <td className="min-w-[200px] px-3 py-2.5">
                                                <Link
                                                    href={`${organizationUrl}?tab=subscription`}
                                                    className="flex items-center gap-3"
                                                >
                                                    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-[#7a0010]/10 text-[#7a0010]">
                                                        <Building2 className="size-5" />
                                                    </span>
                                                    <span>
                                                        <span className="block leading-tight font-bold text-slate-900">
                                                            {s.organization}
                                                        </span>
                                                        <span className="block text-xs text-slate-500">
                                                            {s.provider}
                                                        </span>
                                                    </span>
                                                </Link>
                                            </td>
                                            <td className={cell}>
                                                {s.plan ?? '—'}
                                                {s.plan_version !== null &&
                                                    ` v${s.plan_version}`}
                                            </td>
                                            <td className={cell}>
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                                    {cycleLabel(
                                                        s.billing_cycle,
                                                    )}
                                                </span>
                                            </td>
                                            <td className={cell}>
                                                {money(
                                                    s.base_price,
                                                    s.currency,
                                                )}
                                            </td>
                                            <td className={cell}>
                                                {s.discount_percent > 0
                                                    ? `${s.discount_percent} %`
                                                    : '—'}
                                            </td>
                                            <td className={cell}>
                                                {s.current_period_start
                                                    ? `${date(s.current_period_start)} – ${date(s.current_period_end)}`
                                                    : '—'}
                                            </td>
                                            <td className={cell}>
                                                {s.cancel_at_period_end
                                                    ? 'Cancela al final'
                                                    : s.scheduled_change_at
                                                      ? `Cambio ${date(s.scheduled_change_at)}`
                                                      : date(
                                                            s.current_period_end,
                                                        )}
                                            </td>
                                            <td className={cell}>
                                                <Pill
                                                    tone={statusTone[s.status]}
                                                >
                                                    {s.status_label}
                                                </Pill>
                                                {s.over_limit && (
                                                    <span className="mt-0.5 block text-[10px] text-[#dc2626]">
                                                        Excede su plan
                                                    </span>
                                                )}
                                                {s.grace_ends_at && (
                                                    <span className="block text-[10px] text-[#b45309]">
                                                        Gracia hasta{' '}
                                                        {date(s.grace_ends_at)}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <div className="flex items-center justify-center gap-1.5">
                                                    <Link
                                                        href={`${organizationUrl}?tab=subscription`}
                                                        className={iconButton}
                                                        aria-label="Ver"
                                                        title="Ver la suscripción en la organización"
                                                    >
                                                        <Eye className="size-4" />
                                                    </Link>
                                                    {can_manage && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setManaging(s)
                                                            }
                                                            className={
                                                                iconButton
                                                            }
                                                            aria-label="Gestionar"
                                                            title="Plan, descuento, prueba, pausa y cancelación"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </button>
                                                    )}
                                                    <RowMenu
                                                        links={[
                                                            {
                                                                label: 'Billing de la organización',
                                                                href: `${organizationUrl}?tab=billing`,
                                                            },
                                                            {
                                                                label: 'Consumo',
                                                                href: `${organizationUrl}?tab=usage`,
                                                            },
                                                            {
                                                                label: 'Plan y funciones',
                                                                href: `${organizationUrl}?tab=features`,
                                                            },
                                                            {
                                                                label: 'Actividad',
                                                                href: `${organizationUrl}?tab=activity`,
                                                            },
                                                        ]}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 px-1">
                        <p className="text-sm text-slate-600">
                            {visible.length === 0
                                ? 'Sin suscripciones para mostrar'
                                : `Mostrando ${(current - 1) * PAGE_SIZE + 1} a ${(current - 1) * PAGE_SIZE + shown.length} de ${visible.length} suscripci${visible.length === 1 ? 'ón' : 'ones'}`}
                        </p>
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                aria-label="Página anterior"
                                disabled={current === 1}
                                onClick={() => setPage(current - 1)}
                                className={`${iconButton} size-9 disabled:opacity-40`}
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                            {Array.from({ length: pages }, (_, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    onClick={() => setPage(index + 1)}
                                    aria-current={
                                        current === index + 1
                                            ? 'page'
                                            : undefined
                                    }
                                    className={`grid size-9 place-items-center rounded-lg text-sm font-bold ${current === index + 1 ? 'bg-[#7a0010] text-white' : 'border border-slate-300 text-slate-700 hover:border-[#7a0010]/50'}`}
                                >
                                    {index + 1}
                                </button>
                            ))}
                            <button
                                type="button"
                                aria-label="Página siguiente"
                                disabled={current === pages}
                                onClick={() => setPage(current + 1)}
                                className={`${iconButton} size-9 disabled:opacity-40`}
                            >
                                <ChevronRight className="size-4" />
                            </button>
                        </div>
                    </div>
                </section>
            </div>
            {creating && (
                <NewSubscription
                    organizations={organizations}
                    prices={prices}
                    onClose={() => setCreating(false)}
                />
            )}
            {managing && (
                <ManageSubscription
                    key={managing.id}
                    subscription={managing}
                    prices={prices}
                    onClose={() => setManaging(null)}
                />
            )}
        </>
    );
}
