import { Form, Link } from '@inertiajs/react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import { Card, field, primary, secondary } from '@/components/accounting-ui';
import InputError from '@/components/input-error';
import * as fel from '@/routes/accounting/fel';
import * as settings from '@/routes/accounting/fel/settings';

export default function FelSettings({
    company,
    issuer,
    isPlatform,
    notice,
}: {
    company: Company;
    issuer: {
        name: string;
        nit: string;
        address: string;
        logo_data_uri: string | null;
        provider: string;
        environment: string;
    };
    isPlatform: boolean;
    notice: string | null;
}) {
    return (
        <AccountingSection
            company={company}
            title="Configuración de facturación"
            action={
                <Link className={secondary} href={fel.index(company.public_id)}>
                    Ver facturas
                </Link>
            }
        >
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
                            <label className="text-sm">
                                Proveedor
                                <select
                                    className={field}
                                    name="provider"
                                    defaultValue={issuer.provider}
                                >
                                    <option value="mock">Simulador FEL</option>
                                </select>
                                <InputError message={errors.provider} />
                            </label>
                            <label className="text-sm">
                                Ambiente
                                <select
                                    className={field}
                                    name="environment"
                                    defaultValue={issuer.environment}
                                >
                                    <option value="test">
                                        Pruebas · sin validez fiscal
                                    </option>
                                </select>
                                <InputError message={errors.environment} />
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
                                    PNG o JPG, hasta 512 KB y 2000 × 2000
                                    píxeles.
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
                            <p className="text-sm text-amber-900 sm:col-span-2">
                                La conexión a un certificador real se habilitará
                                en una próxima fase. Por ahora, todas las
                                emisiones son simuladas.
                            </p>
                            <div>
                                <button
                                    className={primary}
                                    disabled={processing}
                                >
                                    Guardar configuración
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </Card>
        </AccountingSection>
    );
}
