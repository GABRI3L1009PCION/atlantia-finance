import { Head, Link } from '@inertiajs/react';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import {
    amount,
    companyTone,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    CustomerBalance,
    ReceivablesCompany,
    ReceivablesContext,
} from '@/components/receivables/kit';
import { CustomersTable } from '@/components/receivables/tables';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Aging = {
    buckets: string[];
    totals: number[];
    total_cents: number;
};
type Reconciliation = {
    status: string;
    status_label: string;
    auxiliary_cents: number | null;
    ledger_cents: number | null;
    difference_cents: number | null;
    message: string | null;
};

export default function ReceivablesCompany({
    company,
    customers,
    aging,
    reconciliation,
}: {
    context: ReceivablesContext;
    company: ReceivablesCompany;
    customers: CustomerBalance[];
    aging: Aging | null;
    reconciliation: Reconciliation;
}) {
    const href = useReceivablesHref();

    return (
        <>
            <Head title={`${company.name} · CxC`} />
            <Shell>
                <PageHeader
                    title={company.name}
                    subtitle={`NIT ${company.tax_id} · Cartera de la empresa`}
                    actions={
                        <span
                            className={toneBadge(
                                companyTone[company.status] ?? 'slate',
                            )}
                        >
                            {company.status_label}
                        </span>
                    }
                />
                <div className="grid gap-3 lg:grid-cols-3">
                    <Card title="Cartera">
                        <div className="grid gap-1 text-sm">
                            <p className="flex justify-between">
                                <span>Total</span>
                                <b>{amount(company.total_cents)}</b>
                            </p>
                            <p className="flex justify-between text-red-700">
                                <span>Vencida</span>
                                <b>{amount(company.overdue_cents)}</b>
                            </p>
                            <p className="flex justify-between text-emerald-700">
                                <span>Por vencer</span>
                                <b>{amount(company.current_cents)}</b>
                            </p>
                        </div>
                    </Card>
                    <Card title="Antigüedad">
                        {aging ? (
                            <Table headers={aging.buckets}>
                                <tr>
                                    {aging.totals.map((cents, index) => (
                                        <td key={index} className="px-2 py-2">
                                            {amount(cents)}
                                        </td>
                                    ))}
                                </tr>
                            </Table>
                        ) : (
                            <p className="text-sm text-slate-500">
                                No disponible.
                            </p>
                        )}
                    </Card>
                    <Card
                        title="CxC vs Mayor"
                        action={
                            <Link
                                href={href(
                                    `/receivables/reconciliation?company_id=${company.id}`,
                                )}
                                className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                            >
                                Investigar
                            </Link>
                        }
                    >
                        <div className="grid gap-1 text-sm">
                            <p className="flex justify-between">
                                <span>Auxiliar CxC</span>
                                <b>{amount(reconciliation.auxiliary_cents)}</b>
                            </p>
                            <p className="flex justify-between">
                                <span>Mayor</span>
                                <b>{amount(reconciliation.ledger_cents)}</b>
                            </p>
                            <p
                                className={`flex justify-between ${reconciliation.status === 'matched' ? 'text-emerald-700' : 'text-red-700'}`}
                            >
                                <span>{reconciliation.status_label}</span>
                                <b>{amount(reconciliation.difference_cents)}</b>
                            </p>
                        </div>
                    </Card>
                </div>
                <Card title="Clientes con saldo">
                    <CustomersTable
                        customers={customers}
                        empty="No existen cuentas por cobrar abiertas."
                    />
                </Card>
            </Shell>
        </>
    );
}
