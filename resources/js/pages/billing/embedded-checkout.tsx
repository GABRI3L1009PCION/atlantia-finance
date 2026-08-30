import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    LoaderCircle,
    LockKeyhole,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import RecurrenteCheckout from 'recurrente-checkout';
import { status as checkoutStatus } from '@/routes/billing/checkout';

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
    planChanged?: boolean;
};

type CheckoutResponse = { checkoutUrl?: string; message?: string };
type EmbedStatus =
    | 'idle'
    | 'creating'
    | 'loading'
    | 'ready'
    | 'failed'
    | 'processing'
    | 'paid';

const currency = new Intl.NumberFormat('es-GT', {
    style: 'currency',
    currency: 'GTQ',
    minimumFractionDigits: 2,
});

export default function EmbeddedCheckout({
    organization,
    subscription,
    plan,
    paymentProviderReady,
    paymentStatus,
    planChanged = false,
}: Props) {
    const [status, setStatus] = useState<EmbedStatus>(
        paymentProviderReady ? 'creating' : 'idle',
    );
    const [error, setError] = useState<string | null>(null);
    const initialized = useRef(false);
    const [confirmationTimedOut, setConfirmationTimedOut] = useState(false);
    const [confirmationRound, setConfirmationRound] = useState(0);
    const confirmationKey = `atlantia:payment-confirmation:${subscription.publicId}`;

    useEffect(() => {
        if (status !== 'paid' && status !== 'processing') {
            return;
        }

        let stopped = false;
        let nextCheck: ReturnType<typeof setTimeout>;
        let controller: AbortController | undefined;
        const deadline = window.setTimeout(() => {
            stopped = true;
            controller?.abort();
            clearTimeout(nextCheck);
            setConfirmationTimedOut(true);
        }, 30000);

        const check = async () => {
            controller = new AbortController();
            const requestTimeout = window.setTimeout(
                () => controller?.abort(),
                5000,
            );

            try {
                const response = await fetch(checkoutStatus.url(), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error('Confirmation unavailable');
                }

                const result = await response.json();

                if (!stopped && result.active === true && result.redirectUrl) {
                    stopped = true;
                    clearTimeout(deadline);
                    sessionStorage.removeItem(confirmationKey);
                    router.visit(result.redirectUrl);
                }
            } catch {
                // A temporary network failure does not mean that the payment failed.
            } finally {
                clearTimeout(requestTimeout);

                if (!stopped) {
                    nextCheck = setTimeout(check, 2000);
                }
            }
        };
        void check();

        return () => {
            stopped = true;
            clearTimeout(deadline);
            clearTimeout(nextCheck);
            controller?.abort();
        };
    }, [status, confirmationRound, confirmationKey]);

    const initializeCheckout = useCallback(async () => {
        if (
            paymentStatus === 'processing' ||
            sessionStorage.getItem(confirmationKey)
        ) {
            setStatus('paid');

            return;
        }

        if (!paymentProviderReady || initialized.current) {
            return;
        }

        initialized.current = true;
        setStatus('creating');
        setError(null);

        const csrfToken = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content;

        try {
            const response = await fetch('/billing/checkout', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken ?? '',
                },
                body: '{}',
            });
            const data = (await response.json()) as CheckoutResponse;

            if (!response.ok || !data.checkoutUrl) {
                throw new Error(
                    data.message ??
                        'No pudimos preparar el formulario de pago.',
                );
            }

            setStatus('loading');
            RecurrenteCheckout.load({
                url: data.checkoutUrl,
                onSuccess: () => {
                    sessionStorage.setItem(confirmationKey, 'pending');
                    setStatus('paid');
                },
                onFailure: () => {
                    setStatus('failed');
                    setError(
                        'El pago no pudo completarse. Revisa los datos de la tarjeta e inténtalo nuevamente.',
                    );
                },
                onPaymentInProgress: () => {
                    sessionStorage.setItem(confirmationKey, 'pending');
                    setStatus('processing');
                },
            });

            const iframe = document.querySelector<HTMLIFrameElement>(
                '#recurrente-checkout-iframe',
            );

            if (!iframe) {
                throw new Error('No fue posible mostrar el formulario seguro.');
            }

            iframe.title = 'Pago seguro con Recurrente';
            iframe.addEventListener(
                'load',
                () =>
                    setStatus((current) =>
                        current === 'loading' ? 'ready' : current,
                    ),
                {
                    once: true,
                },
            );
        } catch (exception) {
            initialized.current = false;
            setStatus('failed');
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'No pudimos conectar con la pasarela de pagos.',
            );
        }
    }, [paymentProviderReady, paymentStatus, confirmationKey]);

    useEffect(() => {
        const initialization = window.setTimeout(() => {
            void initializeCheckout();
        }, 0);

        return () => window.clearTimeout(initialization);
    }, [initializeCheckout]);

    const retry = () => window.location.reload();

    const isPreparing = status === 'creating' || status === 'loading';

    return (
        <>
            <Head title="Pago seguro">
                <link
                    rel="preconnect"
                    href="https://app.recurrente.com"
                    crossOrigin="anonymous"
                />
                <link rel="dns-prefetch" href="https://app.recurrente.com" />
            </Head>
            <main className="min-h-screen bg-[#f8f6f5] px-4 py-6 text-slate-950 lg:px-6">
                <div className="mx-auto max-w-7xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
                    <header className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-[#640000] px-6 py-5 text-white lg:px-10">
                        <div>
                            <p className="text-xs font-bold tracking-[0.24em] text-rose-100 uppercase">
                                Atlantia Systems
                            </p>
                            <h1 className="mt-1 text-xl font-bold">
                                Finaliza tu suscripción
                            </h1>
                        </div>
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-2 text-xs font-semibold">
                            <LockKeyhole className="size-4" /> Pago protegido
                            por Recurrente
                        </span>
                    </header>

                    <div className="grid lg:grid-cols-[.72fr_1.28fr]">
                        <aside className="border-b border-slate-200 bg-slate-50 p-6 lg:border-r lg:border-b-0 lg:p-10">
                            <p className="text-xs font-bold tracking-wider text-[#640000] uppercase">
                                Resumen de compra
                            </p>
                            <h2 className="mt-3 text-3xl font-bold">
                                {plan.name}
                            </h2>
                            <p className="mt-2 text-sm text-slate-600">
                                {organization.name}
                            </p>

                            <div className="mt-8 rounded-2xl border border-rose-200 bg-white p-5">
                                <div className="flex items-start justify-between gap-5">
                                    <div>
                                        <p className="font-semibold">
                                            Atlantia Finance
                                        </p>
                                        <p className="mt-1 text-sm text-slate-500">
                                            Facturación{' '}
                                            {plan.billingPeriod === 'annual'
                                                ? 'anual'
                                                : 'mensual'}
                                        </p>
                                    </div>
                                    <strong className="text-xl text-[#640000]">
                                        {currency.format(Number(plan.amount))}
                                    </strong>
                                </div>
                                <div className="mt-5 flex items-center justify-between border-t border-slate-200 pt-5">
                                    <span className="font-medium">Total</span>
                                    <strong className="text-2xl text-[#640000]">
                                        {currency.format(Number(plan.amount))}
                                    </strong>
                                </div>
                            </div>

                            <div className="mt-7 space-y-4 text-sm text-slate-700">
                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="size-5 text-[#640000]" />
                                    Correo verificado
                                </div>
                                <div className="flex items-center gap-3">
                                    <ShieldCheck className="size-5 text-[#640000]" />
                                    Precio validado por Atlantia
                                </div>
                                <div className="flex items-center gap-3">
                                    <LockKeyhole className="size-5 text-[#640000]" />
                                    Datos de tarjeta aislados
                                </div>
                            </div>

                            <p className="mt-8 text-xs leading-5 text-slate-500">
                                Atlantia nunca recibe ni almacena el número de
                                tu tarjeta. La cuenta se activa únicamente al
                                confirmar el webhook firmado.
                            </p>
                            <Link
                                href="/billing/plans"
                                className="mt-5 inline-flex items-center rounded-xl border border-[#640000]/20 bg-white px-4 py-2.5 text-sm font-semibold text-[#640000] hover:bg-rose-50"
                            >
                                Cambiar plan
                            </Link>
                        </aside>

                        <section className="relative min-h-[760px] bg-white p-3 sm:p-6 lg:p-8">
                            {planChanged && (
                                <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-center text-sm font-medium text-emerald-800">
                                    Plan actualizado. Revisa el nuevo resumen
                                    antes de continuar.
                                </p>
                            )}
                            {paymentStatus === 'processing' && (
                                <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-center text-sm text-emerald-800">
                                    Estamos confirmando tu pago de forma segura.
                                </p>
                            )}
                            {paymentStatus === 'cancelled' && (
                                <p className="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-center text-sm text-amber-800">
                                    El checkout fue cancelado. No se realizó
                                    ningún cobro.
                                </p>
                            )}

                            {!paymentProviderReady && (
                                <div className="mx-auto mt-20 max-w-md rounded-2xl bg-amber-50 p-6 text-center text-sm text-amber-900">
                                    El servicio de pagos está temporalmente no
                                    disponible. No se iniciará un nuevo cobro
                                    hasta completar su configuración.
                                </div>
                            )}

                            {isPreparing && (
                                <div className="absolute inset-x-3 top-3 z-10 flex min-h-[680px] items-center justify-center rounded-2xl bg-white sm:inset-x-6 sm:top-6 lg:inset-x-8 lg:top-8">
                                    <div className="max-w-sm px-6 text-center">
                                        <div className="mx-auto flex size-16 items-center justify-center rounded-2xl bg-[#640000] text-white shadow-lg shadow-[#640000]/20">
                                            <LoaderCircle className="size-8 animate-spin" />
                                        </div>
                                        <h3 className="mt-5 text-xl font-bold">
                                            {status === 'creating'
                                                ? 'Preparando tu pago'
                                                : 'Cargando formulario seguro'}
                                        </h3>
                                        <p className="mt-2 text-sm leading-6 text-slate-600">
                                            Esto normalmente toma solo unos
                                            segundos.
                                        </p>
                                        <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div className="h-full w-2/3 animate-pulse rounded-full bg-[#640000]" />
                                        </div>
                                    </div>
                                </div>
                            )}

                            {status === 'failed' && (
                                <div className="mx-auto mt-20 max-w-md rounded-2xl border border-red-200 bg-red-50 p-6 text-center">
                                    <AlertCircle className="mx-auto size-9 text-red-700" />
                                    <h3 className="mt-3 text-lg font-bold">
                                        No pudimos cargar el pago
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-red-800">
                                        {error}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={retry}
                                        className="mt-5 inline-flex items-center gap-2 rounded-xl bg-[#640000] px-5 py-3 font-bold text-white hover:bg-[#420001]"
                                    >
                                        <RefreshCw className="size-4" />{' '}
                                        Reintentar
                                    </button>
                                    <Link
                                        href="/billing/plans"
                                        className="mt-3 inline-flex px-5 py-3 text-sm font-semibold text-[#640000] underline"
                                    >
                                        Elegir otro plan
                                    </Link>
                                </div>
                            )}

                            {(status === 'processing' || status === 'paid') && (
                                <div className="absolute inset-0 z-20 flex items-center justify-center bg-white/95 p-6 text-center">
                                    <div>
                                        <CheckCircle2 className="mx-auto size-14 text-emerald-600" />
                                        <h3 className="mt-4 text-2xl font-bold">
                                            {status === 'paid'
                                                ? 'Confirmación del pago'
                                                : 'Pago en proceso'}
                                        </h3>
                                        <p className="mt-2 text-sm text-slate-600">
                                            {confirmationTimedOut
                                                ? 'La confirmación está pendiente. No vuelvas a pagar. Puedes salir de esta pantalla y regresar más tarde.'
                                                : 'Comprobando la confirmación segura. Entrarás automáticamente cuando el servidor valide tu suscripción.'}
                                        </p>
                                        {confirmationTimedOut && (
                                            <div className="mt-6 flex flex-wrap justify-center gap-4">
                                                <button
                                                    type="button"
                                                    className="rounded-xl bg-[#640000] px-5 py-3 font-semibold text-white"
                                                    onClick={() => {
                                                        setConfirmationTimedOut(
                                                            false,
                                                        );
                                                        setConfirmationRound(
                                                            (round) =>
                                                                round + 1,
                                                        );
                                                    }}
                                                >
                                                    Comprobar estado sin volver
                                                    a pagar
                                                </button>
                                                <Link
                                                    href="/"
                                                    className="px-4 py-3 text-[#640000] underline"
                                                >
                                                    Volver al inicio
                                                </Link>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div
                                id="recurrente-checkout-container"
                                className={
                                    status === 'failed' || !paymentProviderReady
                                        ? 'hidden'
                                        : 'min-h-[700px] overflow-hidden rounded-2xl'
                                }
                            />
                        </section>
                    </div>
                </div>
            </main>
        </>
    );
}
