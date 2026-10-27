import { router, useForm } from '@inertiajs/react';
import { Calculator, Download, Plus, X } from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    exportUrl,
    head,
    Label,
    PageShell,
    Stat,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import {
    CertificateCell,
    WithholdingReconciliationCard,
    WithholdingSettingsCard,
} from '@/components/withholdings-panel';
import type {
    VatAgents,
    WithholdingReconciliation,
    WithholdingSettings,
} from '@/components/withholdings-panel';

type AccountRow = {
    id: number;
    code: string;
    name: string;
    type: string;
    nature: string;
    explicit: boolean;
    cents: number;
};
type Adjustment = {
    id: number;
    kind: 'temporary' | 'permanent';
    direction: 'increase' | 'decrease';
    description: string;
    amount_cents: number;
};
type Isr = {
    year: number;
    range: { from: string; to: string };
    regime: string;
    regime_label: string;
    income_cents: number;
    expense_cents: number;
    accounting_profit_cents: number;
    taxable_income_cents: number;
    temporary_difference_cents: number;
    permanent_difference_cents: number;
    isr_cents: number;
    rate_bp: number | null;
    reconciliation: { label: string; cents: number; kind: string }[];
    quarters: {
        quarter: number;
        label: string;
        gross_cents: number;
        payment_cents: number;
    }[];
    monthly: {
        month: string;
        label: string;
        gross_cents: number;
        tax_cents: number;
    }[];
};
type Withholdings = {
    rows: {
        id: number;
        date: string;
        supplier: string;
        tax_id: string | null;
        document: string;
        kind: string;
        kind_label: string;
        base_cents: number;
        rate_bp: number;
        amount_cents: number;
        certificate_number: string | null;
        certificate_date: string | null;
    }[];
    totals: {
        isr_cents: number;
        iva_cents: number;
        total_cents: number;
        base_cents: number;
    };
    monthly: {
        month: string;
        isr_cents: number;
        iva_cents: number;
        total_cents: number;
    }[];
};

type Props = {
    company: Company;
    year: number;
    isr: Isr;
    natures: Record<string, string>;
    accounts: AccountRow[];
    adjustments: Adjustment[];
    withholdings: Withholdings;
    withholdingSettings: WithholdingSettings;
    vatAgents: VatAgents;
    withholdingReconciliation: WithholdingReconciliation;
    filters: { from: string; to: string };
    canManage: boolean;
    canExport: boolean;
};

