import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    companyTone,
    movementTone,
    paymentTone,
    receiptTone,
    reconciliationTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    BankAccount,
    CashFlowRow,
    Movement,
    Payment,
    Receipt,
    Reconciliation,
    TreasuryCompany,
    TreasuryContext,
} from '@/components/treasury/kit';

export default function TreasuryCompanyDetail({
    company,
    accounts,
    movements,
    payments,
    receipts,
    reconciliations,
    cashFlow,
}: {
    context: TreasuryContext;
    company: TreasuryCompany;
    accounts: BankAccount[];
    movements: Movement[];
    payments: Payment[];
    receipts: Receipt[];
    reconciliations: Reconciliation[];
    cashFlow: CashFlowRow[];
}) {
    const href = useTreasuryHref();
    const flow = cashFlow[0];

    return (
        <>
            <Head title={`${company.name} · Tesorería`} />
            <Shell>
                <PageHeader
                    title={company.name}
                    subtitle={`NIT ${company.tax_id} · Tesorería al corte ${formatDate(company.cutoff)}`}
                    actions={
                        <div className="flex items-center gap-2">
                            <span
                                className={toneBadge(
                                    companyTone[company.status] ?? 'slate',
                                )}
                            >
                                {company.status_label}
                            </span>
                            <a
                                href={company.company_dashboard}
                                className={secondaryButton}
                            >
                                Abrir empresa
                            </a>
                        </div>
                    }
                />

                <Card title="Cuentas bancarias">
                    <Table
                        headers={[
                            'Cuenta',
                            'Cuenta contable',
                            'Saldo bancario',
                            'Saldo contable',
                            'Diferencia',
                            'Pendientes',
                            'Última conciliación',
                        ]}
                        empty={
                            accounts.length === 0 &&
                            'La empresa no tiene cuentas bancarias activas.'
                        }
                    >
                        {accounts.map((account) => (
                            <tr key={account.id}>
                                <td className="px-2 py-2 font-bold">
                                    {account.label}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {account.type} · {account.currency}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {account.ledger_account}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(account.bank_balance_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(account.ledger_balance_cents)}
                                </td>
                                <td
                                    className={`px-2 py-2 ${account.difference_cents !== 0 ? 'font-bold text-red-700' : ''}`}
                                >
                                    {amount(account.difference_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(
                                            `/treasury/movements?account_id=${account.id}`,
                                        )}
                                        className="font-bold text-[#640000]"
                                    >
                                        {account.pending_movements}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(account.last_reconciled_on)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                {flow && (
                    <Card title="Flujo de caja del mes">
                        <div className="grid gap-2 text-sm sm:grid-cols-3">
                            <p>
                                Entradas reales{' '}
                                <b className="block text-emerald-700">
                                    {amount(flow.realized_in_cents)}
                                </b>
                            </p>
                            <p>
                                Salidas reales{' '}
                                <b className="block text-red-700">
                                    {amount(flow.realized_out_cents)}
                                </b>
                            </p>
                            <p>
                                Neto proyectado{' '}
                                <b className="block">
                                    {amount(flow.forecast_net_cents)}
                                </b>
                            </p>
                        </div>
                    </Card>
                )}

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Movimientos por conciliar">
                        <Table
                            headers={['Fecha', 'Referencia', 'Monto', 'Estado']}
                            empty={
                                movements.length === 0 &&
                                'Sin movimientos pendientes.'
                            }
                        >
                            {movements.map((movement) => (
                                <tr key={movement.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(movement.date)}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {movement.reference}
                                    </td>
                                    <td className="px-2 py-2">
                                        {movement.flow === 'inflow' ? '+' : '−'}
                                        {amount(movement.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                movementTone[movement.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {movement.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Conciliaciones">
                        <Table
                            headers={[
                                'Cuenta',
                                'Corte',
                                'Diferencia',
                                'Estado',
                            ]}
                            empty={
                                reconciliations.length === 0 &&
                                'Sin conciliaciones registradas.'
                            }
                        >
                            {reconciliations.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(row.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {row.account}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(row.cutoff_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(row.difference_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                row.stale
                                                    ? 'amber'
                                                    : (reconciliationTone[
                                                          row.status
                                                      ] ?? 'slate'),
                                            )}
                                        >
                                            {row.stale
                                                ? 'Desactualizada'
                                                : row.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Pagos abiertos">
                        <Table
                            headers={['Proveedor', 'Monto', 'Estado']}
                            empty={
                                payments.length === 0 && 'Sin pagos abiertos.'
                            }
                        >
                            {payments.map((payment) => (
                                <tr key={payment.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(payment.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {payment.supplier}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(payment.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                paymentTone[payment.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {payment.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Cobros recientes">
                        <Table
                            headers={[
                                'Cliente',
                                'Recibido',
                                'Sin aplicar',
                                'Estado',
                            ]}
                            empty={receipts.length === 0 && 'Sin cobros.'}
                        >
                            {receipts.map((receipt) => (
                                <tr key={receipt.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {receipt.customer}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(receipt.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(receipt.unapplied_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                receiptTone[receipt.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {receipt.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
            </Shell>
        </>
    );
}
