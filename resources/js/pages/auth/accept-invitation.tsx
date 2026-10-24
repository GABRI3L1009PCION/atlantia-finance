import { Form, Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasswordRequirements from '@/components/password-requirements';
import { meetsPasswordRules } from '@/lib/password-rules';
import { login } from '@/routes';

export default function AcceptInvitation({
    organizationName,
    email,
    existingAccount,
    signedIn,
    acceptUrl,
    asOwner,
}: {
    organizationName: string;
    email: string;
    existingAccount: boolean;
    signedIn: boolean;
    acceptUrl: string;
    asOwner: boolean;
}) {
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const ready =
        existingAccount ||
        (meetsPasswordRules(password) && password === confirmation);
    const field =
        'h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-900 focus:outline-[#640000]';
    const input = `mt-2 ${field}`;

    return (
        <>
            <Head title="Únete a tu equipo" />
            <div className="text-slate-900">
                <Users className="mb-4 size-8 text-[#640000]" />
                <h1 className="text-3xl font-semibold">
                    {asOwner ? 'Tu firma está lista' : 'Tu equipo te espera'}
                </h1>
                <p className="mt-3 text-sm leading-6 text-slate-600">
                    {asOwner ? (
                        <>
                            Activa tu cuenta como propietario de{' '}
                            <strong>{organizationName}</strong>. Desde ahí
                            administras tu oficina e invitas a tu equipo.
                        </>
                    ) : (
                        <>
                            Te invitaron a <strong>{organizationName}</strong>{' '}
                            como colaborador.
                        </>
                    )}
                </p>
                <p className="mt-2 text-sm font-semibold break-all text-[#640000]">
                    {email}
                </p>
                {existingAccount && !signedIn ? (
                    <div className="mt-6">
                        <p className="mb-4 text-sm text-slate-600">
                            Inicia sesión con este correo para aceptar la
                            invitación. No necesitas comprar otro plan.
                        </p>
                        <Link
                            href={login()}
                            className="block rounded-xl bg-[#640000] p-3 text-center font-semibold text-white"
                        >
                            Iniciar sesión
                        </Link>
                    </div>
                ) : (
                    <Form
                        action={acceptUrl}
                        method="post"
                        className="mt-6 space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                {!existingAccount && (
                                    <>
                                        <label className="block text-sm font-semibold">
                                            Nombre completo
                                            <input
                                                name="name"
                                                required
                                                maxLength={255}
                                                autoComplete="name"
                                                className={input}
                                            />
                                            <InputError message={errors.name} />
                                        </label>
                                        <label className="block text-sm font-semibold">
                                            Contraseña
                                            <div className="mt-2">
                                                <PasswordInput
                                                    name="password"
                                                    required
                                                    autoComplete="new-password"
                                                    className={field}
                                                    onChange={(event) =>
                                                        setPassword(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <InputError
                                                message={errors.password}
                                            />
                                        </label>
                                        <label className="block text-sm font-semibold">
                                            Confirmar contraseña
                                            <div className="mt-2">
                                                <PasswordInput
                                                    name="password_confirmation"
                                                    required
                                                    autoComplete="new-password"
                                                    className={field}
                                                    onChange={(event) =>
                                                        setConfirmation(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </label>
                                        <PasswordRequirements
                                            password={password}
                                            confirmation={confirmation}
                                        />
                                        <label className="flex gap-3 text-sm">
                                            <input
                                                type="checkbox"
                                                name="terms"
                                                value="1"
                                                required
                                            />
                                            <span>
                                                Acepto los{' '}
                                                <a
                                                    href="/terminos"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="font-semibold underline"
                                                >
                                                    Términos y Condiciones
                                                </a>{' '}
                                                de Atlantia Finance, que
                                                incluyen la Política de
                                                Privacidad y el Tratamiento de
                                                datos personales.
                                            </span>
                                        </label>
                                        <InputError message={errors.terms} />
                                    </>
                                )}
                                <InputError message={errors.invitation} />
                                <button
                                    disabled={processing || !ready}
                                    className="w-full rounded-xl bg-[#640000] px-5 py-3 font-semibold text-white disabled:opacity-50"
                                >
                                    {processing
                                        ? 'Confirmando…'
                                        : 'Aceptar invitación'}
                                </button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
