import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Building2, KeyRound } from 'lucide-react';
import {
    CCPage,
    Field,
    Panel,
    Pill,
    Progress,
    SensitiveAction,
    Table,
    ago,
    bytes,
    ccButton,
    ccGhost,
    ccInput,
    date,
    money,
    statusTone,
} from '@/components/control-center/kit';

type Usage = {
    metric: string;
    label: string;
    value: number;
    limit: number | null;
    percent: number | null;
    status: string;
    enforcement: string;
};
type Detail = {
    organization: Record<string, unknown> & {
        id: number;
        name: string;
        code: string | null;
        organization_status: string;
        organization_label: string;
        subscription_status: string | null;
        subscription_label: string;
        plan: string | null;
        plan_version: number | null;
        tax_id: string | null;
        legal_name: string | null;
        timezone: string | null;
        beta_program: boolean;
        suspension_reason: string | null;
        last_activity: string | null;
    };
    subscription:
        | (Record<string, unknown> & {
              id: number;
              status: string;
              status_label: string;
              plan: string | null;
              billing_cycle: string | null;
              base_price: number | null;
              discount_percent: number;
              current_period_end: string | null;
              trial_ends_at: string | null;
              cancel_at_period_end: boolean;
              over_limit: boolean;
              scheduled_change_at: string | null;
          })
        | null;
    features: {
        key: string;
        enabled: boolean;
        source: string;
        reason: string | null;
        expires_at: string | null;
    }[];
    usage: Usage[];
    offices: {
        id: number;
        code: string;
        name: string;
        city: string | null;
        status: string;
        primary: boolean;
        users: number;
        companies: number;
    }[];
    users: {
        id: number;
        name: string;
        email: string;
        role: string;
        status: string;
        two_factor: boolean;
        last_seen: string | null;
    }[];
    integrations: {
        api_tokens: number;
        webhooks: number;
        webhook_failures_7d: number;
        api_requests_month: number;
    };
    billing: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        total: number;
        currency: string;
        due_on: string | null;
        days_overdue: number;
        kind: string;
    }[];
    credits: {
        id: number;
        amount: string;
        remaining: string;
        reason: string;
        expires_at: string | null;
    }[];
    security: {
        users_with_2fa: number;
        offices_requiring_2fa: number;
        support_accesses: {
            id: number;
            status: string;
            reason: string;
            expires_at: string | null;
            created_at: string;
        }[];
    };
    support: {
        id: number;
        code: string;
        subject: string;
        priority: string;
        status: string;
        created_at: string;
    }[];
    activity: {
        action: string;
        actor_name: string | null;
        reason: string | null;
        created_at: string;
    }[];
    overrides: {
        id: number;
        feature: string;
        value: boolean;
        reason: string;
        granted_by: string | null;
        expires_at: string | null;
        status: string;
    }[];
};
type Props = {
    detail: Detail;
    impact: {
        users: number;
        offices: number;
        companies: number;
        sessions: number;
        api_tokens: number;
        webhooks: number;
        subscription_status: string | null;
    };
    tab: string;
    features: { code: string; name: string }[];
    plans: { id: number; label: string }[];
    can: Record<string, boolean>;
};

const tabs: Record<string, string> = {
    summary: 'Resumen',
    subscription: 'Suscripción',
    features: 'Plan y funciones',
    usage: 'Consumo',
    offices: 'Oficinas',
    users: 'Usuarios',
    integrations: 'Integraciones',
    billing: 'Billing',
    security: 'Seguridad',
    support: 'Soporte',
    activity: 'Actividad',
};

