import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MailCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = {
    status?: string;
    email?: string;
    resendIn?: number;
    codeMinutes?: number;
    selectedPlan?: {
        name: string;
        billingPeriod: 'monthly' | 'annual';
    } | null;
};

export default function VerifyEmail({
    status,
    email,
    resendIn = 0,
    codeMinutes = 15,
    selectedPlan,
}: Props) {
    const [code, setCode] = useState('');
    const [wait, setWait] = useState(resendIn);
    const [seen, setSeen] = useState(resendIn);

    // A new page load (after asking for another code) brings a fresh wait.
    if (seen !== resendIn) {
        setSeen(resendIn);
        setWait(resendIn);
    }

    useEffect(() => {
        if (wait <= 0) {
            return;
        }

        const timer = window.setTimeout(
            () => setWait((left) => left - 1),
            1000,
        );

        return () => window.clearTimeout(timer);
    }, [wait]);

    return (
        <>
            <Head title="Verifica tu correo" />

            <div className="mb-4 flex flex-col items-center text-center">
                <span className="grid size-12 place-items-center rounded-2xl bg-emerald-100 text-emerald-800">
                    <MailCheck className="size-6" />
                </span>
                <h2 className="mt-3 text-xl font-bold text-slate-950">
                    Escribe el código que te enviamos
                </h2>
                <p className="mt-1.5 text-sm leading-6 text-slate-600">
                    Enviamos un código de 6 dígitos a
                    {email ? (
                        <strong className="mt-0.5 block text-slate-950">
                            {email}
                        </strong>
                    ) : (
                        ' tu correo electrónico.'
                    )}
                </p>
            </div>

            {selectedPlan && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5">
                    <CheckCircle2 className="size-5 shrink-0 text-emerald-700" />
                    <div>
                        <p className="text-[10px] font-extrabold tracking-wider text-emerald-700 uppercase">
                            Selección conservada
                        </p>
                        <p className="text-sm font-bold text-slate-950">
                            Plan {selectedPlan.name} ·{' '}
                            {selectedPlan.billingPeriod === 'annual'
                                ? 'Anual'
                                : 'Mensual'}
                        </p>
                    </div>
                </div>
            )}

            {status === 'verification-link-sent' && (
                <div className="mb-4 rounded-lg bg-green-50 px-4 py-2.5 text-center text-sm font-medium text-green-700">
                    Si tu correo es válido, te enviamos un código nuevo.
                </div>
            )}

            <Form
                action="/email/verify-code"
                method="post"
                className="space-y-3 text-center"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1.5 text-left">
                            <Label htmlFor="code">Código de verificación</Label>
                            <Input
                                id="code"
                                name="code"
                                value={code}
                                onChange={(event) =>
                                    setCode(
                                        event.target.value
                                            .replace(/\D/g, '')
                                            .slice(0, 6),
                                    )
                                }
                                inputMode="numeric"
                                pattern="[0-9]{6}"
                                maxLength={6}
                                autoComplete="one-time-code"
                                autoFocus
                                required
                                placeholder="••••••"
                                className="h-14 text-center font-mono text-3xl tracking-[0.5em]"
                            />
                            <InputError message={errors.code} />
                        </div>

                        <Button
                            type="submit"
                            disabled={processing || code.length !== 6}
                            className="w-full bg-emerald-700 font-bold text-white hover:bg-emerald-800"
                        >
                            {processing && <Spinner />}
                            Verificar y continuar
                        </Button>
                    </>
                )}
            </Form>

            <Form {...send.form()} className="mt-3 space-y-3 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing || wait > 0}
                            className="w-full border-slate-300 bg-white font-semibold text-[#640000] hover:bg-slate-50 hover:text-[#640000] dark:border-slate-300 dark:bg-white dark:hover:bg-slate-50"
                        >
                            {processing && <Spinner />}
                            {wait > 0
                                ? `Reenviar código en ${wait} s`
                                : 'Reenviar código'}
                        </Button>

                        <p className="text-xs leading-5 text-slate-500">
                            El código vale {codeMinutes} minutos. Puede tardar
                            un momento; revisa también la carpeta de correo no
                            deseado.
                        </p>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm font-semibold text-[#640000]"
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
        'Confirma tu dirección con el código antes de configurar la organización y el pago.',
};
