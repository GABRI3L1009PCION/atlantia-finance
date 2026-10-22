import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ShieldCheck } from 'lucide-react';

type Price = {
    billingPeriod: 'monthly' | 'annual';
    amount: string;
    currencyCode: string;
    selected: boolean;
};

type Plan = {
    code: string;
    name: string;
    description: string;
    payrollNote: string | null;
    prices: Price[];
};

type Props = {
    organization: { name: string };
    plans: Plan[];
    mode?: 'change' | 'reactivate';
    testEnvironment: boolean;
    errors?: { plan?: string };
};

const money = new Intl.NumberFormat('es-GT', {
    style: 'currency',
    currency: 'GTQ',
    minimumFractionDigits: 2,
});

export default function BillingPlans({
    organization,
    plans,
    mode = 'change',
    testEnvironment,
    errors = {},
}: Props) {
    const reactivate = mode === 'reactivate';

    return (
        <>
            <Head title={reactivate ? 'Reactivar cuenta' : 'Cambiar plan'} />
            <main className="min-h-screen bg-[#f8f6f5] px-4 py-8 text-slate-950 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    {!reactivate && (
                        <Link
                            href="/billing/checkout"
                            className="inline-flex items-center gap-2 text-sm font-semibold text-[#640000]"
                        >
                            <ArrowLeft className="size-4" /> Volver al pago
                        </Link>
                    )}

                    <header className="mt-5 rounded-3xl bg-[#640000] px-7 py-8 text-white shadow-xl lg:px-10">
                        <p className="text-xs font-bold tracking-[0.22em] text-rose-100 uppercase">
                            Atlantia Systems
                        </p>
                        <h1 className="mt-2 text-3xl font-bold">
                            {reactivate
                                ? 'Reactiva tu cuenta'
                                : 'Elige el plan correcto'}
                        </h1>
                        <p className="mt-2 text-rose-100">
                            {organization.name}
                        </p>
                        {reactivate && (
                            <p className="mt-2 text-sm text-rose-100">
                                Tu suscripción terminó, pero todos tus datos se
                                conservan. Elige un plan y paga para volver a
                                trabajar.
                            </p>
                        )}
                    </header>

                    {testEnvironment && (
                        <div className="mt-6 flex gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                            <ShieldCheck className="mt-0.5 size-5 shrink-0" />
                            <p>
                                Estás usando Recurrente TEST. Mientras la cuenta
                                no esté verificada, usa{' '}
                                <strong>Esencial mensual (Q399)</strong> para
                                probar el checkout; los importes mayores de Q500
                                pueden ser rechazados por el proveedor.
                            </p>
                        </div>
                    )}

                    {errors.plan && (
                        <div className="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                            {errors.plan}
                        </div>
                    )}

                    <section className="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {plans.map((plan) => (
                            <article
                                key={plan.code}
                                className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                            >
                                <h2 className="text-xl font-bold">
                                    {plan.name}
                                </h2>
                                <p className="mt-2 min-h-12 text-sm leading-6 text-slate-600">
                                    {plan.description}
                                </p>
                                {plan.payrollNote && (
                                    <p className="mt-2 text-xs font-medium text-amber-800">
                                        {plan.payrollNote}
                                    </p>
                                )}

                                <div className="mt-5 space-y-3">
                                    {plan.prices.map((price) => (
                                        <Form
                                            key={price.billingPeriod}
                                            action={
                                                reactivate
                                                    ? '/billing/reactivate'
                                                    : '/billing/plan'
                                            }
                                            method={
                                                reactivate ? 'post' : 'patch'
                                            }
                                            className="rounded-xl border border-slate-200 p-4"
                                        >
                                            <input
                                                type="hidden"
                                                name="plan"
                                                value={plan.code}
                                            />
                                            <input
                                                type="hidden"
                                                name="billing_period"
                                                value={price.billingPeriod}
                                            />
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-medium text-slate-600">
                                                        {price.billingPeriod ===
                                                        'annual'
                                                            ? 'Anual'
                                                            : 'Mensual'}
                                                    </p>
                                                    <p className="mt-1 text-xl font-bold text-[#640000]">
                                                        {money.format(
                                                            Number(
                                                                price.amount,
                                                            ),
                                                        )}
                                                    </p>
                                                </div>
                                                <button
                                                    type="submit"
                                                    disabled={price.selected}
                                                    className="rounded-xl bg-[#640000] px-4 py-2.5 text-sm font-bold text-white disabled:cursor-default disabled:bg-emerald-100 disabled:text-emerald-800"
                                                >
                                                    {price.selected ? (
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <CheckCircle2 className="size-4" />{' '}
                                                            Actual
                                                        </span>
                                                    ) : reactivate ? (
                                                        'Reactivar'
                                                    ) : (
                                                        'Elegir'
                                                    )}
                                                </button>
                                            </div>
                                        </Form>
                                    ))}
                                </div>
                            </article>
                        ))}
                    </section>
                </div>
            </main>
        </>
    );
}
