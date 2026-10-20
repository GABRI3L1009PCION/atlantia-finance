import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronDown,
    CircleDollarSign,
    Clock3,
    Gauge,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

type Row = {
    company_id: number;
    public_id: string;
    name: string;
    office: string;
    has_contract: boolean;
    fee_cents: number;
    budget_minutes: number;
    minutes: number;
    deviation_minutes: number | null;
    deviation_pct: number | null;
    cost_cents: number;
    margin_cents: number;
    margin_pct: number | null;
    people: { name: string; minutes: number; cost_cents: number }[];
    flag: 'ok' | 'over_budget' | 'loss' | 'no_contract';
};

type Props = {
    month: string;
    rows: Row[];
    totals: {
        fee_cents: number;
        budget_minutes: number;
        minutes: number;
        cost_cents: number;
        margin_cents: number;
        margin_pct: number | null;
    };
    offices: { id: number; name: string; hourly_cost_cents: number }[];
    canManage: boolean;
    overrunThreshold: number;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const field =
    'h-10 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const button =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#8a0b0b] px-4 text-sm font-bold text-white disabled:opacity-60';
const money = (cents: number) =>
    `Q${(cents / 100).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const hours = (minutes: number) =>
    `${(minutes / 60).toLocaleString('es-GT', { maximumFractionDigits: 2 })} h`;
const FLAGS = {
    ok: { label: 'En rango', tone: 'bg-emerald-50 text-emerald-700' },
    over_budget: {
        label: 'Horas excedidas',
        tone: 'bg-amber-50 text-amber-800',
    },
    loss: { label: 'Margen negativo', tone: 'bg-red-50 text-red-700' },
    no_contract: { label: 'Sin contrato', tone: 'bg-slate-100 text-slate-600' },
} as const;

function Kpi({
    icon,
    label,
    value,
    hint,
    bad,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    hint: string;
    bad?: boolean;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 !p-4`}>
            <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-[#f6e6e4] text-[#8a0b0b]">
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-xs font-bold text-[#6b1d1d]">
                    {label}
                </p>
                <p
                    className={`text-2xl leading-tight font-black ${bad ? 'text-red-700' : 'text-[#061633]'}`}
                >
                    {value}
                </p>
                <p className="truncate text-xs font-semibold text-slate-500">
                    {hint}
                </p>
            </div>
        </div>
    );
}

