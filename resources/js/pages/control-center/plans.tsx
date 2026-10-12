import { Head, router, useForm } from '@inertiajs/react';
import { Boxes, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    CCPage,
    Field,
    Panel,
    Pill,
    SensitiveAction,
    ccButton,
    ccGhost,
    ccInput,
    date,
    money,
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
function PresentationEditor({ plan }: { plan: Plan }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        tagline: plan.presentation.tagline ?? '',
        highlights: plan.presentation.highlights.join('\n'),
        is_recommended: plan.presentation.is_recommended,
        price_prefix: plan.presentation.price_prefix ?? '',
        cta_label: plan.presentation.cta_label ?? '',
        reason: '',
    });

    if (!open) {
        return (
            <button
                type="button"
                className={ccGhost}
                onClick={() => setOpen(true)}
            >
                Página de ventas
            </button>
        );
    }

    return (
        <form
            className="mt-3 grid w-full gap-2 rounded-xl border border-white/10 bg-black/20 p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    highlights: data.highlights.split('\n'),
                    tagline: data.tagline || null,
                    price_prefix: data.price_prefix || null,
                    cta_label: data.cta_label || null,
                }));
                form.post(`/control-center/plans/${plan.id}/presentation`, {
                    preserveScroll: true,
                    onSuccess: () => setOpen(false),
                });
            }}
        >
            <Field label="Lema (sobre el nombre)">
                <input
                    className={ccInput}
                    value={form.data.tagline}
                    onChange={(e) => form.setData('tagline', e.target.value)}
                    maxLength={120}
                />
            </Field>
            <Field label="Frases destacadas (una por línea, máx. 6). Los límites se agregan solos desde la versión publicada.">
                <textarea
                    className={`${ccInput} h-28 py-2`}
                    value={form.data.highlights}
                    onChange={(e) => form.setData('highlights', e.target.value)}
                />
            </Field>
            <div className="grid gap-2 sm:grid-cols-2">
                <Field label="Prefijo del precio (ej. Desde)">
                    <input
                        className={ccInput}
                        value={form.data.price_prefix}
                        onChange={(e) =>
                            form.setData('price_prefix', e.target.value)
                        }
                        maxLength={30}
                    />
                </Field>
                <Field label="Texto del botón (vacío: Elegir este plan)">
                    <input
                        className={ccInput}
                        value={form.data.cta_label}
                        onChange={(e) =>
                            form.setData('cta_label', e.target.value)
                        }
                        maxLength={60}
                    />
                </Field>
            </div>
            <label className="flex items-center gap-2 text-xs text-slate-300">
                <input
                    type="checkbox"
                    checked={form.data.is_recommended}
                    onChange={(e) =>
                        form.setData('is_recommended', e.target.checked)
                    }
                />
                Plan recomendado (muestra «Más elegido»; solo puede haber uno)
            </label>
            <Field label="Motivo del cambio">
                <input
                    className={ccInput}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    required
                    minLength={5}
                />
            </Field>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-[#f87171]">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex gap-2">
                <button className={ccButton} disabled={form.processing}>
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
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/70 p-4 backdrop-blur-sm">
            <form
                className="w-full max-w-4xl space-y-3 rounded-2xl border border-white/10 bg-[#11141b] p-5"
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
                <h2 className="text-lg font-bold text-white">
                    {plan ? `Nueva versión de ${plan.name}` : 'Nuevo plan'}
                </h2>
                <p className="text-xs text-slate-400">
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
                <p className="text-xs font-bold text-slate-300">
                    Funciones (entitlements)
                </p>
                <div className="grid max-h-56 gap-1 overflow-y-auto rounded-xl border border-white/[0.06] p-3 text-xs md:grid-cols-3">
                    {features.map((feature) => (
                        <label
                            key={feature.code}
                            className="flex items-center gap-2 text-slate-300"
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
                <p className="text-xs font-bold text-slate-300">
                    Límites (vacío = ilimitado)
                </p>
                <div className="grid gap-2">
                    {Object.entries(metrics).map(([metric, definition]) => (
                        <div
                            key={metric}
                            className="grid grid-cols-[180px_1fr_90px_90px_120px] items-center gap-2 text-xs"
                        >
                            <span className="text-slate-300">
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
                    <div className="rounded-lg bg-[#ef4444]/10 p-2 text-xs text-[#fca5a5]">
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

    return (
        <>
            <Head title="Planes · Control Center" />
            <CCPage
                title="Planes"
                subtitle="Todos los planes usan el mismo motor; se diferencian por funciones y límites. Cada cambio crea una versión: nunca se modifica en silencio lo que un cliente ya compró."
                actions={
                    can_manage && (
                        <button
                            type="button"
                            className={ccButton}
                            onClick={() => setEditing(null)}
                        >
                            <Plus className="size-4" /> Nuevo plan
                        </button>
                    )
                }
            >
                <div className="grid gap-3 xl:grid-cols-2">
                    {plans.map((plan) => (
                        <Panel
                            key={plan.id}
                            title={
                                <>
                                    {plan.name}{' '}
                                    <span className="font-mono text-xs text-slate-500">
                                        {plan.code}
                                    </span>
                                </>
                            }
                            icon={<Boxes className="size-4 text-[#e8b04b]" />}
                            action={
                                <div className="flex items-center gap-2">
                                    <Pill
                                        tone={
                                            plan.is_active ? 'green' : 'slate'
                                        }
                                    >
                                        {plan.is_active
                                            ? 'A la venta'
                                            : 'Retirado'}
                                    </Pill>
                                    <span className="text-[11px] text-slate-400">
                                        {plan.subscribers} suscriptor(es)
                                    </span>
                                </div>
                            }
                        >
                            <p className="mb-3 text-xs text-slate-400">
                                {plan.description}
                            </p>
                            <div className="space-y-2">
                                {plan.versions.map((version) => (
                                    <div
                                        key={version.id}
                                        className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-xs"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-bold text-white">
                                                v{version.version} ·{' '}
                                                {version.name}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <Pill
                                                    tone={
                                                        statusTone[
                                                            version.status
                                                        ]
                                                    }
                                                >
                                                    {
                                                        {
                                                            draft: 'Borrador',
                                                            published:
                                                                'Publicada',
                                                            retired: 'Retirada',
                                                        }[version.status]
                                                    }
                                                </Pill>
                                                <span className="text-slate-500">
                                                    {version.subscriptions}{' '}
                                                    suscripción(es)
                                                </span>
                                            </div>
                                        </div>
                                        <p className="mt-1 text-slate-300">
                                            {money(
                                                Number(version.monthly_price),
                                            )}
                                            /mes ·{' '}
                                            {money(
                                                Number(version.annual_price),
                                            )}
                                            /año · {version.entitlements.length}{' '}
                                            funciones
                                        </p>
                                        <p className="mt-1 text-slate-500">
                                            {Object.entries(version.limits)
                                                .map(
                                                    ([metric, limit]) =>
                                                        `${metrics[metric]?.label ?? metric}: ${limit.value === null ? '∞' : limit.value}`,
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
                                        {can_manage &&
                                            version.status === 'draft' && (
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
                                                                {
                                                                    preserveScroll: true,
                                                                },
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
                            {can_manage && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className={ccGhost}
                                        onClick={() => setEditing(plan)}
                                    >
                                        Nueva versión
                                    </button>
                                    <SensitiveAction
                                        danger={plan.is_active}
                                        url={`/control-center/plans/${plan.id}/status`}
                                        data={{ active: !plan.is_active }}
                                        label={
                                            plan.is_active
                                                ? 'Retirar de la venta'
                                                : 'Poner a la venta'
                                        }
                                        title={
                                            plan.is_active
                                                ? `Retirar ${plan.name}`
                                                : `Vender ${plan.name}`
                                        }
                                        description="Las suscripciones actuales no cambian."
                                    />
                                    <PresentationEditor plan={plan} />
                                </div>
                            )}
                        </Panel>
                    ))}
                </div>
            </CCPage>
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
