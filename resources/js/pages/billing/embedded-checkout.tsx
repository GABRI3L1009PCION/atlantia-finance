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
    invoice?: {
        number: string;
        periodStart: string | null;
        periodEnd: string | null;
    } | null;
    breakdown?: {
        subtotal: number;
        discount: number;
        credit: number;
    } | null;
    paymentProviderReady: boolean;
    paymentStatus?: 'processing' | 'cancelled' | null;
    planChanged?: boolean;
};

type CheckoutResponse = {
    checkoutUrl?: string;
    message?: string;
    activated?: boolean;
    redirectUrl?: string;
    code?: string;
    reference?: string;
};
type EmbedStatus =
    | 'idle'
    | 'creating'
    | 'loading'
    | 'ready'
    | 'failed'
    | 'processing'
    | 'paid';

/** Approximate height of the provider's form at full size and the smallest scale we accept to fit it on short windows. */
const NATURAL_FORM_HEIGHT = 780;
const MIN_FORM_SCALE = 0.8;

const currency = new Intl.NumberFormat('es-GT', {
    style: 'currency',
    currency: 'GTQ',
    minimumFractionDigits: 2,
});

export default function EmbeddedCheckout({
    organization,
    subscription,
    plan,
    invoice = null,
    breakdown = null,
    paymentProviderReady,
    paymentStatus,
    planChanged = false,
}: Props) {
    const [status, setStatus] = useState<EmbedStatus>(
        paymentProviderReady ? 'creating' : 'idle',
    );
    const [error, setError] = useState<string | null>(null);
    const [errorTitle, setErrorTitle] = useState('No pudimos cargar el pago');
    const [reference, setReference] = useState<string | null>(null);
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

            if (response.ok && data.activated && data.redirectUrl) {
                router.visit(data.redirectUrl);

                return;
            }

            if (!response.ok || !data.checkoutUrl) {
                setReference(data.reference ?? null);
                setErrorTitle(
                    data.code === 'provider_unavailable'
                        ? 'El pago en línea no está disponible por ahora'
                        : 'No pudimos cargar el pago',
                );

                // Only messages written for the customer are shown; a technical answer (for example an expired session) is not.
                throw new Error(
                    data.code && data.message
                        ? data.message
                        : response.status === 419
                          ? 'Tu sesión se actualizó. Recarga la página e inténtalo de nuevo.'
                          : 'No pudimos preparar el formulario de pago. Inténtalo de nuevo en unos minutos.',
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
                    setErrorTitle('El pago no pudo completarse');
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

    // The provider's form sets its own fixed height and is taller than most windows: it gets exactly the height left on
    // screen and, when that is short, is scaled down a little so the whole form shows without an inner scroll bar.
    useEffect(() => {
        const container = document.getElementById(
            'recurrente-checkout-container',
        );

        if (!container) {
            return;
        }

        const fit = () => {
            const iframe = container.querySelector('iframe');
            const height = container.clientHeight;

            if (!iframe || height === 0) {
                return;
            }

            const scale = Math.min(
                1,
                Math.max(MIN_FORM_SCALE, height / NATURAL_FORM_HEIGHT),
            );
            const width = `${100 / scale}%`;
            const frameHeight = `${height / scale}px`;
            const transform = scale < 1 ? `scale(${scale})` : '';

            if (
                iframe.style.width !== width ||
                iframe.style.height !== frameHeight ||
                iframe.style.transform !== transform
            ) {
                iframe.style.transformOrigin = 'top left';
                iframe.style.width = width;
                iframe.style.height = frameHeight;
                iframe.style.transform = transform;
            }
        };

        const resize = new ResizeObserver(fit);
        resize.observe(container);
        const mutation = new MutationObserver(fit);
        mutation.observe(container, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['style'],
        });
        fit();

        return () => {
            resize.disconnect();
            mutation.disconnect();
        };
    }, [status]);
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

            <div className="grid min-h-svh bg-[#faf9f8] text-slate-950 lg:h-svh lg:grid-cols-[36%_64%] lg:overflow-hidden">
                <aside className="relative overflow-hidden bg-emerald-700 px-6 py-6 text-white lg:flex lg:h-svh lg:flex-col lg:px-9 lg:py-7">
                    <div className="absolute -top-32 -left-32 size-96 rounded-full border border-white/10" />
                    <div className="absolute -top-16 -left-16 size-96 rounded-full border border-white/10" />

                    <Link
                        href="/"
                        className="relative z-10 w-fit text-sm font-bold tracking-[0.24em] uppercase"
                    >
                        Atlantia Systems
                    </Link>

                    <div className="relative z-10 my-5 lg:my-auto">
                        <img
                            src="/images/atlantia-finance-logo.png"
                            alt="Atlantia Finance"
                            className="mx-auto hidden w-52 drop-shadow-2xl lg:block"
                        />

                        <div className="mt-4 text-center lg:mt-5">
                            <h1 className="text-2xl font-bold tracking-tight">
                                Finaliza tu suscripción
                            </h1>
                            <p className="mt-1 text-sm text-white/70">
                                Un último paso: paga de forma segura para
                                activar tu plan.
                            </p>
                            <span className="mt-3 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold">
                                <LockKeyhole className="size-3.5" /> Pago
                                protegido por Recurrente
                            </span>
                        </div>

                        <div className="mt-4 rounded-2xl border border-white/15 bg-white/10 p-5 backdrop-blur-sm">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <p className="text-[10px] font-extrabold tracking-widest text-white/70 uppercase">
                                        Resumen de compra
                                    </p>
                                    <p className="mt-1 text-lg font-bold">
                                        Plan {plan.name} ·{' '}
                                        {plan.billingPeriod === 'annual'
                                            ? 'Anual'
                                            : 'Mensual'}
                                    </p>
                                    <p className="truncate text-xs text-white/70">
                                        {organization.name}
                                    </p>
                                    {invoice && (
                                        <p className="mt-0.5 text-xs text-white/70">
                                            Factura {invoice.number}
                                            {invoice.periodStart &&
                                                invoice.periodEnd &&
                                                ` · del ${invoice.periodStart} al ${invoice.periodEnd}`}
                                        </p>
                                    )}
                                </div>
                            </div>

                            {breakdown &&
                                (breakdown.discount > 0 ||
                                    breakdown.credit > 0) && (
                                    <dl className="mt-3 space-y-1 border-t border-white/15 pt-3 text-xs text-white/80">
                                        <div className="flex justify-between">
                                            <dt>Precio de lista</dt>
                                            <dd>
                                                {currency.format(
                                                    breakdown.subtotal,
                                                )}
                                            </dd>
                                        </div>
                                        {breakdown.discount > 0 && (
                                            <div className="flex justify-between">
                                                <dt>Descuento</dt>
                                                <dd>
                                                    -
                                                    {currency.format(
                                                        breakdown.discount,
                                                    )}
                                                </dd>
                                            </div>
                                        )}
                                        {breakdown.credit > 0 && (
                                            <div className="flex justify-between">
                                                <dt>Crédito aplicado</dt>
                                                <dd>
                                                    -
                                                    {currency.format(
                                                        breakdown.credit,
                                                    )}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                )}

                            <div className="mt-4 flex items-end justify-between border-t border-white/15 pt-4">
                                <span className="text-sm text-white/80">
                                    Total a pagar
                                </span>
                                <strong className="text-3xl">
                                    {currency.format(Number(plan.amount))}
                                </strong>
                            </div>
                        </div>

                        <ul className="mt-5 space-y-2 text-sm text-white/85">
                            <li className="flex items-center gap-2.5">
                                <CheckCircle2 className="size-4 shrink-0 text-emerald-300" />
                                Correo verificado
                            </li>
                            <li className="flex items-center gap-2.5">
                                <ShieldCheck className="size-4 shrink-0 text-emerald-300" />
                                Precio validado por Atlantia
                            </li>
                            <li className="flex items-center gap-2.5">
                                <LockKeyhole className="size-4 shrink-0 text-emerald-300" />
                                Datos de tarjeta aislados: Atlantia no los
                                recibe
                            </li>
                        </ul>

                        <p className="mt-4 text-xs leading-5 text-white/60">
                            Es un cargo único
                            {invoice
                                ? ': el pago se aplica a esta factura.'
                                : '; cada renovación se paga con su propia factura.'}{' '}
                            Al pagar aceptas los{' '}
                            <a
                                href="/terminos"
                                target="_blank"
                                rel="noreferrer"
                                className="font-semibold text-white underline"
                            >
                                Términos y Condiciones
                            </a>
                            .
                        </p>
                        {!invoice && (
                            <Link
                                href="/billing/plans"
                                className="mt-3 inline-flex text-sm font-semibold text-white underline underline-offset-4"
                            >
                                Cambiar plan
                            </Link>
                        )}
                    </div>
                </aside>

                <main className="flex flex-col px-4 py-4 sm:px-8 lg:h-svh lg:px-8 lg:py-3">
                    {planChanged && (
                        <p className="mt-3 rounded-xl bg-emerald-50 px-4 py-2.5 text-center text-sm font-medium text-emerald-800">
                            Plan actualizado. Revisa el nuevo resumen antes de
                            continuar.
                        </p>
                    )}
                    {paymentStatus === 'processing' && (
                        <p className="mt-3 rounded-xl bg-emerald-50 px-4 py-2.5 text-center text-sm text-emerald-800">
                            Estamos confirmando tu pago de forma segura.
                        </p>
                    )}
                    {paymentStatus === 'cancelled' && (
                        <p className="mt-3 rounded-xl bg-amber-50 px-4 py-2.5 text-center text-sm text-amber-800">
                            El checkout fue cancelado. No se realizó ningún
                            cobro.
                        </p>
                    )}

                    <section className="relative flex min-h-[640px] flex-1 flex-col lg:min-h-0">
                        {!paymentProviderReady && (
                            <div className="mx-auto mt-10 max-w-md rounded-xl bg-amber-50 p-5 text-center text-sm text-amber-900">
                                El servicio de pagos está temporalmente no
                                disponible. No se iniciará un nuevo cobro hasta
                                completar su configuración.
                            </div>
                        )}

                        {isPreparing && (
                            <div className="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white">
                                <div className="max-w-xs px-6 text-center">
                                    <div className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-[#640000] text-white shadow-lg shadow-[#640000]/20">
                                        <LoaderCircle className="size-7 animate-spin" />
                                    </div>
                                    <h3 className="mt-4 text-lg font-bold">
                                        {status === 'creating'
                                            ? 'Preparando tu pago'
                                            : 'Cargando formulario seguro'}
                                    </h3>
                                    <p className="mt-1.5 text-sm leading-6 text-slate-600">
                                        Esto normalmente toma solo unos
                                        segundos.
                                    </p>
                                    <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <div className="h-full w-2/3 animate-pulse rounded-full bg-[#640000]" />
                                    </div>
                                </div>
                            </div>
                        )}

                        {status === 'failed' && (
                            <div className="mx-auto mt-6 w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5">
                                <div className="flex items-start gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-red-50 text-red-700">
                                        <AlertCircle className="size-5" />
                                    </span>
                                    <div>
                                        <h3 className="text-base leading-5 font-bold text-slate-950">
                                            {errorTitle}
                                        </h3>
                                        <p className="mt-1 text-sm leading-5 text-slate-600">
                                            {error}
                                        </p>
                                    </div>
                                </div>

                                <p className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                    <span className="flex items-center gap-1.5">
                                        <CheckCircle2 className="size-3.5 shrink-0 text-emerald-600" />
                                        No se realizó ningún cobro
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <CheckCircle2 className="size-3.5 shrink-0 text-emerald-600" />
                                        Tus datos quedan guardados
                                    </span>
                                </p>

                                <div className="mt-3 flex flex-wrap items-center gap-2.5">
                                    <button
                                        type="button"
                                        onClick={retry}
                                        className="inline-flex items-center gap-2 rounded-xl bg-[#640000] px-4 py-2 text-sm font-bold text-white hover:bg-[#420001]"
                                    >
                                        <RefreshCw className="size-4" />{' '}
                                        Reintentar
                                    </button>
                                    <Link
                                        href="/billing/plans"
                                        className="inline-flex rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-[#640000] hover:bg-slate-50"
                                    >
                                        Elegir otro plan
                                    </Link>
                                </div>

                                {reference && (
                                    <p className="mt-3 text-[11px] leading-4 text-slate-500">
                                        Si continúa, escríbenos con esta
                                        referencia:{' '}
                                        <span className="font-mono break-all text-slate-700 select-all">
                                            {reference}
                                        </span>
                                    </p>
                                )}
                            </div>
                        )}

                        {(status === 'processing' || status === 'paid') && (
                            <div className="mx-auto mt-8 w-full max-w-lg rounded-2xl border border-emerald-200 bg-emerald-50/50 p-6 text-center">
                                <CheckCircle2 className="mx-auto size-12 text-emerald-600" />
                                <h3 className="mt-3 text-xl font-bold">
                                    {status === 'paid'
                                        ? 'Confirmación del pago'
                                        : 'Pago en proceso'}
                                </h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {confirmationTimedOut
                                        ? 'La confirmación está pendiente. No vuelvas a pagar. Puedes salir de esta pantalla y regresar más tarde.'
                                        : 'Comprobando la confirmación segura. Entrarás automáticamente cuando el servidor valide tu suscripción.'}
                                </p>
                                {confirmationTimedOut && (
                                    <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
                                        <button
                                            type="button"
                                            className="rounded-xl bg-[#640000] px-4 py-2.5 text-sm font-semibold text-white"
                                            onClick={() => {
                                                setConfirmationTimedOut(false);
                                                setConfirmationRound(
                                                    (round) => round + 1,
                                                );
                                            }}
                                        >
                                            Comprobar estado sin volver a pagar
                                        </button>
                                        <Link
                                            href="/"
                                            className="px-3 py-2.5 text-sm text-[#640000] underline"
                                        >
                                            Volver al inicio
                                        </Link>
                                    </div>
                                )}
                            </div>
                        )}

                        <div
                            id="recurrente-checkout-container"
                            className={
                                status === 'failed' ||
                                status === 'processing' ||
                                status === 'paid' ||
                                !paymentProviderReady
                                    ? 'hidden'
                                    : 'h-full min-h-[600px] flex-1 overflow-hidden rounded-xl lg:min-h-0'
                            }
                        />
                    </section>
                </main>
            </div>
        </>
    );
}