function UsageList({ usage }: { usage: Usage[] }) {
    return (
        <ul className="space-y-3">
            {usage.map((row) => (
                <li
                    key={row.metric}
                    className="grid grid-cols-[160px_1fr_160px_110px] items-center gap-3 text-xs"
                >
                    <span className="text-slate-300">{row.label}</span>
                    <Progress percent={row.percent} />
                    <span className="text-right text-slate-400">
                        {row.metric === 'storage_bytes'
                            ? bytes(row.value)
                            : row.value}{' '}
                        /{' '}
                        {row.limit === null
                            ? '∞'
                            : row.metric === 'storage_bytes'
                              ? bytes(row.limit)
                              : row.limit}
                    </span>
                    <Pill tone={statusTone[row.status]}>
                        {{
                            ok: 'Normal',
                            warning: 'Advertencia',
                            high: 'Alto',
                            at_limit: 'En el límite',
                            over_limit: 'OVER_LIMIT',
                            unlimited: 'Ilimitado',
                            not_included: 'No incluido',
                        }[row.status] ?? row.status}
                    </Pill>
                </li>
            ))}
        </ul>
    );
}

export default function OrganizationDetail({
    detail,
    impact,
    tab,
    features,
    plans,
    can,
}: Props) {
    const o = detail.organization;
    const s = detail.subscription;
    const base = `/control-center/organizations/${o.id}`;
    const override = useForm({
        feature_code: features[0]?.code ?? '',
        value: true,
        reason: '',
        expires_at: '',
    });
    const credit = useForm({ amount: '', reason: '', expires_at: '' });
    const charge = useForm({ description: '', amount: '' });
    const newSubscription = useForm({
        plan_price_id: plans[0]?.id ?? 0,
        trial: false,
        discount_percent: '',
    });

    return (
        <>
            <Head title={`${o.name} · Control Center`} />
            <CCPage
                title={o.name}
                subtitle={`${o.code ?? ''} · NIT ${o.tax_id ?? '—'} · ${o.legal_name ?? ''} · última actividad ${ago(o.last_activity)}`}
                actions={
                    <>
                        <Link
                            href="/control-center/organizations"
                            className={ccGhost}
                        >
                            <ArrowLeft className="size-3.5" /> Organizaciones
                        </Link>
                        <Pill tone={statusTone[o.organization_status]}>
                            {o.organization_label}
                        </Pill>
                        {can.support_access && (
                            <Link
                                href={`/control-center/support-access?organization=${o.id}`}
                                className={ccGhost}
                            >
                                <KeyRound className="size-3.5" /> Solicitar
                                acceso de soporte
                            </Link>
                        )}
                        {can.suspend &&
                            o.organization_status !== 'suspended' &&
                            o.organization_status !== 'archived' && (
                                <SensitiveAction
                                    danger
                                    url={`${base}/status/suspend`}
                                    label="Suspender"
                                    title={`Suspender ${o.name}`}
                                    description={
                                        <>
                                            Los usuarios pierden acceso;{' '}
                                            <b>nada se elimina</b>. Impacto:{' '}
                                            {impact.users} usuario(s),{' '}
                                            {impact.offices} oficina(s),{' '}
                                            {impact.companies} empresa(s),{' '}
                                            {impact.sessions} sesión(es) activas
                                            que se revocarán,{' '}
                                            {impact.api_tokens} llave(s) de API
                                            y {impact.webhooks} webhook(s) que
                                            dejarán de operar.
                                        </>
                                    }
                                />
                            )}
                        {can.suspend &&
                            o.organization_status === 'suspended' && (
                                <SensitiveAction
                                    url={`${base}/status/reactivate`}
                                    label="Reactivar"
                                    title={`Reactivar ${o.name}`}
                                    description="Confirma que la causa de la suspensión está resuelta."
                                />
                            )}
                        {can.suspend &&
                            ['suspended', 'cancelled'].includes(
                                o.organization_status,
                            ) && (
                                <SensitiveAction
                                    danger
                                    url={`${base}/status/archive`}
                                    label="Archivar"
                                    title={`Archivar ${o.name}`}
                                    description="Estado final controlado: se conserva el historial comercial y la auditoría."
                                />
                            )}
                    </>
                }
            >
                {o.suspension_reason && (
                    <div className="rounded-xl border border-[#ef4444]/30 bg-[#ef4444]/10 p-3 text-sm text-[#fca5a5]">
                        Suspendida:{' '}
                        {String(o.suspension_reason).replace('[billing] ', '')}
                    </div>
                )}
                <nav className="flex flex-wrap gap-1">
                    {Object.entries(tabs).map(([key, label]) => (
                        <Link
                            key={key}
                            href={`${base}?tab=${key}`}
                            preserveScroll
                            className={`rounded-full px-3 py-1.5 text-xs font-semibold ${tab === key ? 'bg-[#c8102e] text-white' : 'border border-white/10 text-slate-300 hover:text-white'}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>

                {tab === 'summary' && (
                    <div className="grid gap-3 xl:grid-cols-3">
                        <Panel
                            title="Organización"
                            icon={
                                <Building2 className="size-4 text-[#e8b04b]" />
                            }
                        >
                            <dl className="grid gap-2 text-xs">
                                {[
                                    [
                                        'Plan',
                                        `${o.plan ?? '—'}${o.plan_version ? ` v${o.plan_version}` : ''}`,
                                    ],
                                    ['Suscripción', o.subscription_label],
                                    ['Zona horaria', o.timezone ?? '—'],
                                    [
                                        'Programa beta',
                                        o.beta_program ? 'Sí' : 'No',
                                    ],
                                    [
                                        'Renovación',
                                        date(
                                            String(o.renewal_date ?? '') ||
                                                null,
                                        ),
                                    ],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex justify-between gap-2"
                                    >
                                        <dt className="text-slate-500">
                                            {label}
                                        </dt>
                                        <dd className="text-slate-200">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {can.manage && (
                                <button
                                    type="button"
                                    className={`${ccGhost} mt-3`}
                                    onClick={() =>
                                        router.put(
                                            base,
                                            {
                                                name: o.name,
                                                legal_name: o.legal_name,
                                                tax_identifier: o.tax_id,
                                                timezone: o.timezone,
                                                beta_program: !o.beta_program,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {o.beta_program
                                        ? 'Quitar del programa beta'
                                        : 'Agregar al programa beta'}
                                </button>
                            )}
                        </Panel>
                        <Panel title="Consumo">
                            <UsageList usage={detail.usage} />
                        </Panel>
                        <Panel title="Estructura">
                            <div className="grid grid-cols-3 gap-3 text-center">
                                {[
                                    ['Oficinas', detail.offices.length],
                                    ['Usuarios', detail.users.length],
                                    [
                                        'Empresas',
                                        detail.offices.reduce(
                                            (sum, office) =>
                                                sum + office.companies,
                                            0,
                                        ),
                                    ],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="rounded-xl bg-white/[0.03] p-3"
                                    >
                                        <p className="text-2xl font-black text-white">
                                            {value}
                                        </p>
                                        <p className="text-[11px] text-slate-500">
                                            {label}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <p className="mt-3 text-[11px] text-slate-500">
                                Este panel muestra solo metadatos de plataforma.
                                Libro diario, CxC, CxP, nómina y fiscal
                                requieren un acceso de soporte aprobado.
                            </p>
                        </Panel>
                    </div>
                )}

                {tab === 'subscription' && (
                    <Panel title="Suscripción">
                        {s === null ? (
                            <div className="space-y-3">
                                <p className="text-sm text-slate-400">
                                    La organización no tiene suscripción.
                                </p>
                                {can.subscriptions && (
                                    <form
                                        className="flex flex-wrap items-end gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            newSubscription.post(
                                                `${base}/subscriptions`,
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        <Field label="Plan">
                                            <select
                                                className={ccInput}
                                                value={
                                                    newSubscription.data
                                                        .plan_price_id
                                                }
                                                onChange={(e) =>
                                                    newSubscription.setData(
                                                        'plan_price_id',
                                                        Number(e.target.value),
                                                    )
                                                }
                                            >
                                                {plans.map((p) => (
                                                    <option
                                                        key={p.id}
                                                        value={p.id}
                                                    >
                                                        {p.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <label className="flex items-center gap-2 text-xs text-slate-300">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    newSubscription.data.trial
                                                }
                                                onChange={(e) =>
                                                    newSubscription.setData(
                                                        'trial',
                                                        e.target.checked,
                                                    )
                                                }
                                            />{' '}
                                            En prueba
                                        </label>
                                        <button className={ccButton}>
                                            Crear suscripción
                                        </button>
                                    </form>
                                )}
                            </div>
                        ) : (
                            <dl className="grid gap-3 text-xs md:grid-cols-4">
                                {[
                                    [
                                        'Estado',
                                        <Pill
                                            key="s"
                                            tone={statusTone[s.status]}
                                        >
                                            {s.status_label}
                                        </Pill>,
                                    ],
                                    ['Plan', s.plan ?? '—'],
                                    [
                                        'Ciclo',
                                        s.billing_cycle === 'annual'
                                            ? 'Anual'
                                            : 'Mensual',
                                    ],
                                    ['Precio base', money(s.base_price)],
                                    ['Descuento', `${s.discount_percent} %`],
                                    [
                                        'Fin del período',
                                        date(s.current_period_end),
                                    ],
                                    ['Prueba hasta', date(s.trial_ends_at)],
                                    [
                                        'Cancela al final',
                                        s.cancel_at_period_end ? 'Sí' : 'No',
                                    ],
                                    [
                                        'Cambio programado',
                                        date(s.scheduled_change_at),
                                    ],
                                    [
                                        'Sobre el límite',
                                        s.over_limit ? 'OVER_LIMIT' : 'No',
                                    ],
                                ].map(([label, value], index) => (
                                    <div key={index}>
                                        <dt className="text-slate-500">
                                            {label}
                                        </dt>
                                        <dd className="mt-0.5 text-slate-100">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                                <div className="md:col-span-4">
                                    <Link
                                        href={`/control-center/subscriptions?search=${encodeURIComponent(o.name)}`}
                                        className={ccGhost}
                                    >
                                        Gestionar en Suscripciones →
                                    </Link>
                                </div>
                            </dl>
                        )}
                    </Panel>
                )}

                {tab === 'features' && (
                    <div className="grid gap-3 xl:grid-cols-2">
                        <Panel title="Funciones efectivas">
                            <Table
                                headers={[
                                    'Función',
                                    'Estado',
                                    'Origen',
                                    'Detalle',
                                ]}
                                empty={
                                    detail.features.length === 0 &&
                                    'Sin funciones.'
                                }
                            >
                                {detail.features.map((feature) => (
                                    <tr key={feature.key}>
                                        <td className="px-2 py-1.5 font-mono">
                                            {feature.key}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            <Pill
                                                tone={
                                                    feature.enabled
                                                        ? 'green'
                                                        : 'slate'
                                                }
                                            >
                                                {feature.enabled
                                                    ? 'Habilitada'
                                                    : 'Deshabilitada'}
                                            </Pill>
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {{
                                                plan: 'Plan',
                                                override: 'Excepción',
                                                dependency: 'Dependencia',
                                            }[feature.source] ?? feature.source}
                                        </td>
                                        <td className="px-2 py-1.5 text-slate-500">
                                            {feature.reason ?? ''}
                                            {feature.expires_at
                                                ? ` · vence ${date(feature.expires_at)}`
                                                : ''}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Panel>
                        <Panel title="Excepciones (overrides)">
                            {can.features && (
                                <form
                                    className="mb-3 grid gap-2 md:grid-cols-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        override.post(`${base}/overrides`, {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                override.reset(
                                                    'reason',
                                                    'expires_at',
                                                ),
                                        });
                                    }}
                                >
                                    <Field label="Función">
                                        <select
                                            className={ccInput}
                                            value={override.data.feature_code}
                                            onChange={(e) =>
                                                override.setData(
                                                    'feature_code',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {features.map((f) => (
                                                <option
                                                    key={f.code}
                                                    value={f.code}
                                                >
                                                    {f.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field label="Valor">
                                        <select
                                            className={ccInput}
                                            value={
                                                override.data.value ? '1' : '0'
                                            }
                                            onChange={(e) =>
                                                override.setData(
                                                    'value',
                                                    e.target.value === '1',
                                                )
                                            }
                                        >
                                            <option value="1">Habilitar</option>
                                            <option value="0">
                                                Deshabilitar
                                            </option>
                                        </select>
                                    </Field>
                                    <Field label="Vence (opcional)">
                                        <input
                                            type="date"
                                            className={ccInput}
                                            value={override.data.expires_at}
                                            onChange={(e) =>
                                                override.setData(
                                                    'expires_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Motivo">
                                        <input
                                            className={ccInput}
                                            value={override.data.reason}
                                            onChange={(e) =>
                                                override.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            minLength={5}
                                        />
                                    </Field>
                                    <div className="md:col-span-2">
                                        <button
                                            className={ccButton}
                                            disabled={override.processing}
                                        >
                                            Registrar excepción
                                        </button>
                                    </div>
                                </form>
                            )}
                            <Table
                                headers={[
                                    'Función',
                                    'Valor',
                                    'Motivo',
                                    'Vence',
                                    'Estado',
                                    '',
                                ]}
                                empty={
                                    detail.overrides.length === 0 &&
                                    'Sin excepciones.'
                                }
                            >
                                {detail.overrides.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-2 py-1.5 font-mono">
                                            {row.feature}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {row.value ? 'ON' : 'OFF'}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {row.reason}
                                            <span className="block text-slate-500">
                                                {row.granted_by}
                                            </span>
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {date(row.expires_at)}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            <Pill
                                                tone={
                                                    statusTone[
                                                        row.status === 'active'
                                                            ? 'active'
                                                            : row.status
                                                    ]
                                                }
                                            >
                                                {{
                                                    active: 'Activa',
                                                    revoked: 'Revocada',
                                                    expired: 'Vencida',
                                                }[row.status] ?? row.status}
                                            </Pill>
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {can.features &&
                                                row.status === 'active' && (
                                                    <button
                                                        type="button"
                                                        className={ccGhost}
                                                        onClick={() => {
                                                            const reason =
                                                                window.prompt(
                                                                    'Motivo de la revocación',
                                                                );

                                                            if (reason) {
                                                                router.post(
                                                                    `/control-center/overrides/${row.id}/revoke`,
                                                                    { reason },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Revocar
                                                    </button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Panel>
                    </div>
                )}

                {tab === 'usage' && (
                    <Panel title="Límites y consumo">
                        <UsageList usage={detail.usage} />
                    </Panel>
                )}

                {tab === 'offices' && (
                    <Panel title="Oficinas">
                        <Table
                            headers={[
                                'Código',
                                'Oficina',
                                'Ciudad',
                                'Usuarios',
                                'Empresas',
                                'Estado',
                            ]}
                            empty={
                                detail.offices.length === 0 && 'Sin oficinas.'
                            }
                        >
                            {detail.offices.map((office) => (
                                <tr key={office.id}>
                                    <td className="px-2 py-2 font-mono">
                                        {office.code}
                                    </td>
                                    <td className="px-2 py-2 font-semibold text-white">
                                        {office.name}
                                        {office.primary && (
                                            <span className="ml-2 text-[10px] text-[#e8b04b]">
                                                Principal
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {office.city ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {office.users}
                                    </td>
                                    <td className="px-2 py-2">
                                        {office.companies}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[office.status]}>
                                            {office.status}
                                        </Pill>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                )}

                {tab === 'users' && (
                    <Panel title="Usuarios de la organización">
                        <Table
                            headers={[
                                'Nombre',
                                'Correo',
                                'Rol',
                                'Estado',
                                '2FA',
                                'Última sesión',
                            ]}
                            empty={detail.users.length === 0 && 'Sin usuarios.'}
                        >
                            {detail.users.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-2 py-2 font-semibold text-white">
                                        {user.name}
                                    </td>
                                    <td className="px-2 py-2">{user.email}</td>
                                    <td className="px-2 py-2">
                                        {user.role === 'owner'
                                            ? 'Dueño'
                                            : user.role}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[user.status]}>
                                            {user.status}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        {user.two_factor ? (
                                            <Pill tone="green">Activo</Pill>
                                        ) : (
                                            <Pill tone="amber">No</Pill>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {ago(user.last_seen)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                )}

                {tab === 'integrations' && (
                    <Panel title="Integraciones del cliente">
                        <div className="grid gap-3 md:grid-cols-4">
                            {[
                                [
                                    'Llaves de API activas',
                                    detail.integrations.api_tokens,
                                ],
                                [
                                    'Webhooks activos',
                                    detail.integrations.webhooks,
                                ],
                                [
                                    'Entregas fallidas (7 días)',
                                    detail.integrations.webhook_failures_7d,
                                ],
                                [
                                    'Solicitudes de API (mes)',
                                    detail.integrations.api_requests_month,
                                ],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-xl bg-white/[0.03] p-3"
                                >
                                    <p className="text-2xl font-black text-white">
                                        {value}
                                    </p>
                                    <p className="text-[11px] text-slate-500">
                                        {label}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Panel>
                )}

                {tab === 'billing' && (
                    <div className="grid gap-3 xl:grid-cols-3">
                        <Panel title="Facturas" className="xl:col-span-2">
                            <Table
                                headers={[
                                    'Número',
                                    'Tipo',
                                    'Total',
                                    'Vence',
                                    'Atraso',
                                    'Estado',
                                ]}
                                empty={
                                    detail.billing.length === 0 &&
                                    'Sin facturas.'
                                }
                            >
                                {detail.billing.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="px-2 py-2 font-mono">
                                            {invoice.number}
                                        </td>
                                        <td className="px-2 py-2">
                                            {{
                                                recurring: 'Recurrente',
                                                one_time: 'Cargo único',
                                                adjustment: 'Ajuste',
                                            }[invoice.kind] ?? invoice.kind}
                                        </td>
                                        <td className="px-2 py-2">
                                            {money(
                                                invoice.total,
                                                invoice.currency,
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {date(invoice.due_on)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {invoice.days_overdue > 0
                                                ? `${invoice.days_overdue} días`
                                                : '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            <Pill
                                                tone={
                                                    statusTone[invoice.status]
                                                }
                                            >
                                                {invoice.status_label}
                                            </Pill>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                            <Link
                                href={`/control-center/billing?search=${encodeURIComponent(o.name)}`}
                                className={`${ccGhost} mt-3`}
                            >
                                Gestionar facturas →
                            </Link>
                        </Panel>
                        <div className="grid gap-3">
                            {can.billing && (
                                <Panel title="Otorgar crédito">
                                    <form
                                        className="grid gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            credit.post(`${base}/credits`, {
                                                preserveScroll: true,
                                                onSuccess: () => credit.reset(),
                                            });
                                        }}
                                    >
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            className={ccInput}
                                            placeholder="Monto (Q)"
                                            value={credit.data.amount}
                                            onChange={(e) =>
                                                credit.setData(
                                                    'amount',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <input
                                            className={ccInput}
                                            placeholder="Motivo"
                                            value={credit.data.reason}
                                            onChange={(e) =>
                                                credit.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <input
                                            type="date"
                                            className={ccInput}
                                            value={credit.data.expires_at}
                                            onChange={(e) =>
                                                credit.setData(
                                                    'expires_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <button
                                            className={ccButton}
                                            disabled={credit.processing}
                                        >
                                            Otorgar crédito
                                        </button>
                                    </form>
                                    <ul className="mt-3 space-y-1 text-xs">
                                        {detail.credits.map((c) => (
                                            <li
                                                key={c.id}
                                                className="flex justify-between text-slate-300"
                                            >
                                                <span>{c.reason}</span>
                                                <span>
                                                    {money(Number(c.remaining))}{' '}
                                                    / {money(Number(c.amount))}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </Panel>
                            )}
                            {can.billing && (
                                <Panel title="Cargo único (no recurrente)">
                                    <form
                                        className="grid gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            charge.post(`${base}/charges`, {
                                                preserveScroll: true,
                                                onSuccess: () => charge.reset(),
                                            });
                                        }}
                                    >
                                        <input
                                            className={ccInput}
                                            placeholder="Descripción (ej. migración de datos)"
                                            value={charge.data.description}
                                            onChange={(e) =>
                                                charge.setData(
                                                    'description',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            className={ccInput}
                                            placeholder="Monto (Q)"
                                            value={charge.data.amount}
                                            onChange={(e) =>
                                                charge.setData(
                                                    'amount',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <button
                                            className={ccButton}
                                            disabled={charge.processing}
                                        >
                                            Emitir factura
                                        </button>
                                    </form>
                                </Panel>
                            )}
                        </div>
                    </div>
                )}

                {tab === 'security' && (
                    <Panel title="Seguridad">
                        <p className="text-sm text-slate-300">
                            {detail.security.users_with_2fa} usuario(s) con 2FA
                            · {detail.security.offices_requiring_2fa} oficina(s)
                            exigen 2FA.
                        </p>
                        <Table
                            headers={[
                                'Acceso de soporte',
                                'Estado',
                                'Expira',
                                'Solicitado',
                            ]}
                            empty={
                                detail.security.support_accesses.length === 0 &&
                                'Sin accesos de soporte.'
                            }
                        >
                            {detail.security.support_accesses.map((access) => (
                                <tr key={access.id}>
                                    <td className="px-2 py-2">
                                        {access.reason}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[access.status]}>
                                            {access.status}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        {date(access.expires_at, true)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {date(access.created_at, true)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                )}

                {tab === 'support' && (
                    <Panel title="Tickets">
                        <Table
                            headers={[
                                'Ticket',
                                'Asunto',
                                'Prioridad',
                                'Estado',
                                'Creado',
                            ]}
                            empty={
                                detail.support.length === 0 &&
                                'No hay solicitudes de soporte pendientes.'
                            }
                        >
                            {detail.support.map((ticket) => (
                                <tr key={ticket.id}>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/support/tickets?ticket=${ticket.id}`}
                                            className="font-mono text-white"
                                        >
                                            {ticket.code}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {ticket.subject}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={statusTone[ticket.priority]}
                                        >
                                            {ticket.priority}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[ticket.status]}>
                                            {ticket.status}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        {date(ticket.created_at)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                )}

                {tab === 'activity' && (
                    <Panel title="Actividad de plataforma sobre la organización">
                        <ul className="space-y-2 text-xs">
                            {detail.activity.length === 0 && (
                                <li className="text-slate-500">
                                    Sin actividad registrada.
                                </li>
                            )}
                            {detail.activity.map((event, index) => (
                                <li
                                    key={index}
                                    className="flex justify-between gap-3"
                                >
                                    <span>
                                        <span className="font-mono text-slate-200">
                                            {event.action}
                                        </span>{' '}
                                        <span className="text-slate-500">
                                            {event.actor_name ?? 'Sistema'}
                                            {event.reason
                                                ? ` · ${event.reason}`
                                                : ''}
                                        </span>
                                    </span>
                                    <span className="text-slate-500">
                                        {date(event.created_at, true)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
            </CCPage>
        </>
    );
}
