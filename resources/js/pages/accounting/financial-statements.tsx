import { router, useForm } from '@inertiajs/react';
import { Download, FileChartColumn, Plus, Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
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
    StatementReviewCard,
    StatementSignersCard,
} from '@/components/statement-review';
import type {
    StatementReview,
    StatementSigners,
} from '@/components/statement-review';

type Line = {
    code: string;
    name: string;
    cents: number;
    previous_cents?: number;
};
type Position = Record<
    | 'current_assets'
    | 'non_current_assets'
    | 'current_liabilities'
    | 'non_current_liabilities'
    | 'equity',
    Line[]
> & {
    result_cents: number;
    totals: Record<string, number>;
};
type Income = Record<
    'revenue' | 'cost_of_sales' | 'operating_expenses',
    Line[]
> & { totals: Record<string, number> };
type CashFlow = {
    operating: {
        net_income: number;
        depreciation: number;
        working_capital: Line[];
        total: number;
    };
    investing: { rows: Line[]; total: number };
    financing: { rows: Line[]; total: number };
    totals: {
        net_change: number;
        cash_open: number;
        cash_close: number;
        cash_in: number;
        cash_out: number;
        cash_change: number;
        difference: number;
    };
    previous?: Record<string, number>;
};
type Equity = {
    columns: string[];
    rows: {
        label: string;
        capital_cents: number;
        retained_cents: number;
        period_result_cents: number;
        total_cents: number;
    }[];
    check_cents: number;
};
type StatementSet = {
    period: { from: string; to: string };
    previous_period: { from: string; to: string } | null;
    position: Position;
    income: Income;
    cash_flow: CashFlow;
    equity: Equity;
};
type Note = {
    number: number;
    title: string;
    body: string;
    version: number;
    status: string;
    published_version: number | null;
    versions: { version: number; status: string; at: string }[];
};
type Ratio = {
    key: string;
    label: string;
    kind: 'percent' | 'times';
    help: string;
    value: number | null;
    previous: number | null;
    change: number | null;
};
type Month = {
    month: string;
    label: string;
    income_cents: number;
    expense_cents: number;
    result_cents: number;
};

type Props = {
    company: Company;
    filters: { year: number; from: string; to: string; compare: string | null };
    set: StatementSet;
    notes: Note[];
    ratios: { ratios: Ratio[]; figures: Record<string, number> };
    monthly: Month[];
    canManageNotes: boolean;
    canExport: boolean;
    review: StatementReview;
    signers: StatementSigners;
    accountantTitles: string[];
    signerTitles: string[];
    canSign: boolean;
};

const tabs = [
    ['position', 'Situación financiera'],
    ['income', 'Resultados'],
    ['cash_flow', 'Flujos de efectivo'],
    ['equity', 'Patrimonio'],
    ['notes', 'Notas'],
    ['ratios', 'Indicadores'],
] as const;

function Block({
    title,
    lines,
    hasPrevious,
}: {
    title: string;
    lines: Line[];
    hasPrevious: boolean;
}) {
    const total = lines.reduce((sum, line) => sum + line.cents, 0);
    const previous = lines.reduce(
        (sum, line) => sum + (line.previous_cents ?? 0),
        0,
    );

    return (
        <>
            <tr className="bg-[#fbf4f4]">
                <td
                    className="px-3 py-2 text-xs font-bold tracking-wide uppercase"
                    colSpan={hasPrevious ? 3 : 2}
                >
                    {title}
                </td>
            </tr>
            {lines.map((line) => (
                <tr key={line.code} className="border-t border-slate-100">
                    <td className={cell}>
                        <span className="mr-2 font-mono text-xs text-slate-400">
                            {line.code}
                        </span>
                        {line.name}
                    </td>
                    <td className={`${cell} text-right`}>
                        {money(line.cents)}
                    </td>
                    {hasPrevious && (
                        <td className={`${cell} text-right text-slate-500`}>
                            {money(line.previous_cents ?? 0)}
                        </td>
                    )}
                </tr>
            ))}
            <tr className="border-t border-slate-300 font-semibold">
                <td className={cell}>Total {title.toLowerCase()}</td>
                <td className={`${cell} text-right`}>{money(total)}</td>
                {hasPrevious && (
                    <td className={`${cell} text-right text-slate-500`}>
                        {money(previous)}
                    </td>
                )}
            </tr>
        </>
    );
}

