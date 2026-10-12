import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { amount, usePayablesHref } from '@/components/payables/kit';
import type { PayablesContext } from '@/components/payables/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Aging = {
    company_id: number;
    company: string;
    buckets: string[];
    suppliers: {
        id: number;
        name: string;
        amounts: number[];
        total_cents: number;
    }[];
    totals: number[];
    total_cents: number;
};

export default function PayablesAging({
    aging,
}: {
    context: PayablesContext;
    aging: Aging[];
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Antigüedad de CxP" />
            <Shell>
                <PageHeader
                    title="Antigüedad de saldos por pagar"
                    subtitle="Calculada sobre el saldo pendiente con los tramos configurados para la oficina; cada empresa por separado"
                />
                {aging.length === 0 && (
                    <Card title="Sin empresas">
                        <p className="text-sm text-slate-500">
                            No tienes empresas asignadas.
                        </p>
                    </Card>
                )}
                {aging.map((company) => (
                    <Card
                        key={company.company_id}
                        title={`${company.company} · ${amount(company.total_cents)}`}
                    >
                        <Table
                            headers={['Proveedor', ...company.buckets, 'Total']}
                            empty={
                                company.suppliers.length === 0 &&
                                'No existen cuentas por pagar abiertas.'
                            }
                        >
                            {company.suppliers.map((supplier) => (
                                <tr key={supplier.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(
                                                `/payables/suppliers/${supplier.id}`,
                                            )}
                                            className="hover:text-[#640000]"
                                        >
                                            {supplier.name}
                                        </Link>
                                    </td>
                                    {supplier.amounts.map((cents, index) => (
                                        <td
                                            key={index}
                                            className={`px-2 py-2 ${index > 0 && cents > 0 ? 'text-red-700' : ''}`}
                                        >
                                            {cents ? amount(cents) : '—'}
                                        </td>
                                    ))}
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(supplier.total_cents)}
                                    </td>
                                </tr>
                            ))}
                            {company.suppliers.length > 0 && (
                                <tr className="bg-slate-50 font-black">
                                    <td className="px-2 py-2">Total empresa</td>
                                    {company.totals.map((cents, index) => (
                                        <td key={index} className="px-2 py-2">
                                            {amount(cents)}
                                        </td>
                                    ))}
                                    <td className="px-2 py-2">
                                        {amount(company.total_cents)}
                                    </td>
                                </tr>
                            )}
                        </Table>
                    </Card>
                ))}
            </Shell>
        </>
    );
}
