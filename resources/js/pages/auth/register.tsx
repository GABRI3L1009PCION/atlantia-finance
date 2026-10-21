import { Form, Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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
    const selectedPrice =
        billingPeriod === 'monthly'
            ? selectedPlan.monthly_price
            : selectedPlan.annual_price;

    return (
        <>
            <Head title="Crear cuenta" />

            <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="text-[10px] font-extrabold tracking-widest text-emerald-700 uppercase">
                            Plan seleccionado
                        </p>
                        <p className="mt-1 text-lg font-bold text-slate-950">
                            {selectedPlan.name}
                        </p>
                    </div>
                    <div className="text-right">
                        <p className="text-lg font-black text-emerald-800">
                            {selectedPlan.price_prefix &&
                                `${selectedPlan.price_prefix} `}
                            Q{selectedPrice.toLocaleString('es-GT')}
                        </p>
                        <p className="text-xs text-slate-500">
                            /{billingPeriod === 'monthly' ? 'mes' : 'año'}
                        </p>
                    </div>
                </div>
                <div className="mt-3 grid gap-1.5 sm:grid-cols-2">
                    {selectedPlan.features.slice(0, 4).map((feature) => (
                        <span
                            key={feature}
                            className="flex items-start gap-1.5 text-[11px] leading-4 text-slate-600"
                        >
                            <Check className="mt-0.5 size-3 shrink-0 text-emerald-700" />
                            {feature}
                        </span>
                    ))}
                </div>
                <a
                    href={`${home.url()}#planes`}
                    className="mt-3 inline-flex text-xs font-bold text-emerald-800 underline underline-offset-4"
                >
                    Cambiar plan
                </a>
            </div>

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-4"
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

                        <div className="grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="name">Nombre completo</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Tu nombre completo"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="organization_name">
                                    Nombre de tu despacho o firma
                                </Label>
                                <Input
                                    id="organization_name"
                                    type="text"
                                    required
                                    tabIndex={2}
                                    autoComplete="organization"
                                    name="organization_name"
                                    placeholder="Ej. Contadores y Consultores de Izabal"
                                />
                                <InputError
                                    message={errors.organization_name}
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="email">
                                    Correo electrónico
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="nombre@oficina.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-1.5 sm:grid-cols-2 sm:gap-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password">Contraseña</Label>
                                    <PasswordInput
                                        id="password"
                                        required
                                        tabIndex={3}
                                        autoComplete="new-password"
                                        name="password"
                                        placeholder="Crea una contraseña"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="password_confirmation">
                                        Confirmar contraseña
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        required
                                        tabIndex={4}
                                        autoComplete="new-password"
                                        name="password_confirmation"
                                        placeholder="Repite la contraseña"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            <div>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="terms"
                                        name="terms"
                                        required
                                        tabIndex={5}
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
                                            className="font-semibold underline"
                                        >
                                            Términos y Condiciones
                                        </a>
                                        , la{' '}
                                        <a
                                            href="/privacidad"
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-semibold underline"
                                        >
                                            Política de Privacidad
                                        </a>{' '}
                                        y el{' '}
                                        <a
                                            href="/tratamiento-de-datos"
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-semibold underline"
                                        >
                                            Tratamiento de datos personales
                                        </a>{' '}
                                        de Atlantia Finance.
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
                                tabIndex={6}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Crear cuenta y continuar
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            ¿Ya tienes una cuenta?{' '}
                            <TextLink href={login()} tabIndex={7}>
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