export default function TaxReports({
    company,
    year,
    isr,
    natures,
    accounts,
    adjustments,
    withholdings,
    withholdingSettings,
    vatAgents,
    withholdingReconciliation,
    filters,
    canManage,
    canExport,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/tax-reports`;
    const [tab, setTab] = useState<'isr' | 'natures' | 'withholdings'>('isr');
    const [range, setRange] = useState(filters);
    const adjustment = useForm({
        fiscal_year: year,
        kind: 'permanent',
        direction: 'increase',
        description: '',
        amount: '',
    });
    const quarterly = isr.quarters.length > 0;

    return (
        <PageShell
            company={company}
            title="ISR y retenciones"
            subtitle={isr.regime_label}
            icon={Calculator}
            actions={
                <form
                    className="flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const value = new FormData(event.currentTarget).get(
                            'year',
                        );
                        router.get(
                            base,
                            { year: String(value) },
                            { preserveState: true },
                        );
                    }}
                >
                    <input
                        name="year"
                        type="number"
                        min={2000}
                        max={2100}
                        defaultValue={year}
                        className={`${field} !mt-0 w-28`}
                    />
                    <button className={secondary}>Ejercicio</button>
                </form>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat
                    label="Utilidad contable"
                    value={money(isr.accounting_profit_cents)}
                />
                <Stat
                    label="Renta imponible"
                    value={money(isr.taxable_income_cents)}
                />
                <Stat
                    label={`ISR del ejercicio${isr.rate_bp ? ` (${isr.rate_bp / 100} %)` : ''}`}
                    value={money(isr.isr_cents)}
                    tone="bad"
                />
                <Stat
                    label="Diferencias temporales / permanentes"
                    value={`${money(isr.temporary_difference_cents)} / ${money(isr.permanent_difference_cents)}`}
                />
            </div>

            <div className="flex flex-wrap gap-2" role="tablist">
                {(
                    [
                        ['isr', 'Cálculo de ISR'],
                        ['natures', 'Naturaleza fiscal de cuentas'],
                        ['withholdings', 'Retenciones'],
                    ] as const
                ).map(([key, label]) => (
                    <button
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        onClick={() => setTab(key)}
                        className={`rounded-full px-4 py-2 text-sm font-semibold ${tab === key ? 'bg-[#640000] text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'isr' && (
                <>
                    <Card
                        title={`Conciliación de la renta imponible · ${isr.range.from} al ${isr.range.to}`}
                    >
                        <table className="w-full max-w-3xl">
                            <tbody>
                                {isr.reconciliation.map((row) => (
                                    <tr
                                        key={row.label}
                                        className={`border-t border-slate-100 ${row.kind === 'total' ? 'bg-[#f7f1f1] font-bold' : ''}`}
                                    >
                                        <td className={cell}>{row.label}</td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>

                    <Card
                        title={
                            quarterly
                                ? 'Pagos trimestrales (8 % de la renta bruta)'
                                : 'Impuesto mensual sobre ingresos'
                        }
                    >
                        {quarterly ? (
                            <table className="w-full max-w-3xl">
                                <thead>
                                    <tr>
                                        {[
                                            'Trimestre',
                                            'Renta bruta gravada',
                                            'Pago',
                                        ].map((title) => (
                                            <th key={title} className={head}>
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {isr.quarters.map((row) => (
                                        <tr
                                            key={row.quarter}
                                            className="border-t border-slate-100"
                                        >
                                            <td className={cell}>
                                                {row.label}
                                            </td>
                                            <td className={cell}>
                                                {money(row.gross_cents)}
                                            </td>
                                            <td
                                                className={`${cell} font-semibold`}
                                            >
                                                {money(row.payment_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : isr.regime === 'exempt' ? (
                            <Empty>
                                La empresa está en régimen exento; no genera
                                ISR.
                            </Empty>
                        ) : (
                            <table className="w-full max-w-3xl">
                                <thead>
                                    <tr>
                                        {[
                                            'Mes',
                                            'Ingresos gravados',
                                            'Impuesto',
                                        ].map((title) => (
                                            <th key={title} className={head}>
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {isr.monthly.map((row) => (
                                        <tr
                                            key={row.month}
                                            className="border-t border-slate-100"
                                        >
                                            <td className={cell}>
                                                {row.label}
                                            </td>
                                            <td className={cell}>
                                                {money(row.gross_cents)}
                                            </td>
                                            <td
                                                className={`${cell} font-semibold`}
                                            >
                                                {money(row.tax_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Card>

                    <Card title="Ajustes fiscales manuales">
                        {canManage && (
                            <form
                                className="mb-4 grid gap-3 md:grid-cols-[150px_150px_1fr_150px_auto] md:items-end"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    adjustment.post(`${base}/adjustments`, {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            adjustment.reset(
                                                'description',
                                                'amount',
                                            ),
                                    });
                                }}
                            >
                                <Label text="Tipo">
                                    <select
                                        className={field}
                                        value={adjustment.data.kind}
                                        onChange={(e) =>
                                            adjustment.setData(
                                                'kind',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="permanent">
                                            Permanente
                                        </option>
                                        <option value="temporary">
                                            Temporal
                                        </option>
                                    </select>
                                </Label>
                                <Label text="Efecto">
                                    <select
                                        className={field}
                                        value={adjustment.data.direction}
                                        onChange={(e) =>
                                            adjustment.setData(
                                                'direction',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="increase">
                                            Aumenta la renta
                                        </option>
                                        <option value="decrease">
                                            Disminuye la renta
                                        </option>
                                    </select>
                                </Label>
                                <Label text="Descripción">
                                    <input
                                        className={field}
                                        value={adjustment.data.description}
                                        onChange={(e) =>
                                            adjustment.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Label>
                                <Label text="Monto (Q)">
                                    <input
                                        className={field}
                                        inputMode="decimal"
                                        value={adjustment.data.amount}
                                        onChange={(e) =>
                                            adjustment.setData(
                                                'amount',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Label>
                                <button
                                    className={primary}
                                    disabled={adjustment.processing}
                                >
                                    <Plus className="size-4" /> Agregar
                                </button>
                            </form>
                        )}
                        <Errors errors={adjustment.errors} />
                        {adjustments.length === 0 ? (
                            <Empty>Sin ajustes para el ejercicio.</Empty>
                        ) : (
                            <table className="w-full">
                                <tbody>
                                    {adjustments.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-t border-slate-100"
                                        >
                                            <td className={cell}>
                                                {row.description}
                                            </td>
                                            <td className={cell}>
                                                <Badge>
                                                    {row.kind === 'temporary'
                                                        ? 'Temporal'
                                                        : 'Permanente'}
                                                </Badge>
                                            </td>
                                            <td className={cell}>
                                                {row.direction === 'increase'
                                                    ? '(+)'
                                                    : '(−)'}{' '}
                                                {money(row.amount_cents)}
                                            </td>
                                            <td className={cell}>
                                                {canManage && (
                                                    <button
                                                        aria-label="Retirar ajuste"
                                                        onClick={() =>
                                                            router.delete(
                                                                `${base}/adjustments/${row.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <X className="size-4 text-red-700" />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Card>
                </>
            )}

            {tab === 'natures' && (
                <Card title="Naturaleza fiscal de las cuentas de resultados">
                    <p className="mb-4 text-sm text-slate-600">
                        Define si cada cuenta es renta gravada o exenta, o gasto
                        deducible o no deducible. Es la base del cálculo de la
                        renta imponible.
                    </p>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px]">
                            <thead>
                                <tr>
                                    {[
                                        'Cuenta',
                                        'Monto del ejercicio',
                                        'Naturaleza',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.map((account) => (
                                    <tr
                                        key={account.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            <span className="mr-2 font-mono text-xs text-slate-400">
                                                {account.code}
                                            </span>
                                            {account.name}
                                        </td>
                                        <td className={cell}>
                                            {money(account.cents)}
                                        </td>
                                        <td className={cell}>
                                            <select
                                                className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
                                                disabled={!canManage}
                                                value={account.nature}
                                                onChange={(e) =>
                                                    router.patch(
                                                        `${base}/accounts/${account.id}/nature`,
                                                        {
                                                            nature: e.target
                                                                .value,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {Object.entries(natures).map(
                                                    ([key, label]) => (
                                                        <option
                                                            key={key}
                                                            value={key}
                                                        >
                                                            {label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            {!account.explicit && (
                                                <span className="ml-2 text-xs text-slate-400">
                                                    por defecto
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}

            {tab === 'withholdings' && (
                <>
                    <WithholdingSettingsCard
                        base={base}
                        settings={withholdingSettings}
                        agents={vatAgents}
                        canManage={canManage}
                    />
                    <Card title="Retenciones aplicadas en pagos a proveedores">
                        <form
                            className="mb-4 flex flex-wrap items-end gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.get(
                                    base,
                                    { year, ...range },
                                    { preserveState: true },
                                );
                            }}
                        >
                            <Label text="Desde">
                                <input
                                    type="date"
                                    className={field}
                                    value={range.from}
                                    onChange={(e) =>
                                        setRange({
                                            ...range,
                                            from: e.target.value,
                                        })
                                    }
                                />
                            </Label>
                            <Label text="Hasta">
                                <input
                                    type="date"
                                    className={field}
                                    value={range.to}
                                    onChange={(e) =>
                                        setRange({
                                            ...range,
                                            to: e.target.value,
                                        })
                                    }
                                />
                            </Label>
                            <button className={primary}>Consultar</button>
                            {canExport && (
                                <>
                                    <a
                                        className={secondary}
                                        href={exportUrl(
                                            `${base}/withholdings/export`,
                                            { ...range, format: 'xlsx' },
                                        )}
                                    >
                                        <Download className="size-4" /> Excel
                                    </a>
                                    <a
                                        className={secondary}
                                        href={exportUrl(
                                            `${base}/withholdings/export`,
                                            { ...range, format: 'pdf' },
                                        )}
                                    >
                                        <Download className="size-4" /> PDF
                                    </a>
                                </>
                            )}
                        </form>
                        <div className="mb-4 grid gap-3 sm:grid-cols-3">
                            <Stat
                                label="ISR retenido"
                                value={money(withholdings.totals.isr_cents)}
                            />
                            <Stat
                                label="IVA retenido"
                                value={money(withholdings.totals.iva_cents)}
                            />
                            <Stat
                                label="Total retenido"
                                value={money(withholdings.totals.total_cents)}
                            />
                        </div>
                        {withholdings.rows.length === 0 ? (
                            <Empty>No hay retenciones en el período.</Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[820px]">
                                    <thead>
                                        <tr>
                                            {[
                                                'Fecha',
                                                'Proveedor',
                                                'Documento',
                                                'Impuesto',
                                                'Base',
                                                'Tasa',
                                                'Retenido',
                                                'Constancia',
                                            ].map((title) => (
                                                <th
                                                    key={title}
                                                    className={head}
                                                >
                                                    {title}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {withholdings.rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-t border-slate-100"
                                            >
                                                <td className={cell}>
                                                    {row.date}
                                                </td>
                                                <td className={cell}>
                                                    {row.supplier}
                                                    <p className="text-xs text-slate-500">
                                                        {row.tax_id}
                                                    </p>
                                                </td>
                                                <td className={cell}>
                                                    {row.document}
                                                </td>
                                                <td className={cell}>
                                                    {row.kind_label}
                                                </td>
                                                <td className={cell}>
                                                    {money(row.base_cents)}
                                                </td>
                                                <td className={cell}>
                                                    {row.rate_bp / 100} %
                                                </td>
                                                <td
                                                    className={`${cell} font-semibold`}
                                                >
                                                    {money(row.amount_cents)}
                                                </td>
                                                <td className={cell}>
                                                    <CertificateCell
                                                        base={base}
                                                        id={row.id}
                                                        number={
                                                            row.certificate_number
                                                        }
                                                        date={
                                                            row.certificate_date
                                                        }
                                                        canManage={canManage}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                    <WithholdingReconciliationCard
                        reconciliation={withholdingReconciliation}
                    />
                    {withholdings.monthly.length > 0 && (
                        <Card title="Resumen mensual">
                            <table className="w-full max-w-2xl">
                                <thead>
                                    <tr>
                                        {['Mes', 'ISR', 'IVA', 'Total'].map(
                                            (title) => (
                                                <th
                                                    key={title}
                                                    className={head}
                                                >
                                                    {title}
                                                </th>
                                            ),
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {withholdings.monthly.map((row) => (
                                        <tr
                                            key={row.month}
                                            className="border-t border-slate-100"
                                        >
                                            <td className={cell}>
                                                {row.month}
                                            </td>
                                            <td className={cell}>
                                                {money(row.isr_cents)}
                                            </td>
                                            <td className={cell}>
                                                {money(row.iva_cents)}
                                            </td>
                                            <td
                                                className={`${cell} font-semibold`}
                                            >
                                                {money(row.total_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Card>
                    )}
                </>
            )}
        </PageShell>
    );
}
