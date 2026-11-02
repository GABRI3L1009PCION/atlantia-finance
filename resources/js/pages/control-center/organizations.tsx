import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    CircleCheck,
    FlaskConical,
    Pencil,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    Field,
    Pill,
    Progress,
    RowMenu,
    ago,
    bytes,
    ccButton,
    ccGhost,
    ccInput,
    date,
    Notice,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Organization = {
    id: number;
    code: string | null;
    name: string;
    legal_name: string | null;
    tax_id: string | null;
    timezone: string | null;
    beta_program: boolean;
    plan: string | null;
    plan_version: number | null;
    subscription_status: string | null;
    subscription_label: string;
    organization_status: string;
    organization_label: string;
    offices_count: number;
    users_count: number;
    companies_count: number;
    storage_bytes: number;
    storage_limit: number | null;
    luzma_usage: number;
    luzma_limit: number | null;
    renewal_date: string | null;
    billing_status: string;
    last_activity: string | null;
    destination: string;
};
type Price = { id: number; label: string };

function CreateOrganization({
    plans,
    defaultTrialDays,
    onClose,
}: {
    plans: Price[];
    defaultTrialDays: number;
    onClose: () => void;
}) {
    const form = useForm({
        name: '',
        legal_name: '',
        tax_identifier: '',
        country_code: 'GT',
        timezone: 'America/Guatemala',
        plan_price_id: plans[0]?.id ?? 0,
        trial: true,
        trial_days: String(defaultTrialDays || 14),
        discount_percent: '',
        owner_name: '',
        owner_email: '',
        office_name: 'Oficina principal',
        office_city: '',
        beta_program: false,
        billing_email: '',
    });

    return (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <form
                className={`${panel} w-full max-w-3xl space-y-4 p-5`}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/control-center/organizations', {
                        onSuccess: onClose,
                    });
                }}
            >
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900">
                        Nueva organización cliente
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
                <p className="text-xs text-slate-500">
                    Se crea la organización, su suscripción, la oficina inicial
                    y se invita al dueño (rol de la organización, nunca personal
                    SaaS).
                </p>
                <div className="grid gap-3 md:grid-cols-3">
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
                    <Field label="Razón social">
                        <input
                            className={ccInput}
                            value={form.data.legal_name}
                            onChange={(e) =>
                                form.setData('legal_name', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="NIT">
                        <input
                            className={ccInput}
                            value={form.data.tax_identifier}
                            onChange={(e) =>
                                form.setData('tax_identifier', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Plan">
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
                            {plans.map((plan) => (
                                <option key={plan.id} value={plan.id}>
                                    {plan.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Descuento (%)">
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
                    <label className="mt-6 flex items-center gap-2 text-xs text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.trial}
                            onChange={(e) =>
                                form.setData('trial', e.target.checked)
                            }
                        />{' '}
                        Iniciar en prueba
                    </label>
                    {form.data.trial && (
                        <Field label="Días de prueba">
                            <input
                                type="number"
                                min={1}
                                max={90}
                                className={ccInput}
                                value={form.data.trial_days}
                                onChange={(e) =>
                                    form.setData('trial_days', e.target.value)
                                }
                                required
                            />
                        </Field>
                    )}
                    <Field label="Dueño · nombre">
                        <input
                            className={ccInput}
                            value={form.data.owner_name}
                            onChange={(e) =>
                                form.setData('owner_name', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Dueño · correo">
                        <input
                            type="email"
                            className={ccInput}
                            value={form.data.owner_email}
                            onChange={(e) =>
                                form.setData('owner_email', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Correo de facturación">
                        <input
                            type="email"
                            className={ccInput}
                            value={form.data.billing_email}
                            onChange={(e) =>
                                form.setData('billing_email', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Oficina inicial">
                        <input
                            className={ccInput}
                            value={form.data.office_name}
                            onChange={(e) =>
                                form.setData('office_name', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Ciudad">
                        <input
                            className={ccInput}
                            value={form.data.office_city}
                            onChange={(e) =>
                                form.setData('office_city', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Zona horaria">
                        <input
                            className={ccInput}
                            value={form.data.timezone}
                            onChange={(e) =>
                                form.setData('timezone', e.target.value)
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-2 text-xs text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.beta_program}
                            onChange={(e) =>
                                form.setData('beta_program', e.target.checked)
                            }
                        />{' '}
                        Programa beta
                    </label>
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
                    <button
                        type="submit"
                        className={ccButton}
                        disabled={form.processing}
                    >
                        Crear organización
                    </button>
                </div>
            </form>
        </div>
    );
}

const DAY = 86400000;

/** Edits the organization's own data. The form sends every field the update replaces, so nothing is wiped. */
function EditOrganization({
    organization,
    onClose,
}: {
    organization: Organization;
    onClose: () => void;
}) {
    const form = useForm({
        name: organization.name,
        legal_name: organization.legal_name ?? '',
        tax_identifier: organization.tax_id ?? '',
        timezone: organization.timezone ?? 'America/Guatemala',
        beta_program: organization.beta_program,
    });

    return (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <form
                className={`${panel} w-full max-w-xl space-y-4 p-5`}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(organization.destination, {
                        preserveScroll: true,
                        onSuccess: onClose,
                    });
                }}
            >
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900">
                        Editar organización
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
                <div className="grid gap-3 md:grid-cols-2">
                    <Field label="Nombre" wide>
                        <input
                            className={ccInput}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Razón social">
                        <input
                            className={ccInput}
                            value={form.data.legal_name}
                            onChange={(e) =>
                                form.setData('legal_name', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="NIT">
                        <input
                            className={ccInput}
                            value={form.data.tax_identifier}
                            onChange={(e) =>
                                form.setData('tax_identifier', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Zona horaria">
                        <input
                            className={ccInput}
                            value={form.data.timezone}
                            onChange={(e) =>
                                form.setData('timezone', e.target.value)
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.beta_program}
                            onChange={(e) =>
                                form.setData('beta_program', e.target.checked)
                            }
                        />
                        Programa beta
                    </label>
                </div>
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-[#b91c1c]">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ccGhost} onClick={onClose}>
                        Cancelar
                    </button>
                    <button className={ccButton} disabled={form.processing}>
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    );
}

const moreLinks: [string, string][] = [
    ['subscription', 'Suscripción'],
    ['features', 'Plan y funciones'],
    ['users', 'Usuarios'],
    ['billing', 'Billing'],
    ['activity', 'Actividad'],
];

export default function Organizations({
    organizations,
    filters,
    statuses,
    plans,
    can_create,
    default_trial_days,
}: {
    organizations: Organization[];
    filters: { search?: string; status?: string };
    statuses: Record<string, string>;
    plans: Price[];
    can_create: boolean;
    default_trial_days: number;
}) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Organization | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const [today] = useState(() => Date.now());
    const renewals = organizations.filter((organization) => {
        if (organization.renewal_date === null) {
            return false;
        }

        const days =
            (new Date(organization.renewal_date).getTime() - today) / DAY;

        return days >= -1 && days <= 30;
    }).length;
    const kpis = [
        {
            label: 'Total organizaciones',
            value: organizations.length,
            icon: Building2,
            tile: '#7a0010',
        },
        {
            label: 'Activas',
            value: organizations.filter(
                (organization) => organization.subscription_status === 'active',
            ).length,
            icon: CircleCheck,
            tile: '#7a0010',
        },
        {
            label: 'En prueba',
            value: organizations.filter((organization) =>
                ['trial', 'trialing'].includes(
                    organization.subscription_status ?? '',
                ),
            ).length,
            icon: FlaskConical,
            tile: '#b8862b',
        },
        {
            label: 'Renovaciones próximas',
            value: renewals,
            icon: CalendarDays,
            tile: '#7a0010',
        },
    ];
    const cell = 'px-2 py-2.5 text-center whitespace-nowrap';

    return (
        <>
            <Head title="Organizaciones · Control Center" />
            <div className="space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="border-l-4 border-[#e0a526] pl-5">
                        <h1 className="font-serif text-4xl font-bold text-slate-900">
                            Organizaciones
                        </h1>
                        <p className="mt-1 text-sm text-slate-600">
                            Gestiona los clientes SaaS de Atlantia Finance, su
                            plan, estado, consumo y renovación.
                        </p>
                    </div>
                    {can_create && (
                        <button
                            type="button"
                            onClick={() => setCreating(true)}
                            className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#7a0010] px-5 text-sm font-bold text-white shadow-[0_10px_24px_-14px_rgba(122,0,16,.9)] transition hover:bg-[#8f0b20]"
                        >
                            <Plus className="size-5" /> Nueva organización
                        </button>
                    )}
                </header>
                <Notice />

                <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.label}
                            className="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]"
                        >
                            <span
                                className="grid size-12 shrink-0 place-items-center rounded-xl text-white"
                                style={{ background: kpi.tile }}
                            >
                                <kpi.icon className="size-6" />
                            </span>
                            <span>
                                <span className="block text-sm font-semibold text-slate-800">
                                    {kpi.label}
                                </span>
                                <span className="block font-serif text-2xl font-bold text-slate-900">
                                    {kpi.value}
                                </span>
                            </span>
                        </div>
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]">
                    <form
                        className="mb-4 flex flex-wrap gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/organizations',
                                { ...filters, search },
                                { preserveState: true },
                            );
                        }}
                    >
                        <label className="relative w-full max-w-xs">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                className={`${ccInput} pl-9`}
                                placeholder="Nombre, código o NIT"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </label>
                        <select
                            className={`${ccInput} max-w-[220px]`}
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get(
                                    '/control-center/organizations',
                                    {
                                        ...filters,
                                        status: e.target.value || undefined,
                                    },
                                    { preserveState: true },
                                )
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

                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="min-w-full text-[13px] text-slate-800">
                            <thead>
                                <tr className="bg-[#111113] text-[10px] font-bold tracking-wide whitespace-nowrap text-white uppercase">
                                    <th className="px-3 py-3 text-left">
                                        Organización
                                    </th>
                                    <th className="px-3 py-3 text-left">
                                        Plan
                                    </th>
                                    {[
                                        'Suscripción',
                                        'Oficinas',
                                        'Usuarios',
                                        'Empresas',
                                        'Storage',
                                        'Luzma (mes)',
                                        'Renovación',
                                        'Estado',
                                        'Acciones',
                                    ].map((header) => (
                                        <th key={header} className={cell}>
                                            {header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {organizations.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={11}
                                            className="px-3 py-10 text-center text-slate-500"
                                        >
                                            No existen organizaciones
                                            registradas.
                                        </td>
                                    </tr>
                                )}
                                {organizations.map((organization) => (
                                    <tr
                                        key={organization.id}
                                        className="hover:bg-slate-50"
                                    >
                                        <td className="min-w-[180px] px-3 py-2.5">
                                            <Link
                                                href={organization.destination}
                                                className="flex items-center gap-2.5"
                                                title={`Última actividad: ${ago(organization.last_activity)}`}
                                            >
                                                <Building2 className="size-5 shrink-0 text-[#b8862b]" />
                                                <span>
                                                    <span className="block leading-tight font-bold text-slate-900">
                                                        {organization.name}
                                                    </span>
                                                    <span className="block text-xs text-slate-500">
                                                        {[
                                                            organization.code,
                                                            `NIT ${organization.tax_id ?? '—'}`,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                </span>
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2.5 whitespace-nowrap">
                                            {organization.plan ?? '—'}
                                            {organization.plan_version !==
                                                null &&
                                                ` v${organization.plan_version}`}
                                        </td>
                                        <td className={cell}>
                                            <Pill
                                                tone={
                                                    statusTone[
                                                        organization.subscription_status ??
                                                            'incomplete'
                                                    ]
                                                }
                                            >
                                                {
                                                    organization.subscription_label
                                                }
                                            </Pill>
                                        </td>
                                        <td className={cell}>
                                            {organization.offices_count}
                                        </td>
                                        <td className={cell}>
                                            {organization.users_count}
                                        </td>
                                        <td className={cell}>
                                            {organization.companies_count}
                                        </td>
                                        <td className="w-24 px-2 py-2.5">
                                            <span className="block text-center text-xs whitespace-nowrap">
                                                {bytes(
                                                    organization.storage_bytes,
                                                )}{' '}
                                                /{' '}
                                                {organization.storage_limit ===
                                                null
                                                    ? '∞'
                                                    : bytes(
                                                          organization.storage_limit,
                                                      )}
                                            </span>
                                            {organization.storage_limit !==
                                                null &&
                                                organization.storage_limit >
                                                    0 && (
                                                    <Progress
                                                        percent={
                                                            (organization.storage_bytes *
                                                                100) /
                                                            organization.storage_limit
                                                        }
                                                        tone={
                                                            organization.storage_bytes *
                                                                100 <
                                                            organization.storage_limit *
                                                                80
                                                                ? '#e0a526'
                                                                : undefined
                                                        }
                                                    />
                                                )}
                                        </td>
                                        <td className={cell}>
                                            {organization.luzma_usage} /{' '}
                                            {organization.luzma_limit === null
                                                ? '∞'
                                                : organization.luzma_limit}
                                        </td>
                                        <td
                                            className={`${cell} whitespace-nowrap`}
                                        >
                                            {date(organization.renewal_date)}
                                        </td>
                                        <td className={cell}>
                                            <Pill
                                                tone={
                                                    statusTone[
                                                        organization
                                                            .organization_status
                                                    ]
                                                }
                                            >
                                                {
                                                    organization.organization_label
                                                }
                                            </Pill>
                                        </td>
                                        <td className="px-2 py-2.5">
                                            <div className="flex items-center justify-center gap-1.5">
                                                <Link
                                                    href={
                                                        organization.destination
                                                    }
                                                    className="inline-flex h-8 items-center rounded-lg bg-[#7a0010] px-3 text-xs font-bold text-white hover:bg-[#8f0b20]"
                                                >
                                                    Ver
                                                </Link>
                                                {can_create && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setEditing(
                                                                organization,
                                                            )
                                                        }
                                                        title="Editar"
                                                        aria-label="Editar"
                                                        className="inline-flex h-8 items-center gap-1 rounded-lg border border-slate-300 px-2.5 text-xs font-semibold text-slate-800 hover:border-[#7a0010]/50"
                                                    >
                                                        <Pencil className="size-3.5" />
                                                        <span className="hidden min-[1500px]:inline">
                                                            Editar
                                                        </span>
                                                    </button>
                                                )}
                                                <RowMenu
                                                    links={moreLinks.map(
                                                        ([tab, label]) => ({
                                                            label,
                                                            href: `${organization.destination}?tab=${tab}`,
                                                        }),
                                                    )}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            {creating && (
                <CreateOrganization
                    plans={plans}
                    defaultTrialDays={default_trial_days}
                    onClose={() => setCreating(false)}
                />
            )}
            {editing && (
                <EditOrganization
                    key={editing.id}
                    organization={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
