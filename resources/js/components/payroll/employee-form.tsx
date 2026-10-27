import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { input, primaryButton } from '@/components/office-admin/kit';

export type EmployeeFormData = {
    company_id: string;
    code: string;
    first_name: string;
    last_name: string;
    dpi: string;
    nit: string;
    igss_number: string;
    email: string;
    position: string;
    department: string;
    hire_date: string;
    pay_frequency: string;
    base_salary: string;
    incentive_bonus: string;
    igss_affiliated: boolean;
    payment_method: string;
    bank_name: string;
    bank_account_number: string;
    bank_account_type: string;
    sex: string;
    birth_date: string;
    nationality: string;
};

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label className="grid gap-1 text-xs font-semibold">
            {label}
            {children}
            {error && <span className="text-[11px] text-red-700">{error}</span>}
        </label>
    );
}

/**
 * Employee record form. The incentive bonus defaults to the legal amount only as a suggestion typed by the user; the
 * calculation always takes rates and amounts from the payroll parameters and the employee record.
 */
export function EmployeeForm({
    initial,
    companies,
    action,
    method,
    submitLabel,
}: {
    initial: EmployeeFormData;
    companies?: { id: number; name: string }[];
    action: string;
    method: 'post' | 'put';
    submitLabel: string;
}) {
    const form = useForm<EmployeeFormData>(initial);
    const set = <K extends keyof EmployeeFormData>(
        key: K,
        value: EmployeeFormData[K],
    ) => form.setData((data) => ({ ...data, [key]: value }));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(method, action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-3">
            <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
                {companies && (
                    <Field label="Empresa" error={form.errors.company_id}>
                        <select
                            className={input}
                            value={form.data.company_id}
                            onChange={(event) =>
                                set('company_id', event.target.value)
                            }
                            required
                        >
                            <option value="">Selecciona…</option>
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                <Field label="Código" error={form.errors.code}>
                    <input
                        className={input}
                        value={form.data.code}
                        onChange={(event) => set('code', event.target.value)}
                        required
                    />
                </Field>
                <Field label="Nombres" error={form.errors.first_name}>
                    <input
                        className={input}
                        value={form.data.first_name}
                        onChange={(event) =>
                            set('first_name', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field label="Apellidos" error={form.errors.last_name}>
                    <input
                        className={input}
                        value={form.data.last_name}
                        onChange={(event) =>
                            set('last_name', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field label="DPI (13 dígitos)" error={form.errors.dpi}>
                    <input
                        className={input}
                        value={form.data.dpi}
                        onChange={(event) => set('dpi', event.target.value)}
                        inputMode="numeric"
                    />
                </Field>
                <Field label="NIT" error={form.errors.nit}>
                    <input
                        className={input}
                        value={form.data.nit}
                        onChange={(event) => set('nit', event.target.value)}
                    />
                </Field>
                <Field label="Afiliación IGSS" error={form.errors.igss_number}>
                    <input
                        className={input}
                        value={form.data.igss_number}
                        onChange={(event) =>
                            set('igss_number', event.target.value)
                        }
                    />
                </Field>
                <Field label="Sexo" error={form.errors.sex}>
                    <select
                        className={input}
                        value={form.data.sex}
                        onChange={(event) => set('sex', event.target.value)}
                    >
                        <option value="">Sin indicar</option>
                        <option value="F">Mujer</option>
                        <option value="M">Hombre</option>
                    </select>
                </Field>
                <Field
                    label="Fecha de nacimiento"
                    error={form.errors.birth_date}
                >
                    <input
                        type="date"
                        className={input}
                        value={form.data.birth_date}
                        onChange={(event) =>
                            set('birth_date', event.target.value)
                        }
                    />
                </Field>
                <Field label="Nacionalidad" error={form.errors.nationality}>
                    <input
                        className={input}
                        value={form.data.nationality}
                        onChange={(event) =>
                            set('nationality', event.target.value)
                        }
                    />
                </Field>
                <Field label="Correo" error={form.errors.email}>
                    <input
                        type="email"
                        className={input}
                        value={form.data.email}
                        onChange={(event) => set('email', event.target.value)}
                    />
                </Field>
                <Field label="Puesto" error={form.errors.position}>
                    <input
                        className={input}
                        value={form.data.position}
                        onChange={(event) =>
                            set('position', event.target.value)
                        }
                    />
                </Field>
                <Field label="Departamento" error={form.errors.department}>
                    <input
                        className={input}
                        value={form.data.department}
                        onChange={(event) =>
                            set('department', event.target.value)
                        }
                    />
                </Field>
                <Field label="Fecha de ingreso" error={form.errors.hire_date}>
                    <input
                        type="date"
                        className={input}
                        value={form.data.hire_date}
                        onChange={(event) =>
                            set('hire_date', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Frecuencia de pago"
                    error={form.errors.pay_frequency}
                >
                    <select
                        className={input}
                        value={form.data.pay_frequency}
                        onChange={(event) =>
                            set('pay_frequency', event.target.value)
                        }
                    >
                        <option value="monthly">Mensual</option>
                    </select>
                </Field>
                <Field
                    label="Salario base mensual (Q)"
                    error={form.errors.base_salary}
                >
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        className={input}
                        value={form.data.base_salary}
                        onChange={(event) =>
                            set('base_salary', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Bonificación incentivo mensual (Q)"
                    error={form.errors.incentive_bonus}
                >
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        className={input}
                        value={form.data.incentive_bonus}
                        onChange={(event) =>
                            set('incentive_bonus', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Afiliado al IGSS"
                    error={form.errors.igss_affiliated}
                >
                    <select
                        className={input}
                        value={form.data.igss_affiliated ? '1' : '0'}
                        onChange={(event) =>
                            set('igss_affiliated', event.target.value === '1')
                        }
                    >
                        <option value="1">Sí</option>
                        <option value="0">No</option>
                    </select>
                </Field>
                <Field label="Forma de pago" error={form.errors.payment_method}>
                    <select
                        className={input}
                        value={form.data.payment_method}
                        onChange={(event) =>
                            set('payment_method', event.target.value)
                        }
                    >
                        <option value="transfer">Transferencia</option>
                        <option value="check">Cheque</option>
                        <option value="cash">Efectivo</option>
                    </select>
                </Field>
                {form.data.payment_method === 'transfer' && (
                    <>
                        <Field label="Banco" error={form.errors.bank_name}>
                            <input
                                className={input}
                                value={form.data.bank_name}
                                onChange={(event) =>
                                    set('bank_name', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Número de cuenta"
                            error={form.errors.bank_account_number}
                        >
                            <input
                                className={input}
                                value={form.data.bank_account_number}
                                onChange={(event) =>
                                    set(
                                        'bank_account_number',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Tipo de cuenta"
                            error={form.errors.bank_account_type}
                        >
                            <select
                                className={input}
                                value={form.data.bank_account_type}
                                onChange={(event) =>
                                    set('bank_account_type', event.target.value)
                                }
                            >
                                <option value="">—</option>
                                <option value="monetaria">Monetaria</option>
                                <option value="ahorro">Ahorro</option>
                            </select>
                        </Field>
                    </>
                )}
            </div>
            <div>
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing}
                >
                    {submitLabel}
                </button>
            </div>
        </form>
    );
}

export const emptyEmployee: EmployeeFormData = {
    company_id: '',
    code: '',
    first_name: '',
    last_name: '',
    dpi: '',
    nit: '',
    igss_number: '',
    email: '',
    position: '',
    department: '',
    hire_date: '',
    pay_frequency: 'monthly',
    base_salary: '',
    incentive_bonus: '',
    igss_affiliated: true,
    payment_method: 'transfer',
    bank_name: '',
    bank_account_number: '',
    bank_account_type: '',
    sex: '',
    birth_date: '',
    nationality: 'Guatemalteca',
};
