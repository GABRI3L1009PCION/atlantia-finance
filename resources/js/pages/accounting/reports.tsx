import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type Row = {
    id: number;
    code: string;
    name: string;
    type: string;
    net: number;
};
type Statements = {
    incomeRows: Row[];
    positionRows: Row[];
    income: number;
    expenses: number;
    profit: number;
    assets: number;
    liabilities: number;
    equity: number;
    accumulated: number;
    difference: number;
};
const types: Record<string, string> = {
    asset: 'Activo',
    liability: 'Pasivo',
    equity: 'Patrimonio',
    income: 'Ingreso',
    expense: 'Gasto',
};
function Accounts({ rows }: { rows: Row[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <thead className="bg-[#faf4f4]">
                    <tr>
                        <th className="p-3">Cuenta</th>
                        <th className="p-3">Clasificación</th>
                        <th className="p-3 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-b border-slate-100">
                            <td className="p-3">
                                {row.code} · {row.name}
                            </td>
                            <td className="p-3">{types[row.type]}</td>
                            <td className="p-3 text-right tabular-nums">
                                {money(
                                    ['income', 'liability', 'equity'].includes(
                                        row.type,
                                    )
                                        ? -row.net
                                        : row.net,
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {rows.length === 0 && (
                <p className="p-4 text-slate-500">
                    No hay movimientos contabilizados para estas fechas.
                </p>
            )}
        </div>
    );
}
export default function Reports({
    company,
    filters,
    statements: s,
}: {
    company: Company;
    filters: { from: string; to: string };
    statements: Statements;
}) {
    const form = useForm(filters);
    const [kind, setKind] = useState('journal');

    return (
        <AccountingSection
            company={company}
            title="Estados financieros y reportes"
        >
            <Card title="Fechas de consulta">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.get(routes.reports.url(company.public_id));
                    }}
                    className="flex flex-wrap items-end gap-4"
                >
                    <label>
                        Desde
                        <input
                            aria-label="Desde"
                            type="date"
                            required
                            className={field}
                            value={form.data.from}
                            onChange={(e) =>
                                form.setData('from', e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Hasta / fecha de corte
                        <input
                            aria-label="Hasta"
                            type="date"
                            required
                            min={form.data.from}
                            className={field}
                            value={form.data.to}
                            onChange={(e) => form.setData('to', e.target.value)}
                        />
                    </label>
                    <button disabled={form.processing} className={primary}>
                        Consultar
                    </button>
                </form>
                <Errors errors={form.errors} />
                <p className="mt-3 text-sm text-slate-500">
                    Solo asientos aprobados. El balance general acumula todos
                    los registros hasta la fecha de corte; no se limita a
                    «Desde».
                </p>
            </Card>
            <div className="grid gap-4 sm:grid-cols-3">
                {[
                    ['Ingresos del rango', s.income],
                    ['Gastos del rango', s.expenses],
                    ['Resultado del rango', s.profit],
                ].map(([label, value]) => (
                    <Card key={String(label)} title={String(label)}>
                        <p className="text-3xl font-semibold text-[#640000]">
                            {money(value)}
                        </p>
                    </Card>
                ))}
            </div>
            <Card title="Estado de resultados">
                <p className="mb-4 text-sm text-slate-500">
                    Del {filters.from} al {filters.to}. Los asientos de cierre
                    registrados afectan los movimientos de las cuentas de
                    ingresos y gastos.
                </p>
                <Accounts rows={s.incomeRows} />
                <p className="mt-5 text-right font-semibold">
                    Resultado: {money(s.profit)}
                </p>
            </Card>
            <Card title="Balance general">
                <p className="mb-4 text-sm text-slate-500">
                    Acumulado hasta {filters.to}. Un saldo contrario a su
                    naturaleza requiere revisión; no se oculta.
                </p>
                <Accounts rows={s.positionRows} />
                <dl className="mt-5 ml-auto max-w-lg space-y-3">
                    {[
                        ['Activos', s.assets],
                        ['Pasivos', s.liabilities],
                        ['Patrimonio registrado', s.equity],
                        ['Resultado acumulado no transferido', s.accumulated],
                        [
                            'Pasivo + patrimonio + resultado',
                            s.liabilities + s.equity + s.accumulated,
                        ],
                    ].map(([label, value]) => (
                        <div
                            key={String(label)}
                            className="flex justify-between gap-5"
                        >
                            <dt>{label}</dt>
                            <dd className="font-semibold">{money(value)}</dd>
                        </div>
                    ))}
                </dl>
                <p
                    role="status"
                    className={`mt-5 rounded-xl p-4 text-sm ${s.difference === 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}
                >
                    {s.difference === 0
                        ? 'La ecuación contable cuadra.'
                        : `Revisa la clasificación de cuentas: diferencia ${money(s.difference)}.`}
                </p>
            </Card>
            <Card title="Descargar libros y estados">
                <div className="flex flex-wrap items-end gap-3">
                    <label className="min-w-64 flex-1">
                        Reporte
                        <select
                            className={field}
                            value={kind}
                            onChange={(e) => setKind(e.target.value)}
                        >
                            {[
                                ['journal', 'Libro diario'],
                                ['ledger', 'Libro mayor'],
                                ['trial', 'Balance de comprobación'],
                                ['income', 'Estado de resultados'],
                                ['balance', 'Balance general'],
                            ].map(([v, label]) => (
                                <option key={v} value={v}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {['pdf', 'xlsx'].map((format) => (
                        <a
                            key={format}
                            className={secondary}
                            href={routes.exportMethod.url(company.public_id, {
                                query: { ...filters, kind, format },
                            })}
                        >
                            {format === 'pdf'
                                ? 'Descargar PDF'
                                : 'Descargar Excel'}
                        </a>
                    ))}
                </div>
                <p className="mt-3 text-sm text-slate-500">
                    Se exporta todo el rango consultado, no solo una página.
                    Máximo 1,000 líneas en PDF y 10,000 en Excel; reduce el
                    rango si es necesario. Reportes internos, no certificados.
                </p>
            </Card>
        </AccountingSection>
    );
}
