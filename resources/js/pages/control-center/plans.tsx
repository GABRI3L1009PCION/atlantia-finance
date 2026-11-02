import { Head, router, useForm } from '@inertiajs/react';
import {
    Ban,
    BarChart3,
    Boxes,
    Briefcase,
    Building2,
    ChevronLeft,
    ChevronRight,
    Crown,
    Database,
    ExternalLink,
    Eye,
    Gem,
    Layers,
    Plus,
    Rocket,
    TreePalm,
    User,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useRef, useState } from 'react';
import {
    Field,
    Notice,
    Pill,
    SensitiveAction,
    bytes,
    ccButton,
    ccGhost,
    ccInput,
    date,
    money,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Limit = {
    value: number | null;
    unit?: string;
    warning?: number;
    high?: number;
    enforcement?: string;
};
type Version = {
    id: number;
    version: number;
    name: string;
    description: string | null;
    monthly_price: string;
    annual_price: string;
    entitlements: string[];
    limits: Record<string, Limit>;
    status: string;
    change_note: string | null;
    published_at: string | null;
    subscriptions: number;
};
type Plan = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    subscribers: number;
    versions: Version[];
    presentation: Presentation;
};
type Presentation = {
    tagline: string | null;
    highlights: string[];
    is_recommended: boolean;
    price_prefix: string | null;
    cta_label: string | null;
};
type Feature = {
    code: string;
    name: string;
    module: string | null;
    release_stage: string;
};
type Metrics = Record<string, { label: string; unit: string }>;

/** Sales-page copy of a plan (prices and limits always come from the published version). */
function PresentationEditor({
    plan,
    buttonClassName,
    children,
}: {
    plan: Plan;
    buttonClassName?: string;
    children?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        tagline: plan.presentation.tagline ?? '',
        highlights: plan.presentation.highlights.join('\n'),
        is_recommended: plan.presentation.is_recommended,
        price_prefix: plan.presentation.price_prefix ?? '',
        cta_label: plan.presentation.cta_label ?? '',
        reason: '',
    });

    return (
        <>
            <button
                type="button"
                className={buttonClassName ?? ccGhost}
                onClick={() => setOpen(true)}
            >
                {children ?? 'Página de ventas'}
            </button>
            {open && (
                <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
                    <form
                        className={`${panel} grid w-full max-w-xl gap-3 p-5`}
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                ...data,
                                highlights: data.highlights.split('\n'),
                                tagline: data.tagline || null,
                                price_prefix: data.price_prefix || null,
                                cta_label: data.cta_label || null,
                            }));
                            form.post(
                                `/control-center/plans/${plan.id}/presentation`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                },
                            );
                        }}
                    >
                        <h2 className="text-lg font-bold text-slate-900">
                            Página de ventas · {plan.name}
                        </h2>
                        <Field label="Lema (sobre el nombre)">
                            <input
                                className={ccInput}
                                value={form.data.tagline}
                                onChange={(e) =>
                                    form.setData('tagline', e.target.value)
                                }
                                maxLength={120}
                            />
                        </Field>
                        <Field label="Frases destacadas (una por línea, máx. 6). Los límites se agregan solos desde la versión publicada.">
                            <textarea
                                className={`${ccInput} h-28 py-2`}
                                value={form.data.highlights}
                                onChange={(e) =>
                                    form.setData('highlights', e.target.value)
                                }
                            />
                        </Field>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Field label="Prefijo del precio (ej. Desde)">
                                <input
                                    className={ccInput}
                                    value={form.data.price_prefix}
                                    onChange={(e) =>
                                        form.setData(
                                            'price_prefix',
                                            e.target.value,
                                        )
                                    }
                                    maxLength={30}
                                />
                            </Field>
                            <Field label="Texto del botón (vacío: Elegir este plan)">
                                <input
                                    className={ccInput}
                                    value={form.data.cta_label}
                                    onChange={(e) =>
                                        form.setData(
                                            'cta_label',
                                            e.target.value,
                                        )
                                    }
                                    maxLength={60}
                                />
                            </Field>
                        </div>
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_recommended}
                                onChange={(e) =>
                                    form.setData(
                                        'is_recommended',
                                        e.target.checked,
                                    )
                                }
                            />
                            Plan recomendado (muestra «Más elegido»; solo puede
                            haber uno)
                        </label>
                        <Field label="Motivo del cambio">
                            <input
                                className={ccInput}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                                required
                                minLength={5}
                            />
                        </Field>
                        {Object.values(form.errors).length > 0 && (
                            <p className="text-xs text-[#dc2626]">
                                {Object.values(form.errors).join(' ')}
                            </p>
                        )}
                        <div className="flex gap-2">
                            <button
                                className={ccButton}
                                disabled={form.processing}
                            >
                                Guardar
                            </button>
                            <button
                                type="button"
                                className={ccGhost}
                                onClick={() => setOpen(false)}
                            >
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}