export default function Profitability({
    month,
    rows,
    totals,
    offices,
    canManage,
    overrunThreshold,
}: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const [budgets, setBudgets] = useState<Record<number, string>>({});
    const [costs, setCosts] = useState<Record<number, string>>({});
    const go = (value: string) =>
        router.get(
            '/office/profitability',
            { month: value },
            { preserveScroll: true },
        );
    const saveBudget = (event: FormEvent, row: Row) => {
        event.preventDefault();
        router.patch(
            '/office/profitability/budget',
            {
                company_id: row.company_id,
                hours: budgets[row.company_id] ?? '',
            },
            { preserveScroll: true },
        );
    };
    const saveCost = (event: FormEvent, officeId: number) => {
        event.preventDefault();
        router.patch(
            '/office/profitability/cost',
            { office_id: officeId, cost: costs[officeId] ?? '' },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Rentabilidad por cliente" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#061633] sm:p-6">
                <div className="mx-auto max-w-7xl space-y-5">
                    <header className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="text-xs font-black tracking-[0.2em] text-[#9b5757] uppercase">
                                Dirección
                            </p>
                            <h1 className="mt-1 text-3xl font-black tracking-tight">
                                Rentabilidad por cliente
                            </h1>
                            <p className="mt-1 max-w-3xl text-sm text-slate-600">
                                Honorario pactado contra las horas reales del
                                equipo y su costo interno. Es un margen antes de
                                gastos generales, no la utilidad neta, y un
                                honorario pactado no es dinero cobrado.
                            </p>
                        </div>
                        <input
                            type="month"
                            value={month}
                            onChange={(event) => go(event.target.value)}
                            className={field}
                            aria-label="Mes"
                        />
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Kpi
                            icon={<CircleDollarSign className="size-6" />}
                            label="Honorarios pactados"
                            value={money(totals.fee_cents)}
                            hint={`${rows.length} cliente(s)`}
                        />
                        <Kpi
                            icon={<Clock3 className="size-6" />}
                            label="Horas reales"
                            value={hours(totals.minutes)}
                            hint={
                                totals.budget_minutes > 0
                                    ? `${hours(totals.budget_minutes)} presupuestadas`
                                    : 'Sin horas presupuestadas'
                            }
                            bad={
                                totals.budget_minutes > 0 &&
                                totals.minutes > totals.budget_minutes
                            }
                        />
                        <Kpi
                            icon={<Gauge className="size-6" />}
                            label="Costo directo"
                            value={money(totals.cost_cents)}
                            hint="Horas por costo interno"
                        />
                        <Kpi
                            icon={<TrendingUp className="size-6" />}
                            label="Margen antes de gastos generales"
                            value={money(totals.margin_cents)}
                            hint={
                                totals.margin_pct === null
                                    ? 'Sin honorarios'
                                    : `${totals.margin_pct}% del honorario`
                            }
                            bad={totals.margin_cents < 0}
                        />
                    </section>

                    <section className={`${card} overflow-x-auto !p-0`}>
                        <table className="w-full min-w-[980px] text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-xs font-bold tracking-wide text-slate-500 uppercase">
                                    {[
                                        'Cliente',
                                        'Honorario',
                                        'Horas presup.',
                                        'Horas reales',
                                        'Desviación',
                                        'Costo directo',
                                        'Margen',
                                        'Estado',
                                        '',
                                    ].map((title) => (
                                        <th key={title} className="px-4 py-3">
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="p-8 text-center text-slate-500"
                                        >
                                            Aún no hay clientes visibles para
                                            ti.
                                        </td>
                                    </tr>
                                )}
                                {rows.map((row) => (
                                    <>
                                        <tr
                                            key={row.company_id}
                                            className="border-b border-slate-100"
                                        >
                                            <td className="px-4 py-3">
                                                <b className="block">
                                                    {row.name}
                                                </b>
                                                <span className="text-xs text-slate-500">
                                                    {row.office}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.has_contract
                                                    ? money(row.fee_cents)
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {canManage &&
                                                row.has_contract ? (
                                                    <form
                                                        onSubmit={(event) =>
                                                            saveBudget(
                                                                event,
                                                                row,
                                                            )
                                                        }
                                                        className="flex items-center gap-1"
                                                    >
                                                        <input
                                                            className={`${field} !h-8 w-20`}
                                                            inputMode="decimal"
                                                            aria-label={`Horas presupuestadas de ${row.name}`}
                                                            value={
                                                                budgets[
                                                                    row
                                                                        .company_id
                                                                ] ??
                                                                String(
                                                                    row.budget_minutes /
                                                                        60,
                                                                )
                                                            }
                                                            onChange={(event) =>
                                                                setBudgets({
                                                                    ...budgets,
                                                                    [row.company_id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                        />
                                                        <button className="text-xs font-bold text-[#8a0b0b]">
                                                            Guardar
                                                        </button>
                                                    </form>
                                                ) : row.budget_minutes > 0 ? (
                                                    hours(row.budget_minutes)
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-semibold">
                                                {hours(row.minutes)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.deviation_minutes ===
                                                null ? (
                                                    '—'
                                                ) : (
                                                    <span
                                                        className={
                                                            (row.deviation_pct ??
                                                                0) >
                                                            overrunThreshold
                                                                ? 'font-bold text-amber-700'
                                                                : ''
                                                        }
                                                    >
                                                        {row.deviation_minutes >
                                                        0
                                                            ? '+'
                                                            : ''}
                                                        {hours(
                                                            row.deviation_minutes,
                                                        )}{' '}
                                                        ({row.deviation_pct}%)
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {money(row.cost_cents)}
                                            </td>
                                            <td
                                                className={`px-4 py-3 font-semibold ${row.margin_cents < 0 ? 'text-red-700' : ''}`}
                                            >
                                                {money(row.margin_cents)}
                                                {row.margin_pct !== null && (
                                                    <span className="block text-xs font-normal text-slate-500">
                                                        {row.margin_pct}%
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${FLAGS[row.flag].tone}`}
                                                >
                                                    {FLAGS[row.flag].label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setOpen(
                                                            open ===
                                                                row.company_id
                                                                ? null
                                                                : row.company_id,
                                                        )
                                                    }
                                                    className="inline-flex items-center gap-1 text-xs font-bold text-[#8a0b0b]"
                                                >
                                                    Quién{' '}
                                                    <ChevronDown className="size-3" />
                                                </button>
                                            </td>
                                        </tr>
                                        {open === row.company_id && (
                                            <tr
                                                key={`${row.company_id}-people`}
                                            >
                                                <td
                                                    colSpan={9}
                                                    className="bg-[#fbf8f8] px-4 py-3"
                                                >
                                                    {row.people.length === 0 ? (
                                                        <p className="text-sm text-slate-500">
                                                            Nadie registró horas
                                                            en este cliente
                                                            durante el mes.
                                                        </p>
                                                    ) : (
                                                        <ul className="grid gap-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                                            {row.people.map(
                                                                (person) => (
                                                                    <li
                                                                        key={
                                                                            person.name
                                                                        }
                                                                        className="flex justify-between gap-3 rounded-lg bg-white px-3 py-2"
                                                                    >
                                                                        <span>
                                                                            {
                                                                                person.name
                                                                            }
                                                                        </span>
                                                                        <span className="text-slate-600">
                                                                            {hours(
                                                                                person.minutes,
                                                                            )}{' '}
                                                                            ·{' '}
                                                                            {money(
                                                                                person.cost_cents,
                                                                            )}
                                                                        </span>
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                ))}
                            </tbody>
                        </table>
                    </section>

                    {rows.some(
                        (row) =>
                            row.flag === 'loss' || row.flag === 'over_budget',
                    ) && (
                        <p className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            Hay clientes con margen negativo o con más de{' '}
                            {overrunThreshold}% de horas sobre lo presupuestado.
                            Revisa quién dedica el tiempo y si el honorario o el
                            alcance del servicio deben ajustarse.
                        </p>
                    )}

                    <section className={card}>
                        <h2 className="text-lg font-black">
                            Costo interno por hora
                        </h2>
                        <p className="mb-3 text-sm text-slate-600">
                            Es el costo supuesto de una hora de trabajo del
                            equipo en cada oficina. Se copia a cada registro de
                            horas, así que cambiarlo no altera meses anteriores.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {offices.map((office) => (
                                <form
                                    key={office.id}
                                    onSubmit={(event) =>
                                        saveCost(event, office.id)
                                    }
                                    className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3"
                                >
                                    <span className="text-sm font-semibold">
                                        {office.name}
                                    </span>
                                    {canManage ? (
                                        <span className="flex items-center gap-1">
                                            <input
                                                className={`${field} !h-8 w-24`}
                                                inputMode="decimal"
                                                aria-label={`Costo por hora de ${office.name}`}
                                                value={
                                                    costs[office.id] ??
                                                    (
                                                        office.hourly_cost_cents /
                                                        100
                                                    ).toFixed(2)
                                                }
                                                onChange={(event) =>
                                                    setCosts({
                                                        ...costs,
                                                        [office.id]:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <button
                                                className={`${button} !h-8 !px-3 !text-xs`}
                                            >
                                                Guardar
                                            </button>
                                        </span>
                                    ) : (
                                        <span className="text-sm">
                                            {money(office.hourly_cost_cents)}
                                        </span>
                                    )}
                                </form>
                            ))}
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
