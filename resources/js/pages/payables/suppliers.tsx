import { Head, router } from '@inertiajs/react';
import { PageHeader, Shell, input } from '@/components/office-admin/kit';
import { usePayablesHref } from '@/components/payables/kit';
import type {
    PayablesContext,
    SupplierBalance,
} from '@/components/payables/kit';
import { SuppliersTable } from '@/components/payables/tables';
import { Card } from '@/components/supervisor/kit';

export default function PayablesSuppliers({
    context,
    suppliers,
    filters,
}: {
    context: PayablesContext;
    suppliers: SupplierBalance[];
    filters: { company_id?: string };
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Proveedores con saldo" />
            <Shell>
                <PageHeader
                    title="Proveedores con saldo"
                    subtitle="Saldo pendiente, vencido y anticipos por proveedor de cada empresa"
                />
                <Card
                    title={`${suppliers.length} proveedor(es)`}
                    action={
                        <select
                            aria-label="Empresa"
                            className={`${input} h-8 text-xs`}
                            value={filters.company_id ?? ''}
                            onChange={(event) =>
                                router.get(href('/payables/suppliers'), {
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
                    <SuppliersTable
                        suppliers={suppliers}
                        empty="No existen proveedores con saldo pendiente."
                    />
                </Card>
            </Shell>
        </>
    );
}
