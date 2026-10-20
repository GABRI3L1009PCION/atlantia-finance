import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import { Card, field, primary, secondary } from '@/components/accounting-ui';
import InputError from '@/components/input-error';
import * as fel from '@/routes/accounting/fel';
import * as settings from '@/routes/accounting/fel/settings';

type FelSettingsProps = {
    company: Company;
    issuer: {
        name: string;
        nit: string;
        address: string;
        logo_data_uri: string | null;
        provider: string;
        environment: string;
        establishment_code?: string;
        vat_affiliation?: string;
        issuer_email?: string | null;
        trade_name?: string | null;
        certifier_environment?: string;
    };
    certification?: { certifier: string; credentials: Record<string, string> };
    certifiers?: {
        code: string;
        label: string;
        fields: { key: string; label: string; secret?: boolean }[];
    }[];
    vatAffiliations?: Record<string, string>;
    isPlatform: boolean;
    notice: string | null;
};

export function FelIssuerForm({
    company,
    issuer,
    certification,
    certifiers = [],
    vatAffiliations = {},
    isPlatform,
    notice,
}: FelSettingsProps) {
    const [certifier, setCertifier] = useState(
        certification?.certifier ?? 'none',
    );
    const selected = certifiers.find((item) => item.code === certifier);

    return (
        <Card
            title={
                isPlatform
                    ? 'Atlantia · Facturación de suscripciones'
                    : 'Emisor de las facturas de esta empresa'
            }
        >
            <p className="mb-5 text-sm text-slate-600">
                {isPlatform
                    ? 'Estos datos se usan para las facturas de los planes vendidos por Atlantia.'
                    : 'Configura cómo aparecerá tu empresa en las facturas de tus ventas. Esto no modifica la factura de tu suscripción a Atlantia.'}
            </p>
            {notice && (
                <p
                    role="status"
                    className="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"
                >
                    {notice}
                </p>
            )}
            <Form
                {...settings.update.form(company.public_id)}
                className="grid gap-4 sm:grid-cols-2"
            >
                {({ errors, processing }) => (
                    <>
                        <label className="text-sm">
                            Nombre o razón social
                            <input
                                className={field}
                                name="name"
                                defaultValue={issuer.name}
                                required
                                maxLength={150}
                            />
                            <InputError message={errors.name} />
                        </label>
                        <label className="text-sm">
                            NIT del emisor
                            <input
                                className={field}
                                name="nit"
                                defaultValue={issuer.nit}
                                required
                                maxLength={30}
                            />
                            <InputError message={errors.nit} />
                        </label>
                        <label className="text-sm sm:col-span-2">
                            Dirección fiscal
                            <input
                                className={field}
                                name="address"
                                defaultValue={issuer.address}
                                required
                                maxLength={255}
                            />
                            <InputError message={errors.address} />
                        </label>
                        <div className="sm:col-span-2">
                            {issuer.logo_data_uri && (
                                <img
                                    src={issuer.logo_data_uri}
                                    alt="Logo del emisor"
                                    className="mb-3 max-h-28 max-w-56 object-contain"
                                />
                            )}
                            <label className="block text-sm">
                                Logo para el PDF
                                <input
                                    className={field}
                                    type="file"
                                    name="logo"
                                    accept="image/png,image/jpeg"
                                />
                                <InputError message={errors.logo} />
                            </label>
                            <p className="mt-2 text-xs text-slate-500">
                                PNG o JPG, hasta 512 KB y 2000 × 2000 píxeles.
                            </p>
                            {issuer.logo_data_uri && (
                                <label className="mt-3 block text-sm">
                                    <input
                                        type="checkbox"
                                        name="remove_logo"
                                        value="1"
                                    />{' '}
                                    Quitar logo de las próximas facturas
                                </label>
                            )}
                        </div>
                        <div className="grid gap-4 rounded-xl border border-slate-200 p-4 sm:col-span-2 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <p className="font-semibold">
                                    Cómo se emiten las facturas
                                </p>
                                <p className="text-xs text-slate-500">
                                    Puedes emitir directamente en el portal de
                                    la SAT, sin certificador ni costo, y
                                    registrar aquí lo que la SAT emitió. Si
                                    facturas mucho, elige un certificador
                                    autorizado: sus credenciales se guardan
                                    cifradas y nunca se vuelven a mostrar
                                    completas.
                                </p>
                            </div>
                            <label className="text-sm">
                                Forma de emisión
                                <select
                                    className={field}
                                    name="certifier"
                                    value={certifier}
                                    onChange={(event) =>
                                        setCertifier(event.target.value)
                                    }
                                >
                                    {certifiers.map((item) => (
                                        <option
                                            key={item.code}
                                            value={item.code}
                                        >
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.certifier} />
                            </label>
                            <label
                                className={`text-sm ${certifier === 'sat_manual' ? 'hidden' : ''}`}
                            >
                                Ambiente del certificador
                                <select
                                    className={field}
                                    name="certifier_environment"
                                    defaultValue={
                                        issuer.certifier_environment ?? 'test'
                                    }
                                >
                                    <option value="test">
                                        Pruebas del certificador
                                    </option>
                                    <option value="production">
                                        Producción
                                    </option>
                                </select>
                                <InputError
                                    message={errors.certifier_environment}
                                />
                            </label>
                            <label className="text-sm">
                                Código de establecimiento
                                <input
                                    className={field}
                                    name="establishment_code"
                                    defaultValue={
                                        issuer.establishment_code ?? '1'
                                    }
                                    inputMode="numeric"
                                    maxLength={10}
                                    required
                                />
                                <InputError
                                    message={errors.establishment_code}
                                />
                            </label>
                            <label className="text-sm">
                                Afiliación al IVA
                                <select
                                    className={field}
                                    name="vat_affiliation"
                                    defaultValue={
                                        issuer.vat_affiliation ?? 'GEN'
                                    }
                                >
                                    {Object.entries(vatAffiliations).map(
                                        ([code, label]) => (
                                            <option key={code} value={code}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <InputError message={errors.vat_affiliation} />
                            </label>
                            <label className="text-sm">
                                Correo del emisor
                                <input
                                    className={field}
                                    type="email"
                                    name="issuer_email"
                                    defaultValue={issuer.issuer_email ?? ''}
                                    maxLength={150}
                                />
                                <InputError message={errors.issuer_email} />
                            </label>
                            <label className="text-sm sm:col-span-2">
                                Nombre comercial (opcional)
                                <input
                                    className={field}
                                    name="trade_name"
                                    defaultValue={issuer.trade_name ?? ''}
                                    maxLength={150}
                                />
                                <InputError message={errors.trade_name} />
                            </label>
                            {selected?.fields.map((item) => (
                                <label
                                    key={`${certifier}-${item.key}`}
                                    className="text-sm"
                                >
                                    {item.label}
                                    <input
                                        className={field}
                                        type={item.secret ? 'password' : 'text'}
                                        name={`credentials[${item.key}]`}
                                        defaultValue={
                                            certifier ===
                                            certification?.certifier
                                                ? (certification?.credentials[
                                                      item.key
                                                  ] ?? '')
                                                : ''
                                        }
                                        autoComplete="off"
                                    />
                                    <InputError
                                        message={
                                            (errors as Record<string, string>)[
                                                `credentials.${item.key}`
                                            ]
                                        }
                                    />
                                </label>
                            ))}
                            {certifier === 'sat_manual' ? (
                                <p className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900 sm:col-span-2">
                                    Atlantia prepara la factura; tú la emites en
                                    el portal de la SAT con tu usuario y
                                    registras aquí el XML que la SAT te da.
                                    Atlantia no pide ni guarda tu usuario ni tu
                                    clave de la SAT.
                                </p>
                            ) : (
                                <p className="text-sm text-amber-900 sm:col-span-2">
                                    {certifier === 'none'
                                        ? 'Selecciona cómo emite facturas esta empresa para poder prepararlas.'
                                        : 'Datos del certificador guardados. La emisión con validez ante SAT se activa al validar la conexión con el ambiente de pruebas de este certificador.'}
                                </p>
                            )}
                        </div>
                        <div>
                            <button className={primary} disabled={processing}>
                                Guardar configuración
                            </button>
                        </div>
                    </>
                )}
            </Form>
        </Card>
    );
}

export default function FelSettings(props: FelSettingsProps) {
    return (
        <AccountingSection
            company={props.company}
            title="Configuración de facturación"
            action={
                <Link
                    className={secondary}
                    href={fel.index(props.company.public_id)}
                >
                    Ver facturas
                </Link>
            }
        >
            <FelIssuerForm {...props} />
        </AccountingSection>
    );
}
