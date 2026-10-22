import { Form, Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasswordRequirements from '@/components/password-requirements';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { meetsPasswordRules } from '@/lib/password-rules';
import { home, login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
    selectedPlan: {
        code: string;
        name: string;
        monthly_price: number;
        annual_price: number;
        price_prefix?: string;
        features: string[];
    };
    billingPeriod: 'monthly' | 'annual';
};

export default function Register({
    passwordRules,
    selectedPlan,
    billingPeriod,
}: Props) {
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const passwordReady =
        meetsPasswordRules(password) && password === confirmation;
    const selectedPrice =
        billingPeriod === 'monthly'
            ? selectedPlan.monthly_price
            : selectedPlan.annual_price;

    return (
        <>
            <Head title="Crear cuenta" />

            <div className="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[10px] font-extrabold tracking-widest text-emerald-700 uppercase">
                            Plan seleccionado
                        </p>
                        <p className="truncate text-base font-bold text-slate-950">
                            {selectedPlan.name}{' '}
                            <a
                                href={`${home.url()}#planes`}
                                className="ml-1 text-xs font-bold text-emerald-800 underline underline-offset-4"
                            >
                                Cambiar plan
                            </a>
                        </p>
                    </div>
                    <p className="shrink-0 text-right text-lg font-black text-emerald-800">
                        {selectedPlan.price_prefix &&
                            `${selectedPlan.price_prefix} `}
                        Q{selectedPrice.toLocaleString('es-GT')}
                        <span className="text-xs font-medium text-slate-500">
                            /{billingPeriod === 'monthly' ? 'mes' : 'año'}
                        </span>
                    </p>
                </div>
                <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5">
                    {selectedPlan.features.slice(0, 4).map((feature) => (
                        <span
                            key={feature}
                            className="flex items-center gap-1 text-[11px] leading-4 text-slate-600"
                        >
                            <Check className="size-3 shrink-0 text-emerald-700" />
                            {feature}
                        </span>
                    ))}
                </div>
            </div>

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="plan"
                            value={selectedPlan.code}
                        />
                        <input
                            type="hidden"
                            name="billing_period"
                            value={billingPeriod}
                        />

                        <div className="grid gap-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="name">
                                        Nombre completo
                                    </Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="name"
                                        name="name"
                                        placeholder="Tu nombre"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-1">
                                    <Label htmlFor="organization_name">
                                        Despacho o firma
                                    </Label>
                                    <Input
                                        id="organization_name"
                                        type="text"
                                        required
                                        tabIndex={2}
                                        autoComplete="organization"
                                        name="organization_name"
                                        placeholder="Tu despacho"
                                    />
                                    <InputError
                                        message={errors.organization_name}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-1">
                                <Label htmlFor="email">
                                    Correo electrónico
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={3}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="nombre@oficina.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="password">Contraseña</Label>
                                    <PasswordInput
                                        id="password"
                                        required
                                        tabIndex={4}
                                        autoComplete="new-password"
                                        name="password"
                                        placeholder="Contraseña"
                                        passwordrules={passwordRules}
                                        onChange={(event) =>
                                            setPassword(event.target.value)
                                        }
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-1">
                                    <Label htmlFor="password_confirmation">
                                        Confirmar contraseña
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        required
                                        tabIndex={5}
                                        autoComplete="new-password"
                                        name="password_confirmation"
                                        placeholder="Repítela"
                                        passwordrules={passwordRules}
                                        onChange={(event) =>
                                            setConfirmation(event.target.value)
                                        }
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            <PasswordRequirements
                                password={password}
                                confirmation={confirmation}
                                className="-mt-1"
                            />

                            <div>
                                <div className="flex items-start gap-2.5">
                                    <Checkbox
                                        id="terms"
                                        name="terms"
                                        required
                                        tabIndex={6}
                                    />
                                    <Label
                                        htmlFor="terms"
                                        className="text-xs leading-5 font-normal text-slate-600"
                                    >
                                        Acepto los{' '}
                                        <a
                                            href="/terminos"
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-semibold text-[#640000] underline"
                                        >
                                            Términos y Condiciones
                                        </a>{' '}
                                        de Atlantia Finance, que incluyen la
                                        Política de Privacidad y el Tratamiento
                                        de datos personales.
                                    </Label>
                                </div>
                                <InputError
                                    message={errors.terms}
                                    className="mt-1"
                                />
                            </div>

                            <Button
                                type="submit"
                                className="w-full bg-emerald-700 font-bold text-white hover:bg-emerald-800"
                                tabIndex={7}
                                disabled={!passwordReady}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Crear cuenta y continuar
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            ¿Ya tienes una cuenta?{' '}
                            <TextLink
                                href={login()}
                                tabIndex={8}
                                className="font-semibold text-[#640000]"
                            >
                                Iniciar sesión
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Crear tu cuenta',
    description: 'Confirma tu plan y crea la cuenta del propietario.',
};
