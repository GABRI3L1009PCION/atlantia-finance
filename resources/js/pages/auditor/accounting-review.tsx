import { Head, router } from '@inertiajs/react';
import { ReviewFilter, amount, useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext, Engagement } from '@/components/auditor/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { statusLabel } from '@/lib/status-labels';
import { ReconciliationTable } from './reconciliations';
import type { ReconciliationRow } from './reconciliations';

type StatementRow = {
    id: number;
    code: string;
    name: string;
    type: string;
    amount_cents: number;
};
type Data = Record<string, unknown> & { ok?: boolean; message?: string };

const tabs: [string, string][] = [
    ['statements', 'Estados financieros'],
    ['trial', 'Balance de comprobación'],
    ['journal', 'Libro Diario'],
    ['ledger', 'Libro Mayor'],
    ['auxiliaries', 'Auxiliares'],
];

export default function AuditorAccountingReview({
    companyId,
    from,
    to,
    tab,
    data,
    filters,
    engagements,
}: {
    context: AuditorContext;
    companyId: number | null;
    from: string;
    to: string;
    tab: string;
    data: Data | null;
    filters: Record<string, string>;
    engagements: Engagement[];
}) {
    const href = useAuditorHref();
    const go = (params: Record<string, string | number>) =>
        router.get(
            href('/work/audit/accounting-review'),
            {
                company_id: companyId ?? '',
                from,
                to,
                tab,
                ...filters,
                ...params,
            },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Revisión contable" />
            <Shell>
                <PageHeader
                    title="Revisión contable"
                    subtitle="Consulta de solo lectura con las cifras oficiales; para documentar usa los procedimientos, papeles y hallazgos de la auditoría"
                />
                <Card title="Empresa y período">
                    <ReviewFilter
                        path="/work/audit/accounting-review"
                        companyId={companyId}
                        from={from}
                        to={to}
                        extra={{ tab }}
                    />
                </Card>
                <nav className="flex flex-wrap gap-1 border-b border-slate-200">
                    {tabs.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => go({ tab: key })}
                            className={`rounded-t-lg px-3 py-2 text-xs font-bold ${tab === key ? 'border border-slate-200 border-b-white bg-white text-[#640000]' : 'text-[#526b92]'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                {data === null && (
                    <p className="text-sm text-slate-500">
                        No tienes empresas asignadas para revisar.
                    </p>
                )}
                {data !== null && data.ok === false && (
                    <p className="rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm text-violet-800">
                        {data.message}
                    </p>
                )}
                {data !== null && data.ok !== false && tab === 'statements' && (
                    <div className="grid gap-3 xl:grid-cols-2">
                        <Card title="Estado de resultados">
                            <Table headers={['Cuenta', 'Importe']}>
                                {(data.income_rows as StatementRow[]).map(
                                    (row) => (
                                        <tr key={row.id}>
                                            <td className="px-2 py-1">
                                                <button
                                                    type="button"
                                                    className="text-left text-blue-700 hover:underline"
                                                    onClick={() =>
                                                        go({
                                                            tab: 'ledger',
                                                            account_id: row.id,
                                                        })
                                                    }
                                                >
                                                    {row.code} {row.name}
                                                </button>
                                            </td>
                                            <td className="px-2 py-1 text-right">
                                                {amount(row.amount_cents)}
                                            </td>
                                        </tr>
                                    ),
                                )}
                                <tr className="font-bold">
                                    <td className="px-2 py-1">
                                        Resultado del período
                                    </td>
                                    <td className="px-2 py-1 text-right">
                                        {amount(data.profit as number)}
                                    </td>
                                </tr>
                            </Table>
                        </Card>
                        <Card title="Estado de situación financiera">
                            <Table headers={['Cuenta', 'Importe']}>
                                {(data.position_rows as StatementRow[]).map(
                                    (row) => (
                                        <tr key={row.id}>
                                            <td className="px-2 py-1">
                                                <button
                                                    type="button"
                                                    className="text-left text-blue-700 hover:underline"
                                                    onClick={() =>
                                                        go({
                                                            tab: 'ledger',
                                                            account_id: row.id,
                                                        })
                                                    }
                                                >
                                                    {row.code} {row.name}
                                                </button>
                                            </td>
                                            <td className="px-2 py-1 text-right">
                                                {amount(row.amount_cents)}
                                            </td>
                                        </tr>
                                    ),
                                )}
                                <tr className="font-bold">
                                    <td className="px-2 py-1">
                                        Activo / Pasivo / Patrimonio
                                    </td>
                                    <td className="px-2 py-1 text-right">
                                        {amount(data.assets as number)} /{' '}
                                        {amount(data.liabilities as number)} /{' '}
                                        {amount(data.equity as number)}
                                    </td>
                                </tr>
                                <tr
                                    className={`font-bold ${(data.difference as number) === 0 ? 'text-emerald-700' : 'text-red-700'}`}
                                >
                                    <td className="px-2 py-1">
                                        Diferencia de la ecuación contable
                                    </td>
                                    <td className="px-2 py-1 text-right">
                                        {amount(data.difference as number)}
                                    </td>
                                </tr>
                            </Table>
                        </Card>
                    </div>
                )}
                {data !== null && data.ok !== false && tab === 'trial' && (
                    <Card title="Balance de comprobación">
                        <Table
                            headers={[
                                'Cuenta',
                                'SI debe',
                                'SI haber',
                                'Mov. debe',
                                'Mov. haber',
                                'SF deudor',
                                'SF acreedor',
                            ]}
                        >
                            {(
                                data.rows as Record<string, number | string>[]
                            ).map((row) => (
                                <tr key={String(row.code)}>
                                    <td className="px-2 py-1">
                                        <button
                                            type="button"
                                            className="text-left text-blue-700 hover:underline"
                                            onClick={() =>
                                                go({
                                                    tab: 'ledger',
                                                    account_id: row.id,
                                                })
                                            }
                                        >
                                            {row.code} {row.name}
                                        </button>
                                    </td>
                                    {(
                                        [
                                            'opening_debit_cents',
                                            'opening_credit_cents',
                                            'period_debit_cents',
                                            'period_credit_cents',
                                            'closing_debit_cents',
                                            'closing_credit_cents',
                                        ] as const
                                    ).map((key) => (
                                        <td
                                            key={key}
                                            className="px-2 py-1 text-right"
                                        >
                                            {amount(row[key] as number)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {data !== null && tab === 'journal' && (
                    <Card
                        title="Libro Diario"
                        action={
                            <div className="flex gap-2">
                                <select
                                    aria-label="Estado"
                                    className={`${input} h-8 w-40 text-xs`}
                                    value={filters.status ?? ''}
                                    onChange={(event) =>
                                        go({ status: event.target.value })
                                    }
                                >
                                    <option value="">Todos los estados</option>
                                    {[
                                        'draft',
                                        'pending_review',
                                        'approved',
                                        'posted',
                                        'reversed',
                                        'rejected',
                                    ].map((s) => (
                                        <option key={s} value={s}>
                                            {s}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    className={`${input} h-8 w-48 text-xs`}
                                    placeholder="Buscar descripción"
                                    defaultValue={filters.search ?? ''}
                                    onKeyDown={(event) =>
                                        event.key === 'Enter' &&
                                        go({
                                            search: (
                                                event.target as HTMLInputElement
                                            ).value,
                                        })
                                    }
                                />
                            </div>
                        }
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Asiento',
                                'Descripción',
                                'Origen',
                                'Total',
                                'Estado',
                                'Creó',
                                'Revisó',
                                'Aprobó',
                                'Contabilizó',
                                'Soportes',
                                '',
                            ]}
                            empty={
                                (data.rows as unknown[]).length === 0 &&
                                'Sin asientos en el período.'
                            }
                        >
                            {(
                                data.rows as Record<
                                    string,
                                    string | number | null
                                >[]
                            ).map((row) => (
                                <tr key={String(row.id)}>
                                    <td className="px-2 py-1">
                                        {formatDate(String(row.date))}
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.number
                                            ? `#${row.number}`
                                            : 'Borrador'}
                                        {row.reversal_of && (
                                            <p className="text-[11px] text-red-700">
                                                Reversión
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.description}
                                    </td>
                                    <td className="px-2 py-1">{row.source}</td>
                                    <td className="px-2 py-1 text-right">
                                        {amount(row.total_cents as number)}
                                    </td>
                                    <td className="px-2 py-1">
                                        <span
                                            className={toneBadge(
                                                row.status === 'posted'
                                                    ? 'green'
                                                    : row.status === 'reversed'
                                                      ? 'red'
                                                      : 'amber',
                                            )}
                                        >
                                            {statusLabel(String(row.status))}
                                        </span>
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.created_by ?? '—'}
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.reviewed_by ?? '—'}
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.approved_by ?? '—'}
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.posted_by ?? '—'}
                                    </td>
                                    <td className="px-2 py-1">
                                        {row.documents}
                                    </td>
                                    <td className="px-2 py-1">
                                        <a
                                            href={String(row.trace)}
                                            className="text-[11px] font-bold text-[#640000]"
                                        >
                                            Trazar
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {data !== null && tab === 'ledger' && (
                    <Card
                        title="Libro Mayor"
                        action={
                            <select
                                aria-label="Cuenta"
                                className={`${input} h-8 w-72 text-xs`}
                                value={(data.account_id as number | null) ?? ''}
                                onChange={(event) =>
                                    go({ account_id: event.target.value })
                                }
                            >
                                <option value="">Selecciona una cuenta…</option>
                                {(
                                    data.accounts as {
                                        id: number;
                                        label: string;
                                    }[]
                                ).map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.label}
                                    </option>
                                ))}
                            </select>
                        }
                    >
                        {data.summary ? (
                            <>
                                <p className="mb-2 text-xs">
                                    Saldo inicial{' '}
                                    {amount(
                                        (data.summary as Record<string, number>)
                                            .opening_balance_cents,
                                    )}{' '}
                                    · Débitos{' '}
                                    {amount(
                                        (data.summary as Record<string, number>)
                                            .debit_cents,
                                    )}{' '}
                                    · Créditos{' '}
                                    {amount(
                                        (data.summary as Record<string, number>)
                                            .credit_cents,
                                    )}{' '}
                                    · Saldo final{' '}
                                    {amount(
                                        (data.summary as Record<string, number>)
                                            .closing_balance_cents,
                                    )}
                                </p>
                                <Table
                                    headers={[
                                        'Fecha',
                                        'Asiento',
                                        'Concepto',
                                        'Origen',
                                        'Tercero',
                                        'Centro / proyecto',
                                        'Debe',
                                        'Haber',
                                        'Saldo',
                                        'Contabilizó',
                                        'Soporte',
                                    ]}
                                >
                                    {(
                                        data.movements as Record<
                                            string,
                                            string | number | null
                                        >[]
                                    ).map((m, index) => (
                                        <tr key={index}>
                                            <td className="px-2 py-1">
                                                {formatDate(String(m.date))}
                                            </td>
                                            <td className="px-2 py-1">
                                                #{m.number}
                                            </td>
                                            <td className="px-2 py-1">
                                                {m.description}
                                            </td>
                                            <td className="px-2 py-1">
                                                {m.source}
                                            </td>
                                            <td className="px-2 py-1">
                                                {m.third_party ?? '—'}
                                            </td>
                                            <td className="px-2 py-1">
                                                {[m.cost_center, m.project]
                                                    .filter(Boolean)
                                                    .join(' / ') || '—'}
                                            </td>
                                            <td className="px-2 py-1 text-right">
                                                {amount(
                                                    m.debit_cents as number,
                                                )}
                                            </td>
                                            <td className="px-2 py-1 text-right">
                                                {amount(
                                                    m.credit_cents as number,
                                                )}
                                            </td>
                                            <td className="px-2 py-1 text-right">
                                                {amount(
                                                    m.balance_cents as number,
                                                )}
                                            </td>
                                            <td className="px-2 py-1">
                                                {m.posted_by ?? '—'}
                                            </td>
                                            <td className="px-2 py-1">
                                                {m.document ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </Table>
                            </>
                        ) : (
                            <p className="py-6 text-center text-sm text-slate-500">
                                Selecciona una cuenta para ver sus movimientos
                                contabilizados.
                            </p>
                        )}
                    </Card>
                )}
                {data !== null && tab === 'auxiliaries' && (
                    <Card title="Auxiliares contra el Mayor">
                        <ReconciliationTable
                            rows={data.rows as ReconciliationRow[]}
                            engagements={engagements}
                        />
                    </Card>
                )}
            </Shell>
        </>
    );
}
