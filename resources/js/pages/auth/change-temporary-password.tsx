import { Head, useForm } from '@inertiajs/react';
import { Check, Circle, Eye, EyeOff, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';

function PasswordField({
    label,
    value,
    onChange,
    error,
    autoComplete,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    autoComplete: string;
}) {
    const [visible, setVisible] = useState(false);

    return (
        <div>
            <label className="text-[11px] font-bold tracking-wide text-slate-600 uppercase">
                {label}
            </label>
            <div className="relative mt-0.5">
                <input
                    type={visible ? 'text' : 'password'}
                    className="h-9 w-full rounded-lg border border-slate-300 bg-white pr-10 pl-3 text-sm text-slate-900 transition outline-none focus:border-[#640000] focus:ring-2 focus:ring-[#640000]/15"
                    value={value}
                    required
                    autoComplete={autoComplete}
                    onChange={(event) => onChange(event.target.value)}
                />
                <button
                    type="button"
                    onClick={() => setVisible(!visible)}
                    aria-label={
                        visible ? 'Ocultar contraseña' : 'Mostrar contraseña'
                    }
                    className="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-[#640000]"
                >
                    {visible ? (
                        <EyeOff className="size-4" />
                    ) : (
                        <Eye className="size-4" />
                    )}
                </button>
            </div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

export default function ChangeTemporaryPassword({
    email,
    name,
}: {
    email: string;
    name: string;
}) {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const {
        current_password: current,
        password,
        password_confirmation: confirmation,
    } = form.data;
    const rules: [string, boolean][] = [
        ['8 caracteres o más', password.length >= 8],
        ['Una mayúscula', /\p{Lu}/u.test(password)],
        ['Una minúscula', /\p{Ll}/u.test(password)],
        ['Un número', /\d/.test(password)],
        ['Un símbolo', /[^\p{L}\d]/u.test(password)],
        ['Distinta a la temporal', password !== '' && password !== current],
        [
            'Distinta al usuario y correo',
            password !== '' &&
                ![
                    email.toLowerCase(),
                    email.split('@')[0].toLowerCase(),
                    name.toLowerCase(),
                ].includes(password.toLowerCase()),
        ],
        ['Las dos coinciden', password !== '' && password === confirmation],
    ];
    const valid = rules.every(([, passes]) => passes);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/password/change', {
            onFinish: () =>
                form.reset(
                    'current_password',
                    'password',
                    'password_confirmation',
                ),
        });
    }

    return (
        <>
            <Head title="Crea tu contraseña" />
            <form onSubmit={submit} className="space-y-2.5 text-slate-900">
                <div className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-4 text-amber-900">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <p>
                        Entraste con una contraseña temporal de tu oficina
                        contable. Crea la tuya para continuar.
                    </p>
                </div>
                <div className="flex items-center justify-between gap-2 text-xs text-slate-500">
                    <span className="truncate">
                        Cuenta:{' '}
                        <span className="font-semibold text-slate-800">
                            {email}
                        </span>
                    </span>
                    <button
                        type="button"
                        onClick={() => form.post('/logout')}
                        className="shrink-0 hover:text-[#640000] hover:underline"
                    >
                        Cerrar sesión
                    </button>
                </div>

                <PasswordField
                    label="Contraseña temporal"
                    value={form.data.current_password}
                    onChange={(value) =>
                        form.setData('current_password', value)
                    }
                    error={form.errors.current_password}
                    autoComplete="current-password"
                />
                <PasswordField
                    label="Contraseña nueva"
                    value={form.data.password}
                    onChange={(value) => form.setData('password', value)}
                    error={form.errors.password}
                    autoComplete="new-password"
                />
                <PasswordField
                    label="Repite la contraseña nueva"
                    value={form.data.password_confirmation}
                    onChange={(value) =>
                        form.setData('password_confirmation', value)
                    }
                    autoComplete="new-password"
                />

                <ul className="grid grid-cols-2 gap-x-3 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-[11px]">
                    {rules.map(([label, passes]) => (
                        <li
                            key={label}
                            className={`flex items-center gap-1.5 ${passes ? 'font-semibold text-emerald-700' : 'text-slate-500'}`}
                        >
                            {passes ? (
                                <Check className="size-3.5 shrink-0" />
                            ) : (
                                <Circle className="size-3.5 shrink-0 opacity-40" />
                            )}
                            {label}
                        </li>
                    ))}
                </ul>

                <button
                    disabled={form.processing || !valid}
                    className="h-10 w-full rounded-lg bg-[#640000] text-sm font-bold tracking-wide text-white uppercase transition hover:bg-[#7a0a0a] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {form.processing ? 'Guardando…' : 'Guardar y continuar'}
                </button>
            </form>
        </>
    );
}

ChangeTemporaryPassword.layout = {
    title: 'Crea tu contraseña',
    description: 'Elige una contraseña propia para proteger tu cuenta.',
};
