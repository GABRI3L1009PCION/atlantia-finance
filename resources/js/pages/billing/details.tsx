import { Form, Head } from '@inertiajs/react';
import { field, primary } from '@/components/accounting-ui';
import InputError from '@/components/input-error';
import * as details from '@/routes/billing/details';

export default function BillingDetails({
    billing,
    email,
    issuer,
}: {
    billing: { name: string; nit: string; address: string };
    email: string;
    issuer: { name: string; nit: string };
}) {
    return (
        <main className="min-h-screen bg-[#f8f6f5] px-5 py-12 text-slate-950">
            <Head title="Datos para tu factura de suscripción" />
            <section className="mx-auto max-w-xl rounded-3xl border border-rose-100 bg-white p-8 shadow-sm">
                <img
                    src="/images/atlantia-finance-logo.png"
                    alt="Atlantia Finance"
                    className="mb-6 w-44"
                />
                <h1 className="text-2xl font-semibold">
                    Tu factura de suscripción
                </h1>
                <p className="mt-3 text-sm text-slate-600">
                    {issuer.name}, NIT {issuer.nit}, emitirá la factura de tu
                    plan y enviará el PDF a {email} cuando se confirme el pago.
                </p>
                <p className="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                    Actualmente emitimos documentos simulados, sin validez ante
                    SAT.
                </p>
                <Form {...details.update.form()} className="mt-6 space-y-4">
                    {({ errors, processing }) => (
                        <>
                            <label className="block text-sm">
                                Nombre o razón social
                                <input
                                    className={field}
                                    name="name"
                                    required
                                    maxLength={150}
                                    defaultValue={billing.name}
                                />
                                <InputError message={errors.name} />
                            </label>
                            <label className="block text-sm">
                                NIT del comprador
                                <input
                                    className={field}
                                    name="nit"
                                    required
                                    maxLength={30}
                                    defaultValue={billing.nit}
                                />
                                <InputError message={errors.nit} />
                            </label>
                            <label className="block text-sm">
                                Dirección fiscal
                                <input
                                    className={field}
                                    name="address"
                                    required
                                    maxLength={255}
                                    defaultValue={billing.address}
                                />
                                <InputError message={errors.address} />
                            </label>
                            <InputError message={errors.billing} />
                            <button className={primary} disabled={processing}>
                                Guardar y continuar al pago
                            </button>
                        </>
                    )}
                </Form>
                <p className="mt-6 text-sm text-slate-500">
                    Estos datos se usan para tu compra a Atlantia. La
                    facturación de las ventas de tu empresa se configura por
                    separado, dentro de Facturación electrónica.
                </p>
            </section>
        </main>
    );
}
