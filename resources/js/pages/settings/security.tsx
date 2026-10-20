import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { SettingsCard } from '@/components/settings-card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
    twoFactorRequired?: boolean;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const isPlatformStaff = usePage().props.isPlatformStaff === true;

    return (
        <>
            <Head title="Seguridad" />

            {isPlatformStaff &&
                (props.twoFactorEnabled ? (
                    <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
                        <span>
                            La autenticación de dos factores está activa. Ya
                            puedes entrar al Control Center.
                        </span>
                        <Button asChild>
                            <Link href="/control-center">
                                Ir al Control Center
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                        <p className="font-semibold">
                            El Control Center exige autenticación de dos
                            factores.
                        </p>
                        <p className="mt-1">
                            Pulsa «Activar 2FA» más abajo, escanea el código QR
                            con tu app (Google Authenticator, Microsoft
                            Authenticator o Authy) y confirma el código de 6
                            dígitos. Después entrarás al Control Center.
                        </p>
                    </div>
                ))}

            {props.twoFactorRequired && !props.twoFactorEnabled && (
                <div className="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-semibold">
                        Activa la verificación en dos pasos para continuar.
                    </p>
                    <p className="mt-1">
                        Baja a «Autenticación de dos factores» y pulsa «Activar
                        2FA». Escanea el código QR con Google Authenticator (o
                        Microsoft Authenticator o Authy) y escribe el código de
                        6 dígitos para confirmar.
                    </p>
                </div>
            )}

            <SettingsCard
                icon={Lock}
                title="Contraseña"
                description="Usa una contraseña larga y aleatoria para mantener tu cuenta segura."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Contraseña actual
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Contraseña actual"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    Nueva contraseña
                                </Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Nueva contraseña"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirmar contraseña
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Confirmar contraseña"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Guardar contraseña
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsCard>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Configuración de seguridad',
            href: edit(),
        },
    ],
};
