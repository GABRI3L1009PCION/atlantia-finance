import { Head, Link, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    reconciliationTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    AccountOption,
    Reconciliation,
    TreasuryContext,
    TreasuryPermissions,
} from '@/components/treasury/kit';

export default function TreasuryReconciliations({
    reconciliations,
    accounts,
    cutoff,
    pendingOnly,
    permissions,
    notice,
}: {
    context: TreasuryContext;
    reconciliations: Reconciliation[];
    filters: { status?: string };
    accounts: AccountOption[];
    statuses: Record<string, string>;
    cutoff: string;
    pendingOnly: boolean;
    permissions: TreasuryPermissions;
    notice: string | null;
}) {
    const href = useTreasuryHref();
    const form = useForm({
        bank_account_id: accounts[0] ? String(accounts[0].id) : '',
        cutoff_date: cutoff,
    });
    const rows = pendingOnly
        ? reconciliations.filter(
              (row) => row.status !== 'approved' || row.stale,
          )
        : reconciliations;

    return (
        <>
            <Head title="Conciliaciones bancarias" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={
                        pendingOnly
                            ? 'Conciliaciones pendientes'
                            : 'Conciliación bancaria'
                    }
                    subtitle="Atlantia calcula saldos, partidas y diferencia; no existe un botón para cuadrar. Quien prepara no aprueba."
                />

                {permissions.canReconcile && accounts.length > 0 && (
                    <Card title="Preparar o recalcular una conciliación">
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/treasury/reconciliations');
                            }}
                        >
                            <label className="grid min-w-56 gap-1 text-xs font-semibold">
                                Cuenta
                                <select
                                    className={input}
                                    value={form.data.bank_account_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'bank_account_id',
                                            event.target.value,
                                        )
                                    }
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
                                Fecha de corte
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.cutoff_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'cutoff_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Calcular conciliación
                            </button>
                            {(form.errors.cutoff_date ||
                                form.errors.bank_account_id) && (
                                <p className="w-full text-xs text-red-700">
                                    {form.errors.cutoff_date ??
                                        form.errors.bank_account_id}
                                </p>
                            )}
                        </form>
                    </Card>
                )}

                <Card title={`${rows.length} conciliación(es)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Cuenta',
                            'Corte',
                            'Saldo banco',
                            'Saldo libros',
                            'Diferencia',
                            'Preparó',
                            'Estado',
                        ]}
                        empty={
                            rows.length === 0 &&
                            'Aún no hay conciliaciones registradas.'
                        }
                    >
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2">{row.company}</td>
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
                                    {amount(row.bank_balance_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(row.ledger_balance_cents)}
                                </td>
                                <td
                                    className={`px-2 py-2 font-bold ${row.difference_cents !== 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                >
                                    {amount(row.difference_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {row.preparer ?? '—'}
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
            </Shell>
        </>
    );
}
