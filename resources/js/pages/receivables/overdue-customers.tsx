import { Head } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import type {
    CustomerBalance,
    ReceivablesContext,
} from '@/components/receivables/kit';
import { CustomersTable } from '@/components/receivables/tables';
import { Card } from '@/components/supervisor/kit';

export default function ReceivablesOverdueCustomers({
    customers,
}: {
    context: ReceivablesContext;
    customers: CustomerBalance[];
}) {
    return (
        <>
            <Head title="Clientes vencidos" />
            <Shell>
                <PageHeader
                    title="Clientes vencidos"
                    subtitle="Ordenados por prioridad operativa de cobranza (días vencido, promesas incumplidas, falta de gestión, disputas y saldo); no es una evaluación crediticia"
                />
                <Card title={`${customers.length} cliente(s)`}>
                    <CustomersTable
                        customers={customers}
                        empty="No existen documentos vencidos."
                    />
                </Card>
            </Shell>
        </>
    );
}
