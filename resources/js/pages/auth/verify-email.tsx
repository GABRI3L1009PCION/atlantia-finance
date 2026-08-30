// Components
import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = {
    status?: string;
    email?: string;
    selectedPlan?: {
        name: string;
        billingPeriod: 'monthly' | 'annual';
    } | null;
};

export default function VerifyEmail({ status, email, selectedPlan }: Props) {
    return (
        <>
            <Head title="Verifica tu correo" />

            <div className="mb-5 flex flex-col items-center text-center">
                <span className="grid size-14 place-items-center rounded-2xl bg-emerald-100 text-emerald-800">
                    <MailCheck className="size-7" />
                </span>
                <h2 className="mt-4 text-xl font-bold text-slate-950">
                    Revisa tu bandeja de entrada
                </h2>
                <p className="mt-2 text-sm leading-6 text-slate-600">
                    Enviamos un enlace de verificación a
                    {email ? (
                        <strong className="mt-1 block text-slate-950">
                            {email}
                        </strong>
                    ) : (
                        ' tu correo electrónico.'
                    )}
                </p>
            </div>

            {selectedPlan && (
                <div className="mb-5 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <CheckCircle2 className="size-5 shrink-0 text-emerald-700" />
                    <div>
                        <p className="text-[10px] font-extrabold tracking-wider text-emerald-700 uppercase">
                            Selección conservada
                        </p>
                        <p className="mt-0.5 text-sm font-bold text-slate-950">
                            Plan {selectedPlan.name} ·{' '}
                            {selectedPlan.billingPeriod === 'annual'
                                ? 'Anual'
                                : 'Mensual'}
                        </p>
                    </div>
                </div>
            )}

            {status === 'verification-link-sent' && (
                <div className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-center text-sm font-medium text-green-700">
                    Te enviamos un nuevo enlace de verificación.
                </div>
            )}

            <Form {...send.form()} className="space-y-4 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            disabled={processing}
                            className="w-full bg-emerald-700 font-bold text-white hover:bg-emerald-800"
                        >
                            {processing && <Spinner />}
                            Reenviar correo de verificación
                        </Button>

                        <p className="text-xs leading-5 text-slate-500">
                            El enlace puede tardar unos minutos. Revisa también
                            la carpeta de correo no deseado.
                        </p>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Cerrar sesión
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verifica tu correo',
    description:
        'Debes confirmar tu dirección antes de configurar la organización y el pago.',
};
