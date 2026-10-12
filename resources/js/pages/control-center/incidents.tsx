import { Head, Link, useForm } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import {
    CCPage,
    Field,
    Panel,
    Pill,
    Table,
    ago,
    ccButton,
    ccInput,
    ccTextarea,
    date,
} from '@/components/control-center/kit';

type Incident = {
    id: number;
    code: string;
    title: string;
    severity: string;
    severity_label: string;
    status: string;
    status_label: string;
    audience: string;
    affected_services: string[];
    affected_organizations: number;
    owner: string | null;
    started_at: string;
    detected_at: string;
    resolved_at: string | null;
};
type Detail = Incident & {
    description: string;
    postmortem: string | null;
    organizations: { id: number; name: string }[];
    updates: {
        status: string;
        status_label: string;
        message: string;
        published: boolean;
        author: string | null;
        at: string;
    }[];
};

const severityTone: Record<string, string> = {
    low: 'slate',
    medium: 'amber',
    high: 'red',
    critical: 'red',
};
const statusToneMap: Record<string, string> = {
    investigating: 'red',
    identified: 'amber',
    mitigating: 'amber',
    monitoring: 'blue',
    resolved: 'green',
    postmortem: 'slate',
};
const audienceLabels: Record<string, string> = {
    all: 'Todos los clientes',
    affected: 'Organizaciones afectadas',
    none: 'Solo interno',
};

