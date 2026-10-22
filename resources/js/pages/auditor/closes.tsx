import { Head } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { ReviewFilter, amount } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { statusLabel as translateStatus } from '@/lib/status-labels';

type Period = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    status: string;
    closed_at: string | null;
    closed_by: string | null;
    snapshot: {
        closed_at: string;
        closed_by: string;
        debits_cents: number;
        credits_cents: number;
        result_cents: number;
        warnings: unknown[];
        blockers: unknown[];
        checklist: { label?: string; status?: string; message?: string }[];
    } | null;
    reopenings: {
        requested_at: string;
        requested_by: string;
        reason: string;
        status: string;
        approved_by: string | null;
        approved_at: string | null;
        reopened_at: string | null;
    }[];
    transactions_after_reopen: number;
};

const statusLabel: Record<string, [string, string]> = {
    open: ['Abierto', 'blue'],
    closed: ['Cerrado', 'green'],
    reopen_requested: ['Reapertura solicitada', 'amber'],
    reopen_approved: ['Reapertura aprobada', 'amber'],
    reopened: ['Reabierto', 'red'],
};

export default function AuditorCloses({
    companyId,
    periods,
}: {
    context: AuditorContext;
    companyId: number | null;
    periods: Period[];
}) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <>
            <Head title="Cierres contables" />
            <Shell>
                <PageHeader
                    title="Cierres contables"
                    subtitle="Períodos, snapshot del cierre, advertencias, bloqueos y reaperturas; consulta de solo lectura"
                />
                <Card title="Empresa">
                    <ReviewFilter
                        path="/work/audit/accounting-closes"
                        companyId={companyId}
                    />
                </Card>
                <Card title={`${periods.length} período(s)`}>
                    <Table
                        headers={[
                            'Período',
                            'Estado',
                            'Cerrado',
                            'Por',
                            'Resultado al cierre',
                            'Reaperturas',
                            'Movimientos post-reapertura',
                            '',
                        ]}
                        empty={
                            periods.length === 0 &&
                            'La empresa no tiene períodos contables.'
                        }
                    >
                        {periods.map((period) => {
                            const [label, tone] = statusLabel[
                                period.status
                            ] ?? [period.status, 'slate'];

                            return (
                                <Fragment key={period.id}>
                                    <tr>
                                        <td className="px-2 py-2 font-bold">
                                            {period.name}
                                            <p className="text-[11px] font-normal text-[#526b92]">
                                                {formatDate(period.starts_on)} –{' '}
                                                {formatDate(period.ends_on)}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2">
                                            <span className={toneBadge(tone)}>
                                                {label}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(period.closed_at, true)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {period.closed_by ?? '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {period.snapshot
                                                ? amount(
                                                      period.snapshot
                                                          .result_cents,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {period.reopenings.length}
                                        </td>
                                        <td
                                            className={`px-2 py-2 ${period.transactions_after_reopen > 0 ? 'font-bold text-red-700' : ''}`}
                                        >
                                            {period.transactions_after_reopen}
                                        </td>
                                        <td className="px-2 py-2">
                                            {(period.snapshot ||
                                                period.reopenings.length >
                                                    0) && (
                                                <button
                                                    type="button"
                                                    className="text-xs font-bold text-[#640000]"
                                                    onClick={() =>
                                                        setOpen(
                                                            open === period.id
                                                                ? null
                                                                : period.id,
                                                        )
                                                    }
                                                >
                                                    {open === period.id
                                                        ? 'Ocultar'
                                                        : 'Detalle'}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                    {open === period.id && (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="bg-[#f8fafc] px-3 py-3 text-xs"
                                            >
                                                {period.snapshot && (
                                                    <div className="mb-3">
                                                        <p className="font-bold">
                                                            Snapshot del cierre
                                                            (
                                                            {
                                                                period.snapshot
                                                                    .closed_by
                                                            }
                                                            ,{' '}
                                                            {formatDate(
                                                                period.snapshot
                                                                    .closed_at,
                                                                true,
                                                            )}
                                                            )
                                                        </p>
                                                        <p>
                                                            Débitos{' '}
                                                            {amount(
                                                                period.snapshot
                                                                    .debits_cents,
                                                            )}{' '}
                                                            · Créditos{' '}
                                                            {amount(
                                                                period.snapshot
                                                                    .credits_cents,
                                                            )}{' '}
                                                            ·{' '}
                                                            {
                                                                period.snapshot
                                                                    .warnings
                                                                    .length
                                                            }{' '}
                                                            advertencia(s) ·{' '}
                                                            {
                                                                period.snapshot
                                                                    .blockers
                                                                    .length
                                                            }{' '}
                                                            bloqueo(s)
                                                        </p>
                                                        <ul className="mt-1 grid gap-0.5">
                                                            {period.snapshot.checklist.map(
                                                                (
                                                                    check,
                                                                    index,
                                                                ) => (
                                                                    <li
                                                                        key={
                                                                            index
                                                                        }
                                                                    >
                                                                        {check.status ===
                                                                        'passed'
                                                                            ? '✓'
                                                                            : '•'}{' '}
                                                                        {
                                                                            check.label
                                                                        }
                                                                        :{' '}
                                                                        {
                                                                            check.message
                                                                        }
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    </div>
                                                )}
                                                {period.reopenings.map(
                                                    (r, index) => (
                                                        <p
                                                            key={index}
                                                            className="mb-1"
                                                        >
                                                            Reapertura
                                                            solicitada por{' '}
                                                            {r.requested_by} el{' '}
                                                            {formatDate(
                                                                r.requested_at,
                                                            )}{' '}
                                                            · «{r.reason}» ·{' '}
                                                            {translateStatus(
                                                                r.status,
                                                            )}
                                                            {r.approved_by &&
                                                                ` · aprobó ${r.approved_by}`}
                                                            {r.reopened_at &&
                                                                ` · reabierto ${formatDate(r.reopened_at, true)}`}
                                                        </p>
                                                    ),
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
