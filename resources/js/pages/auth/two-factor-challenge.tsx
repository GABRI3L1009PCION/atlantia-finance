import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { KeyRound, Loader2, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { login } from '@/routes';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'Código de recuperación',
                description:
                    'Confirma tu acceso con uno de los códigos de emergencia que guardaste al activar la verificación.',
                toggleText: 'usar el código de mi app de autenticación',
            };
        }

        return {
            title: 'Verificación en dos pasos',
            description:
                'Ingresa el código de 6 dígitos que muestra tu app de autenticación.',
            toggleText: 'usar un código de recuperación',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title="Verificación en dos pasos" />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    id="two-factor-form"
                    className="space-y-5"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <div className="space-y-2">
                                    <div className="relative">
                                        <KeyRound className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                        <Input
                                            name="recovery_code"
                                            type="text"
                                            className="h-12 pl-10 font-mono tracking-wider"
                                            placeholder="Ej.: abcd1234-efgh5678"
                                            autoComplete="one-time-code"
                                            autoFocus={showRecoveryInput}
                                            required
                                        />
                                    </div>
                                    <InputError
                                        message={errors.recovery_code}
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center space-y-3 text-center">
                                    <span className="grid size-14 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                                        <ShieldCheck className="size-7" />
                                    </span>
                                    <InputOTP
                                        name="code"
                                        maxLength={OTP_MAX_LENGTH}
                                        value={code}
                                        onChange={(value) => {
                                            setCode(value);

                                            if (
                                                value.length === OTP_MAX_LENGTH
                                            ) {
                                                setTimeout(
                                                    () =>
                                                        (
                                                            document.getElementById(
                                                                'two-factor-form',
                                                            ) as HTMLFormElement | null
                                                        )?.requestSubmit(),
                                                    0,
                                                );
                                            }
                                        }}
                                        disabled={processing}
                                        pattern={REGEXP_ONLY_DIGITS}
                                        containerClassName="gap-2.5"
                                        autoFocus
                                    >
                                        <InputOTPGroup className="gap-2.5">
                                            {Array.from(
                                                { length: OTP_MAX_LENGTH },
                                                (_, index) => (
                                                    <InputOTPSlot
                                                        key={index}
                                                        index={index}
                                                        className="size-12 rounded-xl border text-xl font-bold first:rounded-xl first:border-l last:rounded-xl data-[active=true]:border-emerald-600"
                                                    />
                                                ),
                                            )}
                                        </InputOTPGroup>
                                    </InputOTP>
                                    <InputError message={errors.code} />
                                    <p className="text-xs text-slate-500">
                                        El código cambia cada 30 segundos. Se
                                        envía solo al completar los 6 dígitos.
                                    </p>
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="h-11 w-full bg-emerald-700 font-bold tracking-wide text-white hover:bg-emerald-800"
                                disabled={
                                    processing ||
                                    (!showRecoveryInput &&
                                        code.length < OTP_MAX_LENGTH)
                                }
                            >
                                {processing ? (
                                    <>
                                        <Loader2 className="size-4 animate-spin" />{' '}
                                        Verificando…
                                    </>
                                ) : (
                                    'Verificar y continuar'
                                )}
                            </Button>

                            <div className="space-y-2 text-center text-sm text-muted-foreground">
                                <p>
                                    ¿No tienes tu app a la mano?{' '}
                                    <button
                                        type="button"
                                        className="cursor-pointer font-semibold text-emerald-700 underline decoration-emerald-200 underline-offset-4 transition-colors hover:decoration-current"
                                        onClick={() =>
                                            toggleRecoveryMode(clearErrors)
                                        }
                                    >
                                        {authConfigContent.toggleText}
                                    </button>
                                </p>
                                <p>
                                    <Link
                                        href={login()}
                                        className="font-medium text-slate-500 underline-offset-4 hover:text-slate-700 hover:underline"
                                    >
                                        Volver al inicio de sesión
                                    </Link>
                                </p>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
