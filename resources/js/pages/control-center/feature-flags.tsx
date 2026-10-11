import { Head, router, useForm } from '@inertiajs/react';
import { Flag } from 'lucide-react';
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
} from '@/components/control-center/kit';

type Target = {
    id: number;
    type: string;
    type_label: string;
    target_id: string;
    label: string;
    enabled: boolean;
    reason: string | null;
};
type FlagRow = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    enabled_globally: boolean;
    status: string;
    targets: Target[];
};

function TargetForm({
    flag,
    plans,
    organizations,
}: {
    flag: FlagRow;
    plans: { id: number; name: string }[];
    organizations: { id: number; name: string }[];
}) {
    const [data, setData] = useState({
        target_type: 'organization',
        target_id: String(organizations[0]?.id ?? ''),
        enabled: true,
        reason: '',
    });
    const options =
        data.target_type === 'plan'
            ? plans
            : data.target_type === 'organization'
              ? organizations
              : [];

    return (
        <div className="mt-2 flex flex-wrap items-end gap-2">
            <select
                className={`${ccInput} h-8 w-36`}
                value={data.target_type}
                onChange={(e) =>
                    setData({
                        ...data,
                        target_type: e.target.value,
                        target_id:
                            e.target.value === 'plan'
                                ? String(plans[0]?.id ?? '')
                                : e.target.value === 'organization'
                                  ? String(organizations[0]?.id ?? '')
                                  : '',
                    })
                }
            >
                <option value="organization">Organización</option>
                <option value="plan">Plan</option>
                <option value="beta_group">Grupo beta</option>
                <option value="user">Usuario (id)</option>
            </select>
            {options.length > 0 ? (
                <select
                    className={`${ccInput} h-8 w-52`}
                    value={data.target_id}
                    onChange={(e) =>
                        setData({ ...data, target_id: e.target.value })
                    }
                >
                    {options.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.name}
                        </option>
                    ))}
                </select>
            ) : data.target_type === 'user' ? (
                <input
                    className={`${ccInput} h-8 w-28`}
                    placeholder="Id de usuario"
                    value={data.target_id}
                    onChange={(e) =>
                        setData({ ...data, target_id: e.target.value })
                    }
                />
            ) : null}
            <select
                className={`${ccInput} h-8 w-24`}
                value={data.enabled ? '1' : '0'}
                onChange={(e) =>
                    setData({ ...data, enabled: e.target.value === '1' })
                }
            >
                <option value="1">ON</option>
                <option value="0">OFF</option>
            </select>
            <input
                className={`${ccInput} h-8 w-56`}
                placeholder="Motivo"
                value={data.reason}
                onChange={(e) => setData({ ...data, reason: e.target.value })}
            />
            <button
                type="button"
                className={ccGhost}
                disabled={data.reason.length < 5}
                onClick={() =>
                    router.post(
                        `/control-center/feature-flags/${flag.id}/target`,
                        data,
                        { preserveScroll: true },
                    )
                }
            >
                Agregar destino
            </button>
        </div>
    );
}

export default function FeatureFlags({
    flags,
    affected,
    plans,
    organizations,
    can_manage,
}: {
    flags: FlagRow[];
    targets: Record<string, string>;
    affected: number;
    plans: { id: number; name: string }[];
    organizations: { id: number; name: string }[];
    can_manage: boolean;
}) {
    const form = useForm({ key: '', name: '', description: '', reason: '' });

    return (
        <>
            <Head title="Feature Flags · Control Center" />
            <CCPage
                title="Feature Flags"
                subtitle="Lanzamientos graduales. Resolución: usuario → organización → grupo beta → plan → valor global. Cada cambio queda auditado con valor anterior, nuevo, destino, autor y motivo."
            >
                {can_manage && (
                    <Panel title="Nuevo flag">
                        <form
                            className="grid gap-2 md:grid-cols-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/control-center/feature-flags', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                });
                            }}
                        >
                            <Field label="Clave">
                                <input
                                    className={ccInput}
                                    placeholder="luzma_multioffice"
                                    value={form.data.key}
                                    onChange={(e) =>
                                        form.setData('key', e.target.value)
                                    }
                                    required
                                />
                            </Field>
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
                            <Field label="Descripción">
                                <input
                                    className={ccInput}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Motivo">
                                <input
                                    className={ccInput}
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <div className="flex items-end">
                                <button
                                    className={ccButton}
                                    disabled={form.processing}
                                >
                                    Crear (apagado)
                                </button>
                            </div>
                        </form>
                    </Panel>
                )}
                {flags.length === 0 && (
                    <Panel>
                        <p className="py-6 text-center text-sm text-slate-500">
                            No hay feature flags.
                        </p>
                    </Panel>
                )}
                {flags.map((flag) => (
                    <Panel
                        key={flag.id}
                        title={
                            <>
                                {flag.name}{' '}
                                <span className="font-mono text-xs text-slate-500">
                                    {flag.key}
                                </span>
                            </>
                        }
                        icon={<Flag className="size-4 text-[#c084fc]" />}
                        action={
                            <div className="flex items-center gap-2">
                                <span className="text-[11px] text-slate-400">
                                    Global
                                </span>
                                <Pill
                                    tone={
                                        flag.enabled_globally
                                            ? 'green'
                                            : 'slate'
                                    }
                                >
                                    {flag.enabled_globally ? 'ON' : 'OFF'}
                                </Pill>
                                {can_manage && (
                                    <SensitiveAction
                                        danger={!flag.enabled_globally}
                                        url={`/control-center/feature-flags/${flag.id}/global`}
                                        data={{
                                            enabled: !flag.enabled_globally,
                                        }}
                                        label={
                                            flag.enabled_globally
                                                ? 'Apagar globalmente'
                                                : 'Encender globalmente'
                                        }
                                        title={`Cambio global de ${flag.key}`}
                                        description={`Afecta a ${affected} organización(es) activas que no tengan un destino específico.`}
                                    />
                                )}
                            </div>
                        }
                    >
                        {flag.description && (
                            <p className="mb-2 text-xs text-slate-400">
                                {flag.description}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {flag.targets.length === 0 && (
                                <span className="text-xs text-slate-500">
                                    Sin destinos: aplica el valor global.
                                </span>
                            )}
                            {flag.targets.map((target) => (
                                <span
                                    key={target.id}
                                    className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-3 py-1 text-[11px] text-slate-300"
                                    title={target.reason ?? undefined}
                                >
                                    {target.type_label}: {target.label}{' '}
                                    <Pill
                                        tone={
                                            target.enabled ? 'green' : 'slate'
                                        }
                                        dot={false}
                                    >
                                        {target.enabled ? 'ON' : 'OFF'}
                                    </Pill>
                                    {can_manage && (
                                        <button
                                            type="button"
                                            className="text-slate-500 hover:text-white"
                                            onClick={() => {
                                                const reason = window.prompt(
                                                    'Motivo para quitar el destino',
                                                );

                                                if (reason) {
                                                    router.post(
                                                        `/control-center/feature-flag-targets/${target.id}/remove`,
                                                        { reason },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            ✕
                                        </button>
                                    )}
                                </span>
                            ))}
                        </div>
                        {can_manage && (
                            <TargetForm
                                flag={flag}
                                plans={plans}
                                organizations={organizations}
                            />
                        )}
                    </Panel>
                ))}
            </CCPage>
        </>
    );
}
