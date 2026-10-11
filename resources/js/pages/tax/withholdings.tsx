import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import { useTaxHref } from '@/components/tax/kit';
import type { TaxContext } from '@/components/tax/kit';

type Row = {
    key: string;
    company: string;
    tax: string;
    rate: number | null;
    in_force: boolean;
    records: number;
    amount_cents: number;
    obligation_status: string | null;
    obligation_destination: string | null;
    destination: string;
};

export default function TaxWithholdings({
    context,
    rows,
}: {
    context: TaxContext;
    rows: Row[];
}) {
    const href = useTaxHref();

    return (
        <>
            <Head title="Retenciones" />
            <Shell>
                <PageHeader
                    title="Retenciones"
                    subtitle={`Solo empresas con retenciones configuradas en Parámetros fiscales · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card title={`${rows.length} retención(es) configurada(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Retención',
                            'Tasa vigente',
                            'Registros del mes',
                            'Monto',
                            'Obligación',
                            '',
                        ]}
                        empty={
                            rows.length === 0 &&
                            'Ninguna empresa asignada tiene retenciones configuradas; no aplican.'
                        }
                    >
                        {rows.map((row) => (
                            <tr key={row.key}>
                                <td className="px-2 py-2 font-bold">
                                    {row.company}
                                </td>
                                <td className="px-2 py-2">{row.tax}</td>
                                <td className="px-2 py-2">
                                    {row.in_force ? (
                                        `${row.rate?.toFixed(2)}%`
                                    ) : (
                                        <span className="text-amber-700">
                                            Sin regla vigente
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">{row.records}</td>
                                <td className="px-2 py-2">
                                    {money(row.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {row.obligation_destination ? (
                                        <Link
                                            href={href(
                                                row.obligation_destination,
                                            )}
                                            className="font-semibold hover:text-[#640000]"
                                        >
                                            <span className={toneBadge('blue')}>
                                                {row.obligation_status}
                                            </span>
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={row.destination}
                                        className={secondaryButton}
                                    >
                                        Registros
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