function Sheet({
    hasPrevious,
    previousLabel,
    children,
}: {
    hasPrevious: boolean;
    previousLabel?: string;
    children: ReactNode;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[520px]">
                <thead>
                    <tr>
                        <th className={head}>Concepto</th>
                        <th className={`${head} text-right`}>Período actual</th>
                        {hasPrevious && (
                            <th className={`${head} text-right`}>
                                {previousLabel}
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

function Total({
    label,
    current,
    previous,
    hasPrevious,
    strong,
}: {
    label: string;
    current: number;
    previous?: number;
    hasPrevious: boolean;
    strong?: boolean;
}) {
    return (
        <tr
            className={`border-t-2 border-slate-300 ${strong ? 'bg-[#f7f1f1] font-bold' : 'font-semibold'}`}
        >
            <td className={cell}>{label}</td>
            <td className={`${cell} text-right`}>{money(current)}</td>
            {hasPrevious && (
                <td className={`${cell} text-right text-slate-500`}>
                    {previous === undefined ? '' : money(previous)}
                </td>
            )}
        </tr>
    );
}

function NoteEditor({
    base,
    year,
    note,
    canManage,
}: {
    base: string;
    year: number;
    note: Note | null;
    canManage: boolean;
}) {
    const form = useForm({
        year,
        number: note?.number ?? '',
        title: note?.title ?? '',
        body: note?.body ?? '',
    });

    return (
        <form
            className="space-y-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${base}/notes`, {
                    preserveScroll: true,
                    onSuccess: () =>
                        note === null && form.reset('title', 'body'),
                });
            }}
        >
            <input
                className={field}
                placeholder="Título de la nota"
                disabled={!canManage}
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
                required
            />
            <textarea
                className={field}
                rows={note ? 6 : 4}
                placeholder="Contenido"
                disabled={!canManage}
                value={form.data.body}
                onChange={(e) => form.setData('body', e.target.value)}
                required
            />
            <Errors errors={form.errors} />
            {canManage && (
                <button className={secondary} disabled={form.processing}>
                    {note ? (
                        'Guardar cambios'
                    ) : (
                        <>
                            <Plus className="size-4" /> Agregar nota
                        </>
                    )}
                </button>
            )}
        </form>
    );
}

