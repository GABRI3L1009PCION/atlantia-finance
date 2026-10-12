import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    Table,
    ccButton,
    ccGhost,
    ccInput,
    date,
    statusTone,
} from '@/components/control-center/kit';

type Feature = {
    id: number;
    key: string;
    name: string;
    module: string | null;
    description: string | null;
    status: string;
    release_stage: string;
    stage_label: string;
    requires: string[];
    conflicts_with: string[];
    plans: string[];
    overrides: number;
};
type Override = {
    id: number;
    organization_id: number;
    organization: string;
    feature: string;
    value: boolean;
    reason: string;
    granted_by: string | null;
    expires_at: string | null;
    status: string;
};

const stageTone: Record<string, string> = {
    internal: 'slate',
    alpha: 'red',
    beta: 'violet',
    general_availability: 'green',
    deprecated: 'slate',
};

function FeatureEditor({
    feature,
    all,
    stages,
    onDone,
}: {
    feature: Feature;
    all: Feature[];
    stages: Record<string, string>;
    onDone: () => void;
}) {
    const [data, setData] = useState({
        name: feature.name,
        description: feature.description ?? '',
        module: feature.module ?? '',
        status: feature.status,
        release_stage: feature.release_stage,
        requires: feature.requires,
        conflicts_with: feature.conflicts_with,
    });
    const toggle = (field: 'requires' | 'conflicts_with', key: string) =>
        setData({
            ...data,
            [field]: data[field].includes(key)
                ? data[field].filter((k) => k !== key)
                : [...data[field], key],
        });

    return (
        <div className="grid gap-2 md:grid-cols-4">
            <input
                className={ccInput}
                value={data.name}
                onChange={(e) => setData({ ...data, name: e.target.value })}
            />
            <input
                className={ccInput}
                placeholder="Módulo"
                value={data.module}
                onChange={(e) => setData({ ...data, module: e.target.value })}
            />
            <select
                className={ccInput}
                value={data.release_stage}
                onChange={(e) =>
                    setData({ ...data, release_stage: e.target.value })
                }
            >
                {Object.entries(stages).map(([key, label]) => (
                    <option key={key} value={key}>
                        {label}
                    </option>
                ))}
            </select>
            <select
                className={ccInput}
                value={data.status}
                onChange={(e) => setData({ ...data, status: e.target.value })}
            >
                <option value="active">Activa</option>
                <option value="disabled">Deshabilitada</option>
            </select>
            <input
                className={`${ccInput} md:col-span-4`}
                placeholder="Descripción"
                value={data.description}
                onChange={(e) =>
                    setData({ ...data, description: e.target.value })
                }
            />
            {(['requires', 'conflicts_with'] as const).map((field) => (
                <div key={field} className="md:col-span-2">
                    <p className="mb-1 text-[11px] font-bold text-slate-400">
                        {field === 'requires' ? 'Requiere' : 'En conflicto con'}
                    </p>
                    <div className="flex max-h-28 flex-wrap gap-1 overflow-y-auto">
                        {all
                            .filter((f) => f.key !== feature.key)
                            .map((f) => (
                                <button
                                    key={f.key}
                                    type="button"
                                    onClick={() => toggle(field, f.key)}
                                    className={`rounded-full px-2 py-0.5 text-[10px] ${data[field].includes(f.key) ? 'bg-[#c8102e] text-white' : 'border border-white/10 text-slate-400'}`}
                                >
                                    {f.key}
                                </button>
                            ))}
                    </div>
                </div>
            ))}
            <div className="flex gap-2 md:col-span-4">
                <button
                    type="button"
                    className={ccButton}
                    onClick={() =>
                        router.put(
                            `/control-center/features/${feature.key}`,
                            data,
                            { preserveScroll: true, onSuccess: onDone },
                        )
                    }
                >
                    Guardar
                </button>
                <button type="button" className={ccGhost} onClick={onDone}>
                    Cancelar
                </button>
            </div>
        </div>
    );
}

export default function Features({
    features,
    overrides,
    stages,
    can_manage,
}: {
    features: Feature[];
    overrides: Override[];
    stages: Record<string, string>;
    can_manage: boolean;
}) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Módulos y funciones · Control Center" />
            <CCPage
                title="Módulos y funciones"
                subtitle="Catálogo global de funciones (entitlements), su etapa de lanzamiento y sus dependencias. La aplicación consulta el entitlement efectivo de cada organización; nunca el nombre del plan."
            >
                <Panel title={`${features.length} funciones`}>
                    <Table
                        headers={[
                            'Clave',
                            'Nombre',
                            'Módulo',
                            'Etapa',
                            'Requiere',
                            'Planes',
                            'Excepciones',
                            'Estado',
                            '',
                        ]}
                    >
                        {features.map((feature) => (
                            <Fragment key={feature.key}>
                                <tr>
                                    <td className="px-2 py-2 font-mono text-white">
                                        {feature.key}
                                    </td>
                                    <td className="px-2 py-2">
                                        {feature.name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {feature.module ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={
                                                stageTone[feature.release_stage]
                                            }
                                        >
                                            {feature.stage_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2 font-mono text-[10px]">
                                        {feature.requires.join(', ') || '—'}
                                        {feature.conflicts_with.length > 0 && (
                                            <span className="block text-[#f87171]">
                                                ✕{' '}
                                                {feature.conflicts_with.join(
                                                    ', ',
                                                )}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2 text-[11px]">
                                        {feature.plans.join(', ') || '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {feature.overrides}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={
                                                feature.status === 'active'
                                                    ? 'green'
                                                    : 'slate'
                                            }
                                        >
                                            {feature.status === 'active'
                                                ? 'Activa'
                                                : 'Deshabilitada'}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        {can_manage && (
                                            <button
                                                type="button"
                                                className={ccGhost}
                                                onClick={() =>
                                                    setEditing(
                                                        editing === feature.key
                                                            ? null
                                                            : feature.key,
                                                    )
                                                }
                                            >
                                                Editar
                                            </button>
                                        )}
                                    </td>
                                </tr>
                                {editing === feature.key && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="bg-black/20 p-3"
                                        >
                                            <FeatureEditor
                                                feature={feature}
                                                all={features}
                                                stages={stages}
                                                onDone={() => setEditing(null)}
                                            />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </Table>
                </Panel>
                <Panel title="Excepciones por organización">
                    <Table
                        headers={[
                            'Organización',
                            'Función',
                            'Valor',
                            'Motivo',
                            'Otorgó',
                            'Vence',
                            'Estado',
                        ]}
                        empty={overrides.length === 0 && 'Sin excepciones.'}
                    >
                        {overrides.map((o) => (
                            <tr key={o.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={`/control-center/organizations/${o.organization_id}?tab=features`}
                                        className="text-white"
                                    >
                                        {o.organization}
                                    </Link>
                                </td>
                                <td className="px-2 py-2 font-mono">
                                    {o.feature}
                                </td>
                                <td className="px-2 py-2">
                                    {o.value ? 'ON' : 'OFF'}
                                </td>
                                <td className="px-2 py-2">{o.reason}</td>
                                <td className="px-2 py-2">
                                    {o.granted_by ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {date(o.expires_at)}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill
                                        tone={
                                            statusTone[
                                                o.status === 'active'
                                                    ? 'active'
                                                    : 'expired'
                                            ]
                                        }
                                    >
                                        {o.status}
                                    </Pill>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
