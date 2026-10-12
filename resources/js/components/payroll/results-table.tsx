import { Fragment, useState } from 'react';
import {
    IssueList,
    amount,
    resultLabel,
    resultTone,
} from '@/components/payroll/kit';
import type { EmployeeResult } from '@/components/payroll/kit';
import { Table, toneBadge } from '@/components/supervisor/kit';

const typeLabel: Record<string, string> = {
    earning: 'Ingreso',
    deduction: 'Descuento',
    employer: 'Aporte empresa',
    provision: 'Provisión',
};

const reserved = (value: number | null) =>
    value === null ? 'Reservado' : amount(value);

/** Calculated payroll per employee with its concept lines and ISR projection (art. 76) on demand. */
export function ResultsTable({ results }: { results: EmployeeResult[] }) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <Table
            headers={[
                'Empleado',
                'Días',
                'Devengado',
                'Descuentos',
                'Líquido',
                'Aporte empresa',
                'Validación',
                '',
            ]}
            empty={results.length === 0 && 'La nómina aún no se ha calculado.'}
        >
            {results.map((result) => (
                <Fragment key={result.id}>
                    <tr>
                        <td className="px-2 py-2 font-bold">
                            {result.employee}
                            <p className="text-[11px] font-normal text-[#526b92]">
                                {result.code}
                            </p>
                        </td>
                        <td className="px-2 py-2">{result.days_worked}</td>
                        <td className="px-2 py-2">
                            {reserved(result.gross_cents)}
                        </td>
                        <td className="px-2 py-2 text-red-700">
                            {reserved(result.deductions_cents)}
                        </td>
                        <td className="px-2 py-2 font-semibold text-emerald-700">
                            {reserved(result.net_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {reserved(result.employer_cents)}
                        </td>
                        <td className="max-w-80 px-2 py-2">
                            <span
                                className={toneBadge(
                                    resultTone[result.status] ?? 'slate',
                                )}
                            >
                                {resultLabel[result.status] ?? result.status}
                            </span>
                            {result.issues.length > 0 && (
                                <div className="mt-1 text-[11px]">
                                    <IssueList issues={result.issues} />
                                </div>
                            )}
                        </td>
                        <td className="px-2 py-2">
                            {result.lines.length > 0 && (
                                <button
                                    type="button"
                                    className="text-xs font-bold text-[#640000]"
                                    onClick={() =>
                                        setOpen(
                                            open === result.id
                                                ? null
                                                : result.id,
                                        )
                                    }
                                >
                                    {open === result.id ? 'Ocultar' : 'Detalle'}
                                </button>
                            )}
                        </td>
                    </tr>
                    {open === result.id && (
                        <tr>
                            <td colSpan={8} className="bg-[#f8fafc] px-3 py-3">
                                <div className="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                                    <table className="min-w-full text-xs">
                                        <thead className="text-left text-[#526b92]">
                                            <tr>
                                                <th className="px-2 py-1">
                                                    Concepto
                                                </th>
                                                <th className="px-2 py-1">
                                                    Tipo
                                                </th>
                                                <th className="px-2 py-1">
                                                    Cantidad
                                                </th>
                                                <th className="px-2 py-1">
                                                    Tasa
                                                </th>
                                                <th className="px-2 py-1 text-right">
                                                    Monto
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {result.lines.map((line, index) => (
                                                <tr
                                                    key={index}
                                                    className="border-t border-slate-100"
                                                >
                                                    <td className="px-2 py-1">
                                                        {line.concept}
                                                    </td>
                                                    <td className="px-2 py-1">
                                                        {typeLabel[line.type] ??
                                                            line.type}
                                                    </td>
                                                    <td className="px-2 py-1">
                                                        {line.quantity ?? ''}
                                                    </td>
                                                    <td className="px-2 py-1">
                                                        {line.rate === null
                                                            ? ''
                                                            : `${Math.round(line.rate * 10000) / 100}%`}
                                                    </td>
                                                    <td className="px-2 py-1 text-right font-semibold">
                                                        {amount(
                                                            line.amount_cents,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {result.isr_detail && (
                                        <dl className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 rounded-lg border border-slate-200 bg-white p-3 text-xs">
                                            <dt className="col-span-2 font-bold text-[#061633]">
                                                Proyección ISR (art. 76)
                                            </dt>
                                            <dt className="text-[#526b92]">
                                                Renta anual proyectada
                                            </dt>
                                            <dd className="text-right">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .projected_income_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dt className="text-[#526b92]">
                                                Deducción personal
                                            </dt>
                                            <dd className="text-right">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .personal_deduction_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dt className="text-[#526b92]">
                                                IGSS proyectado
                                            </dt>
                                            <dd className="text-right">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .projected_igss_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dt className="text-[#526b92]">
                                                Renta imponible
                                            </dt>
                                            <dd className="text-right">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .taxable_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dt className="text-[#526b92]">
                                                Impuesto anual
                                            </dt>
                                            <dd className="text-right">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .annual_tax_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dt className="text-[#526b92]">
                                                Retenido en el año
                                            </dt>
                                            <dd className="text-right">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .withheld_ytd_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dt className="text-[#526b92]">
                                                Meses por retener
                                            </dt>
                                            <dd className="text-right">
                                                {
                                                    result.isr_detail
                                                        .remaining_months
                                                }
                                            </dd>
                                            <dt className="font-bold">
                                                Retención del mes
                                            </dt>
                                            <dd className="text-right font-bold">
                                                {amount(
                                                    Number(
                                                        result.isr_detail
                                                            .retention_cents,
                                                    ),
                                                )}
                                            </dd>
                                            <dd className="col-span-2 mt-1 text-[10px] text-[#526b92]">
                                                {
                                                    result.isr_detail
                                                        .legal_reference
                                                }
                                            </dd>
                                        </dl>
                                    )}
                                </div>
                            </td>
                        </tr>
                    )}
                </Fragment>
            ))}
        </Table>
    );
}
