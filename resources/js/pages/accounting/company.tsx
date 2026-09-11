import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowDownLeft,
    ArrowUpRight,
    BookOpen,
    Building2,
    CalendarDays,
    Check,
    ChevronRight,
    Clock3,
    FileChartColumn,
    FileDown,
    FileSpreadsheet,
    FilePenLine,
    FilePlus2,
    FolderUp,
    Landmark,
    ListTree,
    LockKeyhole,
    MoreHorizontal,
    NotebookTabs,
    Paperclip,
    PieChart,
    Plus,
    Printer,
    RefreshCw,
    Search,
    Scale,
    SlidersHorizontal,
    Trash2,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Card,
    Errors,
    field,
    money,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { hasPlanFeature } from '@/lib/plan-capabilities';
import type { PlanFeature } from '@/lib/plan-capabilities';
import * as routes from '@/routes/accounting';
import * as fel from '@/routes/accounting/fel';

type AccountingModule = {
    title: string;
    subtitle: string;
    icon: LucideIcon;
    action: () => void;
    tone: string;
    requiredFeature?: PlanFeature;
};

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    created_at?: string;
    updated_at?: string;
};
type Period = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    closed_at: string | null;
    entries_count: number;
    posted_count: number;
    draft_count: number;
    void_count: number;
    debit_cents: number;
    credit_cents: number;
};
type Line = {
    code: string;
    name: string;
    debit_cents: number;
    credit_cents: number;
};
type Entry = {
    id: number;
    public_id: string;
    number: number | null;
    status: string;
    entry_date: string;
    description: string;
    reference: string | null;
    fel_document_id: string | null;
    kind: string;
    author: string;
    approver: string | null;
    posted_at: string | null;
    period_name: string;
    reversal_of: number | null;
    reversed_by: string | null;
    lines: Line[];
    documents: {
        public_id: string;
        original_name: string;
        mime_type: string;
        size_bytes: number;
    }[];
};
type Balance = Account & { debit_cents: string; credit_cents: string };
type Movement = Line & {
    entry_date: string;
    number: number;
    description: string;
    period_name: string;
};
const kinds: Record<string, string> = {
    standard: 'Movimiento ordinario',
    opening: 'Saldos iniciales',
    adjustment: 'Asiento de ajuste',
    closing: 'Asiento de cierre',
    reversal: 'Reversión',
};
type Props = {
    accountHistory: {
        id: number;
        date: string;
        actor: string | null;
        before: { code: string; name: string; type: string };
        after: { code: string; name: string; type: string; reason: string };
    }[];
    company: {
        public_id: string;
        name: string;
        tax_id: string;
        office_name: string;
    };
    canManage: boolean;
    canWrite: boolean;
    accounts: Account[];
    periods: Period[];
    journal: Page<Entry>;
    balances: Balance[];
    ledger: Page<Movement> | null;
    members: { id: number; name: string; permission: string }[];
    filters: { period: number; account: number };
    notice: string | null;
};
const types: Record<string, string> = {
    asset: 'Activo',
    liability: 'Pasivo',
    equity: 'Patrimonio',
    income: 'Ingreso',
    expense: 'Gasto',
};
const statuses: Record<string, string> = {
    draft: 'Borrador',
    posted: 'Contabilizado',
    void: 'Descartado',
};
const today = () => new Date().toLocaleDateString('en-CA');
const emptyLine = () => ({ account_id: '', debit: '0', credit: '0' });
const sumCents = (value: string) =>
    /^\d{1,10}(\.\d{0,2})?$/.test(value)
        ? Number(value.split('.')[0]) * 100 +
          Number((value.split('.')[1] ?? '').padEnd(2, '0'))
        : 0;

type BookFilter = (values: { period?: number; account?: number }) => void;

