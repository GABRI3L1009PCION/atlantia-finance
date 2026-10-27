import { router, useForm } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    Badge,
    cell,
    Empty,
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

type Property = {
    id: number;
    name: string;
    registry: string | null;
    municipality: string;
    use: string;
    use_label: string;
    value_cents: number;
    is_active: boolean;
    rate_bp: number;
    annual_cents: number;
    law: string;
};

type Props = {
    company: Company;
    year: number;
    iusi: {
        properties: Property[];
        quarters: {
            quarter: number;
            label: string;
            due_on: string;
            law: string;
            tax_cents: number;
        }[];
    };
    uses: Record<string, string>;
    stamps: {
        rows: {
            id: number;
            date: string;
            description: string;
            counterparty: string | null;
            taxable_cents: number;
            rate_bp: number;
            tax_cents: number;
            voided: boolean;
        }[];
        monthly: {
            month: string;
            label: string;
            taxable_cents: number;
            tax_cents: number;
        }[];
        total_cents: number;
    };
    stampRate: number;
    contributions: {
        month: string;
        label: string;
        employees: number;
        igss_employee_cents: number;
        igss_employer_cents: number;
        irtra_cents: number;
        intecap_cents: number;
        total_cents: number;
        due_on: string;
    }[];
    iso: {
        estimate: {
            net_assets_cents: number;
            gross_income_cents: number;
            basis: string;
            base_cents: number;
            rate_bp: number;
            quarterly_cents: number;
            has_data: boolean;
        };
        quarters: {
            quarter: number;
            due_on: string;
            iso_cents: number;
            isr_cents: number;
            credit_used_cents: number;
            isr_to_pay_cents: number;
            credit_left_cents: number;
        }[];
    };
    canManage: boolean;
};

