import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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
        <>
            <Head title="Datos para tu factura de suscripción" />

            <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-xs leading-5 text-slate-700">
                <strong className="text-emerald-800">{issuer.name}</strong>, NIT{' '}
                {issuer.nit}, emitirá la factura electrónica de tu plan con
                estos datos y te la enviará en PDF a{' '}
                <strong className="break-all text-slate-950">{email}</strong>{' '}
                una vez emitida, después de confirmar tu pago.
            </p>

            <Form {...details.update.form()} className="flex flex-col gap-3.5">
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor="name">Nombre o razón social</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                maxLength={150}
                                defaultValue={billing.name}
                                placeholder="Como aparece en tu NIT"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="nit">NIT</Label>
                            <Input
                                id="nit"
                                name="nit"
                                required
                                maxLength={30}
                                defaultValue={billing.nit}
                                placeholder="Ej. 1234567-8 o CF"
                            />
                            <InputError message={errors.nit} />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="address">Dirección fiscal</Label>
                            <Input
                                id="address"
                                name="address"
                                required
                                maxLength={255}
                                defaultValue={billing.address}
                                placeholder="Calle, zona, municipio, departamento"
                            />
                            <InputError message={errors.address} />
                        </div>

                        <InputError message={errors.billing} />

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-emerald-700 font-bold text-white hover:bg-emerald-800"
                        >
                            {processing && <Spinner />}
                            Guardar y continuar al pago
                        </Button>

                        <p className="flex items-start gap-1.5 text-[11px] leading-4 text-slate-500">
                            <ShieldCheck className="mt-px size-3.5 shrink-0 text-emerald-700" />
                            Estos datos se usan solo para tu compra a Atlantia.
                            La facturación de las ventas de tu empresa se
                            configura aparte, dentro de Facturación electrónica.
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

BillingDetails.layout = {
    title: 'Datos de tu factura',
    description: 'Un último paso antes del pago de tu suscripción.',
};
