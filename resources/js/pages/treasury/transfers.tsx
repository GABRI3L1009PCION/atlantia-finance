import { Head, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';
import { amount } from '@/components/treasury/kit';
import type {
    AccountOption,
    TreasuryContext,
    TreasuryPermissions,
} from '@/components/treasury/kit';
import { statusLabel } from '@/lib/status-labels';

type Transfer = {
    id: number;
    date: string;
    reference: string;
    amount_cents: number;
    status: string;
    company: string;
    from: string;
    to: string;
};

export default function TreasuryTransfers({
    transfers,
    accounts,
    permissions,
    notice,
}: {
    context: TreasuryContext;
    transfers: Transfer[];
    accounts: AccountOption[];
    permissions: TreasuryPermissions;
    notice: string | null;
}) {
    const form = useForm({
        from_bank_account_id: accounts[0] ? String(accounts[0].id) : '',
        to_bank_account_id: '',
        amount: '',
        transfer_date: new Date().toISOString().slice(0, 10),
        reference: '',
    });
    const origin = accounts.find(
        (account) => String(account.id) === form.data.from_bank_account_id,
    );
    const targets = accounts.filter(
        (account) =>
            account.company_id === origin?.company_id &&
            account.id !== origin?.id,
    );

    return (
        <>
            <Head title="Transferencias · Tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Transferencias entre cuentas"
                    subtitle="Solo entre cuentas de la misma empresa y la misma moneda; se crean dos movimientos enlazados y un asiento en borrador"
                />
                {permissions.canTransfer && accounts.length > 1 && (
                    <Card title="Registrar transferencia">
                        <form
                            className="grid gap-2 md:grid-cols-3 xl:grid-cols-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/treasury/transfers', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset('amount', 'reference'),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-xs font-semibold">
                                Desde
                                <select
                                    className={input}
                                    value={form.data.from_bank_account_id}
                                    onChange={(event) => {
                                        form.setData({
                                            ...form.data,
                                            from_bank_account_id:
                                                event.target.value,
                                            to_bank_account_id: '',
                                        });
                                    }}
                                >
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.company} · {account.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Hacia
                                <select
                                    className={input}
                                    value={form.data.to_bank_account_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'to_bank_account_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Elegir…</option>
                                    {targets.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Monto (Q)
                                <input
                                    className={input}
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Fecha
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.transfer_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'transfer_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Referencia
                                <input
                                    className={input}
                                    value={form.data.reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'reference',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="flex items-end">
                                <button
                                    type="submit"
                                    className={primaryButton}
                                    disabled={form.processing}
                                >
                                    Registrar
                                </button>
                            </div>
                            {Object.values(form.errors).length > 0 && (
                                <p className="text-xs text-red-700 md:col-span-3 xl:col-span-6">
                                    {Object.values(form.errors).join(' ')}
                                </p>
                            )}
                        </form>
                    </Card>
                )}
                <Card title={`${transfers.length} transferencia(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Desde',
                            'Hacia',
                            'Referencia',
                            'Monto',
                            'Estado',
                        ]}
                        empty={
                            transfers.length === 0 &&
                            'Sin transferencias registradas.'
                        }
                    >
                        {transfers.map((transfer) => (
                            <tr key={transfer.id}>
                                <td className="px-2 py-2">
                                    {formatDate(transfer.date)}
                                </td>
                                <td className="px-2 py-2">
                                    {transfer.company}
                                </td>
                                <td className="px-2 py-2">{transfer.from}</td>
                                <td className="px-2 py-2">{transfer.to}</td>
                                <td className="px-2 py-2 font-bold">
                                    {transfer.reference}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {amount(transfer.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {statusLabel(transfer.status)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
