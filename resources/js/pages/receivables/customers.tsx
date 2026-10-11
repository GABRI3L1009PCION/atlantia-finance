import { Head, router } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { useReceivablesHref } from '@/components/receivables/kit';
import type {
    CustomerBalance,
    ReceivablesContext,
} from '@/components/receivables/kit';
import { CustomersTable } from '@/components/receivables/tables';
import { Card } from '@/components/supervisor/kit';

export default function ReceivablesCustomers({
    context,
    customers,
    filters,
}: {
    context: ReceivablesContext;
    customers: CustomerBalance[];
    filters: { company_id?: string };
}) {
    const href = useReceivablesHref();

    return (
        <>
            <Head title="Cartera de clientes" />
            <Shell>
                <PageHeader
                    title="Cartera de clientes"
                    subtitle="Saldos derivados de facturas, cobros aplicados y notas de crédito; ordenados por saldo vencido"
                />
                <Card
                    title={`${customers.length} cliente(s) con saldo`}
                    action={
                        <select
                            aria-label="Empresa"
                            className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                            value={filters.company_id ?? ''}
                            onChange={(event) =>
                                router.get(href('/receivables/customers'), {
                                    company_id: event.target.value,
                                })
                            }
                        >
                            <option value="">Todas las empresas</option>
                            {context.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    }
                >
                    <CustomersTable
                        customers={customers}
                        empty="No existen cuentas por cobrar abiertas."
                    />
                </Card>
            </Shell>
        </>
    );
}