function VersionEditor({
    plan,
    features,
    metrics,
    onClose,
}: {
    plan: Plan | null;
    features: Feature[];
    metrics: Metrics;
    onClose: () => void;
}) {
    const current =
        plan?.versions.find((v) => v.status === 'published') ??
        plan?.versions[0];
    const form = useForm({
        code: '',
        change_note: '',
        name: current?.name ?? '',
        description: current?.description ?? '',
        monthly_price: current?.monthly_price ?? '0',
        annual_price: current?.annual_price ?? '0',
        entitlements: current?.entitlements ?? [],
        limits: Object.fromEntries(
            Object.keys(metrics).map((metric) => [
                metric,
                {
                    value: current?.limits[metric]?.value ?? null,
                    warning: current?.limits[metric]?.warning ?? 80,
                    high: current?.limits[metric]?.high ?? 95,
                    enforcement: current?.limits[metric]?.enforcement ?? 'hard',
                },
            ]),
        ) as Record<string, Limit>,
    });

    return (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <form
                className="w-full max-w-4xl space-y-3 rounded-2xl border border-slate-200 bg-white p-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(
                        plan
                            ? `/control-center/plans/${plan.id}/versions`
                            : '/control-center/plans',
                        { preserveScroll: true, onSuccess: onClose },
                    );
                }}
            >
                <h2 className="text-lg font-bold text-slate-900">
                    {plan ? `Nueva versión de ${plan.name}` : 'Nuevo plan'}
                </h2>
                <p className="text-xs text-slate-500">
                    Se guarda como borrador. Al publicarla será lo que se vende;
                    las suscripciones existentes conservan su versión.
                </p>
                <div className="grid gap-3 md:grid-cols-4">
                    {!plan && (
                        <Field label="Código">
                            <input
                                className={ccInput}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="ej. growth"
                                required
                            />
                        </Field>
                    )}
                    <Field label="Nombre">
                        <input
                            className={ccInput}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Precio mensual (Q)">
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            className={ccInput}
                            value={form.data.monthly_price}
                            onChange={(e) =>
                                form.setData('monthly_price', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Precio anual (Q)">
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            className={ccInput}
                            value={form.data.annual_price}
                            onChange={(e) =>
                                form.setData('annual_price', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Descripción" wide>
                        <input
                            className={ccInput}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </Field>
                    {plan && (
                        <Field label="Nota del cambio" wide>
                            <input
                                className={ccInput}
                                value={form.data.change_note}
                                onChange={(e) =>
                                    form.setData('change_note', e.target.value)
                                }
                                required
                            />
                        </Field>
                    )}
                </div>
                <p className="text-xs font-bold text-slate-700">
                    Funciones (entitlements)
                </p>
                <div className="grid max-h-56 gap-1 overflow-y-auto rounded-xl border border-slate-200 p-3 text-xs md:grid-cols-3">
                    {features.map((feature) => (
                        <label
                            key={feature.code}
                            className="flex items-center gap-2 text-slate-700"
                        >
                            <input
                                type="checkbox"
                                checked={form.data.entitlements.includes(
                                    feature.code,
                                )}
                                onChange={(e) =>
                                    form.setData(
                                        'entitlements',
                                        e.target.checked
                                            ? [
                                                  ...form.data.entitlements,
                                                  feature.code,
                                              ]
                                            : form.data.entitlements.filter(
                                                  (c) => c !== feature.code,
                                              ),
                                    )
                                }
                            />
                            {feature.name}{' '}
                            <span className="font-mono text-[10px] text-slate-500">
                                {feature.code}
                            </span>
                        </label>
                    ))}
                </div>
                <p className="text-xs font-bold text-slate-700">
                    Límites (vacío = ilimitado)
                </p>
                <div className="grid gap-2">
                    {Object.entries(metrics).map(([metric, definition]) => (
                        <div
                            key={metric}
                            className="grid grid-cols-[180px_1fr_90px_90px_120px] items-center gap-2 text-xs"
                        >
                            <span className="text-slate-700">
                                {definition.label}
                            </span>
                            <input
                                type="number"
                                min={0}
                                className={ccInput}
                                value={form.data.limits[metric]?.value ?? ''}
                                placeholder="Ilimitado"
                                onChange={(e) =>
                                    form.setData('limits', {
                                        ...form.data.limits,
                                        [metric]: {
                                            ...form.data.limits[metric],
                                            value:
                                                e.target.value === ''
                                                    ? null
                                                    : Number(e.target.value),
                                        },
                                    })
                                }
                            />
                            <input
                                type="number"
                                min={1}
                                max={100}
                                className={ccInput}
                                title="Advertencia %"
                                value={form.data.limits[metric]?.warning ?? 80}
                                onChange={(e) =>
                                    form.setData('limits', {
                                        ...form.data.limits,
                                        [metric]: {
                                            ...form.data.limits[metric],
                                            warning: Number(e.target.value),
                                        },
                                    })
                                }
                            />
                            <input
                                type="number"
                                min={1}
                                max={100}
                                className={ccInput}
                                title="Alto %"
                                value={form.data.limits[metric]?.high ?? 95}
                                onChange={(e) =>
                                    form.setData('limits', {
                                        ...form.data.limits,
                                        [metric]: {
                                            ...form.data.limits[metric],
                                            high: Number(e.target.value),
                                        },
                                    })
                                }
                            />
                            <select
                                className={ccInput}
                                value={
                                    form.data.limits[metric]?.enforcement ??
                                    'hard'
                                }
                                onChange={(e) =>
                                    form.setData('limits', {
                                        ...form.data.limits,
                                        [metric]: {
                                            ...form.data.limits[metric],
                                            enforcement: e.target.value,
                                        },
                                    })
                                }
                            >
                                <option value="hard">Bloquea (hard)</option>
                                <option value="soft">Solo avisa (soft)</option>
                            </select>
                        </div>
                    ))}
                </div>
                {Object.values(form.errors).length > 0 && (
                    <div className="rounded-lg bg-[#ef4444]/10 p-2 text-xs text-[#b91c1c]">
                        {Object.values(form.errors).map((e) => (
                            <p key={e}>{e}</p>
                        ))}
                    </div>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ccGhost} onClick={onClose}>
                        Cancelar
                    </button>
                    <button className={ccButton} disabled={form.processing}>
                        Guardar borrador
                    </button>
                </div>
            </form>
        </div>
    );
}

const versionLabels: Record<string, string> = {
    draft: 'Borrador',
    published: 'Publicada',
    retired: 'Retirada',
};

const limitIcons: Record<string, LucideIcon> = {
    users: User,
    offices: Building2,
    ai_queries: BarChart3,
    active_cases: Briefcase,
    storage_bytes: Database,
};

const planIcons: LucideIcon[] = [Layers, Crown, Boxes, Gem, Rocket];

function limitValue(metric: string, limit: Limit | undefined): string {
    if (limit === undefined || limit.value === null) {
        return 'Ilimitado';
    }

    return metric === 'storage_bytes'
        ? bytes(limit.value)
        : limit.value.toLocaleString('es-GT');
}

/** Every version of a plan, with the drafts that can still be published or discarded. */
function VersionsModal({
    plan,
    metrics,
    canManage,
    onClose,
}: {
    plan: Plan;
    metrics: Metrics;
    canManage: boolean;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <div className={`${panel} w-full max-w-2xl space-y-3 p-5`}>
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900">
                        {plan.name}{' '}
                        <span className="font-mono text-xs text-slate-500">
                            {plan.code}
                        </span>
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
                <p className="text-sm text-slate-600">{plan.description}</p>
                <div className="space-y-2">
                    {plan.versions.map((version) => (
                        <div
                            key={version.id}
                            className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-bold text-slate-900">
                                    v{version.version} · {version.name}
                                </span>
                                <div className="flex items-center gap-2">
                                    <Pill tone={statusTone[version.status]}>
                                        {versionLabels[version.status] ??
                                            version.status}
                                    </Pill>
                                    <span className="text-slate-500">
                                        {version.subscriptions} suscripción(es)
                                    </span>
                                </div>
                            </div>
                            <p className="mt-1 text-slate-700">
                                {money(Number(version.monthly_price))}/mes ·{' '}
                                {money(Number(version.annual_price))}/año ·{' '}
                                {version.entitlements.length} funciones
                            </p>
                            <p className="mt-1 text-slate-500">
                                {Object.entries(version.limits)
                                    .map(
                                        ([metric, limit]) =>
                                            `${metrics[metric]?.label ?? metric}: ${limitValue(metric, limit)}`,
                                    )
                                    .join(' · ')}
                            </p>
                            {version.change_note && (
                                <p className="mt-1 text-slate-500 italic">
                                    {version.change_note}{' '}
                                    {version.published_at &&
                                        `· publicada ${date(version.published_at)}`}
                                </p>
                            )}
                            {canManage && version.status === 'draft' && (
                                <div className="mt-2 flex gap-2">
                                    <SensitiveAction
                                        url={`/control-center/plan-versions/${version.id}/publish`}
                                        label="Publicar"
                                        title={`Publicar v${version.version} de ${plan.name}`}
                                        description="Será lo que se vende desde ahora (precios, funciones y límites del checkout). Las suscripciones existentes conservan su versión."
                                    />
                                    <button
                                        type="button"
                                        className={ccGhost}
                                        onClick={() =>
                                            router.post(
                                                `/control-center/plan-versions/${version.id}/discard`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Descartar
                                    </button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function PlanCard({
    plan,
    position,
    metrics,
    canManage,
    onView,
    onNewVersion,
}: {
    plan: Plan;
    position: number;
    metrics: Metrics;
    canManage: boolean;
    onView: () => void;
    onNewVersion: () => void;
}) {
    const current =
        plan.versions.find((version) => version.status === 'published') ??
        plan.versions[0];
    const draft = plan.versions.find((version) => version.status === 'draft');
    const Icon = planIcons[position % planIcons.length];
    const recommended = plan.presentation.is_recommended;
    const outline =
        'inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border-2 border-[#7a0010]/60 bg-white px-2 text-xs font-bold text-[#7a0010] transition hover:bg-[#7a0010]/5';

    return (
        <li
            className={`relative w-full shrink-0 snap-start pt-4 md:w-[calc((100%-1.25rem)/2)] xl:w-[calc((100%-2.5rem)/3)]`}
        >
            {recommended && (
                <span className="absolute top-0 left-1/2 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full border-2 border-[#e0a526] bg-[#fff8e6] px-3 py-1 text-xs font-bold text-[#7a4b00]">
                    <Crown className="size-3.5 text-[#e0a526]" /> Más elegido
                </span>
            )}
            <article
                className={`flex h-full flex-col overflow-hidden rounded-2xl bg-white shadow-[0_14px_34px_-22px_rgba(60,0,0,.55)] ${recommended ? 'ring-2 ring-[#e0a526]' : 'border border-slate-200'}`}
            >
                <header className="relative overflow-hidden bg-gradient-to-br from-[#6b0000] to-[#8b1020] px-5 py-4 text-white">
                    <TreePalm
                        aria-hidden
                        className="absolute -top-3 -right-4 size-28 text-white/10"
                    />
                    <div className="relative flex items-center gap-3">
                        <Icon className="size-9 shrink-0 text-[#e0a526]" />
                        <div className="min-w-0">
                            <h2 className="truncate font-serif text-2xl font-bold">
                                {plan.name}
                            </h2>
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                <span
                                    className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-semibold ${plan.is_active ? 'bg-white text-[#15803d]' : 'bg-white/15 text-white'}`}
                                >
                                    <span className="size-1.5 rounded-full bg-current" />
                                    {plan.is_active ? 'A la venta' : 'Retirado'}
                                </span>
                                <span className="text-white/85">
                                    {plan.subscribers} suscriptor(es)
                                </span>
                            </div>
                        </div>
                    </div>
                </header>

                <div className="flex flex-1 flex-col gap-3 p-4">
                    <p className="min-h-10 text-sm text-slate-700">
                        {plan.presentation.tagline ?? plan.description}
                    </p>
                    {current && (
                        <div className="rounded-xl bg-slate-50 px-3 py-2.5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="truncate text-sm font-bold text-slate-900">
                                    v{current.version} · {current.name}
                                </span>
                                <Pill tone={statusTone[current.status]}>
                                    {versionLabels[current.status] ??
                                        current.status}
                                </Pill>
                            </div>
                            <p className="mt-1 text-[13px] text-slate-700">
                                {money(Number(current.monthly_price))}/mes ·{' '}
                                {money(Number(current.annual_price))}/año ·{' '}
                                {current.entitlements.length} funciones
                            </p>
                        </div>
                    )}
                    {draft && (
                        <button
                            type="button"
                            onClick={onView}
                            className="self-start rounded-full bg-[#e0a526]/15 px-2.5 py-0.5 text-[11px] font-semibold text-[#7a4b00] ring-1 ring-[#e0a526]/40 hover:bg-[#e0a526]/25"
                        >
                            Borrador v{draft.version} por publicar
                        </button>
                    )}
                    {current && (
                        <ul className="space-y-1.5 px-1 text-[13px] text-slate-700">
                            {Object.keys(metrics).map((metric) => {
                                const LimitIcon = limitIcons[metric] ?? Boxes;

                                return (
                                    <li
                                        key={metric}
                                        className="flex items-center gap-2.5"
                                    >
                                        <LimitIcon className="size-4 shrink-0 text-slate-600" />
                                        <span>
                                            {metrics[metric].label}:{' '}
                                            <b className="text-slate-900">
                                                {limitValue(
                                                    metric,
                                                    current.limits[metric],
                                                )}
                                            </b>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {current?.change_note && (
                        <p className="text-center text-xs text-slate-500 italic">
                            {current.change_note}
                            {current.published_at &&
                                ` · publicada ${date(current.published_at)}`}
                        </p>
                    )}
                    <div className="mt-auto grid grid-cols-2 gap-2 pt-1">
                        <button
                            type="button"
                            onClick={onView}
                            className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border-2 border-[#7a0010] bg-[#7a0010] px-2 text-xs font-bold text-white transition hover:bg-[#8f0b20]"
                        >
                            <Eye className="size-4" /> Ver plan
                        </button>
                        {canManage && (
                            <>
                                <button
                                    type="button"
                                    onClick={onNewVersion}
                                    className={outline}
                                >
                                    <Layers className="size-4" /> Nueva versión
                                </button>
                                <SensitiveAction
                                    danger={plan.is_active}
                                    url={`/control-center/plans/${plan.id}/status`}
                                    data={{ active: !plan.is_active }}
                                    buttonClassName={outline}
                                    label={
                                        <>
                                            <Ban className="size-4" />
                                            {plan.is_active
                                                ? 'Retirar de la venta'
                                                : 'Poner a la venta'}
                                        </>
                                    }
                                    title={
                                        plan.is_active
                                            ? `Retirar ${plan.name}`
                                            : `Vender ${plan.name}`
                                    }
                                    description="Las suscripciones actuales no cambian."
                                />
                                <PresentationEditor
                                    plan={plan}
                                    buttonClassName={outline}
                                >
                                    <ExternalLink className="size-4" /> Página
                                    de ventas
                                </PresentationEditor>
                            </>
                        )}
                    </div>
                </div>
            </article>
        </li>
    );
}

export default function Plans({
    plans,
    features,
    metrics,
    can_manage,
}: {
    plans: Plan[];
    features: Feature[];
    metrics: Metrics;
    can_manage: boolean;
}) {
    const [editing, setEditing] = useState<Plan | null | false>(false);
    const [viewing, setViewing] = useState<Plan | null>(null);
    const track = useRef<HTMLUListElement | null>(null);
    const [position, setPosition] = useState({ page: 0, pages: 1 });
    const measure = useCallback(() => {
        const element = track.current;

        if (!element) {
            return;
        }

        const pages = Math.max(
            1,
            Math.ceil(element.scrollWidth / element.clientWidth - 0.05),
        );
        const page = Math.min(
            pages - 1,
            Math.round(
                (element.scrollLeft /
                    Math.max(1, element.scrollWidth - element.clientWidth)) *
                    (pages - 1),
            ),
        );

        setPosition((value) =>
            value.page === page && value.pages === pages
                ? value
                : { page, pages },
        );
    }, []);
    const attach = useCallback(
        (element: HTMLUListElement | null) => {
            track.current = element;
            measure();
        },
        [measure],
    );
    const scroll = (direction: 1 | -1) =>
        track.current?.scrollBy({
            left: direction * track.current.clientWidth,
            behavior: 'smooth',
        });
    const arrow =
        'absolute top-1/2 z-20 grid size-11 -translate-y-1/2 place-items-center rounded-full border border-slate-200 bg-white text-slate-800 shadow-md transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-0';

    return (
        <>
            <Head title="Planes · Control Center" />
            <div className="space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="border-l-4 border-[#e0a526] pl-5">
                        <h1 className="font-serif text-4xl font-bold text-slate-900">
                            Planes
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600">
                            Todos los planes usan el mismo motor; se diferencian
                            por funciones y límites. Cada cambio crea una
                            versión: nunca se modifica en silencio lo que un
                            cliente ya compró.
                        </p>
                    </div>
                    {can_manage && (
                        <button
                            type="button"
                            onClick={() => setEditing(null)}
                            className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#7a0010] px-5 text-sm font-bold text-white shadow-[0_10px_24px_-14px_rgba(122,0,16,.9)] transition hover:bg-[#8f0b20]"
                        >
                            <Plus className="size-5" /> Nuevo plan
                        </button>
                    )}
                </header>
                <Notice />

                {plans.length === 0 ? (
                    <p className="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500">
                        Aún no hay planes. Crea el primero con «Nuevo plan».
                    </p>
                ) : (
                    <div className="relative px-6">
                        {position.pages > 1 && (
                            <>
                                <button
                                    type="button"
                                    aria-label="Planes anteriores"
                                    disabled={position.page === 0}
                                    onClick={() => scroll(-1)}
                                    className={`${arrow} -left-1`}
                                >
                                    <ChevronLeft className="size-5" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Planes siguientes"
                                    disabled={
                                        position.page >= position.pages - 1
                                    }
                                    onClick={() => scroll(1)}
                                    className={`${arrow} -right-1`}
                                >
                                    <ChevronRight className="size-5" />
                                </button>
                            </>
                        )}
                        <ul
                            ref={attach}
                            onScroll={measure}
                            className="flex snap-x snap-mandatory [scrollbar-width:none] gap-5 overflow-x-auto scroll-smooth pb-3 [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden"
                        >
                            {plans.map((plan, index) => (
                                <PlanCard
                                    key={plan.id}
                                    plan={plan}
                                    position={index}
                                    metrics={metrics}
                                    canManage={can_manage}
                                    onView={() => setViewing(plan)}
                                    onNewVersion={() => setEditing(plan)}
                                />
                            ))}
                        </ul>
                        {position.pages > 1 && (
                            <div
                                className="mt-1 flex justify-center gap-2"
                                aria-hidden="true"
                            >
                                {Array.from(
                                    { length: position.pages },
                                    (_, index) => (
                                        <span
                                            key={index}
                                            className={`size-2.5 rounded-full ${index === position.page ? 'bg-[#7a0010]' : 'bg-slate-300'}`}
                                        />
                                    ),
                                )}
                            </div>
                        )}
                    </div>
                )}
            </div>
            {viewing && (
                <VersionsModal
                    plan={
                        plans.find((plan) => plan.id === viewing.id) ?? viewing
                    }
                    metrics={metrics}
                    canManage={can_manage}
                    onClose={() => setViewing(null)}
                />
            )}
            {editing !== false && (
                <VersionEditor
                    plan={editing}
                    features={features}
                    metrics={metrics}
                    onClose={() => setEditing(false)}
                />
            )}
        </>
    );
}
