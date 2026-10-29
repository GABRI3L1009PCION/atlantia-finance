import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, cell, head, Label } from '@/components/accounting-page';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
    shortDate,
} from '@/components/accounting-ui';

export type WithholdingSettings = {
    vat_agent: string | null;
    vat_rate_bp: number | null;
    isr_agent: boolean;
    threshold_cents: number;
};

export type VatAgents = Record<string, { label: string; rate_bp: number }>;

type ReconciliationLine = {
    kind: string;
    label: string;
    documents_cents: number;
    ledger_cents: number;
    difference_cents: number;
};

export type WithholdingReconciliation = {
    made: ReconciliationLine[];
    received: ReconciliationLine[];
    missing_certificates: number;
    received_rows: {
        id: number;
        kind: string;
        kind_label: string;
        customer: string;
        tax_id: string | null;
        document: string;
        amount_cents: number;
        certificate_number: string;
        certificate_date: string;
    }[];
};

/** Whether the company withholds IVA (and how much) and ISR, so supplier payments can suggest it. */
export function WithholdingSettingsCard({
    base,
    settings,
    agents,
    canManage,
}: {
    base: string;
    settings: WithholdingSettings;
    agents: VatAgents;
    canManage: boolean;
}) {
    const form = useForm({
        vat_agent: settings.vat_agent ?? '',
        vat_rate:
            settings.vat_rate_bp === null
                ? ''
                : String(settings.vat_rate_bp / 100),
        isr_agent: settings.isr_agent,
        threshold: String(settings.threshold_cents / 100),
    });

    return (
        <Card title="La empresa como agente de retención">
            <form
                className="grid gap-3 md:grid-cols-[2fr_1fr_1fr_auto]"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        vat_agent: data.vat_agent || null,
                        vat_rate: data.vat_rate || null,
                    }));
                    form.put(`${base}/withholdings/settings`, {
                        preserveScroll: true,
                    });
                }}
            >
                <Label text="Agente de retención del IVA">
                    <select
                        className={field}
                        disabled={!canManage}
                        value={form.data.vat_agent}
                        onChange={(event) =>
                            form.setData({
                                ...form.data,
                                vat_agent: event.target.value,
                                vat_rate: event.target.value
                                    ? String(
                                          (agents[event.target.value]
                                              ?.rate_bp ?? 0) / 100,
                                      )
                                    : '',
                            })
                        }
                    >
                        <option value="">No es agente de retención</option>
                        {Object.entries(agents).map(([key, agent]) => (
                            <option key={key} value={key}>
                                {agent.label}
                            </option>
                        ))}
                    </select>
                </Label>
                <Label text="% del IVA que retiene">
                    <input
                        className={field}
                        inputMode="decimal"
                        disabled={!canManage || !form.data.vat_agent}
                        value={form.data.vat_rate}
                        onChange={(event) =>
                            form.setData('vat_rate', event.target.value)
                        }
                    />
                </Label>
                <Label text="Compra mínima (Q)">
                    <input
                        className={field}
                        inputMode="decimal"
                        disabled={!canManage}
                        value={form.data.threshold}
                        onChange={(event) =>
                            form.setData('threshold', event.target.value)
                        }
                    />
                </Label>
                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-4"
                        disabled={!canManage}
                        checked={form.data.isr_agent}
                        onChange={(event) =>
                            form.setData('isr_agent', event.target.checked)
                        }
                    />
                    Retiene ISR
                </label>
                <p className="text-xs text-slate-500 md:col-span-3">
                    Con esto, al pagar a un proveedor el sistema sugiere la
                    retención: el porcentaje del IVA del agente (5 % del total
                    si es pequeño contribuyente) y el ISR a los proveedores
                    sujetos a retención definitiva (5 % hasta Q30,000 y 7 %
                    sobre el excedente). Confirma los porcentajes con la
                    resolución de la SAT.
                </p>
                {canManage && (
                    <button
                        className={`${primary} self-start`}
                        disabled={form.processing}
                    >
                        Guardar
                    </button>
                )}
                <div className="md:col-span-4">
                    <Errors errors={form.errors} />
                </div>
            </form>
        </Card>
    );
}

