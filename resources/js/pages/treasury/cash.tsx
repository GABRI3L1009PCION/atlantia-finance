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
    TreasuryContext,
    TreasuryPermissions,
} from '@/components/treasury/kit';

type CashCount = {
    id: number;
    company: string;
    account: string;
    date: string;
    system_cents: number;
    counted_cents: number;
    difference_cents: number;
    notes: string | null;
    counted_by: string;
};
type CashAccount = {
    id: number;
    code: string;
    name: string;
    company_id: number;
    company: string;
};

export default function TreasuryCash({
    counts,
    cashAccounts,
    permissions,
    notice,
}: {
    context: TreasuryContext;
    counts: CashCount[];
    cashAccounts: CashAccount[];
    permissions: TreasuryPermissions;
    notice: string | null;
}) {
    const form = useForm({
        account: cashAccounts[0]
            ? `${cashAccounts[0].company_id}:${cashAccounts[0].id}`
            : '',
        counted_on: new Date().toISOString().slice(0, 10),
        counted: '',
        notes: '',
    });

    return (
        <>
            <Head title="Caja · Tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Caja y arqueos"
                    subtitle="El conteo se compara con el saldo contable de la cuenta de caja; una diferencia abre una incidencia y nunca se ajusta automáticamente"
                />
                {permissions.canCountCash && (
                    <Card title="Registrar arqueo">
                        {cashAccounts.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                Tus empresas no tienen cuentas contables de caja
                                activas.
                            </p>
                        ) : (
                            <form
                                className="grid gap-2 md:grid-cols-2 xl:grid-cols-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    const [companyId, ledgerId] =
                                        form.data.account.split(':');
                                    form.transform((values) => ({
                                        company_id: Number(companyId),
                                        ledger_account_id: Number(ledgerId),
                                        counted_on: values.counted_on,
                                        counted: values.counted,
                                        notes: values.notes || null,
                                    }));
                                    form.post('/treasury/cash', {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            form.reset('counted', 'notes'),
                                    });
                                }}
                            >
                                <label className="grid gap-1 text-xs font-semibold">
                                    Cuenta de caja
                                    <select
                                        className={input}
                                        value={form.data.account}
                                        onChange={(event) =>
                                            form.setData(
                                                'account',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {cashAccounts.map((account) => (
                                            <option
                                                key={`${account.company_id}:${account.id}`}
                                                value={`${account.company_id}:${account.id}`}
                                            >
                                                {account.company} ·{' '}
                                                {account.code} {account.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-xs font-semibold">
                                    Fecha
                                    <input
                                        type="date"
                                        className={input}
                                        value={form.data.counted_on}
                                        onChange={(event) =>
                                            form.setData(
                                                'counted_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-semibold">
                                    Efectivo contado (Q)
                                    <input
                                        className={input}
                                        inputMode="decimal"
                                        value={form.data.counted}
                                        onChange={(event) =>
                                            form.setData(
                                                'counted',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-semibold">
                                    Notas
                                    <input
                                        className={input}
                                        value={form.data.notes}
                                        onChange={(event) =>
                                            form.setData(
                                                'notes',
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
                                        Registrar arqueo
                                    </button>
                                </div>
                                {Object.values(form.errors).length > 0 && (
                                    <p className="text-xs text-red-700 md:col-span-2 xl:col-span-5">
                                        {Object.values(form.errors).join(' ')}
                                    </p>
                                )}
                            </form>
                        )}
                    </Card>
                )}
                <Card title={`${counts.length} arqueo(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Cuenta',
                            'Según sistema',
                            'Contado',
                            'Diferencia',
                            'Registró',
                            'Notas',
                        ]}
                        empty={
                            counts.length === 0 && 'Sin arqueos registrados.'
                        }
                    >
                        {counts.map((count) => (
                            <tr key={count.id}>
                                <td className="px-2 py-2">
                                    {formatDate(count.date)}
                                </td>
                                <td className="px-2 py-2">{count.company}</td>
                                <td className="px-2 py-2">{count.account}</td>
                                <td className="px-2 py-2">
                                    {amount(count.system_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(count.counted_cents)}
                                </td>
                                <td
                                    className={`px-2 py-2 font-bold ${count.difference_cents !== 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                >
                                    {amount(count.difference_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {count.counted_by}
                                </td>
                                <td className="px-2 py-2">
                                    {count.notes ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
