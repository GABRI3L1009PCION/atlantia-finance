import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import type { FormEvent } from 'react';
import {
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    input,
    panel,
    primaryButton,
} from '@/components/office-admin/kit';
import { PoliciesForm } from '@/components/office-admin/policies-form';
import type { OfficePolicies } from '@/components/office-admin/policies-form';

type Office = OfficePolicies & {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    city: string | null;
    state: string | null;
    country_code: string;
    timezone: string;
    status: string;
    is_primary: boolean;
};

type Integration = {
    key: string;
    name: string;
    status: 'ok' | 'warning';
    detail: string;
};

export default function OfficeSettings({
    offices,
    integrations,
    organization,
    permissions,
    notice,
}: {
    offices: Office[];
    integrations: Integration[];
    organization: { name: string; timezone: string };
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    return (
        <>
            <Head title="Configuración de oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Configuración"
                    subtitle={`${organization.name} · datos de la oficina, preferencias e integraciones`}
                />
                {offices.map((office) => (
                    <OfficeForm
                        key={office.id}
                        office={office}
                        editable={permissions.canManageOffices}
                    />
                ))}
                {offices.map((office) => (
                    <PoliciesForm
                        key={`policies-${office.id}`}
                        office={office}
                        editable={permissions.canManagePolicies}
                    />
                ))}

                <section className={`${panel} p-3`}>
                    <SectionHeader id="integraciones" title="Integraciones" />
                    <div className="grid gap-2 md:grid-cols-2">
                        {integrations.map((integration) => (
                            <div
                                key={integration.key}
                                className="flex items-start gap-3 rounded-xl border border-slate-100 p-3"
                            >
                                {integration.status === 'ok' ? (
                                    <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                ) : (
                                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" />
                                )}
                                <div>
                                    <p className="font-bold">
                                        {integration.name}
                                    </p>
                                    <p className="text-sm text-[#526b92]">
                                        {integration.detail}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                    <p className="mt-2 text-xs text-[#526b92]">
                        La facturación electrónica se configura por empresa; la
                        seguridad y el 2FA en{' '}
                        <Link
                            href="/office/security"
                            className="font-bold text-[#640000]"
                        >
                            Seguridad
                        </Link>
                        ; el plan en{' '}
                        <Link
                            href="/billing/plans"
                            className="font-bold text-[#640000]"
                        >
                            Plan y Suscripción
                        </Link>
                        ; las llaves de API y los webhooks en{' '}
                        <Link
                            href="/office/integrations"
                            className="font-bold text-[#640000]"
                        >
                            Integraciones
                        </Link>
                        .
                    </p>
                </section>
            </Shell>
        </>
    );
}

function OfficeForm({
    office,
    editable,
}: {
    office: Office;
    editable: boolean;
}) {
    const form = useForm({
        code: office.code,
        name: office.name,
        email: office.email ?? '',
        phone: office.phone ?? '',
        address_line_1: office.address_line_1 ?? '',
        city: office.city ?? '',
        state: office.state ?? '',
        country_code: office.country_code,
        timezone: office.timezone,
        status: office.status,
        is_primary: office.is_primary,
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/office/offices/${office.id}`, { preserveScroll: true });
    };
    const field = (
        key:
            | 'name'
            | 'code'
            | 'email'
            | 'phone'
            | 'address_line_1'
            | 'city'
            | 'state'
            | 'timezone',
        label: string,
    ) => (
        <label className="text-sm font-semibold">
            {label}
            <input
                className={`${input} mt-1`}
                value={form.data[key]}
                disabled={!editable}
                onChange={(event) => form.setData(key, event.target.value)}
            />
            {form.errors[key] && (
                <span className="text-xs text-red-700">{form.errors[key]}</span>
            )}
        </label>
    );

    return (
        <section className={`${panel} p-3`}>
            <SectionHeader title={office.name} />
            <form onSubmit={submit} className="grid gap-3 md:grid-cols-3">
                {field('name', 'Nombre')}
                {field('code', 'Código')}
                {field('email', 'Correo')}
                {field('phone', 'Teléfono')}
                {field('address_line_1', 'Dirección')}
                {field('city', 'Ciudad')}
                {field('state', 'Departamento')}
                {field('timezone', 'Zona horaria')}
                <label className="text-sm font-semibold">
                    Estado
                    <select
                        className={`${input} mt-1`}
                        value={form.data.status}
                        disabled={!editable}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                    >
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                    {form.errors.status && (
                        <span className="text-xs text-red-700">
                            {form.errors.status}
                        </span>
                    )}
                </label>
                {editable && (
                    <div className="md:col-span-3">
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Guardar cambios
                        </button>
                    </div>
                )}
            </form>
        </section>
    );
}
