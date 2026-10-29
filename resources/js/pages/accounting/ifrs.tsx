import { router, useForm } from '@inertiajs/react';
import { BookOpenCheck } from 'lucide-react';
import { useState } from 'react';
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
    shortDate,
} from '@/components/accounting-ui';

type ScheduleRow = {
    month: string;
    payment_cents: number;
    interest_cents: number;
    principal_cents: number;
    liability_end_cents: number;
    depreciation_cents: number;
    right_of_use_end_cents: number;
};

type Props = {
    company: Company;
    leases: {
        id: number;
        name: string;
        starts_on: string;
        months: number;
        payment_cents: number;
        annual_rate_bp: number;
        initial_cents: number;
        liability_cents: number;
        right_of_use_cents: number;
        posted_through: string | null;
        schedule: ScheduleRow[];
    }[];
    paymentAccounts: { id: number; code: string; name: string }[];
    ecl: {
        as_of: string;
        buckets: {
            key: string;
            label: string;
            balance_cents: number;
            rate_bp: number;
            loss_cents: number;
        }[];
        required_cents: number;
        recorded_cents: number;
        adjustment_cents: number;
    };
    deferredTax: {
        year: number;
        rate_bp: number;
        deductible_cents: number;
        taxable_cents: number;
        asset_required_cents: number;
        liability_required_cents: number;
        asset_recorded_cents: number;
        liability_recorded_cents: number;
        asset_adjustment_cents: number;
        liability_adjustment_cents: number;
    };
    filters: { as_of: string; year: number };
    canManage: boolean;
    today: string;
};