function CreateIncident({
    services,
    organizations,
    plans,
    severities,
}: {
    services: Record<string, string>;
    organizations: { id: number; name: string }[];
    plans: { id: number; name: string }[];
    severities: Record<string, string>;
}) {
    const form = useForm<{
        title: string;
        description: string;
        severity: string;
        affected_services: string[];
        audience: string;
        plan_id: string;
        organization_ids: number[];
    }>({
        title: '',
        description: '',
        severity: 'medium',
        affected_services: [],
        audience: 'affected',
        plan_id: '',
        organization_ids: [],
    });

    return (
        <Panel
            title="Registrar incidente"
            icon={<Siren className="size-4 text-[#f87171]" />}
        >
            <form
                className="grid gap-3 md:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/control-center/incidents', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Field label="Título">
                    <input
                        className={ccInput}
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        required
                    />
                </Field>
                <Field label="Severidad">
                    <select
                        className={ccInput}
                        value={form.data.severity}
                        onChange={(e) =>
                            form.setData('severity', e.target.value)
                        }
                    >
                        {Object.entries(severities).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Audiencia">
                    <select
                        className={ccInput}
                        value={form.data.audience}
                        onChange={(e) =>
                            form.setData('audience', e.target.value)
                        }
                    >
                        <option value="affected">
                            Organizaciones afectadas (por servicio)
                        </option>
                        <option value="all">Todos los clientes</option>
                        <option value="plan">Clientes de un plan</option>
                        <option value="none">Solo interno</option>
                    </select>
                </Field>
                {form.data.audience === 'plan' && (
                    <Field label="Plan">
                        <select
                            className={ccInput}
                            value={form.data.plan_id}
                            onChange={(e) =>
                                form.setData('plan_id', e.target.value)
                            }
                            required
                        >
                            <option value="">Selecciona…</option>
                            {plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                <Field label="Servicios afectados" wide>
                    <div className="flex flex-wrap gap-1">
                        {Object.entries(services).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    form.setData(
                                        'affected_services',
                                        form.data.affected_services.includes(
                                            key,
                                        )
                                            ? form.data.affected_services.filter(
                                                  (s) => s !== key,
                                              )
                                            : [
                                                  ...form.data
                                                      .affected_services,
                                                  key,
                                              ],
                                    )
                                }
                                className={`rounded-full px-2.5 py-1 text-[11px] ${form.data.affected_services.includes(key) ? 'bg-[#c8102e] text-white' : 'border border-white/10 text-slate-300'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </Field>
                {form.data.audience === 'affected' && (
                    <Field label="Organizaciones adicionales (opcional)" wide>
                        <select
                            multiple
                            className={`${ccInput} h-24`}
                            value={form.data.organization_ids.map(String)}
                            onChange={(e) =>
                                form.setData(
                                    'organization_ids',
                                    Array.from(e.target.selectedOptions).map(
                                        (o) => Number(o.value),
                                    ),
                                )
                            }
                        >
                            {organizations.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                <Field label="Descripción interna" wide>
                    <textarea
                        className={ccTextarea}
                        rows={3}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        required
                    />
                </Field>
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-[#f87171] md:col-span-3">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                <div className="md:col-span-3">
                    <button
                        className={ccButton}
                        disabled={
                            form.processing ||
                            form.data.affected_services.length === 0
                        }
                    >
                        Registrar incidente
                    </button>
                </div>
            </form>
        </Panel>
    );
}

function IncidentDetail({
    incident,
    statuses,
    canManage,
}: {
    incident: Detail;
    statuses: Record<string, string>;
    canManage: boolean;
}) {
    const form = useForm({
        status: incident.status,
        message: '',
        publish: false,
    });
    const postmortem = useForm({ postmortem: incident.postmortem ?? '' });

    return (
        <Panel
            title={
                <>
                    {incident.code} · {incident.title}
                </>
            }
            action={
                <Pill tone={statusToneMap[incident.status]}>
                    {incident.status_label}
                </Pill>
            }
        >
            <p className="text-xs text-slate-400">
                {incident.severity_label} ·{' '}
                {audienceLabels[incident.audience] ?? incident.audience} ·{' '}
                {incident.organizations.length} organización(es) · inicio{' '}
                {date(incident.started_at, true)}
                {incident.resolved_at && (
                    <> · resuelto {date(incident.resolved_at, true)}</>
                )}
            </p>
            <p className="mt-2 text-sm whitespace-pre-wrap text-slate-300">
                {incident.description}
            </p>
            <ol className="mt-4 space-y-2 border-l border-white/10 pl-4">
                {incident.updates.map((update, index) => (
                    <li key={index} className="text-sm">
                        <p className="text-[11px] text-slate-400">
                            <Pill tone={statusToneMap[update.status]}>
                                {update.status_label}
                            </Pill>{' '}
                            {update.author ?? 'Sistema'} · {ago(update.at)} ·{' '}
                            {update.published ? (
                                <span className="text-[#86efac]">
                                    publicado a clientes
                                </span>
                            ) : (
                                'interno'
                            )}
                        </p>
                        <p className="mt-1 whitespace-pre-wrap text-slate-200">
                            {update.message}
                        </p>
                    </li>
                ))}
            </ol>
            {canManage && !['postmortem'].includes(incident.status) && (
                <form
                    className="mt-4 space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/control-center/incidents/${incident.id}`, {
                            preserveScroll: true,
                            onSuccess: () => form.reset('message'),
                        });
                    }}
                >
                    <div className="flex flex-wrap gap-2">
                        <select
                            className={`${ccInput} w-48`}
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            {Object.entries(statuses)
                                .filter(([k]) => k !== 'postmortem')
                                .map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                        </select>
                        <label className="flex items-center gap-2 text-xs text-slate-300">
                            <input
                                type="checkbox"
                                checked={form.data.publish}
                                onChange={(e) =>
                                    form.setData('publish', e.target.checked)
                                }
                            />{' '}
                            Publicar a los clientes afectados
                        </label>
                    </div>
                    <textarea
                        className={ccTextarea}
                        rows={2}
                        placeholder="Actualización"
                        value={form.data.message}
                        onChange={(e) =>
                            form.setData('message', e.target.value)
                        }
                        required
                    />
                    {form.errors.status && (
                        <p className="text-xs text-[#f87171]">
                            {form.errors.status}
                        </p>
                    )}
                    <button className={ccButton} disabled={form.processing}>
                        Registrar actualización
                    </button>
                </form>
            )}
            {canManage &&
                ['resolved', 'postmortem'].includes(incident.status) && (
                    <form
                        className="mt-4 space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            postmortem.put(
                                `/control-center/incidents/${incident.id}`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <p className="text-xs font-bold text-slate-400">
                            Postmortem
                        </p>
                        <textarea
                            className={ccTextarea}
                            rows={5}
                            placeholder="Causa raíz, impacto, línea de tiempo y acciones preventivas"
                            value={postmortem.data.postmortem}
                            onChange={(e) =>
                                postmortem.setData('postmortem', e.target.value)
                            }
                        />
                        {postmortem.errors.postmortem && (
                            <p className="text-xs text-[#f87171]">
                                {postmortem.errors.postmortem}
                            </p>
                        )}
                        <button
                            className={ccButton}
                            disabled={postmortem.processing}
                        >
                            Guardar postmortem
                        </button>
                    </form>
                )}
        </Panel>
    );
}

export default function Incidents({
    incidents,
    selected,
    statuses,
    severities,
    services,
    organizations,
    plans,
    can_manage,
}: {
    incidents: Incident[];
    selected: Detail | null;
    statuses: Record<string, string>;
    severities: Record<string, string>;
    services: Record<string, string>;
    organizations: { id: number; name: string }[];
    plans: { id: number; name: string }[];
    can_manage: boolean;
}) {
    return (
        <>
            <Head title="Incidentes · Control Center" />
            <CCPage
                title="Incidentes"
                subtitle="Fallas de la plataforma que afectan a varios clientes. Las actualizaciones publicadas aparecen como aviso dentro de la aplicación de cada organización afectada."
            >
                {can_manage && (
                    <CreateIncident
                        services={services}
                        organizations={organizations}
                        plans={plans}
                        severities={severities}
                    />
                )}
                <div className="grid gap-3 xl:grid-cols-2">
                    <Panel title="Incidentes">
                        <Table
                            headers={[
                                'Incidente',
                                'Severidad',
                                'Estado',
                                'Servicios',
                                'Afectadas',
                                'Inicio',
                            ]}
                            empty={
                                incidents.length === 0 &&
                                'No hay incidentes registrados.'
                            }
                        >
                            {incidents.map((incident) => (
                                <tr
                                    key={incident.id}
                                    className={
                                        selected?.id === incident.id
                                            ? 'bg-white/[0.04]'
                                            : ''
                                    }
                                >
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/incidents?incident=${incident.id}`}
                                            preserveScroll
                                            className="font-semibold text-white"
                                        >
                                            {incident.code}
                                        </Link>
                                        <span className="block max-w-[220px] truncate text-[11px] text-slate-400">
                                            {incident.title}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={
                                                severityTone[incident.severity]
                                            }
                                        >
                                            {incident.severity_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={
                                                statusToneMap[incident.status]
                                            }
                                        >
                                            {incident.status_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2 text-[11px]">
                                        {incident.affected_services
                                            .map((s) => services[s] ?? s)
                                            .join(', ')}
                                    </td>
                                    <td className="px-2 py-2">
                                        {incident.affected_organizations}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {ago(incident.started_at)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                    {selected ? (
                        <IncidentDetail
                            key={`${selected.id}-${selected.status}`}
                            incident={selected}
                            statuses={statuses}
                            canManage={can_manage}
                        />
                    ) : (
                        <Panel>
                            <p className="py-10 text-center text-sm text-slate-500">
                                Selecciona un incidente para ver su línea de
                                tiempo.
                            </p>
                        </Panel>
                    )}
                </div>
            </CCPage>
        </>
    );
}
