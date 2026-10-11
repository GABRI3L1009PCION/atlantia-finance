import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    amount,
    companyTone,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    PayablesCompany,
    PayablesContext,
    SupplierBalance,
} from '@/components/payables/kit';
import { SuppliersTable } from '@/components/payables/tables';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Aging = {
    buckets: string[];
    totals: number[];
    total_cents: number;
};

export default function PayablesCompany({
    company,
    suppliers,
    aging,
    dueMonths,
    reconciliation,
}: {
    context: PayablesContext;
    company: PayablesCompany | null;
    suppliers: SupplierBalance[];
    aging: Aging | null;
    dueMonths: { month: string; label: string; amount_cents: number }[];
    reconciliation: {
        status: string;
        status_label: string;
        control_account: string | null;
        auxiliary_cents: number | null;
        ledger_cents: number | null;
        difference_cents: number | null;
    };
}) {
    const href = usePayablesHref();

    if (company === null) {
        return null;
    }

    return (
        <>
            <Head title={`${company.name} · CxP`} />
            <Shell>
                <PageHeader
                    title={company.name}
                    subtitle={`NIT ${company.tax_id} · obligaciones con proveedores de esta empresa`}
                    actions={
                        <div className="flex items-center gap-2">
                            <span
                                className={toneBadge(
                                    companyTone[company.status] ?? 'slate',
                                )}
                            >
                                {company.status_label}
                            </span>
                            <Link
                                href={href(
                                    `/payables/proposals?company_id=${company.id}`,
                                )}
                                className={secondaryButton}
                            >
                                Preparar propuesta
                            </Link>
                        </div>
                    }
                />
                <div className="grid gap-3 md:grid-cols-4">
                    <Card title="Total por pagar">
                        <p className="text-2xl font-black">
                            {amount(company.total_cents)}
                        </p>
                    </Card>
                    <Card title="Vencido">
                        <p className="text-2xl font-black text-red-700">
                            {amount(company.overdue_cents)}
                        </p>
                    </Card>
                    <Card title="Por vencer">
                        <p className="text-2xl font-black text-emerald-700">
                            {amount(company.current_cents)}
                        </p>
                    </Card>
                    <Card title="CxP vs Mayor">
                        <p
                            className={`text-lg font-black ${reconciliation.status === 'matched' ? 'text-emerald-700' : 'text-red-700'}`}
                        >
                            {reconciliation.status_label}
                        </p>
                        <p className="text-xs text-[#526b92]">
                            Auxiliar {amount(reconciliation.auxiliary_cents)} ·
                            Mayor {amount(reconciliation.ledger_cents)}
                        </p>
                        <Link
                            href={href(
                                `/payables/reconciliation?company_id=${company.id}`,
                            )}
                            className="text-xs font-bold text-[#640000]"
                        >
                            Investigar
                        </Link>
                    </Card>
                </div>
                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Por mes de vencimiento">
                        <Table
                            headers={['Mes', 'Saldo']}
                            empty={
                                dueMonths.length === 0 && 'Sin obligaciones.'
                            }
                        >
                            {dueMonths.map((month) => (
                                <tr key={month.month}>
                                    <td className="px-2 py-2">{month.label}</td>
                                    <td className="px-2 py-2 font-semibold">
                                        {amount(month.amount_cents)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    {aging && (
                        <Card title="Antigüedad">
                            <Table headers={['Tramo', 'Saldo']} empty={false}>
                                {aging.buckets.map((bucket, index) => (
                                    <tr key={bucket}>
                                        <td className="px-2 py-2">{bucket}</td>
                                        <td
                                            className={`px-2 py-2 font-semibold ${index > 0 && (aging.totals[index] ?? 0) > 0 ? 'text-red-700' : ''}`}
                                        >
                                            {amount(aging.totals[index] ?? 0)}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    )}
                </div>
                <Card title={`Proveedores con saldo (${suppliers.length})`}>
                    <SuppliersTable
                        suppliers={suppliers}
                        empty="La empresa no tiene cuentas por pagar abiertas."
                    />
                </Card>
            </Shell>
        </>
    );
}
