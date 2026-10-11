import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import type { AccountantContext } from '@/components/accountant/kit';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';

type Row = {
    company_id: number;
    company_name: string;
    period_name: string | null;
    error: string | null;
    items: {
        label: string;
        auxiliary_cents: number | null;
        ledger_cents: number;
        difference_cents: number | null;
        status: string;
    }[];
    expediente: string;
};

export default function AccountantReconciliations({
    context,
    rows,
}: {
    context: AccountantContext;
    rows: Row[];
}) {
    return (
        <>
            <Head title="Conciliaciones" />
            <Shell>
                <PageHeader
                    title="Conciliaciones"
                    subtitle={`Cuentas por cobrar, cuentas por pagar y bancos contra el mayor · ${context.period?.name ?? 'Sin período'}`}
                />
                <p className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <AlertTriangle className="size-4" /> Las diferencias nunca
                    se ajustan automáticamente: se investigan y se corrigen con
                    documentos o pólizas aprobadas.
                </p>
                <Card title={`${rows.length} empresa(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Conciliación',
                            'Auxiliar',
                            'Mayor',
                            'Diferencia',
                            'Estado',
                            '',
                        ]}
                        empty={rows.length === 0 && 'Sin empresas a cargo.'}
                    >
                        {rows.flatMap((row) =>
                            row.error !== null
                                ? [
                                      <tr key={row.company_id}>
                                          <td className="px-2 py-2 font-bold">
                                              {row.company_name}
                                          </td>
                                          <td
                                              colSpan={6}
                                              className="px-2 py-2 text-red-700"
                                          >
                                              {row.error}
                                          </td>
                                      </tr>,
                                  ]
                                : row.items.length === 0
                                  ? [
                                        <tr key={row.company_id}>
                                            <td className="px-2 py-2 font-bold">
                                                {row.company_name}
                                            </td>
                                            <td
                                                colSpan={6}
                                                className="px-2 py-2 text-[#526b92]"
                                            >
                                                Sin período abierto para
                                                conciliar.
                                            </td>
                                        </tr>,
                                    ]
                                  : row.items.map((item, index) => (
                                        <tr
                                            key={`${row.company_id}-${item.label}`}
                                        >
                                            <td className="px-2 py-2 font-bold">
                                                {index === 0
                                                    ? row.company_name
                                                    : ''}
                                            </td>
                                            <td className="px-2 py-2">
                                                {item.label}
                                            </td>
                                            <td className="px-2 py-2">
                                                {item.auxiliary_cents === null
                                                    ? 'Sin auxiliar'
                                                    : money(
                                                          item.auxiliary_cents,
                                                      )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {money(item.ledger_cents)}
                                            </td>
                                            <td
                                                className={`px-2 py-2 font-bold ${item.difference_cents ? 'text-red-700' : ''}`}
                                            >
                                                {item.difference_cents === null
                                                    ? '—'
                                                    : money(
                                                          item.difference_cents,
                                                      )}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={toneBadge(
                                                        item.status ===
                                                            'reconciled'
                                                            ? 'green'
                                                            : 'red',
                                                    )}
                                                >
                                                    {item.status ===
                                                    'reconciled'
                                                        ? 'Conciliado'
                                                        : 'Con diferencia'}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2">
                                                {index === 0 && (
                                                    <a
                                                        href={row.expediente}
                                                        className={
                                                            secondaryButton
                                                        }
                                                    >
                                                        Ver empresa
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    )),
                        )}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