function LedgerView({
    uuid,
    accounts,
    periods,
    balances,
    ledger,
    filters,
    onFilter,
}: {
    uuid: string;
    accounts: Account[];
    periods: Period[];
    balances: Balance[];
    ledger: Page<Movement> | null;
    filters: Props['filters'];
    onFilter: BookFilter;
}) {
    const [search, setSearch] = useState('');
    const [group, setGroup] = useState('all');
    const normalizedSearch = search.trim().toLocaleLowerCase();
    const visibleAccounts = balances.filter(
        (account) =>
            (group === 'all' || account.type === group) &&
            (!normalizedSearch ||
                `${account.code} ${account.name}`
                    .toLocaleLowerCase()
                    .includes(normalizedSearch)),
    );
    const selectedAccount = accounts.find(
        (account) => account.id === Number(filters.account),
    );
    const selectedBalance = balances.find(
        (account) => account.id === Number(filters.account),
    );
    const totalDebit = balances.reduce(
        (sum, account) => sum + Number(account.debit_cents),
        0,
    );
    const totalCredit = balances.reduce(
        (sum, account) => sum + Number(account.credit_cents),
        0,
    );
    const ledgerData = ledger?.data ?? [];
    const movements = ledgerData.map((movement, index) => ({
        ...movement,
        runningBalance: ledgerData
            .slice(0, index + 1)
            .reduce(
                (sum, row) =>
                    sum + Number(row.debit_cents) - Number(row.credit_cents),
                0,
            ),
    }));
    const selectedPeriod = periods.find(
        (period) => period.id === Number(filters.period),
    );
    const from =
        selectedPeriod?.starts_on ?? periods.at(-1)?.starts_on ?? today();
    const to = selectedPeriod?.ends_on ?? periods[0]?.ends_on ?? today();

    return (
        <div id="libro-mayor" className="scroll-mt-20 space-y-4">
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {[
                    {
                        label: 'Cuentas con movimiento',
                        value: balances.length,
                        hint: `de ${accounts.length} cuentas`,
                        icon: BookOpen,
                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                    },
                    {
                        label: 'Total débitos',
                        value: money(totalDebit),
                        hint: 'En el período',
                        icon: ArrowDownLeft,
                        tone: 'bg-[#e7f5ec] text-[#3b9b68]',
                    },
                    {
                        label: 'Total créditos',
                        value: money(totalCredit),
                        hint: 'En el período',
                        icon: ArrowUpRight,
                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                    },
                    {
                        label: 'Diferencia',
                        value: money(Math.abs(totalDebit - totalCredit)),
                        hint:
                            totalDebit === totalCredit
                                ? 'Contabilidad cuadrada'
                                : 'Requiere revisión',
                        icon: Scale,
                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                    },
                    {
                        label: 'Estado del período',
                        value: selectedPeriod?.closed_at
                            ? 'Cerrado'
                            : 'Abierto',
                        hint: selectedPeriod?.name ?? 'Todos los períodos',
                        icon: CalendarDays,
                        tone: 'bg-[#e9f0fb] text-[#315fa8]',
                    },
                ].map((metric) => (
                    <article
                        key={metric.label}
                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]"
                    >
                        <div className="flex items-center gap-3">
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block truncate text-base text-[#172033]">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </div>
                    </article>
                ))}
            </section>

            <section className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <label className="relative xl:col-span-2">
                        <span className="sr-only">Buscar cuenta</span>
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar cuenta o movimiento..."
                            className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-xs outline-none focus:border-[#8a1630]"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Período
                        <select
                            value={filters.period}
                            onChange={(event) =>
                                onFilter({ period: Number(event.target.value) })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value={0}>Todos los períodos</option>
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Grupo contable
                        <select
                            value={group}
                            onChange={(event) => setGroup(event.target.value)}
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(types).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end gap-2">
                        <a
                            href={routes.exportMethod.url(uuid, {
                                query: {
                                    kind: 'ledger',
                                    format: 'xlsx',
                                    from,
                                    to,
                                },
                            })}
                            className={secondary}
                        >
                            <FileSpreadsheet size={15} /> Excel
                        </a>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className={secondary}
                        >
                            <Printer size={15} /> Imprimir
                        </button>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-[minmax(280px,.75fr)_minmax(0,2.1fr)]">
                <aside className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                    <div className="border-b border-slate-100 px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Cuentas contables
                        </h2>
                        <p className="mt-1 text-[10px] text-slate-400">
                            Selecciona una cuenta para consultar su mayor.
                        </p>
                    </div>
                    <div className="max-h-[520px] overflow-y-auto p-2">
                        {!visibleAccounts.length ? (
                            <p className="p-5 text-center text-xs text-slate-500">
                                No hay cuentas con esos filtros.
                            </p>
                        ) : (
                            visibleAccounts.map((account) => {
                                const net =
                                    Number(account.debit_cents) -
                                    Number(account.credit_cents);

                                return (
                                    <button
                                        key={account.id}
                                        type="button"
                                        onClick={() =>
                                            onFilter({ account: account.id })
                                        }
                                        className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-left transition ${Number(filters.account) === account.id ? 'bg-[#f8e9ea] text-[#760b20]' : 'hover:bg-[#faf8f7]'}`}
                                    >
                                        <span className="min-w-0">
                                            <strong className="block text-xs">
                                                {account.code} · {account.name}
                                            </strong>
                                            <span className="mt-1 block text-[9px] text-slate-400">
                                                {types[account.type]}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-[11px] font-semibold tabular-nums">
                                            {money(Math.abs(net))}
                                        </span>
                                    </button>
                                );
                            })
                        )}
                    </div>
                </aside>

                <div className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                    {selectedAccount ? (
                        <>
                            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-4">
                                <div>
                                    <h2 className="text-lg font-bold text-[#172033]">
                                        {selectedAccount.code} ·{' '}
                                        {selectedAccount.name}
                                    </h2>
                                    <p className="mt-1 text-[10px] text-slate-500">
                                        Naturaleza:{' '}
                                        {['asset', 'expense'].includes(
                                            selectedAccount.type,
                                        )
                                            ? 'Deudora'
                                            : 'Acreedora'}{' '}
                                        · Tipo: {types[selectedAccount.type]}
                                    </p>
                                </div>
                                <span className="rounded-full bg-[#f8e9ea] px-3 py-1 text-[10px] font-semibold text-[#7d0914]">
                                    Mayor auxiliar
                                </span>
                            </div>
                            <div className="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-3">
                                {[
                                    {
                                        label: 'Débitos del período',
                                        value: money(
                                            Number(
                                                selectedBalance?.debit_cents ??
                                                    0,
                                            ),
                                        ),
                                        tone: 'text-emerald-700',
                                    },
                                    {
                                        label: 'Créditos del período',
                                        value: money(
                                            Number(
                                                selectedBalance?.credit_cents ??
                                                    0,
                                            ),
                                        ),
                                        tone: 'text-rose-700',
                                    },
                                    {
                                        label: 'Saldo neto',
                                        value: money(
                                            Math.abs(
                                                Number(
                                                    selectedBalance?.debit_cents ??
                                                        0,
                                                ) -
                                                    Number(
                                                        selectedBalance?.credit_cents ??
                                                            0,
                                                    ),
                                            ),
                                        ),
                                        tone: 'text-[#243d78]',
                                    },
                                ].map((summary) => (
                                    <div
                                        key={summary.label}
                                        className="rounded-xl border border-slate-200 p-3 text-center"
                                    >
                                        <p className="text-[10px] text-slate-500">
                                            {summary.label}
                                        </p>
                                        <strong
                                            className={`mt-1 block text-base ${summary.tone}`}
                                        >
                                            {summary.value}
                                        </strong>
                                    </div>
                                ))}
                            </div>
                            {!ledger ? null : !movements.length ? (
                                <div className="grid min-h-64 place-items-center p-8 text-center">
                                    <div>
                                        <BookOpen className="mx-auto text-[#b67e7d]" />
                                        <p className="mt-3 text-sm font-medium">
                                            Esta cuenta no tiene movimientos.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[760px] text-xs">
                                        <thead className="bg-[#18294a] text-left text-white">
                                            <tr>
                                                <th className="px-3 py-2">
                                                    Fecha
                                                </th>
                                                <th className="px-3 py-2">
                                                    Asiento
                                                </th>
                                                <th className="px-3 py-2">
                                                    Concepto
                                                </th>
                                                <th className="px-3 py-2 text-right">
                                                    Debe
                                                </th>
                                                <th className="px-3 py-2 text-right">
                                                    Haber
                                                </th>
                                                <th className="px-3 py-2 text-right">
                                                    Saldo
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {movements.map(
                                                (movement, index) => (
                                                    <tr
                                                        key={`${movement.number}-${index}`}
                                                        className="border-b border-slate-100"
                                                    >
                                                        <td className="px-3 py-2">
                                                            {
                                                                movement.entry_date
                                                            }
                                                        </td>
                                                        <td className="px-3 py-2 font-medium text-[#8a1630]">
                                                            DI-
                                                            {String(
                                                                movement.number,
                                                            ).padStart(6, '0')}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {
                                                                movement.description
                                                            }
                                                        </td>
                                                        <td className="px-3 py-2 text-right text-emerald-700 tabular-nums">
                                                            {money(
                                                                movement.debit_cents,
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-right text-rose-700 tabular-nums">
                                                            {money(
                                                                movement.credit_cents,
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-right font-semibold tabular-nums">
                                                            {money(
                                                                Math.abs(
                                                                    movement.runningBalance,
                                                                ),
                                                            )}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {ledger && <Pager page={ledger} />}
                        </>
                    ) : (
                        <div className="grid min-h-[430px] place-items-center p-8 text-center">
                            <div>
                                <BookOpen className="mx-auto text-[#b67e7d]" />
                                <p className="mt-3 text-sm font-medium">
                                    Selecciona una cuenta contable
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Sus movimientos aparecerán en este panel.
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}

function TrialBalanceView({
    uuid,
    periods,
    accounts,
    balances,
    filters,
    pendingEntries,
    onFilter,
}: {
    uuid: string;
    periods: Period[];
    accounts: Account[];
    balances: Balance[];
    filters: Props['filters'];
    pendingEntries: number;
    onFilter: BookFilter;
}) {
    const [search, setSearch] = useState('');
    const [group, setGroup] = useState('all');
    const normalizedSearch = search.trim().toLocaleLowerCase();
    const rows = balances.filter(
        (account) =>
            (group === 'all' || account.type === group) &&
            (!normalizedSearch ||
                `${account.code} ${account.name}`
                    .toLocaleLowerCase()
                    .includes(normalizedSearch)),
    );
    const totalDebit = balances.reduce(
        (sum, account) => sum + Number(account.debit_cents),
        0,
    );
    const totalCredit = balances.reduce(
        (sum, account) => sum + Number(account.credit_cents),
        0,
    );
    const totalDebtor = balances.reduce(
        (sum, account) =>
            sum +
            Math.max(
                0,
                Number(account.debit_cents) - Number(account.credit_cents),
            ),
        0,
    );
    const totalCreditor = balances.reduce(
        (sum, account) =>
            sum +
            Math.max(
                0,
                Number(account.credit_cents) - Number(account.debit_cents),
            ),
        0,
    );
    const selectedPeriod = periods.find(
        (period) => period.id === Number(filters.period),
    );
    const from =
        selectedPeriod?.starts_on ?? periods.at(-1)?.starts_on ?? today();
    const to = selectedPeriod?.ends_on ?? periods[0]?.ends_on ?? today();
    const squared = totalDebit === totalCredit;

    return (
        <div id="balance-comprobacion" className="scroll-mt-20 space-y-4">
            <div className="flex flex-wrap justify-end gap-2">
                {(['xlsx', 'pdf'] as const).map((format) => (
                    <a
                        key={format}
                        href={routes.exportMethod.url(uuid, {
                            query: {
                                kind: 'trial',
                                format,
                                from,
                                to,
                            },
                        })}
                        className={secondary}
                    >
                        {format === 'xlsx' ? (
                            <FileSpreadsheet size={15} />
                        ) : (
                            <FileDown size={15} />
                        )}
                        Exportar {format === 'xlsx' ? 'Excel' : 'PDF'}
                    </a>
                ))}
                <button
                    type="button"
                    onClick={() => window.print()}
                    className={secondary}
                >
                    <Printer size={15} /> Imprimir
                </button>
            </div>

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {[
                    {
                        label: 'Cuentas con movimiento',
                        value: balances.length,
                        hint: `de ${accounts.length} cuentas`,
                        icon: BookOpen,
                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                    },
                    {
                        label: 'Total débitos',
                        value: money(totalDebit),
                        hint: 'En el período',
                        icon: ArrowDownLeft,
                        tone: 'bg-[#e7f5ec] text-[#3b9b68]',
                    },
                    {
                        label: 'Total créditos',
                        value: money(totalCredit),
                        hint: 'En el período',
                        icon: ArrowUpRight,
                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                    },
                    {
                        label: 'Diferencia',
                        value: money(Math.abs(totalDebit - totalCredit)),
                        hint: 'Débitos − créditos',
                        icon: Scale,
                        tone: 'bg-[#e9f0fb] text-[#315fa8]',
                    },
                    {
                        label: 'Estado',
                        value: squared ? 'Cuadrado' : 'Por revisar',
                        hint: squared
                            ? 'Contabilidad cuadrada'
                            : 'Existen diferencias',
                        icon: Clock3,
                        tone: squared
                            ? 'bg-[#e7f5ec] text-[#218451]'
                            : 'bg-[#fff0e7] text-[#d2632d]',
                    },
                ].map((metric) => (
                    <article
                        key={metric.label}
                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]"
                    >
                        <div className="flex items-center gap-3">
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block truncate text-base text-[#172033]">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </div>
                    </article>
                ))}
            </section>

            <section className="grid gap-px overflow-hidden rounded-2xl border border-[#e8e4e2] bg-slate-100 shadow-[0_7px_18px_rgba(31,38,55,.05)] md:grid-cols-3">
                {[
                    {
                        label: squared
                            ? 'Débitos y créditos coinciden'
                            : 'El balance requiere revisión',
                        detail: squared
                            ? 'El balance está cuadrado'
                            : 'Revisa los movimientos contabilizados',
                        icon: Check,
                        tone: squared ? 'text-emerald-600' : 'text-amber-600',
                    },
                    {
                        label: `${pendingEntries} asientos pendientes`,
                        detail: 'Pendientes de aprobación o revisión',
                        icon: Clock3,
                        tone: 'text-amber-500',
                    },
                    {
                        label: `${balances.length} cuentas analizadas`,
                        detail: selectedPeriod?.name ?? 'Todos los períodos',
                        icon: Scale,
                        tone: 'text-[#315fa8]',
                    },
                ].map((notice) => (
                    <div
                        key={notice.label}
                        className="flex items-center gap-3 bg-white px-5 py-4"
                    >
                        <notice.icon size={21} className={notice.tone} />
                        <div>
                            <strong className="block text-xs">
                                {notice.label}
                            </strong>
                            <span className="mt-1 block text-[10px] text-slate-500">
                                {notice.detail}
                            </span>
                        </div>
                    </div>
                ))}
            </section>

            <section className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <label className="relative xl:col-span-2">
                        <span className="sr-only">Buscar cuenta</span>
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por código o nombre..."
                            className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-xs outline-none focus:border-[#8a1630]"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Período
                        <select
                            value={filters.period}
                            onChange={(event) =>
                                onFilter({ period: Number(event.target.value) })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value={0}>Todos los períodos</option>
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Grupo contable
                        <select
                            value={group}
                            onChange={(event) => setGroup(event.target.value)}
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(types).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </section>

            <section className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                {!rows.length ? (
                    <div className="py-12 text-center">
                        <Scale className="mx-auto text-[#b67e7d]" />
                        <p className="mt-3 text-sm font-medium">
                            No hay saldos para mostrar.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[850px] text-xs">
                            <thead className="bg-[#f8f7f6] text-left text-[10px] text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Código</th>
                                    <th className="px-4 py-3">
                                        Cuenta contable
                                    </th>
                                    <th className="px-4 py-3">Grupo</th>
                                    <th className="px-4 py-3 text-right">
                                        Débitos
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Créditos
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Saldo deudor
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Saldo acreedor
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((account) => {
                                    const net =
                                        Number(account.debit_cents) -
                                        Number(account.credit_cents);

                                    return (
                                        <tr
                                            key={account.id}
                                            className="border-t border-slate-100"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {account.code}
                                            </td>
                                            <td className="px-4 py-3">
                                                {account.name}
                                            </td>
                                            <td className="px-4 py-3 text-slate-500">
                                                {types[account.type]}
                                            </td>
                                            <td className="px-4 py-3 text-right text-emerald-700 tabular-nums">
                                                {money(account.debit_cents)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-rose-700 tabular-nums">
                                                {money(account.credit_cents)}
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium tabular-nums">
                                                {net > 0 ? money(net) : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium tabular-nums">
                                                {net < 0 ? money(-net) : '—'}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot className="border-t-2 border-slate-200 bg-[#faf8f7] font-semibold">
                                <tr>
                                    <td colSpan={3} className="px-4 py-3">
                                        Totales generales
                                    </td>
                                    <td className="px-4 py-3 text-right text-emerald-700">
                                        {money(totalDebit)}
                                    </td>
                                    <td className="px-4 py-3 text-right text-rose-700">
                                        {money(totalCredit)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {money(totalDebtor)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {money(totalCreditor)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
                <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-[10px] text-slate-500">
                    <span>{rows.length} cuentas mostradas</span>
                    <span
                        className={
                            squared ? 'text-emerald-700' : 'text-amber-700'
                        }
                    >
                        {squared
                            ? 'El balance está cuadrado'
                            : 'Balance por revisar'}
                    </span>
                </div>
            </section>
        </div>
    );
}

function ChartOfAccountsView({
    uuid,
    accounts,
    periods,
    balances,
    ledger,
    filters,
    history,
    canManage,
    onFilter,
    onNew,
    onEdit,
    onInstall,
    onImport,
    onViewLedger,
}: {
    uuid: string;
    accounts: Account[];
    periods: Period[];
    balances: Balance[];
    ledger: Page<Movement> | null;
    filters: Props['filters'];
    history: Props['accountHistory'];
    canManage: boolean;
    onFilter: BookFilter;
    onNew: () => void;
    onEdit: (account: Account) => void;
    onInstall: () => void;
    onImport: () => void;
    onViewLedger: (accountId: number) => void;
}) {
    const [search, setSearch] = useState('');
    const [group, setGroup] = useState('all');
    const normalizedSearch = search.trim().toLocaleLowerCase();
    const visibleAccounts = accounts.filter(
        (account) =>
            (group === 'all' || account.type === group) &&
            (!normalizedSearch ||
                `${account.code} ${account.name}`
                    .toLocaleLowerCase()
                    .includes(normalizedSearch)),
    );
    const selectedAccount = accounts.find(
        (account) => account.id === Number(filters.account),
    );
    const selectedBalance = balances.find(
        (account) => account.id === Number(filters.account),
    );
    const accountsWithMovement = new Set(balances.map((account) => account.id));
    const selectedHistory = selectedAccount
        ? history.filter(
              (change) =>
                  change.before.code === selectedAccount.code ||
                  change.after.code === selectedAccount.code,
          )
        : [];
    const selectedPeriod = periods.find(
        (period) => period.id === Number(filters.period),
    );
    const from =
        selectedPeriod?.starts_on ?? periods.at(-1)?.starts_on ?? today();
    const to = selectedPeriod?.ends_on ?? periods[0]?.ends_on ?? today();

    return (
        <div id="plan-cuentas" className="scroll-mt-20 space-y-4">
            <div className="flex flex-wrap justify-end gap-2">
                {canManage && (
                    <button type="button" onClick={onNew} className={primary}>
                        <Plus size={16} /> Nueva cuenta
                    </button>
                )}
                <button type="button" onClick={onImport} className={secondary}>
                    <FileSpreadsheet size={15} /> Importar Excel
                </button>
                <a
                    href={routes.exportMethod.url(uuid, {
                        query: {
                            kind: 'accounts',
                            format: 'xlsx',
                            from,
                            to,
                        },
                    })}
                    className={secondary}
                >
                    <FileDown size={15} /> Exportar
                </a>
            </div>

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {[
                    {
                        label: 'Total de cuentas',
                        value: accounts.length,
                        hint: 'Catálogo actual',
                        icon: BookOpen,
                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                    },
                    {
                        label: 'Cuentas operativas',
                        value: accounts.length,
                        hint: 'Disponibles para registro',
                        icon: Check,
                        tone: 'bg-[#e7f5ec] text-[#218451]',
                    },
                    {
                        label: 'Con movimiento',
                        value: accountsWithMovement.size,
                        hint: 'Asientos contabilizados',
                        icon: FileChartColumn,
                        tone: 'bg-[#e9f0fb] text-[#315fa8]',
                    },
                    {
                        label: 'Cuentas auxiliares',
                        value: accounts.length,
                        hint: 'Aceptan movimientos',
                        icon: UsersRound,
                        tone: 'bg-[#fff0e7] text-[#d2632d]',
                    },
                    {
                        label: 'Cuentas inactivas',
                        value: 0,
                        hint: 'Sin cuentas bloqueadas',
                        icon: LockKeyhole,
                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                    },
                ].map((metric) => (
                    <article
                        key={metric.label}
                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]"
                    >
                        <div className="flex items-center gap-3">
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block text-lg text-[#172033]">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 truncate text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </div>
                    </article>
                ))}
            </section>

            <section className="grid gap-px overflow-hidden rounded-2xl border border-[#e8e4e2] bg-slate-100 shadow-[0_7px_18px_rgba(31,38,55,.05)] md:grid-cols-3">
                <div className="flex items-center gap-3 bg-white px-5 py-4">
                    <Check size={21} className="text-emerald-600" />
                    <div>
                        <strong className="block text-xs">
                            Plantilla contable disponible
                        </strong>
                        <span className="mt-1 block text-[10px] text-slate-500">
                            Catálogo base de cinco grupos
                        </span>
                    </div>
                </div>
                <div className="flex items-center gap-3 bg-white px-5 py-4">
                    <Scale size={21} className="text-[#315fa8]" />
                    <div>
                        <strong className="block text-xs">
                            {accountsWithMovement.size} cuentas con saldos
                        </strong>
                        <span className="mt-1 block text-[10px] text-slate-500">
                            Solo asientos contabilizados
                        </span>
                    </div>
                </div>
                <div className="flex items-center justify-between gap-3 bg-white px-5 py-4">
                    <div>
                        <strong className="block text-xs">
                            Catálogo inicial seguro
                        </strong>
                        <span className="mt-1 block text-[10px] text-slate-500">
                            No duplica códigos existentes
                        </span>
                    </div>
                    {canManage && (
                        <button
                            type="button"
                            onClick={onInstall}
                            className="shrink-0 text-[10px] font-semibold text-[#7d0914] underline"
                        >
                            Aplicar plantilla
                        </button>
                    )}
                </div>
            </section>

            <section className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <label className="relative xl:col-span-2">
                        <span className="sr-only">Buscar cuenta</span>
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por código o nombre..."
                            className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-xs outline-none focus:border-[#8a1630]"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Grupo contable
                        <select
                            value={group}
                            onChange={(event) => setGroup(event.target.value)}
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(types).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Período de saldos
                        <select
                            value={filters.period}
                            onChange={(event) =>
                                onFilter({ period: Number(event.target.value) })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value={0}>Todos los períodos</option>
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-[minmax(300px,.8fr)_minmax(0,2fr)]">
                <aside className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                    <div className="border-b border-slate-100 px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Árbol de cuentas contables
                        </h2>
                        <p className="mt-1 text-[10px] text-slate-400">
                            Organizado por clasificación contable.
                        </p>
                    </div>
                    <div className="max-h-[610px] overflow-y-auto p-2">
                        {Object.entries(types).map(([type, label]) => {
                            const groupAccounts = visibleAccounts.filter(
                                (account) => account.type === type,
                            );
                            const groupBalance = balances
                                .filter((account) => account.type === type)
                                .reduce(
                                    (sum, account) =>
                                        sum +
                                        Number(account.debit_cents) -
                                        Number(account.credit_cents),
                                    0,
                                );

                            if (!groupAccounts.length) {
                                return null;
                            }

                            return (
                                <div key={type} className="mb-2">
                                    <div className="flex items-center justify-between rounded-lg bg-[#f8f7f6] px-3 py-2 text-[11px] font-bold">
                                        <span>{label.toLocaleUpperCase()}</span>
                                        <span>
                                            {money(Math.abs(groupBalance))}
                                        </span>
                                    </div>
                                    <div className="mt-1 space-y-1 pl-2">
                                        {groupAccounts.map((account) => {
                                            const balance = balances.find(
                                                (row) => row.id === account.id,
                                            );
                                            const net =
                                                Number(
                                                    balance?.debit_cents ?? 0,
                                                ) -
                                                Number(
                                                    balance?.credit_cents ?? 0,
                                                );

                                            return (
                                                <button
                                                    key={account.id}
                                                    type="button"
                                                    onClick={() =>
                                                        onFilter({
                                                            account: account.id,
                                                        })
                                                    }
                                                    className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition ${Number(filters.account) === account.id ? 'bg-[#f8e9ea] text-[#760b20]' : 'hover:bg-[#faf8f7]'}`}
                                                >
                                                    <span className="min-w-0 truncate text-[11px]">
                                                        <strong>
                                                            {account.code}
                                                        </strong>{' '}
                                                        · {account.name}
                                                    </span>
                                                    <span className="shrink-0 text-[10px] font-semibold tabular-nums">
                                                        {money(Math.abs(net))}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })}
                        {!visibleAccounts.length && (
                            <p className="p-6 text-center text-xs text-slate-500">
                                No hay cuentas con estos filtros.
                            </p>
                        )}
                    </div>
                </aside>

                <div className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                    {selectedAccount ? (
                        <>
                            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-4">
                                <div>
                                    <h2 className="text-lg font-bold text-[#172033]">
                                        {selectedAccount.code} ·{' '}
                                        {selectedAccount.name}
                                    </h2>
                                    <div className="mt-2 flex flex-wrap gap-2 text-[9px] font-semibold">
                                        <span className="rounded-full bg-[#eee9fb] px-2 py-1 text-[#6754b7]">
                                            Auxiliar
                                        </span>
                                        <span className="rounded-full bg-emerald-100 px-2 py-1 text-emerald-800">
                                            Operativa
                                        </span>
                                        <span className="rounded-full bg-[#e9f0fb] px-2 py-1 text-[#315fa8]">
                                            Permite movimientos
                                        </span>
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onEdit(selectedAccount)
                                            }
                                            className={primary}
                                        >
                                            <FilePenLine size={15} /> Editar
                                            cuenta
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            onViewLedger(selectedAccount.id)
                                        }
                                        className={secondary}
                                    >
                                        <BookOpen size={15} /> Ver libro mayor
                                    </button>
                                </div>
                            </div>

                            <div className="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-4">
                                {[
                                    {
                                        label: 'Saldo actual',
                                        value: money(
                                            Math.abs(
                                                Number(
                                                    selectedBalance?.debit_cents ??
                                                        0,
                                                ) -
                                                    Number(
                                                        selectedBalance?.credit_cents ??
                                                            0,
                                                    ),
                                            ),
                                        ),
                                        icon: BookOpen,
                                        tone: 'text-[#6754b7]',
                                    },
                                    {
                                        label: 'Débitos del período',
                                        value: money(
                                            Number(
                                                selectedBalance?.debit_cents ??
                                                    0,
                                            ),
                                        ),
                                        icon: ArrowUpRight,
                                        tone: 'text-emerald-700',
                                    },
                                    {
                                        label: 'Créditos del período',
                                        value: money(
                                            Number(
                                                selectedBalance?.credit_cents ??
                                                    0,
                                            ),
                                        ),
                                        icon: ArrowDownLeft,
                                        tone: 'text-rose-700',
                                    },
                                    {
                                        label: 'Movimientos visibles',
                                        value: ledger?.data.length ?? 0,
                                        icon: NotebookTabs,
                                        tone: 'text-[#315fa8]',
                                    },
                                ].map((summary) => (
                                    <div
                                        key={summary.label}
                                        className="flex items-center gap-3 rounded-xl border border-slate-200 p-3"
                                    >
                                        <summary.icon
                                            size={18}
                                            className={summary.tone}
                                        />
                                        <div>
                                            <p className="text-[9px] text-slate-500">
                                                {summary.label}
                                            </p>
                                            <strong className="mt-1 block text-sm">
                                                {summary.value}
                                            </strong>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="grid gap-4 border-b border-slate-100 p-4 md:grid-cols-3">
                                <div>
                                    <h3 className="text-xs font-semibold">
                                        Ficha de la cuenta
                                    </h3>
                                    <dl className="mt-3 space-y-2 text-[10px]">
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Código
                                            </dt>
                                            <dd className="font-medium">
                                                {selectedAccount.code}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Nombre
                                            </dt>
                                            <dd className="text-right font-medium">
                                                {selectedAccount.name}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Grupo
                                            </dt>
                                            <dd className="font-medium">
                                                {types[selectedAccount.type]}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold">
                                        Configuración
                                    </h3>
                                    <dl className="mt-3 space-y-2 text-[10px]">
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Naturaleza
                                            </dt>
                                            <dd className="font-medium">
                                                {['asset', 'expense'].includes(
                                                    selectedAccount.type,
                                                )
                                                    ? 'Deudora'
                                                    : 'Acreedora'}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Moneda
                                            </dt>
                                            <dd className="font-medium">GTQ</dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Estado
                                            </dt>
                                            <dd className="font-medium text-emerald-700">
                                                Operativa
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold">
                                        Control
                                    </h3>
                                    <dl className="mt-3 space-y-2 text-[10px]">
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Permite movimientos
                                            </dt>
                                            <dd className="font-medium text-emerald-700">
                                                Sí
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Creada
                                            </dt>
                                            <dd className="font-medium">
                                                {selectedAccount.created_at ??
                                                    'No disponible'}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-slate-500">
                                                Última modificación
                                            </dt>
                                            <dd className="font-medium">
                                                {selectedAccount.updated_at ??
                                                    'No disponible'}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(240px,.8fr)]">
                                <div>
                                    <h3 className="mb-3 text-xs font-semibold">
                                        Movimientos recientes
                                    </h3>
                                    {!ledger?.data.length ? (
                                        <div className="rounded-xl border border-dashed border-slate-200 p-7 text-center text-xs text-slate-500">
                                            Esta cuenta todavía no tiene
                                            movimientos contabilizados.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                                            <table className="w-full min-w-[560px] text-[10px]">
                                                <thead className="bg-[#f8f7f6] text-left text-slate-500">
                                                    <tr>
                                                        <th className="px-3 py-2">
                                                            Fecha
                                                        </th>
                                                        <th className="px-3 py-2">
                                                            Asiento
                                                        </th>
                                                        <th className="px-3 py-2">
                                                            Concepto
                                                        </th>
                                                        <th className="px-3 py-2 text-right">
                                                            Debe
                                                        </th>
                                                        <th className="px-3 py-2 text-right">
                                                            Haber
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {ledger.data
                                                        .slice(0, 6)
                                                        .map(
                                                            (
                                                                movement,
                                                                index,
                                                            ) => (
                                                                <tr
                                                                    key={`${movement.number}-${index}`}
                                                                    className="border-t border-slate-100"
                                                                >
                                                                    <td className="px-3 py-2">
                                                                        {
                                                                            movement.entry_date
                                                                        }
                                                                    </td>
                                                                    <td className="px-3 py-2 text-[#8a1630]">
                                                                        DI-
                                                                        {String(
                                                                            movement.number,
                                                                        ).padStart(
                                                                            6,
                                                                            '0',
                                                                        )}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {
                                                                            movement.description
                                                                        }
                                                                    </td>
                                                                    <td className="px-3 py-2 text-right text-emerald-700">
                                                                        {money(
                                                                            movement.debit_cents,
                                                                        )}
                                                                    </td>
                                                                    <td className="px-3 py-2 text-right text-rose-700">
                                                                        {money(
                                                                            movement.credit_cents,
                                                                        )}
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                                <aside>
                                    <h3 className="mb-3 text-xs font-semibold">
                                        Historial de cambios
                                    </h3>
                                    {!selectedHistory.length ? (
                                        <div className="rounded-xl border border-dashed border-slate-200 p-5 text-center text-[10px] text-slate-500">
                                            Sin correcciones registradas.
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {selectedHistory
                                                .slice(0, 4)
                                                .map((change) => (
                                                    <article
                                                        key={change.id}
                                                        className="rounded-xl border border-slate-200 p-3 text-[10px]"
                                                    >
                                                        <strong>
                                                            {change.actor ??
                                                                'Usuario'}
                                                        </strong>
                                                        <p className="mt-1 text-slate-500">
                                                            {change.date}
                                                        </p>
                                                        <p className="mt-2">
                                                            {
                                                                change.after
                                                                    .reason
                                                            }
                                                        </p>
                                                    </article>
                                                ))}
                                        </div>
                                    )}
                                </aside>
                            </div>
                        </>
                    ) : (
                        <div className="grid min-h-[580px] place-items-center p-8 text-center">
                            <div>
                                <ListTree className="mx-auto text-[#b67e7d]" />
                                <p className="mt-3 text-sm font-medium">
                                    Selecciona una cuenta del árbol
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Aquí aparecerán su configuración, saldos y
                                    movimientos.
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}

type PeriodState = 'closed' | 'open' | 'review' | 'future';

const periodState = (period: Period): PeriodState => {
    if (period.closed_at) {
        return 'closed';
    }

    if (period.starts_on > today()) {
        return 'future';
    }

    if (period.ends_on < today()) {
        return 'review';
    }

    return 'open';
};

const periodStateMeta: Record<
    PeriodState,
    { label: string; tone: string; dot: string }
> = {
    closed: {
        label: 'Cerrado',
        tone: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        dot: 'bg-emerald-500',
    },
    open: {
        label: 'Abierto',
        tone: 'bg-blue-50 text-blue-700 border-blue-200',
        dot: 'bg-blue-500',
    },
    review: {
        label: 'En revisión',
        tone: 'bg-orange-50 text-orange-700 border-orange-200',
        dot: 'bg-orange-500',
    },
    future: {
        label: 'Futuro',
        tone: 'bg-violet-50 text-violet-700 border-violet-200',
        dot: 'bg-violet-500',
    },
};

function AccountingPeriodsView({
    uuid,
    periods,
    filters,
    canManage,
    busy,
    onFilter,
    onNew,
    onClose,
}: {
    uuid: string;
    periods: Period[];
    filters: Props['filters'];
    canManage: boolean;
    busy: boolean;
    onFilter: BookFilter;
    onNew: () => void;
    onClose: (period: Period) => void;
}) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'all' | PeriodState>('all');
    const [pendingOnly, setPendingOnly] = useState(false);
    const ordered = [...periods].sort((a, b) =>
        a.starts_on.localeCompare(b.starts_on),
    );
    const selected =
        periods.find((period) => period.id === Number(filters.period)) ??
        ordered.find(
            (period) =>
                !period.closed_at &&
                period.starts_on <= today() &&
                period.ends_on >= today(),
        ) ??
        ordered.find((period) => !period.closed_at) ??
        ordered.at(-1);
    const selectedYear =
        selected?.starts_on.slice(0, 4) ??
        ordered.at(-1)?.starts_on.slice(0, 4) ??
        String(new Date().getFullYear());
    const exercisePeriods = ordered.filter((period) =>
        period.starts_on.startsWith(selectedYear),
    );
    const normalizedSearch = search.trim().toLocaleLowerCase();
    const visiblePeriods = ordered.filter((period) => {
        const state = periodState(period);

        return (
            (!normalizedSearch ||
                `${period.name} ${period.starts_on} ${period.ends_on}`
                    .toLocaleLowerCase()
                    .includes(normalizedSearch)) &&
            (status === 'all' || state === status) &&
            (!pendingOnly || period.draft_count > 0)
        );
    });
    const counts = exercisePeriods.reduce(
        (result, period) => {
            result[periodState(period)] += 1;

            return result;
        },
        { closed: 0, open: 0, review: 0, future: 0 },
    );
    const lastClosed = [...exercisePeriods]
        .reverse()
        .find((period) => period.closed_at);
    const nextFuture = exercisePeriods.find(
        (period) => periodState(period) === 'future',
    );
    const selectedState = selected ? periodState(selected) : 'open';
    const selectedDifference = selected
        ? Number(selected.debit_cents) - Number(selected.credit_cents)
        : 0;
    const exportQuery = selected
        ? {
              kind: 'journal',
              from: selected.starts_on,
              to: selected.ends_on,
          }
        : null;
    const dateLabel = (value: string) =>
        new Intl.DateTimeFormat('es-GT', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(new Date(`${value.slice(0, 10)}T12:00:00`));

    return (
        <div id="periodos-contables" className="scroll-mt-20 space-y-4">
            <section className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_310px]">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {[
                        {
                            label: 'Períodos del ejercicio',
                            value: exercisePeriods.length,
                            icon: CalendarDays,
                            tone: 'bg-violet-50 text-violet-600',
                        },
                        {
                            label: 'Cerrados',
                            value: counts.closed,
                            icon: Check,
                            tone: 'bg-emerald-50 text-emerald-600',
                        },
                        {
                            label: 'Abiertos',
                            value: counts.open,
                            icon: LockKeyhole,
                            tone: 'bg-blue-50 text-blue-600',
                        },
                        {
                            label: 'En revisión',
                            value: counts.review,
                            icon: Clock3,
                            tone: 'bg-orange-50 text-orange-600',
                        },
                        {
                            label: 'Futuros',
                            value: counts.future,
                            icon: ChevronRight,
                            tone: 'bg-violet-50 text-violet-600',
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="flex items-center gap-3 rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_8px_22px_rgba(31,38,55,.05)]"
                        >
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div>
                                <p className="text-[10px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="text-xl text-[#172033]">
                                    {metric.value}
                                </strong>
                            </div>
                        </article>
                    ))}
                </div>
                <aside className="rounded-2xl border border-[#e8e4e2] bg-white px-5 py-3 shadow-[0_8px_22px_rgba(31,38,55,.05)]">
                    <div className="flex items-center gap-2 border-b border-slate-100 py-2 text-xs">
                        <Check size={15} className="text-emerald-600" />
                        <strong>Ejercicio {selectedYear}</strong>
                    </div>
                    <div className="flex items-center gap-2 border-b border-slate-100 py-2 text-xs">
                        <CalendarDays size={15} className="text-blue-600" />
                        <span>
                            Último cierre: {lastClosed?.name ?? 'Sin cierres'}
                        </span>
                    </div>
                    <div className="flex items-center gap-2 py-2 text-xs">
                        <ChevronRight size={15} className="text-violet-600" />
                        <span>
                            Próximo período:{' '}
                            {nextFuture?.name ?? 'No programado'}
                        </span>
                    </div>
                </aside>
            </section>

            <section className="overflow-x-auto rounded-2xl border border-[#e8e4e2] bg-white px-4 py-3 shadow-[0_8px_22px_rgba(31,38,55,.05)]">
                <div className="flex min-w-max items-center gap-2">
                    {exercisePeriods.map((period) => {
                        const state = periodState(period);
                        const active = selected?.id === period.id;

                        return (
                            <button
                                key={period.id}
                                type="button"
                                onClick={() => onFilter({ period: period.id })}
                                className={`min-w-32 rounded-xl border px-4 py-3 text-left transition ${
                                    active
                                        ? 'border-[#8a1630] bg-[#fff7f7] shadow-sm'
                                        : 'border-transparent hover:border-slate-200 hover:bg-slate-50'
                                }`}
                            >
                                <strong className="block text-xs">
                                    {period.name}
                                </strong>
                                <span className="mt-2 flex items-center gap-1.5 text-[10px] text-slate-500">
                                    <span
                                        className={`size-2 rounded-full ${periodStateMeta[state].dot}`}
                                    />
                                    {periodStateMeta[state].label}
                                </span>
                            </button>
                        );
                    })}
                    {!exercisePeriods.length && (
                        <p className="px-4 py-3 text-xs text-slate-500">
                            No hay períodos para el ejercicio {selectedYear}.
                        </p>
                    )}
                </div>
            </section>

            <section className="flex flex-wrap items-end gap-3 rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_8px_22px_rgba(31,38,55,.05)]">
                <label className="min-w-56 flex-1 text-[10px] font-medium text-slate-500">
                    Buscar período
                    <span className="relative mt-1 block">
                        <Search
                            size={15}
                            className="absolute top-1/2 left-3 -translate-y-1/2 text-slate-400"
                        />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-xs"
                            placeholder="Nombre, inicio o fin..."
                        />
                    </span>
                </label>
                <label className="min-w-44 text-[10px] font-medium text-slate-500">
                    Estado
                    <select
                        value={status}
                        onChange={(event) =>
                            setStatus(event.target.value as 'all' | PeriodState)
                        }
                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                    >
                        <option value="all">Todos</option>
                        {Object.entries(periodStateMeta).map(
                            ([value, meta]) => (
                                <option key={value} value={value}>
                                    {meta.label}
                                </option>
                            ),
                        )}
                    </select>
                </label>
                <label className="flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs">
                    <input
                        type="checkbox"
                        checked={pendingOnly}
                        onChange={(event) =>
                            setPendingOnly(event.target.checked)
                        }
                        className="accent-[#8a1630]"
                    />
                    Solo con pendientes
                </label>
                {exportQuery && (
                    <>
                        <a
                            href={routes.exportMethod.url(uuid, {
                                query: { ...exportQuery, format: 'xlsx' },
                            })}
                            className={secondary}
                        >
                            <FileSpreadsheet size={15} /> Exportar Excel
                        </a>
                        <a
                            href={routes.exportMethod.url(uuid, {
                                query: { ...exportQuery, format: 'pdf' },
                            })}
                            className={secondary}
                        >
                            <FileDown size={15} /> Exportar PDF
                        </a>
                    </>
                )}
                {canManage && (
                    <button type="button" onClick={onNew} className={primary}>
                        <Plus size={16} /> Abrir período
                    </button>
                )}
            </section>

            <section className="grid gap-4 xl:grid-cols-[minmax(500px,1.05fr)_minmax(520px,1fr)]">
                <div className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_8px_22px_rgba(31,38,55,.05)]">
                    <div className="border-b border-slate-100 px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Períodos del ejercicio
                        </h2>
                        <p className="mt-1 text-[10px] text-slate-400">
                            Selecciona un período para revisar su cierre.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-xs">
                            <thead className="bg-slate-50 text-[10px] text-slate-500 uppercase">
                                <tr>
                                    <th className="px-4 py-3">Período</th>
                                    <th className="px-3 py-3">Estado</th>
                                    <th className="px-3 py-3">Progreso</th>
                                    <th className="px-3 py-3 text-center">
                                        Pendientes
                                    </th>
                                    <th className="px-3 py-3">Cierre</th>
                                    <th className="px-3 py-3 text-right">
                                        Acción
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visiblePeriods.map((period) => {
                                    const state = periodState(period);
                                    const progress = period.closed_at
                                        ? 100
                                        : period.entries_count
                                          ? Math.round(
                                                (period.posted_count /
                                                    period.entries_count) *
                                                    100,
                                            )
                                          : 0;

                                    return (
                                        <tr
                                            key={period.id}
                                            className={`border-t border-slate-100 ${selected?.id === period.id ? 'bg-[#fff7f7]' : 'hover:bg-slate-50'}`}
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {period.name}
                                            </td>
                                            <td className="px-3 py-3">
                                                <span
                                                    className={`inline-flex rounded-full border px-2 py-1 text-[9px] font-semibold ${periodStateMeta[state].tone}`}
                                                >
                                                    {
                                                        periodStateMeta[state]
                                                            .label
                                                    }
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center gap-2">
                                                    <span className="w-8 text-[10px] font-medium">
                                                        {progress}%
                                                    </span>
                                                    <span className="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                                                        <span
                                                            className="block h-full rounded-full bg-[#8a1630]"
                                                            style={{
                                                                width: `${progress}%`,
                                                            }}
                                                        />
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-3 py-3 text-center font-semibold text-orange-600">
                                                {period.draft_count}
                                            </td>
                                            <td className="px-3 py-3 text-slate-500">
                                                {period.closed_at
                                                    ? period.closed_at.slice(
                                                          0,
                                                          10,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        onFilter({
                                                            period: period.id,
                                                        })
                                                    }
                                                    className="font-medium text-[#8a1630]"
                                                >
                                                    Ver detalle
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {!visiblePeriods.length && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-10 text-center text-slate-500"
                                        >
                                            No hay períodos que coincidan con
                                            los filtros.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_8px_22px_rgba(31,38,55,.05)]">
                    {selected ? (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                                <div className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-full bg-[#f8e9ea] text-[#8a1630]">
                                        <CalendarDays size={19} />
                                    </span>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="font-semibold">
                                                {selected.name}
                                            </h2>
                                            <span
                                                className={`rounded-full border px-2 py-1 text-[9px] font-semibold ${periodStateMeta[selectedState].tone}`}
                                            >
                                                {
                                                    periodStateMeta[
                                                        selectedState
                                                    ].label
                                                }
                                            </span>
                                        </div>
                                        <p className="mt-1 text-[10px] text-slate-500">
                                            {dateLabel(selected.starts_on)} —{' '}
                                            {dateLabel(selected.ends_on)}
                                        </p>
                                    </div>
                                </div>
                                {canManage && !selected.closed_at && (
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => onClose(selected)}
                                        className={primary}
                                    >
                                        <LockKeyhole size={15} /> Cerrar período
                                    </button>
                                )}
                            </div>
                            <div className="grid gap-4 p-5 lg:grid-cols-[1fr_1fr]">
                                <div>
                                    <h3 className="mb-3 text-xs font-semibold">
                                        Checklist de cierre
                                    </h3>
                                    <div className="overflow-hidden rounded-xl border border-slate-200">
                                        {[
                                            {
                                                label: 'Débitos y créditos cuadran',
                                                complete:
                                                    selectedDifference === 0,
                                            },
                                            {
                                                label: 'No existen asientos pendientes',
                                                complete:
                                                    selected.draft_count === 0,
                                            },
                                            {
                                                label: 'No existen asientos descartados',
                                                complete:
                                                    selected.void_count === 0,
                                            },
                                            {
                                                label: 'Asientos contabilizados',
                                                complete:
                                                    selected.entries_count ===
                                                    selected.posted_count,
                                            },
                                        ].map((item) => (
                                            <div
                                                key={item.label}
                                                className="flex items-center justify-between gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0"
                                            >
                                                <span className="flex items-center gap-2 text-[10px]">
                                                    <span
                                                        className={`grid size-4 place-items-center rounded-full ${item.complete ? 'bg-emerald-100 text-emerald-700' : 'bg-orange-100 text-orange-700'}`}
                                                    >
                                                        {item.complete ? (
                                                            <Check size={10} />
                                                        ) : (
                                                            <Clock3 size={10} />
                                                        )}
                                                    </span>
                                                    {item.label}
                                                </span>
                                                <strong
                                                    className={`text-[9px] ${item.complete ? 'text-emerald-700' : 'text-orange-700'}`}
                                                >
                                                    {item.complete
                                                        ? 'Completado'
                                                        : 'Pendiente'}
                                                </strong>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    {[
                                        {
                                            label: 'Asientos',
                                            value: selected.entries_count,
                                            icon: FileChartColumn,
                                        },
                                        {
                                            label: 'Débitos',
                                            value: money(selected.debit_cents),
                                            icon: ArrowDownLeft,
                                        },
                                        {
                                            label: 'Créditos',
                                            value: money(selected.credit_cents),
                                            icon: ArrowUpRight,
                                        },
                                        {
                                            label: 'Diferencia',
                                            value: money(selectedDifference),
                                            icon: Scale,
                                        },
                                    ].map((metric) => (
                                        <article
                                            key={metric.label}
                                            className="rounded-xl border border-slate-200 p-3"
                                        >
                                            <metric.icon
                                                size={16}
                                                className="text-[#8a1630]"
                                            />
                                            <p className="mt-3 text-[10px] text-slate-500">
                                                {metric.label}
                                            </p>
                                            <strong className="mt-1 block text-sm">
                                                {metric.value}
                                            </strong>
                                        </article>
                                    ))}
                                </div>
                            </div>
                            <div className="border-t border-slate-100 px-5 py-4">
                                <h3 className="text-xs font-semibold">
                                    Actividad del período
                                </h3>
                                <div className="mt-3 space-y-2 text-[10px] text-slate-500">
                                    <p className="flex items-center gap-2">
                                        <span className="size-2 rounded-full bg-blue-500" />
                                        Apertura programada:{' '}
                                        {dateLabel(selected.starts_on)}
                                    </p>
                                    <p className="flex items-center gap-2">
                                        <span className="size-2 rounded-full bg-orange-500" />
                                        {selected.draft_count} asiento(s)
                                        pendiente(s) de contabilizar
                                    </p>
                                    {selected.closed_at && (
                                        <p className="flex items-center gap-2">
                                            <span className="size-2 rounded-full bg-emerald-500" />
                                            Cerrado el{' '}
                                            {dateLabel(selected.closed_at)}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </>
                    ) : (
                        <div className="grid min-h-96 place-items-center p-8 text-center">
                            <div>
                                <CalendarDays className="mx-auto text-[#b67e7d]" />
                                <p className="mt-3 text-sm font-medium">
                                    Aún no hay períodos contables
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Abre el primer período para comenzar a
                                    contabilizar.
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}

export default function CompanyAccounting(props: Props) {
    const page = usePage();
    const navigation = page.props.workspaceNavigation as
        { plan: { features: string[] } | null } | null | undefined;
    const planFeatures = navigation?.plan?.features ?? [];
    const {
        company,
        canManage,
        canWrite,
        accounts,
        periods,
        journal,
        balances,
        ledger,
        members,
        filters,
        notice,
    } = props;
    const uuid = company.public_id;
    const [tab, setTab] = useState('overview');
    const [journalSearch, setJournalSearch] = useState('');
    const [journalStatus, setJournalStatus] = useState('all');
    const [journalKind, setJournalKind] = useState('all');
    const [journalFrom, setJournalFrom] = useState('');
    const [journalTo, setJournalTo] = useState('');
    const [selectedEntryId, setSelectedEntryId] = useState<number | null>(null);
    const [newAccountOpen, setNewAccountOpen] = useState(false);
    const [newPeriodOpen, setNewPeriodOpen] = useState(false);
    const [target, setTarget] = useState<Entry | null>(null);
    const [editingAccount, setEditingAccount] = useState<Account | null>(null);
    const editAccount = useForm({
        code: '',
        name: '',
        type: 'asset',
        reason: '',
        acknowledged: false,
    });
    const [busy, setBusy] = useState(false);
    const openPeriods = periods.filter((period) => !period.closed_at);
    const accountForm = useForm({ code: '', name: '', type: 'asset' });
    const periodForm = useForm({ name: '', starts_on: '', ends_on: '' });
    const draft = useForm({
        request_id: crypto.randomUUID(),
        period_id: String(openPeriods[0]?.id ?? ''),
        entry_date: today(),
        description: '',
        reference: '',
        kind: 'standard',
        lines: [emptyLine(), emptyLine()],
    });
    const action = useForm({
        action: 'post',
        period_id: String(openPeriods[0]?.id ?? ''),
        entry_date: today(),
        reason: '',
    });
    const [operationErrors, setOperationErrors] = useState<
        Record<string, string>
    >({});
    const debit = draft.data.lines.reduce(
        (sum, line) => sum + sumCents(line.debit),
        0,
    );
    const credit = draft.data.lines.reduce(
        (sum, line) => sum + sumCents(line.credit),
        0,
    );
    const postedEntries = journal.data.filter(
        (entry) => entry.status === 'posted',
    );
    const periodDebit = postedEntries.reduce(
        (total, entry) =>
            total +
            entry.lines.reduce((sum, line) => sum + line.debit_cents, 0),
        0,
    );
    const periodCredit = postedEntries.reduce(
        (total, entry) =>
            total +
            entry.lines.reduce((sum, line) => sum + line.credit_cents, 0),
        0,
    );
    const pendingEntries = journal.data.filter(
        (entry) => entry.status === 'draft',
    ).length;
    const currentPeriod =
        periods.find((period) => period.id === filters.period) ??
        openPeriods[0] ??
        periods[0];
    const normalizedJournalSearch = journalSearch.trim().toLocaleLowerCase();
    const journalRows = journal.data.filter((entry) => {
        const matchesSearch =
            !normalizedJournalSearch ||
            [
                entry.description,
                entry.reference ?? '',
                entry.author,
                entry.approver ?? '',
                ...entry.lines.flatMap((line) => [line.code, line.name]),
            ].some((value) =>
                value.toLocaleLowerCase().includes(normalizedJournalSearch),
            );

        return (
            matchesSearch &&
            (journalStatus === 'all' || entry.status === journalStatus) &&
            (journalKind === 'all' || entry.kind === journalKind) &&
            (!journalFrom || entry.entry_date >= journalFrom) &&
            (!journalTo || entry.entry_date <= journalTo)
        );
    });
    const selectedEntry =
        journalRows.find((entry) => entry.id === selectedEntryId) ??
        journalRows[0] ??
        null;
    const tabs = [
        { id: 'journal', label: 'Libro diario' },
        ...(canWrite ? [{ id: 'draft', label: 'Nuevo asiento' }] : []),
        { id: 'ledger', label: 'Libro mayor' },
        { id: 'trial', label: 'Balance de comprobación' },
        { id: 'setup', label: 'Plan de cuentas' },
        { id: 'periods', label: 'Períodos contables' },
        ...(canManage ? [{ id: 'access', label: 'Accesos' }] : []),
    ];
    useEffect(() => {
        const selectSection = () => {
            const section = window.location.hash.slice(1);
            const sectionTabs: Record<string, string> = {
                'libro-diario': 'journal',
                'libro-mayor': 'ledger',
                'balance-comprobacion': 'trial',
                'plan-cuentas': 'setup',
                'periodos-contables': 'periods',
            };

            if (sectionTabs[section]) {
                setTab(sectionTabs[section]);
            }
        };

        selectSection();
        window.addEventListener('hashchange', selectSection);

        return () => window.removeEventListener('hashchange', selectSection);
    }, []);
    function switchSection(nextTab: string, section = '') {
        setTab(nextTab);
        const nextHash = section ? `#${section}` : '';
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${window.location.search}${nextHash}`,
        );
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    }
    function filter(values: { period?: number; account?: number }) {
        const section =
            {
                journal: 'libro-diario',
                ledger: 'libro-mayor',
                trial: 'balance-comprobacion',
                setup: 'plan-cuentas',
                periods: 'periodos-contables',
            }[tab] ?? '';
        router.get(
            routes.show.url(uuid),
            { ...filters, ...values },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    if (section) {
                        window.location.hash = section;
                    }
                },
            },
        );
    }
    function updateLine(
        index: number,
        key: 'account_id' | 'debit' | 'credit',
        value: string,
    ) {
        draft.setData(
            'lines',
            draft.data.lines.map((line, position) =>
                position === index ? { ...line, [key]: value } : line,
            ),
        );
    }
    function operation(url: string, data: Record<string, string | number>) {
        setBusy(true);
        setOperationErrors({});
        router.post(url, data, {
            preserveScroll: true,
            onError: setOperationErrors,
            onFinish: () => setBusy(false),
        });
    }

    const openPreparedModule = (module: string) =>
        router.visit(`${routes.companyDashboard.url(uuid)}?module=${module}`);
    const accountingModules: AccountingModule[] = [
        {
            title: 'Plan de Cuentas',
            subtitle: 'Catálogo contable y saldos',
            icon: ListTree,
            action: () => switchSection('setup', 'plan-cuentas'),
            tone: 'wine',
        },
        {
            title: 'Asientos Contables',
            subtitle: 'Registro y edición de partidas',
            icon: FilePenLine,
            action: () => setTab(canWrite ? 'draft' : 'journal'),
            tone: 'wine',
        },
        {
            title: 'Libro Diario',
            subtitle: 'Movimientos cronológicos',
            icon: NotebookTabs,
            action: () => switchSection('journal', 'libro-diario'),
            tone: 'wine',
        },
        {
            title: 'Libro Mayor',
            subtitle: 'Movimientos por cuenta',
            icon: BookOpen,
            action: () => switchSection('ledger', 'libro-mayor'),
            tone: 'wine',
        },
        {
            title: 'Balance de Comprobación',
            subtitle: 'Validación de débitos y créditos',
            icon: Scale,
            action: () => switchSection('trial', 'balance-comprobacion'),
            tone: 'wine',
        },
        {
            title: 'Auxiliares Contables',
            subtitle: 'Detalle por cuenta y movimiento',
            icon: UsersRound,
            action: () => setTab('ledger'),
            tone: 'wine',
        },
        {
            title: 'Estados Financieros',
            subtitle: 'Balance, resultados y flujo',
            icon: FileChartColumn,
            action: () => router.visit(routes.reports.url(uuid)),
            tone: 'wine',
        },
        {
            title: 'Centros de Costos',
            subtitle: 'Control por área o proyecto',
            icon: Building2,
            action: () => openPreparedModule('centros-costos'),
            tone: 'wine',
            requiredFeature: 'advanced_reports',
        },
        {
            title: 'Presupuestos',
            subtitle: 'Presupuesto vs. ejecución',
            icon: PieChart,
            action: () => openPreparedModule('presupuestos'),
            tone: 'wine',
            requiredFeature: 'advanced_reports',
        },
        {
            title: 'Activos Fijos',
            subtitle: 'Control de bienes y vida útil',
            icon: Landmark,
            action: () => openPreparedModule('activos-fijos'),
            tone: 'blue',
            requiredFeature: 'advanced_reports',
        },
        {
            title: 'Asientos Recurrentes',
            subtitle: 'Automatización mensual',
            icon: RefreshCw,
            action: () => openPreparedModule('asientos-recurrentes'),
            tone: 'orange',
            requiredFeature: 'approval_workflows',
        },
        {
            title: 'Ajustes Contables',
            subtitle: 'Reclasificaciones y provisiones',
            icon: SlidersHorizontal,
            action: () => setTab(canWrite ? 'draft' : 'journal'),
            tone: 'purple',
        },
        {
            title: 'Períodos Contables',
            subtitle: 'Abrir, cerrar y bloquear meses',
            icon: CalendarDays,
            action: () => switchSection('periods', 'periodos-contables'),
            tone: 'wine',
        },
        {
            title: 'Cierre Contable',
            subtitle: 'Cierre mensual y anual',
            icon: LockKeyhole,
            action: () => switchSection('periods', 'periodos-contables'),
            tone: 'orange',
        },
        {
            title: 'Saldos Iniciales',
            subtitle: 'Configuración de arranque',
            icon: FilePlus2,
            action: () => setTab(canWrite ? 'draft' : 'journal'),
            tone: 'purple',
        },
        {
            title: 'Importación Contable',
            subtitle: 'Carga desde Excel o CSV',
            icon: FolderUp,
            action: () => openPreparedModule('importacion-contable'),
            tone: 'blue',
        },
    ];
    const visibleAccountingModules = accountingModules.filter(
        (module) =>
            module.requiredFeature === undefined ||
            hasPlanFeature(planFeatures, module.requiredFeature),
    );
    const moduleTones: Record<string, string> = {
        wine: 'bg-[#f8e9ea] text-[#8a1630]',
        blue: 'bg-[#e9f0fb] text-[#315fa8]',
        orange: 'bg-[#fff0e7] text-[#d2632d]',
        purple: 'bg-[#eee9fb] text-[#6550b5]',
    };
    const sectionHeading =
        tab === 'journal'
            ? {
                  title: 'Libro Diario',
                  subtitle: 'Registro cronológico de asientos contables',
              }
            : tab === 'ledger'
              ? {
                    title: 'Libro Mayor',
                    subtitle: 'Movimientos y saldos por cuenta contable',
                }
              : tab === 'trial'
                ? {
                      title: 'Balance de Comprobación',
                      subtitle: 'Sumas y saldos por cuenta contable',
                  }
                : tab === 'setup'
                  ? {
                        title: 'Plan de Cuentas',
                        subtitle:
                            'Estructura jerárquica y configuración de cuentas contables',
                    }
                  : tab === 'periods'
                    ? {
                          title: 'Períodos Contables',
                          subtitle: 'Apertura, cierre y control de períodos',
                      }
                    : {
                          title: 'Contabilidad General',
                          subtitle: `Centro de control contable · ${company.name}`,
                      };

    return (
        <>
            <Head title={`${company.name} · Contabilidad`} />
            <main className="min-h-full space-y-5 bg-[#faf8f7] p-4 text-[#142135] sm:p-8">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-[#142135]">
                            {sectionHeading.title}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            {sectionHeading.subtitle}
                        </p>
                    </div>
                    {tab === 'overview' ? (
                        <Link
                            href={routes.index()}
                            className="inline-flex items-center gap-2 rounded-xl border border-[#e5dada] bg-white px-4 py-2 text-sm font-semibold text-[#640000] shadow-sm"
                        >
                            <ArrowLeft size={16} /> Empresas
                        </Link>
                    ) : (
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() => switchSection('overview')}
                                className="inline-flex items-center gap-2 rounded-xl border border-[#e5dada] bg-white px-4 py-2 text-sm font-semibold text-[#640000] shadow-sm"
                            >
                                <ArrowLeft size={16} /> Centro contable
                            </button>
                            {tab === 'journal' && canWrite && (
                                <button
                                    type="button"
                                    onClick={() => setTab('draft')}
                                    className="inline-flex items-center gap-2 rounded-xl bg-[#7d0914] px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_20px_rgba(100,0,0,.18)]"
                                >
                                    <Plus size={17} /> Nuevo asiento
                                </button>
                            )}
                        </div>
                    )}
                </header>
                {tab === 'overview' && (
                    <>
                        <section className="grid gap-4 xl:grid-cols-[minmax(0,2.2fr)_minmax(280px,.9fr)]">
                            <div
                                className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                                aria-label="Resumen contable"
                            >
                                {[
                                    {
                                        label: 'Débitos',
                                        value: money(periodDebit),
                                        hint: 'Este período',
                                        icon: ArrowDownLeft,
                                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                                    },
                                    {
                                        label: 'Créditos',
                                        value: money(periodCredit),
                                        hint: 'Este período',
                                        icon: ArrowUpRight,
                                        tone: 'bg-[#e7f5ec] text-[#3b9b68]',
                                    },
                                    {
                                        label: 'Asientos',
                                        value: postedEntries.length,
                                        hint: 'Este período',
                                        icon: FileChartColumn,
                                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                                    },
                                    {
                                        label: 'Pendientes',
                                        value: pendingEntries,
                                        hint: 'Requieren atención',
                                        icon: Clock3,
                                        tone: 'bg-[#fff0e7] text-[#d2632d]',
                                    },
                                ].map((metric) => (
                                    <article
                                        key={metric.label}
                                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_8px_22px_rgba(31,38,55,.06)]"
                                    >
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={`grid size-10 shrink-0 place-items-center rounded-full ${metric.tone}`}
                                            >
                                                <metric.icon size={19} />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-xs font-medium text-slate-500">
                                                    {metric.label}
                                                </p>
                                                <strong className="mt-1 block text-base whitespace-nowrap text-[#172033]">
                                                    {metric.value}
                                                </strong>
                                                <p className="mt-1 text-[10px] text-slate-400">
                                                    {metric.hint}
                                                </p>
                                            </div>
                                        </div>
                                    </article>
                                ))}
                            </div>
                            <aside className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_8px_22px_rgba(31,38,55,.06)]">
                                {[
                                    {
                                        icon: Check,
                                        label:
                                            periodDebit === periodCredit
                                                ? 'Balance cuadrado'
                                                : 'Balance por revisar',
                                        tone: 'text-emerald-600',
                                    },
                                    {
                                        icon: Clock3,
                                        label: `${pendingEntries} partidas pendientes de aprobación`,
                                        tone: 'text-amber-500',
                                    },
                                    {
                                        icon: NotebookTabs,
                                        label: `${accounts.length} cuentas configuradas`,
                                        tone: 'text-[#6550b5]',
                                    },
                                    {
                                        icon: CalendarDays,
                                        label: `Período abierto: ${currentPeriod?.name ?? 'Sin configurar'}`,
                                        tone: 'text-[#315fa8]',
                                    },
                                ].map((item, index) => (
                                    <div
                                        key={item.label}
                                        className={`flex min-h-9 items-center gap-3 px-4 py-2 text-xs font-medium ${index ? 'border-t border-slate-100' : ''}`}
                                    >
                                        <item.icon
                                            size={16}
                                            className={item.tone}
                                        />
                                        <span>{item.label}</span>
                                    </div>
                                ))}
                            </aside>
                        </section>
                        <section
                            className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                            aria-label="Módulos de contabilidad general"
                        >
                            {visibleAccountingModules.map((module, index) => (
                                <button
                                    key={module.title}
                                    type="button"
                                    onClick={module.action}
                                    className="group flex min-h-20 items-center gap-3 rounded-2xl border border-[#e8e4e2] bg-white p-3 text-left shadow-[0_7px_18px_rgba(31,38,55,.05)] transition hover:-translate-y-0.5 hover:border-[#d9bfc2] hover:shadow-[0_12px_26px_rgba(91,0,7,.10)]"
                                >
                                    <span
                                        className={`grid size-11 shrink-0 place-items-center rounded-xl ${moduleTones[module.tone]}`}
                                    >
                                        <module.icon size={21} />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <strong className="block text-sm text-[#172033]">
                                            {index + 1}. {module.title}
                                        </strong>
                                        <span className="mt-1 block text-[11px] leading-4 text-slate-500">
                                            {module.subtitle}
                                        </span>
                                    </span>
                                    <ChevronRight
                                        size={17}
                                        className="shrink-0 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-[#640000]"
                                    />
                                </button>
                            ))}
                        </section>
                    </>
                )}
                {notice && (
                    <p
                        role="status"
                        className="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800"
                    >
                        {notice}
                    </p>
                )}
                <Errors errors={operationErrors} />
                {![
                    'overview',
                    'journal',
                    'ledger',
                    'trial',
                    'setup',
                    'periods',
                ].includes(tab) && (
                    <nav
                        aria-label="Secciones contables"
                        className="flex flex-wrap gap-2 rounded-2xl border border-[#ece3e3] bg-white p-2"
                    >
                        {tabs.map((item) => (
                            <button
                                key={item.id}
                                onClick={() => setTab(item.id)}
                                aria-current={
                                    tab === item.id ? 'page' : undefined
                                }
                                className={`rounded-xl px-4 py-3 text-sm font-medium ${tab === item.id ? 'bg-[#640000] text-white' : 'text-slate-600 hover:bg-[#f8eeee]'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </nav>
                )}
                {tab === 'journal' && (
                    <div id="libro-diario" className="scroll-mt-20 space-y-4">
                        <section className="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(270px,1fr)]">
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                {[
                                    {
                                        label: 'Asientos',
                                        value: postedEntries.length,
                                        hint: 'Este período',
                                        icon: FileChartColumn,
                                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                                    },
                                    {
                                        label: 'Débitos',
                                        value: money(periodDebit),
                                        hint: 'Este período',
                                        icon: ArrowDownLeft,
                                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                                    },
                                    {
                                        label: 'Créditos',
                                        value: money(periodCredit),
                                        hint: 'Este período',
                                        icon: ArrowUpRight,
                                        tone: 'bg-[#e7f5ec] text-[#3b9b68]',
                                    },
                                    {
                                        label: 'Diferencia',
                                        value: money(
                                            Math.abs(
                                                periodDebit - periodCredit,
                                            ),
                                        ),
                                        hint: 'Este período',
                                        icon: Scale,
                                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                                    },
                                    {
                                        label: 'Pendientes',
                                        value: pendingEntries,
                                        hint: 'Requieren atención',
                                        icon: Clock3,
                                        tone: 'bg-[#fff0e7] text-[#d2632d]',
                                    },
                                ].map((metric) => (
                                    <article
                                        key={metric.label}
                                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]"
                                    >
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={`grid size-10 shrink-0 place-items-center rounded-full ${metric.tone}`}
                                            >
                                                <metric.icon size={19} />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-[11px] font-medium text-slate-500">
                                                    {metric.label}
                                                </p>
                                                <strong className="mt-1 block text-sm whitespace-nowrap text-[#172033]">
                                                    {metric.value}
                                                </strong>
                                                <p className="mt-1 text-[9px] text-slate-400">
                                                    {metric.hint}
                                                </p>
                                            </div>
                                        </div>
                                    </article>
                                ))}
                            </div>
                            <aside className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                                {[
                                    {
                                        icon: Check,
                                        label:
                                            periodDebit === periodCredit
                                                ? 'Balance cuadrado'
                                                : 'Balance por revisar',
                                        tone: 'text-emerald-600',
                                    },
                                    {
                                        icon: CalendarDays,
                                        label: `Período abierto: ${currentPeriod?.name ?? 'Sin configurar'}`,
                                        tone: 'text-[#315fa8]',
                                    },
                                    {
                                        icon: Clock3,
                                        label: `${pendingEntries} asientos pendientes de revisión`,
                                        tone: 'text-amber-500',
                                    },
                                    {
                                        icon: LockKeyhole,
                                        label: currentPeriod?.closed_at
                                            ? 'Período cerrado'
                                            : 'Período habilitado para captura',
                                        tone: 'text-[#6550b5]',
                                    },
                                ].map((item, index) => (
                                    <div
                                        key={item.label}
                                        className={`flex min-h-9 items-center gap-3 px-4 py-2 text-[11px] font-medium ${index ? 'border-t border-slate-100' : ''}`}
                                    >
                                        <item.icon
                                            size={15}
                                            className={item.tone}
                                        />
                                        <span>{item.label}</span>
                                    </div>
                                ))}
                            </aside>
                        </section>

                        <section className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                                <label className="relative lg:col-span-2">
                                    <span className="sr-only">
                                        Buscar asiento o concepto
                                    </span>
                                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                    <input
                                        value={journalSearch}
                                        onChange={(event) =>
                                            setJournalSearch(event.target.value)
                                        }
                                        placeholder="Buscar asiento o concepto..."
                                        className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-xs outline-none focus:border-[#8a1630]"
                                    />
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Desde
                                    <input
                                        type="date"
                                        value={journalFrom}
                                        onChange={(event) =>
                                            setJournalFrom(event.target.value)
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    />
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Hasta
                                    <input
                                        type="date"
                                        value={journalTo}
                                        onChange={(event) =>
                                            setJournalTo(event.target.value)
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    />
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Tipo de asiento
                                    <select
                                        value={journalKind}
                                        onChange={(event) =>
                                            setJournalKind(event.target.value)
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option value="all">Todos</option>
                                        {Object.entries(kinds).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Estado
                                    <select
                                        value={journalStatus}
                                        onChange={(event) =>
                                            setJournalStatus(event.target.value)
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option value="all">Todos</option>
                                        {Object.entries(statuses).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                            </div>
                            <div className="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-slate-100 pt-3">
                                <label className="min-w-48 text-[10px] font-medium text-slate-500">
                                    Cuenta contable
                                    <select
                                        value={filters.account}
                                        onChange={(event) =>
                                            filter({
                                                account: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option value={0}>Todas</option>
                                        {accounts.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {account.code} · {account.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            openPreparedModule(
                                                'importacion-contable',
                                            )
                                        }
                                        className={secondary}
                                    >
                                        <FolderUp size={15} /> Importar
                                    </button>
                                    {(['xlsx', 'pdf'] as const).map(
                                        (format) => (
                                            <a
                                                key={format}
                                                href={routes.exportMethod.url(
                                                    uuid,
                                                    {
                                                        query: {
                                                            kind: 'journal',
                                                            format,
                                                            from:
                                                                journalFrom ||
                                                                currentPeriod?.starts_on ||
                                                                today(),
                                                            to:
                                                                journalTo ||
                                                                currentPeriod?.ends_on ||
                                                                today(),
                                                        },
                                                    },
                                                )}
                                                className={secondary}
                                            >
                                                {format === 'xlsx' ? (
                                                    <FileSpreadsheet
                                                        size={15}
                                                    />
                                                ) : (
                                                    <FileDown size={15} />
                                                )}
                                                Exportar{' '}
                                                {format === 'xlsx'
                                                    ? 'Excel'
                                                    : 'PDF'}
                                            </a>
                                        ),
                                    )}
                                </div>
                            </div>
                        </section>

                        <section className="grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(360px,.95fr)]">
                            <div className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                    <h2 className="text-sm font-semibold">
                                        Asientos registrados
                                    </h2>
                                    <span className="text-[10px] text-slate-400">
                                        {journalRows.length} visibles
                                    </span>
                                </div>
                                {!journalRows.length ? (
                                    <div className="py-12 text-center">
                                        <BookOpen className="mx-auto mb-3 text-[#b67e7d]" />
                                        <p className="text-sm font-medium">
                                            No hay asientos en esta selección.
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Ajusta los filtros o crea un nuevo
                                            asiento.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[760px] text-xs">
                                            <thead className="bg-[#f8f7f6] text-left text-[10px] text-slate-500">
                                                <tr>
                                                    <th className="w-10 px-3 py-2" />
                                                    <th className="px-3 py-2">
                                                        Asiento
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Fecha
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Concepto
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Origen
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Debe
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Haber
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Estado
                                                    </th>
                                                    <th className="w-9 px-2 py-2" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {journalRows.map((item) => {
                                                    const itemDebit =
                                                        item.lines.reduce(
                                                            (sum, line) =>
                                                                sum +
                                                                Number(
                                                                    line.debit_cents,
                                                                ),
                                                            0,
                                                        );
                                                    const itemCredit =
                                                        item.lines.reduce(
                                                            (sum, line) =>
                                                                sum +
                                                                Number(
                                                                    line.credit_cents,
                                                                ),
                                                            0,
                                                        );
                                                    const selected =
                                                        selectedEntry?.id ===
                                                        item.id;

                                                    return (
                                                        <tr
                                                            key={item.id}
                                                            className={`cursor-pointer border-t border-slate-100 transition hover:bg-[#fcf7f7] ${selected ? 'bg-[#fbefef]' : ''}`}
                                                            onClick={() =>
                                                                setSelectedEntryId(
                                                                    item.id,
                                                                )
                                                            }
                                                        >
                                                            <td className="px-3 py-3">
                                                                <button
                                                                    type="button"
                                                                    aria-label={`Seleccionar asiento ${item.number ?? item.id}`}
                                                                    className={`size-3.5 rounded-full border ${selected ? 'border-[#7d0914] bg-[#7d0914] ring-2 ring-[#f1cfd2]' : 'border-slate-300 bg-white'}`}
                                                                />
                                                            </td>
                                                            <td className="px-3 py-3 font-semibold text-[#26344c]">
                                                                DI-
                                                                {String(
                                                                    item.number ??
                                                                        item.id,
                                                                ).padStart(
                                                                    6,
                                                                    '0',
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-3 whitespace-nowrap">
                                                                {
                                                                    item.entry_date
                                                                }
                                                            </td>
                                                            <td className="max-w-44 truncate px-3 py-3">
                                                                {
                                                                    item.description
                                                                }
                                                            </td>
                                                            <td className="px-3 py-3">
                                                                {kinds[
                                                                    item.kind
                                                                ] ?? item.kind}
                                                            </td>
                                                            <td className="px-3 py-3 text-right tabular-nums">
                                                                {money(
                                                                    itemDebit,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-3 text-right tabular-nums">
                                                                {money(
                                                                    itemCredit,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-3">
                                                                <span
                                                                    className={`rounded-full px-2 py-1 text-[9px] font-semibold ${item.status === 'posted' ? 'bg-emerald-100 text-emerald-800' : item.status === 'draft' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}
                                                                >
                                                                    {
                                                                        statuses[
                                                                            item
                                                                                .status
                                                                        ]
                                                                    }
                                                                </span>
                                                            </td>
                                                            <td className="px-2 py-3">
                                                                <MoreHorizontal
                                                                    size={15}
                                                                />
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                                <div className="border-t border-slate-100 px-4 py-2">
                                    <Pager page={journal} />
                                </div>
                            </div>

                            <aside className="overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                                {selectedEntry ? (
                                    <>
                                        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                                            <div>
                                                <h2 className="text-sm font-semibold">
                                                    Asiento DI-
                                                    {String(
                                                        selectedEntry.number ??
                                                            selectedEntry.id,
                                                    ).padStart(6, '0')}
                                                </h2>
                                                <p className="mt-1 text-[10px] text-slate-500">
                                                    {kinds[
                                                        selectedEntry.kind
                                                    ] ?? selectedEntry.kind}
                                                </p>
                                            </div>
                                            <span
                                                className={`rounded-full px-2 py-1 text-[9px] font-semibold ${selectedEntry.status === 'posted' ? 'bg-emerald-100 text-emerald-800' : selectedEntry.status === 'draft' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}
                                            >
                                                {statuses[selectedEntry.status]}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-2 gap-x-4 gap-y-2 px-4 py-3 text-[10px]">
                                            <p className="text-slate-500">
                                                Fecha
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.entry_date}
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Período
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.period_name}
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Creado por
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.author}
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Revisado por
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.approver ??
                                                        'Pendiente'}
                                                </strong>
                                            </p>
                                            <p className="col-span-2 text-slate-500">
                                                Concepto
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.description}
                                                    {selectedEntry.fel_document_id && (
                                                        <Link
                                                            className="mt-2 block text-sm text-[#640000]"
                                                            href={fel.show([
                                                                company.public_id,
                                                                selectedEntry.fel_document_id,
                                                            ])}
                                                        >
                                                            Ver documento FEL de
                                                            origen
                                                        </Link>
                                                    )}
                                                </strong>
                                            </p>
                                        </div>
                                        <div className="overflow-x-auto border-y border-slate-100">
                                            <table className="w-full min-w-[360px] text-[10px]">
                                                <thead className="bg-[#f8f7f6] text-slate-500">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left">
                                                            Código
                                                        </th>
                                                        <th className="px-3 py-2 text-left">
                                                            Cuenta
                                                        </th>
                                                        <th className="px-3 py-2 text-right">
                                                            Debe
                                                        </th>
                                                        <th className="px-3 py-2 text-right">
                                                            Haber
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {selectedEntry.lines.map(
                                                        (line, index) => (
                                                            <tr
                                                                key={`${line.code}-${index}`}
                                                                className="border-t border-slate-100"
                                                            >
                                                                <td className="px-3 py-2">
                                                                    {line.code}
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {line.name}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {money(
                                                                        line.debit_cents,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {money(
                                                                        line.credit_cents,
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                                <tfoot className="border-t border-slate-200 font-semibold">
                                                    <tr>
                                                        <td
                                                            colSpan={2}
                                                            className="px-3 py-2"
                                                        >
                                                            Total
                                                        </td>
                                                        <td className="px-3 py-2 text-right">
                                                            {money(
                                                                selectedEntry.lines.reduce(
                                                                    (
                                                                        sum,
                                                                        line,
                                                                    ) =>
                                                                        sum +
                                                                        Number(
                                                                            line.debit_cents,
                                                                        ),
                                                                    0,
                                                                ),
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-right">
                                                            {money(
                                                                selectedEntry.lines.reduce(
                                                                    (
                                                                        sum,
                                                                        line,
                                                                    ) =>
                                                                        sum +
                                                                        Number(
                                                                            line.credit_cents,
                                                                        ),
                                                                    0,
                                                                ),
                                                            )}
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                        {canManage && (
                                            <div className="flex flex-wrap gap-2 border-b border-slate-100 p-3">
                                                {selectedEntry.status ===
                                                    'draft' && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            className={primary}
                                                            onClick={() => {
                                                                action.clearErrors();
                                                                action.setData(
                                                                    'action',
                                                                    'post',
                                                                );
                                                                setTarget(
                                                                    selectedEntry,
                                                                );
                                                            }}
                                                        >
                                                            <Check size={15} />
                                                            Revisar y aprobar
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondary
                                                            }
                                                            onClick={() => {
                                                                action.clearErrors();
                                                                action.setData(
                                                                    'action',
                                                                    'void',
                                                                );
                                                                setTarget(
                                                                    selectedEntry,
                                                                );
                                                            }}
                                                        >
                                                            Descartar
                                                        </button>
                                                    </>
                                                )}
                                                {selectedEntry.status ===
                                                    'posted' &&
                                                    !selectedEntry.reversal_of &&
                                                    !selectedEntry.reversed_by && (
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondary
                                                            }
                                                            onClick={() => {
                                                                action.clearErrors();
                                                                action.setData(
                                                                    'action',
                                                                    'reverse',
                                                                );
                                                                setTarget(
                                                                    selectedEntry,
                                                                );
                                                            }}
                                                        >
                                                            Registrar reverso
                                                        </button>
                                                    )}
                                            </div>
                                        )}
                                        <div className="px-4 py-3">
                                            <h3 className="text-xs font-semibold">
                                                Documento adjunto
                                            </h3>
                                            <EntryDocuments
                                                company={uuid}
                                                entry={selectedEntry}
                                                canWrite={canWrite}
                                            />
                                            <div className="mt-3 border-t border-slate-100 pt-3">
                                                <h3 className="text-xs font-semibold">
                                                    Historial
                                                </h3>
                                                <div className="mt-2 space-y-2 text-[10px] text-slate-500">
                                                    <p>
                                                        Creado por{' '}
                                                        {selectedEntry.author}
                                                    </p>
                                                    {selectedEntry.approver && (
                                                        <p>
                                                            Revisado por{' '}
                                                            {
                                                                selectedEntry.approver
                                                            }
                                                        </p>
                                                    )}
                                                    {selectedEntry.posted_at && (
                                                        <p>
                                                            Aprobado y
                                                            contabilizado
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="grid min-h-80 place-items-center p-8 text-center">
                                        <div>
                                            <BookOpen className="mx-auto text-[#b67e7d]" />
                                            <p className="mt-3 text-sm font-medium">
                                                Selecciona un asiento
                                            </p>
                                            <p className="mt-1 text-xs text-slate-500">
                                                El detalle aparecerá en este
                                                panel.
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </aside>
                        </section>
                    </div>
                )}
                {tab === 'draft' && canWrite && (
                    <Card title="Preparar asiento contable">
                        <p className="mb-5 text-sm text-slate-500">
                            Se guardará como borrador. No afectará los libros
                            hasta que el propietario lo apruebe.
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                draft.post(routes.entry.url(uuid), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        draft.setData({
                                            ...draft.data,
                                            request_id: crypto.randomUUID(),
                                            description: '',
                                            reference: '',
                                            lines: [emptyLine(), emptyLine()],
                                        });
                                        setTab('journal');
                                    },
                                });
                            }}
                        >
                            <div className="grid gap-4 md:grid-cols-3">
                                <label className="text-sm font-medium">
                                    Período abierto
                                    <select
                                        required
                                        className={field}
                                        value={draft.data.period_id}
                                        onChange={(e) =>
                                            draft.setData(
                                                'period_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="">Seleccionar</option>
                                        {openPeriods.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Fecha
                                    <input
                                        required
                                        type="date"
                                        className={field}
                                        value={draft.data.entry_date}
                                        onChange={(e) =>
                                            draft.setData(
                                                'entry_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    Tipo
                                    <select
                                        className={field}
                                        value={draft.data.kind}
                                        onChange={(e) =>
                                            draft.setData(
                                                'kind',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="standard">
                                            Movimiento ordinario
                                        </option>
                                        <option value="opening">
                                            Saldos iniciales
                                        </option>
                                        <option value="adjustment">
                                            Asiento de ajuste
                                        </option>
                                        <option value="closing">
                                            Asiento de cierre
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <label className="mt-4 block text-sm font-medium">
                                Referencia del comprobante
                                <input
                                    maxLength={80}
                                    className={field}
                                    value={draft.data.reference}
                                    onChange={(e) =>
                                        draft.setData(
                                            'reference',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Ej. REC-2026-001, depósito o documento interno"
                                />
                            </label>
                            <label className="mt-4 block text-sm font-medium">
                                Descripción
                                <textarea
                                    required
                                    maxLength={500}
                                    className={field}
                                    value={draft.data.description}
                                    onChange={(e) =>
                                        draft.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="mt-5 space-y-3">
                                {draft.data.lines.map((line, i) => (
                                    <div
                                        key={i}
                                        className="grid grid-cols-2 items-end gap-3 rounded-xl bg-[#faf8f7] p-3 lg:grid-cols-[minmax(200px,1fr)_150px_150px_40px]"
                                    >
                                        <label className="col-span-2 text-xs font-medium lg:col-span-1">
                                            Cuenta · línea {i + 1}
                                            <select
                                                required
                                                className={field}
                                                value={line.account_id}
                                                onChange={(e) =>
                                                    updateLine(
                                                        i,
                                                        'account_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Seleccionar cuenta
                                                </option>
                                                {accounts.map((a) => (
                                                    <option
                                                        key={a.id}
                                                        value={a.id}
                                                    >
                                                        {a.code} · {a.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="text-xs font-medium">
                                            Debe
                                            <input
                                                required
                                                inputMode="decimal"
                                                pattern="[0-9]+(\.[0-9]{1,2})?"
                                                className={field}
                                                value={line.debit}
                                                onChange={(e) =>
                                                    updateLine(
                                                        i,
                                                        'debit',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="text-xs font-medium">
                                            Haber
                                            <input
                                                required
                                                inputMode="decimal"
                                                pattern="[0-9]+(\.[0-9]{1,2})?"
                                                className={field}
                                                value={line.credit}
                                                onChange={(e) =>
                                                    updateLine(
                                                        i,
                                                        'credit',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            disabled={
                                                draft.data.lines.length <= 2
                                            }
                                            aria-label={`Quitar línea ${i + 1}`}
                                            className="p-3 text-[#640000] disabled:opacity-30"
                                            onClick={() =>
                                                draft.setData(
                                                    'lines',
                                                    draft.data.lines.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 size={18} />
                                        </button>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
                                <button
                                    type="button"
                                    disabled={draft.data.lines.length >= 100}
                                    className={secondary}
                                    onClick={() =>
                                        draft.setData('lines', [
                                            ...draft.data.lines,
                                            emptyLine(),
                                        ])
                                    }
                                >
                                    <Plus size={16} /> Agregar línea
                                </button>
                                <div
                                    aria-live="polite"
                                    className={`rounded-xl px-4 py-3 text-sm ${debit > 0 && debit === credit ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}
                                >
                                    Debe {money(debit)} · Haber {money(credit)}{' '}
                                    · Diferencia {money(debit - credit)}
                                </div>
                            </div>
                            <Errors errors={draft.errors} />
                            <button
                                disabled={
                                    draft.processing ||
                                    !accounts.length ||
                                    !openPeriods.length ||
                                    debit <= 0 ||
                                    debit !== credit
                                }
                                className={`${primary} mt-5`}
                            >
                                <FilePlus2 size={17} />
                                {draft.processing
                                    ? 'Guardando…'
                                    : 'Guardar borrador'}
                            </button>
                            {(!accounts.length || !openPeriods.length) && (
                                <p className="mt-3 text-sm text-amber-800">
                                    Necesitas cuentas y un período abierto para
                                    continuar.
                                </p>
                            )}
                        </form>
                    </Card>
                )}
                {tab === 'ledger' && (
                    <LedgerView
                        uuid={uuid}
                        accounts={accounts}
                        periods={periods}
                        balances={balances}
                        ledger={ledger}
                        filters={filters}
                        onFilter={filter}
                    />
                )}
                {tab === 'trial' && (
                    <TrialBalanceView
                        uuid={uuid}
                        periods={periods}
                        accounts={accounts}
                        balances={balances}
                        filters={filters}
                        pendingEntries={pendingEntries}
                        onFilter={filter}
                    />
                )}
                {tab === 'setup' && (
                    <ChartOfAccountsView
                        uuid={uuid}
                        accounts={accounts}
                        periods={periods}
                        balances={balances}
                        ledger={ledger}
                        filters={filters}
                        history={props.accountHistory}
                        canManage={canManage}
                        onFilter={filter}
                        onNew={() => setNewAccountOpen(true)}
                        onEdit={(account) => {
                            editAccount.clearErrors();
                            editAccount.setData({
                                code: account.code,
                                name: account.name,
                                type: account.type,
                                reason: '',
                                acknowledged: false,
                            });
                            setEditingAccount(account);
                        }}
                        onInstall={() =>
                            operation(routes.accountTemplate.url(uuid), {})
                        }
                        onImport={() =>
                            openPreparedModule('importacion-contable')
                        }
                        onViewLedger={(accountId) => {
                            setTab('ledger');
                            router.get(
                                routes.show.url(uuid),
                                { ...filters, account: accountId },
                                {
                                    preserveState: true,
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        window.location.hash = 'libro-mayor';
                                    },
                                },
                            );
                        }}
                    />
                )}
                {tab === 'periods' && (
                    <AccountingPeriodsView
                        uuid={uuid}
                        periods={periods}
                        filters={filters}
                        canManage={canManage}
                        busy={busy}
                        onFilter={filter}
                        onNew={() => setNewPeriodOpen(true)}
                        onClose={(period) => {
                            if (
                                window.confirm(
                                    'Se bloquearán nuevas contabilizaciones en este período. ¿Continuar?',
                                )
                            ) {
                                operation(
                                    routes.close.url({
                                        company: uuid,
                                        period: period.id,
                                    }),
                                    {},
                                );
                            }
                        }}
                    />
                )}
                {tab === 'access' && canManage && (
                    <Card title="Permisos por empresa">
                        <p className="mb-5 text-sm text-slate-500">
                            Solo aparecen colaboradores activos de esta oficina.
                            El propietario conserva la aprobación de asientos,
                            los reversos y el cierre de períodos.
                        </p>
                        <div className="space-y-3">
                            {members.map((m) => (
                                <div
                                    key={m.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"
                                >
                                    <span className="font-medium">
                                        {m.name}
                                    </span>
                                    <label className="text-xs text-slate-500">
                                        Permiso
                                        <select
                                            aria-label={`Permiso de ${m.name}`}
                                            disabled={busy}
                                            className={field}
                                            value={m.permission}
                                            onChange={(e) =>
                                                operation(
                                                    routes.assign.url(uuid),
                                                    {
                                                        user_id: m.id,
                                                        permission:
                                                            e.target.value,
                                                    },
                                                )
                                            }
                                        >
                                            <option value="none">
                                                Sin acceso
                                            </option>
                                            <option value="viewer">
                                                Consultar
                                            </option>
                                            <option value="editor">
                                                Consultar y preparar borradores
                                            </option>
                                        </select>
                                    </label>
                                </div>
                            ))}
                            {!members.length && (
                                <p className="text-sm text-slate-500">
                                    Invita primero a tu equipo desde Mi
                                    organización.
                                </p>
                            )}
                        </div>
                    </Card>
                )}
                {target && (
                    <Dialog
                        open
                        onOpenChange={(open) => {
                            if (!open && !action.processing) {
                                setTarget(null);
                            }
                        }}
                    >
                        <DialogContent className="max-h-[90dvh] overflow-auto rounded-2xl bg-white text-[#142135] shadow-xl">
                            <DialogTitle
                                id="entry-action-title"
                                className="text-xl font-semibold"
                            >
                                {action.data.action === 'post'
                                    ? 'Aprobar y contabilizar'
                                    : action.data.action === 'void'
                                      ? 'Descartar borrador'
                                      : 'Registrar reverso'}
                            </DialogTitle>
                            <DialogDescription className="mt-2 text-sm text-slate-600">
                                {target.description}
                            </DialogDescription>
                            <p className="mt-3 rounded-xl bg-[#faf4f4] p-3 text-sm text-[#640000]">
                                {action.data.action === 'post'
                                    ? 'El asiento quedará bloqueado para edición y afectará los libros. Revisa sus cuentas e importes antes de confirmar.'
                                    : action.data.action === 'reverse'
                                      ? 'Se contabilizará un nuevo asiento con debe y haber intercambiados. El original permanecerá intacto.'
                                      : 'Se conservarán las líneas y el motivo en la bitácora; no afectará los libros.'}
                            </p>
                            <form
                                className="mt-4 space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    action.post(
                                        routes.process.url({
                                            company: uuid,
                                            entry: target.public_id,
                                        }),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setTarget(null);
                                                action.reset('reason');
                                            },
                                        },
                                    );
                                }}
                            >
                                {action.data.action === 'reverse' && (
                                    <>
                                        <label className="block text-sm">
                                            Período abierto
                                            <select
                                                required
                                                className={field}
                                                value={action.data.period_id}
                                                onChange={(e) =>
                                                    action.setData(
                                                        'period_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Seleccionar
                                                </option>
                                                {openPeriods.map((p) => (
                                                    <option
                                                        key={p.id}
                                                        value={p.id}
                                                    >
                                                        {p.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="block text-sm">
                                            Fecha del reverso
                                            <input
                                                required
                                                type="date"
                                                className={field}
                                                value={action.data.entry_date}
                                                onChange={(e) =>
                                                    action.setData(
                                                        'entry_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                    </>
                                )}
                                {action.data.action !== 'post' && (
                                    <label className="block text-sm">
                                        Motivo
                                        <textarea
                                            required
                                            maxLength={350}
                                            className={field}
                                            value={action.data.reason}
                                            onChange={(e) =>
                                                action.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                )}
                                <Errors errors={action.errors} />
                                <div className="flex justify-end gap-3 pt-3">
                                    <button
                                        type="button"
                                        disabled={action.processing}
                                        className={secondary}
                                        onClick={() => setTarget(null)}
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        disabled={action.processing}
                                        className={primary}
                                    >
                                        {action.processing
                                            ? 'Procesando…'
                                            : 'Confirmar'}
                                    </button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
                <Dialog
                    open={newPeriodOpen}
                    onOpenChange={(open) => {
                        if (!open && !periodForm.processing) {
                            setNewPeriodOpen(false);
                        }
                    }}
                >
                    <DialogContent className="bg-white text-[#142135] sm:max-w-lg">
                        <DialogTitle>Abrir período contable</DialogTitle>
                        <DialogDescription>
                            Define el rango que recibirá los asientos de esta
                            empresa. Los períodos no pueden traslaparse.
                        </DialogDescription>
                        <form
                            className="mt-4 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                periodForm.post(routes.period.url(uuid), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        periodForm.reset();
                                        setNewPeriodOpen(false);
                                    },
                                });
                            }}
                        >
                            <label className="block text-sm">
                                Nombre del período
                                <input
                                    required
                                    className={field}
                                    maxLength={80}
                                    placeholder="Septiembre 2026"
                                    value={periodForm.data.name}
                                    onChange={(event) =>
                                        periodForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="text-sm">
                                    Fecha de inicio
                                    <input
                                        required
                                        type="date"
                                        className={field}
                                        value={periodForm.data.starts_on}
                                        onChange={(event) =>
                                            periodForm.setData(
                                                'starts_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="text-sm">
                                    Fecha de finalización
                                    <input
                                        required
                                        type="date"
                                        className={field}
                                        value={periodForm.data.ends_on}
                                        onChange={(event) =>
                                            periodForm.setData(
                                                'ends_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <Errors errors={periodForm.errors} />
                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    disabled={periodForm.processing}
                                    onClick={() => setNewPeriodOpen(false)}
                                    className={secondary}
                                >
                                    Cancelar
                                </button>
                                <button
                                    disabled={periodForm.processing}
                                    className={primary}
                                >
                                    {periodForm.processing
                                        ? 'Abriendo…'
                                        : 'Abrir período'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog
                    open={newAccountOpen}
                    onOpenChange={(open) => {
                        if (!open && !accountForm.processing) {
                            setNewAccountOpen(false);
                        }
                    }}
                >
                    <DialogContent className="bg-white text-[#142135] sm:max-w-lg">
                        <DialogTitle>Nueva cuenta contable</DialogTitle>
                        <DialogDescription>
                            Crea una cuenta auxiliar disponible para los
                            asientos de esta empresa.
                        </DialogDescription>
                        <form
                            className="mt-4 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                accountForm.post(routes.account.url(uuid), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        accountForm.reset();
                                        setNewAccountOpen(false);
                                    },
                                });
                            }}
                        >
                            <label className="block text-sm">
                                Código
                                <input
                                    required
                                    maxLength={20}
                                    className={field}
                                    value={accountForm.data.code}
                                    onChange={(event) =>
                                        accountForm.setData(
                                            'code',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="1101"
                                />
                            </label>
                            <label className="block text-sm">
                                Nombre
                                <input
                                    required
                                    maxLength={150}
                                    className={field}
                                    value={accountForm.data.name}
                                    onChange={(event) =>
                                        accountForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Caja"
                                />
                            </label>
                            <label className="block text-sm">
                                Clasificación
                                <select
                                    className={field}
                                    value={accountForm.data.type}
                                    onChange={(event) =>
                                        accountForm.setData(
                                            'type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(types).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <Errors errors={accountForm.errors} />
                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    disabled={accountForm.processing}
                                    onClick={() => setNewAccountOpen(false)}
                                    className={secondary}
                                >
                                    Cancelar
                                </button>
                                <button
                                    disabled={accountForm.processing}
                                    className={primary}
                                >
                                    {accountForm.processing
                                        ? 'Guardando…'
                                        : 'Crear cuenta'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog
                    open={editingAccount !== null}
                    onOpenChange={(open) => {
                        if (!open && !editAccount.processing) {
                            setEditingAccount(null);
                        }
                    }}
                >
                    <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#040b15]">
                        <DialogTitle>Editar cuenta contable</DialogTitle>
                        <DialogDescription>
                            Se mantienen los asientos e importes. El código,
                            nombre y clasificación nuevos aparecerán también en
                            los reportes de movimientos anteriores. Las cuentas
                            usadas en períodos cerrados no se pueden editar.
                        </DialogDescription>
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (editingAccount) {
                                    editAccount.patch(
                                        routes.updateAccount.url({
                                            company: uuid,
                                            account: editingAccount.id,
                                        }),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setEditingAccount(null),
                                        },
                                    );
                                }
                            }}
                        >
                            <label className="block text-sm">
                                Código
                                <input
                                    className={field}
                                    value={editAccount.data.code}
                                    required
                                    maxLength={20}
                                    onChange={(e) =>
                                        editAccount.setData(
                                            'code',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="block text-sm">
                                Nombre
                                <input
                                    className={field}
                                    value={editAccount.data.name}
                                    required
                                    maxLength={150}
                                    onChange={(e) =>
                                        editAccount.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="block text-sm">
                                Clasificación
                                <select
                                    className={field}
                                    value={editAccount.data.type}
                                    onChange={(e) =>
                                        editAccount.setData(
                                            'type',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(types).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label className="block text-sm">
                                Motivo de la corrección
                                <textarea
                                    className={field}
                                    required
                                    maxLength={350}
                                    value={editAccount.data.reason}
                                    onChange={(e) =>
                                        editAccount.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    required
                                    checked={editAccount.data.acknowledged}
                                    onChange={(e) =>
                                        editAccount.setData(
                                            'acknowledged',
                                            e.target.checked,
                                        )
                                    }
                                />
                                Entiendo que el cambio actualiza la presentación
                                y clasificación de los movimientos existentes.
                            </label>
                            <Errors errors={editAccount.errors} />
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    className={secondary}
                                    disabled={editAccount.processing}
                                    onClick={() => setEditingAccount(null)}
                                >
                                    Cancelar
                                </button>
                                <button
                                    className={primary}
                                    disabled={editAccount.processing}
                                >
                                    {editAccount.processing
                                        ? 'Guardando…'
                                        : 'Guardar corrección'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </main>
        </>
    );
}

function EntryDocuments({
    company,
    entry,
    canWrite,
}: {
    company: string;
    entry: Entry;
    canWrite: boolean;
}) {
    const upload = useForm<{ document: File | null }>({ document: null });
    const documents = entry.documents ?? [];

    return (
        <section className="border-t border-slate-100 bg-white px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <Paperclip size={16} /> Documentos de respaldo
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                            {documents.length}
                        </span>
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                        PDF o imagen, máximo 10 MB. Al contabilizar quedan
                        inmutables.
                    </p>
                </div>
                {canWrite && entry.status === 'draft' && (
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            upload.post(
                                routes.entryDocumentStore.url({
                                    company,
                                    entry: entry.public_id,
                                }),
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => upload.reset(),
                                },
                            );
                        }}
                    >
                        <input
                            required
                            type="file"
                            accept="application/pdf,image/jpeg,image/png,image/webp"
                            className="max-w-64 text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-[#f8eaea] file:px-3 file:py-2 file:font-semibold file:text-[#640000]"
                            onChange={(event) =>
                                upload.setData(
                                    'document',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <button
                            disabled={
                                upload.processing || !upload.data.document
                            }
                            className={secondary}
                        >
                            <Paperclip size={15} />{' '}
                            {upload.processing ? 'Vinculando…' : 'Vincular'}
                        </button>
                    </form>
                )}
            </div>
            <Errors errors={upload.errors} />
            {documents.length > 0 && (
                <div className="mt-3 grid gap-2 md:grid-cols-2">
                    {documents.map((document) => (
                        <div
                            key={document.public_id}
                            className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 text-sm"
                        >
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {document.original_name}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {(document.size_bytes / 1024).toFixed(1)} KB
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                <a
                                    href={routes.entryDocumentDownload.url({
                                        company,
                                        entry: entry.public_id,
                                        document: document.public_id,
                                    })}
                                    className="rounded-lg p-2 text-[#640000] hover:bg-[#f8eaea]"
                                    aria-label={`Descargar ${document.original_name}`}
                                >
                                    <FileDown size={17} />
                                </a>
                                {canWrite && entry.status === 'draft' && (
                                    <button
                                        type="button"
                                        className="rounded-lg p-2 text-[#640000] hover:bg-[#f8eaea]"
                                        aria-label={`Eliminar ${document.original_name}`}
                                        onClick={() =>
                                            router.delete(
                                                routes.entryDocumentDestroy.url(
                                                    {
                                                        company,
                                                        entry: entry.public_id,
                                                        document:
                                                            document.public_id,
                                                    },
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 size={17} />
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
