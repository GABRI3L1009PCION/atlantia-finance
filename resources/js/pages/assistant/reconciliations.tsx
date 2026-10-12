import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import type {
    AssistantContext,
    Reconciliation,
} from '@/components/assistant/kit';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';

export default function AssistantReconciliations({
    context,
    rows,
}: {
    context: AssistantContext;
    rows: Reconciliation[];
}) {
    return (
        <>
            <Head title="Conciliaciones pendientes" />
            <Shell>
                <PageHeader
                    title="Conciliaciones pendientes"
                    subtitle={`Bancos con movimientos por conciliar y auxiliares que no cuadran con el mayor · ${context.period?.name ?? 'Sin período'}`}
                />
                <p className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <AlertTriangle className="size-4" /> Nunca se ajusta un
                    saldo directamente: se investiga y se corrige con la
                    operación o un asiento formal.
                </p>
                <Card title={`${rows.length} pendiente(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Cuenta',
                            'Tipo',
                            'Saldo banco / auxiliar',
                            'Saldo libro',
                            'Diferencia',
                            'Estado',
                            '',
                        ]}
                        empty={
                            rows.length === 0 &&
                            'No tienes conciliaciones pendientes.'
                        }
                    >
                        {rows.map((row) => (
                            <tr key={row.key}>
                                <td className="px-2 py-2 font-bold">
                                    {row.company}
                                </td>
                                <td className="px-2 py-2">{row.account}</td>
                                <td className="px-2 py-2">{row.type}</td>
                                <td className="px-2 py-2">
                                    {row.auxiliary_cents === null
                                        ? 'No disponible'
                                        : money(row.auxiliary_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {row.ledger_cents === null
                                        ? 'No disponible'
                                        : money(row.ledger_cents)}
                                </td>
                                <td
                                    className={`px-2 py-2 font-bold ${row.difference_cents ? 'text-red-700' : ''}`}
                                >
                                    {row.difference_cents === null
                                        ? '—'
                                        : money(row.difference_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            row.status === 'error'
                                                ? 'violet'
                                                : row.status === 'difference'
                                                  ? 'red'
                                                  : 'amber',
                                        )}
                                    >
                                        {row.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {row.destination && (
                                        <a
                                            href={row.destination}
                                            className={secondaryButton}
                                        >
                                            Conciliar
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