/** Withholdings against the ledger, and the constancias customers handed over. */
export function WithholdingReconciliationCard({
    reconciliation,
}: {
    reconciliation: WithholdingReconciliation;
}) {
    const lines = [...reconciliation.made, ...reconciliation.received];

    return (
        <Card title="Conciliación con la contabilidad">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[640px]">
                    <thead>
                        <tr>
                            {[
                                'Concepto',
                                'Según documentos',
                                'Según Libro Mayor',
                                'Diferencia',
                            ].map((title) => (
                                <th key={title} className={head}>
                                    {title}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((line) => (
                            <tr
                                key={line.label}
                                className="border-t border-slate-100"
                            >
                                <td className={cell}>{line.label}</td>
                                <td className={cell}>
                                    {money(line.documents_cents)}
                                </td>
                                <td className={cell}>
                                    {money(line.ledger_cents)}
                                </td>
                                <td className={cell}>
                                    {line.difference_cents === 0 ? (
                                        <Badge tone="green">Cuadra</Badge>
                                    ) : (
                                        <Badge tone="amber">
                                            {money(line.difference_cents)}
                                        </Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="mt-2 text-xs text-slate-500">
                El Libro Mayor cuenta solo asientos contabilizados: una
                diferencia suele ser un asiento todavía en borrador.
                {reconciliation.missing_certificates > 0 &&
                    ` Hay ${reconciliation.missing_certificates} retención(es) sin el número de constancia de Retenciones Web de la SAT.`}
            </p>
            {reconciliation.received_rows.length > 0 && (
                <div className="mt-4 overflow-x-auto">
                    <p className="mb-1 text-sm font-semibold">
                        Constancias recibidas de clientes
                    </p>
                    <table className="w-full min-w-[640px]">
                        <thead>
                            <tr>
                                {[
                                    'Fecha',
                                    'Cliente',
                                    'Comprobante',
                                    'Impuesto',
                                    'Constancia',
                                    'Monto',
                                ].map((title) => (
                                    <th key={title} className={head}>
                                        {title}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {reconciliation.received_rows.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-t border-slate-100"
                                >
                                    <td className={cell}>
                                        {shortDate(row.certificate_date)}
                                    </td>
                                    <td className={cell}>
                                        {row.customer}
                                        <p className="text-xs text-slate-500">
                                            {row.tax_id}
                                        </p>
                                    </td>
                                    <td className={cell}>{row.document}</td>
                                    <td className={cell}>{row.kind_label}</td>
                                    <td className={cell}>
                                        {row.certificate_number}
                                    </td>
                                    <td className={`${cell} font-semibold`}>
                                        {money(row.amount_cents)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/** The SAT constancia number of a withholding made, editable until it is filled. */
export function CertificateCell({
    base,
    id,
    number,
    date,
    canManage,
}: {
    base: string;
    id: number;
    number: string | null;
    date: string | null;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState({
        certificate_number: number ?? '',
        certificate_date: date ?? new Date().toISOString().slice(0, 10),
    });

    if (!editing) {
        return (
            <span className="flex flex-wrap items-center gap-2">
                {number ? (
                    <span>
                        {number}
                        {date && (
                            <span className="block text-xs text-slate-500">
                                {date}
                            </span>
                        )}
                    </span>
                ) : (
                    <Badge tone="amber">Pendiente</Badge>
                )}
                {canManage && (
                    <button
                        type="button"
                        className="text-xs font-semibold text-[#8f1f30] hover:underline"
                        onClick={() => setEditing(true)}
                    >
                        {number ? 'Cambiar' : 'Registrar'}
                    </button>
                )}
            </span>
        );
    }

    return (
        <form
            className="flex flex-wrap gap-1"
            onSubmit={(event) => {
                event.preventDefault();
                router.patch(`${base}/withholdings/${id}/certificate`, value, {
                    preserveScroll: true,
                    onSuccess: () => setEditing(false),
                });
            }}
        >
            <input
                aria-label="Número de constancia"
                className={`${field} w-32`}
                value={value.certificate_number}
                onChange={(event) =>
                    setValue({
                        ...value,
                        certificate_number: event.target.value,
                    })
                }
            />
            <input
                aria-label="Fecha de la constancia"
                type="date"
                className={`${field} w-36`}
                value={value.certificate_date}
                onChange={(event) =>
                    setValue({
                        ...value,
                        certificate_date: event.target.value,
                    })
                }
            />
            <button className={primary}>Guardar</button>
            <button
                type="button"
                className={secondary}
                onClick={() => setEditing(false)}
            >
                Cancelar
            </button>
        </form>
    );
}
