import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    BadgeCheck,
    Building2,
    CalendarDays,
    CheckCircle2,
    CircleDollarSign,
    Download,
    FileSpreadsheet,
    Landmark,
    Plus,
    RefreshCw,
    Search,
    ShieldCheck,
    Unplug,
    WalletCards,
} from 'lucide-react';
import type { FormEvent } from 'react';
import type { Company } from '@/components/accounting-section';
import {
    Errors,
    field,
    money,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';

type BankAccount = {
    id: number;
    public_id: string;
    company_id: number;
    ledger_account_id: number;
    bank_name: string;
    account_name: string;
    account_type: 'checking' | 'savings';
    last_four: string;
    currency: string;
    bank_balance_cents: number | string;
    accounting_balance_cents: number | string;
    difference_cents: number | string;
    movement_count: number | string;
    pending_count: number | string;
    matched_count: number | string;
    unmatched_count: number | string;
    ledger_code: string;
    ledger_name: string;
};

type BankTransaction = {
    id: number;
    public_id: string;
    bank_account_id: number;
    transaction_date: string;
    reference: string;
    description: string;
    flow: 'inflow' | 'outflow';
    amount_cents: number | string;
    running_balance_cents: number | string;
    reconciliation_status: 'pending' | 'matched' | 'unmatched';
    bank_name: string;
    account_name: string;
    last_four: string;
};

type LedgerAccount = {
    id: number;
    code: string;
    name: string;
};

type Filters = {
    search: string;
    account: number | string | null;
    status: string;
    flow: string;
    from: string;
    to: string;
};

type Summary = {
    accounting_balance: number;
    bank_balance: number;
    difference: number;
    movements: number;
    pending: number;
    reconciled_accounts: number;
};

const numberValue = (value: number | string): number => Number(value);

const accountTypeLabel = (type: BankAccount['account_type']): string =>
    type === 'checking' ? 'Monetaria' : 'Ahorro';

const statusLabel = (
    status: BankTransaction['reconciliation_status'],
): string => {
    if (status === 'matched') {
        return 'Conciliado';
    }

    if (status === 'unmatched') {
        return 'Sin coincidencia';
    }

    return 'Pendiente';
};

const statusClass = (
    status: BankTransaction['reconciliation_status'],
): string => {
    if (status === 'matched') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (status === 'unmatched') {
        return 'bg-rose-50 text-rose-700';
    }

    return 'bg-amber-50 text-amber-700';
};

const dateLabel = (date: string): string => {
    if (!date) {
        return '—';
    }

    return new Intl.DateTimeFormat('es-GT', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    }).format(new Date(`${date}T00:00:00`));
};

