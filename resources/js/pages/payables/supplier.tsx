import { Head } from '@inertiajs/react';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { amount } from '@/components/payables/kit';
import type {
    Advance,
    CreditNote,
    Payable,
    PayableDispute,
    PayablesContext,
    PaymentRow,
    SupplierBalance,
} from '@/components/payables/kit';
import {
    AdvancesTable,
    DocumentsTable,
    PaymentsTable,
} from '@/components/payables/tables';
import { Card, Table } from '@/components/supervisor/kit';

export default function PayablesSupplier({
    supplier,
    summary,
    documents,
    payments,
    advances,
    creditNotes,
    disputes,
    statement,
}: {
    context: PayablesContext;
    supplier: {
        id: number;
        company: string;
        name: string;
        tax_id: string | null;
        email: string | null;
        phone: string | null;
        payment_terms_days: number | null;
    };
    summary: SupplierBalance | null;
    documents: Payable[];
    payments: PaymentRow[];
    advances: Advance[];
    creditNotes: CreditNote[];
    disputes: PayableDispute[];
    statement: {
        date: string;
        type: string;
        document: string;
        charge_cents: number;
        credit_cents: number;
        balance_cents: number;
    }[];
}) {
    return (
        <>
            <Head title={supplier.name} />
            <Shell>
                <PageHeader
                    title={supplier.name}
                    subtitle={`${supplier.company} · NIT ${supplier.tax_id ?? 'No indicado'} · Plazo ${supplier.payment_terms_days === null ? 'no configurado' : `${supplier.payment_terms_days} días`}${supplier.email ? ` · ${supplier.email}` : ''}${supplier.phone ? ` · ${supplier.phone}` : ''}`}
                />
                <div className="grid gap-3 md:grid-cols-4">
                    <Card title="Saldo total">
                        <p className="text-2xl font-black">
                            {amount(summary?.total_cents ?? 0)}
                        </p>
                    </Card>
                    <Card title="Vencido">
                        <p className="text-2xl font-black text-red-700">
                            {amount(summary?.overdue_cents ?? 0)}
                        </p>
                    </Card>
                    <Card title="Anticipos y saldos a favor">
                        <p className="text-2xl font-black text-amber-700">
                            {amount(summary?.advances_cents ?? 0)}
                        </p>
                    </Card>
                    <Card title="Disputas activas">
                        <p className="text-2xl font-black">
                            {
                                disputes.filter((dispute) =>
                                    ['open', 'under_review'].includes(
                                        dispute.status,
                                    ),
                                ).length
                            }
                        </p>
                    </Card>
                </div>
                <Card title="Documentos">
                    <DocumentsTable
                        documents={documents}
                        empty="El proveedor no tiene documentos."
                        showSupplier={false}
                    />
                </Card>
                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Pagos">
                        <PaymentsTable
                            payments={payments}
                            empty="Sin pagos al proveedor."
                        />
                    </Card>
                    <Card title="Anticipos">
                        <AdvancesTable
                            advances={advances}
                            empty="Sin anticipos."
                        />
                    </Card>
                </div>
                <Card title="Estado de cuenta">
                    <Table
                        headers={[
                            'Fecha',
                            'Movimiento',
                            'Documento',
                            'Cargo',
                            'Abono',
                            'Saldo',
                        ]}
                        empty={statement.length === 0 && 'Sin movimientos.'}
                    >
                        {statement.map((row, index) => (
                            <tr key={index}>
                                <td className="px-2 py-2">
                                    {formatDate(row.date)}
                                </td>
                                <td className="px-2 py-2">{row.type}</td>
                                <td className="px-2 py-2">{row.document}</td>
                                <td className="px-2 py-2">
                                    {row.charge_cents
                                        ? amount(row.charge_cents)
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {row.credit_cents
                                        ? amount(row.credit_cents)
                                        : '—'}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(row.balance_cents)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    {creditNotes.length > 0 && (
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Incluye {creditNotes.length} nota(s) de crédito
                            registradas en Compras.
                        </p>
                    )}
                </Card>
            </Shell>
        </>
    );
}
