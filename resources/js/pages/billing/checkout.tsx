import { Form, Head, Link } from '@inertiajs/react';
import {
    CheckCircle2,
    CreditCard,
    LoaderCircle,
    LockKeyhole,
    ShieldCheck,
} from 'lucide-react';

type Props = {
    organization: { name: string; status: string };
    subscription: { publicId: string; status: string };
    plan: {
        code: string;
        name: string;
        billingPeriod: 'monthly' | 'annual';
        currencyCode: string;
        amount: string;
    };
    paymentProviderReady: boolean;
    paymentStatus?: 'processing' | 'cancelled' | null;
};

const currency = new Intl.NumberFormat('es-GT', {
    style: 'currency',
    currency: 'GTQ',
    minimumFractionDigits: 2,
});

export default function Checkout({
    organization,
    plan,
    paymentProviderReady,
    paymentStatus,
}: Props) {
    return (
        <>
            <Head title="Completa tu suscripción" />
            <main className="min-h-screen bg-[#f8f6f5] px-5 py-10 text-slate-950">
                <div className="mx-auto grid max-w-5xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl lg:grid-cols-[minmax(0,1.05fr)_minmax(0,.95fr)]">
                    <section className="bg-[#640000] p-8 text-white sm:p-12">
                        <p className="text-xs font-bold tracking-[0.24em] text-rose-100 uppercase">
                            Atlantia Systems
                        </p>
                        <h1 className="mt-10 text-4xl leading-tight font-bold">
                            Tu espacio está casi listo.
                        </h1>
                        <p className="mt-4 max-w-md leading-7 text-rose-100">
                            Verificamos tu correo. Ahora confirma la suscripción
                            para habilitar la organización y comenzar la
                            configuración inicial.
                        </p>
                        <div className="mt-10 space-y-5 text-sm">
                            <div className="flex items-center gap-3">
                                <CheckCircle2 className="size-5" />
                                Correo verificado
                            </div>
                            <div className="flex items-center gap-3">
                                <ShieldCheck className="size-5" />
                                Precio validado en el servidor
                            </div>
                            <div className="flex items-center gap-3">
                                <LockKeyhole className="size-5" />
                                Activación exclusiva mediante webhook
                            </div>
                        </div>
                    </section>
                    <section className="p-8 sm:p-12">
                        <span className="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1.5 text-xs font-bold text-[#640000]">
                            <LockKeyhole className="size-3.5" /> Checkout
                            protegido
                        </span>
                        <h2 className="mt-5 text-3xl font-bold">
                            Resumen de compra
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            {organization.name}
                        </p>
                        <div className="mt-8 rounded-2xl border border-rose-200 bg-rose-50/50 p-6">
                            <div className="flex items-start justify-between gap-5">
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-[#640000] uppercase">
                                        Plan seleccionado
                                    </p>
                                    <p className="mt-2 text-2xl font-bold">
                                        {plan.name}
                                    </p>
                                    <p className="mt-1 text-sm text-slate-600">
                                        Facturación{' '}
                                        {plan.billingPeriod === 'annual'
                                            ? 'anual'
                                            : 'mensual'}
                                    </p>
                                </div>
                                <p className="text-right text-2xl font-bold text-[#640000]">
                                    {currency.format(Number(plan.amount))}
                                </p>
                            </div>
                        </div>
                        <div className="mt-6 flex items-center justify-between border-y border-slate-200 py-5">
                            <span className="font-medium">Total a pagar</span>
                            <strong className="text-2xl text-[#640000]">
                                {currency.format(Number(plan.amount))}
                            </strong>
                        </div>
                        {paymentStatus === 'processing' && (
                            <p className="mt-5 rounded-lg bg-emerald-50 px-4 py-3 text-center text-sm text-emerald-800">
                                Recibimos tu regreso de Recurrente. Estamos
                                confirmando el pago de forma segura.
                            </p>
                        )}
                        {paymentStatus === 'cancelled' && (
                            <p className="mt-5 rounded-lg bg-amber-50 px-4 py-3 text-center text-sm text-amber-800">
                                El checkout fue cancelado. No se realizó ningún
                                cobro.
                            </p>
                        )}
                        <Form action="/billing/checkout" method="post">
                            {({ processing, errors }) => (
                                <>
                                    {processing && (
                                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#040b15]/80 px-5 backdrop-blur-sm">
                                            <div className="w-full max-w-sm rounded-3xl border border-white/10 bg-white p-8 text-center shadow-2xl">
                                                <div className="mx-auto flex size-16 items-center justify-center rounded-2xl bg-[#640000] text-white shadow-lg shadow-[#640000]/20">
                                                    <LoaderCircle
                                                        className="size-8 animate-spin"
                                                        aria-hidden="true"
                                                    />
                                                </div>
                                                <p className="mt-6 text-xs font-bold tracking-[0.2em] text-[#640000] uppercase">
                                                    Atlantia Systems
                                                </p>
                                                <h3 className="mt-2 text-2xl font-bold text-slate-950">
                                                    Preparando tu pago seguro
                                                </h3>
                                                <p className="mt-3 text-sm leading-6 text-slate-600">
                                                    Estamos creando una sesión
                                                    cifrada con Recurrente. No
                                                    cierres ni recargues esta
                                                    ventana.
                                                </p>
                                                <div className="mt-6 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                                    <div className="h-full w-2/3 animate-pulse rounded-full bg-[#640000]" />
                                                </div>
                                                <div className="mt-5 flex items-center justify-center gap-2 text-xs font-medium text-slate-500">
                                                    <LockKeyhole className="size-3.5 text-[#640000]" />
                                                    Serás redirigido al portal
                                                    protegido de Recurrente
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    <button
                                        type="submit"
                                        disabled={
                                            !paymentProviderReady || processing
                                        }
                                        className="mt-7 flex w-full items-center justify-center gap-2 rounded-xl bg-[#640000] px-5 py-3.5 font-bold text-white hover:bg-[#420001] disabled:cursor-not-allowed disabled:bg-slate-300"
                                    >
                                        <CreditCard className="size-5" />{' '}
                                        {processing
                                            ? 'Conectando…'
                                            : 'Continuar al pago seguro'}
                                    </button>
                                    {errors.payment && (
                                        <p className="mt-3 text-center text-sm text-red-700">
                                            {errors.payment}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                        {!paymentProviderReady && (
                            <p className="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-center text-xs leading-5 text-amber-800">
                                El resumen está listo. El botón se habilitará al
                                conectar el proveedor de pagos.
                            </p>
                        )}
                        <p className="mt-6 text-center text-xs leading-5 text-slate-500">
                            La cuenta no se habilita desde el navegador.
                            Atlantia espera la confirmación firmada del
                            proveedor de pagos.
                        </p>
                        <Link
                            href="/"
                            className="mt-4 block text-center text-sm font-semibold text-[#640000] underline"
                        >
                            Volver a la página principal
                        </Link>
                    </section>
                </div>
            </main>
        </>
    );
}
