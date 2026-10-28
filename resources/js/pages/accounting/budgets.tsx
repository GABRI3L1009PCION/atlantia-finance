import { router, useForm } from '@inertiajs/react';
import { Target } from 'lucide-react';
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
} from '@/components/accounting-ui';

type GridRow = {
    account_id: number;
    code: string;
    name: string;
    type: string;
    months: number[];
};

type ComparisonRow = {
    account_id: number;
    code: string;
    name: string;
    type: string;
    budget_month_cents: number;
    actual_month_cents: number;
    budget_ytd_cents: number;
    actual_ytd_cents: number;
    variance_cents: number;
    variance_bp: number | null;
    deviation: boolean;
};

type Props = {
    company: Company;
    budgets: {
        id: number;
        year: number;
        name: string;
        status: string;
        threshold_bp: number;
        income_cents: number;
        expense_cents: number;
    }[];
    budget: {
        id: number;
        year: number;
        name: string;
        status: string;
        threshold_bp: number;
        grid: GridRow[];
        comparison: {
            month: number;
            rows: ComparisonRow[];
            totals: {
                income_budget_cents: number;
                income_actual_cents: number;
                expense_budget_cents: number;
                expense_actual_cents: number;
                result_budget_cents: number;
                result_actual_cents: number;
            };
            deviations: number;
        };
    } | null;
    months: string[];
    canManage: boolean;
    year: number;
};

