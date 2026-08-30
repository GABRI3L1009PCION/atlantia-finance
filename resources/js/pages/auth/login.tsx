import { Form, Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const [showVerifiedModal, setShowVerifiedModal] = useState(
        status === 'email-verified',
    );

    return (
        <>
            <Head title="Iniciar sesión" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="email">
                                    Correo electrónico
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="nombre@oficina.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-1.5">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Contraseña</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm font-medium text-emerald-700!"
                                            tabIndex={5}
                                        >
                                            ¿Olvidaste tu contraseña?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Ingresa tu contraseña"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    Recordar este equipo
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-1 w-full bg-emerald-700 font-bold tracking-wide text-white hover:bg-emerald-800"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Ingresar al sistema
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            <div className="relative my-4">
                <div className="absolute inset-0 flex items-center">
                    <Separator className="w-full" />
                </div>
                <div className="relative flex justify-center text-xs">
                    <span className="bg-white px-3 text-slate-500">
                        o continúa con
                    </span>
                </div>
            </div>

            <div className="grid gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled
                    className="relative w-full border-slate-300 bg-white! text-slate-950! opacity-100!"
                    aria-label="Continuar con Google, próximamente"
                >
                    <span className="text-base font-extrabold text-[#4285f4]">
                        G
                    </span>
                    Continuar con Google
                    <span className="absolute right-3 rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-bold tracking-wide text-slate-500 uppercase">
                        Próximamente
                    </span>
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    disabled
                    className="relative w-full border-slate-300 bg-white! text-slate-950! opacity-100!"
                    aria-label="Continuar con Microsoft, próximamente"
                >
                    <span className="grid size-4 grid-cols-2 gap-0.5">
                        <span className="bg-[#f25022]" />
                        <span className="bg-[#7fba00]" />
                        <span className="bg-[#00a4ef]" />
                        <span className="bg-[#ffb900]" />
                    </span>
                    Continuar con Microsoft
                    <span className="absolute right-3 rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-bold tracking-wide text-slate-500 uppercase">
                        Próximamente
                    </span>
                </Button>

                <PasskeyVerify
                    label="Ingresar con passkey"
                    loadingLabel="Verificando..."
                    showSeparator={false}
                />
            </div>

            <div className="mt-4 text-center text-sm text-slate-500">
                ¿Aún no tienes una cuenta?{' '}
                <TextLink
                    href={register()}
                    className="font-semibold text-emerald-700"
                    tabIndex={5}
                >
                    Crear cuenta
                </TextLink>
            </div>

            {status && status !== 'email-verified' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <Dialog
                open={showVerifiedModal}
                onOpenChange={setShowVerifiedModal}
            >
                <DialogContent className="overflow-hidden border-0 p-0 sm:max-w-md">
                    <div className="h-2 bg-[#640000]" />
                    <div className="px-7 pt-4 pb-7 text-center">
                        <div className="mx-auto mb-5 flex size-16 items-center justify-center rounded-full bg-[#f6e4e4] text-[#640000]">
                            <CheckCircle2
                                className="size-9"
                                aria-hidden="true"
                            />
                        </div>
                        <DialogHeader className="text-center sm:text-center">
                            <DialogTitle className="text-2xl text-slate-950">
                                Correo verificado correctamente
                            </DialogTitle>
                            <DialogDescription className="mt-2 text-base leading-6 text-slate-600">
                                Tu dirección fue confirmada. Ahora inicia sesión
                                para continuar con la configuración y el pago
                                seguro de tu suscripción.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="mt-6 sm:justify-center">
                            <DialogClose asChild>
                                <Button className="w-full bg-[#640000] font-bold text-white hover:bg-[#420001]">
                                    Entendido, iniciar sesión
                                </Button>
                            </DialogClose>
                        </DialogFooter>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

Login.layout = {
    title: 'Iniciar sesión',
    description: 'Ingresa tus credenciales para acceder de forma segura.',
};