export default function Ifrs({
    company,
    leases,
    paymentAccounts,
    ecl,
    deferredTax,
    filters,
    canManage,
    today,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/ifrs`;
    const [tab, setTab] = useState<'leases' | 'ecl' | 'deferred'>('leases');
    const [open, setOpen] = useState<number | null>(null);
    const lease = useForm({
        name: '',
        starts_on: today.slice(0, 8) + '01',
        months: '36',
        payment: '',
        annual_rate: '10',
        payment_account_id: String(paymentAccounts[0]?.id ?? ''),
    });
    const post = (path: string, data: Record<string, string | number>) =>
        router.post(`${base}/${path}`, data, { preserveScroll: true });

    return (
        <PageShell
            company={company}
            title="NIIF 16, NIIF 9 y NIC 12"
            subtitle="Arrendamientos, pérdida crediticia esperada e impuesto diferido"
            icon={BookOpenCheck}
        >
            <div className="flex flex-wrap gap-2" role="tablist">
                {(
                    [
                        ['leases', 'Arrendamientos (NIIF 16)'],
                        ['ecl', 'Pérdida esperada (NIIF 9)'],
                        ['deferred', 'Impuesto diferido (NIC 12)'],
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

            {tab === 'leases' && (
                <>
                    <Card title="Arrendamientos">
                        {leases.length === 0 ? (
                            <Empty>
                                Sin arrendamientos. Un local, una bodega o un
                                vehículo alquilado a más de un año se reconoce
                                como activo por derecho de uso y pasivo por
                                arrendamiento.
                            </Empty>
                        ) : (
                            <div className="space-y-3">
                                {leases.map((row) => (
                                    <article
                                        key={row.id}
                                        className="rounded-xl border border-slate-200 p-3"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p className="font-semibold">
                                                    {row.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    Desde{' '}
                                                    {shortDate(row.starts_on)} ·{' '}
                                                    {row.months} meses ·{' '}
                                                    {money(row.payment_cents)}{' '}
                                                    al mes ·{' '}
                                                    {row.annual_rate_bp / 100} %
                                                    anual · reconocido en{' '}
                                                    {money(row.initial_cents)}
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap gap-2 text-sm">
                                                <Badge>
                                                    Pasivo{' '}
                                                    {money(row.liability_cents)}
                                                </Badge>
                                                <Badge>
                                                    Derecho de uso{' '}
                                                    {money(
                                                        row.right_of_use_cents,
                                                    )}
                                                </Badge>
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() =>
                                                        setOpen(
                                                            open === row.id
                                                                ? null
                                                                : row.id,
                                                        )
                                                    }
                                                >
                                                    Tabla de amortización
                                                </button>
                                                {canManage && (
                                                    <button
                                                        type="button"
                                                        className={primary}
                                                        onClick={() =>
                                                            post(
                                                                `leases/${row.id}/post`,
                                                                {
                                                                    through:
                                                                        today.slice(
                                                                            0,
                                                                            7,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Registrar meses
                                                        pendientes
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Registrado hasta{' '}
                                            {row.posted_through ?? 'ningún mes'}
                                        </p>
                                        {open === row.id && (
                                            <div className="mt-3 max-h-80 overflow-auto">
                                                <table className="w-full min-w-[760px]">
                                                    <thead>
                                                        <tr>
                                                            {[
                                                                'Mes',
                                                                'Pago',
                                                                'Interés',
                                                                'Capital',
                                                                'Pasivo al cierre',
                                                                'Depreciación',
                                                                'Derecho de uso',
                                                            ].map((title) => (
                                                                <th
                                                                    key={title}
                                                                    className={
                                                                        head
                                                                    }
                                                                >
                                                                    {title}
                                                                </th>
                                                            ))}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {row.schedule.map(
                                                            (month) => (
                                                                <tr
                                                                    key={
                                                                        month.month
                                                                    }
                                                                    className="border-t border-slate-100"
                                                                >
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {
                                                                            month.month
                                                                        }
                                                                    </td>
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {money(
                                                                            month.payment_cents,
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {money(
                                                                            month.interest_cents,
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {money(
                                                                            month.principal_cents,
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {money(
                                                                            month.liability_end_cents,
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {money(
                                                                            month.depreciation_cents,
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className={
                                                                            cell
                                                                        }
                                                                    >
                                                                        {money(
                                                                            month.right_of_use_end_cents,
                                                                        )}
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </article>
                                ))}
                            </div>
                        )}
                    </Card>
                    {canManage && (
                        <Card title="Nuevo arrendamiento">
                            <form
                                className="grid gap-3 md:grid-cols-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    lease.post(`${base}/leases`, {
                                        preserveScroll: true,
                                        onSuccess: () => lease.reset('name'),
                                    });
                                }}
                            >
                                <Label text="Bien arrendado">
                                    <input
                                        className={field}
                                        placeholder="Local comercial zona 10"
                                        value={lease.data.name}
                                        onChange={(e) =>
                                            lease.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Inicio">
                                    <input
                                        type="date"
                                        className={field}
                                        value={lease.data.starts_on}
                                        onChange={(e) =>
                                            lease.setData(
                                                'starts_on',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Plazo (meses)">
                                    <input
                                        className={field}
                                        value={lease.data.months}
                                        onChange={(e) =>
                                            lease.setData(
                                                'months',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Renta mensual sin IVA (Q)">
                                    <input
                                        className={field}
                                        inputMode="decimal"
                                        value={lease.data.payment}
                                        onChange={(e) =>
                                            lease.setData(
                                                'payment',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Tasa de descuento anual (%)">
                                    <input
                                        className={field}
                                        inputMode="decimal"
                                        value={lease.data.annual_rate}
                                        onChange={(e) =>
                                            lease.setData(
                                                'annual_rate',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Cuenta con la que se paga">
                                    <select
                                        className={field}
                                        value={lease.data.payment_account_id}
                                        onChange={(e) =>
                                            lease.setData(
                                                'payment_account_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {paymentAccounts.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {account.code} {account.name}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                                <div className="md:col-span-3">
                                    <button
                                        className={primary}
                                        disabled={lease.processing}
                                    >
                                        Reconocer arrendamiento
                                    </button>
                                    <Errors errors={lease.errors} />
                                </div>
                            </form>
                        </Card>
                    )}
                </>
            )}

            {tab === 'ecl' && (
                <Card title={`Pérdida crediticia esperada al ${ecl.as_of}`}>
                    <div className="mb-3 grid gap-3 sm:grid-cols-3">
                        <Stat
                            label="Estimación requerida"
                            value={money(ecl.required_cents)}
                        />
                        <Stat
                            label="Estimación registrada (1108)"
                            value={money(ecl.recorded_cents)}
                        />
                        <Stat
                            label="Ajuste"
                            value={money(ecl.adjustment_cents)}
                            tone={ecl.adjustment_cents === 0 ? 'good' : 'bad'}
                        />
                    </div>
                    <div className="mb-3 flex flex-wrap items-end gap-3">
                        <Label text="A la fecha">
                            <input
                                type="date"
                                className={field}
                                defaultValue={filters.as_of}
                                onBlur={(e) =>
                                    router.get(
                                        base,
                                        { ...filters, as_of: e.target.value },
                                        { preserveState: true },
                                    )
                                }
                            />
                        </Label>
                        {canManage && ecl.adjustment_cents !== 0 && (
                            <button
                                type="button"
                                className={primary}
                                onClick={() =>
                                    post('expected-credit-loss', {
                                        as_of: ecl.as_of,
                                    })
                                }
                            >
                                Registrar ajuste
                            </button>
                        )}
                    </div>
                    <table className="w-full max-w-3xl">
                        <thead>
                            <tr>
                                {['Antigüedad', 'Saldo', 'Tasa', 'Pérdida'].map(
                                    (title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ),
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {ecl.buckets.map((bucket) => (
                                <tr
                                    key={bucket.key}
                                    className="border-t border-slate-100"
                                >
                                    <td className={cell}>{bucket.label}</td>
                                    <td className={cell}>
                                        {money(bucket.balance_cents)}
                                    </td>
                                    <td className={cell}>
                                        {bucket.rate_bp / 100} %
                                    </td>
                                    <td className={cell}>
                                        {money(bucket.loss_cents)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-2 text-xs text-slate-500">
                        Las tasas se cambian en Políticas contables según la
                        experiencia de cobro de la empresa.
                    </p>
                </Card>
            )}

            {tab === 'deferred' && (
                <Card title={`Impuesto diferido ${deferredTax.year}`}>
                    <div className="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Stat
                            label="Diferencias deducibles acumuladas"
                            value={money(deferredTax.deductible_cents)}
                        />
                        <Stat
                            label="Diferencias imponibles acumuladas"
                            value={money(deferredTax.taxable_cents)}
                        />
                        <Stat
                            label={`Activo diferido (${deferredTax.rate_bp / 100} %)`}
                            value={`${money(deferredTax.asset_required_cents)} · registrado ${money(deferredTax.asset_recorded_cents)}`}
                        />
                        <Stat
                            label="Pasivo diferido"
                            value={`${money(deferredTax.liability_required_cents)} · registrado ${money(deferredTax.liability_recorded_cents)}`}
                        />
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <Label text="Ejercicio">
                            <input
                                className={`${field} w-28`}
                                defaultValue={filters.year}
                                onBlur={(e) =>
                                    router.get(
                                        base,
                                        { ...filters, year: e.target.value },
                                        { preserveState: true },
                                    )
                                }
                            />
                        </Label>
                        {canManage &&
                            (deferredTax.asset_adjustment_cents !== 0 ||
                                deferredTax.liability_adjustment_cents !==
                                    0) && (
                                <button
                                    type="button"
                                    className={primary}
                                    onClick={() =>
                                        post('deferred-tax', {
                                            year: deferredTax.year,
                                        })
                                    }
                                >
                                    Registrar impuesto diferido
                                </button>
                            )}
                    </div>
                    <p className="mt-3 text-xs text-slate-500">
                        Sale de los ajustes temporarios de la conciliación del
                        ISR (ISR y retenciones): un gasto que hoy no se deduce y
                        se deducirá después genera activo; un ingreso que se
                        gravará después genera pasivo.
                    </p>
                </Card>
            )}
        </PageShell>
    );
}