export default function Budgets({
    company,
    budgets,
    budget,
    months,
    canManage,
    year,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/budgets`;
    const [editing, setEditing] = useState(false);
    const [onlyUsed, setOnlyUsed] = useState(true);
    const [values, setValues] = useState<Record<number, string[]>>(() =>
        Object.fromEntries(
            (budget?.grid ?? []).map((row) => [
                row.account_id,
                row.months.map((cents) => (cents ? String(cents / 100) : '')),
            ]),
        ),
    );
    const create = useForm({
        year: String(year + 1),
        name: `Presupuesto ${year + 1}`,
        threshold: '10',
        base_year: String(year),
        growth: '5',
    });
    const visit = (params: Record<string, string | number>) =>
        router.get(
            base,
            { budget: budget?.id ?? '', ...params },
            { preserveState: true },
        );
    const gridRows = (budget?.grid ?? []).filter(
        (row) =>
            !onlyUsed ||
            (values[row.account_id] ?? []).some((value) => value !== ''),
    );

    return (
        <PageShell
            company={company}
            title="Presupuesto vs real"
            subtitle="Presupuesto anual contra lo real, con aviso de desviaciones"
            icon={Target}
            actions={
                budgets.length > 0 && (
                    <select
                        aria-label="Presupuesto"
                        className={`${field} !mt-0 w-64`}
                        value={budget?.id ?? ''}
                        onChange={(event) =>
                            visit({ budget: event.target.value })
                        }
                    >
                        {budgets.map((row) => (
                            <option key={row.id} value={row.id}>
                                {row.name} ·{' '}
                                {row.status === 'approved'
                                    ? 'Aprobado'
                                    : 'Borrador'}
                            </option>
                        ))}
                    </select>
                )
            }
        >
            {budget === null ? (
                <Empty>
                    La empresa aún no tiene presupuestos. Crea el primero abajo:
                    puede partir de lo real de un año anterior.
                </Empty>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Stat
                            label="Ingresos (presupuesto / real)"
                            value={`${money(budget.comparison.totals.income_budget_cents)} / ${money(budget.comparison.totals.income_actual_cents)}`}
                        />
                        <Stat
                            label="Gastos (presupuesto / real)"
                            value={`${money(budget.comparison.totals.expense_budget_cents)} / ${money(budget.comparison.totals.expense_actual_cents)}`}
                        />
                        <Stat
                            label="Resultado (presupuesto / real)"
                            value={`${money(budget.comparison.totals.result_budget_cents)} / ${money(budget.comparison.totals.result_actual_cents)}`}
                            tone={
                                budget.comparison.totals.result_actual_cents >=
                                budget.comparison.totals.result_budget_cents
                                    ? 'good'
                                    : 'bad'
                            }
                        />
                        <Stat
                            label={`Cuentas fuera del ${budget.threshold_bp / 100} %`}
                            value={budget.comparison.deviations}
                            tone={
                                budget.comparison.deviations === 0
                                    ? 'good'
                                    : 'bad'
                            }
                        />
                    </div>

                    <Card
                        title={`${budget.name} · acumulado a ${months[budget.comparison.month - 1]} ${budget.year}`}
                    >
                        <div className="mb-3 flex flex-wrap items-end gap-3">
                            <Label text="Hasta el mes">
                                <select
                                    className={`${field} w-32`}
                                    value={budget.comparison.month}
                                    onChange={(event) =>
                                        visit({ month: event.target.value })
                                    }
                                >
                                    {months.map((label, index) => (
                                        <option key={label} value={index + 1}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            {canManage && (
                                <>
                                    {budget.status === 'draft' ? (
                                        <>
                                            <button
                                                type="button"
                                                className={secondary}
                                                onClick={() =>
                                                    setEditing(!editing)
                                                }
                                            >
                                                {editing
                                                    ? 'Ver comparación'
                                                    : 'Editar montos'}
                                            </button>
                                            <button
                                                type="button"
                                                className={primary}
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/${budget.id}/approve`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Aprobar
                                            </button>
                                        </>
                                    ) : (
                                        <button
                                            type="button"
                                            className={secondary}
                                            onClick={() =>
                                                router.post(
                                                    `${base}/${budget.id}/reopen`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Devolver a borrador
                                        </button>
                                    )}
                                </>
                            )}
                            <Badge
                                tone={
                                    budget.status === 'approved'
                                        ? 'green'
                                        : 'amber'
                                }
                            >
                                {budget.status === 'approved'
                                    ? 'Aprobado'
                                    : 'Borrador'}
                            </Badge>
                        </div>

                        {editing && budget.status === 'draft' ? (
                            <>
                                <label className="mb-2 flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={onlyUsed}
                                        onChange={(event) =>
                                            setOnlyUsed(event.target.checked)
                                        }
                                    />
                                    Mostrar solo las cuentas con monto
                                </label>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[1300px]">
                                        <thead>
                                            <tr>
                                                <th className={head}>Cuenta</th>
                                                {months.map((label) => (
                                                    <th
                                                        key={label}
                                                        className={head}
                                                    >
                                                        {label}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {gridRows.map((row) => (
                                                <tr
                                                    key={row.account_id}
                                                    className="border-t border-slate-100"
                                                >
                                                    <td
                                                        className={`${cell} whitespace-nowrap`}
                                                    >
                                                        {row.code} {row.name}
                                                    </td>
                                                    {months.map((label, i) => (
                                                        <td
                                                            key={label}
                                                            className="px-1 py-1"
                                                        >
                                                            <input
                                                                aria-label={`${row.name} ${label}`}
                                                                inputMode="decimal"
                                                                className={`${field} !mt-0 w-24 text-right`}
                                                                value={
                                                                    values[
                                                                        row
                                                                            .account_id
                                                                    ]?.[i] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) => {
                                                                    const next =
                                                                        [
                                                                            ...(values[
                                                                                row
                                                                                    .account_id
                                                                            ] ??
                                                                                Array(
                                                                                    12,
                                                                                ).fill(
                                                                                    '',
                                                                                )),
                                                                        ];
                                                                    next[i] =
                                                                        event.target.value;
                                                                    setValues({
                                                                        ...values,
                                                                        [row.account_id]:
                                                                            next,
                                                                    });
                                                                }}
                                                            />
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <button
                                    type="button"
                                    className={`${primary} mt-3`}
                                    onClick={() =>
                                        router.put(
                                            `${base}/${budget.id}/lines`,
                                            { accounts: values },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Guardar montos
                                </button>
                            </>
                        ) : budget.comparison.rows.length === 0 ? (
                            <Empty>
                                Sin montos presupuestados ni movimientos en el
                                año.
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px]">
                                    <thead>
                                        <tr>
                                            {[
                                                'Cuenta',
                                                'Presupuesto del mes',
                                                'Real del mes',
                                                'Presupuesto acumulado',
                                                'Real acumulado',
                                                'Variación',
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
                                        {budget.comparison.rows.map((row) => (
                                            <tr
                                                key={row.account_id}
                                                className={`border-t border-slate-100 ${row.deviation ? 'bg-rose-50' : ''}`}
                                            >
                                                <td className={cell}>
                                                    {row.code} {row.name}
                                                    <span className="block text-xs text-slate-500">
                                                        {row.type === 'income'
                                                            ? 'Ingreso'
                                                            : 'Gasto'}
                                                    </span>
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        row.budget_month_cents,
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        row.actual_month_cents,
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        row.budget_ytd_cents,
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        row.actual_ytd_cents,
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    <Badge
                                                        tone={
                                                            row.deviation
                                                                ? 'red'
                                                                : 'slate'
                                                        }
                                                    >
                                                        {money(
                                                            row.variance_cents,
                                                        )}
                                                        {row.variance_bp !==
                                                            null &&
                                                            ` (${(row.variance_bp / 100).toFixed(1)} %)`}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </>
            )}

            {canManage && (
                <Card title="Nuevo presupuesto">
                    <form
                        className="grid gap-3 md:grid-cols-[100px_2fr_1fr_1fr_1fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            create.transform((data) => ({
                                ...data,
                                base_year: data.base_year || null,
                            }));
                            create.post(base);
                        }}
                    >
                        <Label text="Año">
                            <input
                                className={field}
                                value={create.data.year}
                                onChange={(e) =>
                                    create.setData('year', e.target.value)
                                }
                            />
                        </Label>
                        <Label text="Nombre">
                            <input
                                className={field}
                                value={create.data.name}
                                onChange={(e) =>
                                    create.setData('name', e.target.value)
                                }
                            />
                        </Label>
                        <Label text="Avisar si se desvía más de (%)">
                            <input
                                className={field}
                                value={create.data.threshold}
                                onChange={(e) =>
                                    create.setData('threshold', e.target.value)
                                }
                            />
                        </Label>
                        <Label text="Partir de lo real de (año)">
                            <input
                                className={field}
                                placeholder="Vacío: empezar en cero"
                                value={create.data.base_year}
                                onChange={(e) =>
                                    create.setData('base_year', e.target.value)
                                }
                            />
                        </Label>
                        <Label text="Más un crecimiento de (%)">
                            <input
                                className={field}
                                value={create.data.growth}
                                onChange={(e) =>
                                    create.setData('growth', e.target.value)
                                }
                            />
                        </Label>
                        <button
                            className={`${primary} self-end`}
                            disabled={create.processing}
                        >
                            Crear
                        </button>
                        <div className="md:col-span-6">
                            <Errors errors={create.errors} />
                        </div>
                    </form>
                </Card>
            )}
        </PageShell>
    );
}