function Table({
    headers,
    children,
}: {
    headers: string[];
    children: ReactNode;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px]">
                <thead>
                    <tr>
                        {headers.map((title) => (
                            <th key={title} className={head}>
                                {title}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

export default function OtherTaxes({
    company,
    year,
    iusi,
    uses,
    stamps,
    stampRate,
    contributions,
    iso,
    canManage,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/other-taxes`;
    const [tab, setTab] = useState<'iusi' | 'stamps' | 'payroll' | 'iso'>(
        'iusi',
    );
    const property = useForm({
        name: '',
        registry: '',
        municipality: '',
        use: 'commercial',
        value: '',
    });
    const stamp = useForm({
        document_date: new Date().toISOString().slice(0, 10),
        description: '',
        counterparty: '',
        taxable: '',
    });
    const draft = (tax: 'iusi' | 'iso', quarter: number) =>
        router.post(
            `${base}/${tax}/draft`,
            { year, quarter },
            { preserveScroll: true },
        );

    return (
        <PageShell
            company={company}
            title="Otros impuestos"
            subtitle="IUSI, timbres fiscales, cuotas de planilla e ISO"
            icon={Landmark}
            actions={
                <form
                    className="flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            base,
                            {
                                year: String(
                                    new FormData(event.currentTarget).get(
                                        'year',
                                    ),
                                ),
                            },
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
                    <button className={secondary}>Año</button>
                </form>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat
                    label="IUSI del año"
                    value={money(
                        iusi.quarters.reduce((sum, q) => sum + q.tax_cents, 0),
                    )}
                />
                <Stat
                    label="Timbres fiscales del año"
                    value={money(stamps.total_cents)}
                />
                <Stat
                    label="IGSS, IRTRA e INTECAP del año"
                    value={money(
                        contributions.reduce(
                            (sum, row) => sum + row.total_cents,
                            0,
                        ),
                    )}
                />
                <Stat
                    label="ISO trimestral"
                    value={money(iso.estimate.quarterly_cents)}
                />
            </div>

            <div className="flex flex-wrap gap-2" role="tablist">
                {(
                    [
                        ['iusi', 'IUSI'],
                        ['stamps', 'Timbres fiscales'],
                        ['payroll', 'IGSS, IRTRA e INTECAP'],
                        ['iso', 'ISO'],
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

            {tab === 'iusi' && (
                <>
                    <Card title="Inmuebles de la empresa">
                        {iusi.properties.length === 0 ? (
                            <Empty>
                                Sin inmuebles registrados: la empresa no paga
                                IUSI.
                            </Empty>
                        ) : (
                            <Table
                                headers={[
                                    'Inmueble',
                                    'Municipio',
                                    'Uso',
                                    'Valor inscrito',
                                    'Tasa',
                                    'IUSI anual',
                                    '',
                                ]}
                            >
                                {iusi.properties.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {row.name}
                                            <p className="text-xs text-slate-500">
                                                {row.registry}
                                            </p>
                                        </td>
                                        <td className={cell}>
                                            {row.municipality}
                                        </td>
                                        <td className={cell}>
                                            {row.use_label}
                                        </td>
                                        <td className={cell}>
                                            {money(row.value_cents)}
                                        </td>
                                        <td className={cell}>
                                            {row.rate_bp / 10} por millar
                                            <p className="text-xs text-slate-500">
                                                {row.law}
                                            </p>
                                        </td>
                                        <td className={`${cell} font-semibold`}>
                                            {row.is_active ? (
                                                money(row.annual_cents)
                                            ) : (
                                                <Badge>De baja</Badge>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            {canManage && row.is_active && (
                                                <button
                                                    type="button"
                                                    className="text-xs font-semibold text-[#8f1f30] hover:underline"
                                                    onClick={() =>
                                                        router.delete(
                                                            `${base}/properties/${row.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Dar de baja
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                        {canManage && (
                            <form
                                className="mt-4 grid gap-3 md:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto]"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    property.post(`${base}/properties`, {
                                        preserveScroll: true,
                                        onSuccess: () => property.reset(),
                                    });
                                }}
                            >
                                <Label text="Inmueble">
                                    <input
                                        required
                                        className={field}
                                        value={property.data.name}
                                        onChange={(e) =>
                                            property.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Finca, folio y libro">
                                    <input
                                        className={field}
                                        value={property.data.registry}
                                        onChange={(e) =>
                                            property.setData(
                                                'registry',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Municipio">
                                    <input
                                        required
                                        className={field}
                                        value={property.data.municipality}
                                        onChange={(e) =>
                                            property.setData(
                                                'municipality',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Uso">
                                    <select
                                        className={field}
                                        value={property.data.use}
                                        onChange={(e) =>
                                            property.setData(
                                                'use',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {Object.entries(uses).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Label>
                                <Label text="Valor inscrito (Q)">
                                    <input
                                        required
                                        inputMode="decimal"
                                        className={field}
                                        value={property.data.value}
                                        onChange={(e) =>
                                            property.setData(
                                                'value',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <button
                                    className={`${primary} self-end`}
                                    disabled={property.processing}
                                >
                                    Agregar
                                </button>
                                <div className="md:col-span-6">
                                    <Errors errors={property.errors} />
                                </div>
                            </form>
                        )}
                    </Card>
                    <Card title={`IUSI por trimestre · ${year}`}>
                        <Table
                            headers={[
                                'Trimestre',
                                'Ley aplicada',
                                'Vence',
                                'IUSI',
                                '',
                            ]}
                        >
                            {iusi.quarters.map((row) => (
                                <tr
                                    key={row.quarter}
                                    className="border-t border-slate-100"
                                >
                                    <td className={cell}>{row.label}</td>
                                    <td className={cell}>{row.law}</td>
                                    <td className={cell}>{row.due_on}</td>
                                    <td className={`${cell} font-semibold`}>
                                        {money(row.tax_cents)}
                                    </td>
                                    <td className={cell}>
                                        {canManage && row.tax_cents > 0 && (
                                            <button
                                                type="button"
                                                className={secondary}
                                                onClick={() =>
                                                    draft('iusi', row.quarter)
                                                }
                                            >
                                                Registrar asiento
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        <p className="mt-2 text-xs text-slate-500">
                            Desde el 26 de noviembre de 2026 rige el Decreto
                            18-2026: la vivienda y el uso mixto pagan cero, y
                            los demás inmuebles 3, 6 o 9 por millar según su
                            valor. Cada trimestre usa la ley vigente el día que
                            empieza. Confirma el valor inscrito y las tasas con
                            la municipalidad.
                        </p>
                    </Card>
                </>
            )}

            {tab === 'stamps' && (
                <>
                    <Card title="Documentos afectos a timbres fiscales">
                        {canManage && (
                            <form
                                className="mb-4 grid gap-3 md:grid-cols-[1fr_2fr_1.5fr_1fr_auto]"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    stamp.post(`${base}/stamps`, {
                                        preserveScroll: true,
                                        onSuccess: () => stamp.reset(),
                                    });
                                }}
                            >
                                <Label text="Fecha">
                                    <input
                                        type="date"
                                        className={field}
                                        value={stamp.data.document_date}
                                        onChange={(e) =>
                                            stamp.setData(
                                                'document_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Documento">
                                    <input
                                        required
                                        placeholder="Contrato de arrendamiento"
                                        className={field}
                                        value={stamp.data.description}
                                        onChange={(e) =>
                                            stamp.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Otorgante o contraparte">
                                    <input
                                        className={field}
                                        value={stamp.data.counterparty}
                                        onChange={(e) =>
                                            stamp.setData(
                                                'counterparty',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Valor del documento (Q)">
                                    <input
                                        required
                                        inputMode="decimal"
                                        className={field}
                                        value={stamp.data.taxable}
                                        onChange={(e) =>
                                            stamp.setData(
                                                'taxable',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <button
                                    className={`${primary} self-end`}
                                    disabled={stamp.processing}
                                >
                                    Registrar
                                </button>
                                <p className="text-xs text-slate-500 md:col-span-5">
                                    Tarifa general del {stampRate / 100} %
                                    (Decreto 37-92). El asiento carga el gasto
                                    de timbres y abona timbres por pagar.
                                </p>
                                <div className="md:col-span-5">
                                    <Errors errors={stamp.errors} />
                                </div>
                            </form>
                        )}
                        {stamps.rows.length === 0 ? (
                            <Empty>Sin documentos afectos en el año.</Empty>
                        ) : (
                            <Table
                                headers={[
                                    'Fecha',
                                    'Documento',
                                    'Valor',
                                    'Timbres',
                                    '',
                                ]}
                            >
                                {stamps.rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>{row.date}</td>
                                        <td className={cell}>
                                            {row.description}
                                            <p className="text-xs text-slate-500">
                                                {row.counterparty}
                                            </p>
                                        </td>
                                        <td className={cell}>
                                            {money(row.taxable_cents)}
                                        </td>
                                        <td className={`${cell} font-semibold`}>
                                            {money(row.tax_cents)}
                                        </td>
                                        <td className={cell}>
                                            {row.voided ? (
                                                <Badge tone="red">
                                                    Anulado
                                                </Badge>
                                            ) : (
                                                canManage && (
                                                    <button
                                                        type="button"
                                                        className="text-xs font-semibold text-[#8f1f30] hover:underline"
                                                        onClick={() => {
                                                            const reason =
                                                                window.prompt(
                                                                    'Motivo de la anulación',
                                                                );

                                                            if (reason) {
                                                                router.post(
                                                                    `${base}/stamps/${row.id}/void`,
                                                                    {
                                                                        reason,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Anular
                                                    </button>
                                                )
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </Card>
                    {stamps.monthly.length > 0 && (
                        <Card title="Timbres por mes">
                            <Table headers={['Mes', 'Valor afecto', 'Timbres']}>
                                {stamps.monthly.map((row) => (
                                    <tr
                                        key={row.month}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>{row.label}</td>
                                        <td className={cell}>
                                            {money(row.taxable_cents)}
                                        </td>
                                        <td className={`${cell} font-semibold`}>
                                            {money(row.tax_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    )}
                </>
            )}

            {tab === 'payroll' && (
                <Card title="Cuotas de la planilla por mes">
                    {contributions.length === 0 ? (
                        <Empty>
                            Sin planillas aprobadas en el año. Las cuotas salen
                            de la nómina aprobada.
                        </Empty>
                    ) : (
                        <Table
                            headers={[
                                'Mes',
                                'Empleados',
                                'IGSS laboral',
                                'IGSS patronal',
                                'IRTRA',
                                'INTECAP',
                                'Total a pagar',
                                'Vence',
                            ]}
                        >
                            {contributions.map((row) => (
                                <tr
                                    key={row.month}
                                    className="border-t border-slate-100"
                                >
                                    <td className={cell}>{row.label}</td>
                                    <td className={cell}>{row.employees}</td>
                                    <td className={cell}>
                                        {money(row.igss_employee_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(row.igss_employer_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(row.irtra_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(row.intecap_cents)}
                                    </td>
                                    <td className={`${cell} font-semibold`}>
                                        {money(row.total_cents)}
                                    </td>
                                    <td className={cell}>{row.due_on}</td>
                                </tr>
                            ))}
                        </Table>
                    )}
                    <p className="mt-2 text-xs text-slate-500">
                        IGSS, IRTRA e INTECAP se pagan juntos en la planilla del
                        IGSS dentro de los primeros veinte días del mes
                        siguiente.
                    </p>
                </Card>
            )}

            {tab === 'iso' && (
                <>
                    <Card title={`Base del ISO ${year}`}>
                        {!iso.estimate.has_data ? (
                            <Empty>
                                Sin balance del año anterior: el ISO se calcula
                                sobre el activo neto o los ingresos brutos del
                                año previo.
                            </Empty>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-4">
                                <Stat
                                    label="Activo neto año anterior"
                                    value={money(iso.estimate.net_assets_cents)}
                                />
                                <Stat
                                    label="Ingresos brutos año anterior"
                                    value={money(
                                        iso.estimate.gross_income_cents,
                                    )}
                                />
                                <Stat
                                    label={`Base trimestral (${iso.estimate.basis === 'gross_income' ? 'ingresos' : 'activo'})`}
                                    value={money(iso.estimate.base_cents)}
                                />
                                <Stat
                                    label={`ISO trimestral (${iso.estimate.rate_bp / 100} %)`}
                                    value={money(iso.estimate.quarterly_cents)}
                                    tone="bad"
                                />
                            </div>
                        )}
                    </Card>
                    <Card title="ISO y su acreditamiento al ISR trimestral">
                        <Table
                            headers={[
                                'Trimestre',
                                'Vence',
                                'ISO',
                                'ISR trimestral',
                                'ISO acreditado',
                                'ISR a pagar',
                                'Crédito que queda',
                                '',
                            ]}
                        >
                            {iso.quarters.map((row) => (
                                <tr
                                    key={row.quarter}
                                    className="border-t border-slate-100"
                                >
                                    <td className={cell}>
                                        Trimestre {row.quarter}
                                    </td>
                                    <td className={cell}>{row.due_on}</td>
                                    <td className={cell}>
                                        {money(row.iso_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(row.isr_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(row.credit_used_cents)}
                                    </td>
                                    <td className={`${cell} font-semibold`}>
                                        {money(row.isr_to_pay_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(row.credit_left_cents)}
                                    </td>
                                    <td className={cell}>
                                        {canManage && row.iso_cents > 0 && (
                                            <button
                                                type="button"
                                                className={secondary}
                                                onClick={() =>
                                                    draft('iso', row.quarter)
                                                }
                                            >
                                                Registrar ISO
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        <p className="mt-2 text-xs text-slate-500">
                            El ISO pagado se acredita a los pagos trimestrales
                            de ISR del mismo año hasta agotarse. Es un cálculo
                            de referencia: confirma la opción de acreditamiento
                            elegida ante la SAT.
                        </p>
                    </Card>
                </>
            )}
        </PageShell>
    );
}
