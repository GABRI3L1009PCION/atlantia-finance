import { Head, Link, router } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import {
    amount,
    dueLabel,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    Receivable,
    ReceivablesContext,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Row = Receivable & {
    responsible: string | null;
    last_activity: string | null;
};

export default function ReceivablesDueDates({
    rows,
    days,
}: {
    context: ReceivablesContext;
    rows: Row[];
    days: number;
}) {
    const href = useReceivablesHref();

    return (
        <>
            <Head title="Vencimientos" />
            <Shell>
                <PageHeader
                    title="Vencimientos"
                    subtitle="Documentos vencidos y por vencer; los días se calculan con la zona horaria de cada empresa"
                    actions={
                        <div className="flex gap-1">
                            {[7, 15, 30, 60].map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() =>
                                        router.get(
                                            href('/receivables/due-dates'),
                                            { days: option },
                                        )
                                    }
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${days === option ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                                >
                                    {option} días
                                </button>
                            ))}
                        </div>
                    }
                />
                <Card title={`${rows.length} documento(s)`}>
                    <Table
                        headers={[
                            'Cliente',
                            'Documento',
                            'Saldo',
                            'Vence',
                            'Días',
                            'Responsable',
                            'Última gestión',
                            '',
                        ]}
                        empty={
                            rows.length === 0 &&
                            'No existen documentos vencidos ni por vencer en el rango.'
                        }
                    >
                        {rows.map((row) => {
                            const [label, tone] = dueLabel(row);

                            return (
                                <tr key={row.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {row.customer}
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {row.company}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.document}
                                    </td>
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(row.outstanding_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(row.due_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.responsible ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(row.last_activity)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={href(
                                                `/receivables/customers/${row.customer_id}`,
                                            )}
                                            className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                        >
                                            Gestionar
                                        </Link>
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
