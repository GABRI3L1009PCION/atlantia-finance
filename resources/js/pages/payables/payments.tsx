import { Head } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import type { PayablesContext, PaymentRow } from '@/components/payables/kit';
import { PaymentsTable } from '@/components/payables/tables';
import { Card } from '@/components/supervisor/kit';

export default function PayablesPayments({
    payments,
}: {
    context: PayablesContext;
    payments: PaymentRow[];
}) {
    return (
        <>
            <Head title="Pagos a proveedores" />
            <Shell>
                <PageHeader
                    title="Pagos a proveedores"
                    subtitle="El mismo pago que prepara y ejecuta Tesorería (sin duplicados), con lo aplicado a facturas y lo que queda sin aplicar"
                />
                <Card title={`${payments.length} pago(s)`}>
                    <PaymentsTable
                        payments={payments}
                        empty="Aún no hay pagos a proveedores."
                    />
                </Card>
            </Shell>
        </>
    );
}
