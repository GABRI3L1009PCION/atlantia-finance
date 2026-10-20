import { Form, Head } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const sent = status === 'password-help-sent';

    return (
        <>
            <Head title="Recuperar contraseña" />

            {sent ? (
                <div className="space-y-4">
                    <div className="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                        <MailCheck className="mt-0.5 size-5 shrink-0" />
                        <div>
                            <p className="font-bold">Solicitud enviada</p>
                            <p className="mt-1 leading-5">
                                Si los datos corresponden a una cuenta, el
                                administrador de tu oficina recibirá tu
                                solicitud. Si la aprueba, te llegará una
                                contraseña temporal a tu correo y deberás crear
                                una nueva al ingresar.
                            </p>
                        </div>
                    </div>
                    <TextLink
                        href={login()}
                        className="block text-center text-sm font-semibold text-emerald-700"
                    >
                        Volver a iniciar sesión
                    </TextLink>
                </div>
            ) : (
                <>
                    <Form {...email.form()} className="grid gap-3.5">
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">
                                        Nombre completo o nombre de la empresa
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        minLength={3}
                                        maxLength={190}
                                        autoFocus
                                        autoComplete="name"
                                        placeholder="Ej. Lucía Morales o Comercial Maya"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="email">
                                        Correo electrónico de tu cuenta
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoComplete="email"
                                        placeholder="nombre@oficina.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="reason">
                                        Motivo del cambio de contraseña
                                    </Label>
                                    <textarea
                                        id="reason"
                                        name="reason"
                                        required
                                        minLength={10}
                                        maxLength={1000}
                                        rows={3}
                                        placeholder="Ej. Olvidé mi contraseña / cambié de teléfono y no puedo ingresar."
                                        className="w-full rounded-md border border-input bg-white px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    />
                                    <InputError message={errors.reason} />
                                </div>

                                <p className="text-xs leading-5 text-slate-500">
                                    Tu solicitud llega al administrador de tu
                                    oficina contable, que decide si la aprueba.
                                    Si lo hace, recibirás una contraseña
                                    temporal en tu correo.
                                </p>

                                <Button
                                    className="w-full bg-emerald-700 font-bold tracking-wide text-white hover:bg-emerald-800"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && <Spinner />}
                                    Enviar solicitud
                                </Button>
                            </>
                        )}
                    </Form>

                    <p className="mt-4 text-center text-sm text-slate-500">
                        ¿Ya la recordaste?{' '}
                        <TextLink
                            href={login()}
                            className="font-semibold text-emerald-700"
                        >
                            Volver a iniciar sesión
                        </TextLink>
                    </p>
                </>
            )}
        </>
    );
}

ForgotPassword.layout = {
    title: 'Recupera tu acceso',
    description:
        'Cuéntanos quién eres y tu oficina revisará tu solicitud de cambio de contraseña.',
};