export default function FinancialStatements({
    company,
    filters,
    set,
    notes,
    ratios,
    monthly,
    canManageNotes,
    canExport,
    review,
    signers,
    accountantTitles,
    signerTitles,
    canSign,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/financial-statements`;
    const [tab, setTab] = useState<(typeof tabs)[number][0]>('position');
    const [range, setRange] = useState({
        from: filters.from,
        to: filters.to,
        compare: filters.compare ?? '',
        year: String(filters.year),
    });
    const { position, income, cash_flow: flow, equity } = set;
    const hasPrevious = set.previous_period !== null;
    const previousLabel = set.previous_period
        ? `Al ${set.previous_period.to}`
        : undefined;
    const balanced = position.totals.difference === 0;
    const maxMonth = Math.max(
        1,
        ...monthly.map((month) =>
            Math.max(month.income_cents, month.expense_cents),
        ),
    );
    const exportStatement =
        tab === 'position' ||
        tab === 'income' ||
        tab === 'cash_flow' ||
        tab === 'notes'
            ? tab
            : null;
    const format = (ratio: Ratio, value: number | null) =>
        value === null
            ? '—'
            : ratio.kind === 'percent'
              ? `${(value * 100).toFixed(1)} %`
              : `${value.toFixed(2)} veces`;

    return (
        <PageShell
            company={company}
            title="Estados financieros"
            subtitle={`Del ${set.period.from} al ${set.period.to}`}
            icon={FileChartColumn}
            actions={
                canExport &&
                exportStatement && (
                    <>
                        <a
                            className={secondary}
                            href={exportUrl(`${base}/export`, {
                                statement: exportStatement,
                                format: 'pdf',
                                year: String(filters.year),
                                from: filters.from,
                                to: filters.to,
                                compare: filters.compare ?? '',
                            })}
                        >
                            <Download className="size-4" /> PDF
                        </a>
                        <a
                            className={secondary}
                            href={exportUrl(`${base}/export`, {
                                statement: exportStatement,
                                format: 'xlsx',
                                year: String(filters.year),
                                from: filters.from,
                                to: filters.to,
                                compare: filters.compare ?? '',
                            })}
                        >
                            <Download className="size-4" /> Excel
                        </a>
                    </>
                )
            }
        >
            <Card title="Período y comparativo">
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            base,
                            { ...range, compare: range.compare || undefined },
                            { preserveState: true },
                        );
                    }}
                >
                    <Label text="Ejercicio">
                        <input
                            type="number"
                            className={`${field} w-28`}
                            value={range.year}
                            onChange={(e) =>
                                setRange({ ...range, year: e.target.value })
                            }
                        />
                    </Label>
                    <Label text="Desde">
                        <input
                            type="date"
                            className={field}
                            value={range.from}
                            onChange={(e) =>
                                setRange({ ...range, from: e.target.value })
                            }
                        />
                    </Label>
                    <Label text="Hasta">
                        <input
                            type="date"
                            className={field}
                            value={range.to}
                            onChange={(e) =>
                                setRange({ ...range, to: e.target.value })
                            }
                        />
                    </Label>
                    <Label text="Comparar con">
                        <select
                            className={field}
                            value={range.compare}
                            onChange={(e) =>
                                setRange({ ...range, compare: e.target.value })
                            }
                        >
                            <option value="">Sin comparativo</option>
                            <option value="previous_period">
                                Período anterior
                            </option>
                            <option value="previous_year">
                                Mismo período del año anterior
                            </option>
                        </select>
                    </Label>
                    <button className={primary}>Consultar</button>
                </form>
            </Card>

            <StatementReviewCard review={review} />
            {canSign && (
                <StatementSignersCard
                    action={`${base}/signers`}
                    signers={signers}
                    accountantTitles={accountantTitles}
                    signerTitles={signerTitles}
                />
            )}

            <div className="flex flex-wrap gap-2" role="tablist">
                {tabs.map(([key, label]) => (
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

            {tab === 'position' && (
                <Card title="Estado de situación financiera">
                    <div className="mb-4 flex items-center gap-3">
                        {balanced ? (
                            <Badge tone="green">
                                Cuadra: activo = pasivo + patrimonio
                            </Badge>
                        ) : (
                            <Badge tone="red">
                                Descuadre de {money(position.totals.difference)}
                            </Badge>
                        )}
                    </div>
                    <Sheet
                        hasPrevious={hasPrevious}
                        previousLabel={previousLabel}
                    >
                        <Block
                            title="Activo corriente"
                            lines={position.current_assets}
                            hasPrevious={hasPrevious}
                        />
                        <Block
                            title="Activo no corriente"
                            lines={position.non_current_assets}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Total activo"
                            current={position.totals.assets}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        <Block
                            title="Pasivo corriente"
                            lines={position.current_liabilities}
                            hasPrevious={hasPrevious}
                        />
                        <Block
                            title="Pasivo no corriente"
                            lines={position.non_current_liabilities}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Total pasivo"
                            current={position.totals.liabilities}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        <Block
                            title="Patrimonio"
                            lines={position.equity}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Resultado del período"
                            current={position.result_cents}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Total pasivo y patrimonio"
                            current={position.totals.liabilities_and_equity}
                            hasPrevious={hasPrevious}
                            strong
                        />
                    </Sheet>
                </Card>
            )}

            {tab === 'income' && (
                <Card title="Estado de resultado integral">
                    <Sheet
                        hasPrevious={hasPrevious}
                        previousLabel={previousLabel}
                    >
                        <Block
                            title="Ingresos"
                            lines={income.revenue}
                            hasPrevious={hasPrevious}
                        />
                        <Block
                            title="Costo de ventas"
                            lines={income.cost_of_sales}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Utilidad bruta"
                            current={income.totals.gross_profit}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        <Block
                            title="Gastos de operación"
                            lines={income.operating_expenses}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Resultado operativo"
                            current={income.totals.operating_result}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Resultado neto"
                            current={income.totals.net_result}
                            hasPrevious={hasPrevious}
                            strong
                        />
                    </Sheet>
                </Card>
            )}

            {tab === 'cash_flow' && (
                <Card title="Estado de flujos de efectivo (método indirecto)">
                    <div className="mb-4 flex items-center gap-3">
                        {flow.totals.difference === 0 ? (
                            <Badge tone="green">
                                Concilia con el efectivo en bancos y caja
                            </Badge>
                        ) : (
                            <Badge tone="red">
                                Diferencia de control{' '}
                                {money(flow.totals.difference)}
                            </Badge>
                        )}
                    </div>
                    <Sheet
                        hasPrevious={hasPrevious}
                        previousLabel={previousLabel}
                    >
                        <tr className="bg-[#fbf4f4]">
                            <td
                                className="px-3 py-2 text-xs font-bold uppercase"
                                colSpan={hasPrevious ? 3 : 2}
                            >
                                Actividades de operación
                            </td>
                        </tr>
                        <Total
                            label="Resultado del período"
                            current={flow.operating.net_income}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Depreciación y amortización"
                            current={flow.operating.depreciation}
                            hasPrevious={hasPrevious}
                        />
                        {flow.operating.working_capital.map((line) => (
                            <tr
                                key={line.code}
                                className="border-t border-slate-100"
                            >
                                <td className={cell}>{line.name}</td>
                                <td className={`${cell} text-right`}>
                                    {money(line.cents)}
                                </td>
                                {hasPrevious && <td />}
                            </tr>
                        ))}
                        <Total
                            label="Flujo de operación"
                            current={flow.operating.total}
                            previous={flow.previous?.operating}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        {flow.investing.rows.map((line) => (
                            <tr
                                key={line.code}
                                className="border-t border-slate-100"
                            >
                                <td className={cell}>{line.name}</td>
                                <td className={`${cell} text-right`}>
                                    {money(line.cents)}
                                </td>
                                {hasPrevious && <td />}
                            </tr>
                        ))}
                        <Total
                            label="Flujo de inversión"
                            current={flow.investing.total}
                            previous={flow.previous?.investing}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        {flow.financing.rows.map((line) => (
                            <tr
                                key={line.code}
                                className="border-t border-slate-100"
                            >
                                <td className={cell}>{line.name}</td>
                                <td className={`${cell} text-right`}>
                                    {money(line.cents)}
                                </td>
                                {hasPrevious && <td />}
                            </tr>
                        ))}
                        <Total
                            label="Flujo de financiamiento"
                            current={flow.financing.total}
                            previous={flow.previous?.financing}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        <Total
                            label="Variación neta del efectivo"
                            current={flow.totals.net_change}
                            previous={flow.previous?.net_change}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Efectivo al inicio"
                            current={flow.totals.cash_open}
                            previous={flow.previous?.cash_open}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Efectivo al final"
                            current={flow.totals.cash_close}
                            previous={flow.previous?.cash_close}
                            hasPrevious={hasPrevious}
                            strong
                        />
                        <Total
                            label="Entradas de efectivo del período"
                            current={flow.totals.cash_in}
                            previous={flow.previous?.cash_in}
                            hasPrevious={hasPrevious}
                        />
                        <Total
                            label="Salidas de efectivo del período"
                            current={flow.totals.cash_out}
                            previous={flow.previous?.cash_out}
                            hasPrevious={hasPrevious}
                        />
                    </Sheet>
                </Card>
            )}

            {tab === 'equity' && (
                <Card title="Estado de cambios en el patrimonio">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px]">
                            <thead>
                                <tr>
                                    <th className={head}>Movimiento</th>
                                    {equity.columns.map((column) => (
                                        <th
                                            key={column}
                                            className={`${head} text-right`}
                                        >
                                            {column}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {equity.rows.map((row) => (
                                    <tr
                                        key={row.label}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>{row.label}</td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.capital_cents)}
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.retained_cents)}
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.period_result_cents)}
                                        </td>
                                        <td
                                            className={`${cell} text-right font-semibold`}
                                        >
                                            {money(row.total_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-3 text-xs text-slate-500">
                        Verificación de saldos:{' '}
                        {equity.check_cents === 0
                            ? 'sin diferencias.'
                            : `diferencia de ${money(equity.check_cents)}.`}
                    </p>
                </Card>
            )}

            {tab === 'notes' && (
                <Card
                    title={`Notas a los estados financieros · ejercicio ${filters.year}`}
                >
                    {notes.length === 0 ? (
                        <div className="space-y-3">
                            <Empty>El ejercicio aún no tiene notas.</Empty>
                            {canManageNotes && (
                                <button
                                    className={primary}
                                    onClick={() =>
                                        router.post(
                                            `${base}/notes/seed`,
                                            { year: filters.year },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Sparkles className="size-4" /> Generar
                                    notas con las cifras de la empresa
                                </button>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-5">
                            {notes.map((note) => (
                                <div
                                    key={note.number}
                                    className="rounded-xl border border-slate-200 p-4"
                                >
                                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-semibold">
                                            Nota {note.number}
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <Badge>
                                                Versión {note.version}
                                            </Badge>
                                            {note.status === 'published' ? (
                                                <Badge tone="green">
                                                    Publicada
                                                </Badge>
                                            ) : (
                                                <Badge tone="amber">
                                                    Borrador
                                                    {note.published_version
                                                        ? ` · vigente v${note.published_version}`
                                                        : ''}
                                                </Badge>
                                            )}
                                            {canManageNotes &&
                                                note.status !== 'published' && (
                                                    <button
                                                        className="text-xs font-semibold text-[#640000]"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/notes/${note.number}/publish`,
                                                                {
                                                                    year: filters.year,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Publicar
                                                    </button>
                                                )}
                                        </div>
                                    </div>
                                    <NoteEditor
                                        key={`${note.number}-${note.version}`}
                                        base={base}
                                        year={filters.year}
                                        note={note}
                                        canManage={canManageNotes}
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                    {canManageNotes && notes.length > 0 && (
                        <div className="mt-6 border-t border-slate-100 pt-5">
                            <p className="mb-2 text-sm font-semibold">
                                Agregar nota
                            </p>
                            <NoteEditor
                                base={base}
                                year={filters.year}
                                note={null}
                                canManage
                            />
                        </div>
                    )}
                </Card>
            )}

            {tab === 'ratios' && (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {ratios.ratios.slice(0, 4).map((ratio) => (
                            <Stat
                                key={ratio.key}
                                label={ratio.label}
                                value={format(ratio, ratio.value)}
                            />
                        ))}
                    </div>
                    <Card title="Indicadores financieros">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px]">
                                <thead>
                                    <tr>
                                        {[
                                            'Indicador',
                                            'Fórmula',
                                            'Actual',
                                            'Anterior',
                                            'Variación',
                                        ].map((title) => (
                                            <th key={title} className={head}>
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {ratios.ratios.map((ratio) => (
                                        <tr
                                            key={ratio.key}
                                            className="border-t border-slate-100"
                                        >
                                            <td
                                                className={`${cell} font-semibold`}
                                            >
                                                {ratio.label}
                                            </td>
                                            <td
                                                className={`${cell} text-slate-500`}
                                            >
                                                {ratio.help}
                                            </td>
                                            <td className={cell}>
                                                {format(ratio, ratio.value)}
                                            </td>
                                            <td className={cell}>
                                                {format(ratio, ratio.previous)}
                                            </td>
                                            <td className={cell}>
                                                {ratio.change === null
                                                    ? '—'
                                                    : ratio.kind === 'percent'
                                                      ? `${(ratio.change * 100).toFixed(1)} pp`
                                                      : ratio.change.toFixed(2)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                    <Card title="Ingresos y gastos de los últimos 12 meses">
                        <div className="flex h-48 items-end gap-2">
                            {monthly.map((month) => (
                                <div
                                    key={month.month}
                                    className="flex flex-1 flex-col items-center gap-1"
                                    title={`${month.label}: ingresos ${money(month.income_cents)}, gastos ${money(month.expense_cents)}`}
                                >
                                    <div className="flex h-40 w-full items-end gap-0.5">
                                        <div
                                            className="flex-1 rounded-t bg-emerald-600"
                                            style={{
                                                height: `${(month.income_cents / maxMonth) * 100}%`,
                                            }}
                                        />
                                        <div
                                            className="flex-1 rounded-t bg-[#640000]"
                                            style={{
                                                height: `${(month.expense_cents / maxMonth) * 100}%`,
                                            }}
                                        />
                                    </div>
                                    <span className="text-[10px] text-slate-500">
                                        {month.month.slice(5)}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            <span className="text-emerald-700">■</span> Ingresos
                            &nbsp; <span className="text-[#640000]">■</span>{' '}
                            Gastos
                        </p>
                    </Card>
                </>
            )}
        </PageShell>
    );
}
