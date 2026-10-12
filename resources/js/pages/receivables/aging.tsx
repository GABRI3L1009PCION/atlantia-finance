import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { amount, useReceivablesHref } from '@/components/receivables/kit';
import type { ReceivablesContext } from '@/components/receivables/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Aging = {
    company_id: number;
    company: string;
    buckets: string[];
    customers: {
        id: number;
        name: string;
        amounts: number[];
        total_cents: number;
    }[];
    totals: number[];
    total_cents: number;
};

export default function ReceivablesAging({
    aging,
}: {
    context: ReceivablesContext;
    aging: Aging[];
}) {
    const href = useReceivablesHref();

    return (
        <>
            <Head title="Antigüedad de saldos" />
            <Shell>
                <PageHeader
                    title="Antigüedad de saldos"
                    subtitle="Calculada sobre el saldo pendiente (no el monto original), con los tramos configurados para la oficina; cada empresa por separado"
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
                            headers={['Cliente', ...company.buckets, 'Total']}
                            empty={
                                company.customers.length === 0 &&
                                'No existen cuentas por cobrar abiertas.'
                            }
                        >
                            {company.customers.map((customer) => (
                                <tr key={customer.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(
                                                `/receivables/customers/${customer.id}`,
                                            )}
                                            className="hover:text-[#640000]"
                                        >
                                            {customer.name}
                                        </Link>
                                    </td>
                                    {customer.amounts.map((cents, index) => (
                                        <td
                                            key={index}
                                            className={`px-2 py-2 ${index > 0 && cents > 0 ? 'text-red-700' : ''}`}
                                        >
                                            {cents ? amount(cents) : '—'}
                                        </td>
                                    ))}
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(customer.total_cents)}
                                    </td>
                                </tr>
                            ))}
                            {company.customers.length > 0 && (
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
