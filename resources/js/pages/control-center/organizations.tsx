import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2, Plus, X } from 'lucide-react';
import { useState } from 'react';
import {
    CCPage,
    Field,
    Panel,
    Pill,
    Progress,
    Table,
    ago,
    bytes,
    ccButton,
    ccGhost,
    ccInput,
    date,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Organization = {
    id: number;
    code: string | null;
    name: string;
    tax_id: string | null;
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
    onClose,
}: {
    plans: Price[];
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
        discount_percent: '',
        owner_name: '',
        owner_email: '',
        office_name: 'Oficina principal',
        office_city: '',
        beta_program: false,
        billing_email: '',
    });

    return (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/70 p-4 backdrop-blur-sm">
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
                    <h2 className="text-lg font-bold text-white">
                        Nueva organización cliente
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="text-slate-400 hover:text-white"
                    >
                        <X className="size-5" />
                    </button>
                </div>
                <p className="text-xs text-slate-400">
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
                    <label className="mt-6 flex items-center gap-2 text-xs text-slate-300">
                        <input
                            type="checkbox"
                            checked={form.data.trial}
                            onChange={(e) =>
                                form.setData('trial', e.target.checked)
                            }
                        />{' '}
                        Iniciar en prueba
                    </label>
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
                    <label className="flex items-center gap-2 text-xs text-slate-300">
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

export default function Organizations({
    organizations,
    filters,
    statuses,
    plans,
    can_create,
}: {
    organizations: Organization[];
    filters: { search?: string; status?: string };
    statuses: Record<string, string>;
    plans: Price[];
    can_create: boolean;
}) {
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');

    return (
        <>
            <Head title="Organizaciones · Control Center" />
            <CCPage
                title="Organizaciones"
                subtitle="Clientes SaaS de Atlantia Finance: plan, estado, consumo y renovación. Solo metadatos de plataforma: el contenido contable requiere un acceso de soporte formal."
                actions={
                    can_create && (
                        <button
                            type="button"
                            className={ccButton}
                            onClick={() => setCreating(true)}
                        >
                            <Plus className="size-4" /> Nueva organización
                        </button>
                    )
                }
            >
                <Panel>
                    <form
                        className="mb-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/organizations',
                                { ...filters, search },
                                { preserveState: true },
                            );
                        }}
                    >
                        <input
                            className={`${ccInput} max-w-xs`}
                            placeholder="Nombre, código o NIT"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <select
                            className={`${ccInput} max-w-[200px]`}
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
                    <Table
                        headers={[
                            'Organización',
                            'Plan',
                            'Suscripción',
                            'Oficinas',
                            'Usuarios',
                            'Empresas',
                            'Storage',
                            'Luzma (mes)',
                            'Renovación',
                            'Estado',
                            'Última actividad',
                        ]}
                        empty={
                            organizations.length === 0 &&
                            'No existen organizaciones registradas.'
                        }
                    >
                        {organizations.map((organization) => (
                            <tr
                                key={organization.id}
                                className="hover:bg-white/[0.02]"
                            >
                                <td className="px-2 py-2.5">
                                    <Link
                                        href={organization.destination}
                                        className="flex items-center gap-2 font-semibold text-white"
                                    >
                                        <Building2 className="size-4 text-[#e8b04b]" />
                                        <span>
                                            {organization.name}
                                            <span className="block text-[10px] font-normal text-slate-500">
                                                {organization.code} · NIT{' '}
                                                {organization.tax_id ?? '—'}
                                            </span>
                                        </span>
                                    </Link>
                                </td>
                                <td className="px-2 py-2.5">
                                    {organization.plan ?? '—'}
                                    {organization.plan_version !== null && (
                                        <span className="text-slate-500">
                                            {' '}
                                            v{organization.plan_version}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2.5">
                                    <Pill
                                        tone={
                                            statusTone[
                                                organization.subscription_status ??
                                                    'incomplete'
                                            ]
                                        }
                                    >
                                        {organization.subscription_label}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2.5">
                                    {organization.offices_count}
                                </td>
                                <td className="px-2 py-2.5">
                                    {organization.users_count}
                                </td>
                                <td className="px-2 py-2.5">
                                    {organization.companies_count}
                                </td>
                                <td className="w-32 px-2 py-2.5">
                                    <span className="text-[11px]">
                                        {bytes(organization.storage_bytes)} /{' '}
                                        {organization.storage_limit === null
                                            ? '∞'
                                            : bytes(organization.storage_limit)}
                                    </span>
                                    {organization.storage_limit !== null &&
                                        organization.storage_limit > 0 && (
                                            <Progress
                                                percent={
                                                    (organization.storage_bytes *
                                                        100) /
                                                    organization.storage_limit
                                                }
                                            />
                                        )}
                                </td>
                                <td className="px-2 py-2.5">
                                    {organization.luzma_usage} /{' '}
                                    {organization.luzma_limit === null
                                        ? '∞'
                                        : organization.luzma_limit}
                                </td>
                                <td className="px-2 py-2.5 whitespace-nowrap">
                                    {date(organization.renewal_date)}
                                </td>
                                <td className="px-2 py-2.5">
                                    <Pill
                                        tone={
                                            statusTone[
                                                organization.organization_status
                                            ]
                                        }
                                    >
                                        {organization.organization_label}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2.5 whitespace-nowrap text-slate-400">
                                    {ago(organization.last_activity)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
            {creating && (
                <CreateOrganization
                    plans={plans}
                    onClose={() => setCreating(false)}
                />
            )}
        </>
    );
}
