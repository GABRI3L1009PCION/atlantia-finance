import { router, useForm } from '@inertiajs/react';
import { BellRing, BellOff, X } from 'lucide-react';
import { useState } from 'react';
import { Errors, field, primary, secondary } from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

export type CollectionAutomation = {
    enabled: boolean;
    upcoming_days: number | null;
    overdue_steps: number[];
    promise_reminder: boolean;
    broken_promise: boolean;
    monthly_statement: boolean;
    min_overdue_cents: number;
    quiet_days: number;
    paused_customer_ids: number[];
    configured_by: string | null;
    last_run_at: string | null;
    today: {
        customer_id: number;
        customer: string;
        kind: string;
        kind_label: string;
        reason: string;
    }[];
};

const stepOptions = [1, 3, 8, 15, 30, 45, 60, 90];

function Toggle({
    checked,
    onChange,
    label,
    hint,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    hint: string;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3">
            <input
                type="checkbox"
                className="mt-1 size-4 accent-[#8f1f30]"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            <span>
                <span className="block text-sm font-semibold text-[#142135]">
                    {label}
                </span>
                <span className="block text-xs text-slate-500">{hint}</span>
            </span>
        </label>
    );
}

/** Header button with the automatic collection state; opens the rules of the company. */
export function CollectionAutomationButton({
    companyId,
    automation,
}: {
    companyId: string;
    automation: CollectionAutomation;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        enabled: automation.enabled,
        upcoming_days: automation.upcoming_days,
        overdue_steps: automation.overdue_steps,
        promise_reminder: automation.promise_reminder,
        broken_promise: automation.broken_promise,
        monthly_statement: automation.monthly_statement,
        min_overdue_cents: automation.min_overdue_cents,
        quiet_days: automation.quiet_days,
    });

    const toggleStep = (step: number) =>
        form.setData(
            'overdue_steps',
            form.data.overdue_steps.includes(step)
                ? form.data.overdue_steps.filter((value) => value !== step)
                : [...form.data.overdue_steps, step].sort((a, b) => a - b),
        );

    return (
        <>
            <button
                type="button"
                className={secondary}
                onClick={() => setOpen(true)}
            >
                {automation.enabled ? (
                    <BellRing className="size-4 text-emerald-600" />
                ) : (
                    <BellOff className="size-4" />
                )}
                Cobranza automática:{' '}
                {automation.enabled ? 'encendida' : 'apagada'}
            </button>
            {open && (
                <div
                    className="fixed inset-0 z-[100] flex items-center justify-center bg-[#07101f]/70 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Cobranza automática"
                    onMouseDown={(event) => {
                        if (event.currentTarget === event.target) {
                            setOpen(false);
                        }
                    }}
                >
                    <section className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                        <header className="flex items-start justify-between gap-5 border-b border-slate-200 p-6">
                            <div>
                                <p className="text-xs font-semibold tracking-[.18em] text-[#8f1f30] uppercase">
                                    Cobranza automática
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold">
                                    Recordatorios sin acordarse
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    Cada mañana el sistema revisa la cartera y
                                    envía a cada cliente, como máximo, un
                                    recordatorio: el más urgente. Usa las
                                    plantillas de la firma.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500"
                                aria-label="Cerrar"
                            >
                                <X className="size-5" />
                            </button>
                        </header>
                        <form
                            className="space-y-4 p-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.put(
                                    routes.collectionAutomation.url(companyId),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setOpen(false),
                                    },
                                );
                            }}
                        >
                            <Errors errors={form.errors} />
                            <Toggle
                                checked={form.data.enabled}
                                onChange={(value) =>
                                    form.setData('enabled', value)
                                }
                                label="Encender la cobranza automática"
                                hint="Apagada, no sale ningún recordatorio automático; los manuales siguen funcionando."
                            />
                            <div className="grid gap-3 rounded-xl border border-slate-200 p-3">
                                <label className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-[#8f1f30]"
                                        checked={
                                            form.data.upcoming_days !== null
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'upcoming_days',
                                                event.target.checked ? 3 : null,
                                            )
                                        }
                                    />
                                    Avisar antes del vencimiento
                                    {form.data.upcoming_days !== null && (
                                        <>
                                            <input
                                                type="number"
                                                min={1}
                                                max={30}
                                                className={`${field} w-20`}
                                                value={form.data.upcoming_days}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'upcoming_days',
                                                        Number(
                                                            event.target.value,
                                                        ) || 1,
                                                    )
                                                }
                                            />
                                            <span className="font-normal text-slate-500">
                                                días antes
                                            </span>
                                        </>
                                    )}
                                </label>
                                <div>
                                    <p className="text-sm font-semibold">
                                        Documentos vencidos: recordar a los
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {stepOptions.map((step) => (
                                            <button
                                                key={step}
                                                type="button"
                                                aria-pressed={form.data.overdue_steps.includes(
                                                    step,
                                                )}
                                                onClick={() => toggleStep(step)}
                                                className={`rounded-full border px-3 py-1 text-xs font-semibold ${form.data.overdue_steps.includes(step) ? 'border-[#8f1f30] bg-[#8f1f30] text-white' : 'border-slate-200 text-slate-600'}`}
                                            >
                                                {step}{' '}
                                                {step === 1 ? 'día' : 'días'}
                                            </button>
                                        ))}
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Días de atraso del documento más
                                        antiguo. Cada paso se envía una sola
                                        vez.
                                    </p>
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Toggle
                                    checked={form.data.promise_reminder}
                                    onChange={(value) =>
                                        form.setData('promise_reminder', value)
                                    }
                                    label="Promesa de pago"
                                    hint="Un día antes de la fecha que prometió el cliente."
                                />
                                <Toggle
                                    checked={form.data.broken_promise}
                                    onChange={(value) =>
                                        form.setData('broken_promise', value)
                                    }
                                    label="Promesa incumplida"
                                    hint="Cuando la promesa se marca como incumplida."
                                />
                                <Toggle
                                    checked={form.data.monthly_statement}
                                    onChange={(value) =>
                                        form.setData('monthly_statement', value)
                                    }
                                    label="Estado de cuenta mensual"
                                    hint="A inicio de mes, con el PDF del mes anterior, a quien tenga saldo."
                                />
                                <div className="grid gap-2 rounded-xl border border-slate-200 p-3 text-sm">
                                    <label className="grid gap-1">
                                        <span className="font-semibold">
                                            Saldo vencido mínimo (Q)
                                        </span>
                                        <input
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            className={field}
                                            value={
                                                form.data.min_overdue_cents /
                                                100
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'min_overdue_cents',
                                                    Math.round(
                                                        Number(
                                                            event.target.value,
                                                        ) * 100,
                                                    ) || 0,
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-1">
                                        <span className="font-semibold">
                                            Días entre mensajes al mismo cliente
                                        </span>
                                        <input
                                            type="number"
                                            min={1}
                                            max={30}
                                            className={field}
                                            value={form.data.quiet_days}
                                            onChange={(event) =>
                                                form.setData(
                                                    'quiet_days',
                                                    Number(
                                                        event.target.value,
                                                    ) || 1,
                                                )
                                            }
                                        />
                                    </label>
                                </div>
                            </div>
                            {automation.enabled && (
                                <div className="rounded-xl bg-slate-50 p-3 text-sm">
                                    <p className="font-semibold">
                                        Si se enviara hoy (sale cada día a las
                                        8:40)
                                    </p>
                                    {automation.today.length === 0 ? (
                                        <p className="text-slate-500">
                                            Con la cartera de hoy no saldría
                                            ningún recordatorio.
                                        </p>
                                    ) : (
                                        <ul className="mt-1 space-y-1">
                                            {automation.today
                                                .slice(0, 8)
                                                .map((step) => (
                                                    <li key={step.customer_id}>
                                                        <strong>
                                                            {step.customer}
                                                        </strong>{' '}
                                                        · {step.kind_label} ·{' '}
                                                        <span className="text-slate-500">
                                                            {step.reason}
                                                        </span>
                                                    </li>
                                                ))}
                                        </ul>
                                    )}
                                    {automation.configured_by && (
                                        <p className="mt-2 text-xs text-slate-500">
                                            Configurada por{' '}
                                            {automation.configured_by}. Si la
                                            firma no verificó su correo de
                                            respuesta, las respuestas le llegan
                                            a esa persona.
                                        </p>
                                    )}
                                </div>
                            )}
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    className={secondary}
                                    onClick={() => setOpen(false)}
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className={primary}
                                    disabled={form.processing}
                                >
                                    Guardar
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </>
    );
}

/** Pauses or resumes the automatic reminders of one customer. */
export function CustomerAutomationToggle({
    companyId,
    customerId,
    state,
}: {
    companyId: string;
    customerId: number;
    state: { enabled: boolean; paused: boolean };
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 p-3 text-sm">
            <span>
                {!state.enabled
                    ? 'La cobranza automática de la empresa está apagada.'
                    : state.paused
                      ? 'Recordatorios automáticos en pausa para este cliente.'
                      : 'Este cliente recibe los recordatorios automáticos.'}
            </span>
            <button
                type="button"
                className="text-xs font-bold text-[#8f1f30] hover:underline"
                onClick={() =>
                    router.post(
                        routes.collectionAutomationPause.url({
                            company: companyId,
                            customer: customerId,
                        }),
                        { paused: !state.paused },
                        { preserveScroll: true },
                    )
                }
            >
                {state.paused ? 'Reanudar' : 'Pausar para este cliente'}
            </button>
        </div>
    );
}