export default function Banks({
    company,
    accounts,
    transactions,
    ledgerAccounts,
    filters,
    summary,
    canWrite,
    today,
}: {
    company: Company;
    accounts: BankAccount[];
    transactions: Page<BankTransaction>;
    ledgerAccounts: LedgerAccount[];
    filters: Filters;
    summary: Summary;
    canWrite: boolean;
    today: string;
}) {
    const baseUrl = `/accounting/companies/${company.public_id}/banks`;

    const filterForm = useForm({
        search: filters.search ?? '',
        account: filters.account ? String(filters.account) : '',
        status: filters.status ?? 'all',
        flow: filters.flow ?? 'all',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const accountForm = useForm({
        ledger_account_id:
            ledgerAccounts.length > 0 ? String(ledgerAccounts[0].id) : '',
        bank_name: '',
        account_name: '',
        account_type: 'checking',
        last_four: '',
        bank_balance: '0.00',
    });

    const transactionForm = useForm({
        account_id: accounts.length > 0 ? String(accounts[0].id) : '',
        transaction_date: today,
        reference: '',
        description: '',
        flow: 'inflow',
        amount: '',
    });

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();

        router.get(
            baseUrl,
            {
                search: filterForm.data.search || undefined,
                account: filterForm.data.account || undefined,
                status:
                    filterForm.data.status === 'all'
                        ? undefined
                        : filterForm.data.status,
                flow:
                    filterForm.data.flow === 'all'
                        ? undefined
                        : filterForm.data.flow,
                from: filterForm.data.from || undefined,
                to: filterForm.data.to || undefined,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const clearFilters = () => {
        filterForm.setData({
            search: '',
            account: '',
            status: 'all',
            flow: 'all',
            from: '',
            to: '',
        });

        router.get(
            baseUrl,
            {},
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const createAccount = (event: FormEvent) => {
        event.preventDefault();

        accountForm.post(`${baseUrl}/accounts`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const refreshedAccounts = page.props.accounts as BankAccount[];
                const refreshedLedgerAccounts = page.props
                    .ledgerAccounts as LedgerAccount[];

                accountForm.reset();

                accountForm.setData(
                    'ledger_account_id',
                    refreshedLedgerAccounts.length > 0
                        ? String(refreshedLedgerAccounts[0].id)
                        : '',
                );

                accountForm.setData('account_type', 'checking');
                accountForm.setData('bank_balance', '0.00');

                const selectedAccountStillExists = refreshedAccounts.some(
                    (account) =>
                        String(account.id) === transactionForm.data.account_id,
                );

                if (!selectedAccountStillExists) {
                    transactionForm.setData(
                        'account_id',
                        refreshedAccounts.length > 0
                            ? String(refreshedAccounts[0].id)
                            : '',
                    );
                }
            },
        });
    };
    const createTransaction = (event: FormEvent) => {
        event.preventDefault();

        transactionForm.post(`${baseUrl}/transactions`, {
            preserveScroll: true,
            onSuccess: () => {
                transactionForm.reset();
                transactionForm.setData('transaction_date', today);
                transactionForm.setData('flow', 'inflow');

                if (accounts.length > 0) {
                    transactionForm.setData(
                        'account_id',
                        String(accounts[0].id),
                    );
                }
            },
        });
    };

    const reconciledPercentage =
        accounts.length > 0
            ? Math.round((summary.reconciled_accounts / accounts.length) * 100)
            : 0;

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Bancos y conciliación" />

            <div className="mx-auto max-w-[1680px] space-y-5">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid size-12 place-items-center rounded-2xl bg-[#7d0808] text-white shadow-lg shadow-red-950/15">
                            <Landmark className="size-6" />
                        </span>

                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Bancos y conciliación
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Control bancario y conciliación contable de{' '}
                                {company.name}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <a
                            className={secondary}
                            href={`${baseUrl}/export?format=xlsx`}
                        >
                            <FileSpreadsheet className="size-4 text-emerald-700" />
                            Excel
                        </a>

                        <a
                            className={secondary}
                            href={`${baseUrl}/export?format=pdf`}
                        >
                            <Download className="size-4 text-red-700" />
                            PDF
                        </a>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {[
                        {
                            label: 'Saldo bancario',
                            value: money(summary.bank_balance),
                            detail: 'Saldo registrado',
                            icon: Landmark,
                            tone: 'bg-blue-50 text-blue-700',
                        },
                        {
                            label: 'Saldo contable',
                            value: money(summary.accounting_balance),
                            detail: 'Según libro mayor',
                            icon: WalletCards,
                            tone: 'bg-violet-50 text-violet-700',
                        },
                        {
                            label: 'Diferencia',
                            value: money(summary.difference),
                            detail:
                                summary.difference === 0
                                    ? 'Sin diferencias'
                                    : 'Requiere conciliación',
                            icon:
                                summary.difference === 0
                                    ? CheckCircle2
                                    : Unplug,
                            tone:
                                summary.difference === 0
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-rose-50 text-rose-700',
                        },
                        {
                            label: 'Movimientos del mes',
                            value: summary.movements,
                            detail: 'Entradas y salidas',
                            icon: RefreshCw,
                            tone: 'bg-cyan-50 text-cyan-700',
                        },
                        {
                            label: 'Pendientes',
                            value: summary.pending,
                            detail: 'Por conciliar',
                            icon: CalendarDays,
                            tone: 'bg-amber-50 text-amber-700',
                        },
                        {
                            label: 'Cuentas conciliadas',
                            value: `${summary.reconciled_accounts}/${accounts.length}`,
                            detail: `${reconciledPercentage}% conciliado`,
                            icon: BadgeCheck,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="flex min-h-28 items-center gap-3 rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-[0_7px_20px_rgba(31,38,55,.05)]"
                        >
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon className="size-5" />
                            </span>

                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block truncate text-lg">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 truncate text-[11px] text-slate-400">
                                    {metric.detail}
                                </p>
                            </div>
                        </article>
                    ))}
                </section>

                <section className="rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-sm">
                    <form
                        onSubmit={applyFilters}
                        className="grid gap-3 lg:grid-cols-[1.4fr_.8fr_.8fr_.8fr_.8fr_auto]"
                    >
                        <label className="relative">
                            <span className="sr-only">Buscar</span>
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                className={`${field} mt-0 pl-9`}
                                placeholder="Buscar referencia, banco o descripción"
                                value={filterForm.data.search}
                                onChange={(event) =>
                                    filterForm.setData(
                                        'search',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <select
                            className={`${field} mt-0`}
                            value={filterForm.data.account}
                            onChange={(event) =>
                                filterForm.setData(
                                    'account',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Todas las cuentas</option>
                            {accounts.map((account) => (
                                <option
                                    key={account.id}
                                    value={String(account.id)}
                                >
                                    {account.bank_name} ••••{account.last_four}
                                </option>
                            ))}
                        </select>

                        <select
                            className={`${field} mt-0`}
                            value={filterForm.data.status}
                            onChange={(event) =>
                                filterForm.setData('status', event.target.value)
                            }
                        >
                            <option value="all">Todos los estados</option>
                            <option value="pending">Pendientes</option>
                            <option value="matched">Conciliados</option>
                            <option value="unmatched">Sin coincidencia</option>
                        </select>

                        <select
                            className={`${field} mt-0`}
                            value={filterForm.data.flow}
                            onChange={(event) =>
                                filterForm.setData('flow', event.target.value)
                            }
                        >
                            <option value="all">Todos los flujos</option>
                            <option value="inflow">Entradas</option>
                            <option value="outflow">Salidas</option>
                        </select>

                        <input
                            className={`${field} mt-0`}
                            type="date"
                            value={filterForm.data.from}
                            onChange={(event) =>
                                filterForm.setData('from', event.target.value)
                            }
                            aria-label="Fecha inicial"
                        />

                        <div className="flex gap-2">
                            <button type="submit" className={primary}>
                                <Search className="size-4" />
                                Filtrar
                            </button>

                            <button
                                type="button"
                                className={secondary}
                                onClick={clearFilters}
                                title="Limpiar filtros"
                            >
                                <RefreshCw className="size-4" />
                            </button>
                        </div>

                        <div className="lg:col-start-5">
                            <input
                                className={`${field} mt-0`}
                                type="date"
                                value={filterForm.data.to}
                                onChange={(event) =>
                                    filterForm.setData('to', event.target.value)
                                }
                                aria-label="Fecha final"
                            />
                        </div>
                    </form>
                </section>

                <section className="grid gap-5 xl:grid-cols-[1.15fr_.85fr]">
                    <div className="space-y-5">
                        <div className="rounded-2xl border border-[#e4e8ef] bg-white p-5 shadow-sm">
                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">
                                        Cuentas bancarias
                                    </h2>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Cuentas vinculadas al plan contable de
                                        la empresa
                                    </p>
                                </div>

                                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                    {accounts.length} registradas
                                </span>
                            </div>

                            {accounts.length === 0 ? (
                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                                    <Landmark className="mx-auto size-10 text-slate-300" />
                                    <h3 className="mt-3 font-semibold">
                                        No hay cuentas bancarias
                                    </h3>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Registra una cuenta para comenzar a
                                        controlar movimientos y conciliaciones.
                                    </p>
                                </div>
                            ) : (
                                <div className="grid gap-3 md:grid-cols-2">
                                    {accounts.map((account) => {
                                        const balanced =
                                            numberValue(
                                                account.difference_cents,
                                            ) === 0 &&
                                            numberValue(
                                                account.pending_count,
                                            ) === 0;

                                        return (
                                            <article
                                                key={account.id}
                                                className="rounded-2xl border border-[#e5e8ee] p-4 transition hover:border-[#d4bcbc] hover:shadow-sm"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex items-center gap-3">
                                                        <span className="grid size-10 place-items-center rounded-xl bg-[#f8eaea] text-[#76080e]">
                                                            <Building2 className="size-5" />
                                                        </span>

                                                        <div>
                                                            <h3 className="font-semibold">
                                                                {
                                                                    account.bank_name
                                                                }
                                                            </h3>
                                                            <p className="text-xs text-slate-500">
                                                                {
                                                                    account.account_name
                                                                }{' '}
                                                                · ••••
                                                                {
                                                                    account.last_four
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                                                            balanced
                                                                ? 'bg-emerald-50 text-emerald-700'
                                                                : 'bg-amber-50 text-amber-700'
                                                        }`}
                                                    >
                                                        {balanced
                                                            ? 'Conciliada'
                                                            : 'Pendiente'}
                                                    </span>
                                                </div>

                                                <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                                    <div>
                                                        <p className="text-xs text-slate-400">
                                                            Saldo banco
                                                        </p>
                                                        <strong>
                                                            {money(
                                                                account.bank_balance_cents,
                                                            )}
                                                        </strong>
                                                    </div>

                                                    <div>
                                                        <p className="text-xs text-slate-400">
                                                            Saldo contable
                                                        </p>
                                                        <strong>
                                                            {money(
                                                                account.accounting_balance_cents,
                                                            )}
                                                        </strong>
                                                    </div>

                                                    <div>
                                                        <p className="text-xs text-slate-400">
                                                            Diferencia
                                                        </p>
                                                        <strong
                                                            className={
                                                                numberValue(
                                                                    account.difference_cents,
                                                                ) === 0
                                                                    ? 'text-emerald-700'
                                                                    : 'text-rose-700'
                                                            }
                                                        >
                                                            {money(
                                                                account.difference_cents,
                                                            )}
                                                        </strong>
                                                    </div>

                                                    <div>
                                                        <p className="text-xs text-slate-400">
                                                            Pendientes
                                                        </p>
                                                        <strong>
                                                            {
                                                                account.pending_count
                                                            }
                                                        </strong>
                                                    </div>
                                                </div>

                                                <div className="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                                    <p>
                                                        Cuenta contable:{' '}
                                                        <strong className="text-slate-700">
                                                            {
                                                                account.ledger_code
                                                            }{' '}
                                                            ·{' '}
                                                            {
                                                                account.ledger_name
                                                            }
                                                        </strong>
                                                    </p>
                                                    <p className="mt-1">
                                                        Tipo:{' '}
                                                        {accountTypeLabel(
                                                            account.account_type,
                                                        )}{' '}
                                                        ·{' '}
                                                        {account.movement_count}{' '}
                                                        movimientos
                                                    </p>
                                                </div>
                                            </article>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        <div className="rounded-2xl border border-[#e4e8ef] bg-white p-5 shadow-sm">
                            <div className="mb-4">
                                <h2 className="text-lg font-semibold">
                                    Movimientos bancarios
                                </h2>
                                <p className="mt-1 text-xs text-slate-500">
                                    Movimientos registrados para conciliación
                                    con el libro contable
                                </p>
                            </div>

                            {transactions.data.length === 0 ? (
                                <div className="rounded-xl bg-slate-50 p-8 text-center text-sm text-slate-500">
                                    No hay movimientos que coincidan con los
                                    filtros seleccionados.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[900px] text-left text-sm">
                                        <thead>
                                            <tr className="border-b border-slate-200 text-[11px] tracking-wide text-slate-500 uppercase">
                                                <th className="px-3 py-3">
                                                    Fecha
                                                </th>
                                                <th className="px-3 py-3">
                                                    Cuenta
                                                </th>
                                                <th className="px-3 py-3">
                                                    Referencia
                                                </th>
                                                <th className="px-3 py-3">
                                                    Descripción
                                                </th>
                                                <th className="px-3 py-3 text-right">
                                                    Movimiento
                                                </th>
                                                <th className="px-3 py-3 text-right">
                                                    Saldo
                                                </th>
                                                <th className="px-3 py-3">
                                                    Conciliación
                                                </th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {transactions.data.map(
                                                (movement) => (
                                                    <tr
                                                        key={movement.id}
                                                        className="border-b border-slate-100 last:border-0 hover:bg-slate-50/70"
                                                    >
                                                        <td className="px-3 py-4 whitespace-nowrap">
                                                            {dateLabel(
                                                                movement.transaction_date,
                                                            )}
                                                        </td>

                                                        <td className="px-3 py-4">
                                                            <p className="font-medium">
                                                                {
                                                                    movement.bank_name
                                                                }
                                                            </p>
                                                            <p className="text-xs text-slate-400">
                                                                ••••
                                                                {
                                                                    movement.last_four
                                                                }
                                                            </p>
                                                        </td>

                                                        <td className="px-3 py-4 font-medium">
                                                            {movement.reference}
                                                        </td>

                                                        <td className="max-w-[260px] px-3 py-4 text-slate-600">
                                                            <p className="truncate">
                                                                {
                                                                    movement.description
                                                                }
                                                            </p>
                                                        </td>

                                                        <td
                                                            className={`px-3 py-4 text-right font-semibold ${
                                                                movement.flow ===
                                                                'inflow'
                                                                    ? 'text-emerald-700'
                                                                    : 'text-rose-700'
                                                            }`}
                                                        >
                                                            <span className="inline-flex items-center justify-end gap-1">
                                                                {movement.flow ===
                                                                'inflow' ? (
                                                                    <ArrowDownLeft className="size-4" />
                                                                ) : (
                                                                    <ArrowUpRight className="size-4" />
                                                                )}
                                                                {movement.flow ===
                                                                'inflow'
                                                                    ? '+'
                                                                    : '-'}
                                                                {money(
                                                                    movement.amount_cents,
                                                                )}
                                                            </span>
                                                        </td>

                                                        <td className="px-3 py-4 text-right font-medium">
                                                            {money(
                                                                movement.running_balance_cents,
                                                            )}
                                                        </td>

                                                        <td className="px-3 py-4">
                                                            <span
                                                                className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${statusClass(
                                                                    movement.reconciliation_status,
                                                                )}`}
                                                            >
                                                                {statusLabel(
                                                                    movement.reconciliation_status,
                                                                )}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            <Pager page={transactions} />
                        </div>
                    </div>

                    <aside className="space-y-5">
                        <section className="rounded-2xl border border-[#e4e8ef] bg-white p-5 shadow-sm">
                            <div className="mb-4 flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-xl bg-[#f8eaea] text-[#76080e]">
                                    <Plus className="size-5" />
                                </span>
                                <div>
                                    <h2 className="font-semibold">
                                        Nueva cuenta bancaria
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Vinculada al plan de cuentas
                                    </p>
                                </div>
                            </div>

                            {!canWrite ? (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                    Tu permiso actual permite consultar, pero no
                                    registrar cuentas bancarias.
                                </div>
                            ) : ledgerAccounts.length === 0 ? (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                    No hay cuentas contables de activo
                                    disponibles para vincular.
                                </div>
                            ) : (
                                <form
                                    className="space-y-4"
                                    onSubmit={createAccount}
                                >
                                    <Errors errors={accountForm.errors} />

                                    <label className="block text-sm font-medium">
                                        Cuenta contable
                                        <select
                                            className={field}
                                            value={
                                                accountForm.data
                                                    .ledger_account_id
                                            }
                                            onChange={(event) =>
                                                accountForm.setData(
                                                    'ledger_account_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {ledgerAccounts.map((account) => (
                                                <option
                                                    key={account.id}
                                                    value={String(account.id)}
                                                >
                                                    {account.code} ·{' '}
                                                    {account.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <label className="block text-sm font-medium">
                                            Banco
                                            <input
                                                className={field}
                                                value={
                                                    accountForm.data.bank_name
                                                }
                                                onChange={(event) =>
                                                    accountForm.setData(
                                                        'bank_name',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Ej. Banco Industrial"
                                            />
                                        </label>

                                        <label className="block text-sm font-medium">
                                            Nombre de cuenta
                                            <input
                                                className={field}
                                                value={
                                                    accountForm.data
                                                        .account_name
                                                }
                                                onChange={(event) =>
                                                    accountForm.setData(
                                                        'account_name',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Cuenta monetaria"
                                            />
                                        </label>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <label className="block text-sm font-medium">
                                            Tipo
                                            <select
                                                className={field}
                                                value={
                                                    accountForm.data
                                                        .account_type
                                                }
                                                onChange={(event) =>
                                                    accountForm.setData(
                                                        'account_type',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="checking">
                                                    Monetaria
                                                </option>
                                                <option value="savings">
                                                    Ahorro
                                                </option>
                                            </select>
                                        </label>

                                        <label className="block text-sm font-medium">
                                            Últimos 4 dígitos
                                            <input
                                                className={field}
                                                inputMode="numeric"
                                                maxLength={4}
                                                value={
                                                    accountForm.data.last_four
                                                }
                                                onChange={(event) =>
                                                    accountForm.setData(
                                                        'last_four',
                                                        event.target.value.replace(
                                                            /\D/g,
                                                            '',
                                                        ),
                                                    )
                                                }
                                                placeholder="1234"
                                            />
                                        </label>
                                    </div>

                                    <label className="block text-sm font-medium">
                                        Saldo bancario inicial
                                        <div className="relative">
                                            <CircleDollarSign className="absolute top-1/2 left-3 mt-0.5 size-4 -translate-y-1/2 text-slate-400" />
                                            <input
                                                className={`${field} pl-9`}
                                                value={
                                                    accountForm.data
                                                        .bank_balance
                                                }
                                                onChange={(event) =>
                                                    accountForm.setData(
                                                        'bank_balance',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </div>
                                    </label>

                                    <button
                                        className={`${primary} w-full`}
                                        disabled={accountForm.processing}
                                    >
                                        <Plus className="size-4" />
                                        {accountForm.processing
                                            ? 'Registrando...'
                                            : 'Registrar cuenta'}
                                    </button>
                                </form>
                            )}
                        </section>

                        <section className="rounded-2xl border border-[#e4e8ef] bg-white p-5 shadow-sm">
                            <div className="mb-4 flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-xl bg-[#f8eaea] text-[#76080e]">
                                    <RefreshCw className="size-5" />
                                </span>
                                <div>
                                    <h2 className="font-semibold">
                                        Registrar movimiento
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Movimiento pendiente de conciliación
                                    </p>
                                </div>
                            </div>

                            {!canWrite ? (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                    Tu permiso actual es únicamente de consulta.
                                </div>
                            ) : accounts.length === 0 ? (
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                                    Primero debes registrar una cuenta bancaria.
                                </div>
                            ) : (
                                <form
                                    className="space-y-4"
                                    onSubmit={createTransaction}
                                >
                                    <Errors errors={transactionForm.errors} />

                                    <label className="block text-sm font-medium">
                                        Cuenta bancaria
                                        <select
                                            className={field}
                                            value={
                                                transactionForm.data.account_id
                                            }
                                            onChange={(event) =>
                                                transactionForm.setData(
                                                    'account_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {accounts.map((account) => (
                                                <option
                                                    key={account.id}
                                                    value={String(account.id)}
                                                >
                                                    {account.bank_name} · ••••
                                                    {account.last_four}
                                                </option>
                                            ))}
                                        </select>
                                    </label>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <label className="block text-sm font-medium">
                                            Fecha
                                            <input
                                                className={field}
                                                type="date"
                                                max={today}
                                                value={
                                                    transactionForm.data
                                                        .transaction_date
                                                }
                                                onChange={(event) =>
                                                    transactionForm.setData(
                                                        'transaction_date',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>

                                        <label className="block text-sm font-medium">
                                            Flujo
                                            <select
                                                className={field}
                                                value={
                                                    transactionForm.data.flow
                                                }
                                                onChange={(event) =>
                                                    transactionForm.setData(
                                                        'flow',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="inflow">
                                                    Entrada
                                                </option>
                                                <option value="outflow">
                                                    Salida
                                                </option>
                                            </select>
                                        </label>
                                    </div>

                                    <label className="block text-sm font-medium">
                                        Referencia
                                        <input
                                            className={field}
                                            value={
                                                transactionForm.data.reference
                                            }
                                            onChange={(event) =>
                                                transactionForm.setData(
                                                    'reference',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Ej. DEP-2026-001"
                                        />
                                    </label>

                                    <label className="block text-sm font-medium">
                                        Descripción
                                        <textarea
                                            className={`${field} min-h-20 resize-y`}
                                            value={
                                                transactionForm.data.description
                                            }
                                            onChange={(event) =>
                                                transactionForm.setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Descripción del movimiento"
                                        />
                                    </label>

                                    <label className="block text-sm font-medium">
                                        Monto
                                        <div className="relative">
                                            <CircleDollarSign className="absolute top-1/2 left-3 mt-0.5 size-4 -translate-y-1/2 text-slate-400" />
                                            <input
                                                className={`${field} pl-9`}
                                                value={
                                                    transactionForm.data.amount
                                                }
                                                onChange={(event) =>
                                                    transactionForm.setData(
                                                        'amount',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </div>
                                    </label>

                                    <button
                                        className={`${primary} w-full`}
                                        disabled={transactionForm.processing}
                                    >
                                        {transactionForm.data.flow ===
                                        'inflow' ? (
                                            <ArrowDownLeft className="size-4" />
                                        ) : (
                                            <ArrowUpRight className="size-4" />
                                        )}
                                        {transactionForm.processing
                                            ? 'Registrando...'
                                            : 'Registrar movimiento'}
                                    </button>
                                </form>
                            )}
                        </section>

                        <section className="rounded-2xl border border-[#eadfdf] bg-[#fff9f1] p-5">
                            <div className="flex items-start gap-3">
                                <ShieldCheck className="mt-0.5 size-5 shrink-0 text-[#8a4b00]" />
                                <div>
                                    <h3 className="font-semibold text-[#5d3600]">
                                        Control de conciliación
                                    </h3>
                                    <p className="mt-1 text-sm leading-6 text-[#75501d]">
                                        El saldo bancario y el saldo contable se
                                        mantienen separados. Una diferencia no
                                        modifica automáticamente el libro
                                        contable y debe ser revisada antes de
                                        considerarse conciliada.
                                    </p>
                                </div>
                            </div>
                        </section>
                    </aside>
                </section>
            </div>
        </div>
    );
}
