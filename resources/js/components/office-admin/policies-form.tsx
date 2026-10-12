import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import {
    SectionHeader,
    input,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';

export type OfficePolicies = {
    id: number;
    name: string;
    review_workflow: string;
    fiscal_internal_lead_days: number;
    fiscal_review_lead_days: number;
    fiscal_close_policy: string;
    payment_approval_tiers: { up_to_cents: number | null; approvals: number }[];
    payment_self_approval: boolean;
    treasury_close_policy: string;
    receivables_aging_buckets: number[];
    receivables_close_policy: string;
};

type Tier = { up_to: string; approvals: string };

function PolicySelect({
    label,
    value,
    onChange,
    disabled,
    hint,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    hint: string;
}) {
    return (
        <label className="text-sm font-semibold">
            {label}
            <select
                className={`${input} mt-1`}
                value={value}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="warn">Solo advertir</option>
                <option value="block">Bloquear el cierre</option>
            </select>
            <span className="text-xs font-normal text-[#526b92]">{hint}</span>
        </label>
    );
}

/** Review, fiscal, treasury and receivables policies of an office (the services read them; nothing is hardcoded). */
export function PoliciesForm({
    office,
    editable,
}: {
    office: OfficePolicies;
    editable: boolean;
}) {
    const form = useForm({
        review_workflow: office.review_workflow,
        fiscal_internal_lead_days: String(office.fiscal_internal_lead_days),
        fiscal_review_lead_days: String(office.fiscal_review_lead_days),
        fiscal_close_policy: office.fiscal_close_policy,
        payment_approval_tiers: office.payment_approval_tiers.map(
            (tier): Tier => ({
                up_to:
                    tier.up_to_cents === null
                        ? ''
                        : String(tier.up_to_cents / 100),
                approvals: String(tier.approvals),
            }),
        ),
        payment_self_approval: office.payment_self_approval,
        treasury_close_policy: office.treasury_close_policy,
        receivables_aging_buckets: office.receivables_aging_buckets.join(', '),
        receivables_close_policy: office.receivables_close_policy,
    });
    const tiers = form.data.payment_approval_tiers;
    const setTier = (index: number, key: keyof Tier, value: string) =>
        form.setData(
            'payment_approval_tiers',
            tiers.map((tier, position) =>
                position === index ? { ...tier, [key]: value } : tier,
            ),
        );

    return (
        <section className={`${panel} p-3`}>
            <SectionHeader title={`Políticas financieras · ${office.name}`} />
            <form
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        receivables_aging_buckets:
                            data.receivables_aging_buckets
                                .split(',')
                                .map((value) => value.trim())
                                .filter((value) => value !== ''),
                    }));
                    form.patch(`/office/offices/${office.id}/policies`, {
                        preserveScroll: true,
                    });
                }}
            >
                <div className="grid gap-3 md:grid-cols-4">
                    <label className="text-sm font-semibold">
                        Revisión contable
                        <select
                            className={`${input} mt-1`}
                            value={form.data.review_workflow}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'review_workflow',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="single_level">Un nivel</option>
                            <option value="double_level">
                                Dos niveles (revisión superior)
                            </option>
                        </select>
                    </label>
                    <label className="text-sm font-semibold">
                        Días de preparación fiscal antes del vencimiento
                        <input
                            type="number"
                            min={0}
                            max={30}
                            className={`${input} mt-1`}
                            value={form.data.fiscal_internal_lead_days}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'fiscal_internal_lead_days',
                                    event.target.value,
                                )
                            }
                        />
                    </label>
                    <label className="text-sm font-semibold">
                        Días de revisión fiscal antes del vencimiento
                        <input
                            type="number"
                            min={0}
                            max={30}
                            className={`${input} mt-1`}
                            value={form.data.fiscal_review_lead_days}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'fiscal_review_lead_days',
                                    event.target.value,
                                )
                            }
                        />
                    </label>
                    <PolicySelect
                        label="Fiscal pendiente al cierre"
                        value={form.data.fiscal_close_policy}
                        disabled={!editable}
                        hint="Obligaciones sin aprobar o FEL con error."
                        onChange={(value) =>
                            form.setData('fiscal_close_policy', value)
                        }
                    />
                </div>

                <div className="grid gap-3 rounded-xl border border-slate-100 p-3">
                    <p className="text-sm font-bold text-[#061633]">
                        Aprobación de pagos (Tesorería)
                    </p>
                    <p className="text-xs text-[#526b92]">
                        Cada tramo indica cuántas aprobaciones distintas
                        requiere un pago hasta ese monto; deja el límite vacío
                        para el último tramo. Sin tramos se exige una
                        aprobación.
                    </p>
                    {tiers.map((tier, index) => (
                        <div
                            key={index}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <label className="text-xs font-semibold">
                                Hasta (Q)
                                <input
                                    className={`${input} mt-1 w-40`}
                                    inputMode="decimal"
                                    placeholder="Sin límite"
                                    value={tier.up_to}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        setTier(
                                            index,
                                            'up_to',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="text-xs font-semibold">
                                Aprobaciones
                                <select
                                    className={`${input} mt-1 w-32`}
                                    value={tier.approvals}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        setTier(
                                            index,
                                            'approvals',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {['1', '2', '3'].map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {editable && (
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    aria-label="Quitar tramo"
                                    onClick={() =>
                                        form.setData(
                                            'payment_approval_tiers',
                                            tiers.filter(
                                                (_, position) =>
                                                    position !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            )}
                        </div>
                    ))}
                    {editable && tiers.length < 5 && (
                        <button
                            type="button"
                            className={`${secondaryButton} w-fit`}
                            onClick={() =>
                                form.setData('payment_approval_tiers', [
                                    ...tiers,
                                    { up_to: '', approvals: '1' },
                                ])
                            }
                        >
                            <Plus className="size-3.5" /> Agregar tramo
                        </button>
                    )}
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.payment_self_approval}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'payment_self_approval',
                                    event.target.checked,
                                )
                            }
                        />
                        Permitir que quien prepara un pago también lo apruebe
                        (no recomendado)
                    </label>
                </div>

                <div className="grid gap-3 md:grid-cols-3">
                    <PolicySelect
                        label="Tesorería pendiente al cierre"
                        value={form.data.treasury_close_policy}
                        disabled={!editable}
                        hint="Cuentas sin conciliación aprobada o movimientos sin conciliar."
                        onChange={(value) =>
                            form.setData('treasury_close_policy', value)
                        }
                    />
                    <label className="text-sm font-semibold">
                        Tramos de antigüedad de CxC (días)
                        <input
                            className={`${input} mt-1`}
                            placeholder="30, 60, 90"
                            value={form.data.receivables_aging_buckets}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'receivables_aging_buckets',
                                    event.target.value,
                                )
                            }
                        />
                        <span className="text-xs font-normal text-[#526b92]">
                            Límites separados por coma; el último tramo es “más
                            de”.
                        </span>
                    </label>
                    <PolicySelect
                        label="Diferencia CxC vs Mayor al cierre"
                        value={form.data.receivables_close_policy}
                        disabled={!editable}
                        hint="Tener clientes con saldo nunca bloquea; solo las inconsistencias."
                        onChange={(value) =>
                            form.setData('receivables_close_policy', value)
                        }
                    />
                </div>
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-red-700">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                {editable && (
                    <div>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Guardar políticas
                        </button>
                    </div>
                )}
            </form>
        </section>
    );
}
