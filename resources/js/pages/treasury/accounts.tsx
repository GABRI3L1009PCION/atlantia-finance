import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { amount, useTreasuryHref } from '@/components/treasury/kit';
import type { BankAccount, TreasuryContext } from '@/components/treasury/kit';

export default function TreasuryAccounts({
    accounts,
}: {
    context: TreasuryContext;
    accounts: BankAccount[];
}) {
    const href = useTreasuryHref();

    return (
        <>
            <Head title="Bancos · Tesorería" />
            <Shell>
                <PageHeader
                    title="Bancos y cuentas"
                    subtitle="Saldo bancario (movimientos registrados o importados) frente al saldo contable (asientos contabilizados), por cuenta"
                />
                <Card title={`${accounts.length} cuenta(s) activa(s)`}>
                    <Table
                        headers={[
                            'Cuenta',
                            'Empresa',
                            'Cuenta contable',
                            'Saldo bancario',
                            'Saldo contable',
                            'Diferencia',
                            'Pendientes',
                            'Última conciliación',
                            '',
                        ]}
                        empty={
                            accounts.length === 0 &&
                            'No hay cuentas bancarias activas en tus empresas.'
                        }
                    >
                        {accounts.map((account) => (
                            <tr key={account.id}>
                                <td className="px-2 py-2 font-bold">
                                    {account.label}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {account.account_name} · {account.type}{' '}
                                        · {account.currency}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{account.company}</td>
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
                                    className={`px-2 py-2 ${account.difference_cents !== 0 ? 'font-bold text-red-700' : 'text-emerald-700'}`}
                                >
                                    {amount(account.difference_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {account.pending_movements}
                                </td>
                                <td className="px-2 py-2">
                                    {account.last_reconciliation ? (
                                        <span
                                            className={toneBadge(
                                                account.last_reconciliation
                                                    .status === 'approved'
                                                    ? 'green'
                                                    : 'amber',
                                            )}
                                        >
                                            {formatDate(
                                                account.last_reconciliation
                                                    .cutoff_date,
                                            )}{' '}
                                            ·{' '}
                                            {
                                                account.last_reconciliation
                                                    .status_label
                                            }
                                        </span>
                                    ) : (
                                        'Nunca'
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex gap-1">
                                        <Link
                                            href={href(
                                                `/treasury/movements?account_id=${account.id}&status=all`,
                                            )}
                                            className={secondaryButton}
                                        >
                                            Movimientos
                                        </Link>
                                        <a
                                            href={account.destination}
                                            className={secondaryButton}
                                        >
                                            Módulo Bancos
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        Una diferencia no se corrige desde aquí: se explica en
                        la conciliación bancaria.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
