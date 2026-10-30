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
    FileText,
    Folder,
    FolderUp,
    Info,
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
    Wallet,
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
    shortDate,
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
import { entryStatusLabels } from '@/lib/status-labels';
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
    is_active?: boolean;
    accepts_movements?: boolean;
    is_group?: boolean;
    created_at?: string;
    updated_at?: string;
};
type CloseCheck = {
    key: string;
    label: string;
    status: 'passed' | 'warning' | 'blocked';
    count: number;
    message: string;
};

type Period = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    status?: string;
    closed_at: string | null;
    entries_count: number;
    posted_count: number;
    draft_count: number;
    void_count: number;
    debit_cents: number;
    credit_cents: number;
};
type ReopeningRequest = {
    id: number;
    company_id: number;
    accounting_period_id: number;
    requested_by: number;
    reason: string;
    status: string;
    approved_by: number | null;
    approved_at: string | null;
    rejected_by: number | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    executed_at: string | null;
    created_at: string;
    updated_at: string;
    requester_name: string;
    approver_name: string | null;
};
type Line = {
    code: string;
    name: string;
    description?: string | null;
    third_party_type?: string | null;
    third_party_id?: number | null;
    cost_center?: string | null;
    project?: string | null;
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
    source_type: string | null;
    source_id: number | null;
    accounting_event: string | null;
    posting_key: string | null;
    fel_document_id: string | null;
    kind: string;
    author: string;
    reviewer: string | null;
    approver: string | null;
    poster: string | null;
    support_count: number;
    reviewed_at: string | null;
    approved_at: string | null;
    posted_at: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
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
    history: {
        id: number;
        action: string;
        actor: string | null;
        date: string;
        changes: Record<string, unknown> | null;
    }[];
};
type Balance = Account & {
    debit_cents: string | number;
    credit_cents: string | number;
    movement_count?: string | number;
};
type Movement = Line & {
    entry_public_id: string;
    entry_date: string;
    number: number | null;
    reference: string | null;
    description: string;
    kind: string;
    source_type: string | null;
    period_name: string;
    line_description: string | null;
    document_name: string | null;
    posted_by: string | null;
    running_balance_cents: number;
};
type LedgerOverview = {
    accounts_with_movement: number;
    debit_cents: number;
    credit_cents: number;
    movement_count: number;
    balanced: boolean;
};
type LedgerSummary = {
    opening_balance_cents: number;
    debit_cents: number;
    credit_cents: number;
    closing_balance_cents: number;
    movement_count: number;
};
type TrialBalanceRow = {
    account_id: number;
    code: string;
    name: string;
    type: string;
    group: string;
    level: number;
    nature: 'debit' | 'credit';
    is_active: boolean;
    accepts_movements: boolean;
    is_group: boolean;
    opening_debit_cents: number;
    opening_credit_cents: number;
    period_debit_cents: number;
    period_credit_cents: number;
    closing_debit_cents: number;
    closing_credit_cents: number;
    closing_net_cents: number;
    movement_count: number;
    has_movements: boolean;
    has_any_balance: boolean;
    unusual_balance: boolean;
    status: 'ok' | 'warning';
    alert: string;
};
type TrialBalanceData = {
    mode: 'official' | 'analytical';
    from: string | null;
    to: string | null;
    summary: {
        accounts_total: number;
        accounts_analyzed: number;
        accounts_with_movements: number;
        opening_debits_cents: number;
        opening_credits_cents: number;
        opening_difference_cents: number;
        period_debits_cents: number;
        period_credits_cents: number;
        movement_difference_cents: number;
        closing_debits_cents: number;
        closing_credits_cents: number;
        closing_difference_cents: number;
        pending_entries: number;
        unusual_accounts: number;
    };
    integrity: {
        opening_balanced: boolean;
        movement_balanced: boolean;
        closing_balanced: boolean;
        journal_consistent: boolean;
        ledger_consistent: boolean;
    };
    rows: TrialBalanceRow[];
};
type AccountingDashboard = {
    period: {
        id: number;
        name: string;
        starts_on: string;
        ends_on: string;
        status: 'open' | 'closed';
        closed_at: string | null;
    } | null;
    summary: {
        posted_entries: number;
        pending_entries: number;
        unsupported_entries: number;
        issues: number;
        close_progress: number;
        total_movements_cents: number;
    };
    trial_balance: {
        debits_cents: number;
        credits_cents: number;
        difference_cents: number;
        balanced: boolean;
    };
    pending: {
        origin: string;
        document: string;
        date: string;
        amount_cents: number;
        description: string;
        status: string;
        problem: string;
        action: string;
    }[];
    checklist: {
        label: string;
        status: 'ok' | 'warning' | 'blocking';
        detail: string;
    }[];
    reconciliations: {
        label: string;
        auxiliary_cents: number | null;
        ledger_cents: number;
        difference_cents: number | null;
        module: string;
    }[];
    recent_activity: {
        entry_id: string;
        number: string;
        description: string;
        status: string;
        date: string;
        actor: string | null;
    }[];
    alerts: {
        label: string;
        detail: string;
        tone: 'success' | 'warning' | 'danger';
    }[];
};
const kinds: Record<string, string> = {
    standard: 'Movimiento ordinario',
    automatic: 'Automático',
    opening: 'Saldos iniciales',
    adjustment: 'Asiento de ajuste',
    reclassification: 'Reclasificación',
    provision: 'Provisión',
    closing: 'Asiento de cierre',
    reversal: 'Reversión',
};
type Props = {
    closingBalances: { id: number; net_cents: number }[];
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
    journalPermissions: {
        create: boolean;
        attachSupport: boolean;
        submit: boolean;
        review: boolean;
        approve: boolean;
        post: boolean;
        reject: boolean;
        reverse: boolean;
        void: boolean;
        import: boolean;
    };
    accounts: Account[];
    periods: Period[];
    journal: Page<Entry>;
    balances: Balance[];
    ledger: Page<Movement> | null;
    ledgerOverview: LedgerOverview;
    ledgerSummary: LedgerSummary | null;
    trialBalance: TrialBalanceData;
    accountingDashboard: AccountingDashboard;
    reopeningRequests: ReopeningRequest[];
    members: { id: number; name: string; permission: string }[];
    journalCreators: { id: number; name: string }[];
    filters: {
        period: number;
        account: number;
        journal_search?: string;
        journal_status?: string;
        journal_kind?: string;
        journal_source?: string;
        journal_support?: string;
        journal_third_party?: string;
        journal_cost_center?: string;
        journal_project?: string;
        journal_created_by?: number;
        journal_from?: string;
        journal_to?: string;
        ledger_search?: string;
        ledger_group?: string;
        ledger_account_mode?: string;
        ledger_nature?: string;
        ledger_third_party?: string;
        ledger_third_party_type?: string;
        ledger_cost_center?: string;
        ledger_project?: string;
        ledger_source?: string;
        ledger_kind?: string;
        ledger_entry_number?: string;
        ledger_document?: string;
        ledger_reference?: string;
        ledger_posted_by?: number;
        ledger_accounts?: string;
        ledger_balance?: string;
        ledger_side?: string;
        ledger_min_amount?: string;
        ledger_max_amount?: string;
        ledger_from?: string;
        ledger_to?: string;
        ledger_sort?: string;
        ledger_direction?: string;
        trial_search?: string;
        trial_group?: string;
        trial_level?: string;
        trial_nature?: string;
        trial_account_mode?: string;
        trial_accounts?: string;
        trial_balance?: string;
        trial_third_party?: string;
        trial_cost_center?: string;
        trial_project?: string;
        trial_source?: string;
        trial_kind?: string;
        trial_view?: string;
        trial_from?: string;
        trial_to?: string;
        entry?: string;
    };
    importPreview?: {
        entries: Record<
            string,
            {
                entry_date: string;
                description: string;
                debit: number;
                credit: number;
                lines: unknown[];
            }
        >;
        errors: string[];
        total_debit: number;
        total_credit: number;
    } | null;
    notice: string | null;
};
const types: Record<string, string> = {
    asset: 'Activo',
    liability: 'Pasivo',
    equity: 'Patrimonio',
    income: 'Ingreso',
    expense: 'Gasto',
};
const statuses = entryStatusLabels;
const today = () => new Date().toLocaleDateString('en-CA');
const entrySources: Record<string, string> = {
    manual: 'Manual',
    fel_document: 'Documento FEL',
    sales_invoice: 'Venta',
    sales_receipt: 'Cobro',
    purchase_document: 'Compra',
    purchase_payment: 'Pago',
    bank_transaction: 'Banco',
    cash_transaction: 'Caja',
    inventory_movement: 'Inventario',
    payroll: 'Nómina',
    fixed_asset: 'Activo fijo',
    project: 'Proyecto',
};
const emptyLine = () => ({
    account_id: '',
    description: '',
    third_party_type: '',
    third_party_id: '',
    cost_center: '',
    project: '',
    debit: '0',
    credit: '0',
});
const sumCents = (value: string) =>
    /^\d{1,10}(\.\d{0,2})?$/.test(value)
        ? Number(value.split('.')[0]) * 100 +
          Number((value.split('.')[1] ?? '').padEnd(2, '0'))
        : 0;

type BookFilter = (values: Partial<Props['filters']>) => void;

function LedgerView({
    uuid,
    accounts,
    periods,
    balances,
    ledger,
    ledgerOverview,
    ledgerSummary,
    filters,
    onFilter,
}: {
    uuid: string;
    accounts: Account[];
    periods: Period[];
    balances: Balance[];
    ledger: Page<Movement> | null;
    ledgerOverview: LedgerOverview;
    ledgerSummary: LedgerSummary | null;
    filters: Props['filters'];
    onFilter: BookFilter;
}) {
    const accountRows = balances;
    const visibleAccounts = accountRows;
    const selectedAccount = accounts.find(
        (account) => account.id === Number(filters.account),
    );
    const selectedBalance = accountRows.find(
        (account) => account.id === Number(filters.account),
    );
    const totalDebit = ledgerOverview.debit_cents;
    const totalCredit = ledgerOverview.credit_cents;
    const ledgerData = ledger?.data ?? [];
    const movements = ledgerData;
    const selectedPeriod = periods.find(
        (period) => period.id === Number(filters.period),
    );
    const from =
        filters.ledger_from ||
        selectedPeriod?.starts_on ||
        periods.at(-1)?.starts_on ||
        today();
    const to =
        filters.ledger_to ||
        selectedPeriod?.ends_on ||
        periods[0]?.ends_on ||
        today();
    const ledgerQuery = {
        period: filters.period,
        account: filters.account,
        ledger_search: filters.ledger_search,
        ledger_group: filters.ledger_group,
        ledger_account_mode: filters.ledger_account_mode,
        ledger_nature: filters.ledger_nature,
        ledger_third_party: filters.ledger_third_party,
        ledger_third_party_type: filters.ledger_third_party_type,
        ledger_cost_center: filters.ledger_cost_center,
        ledger_project: filters.ledger_project,
        ledger_source: filters.ledger_source,
        ledger_kind: filters.ledger_kind,
        ledger_entry_number: filters.ledger_entry_number,
        ledger_document: filters.ledger_document,
        ledger_reference: filters.ledger_reference,
        ledger_posted_by: filters.ledger_posted_by,
        ledger_accounts: filters.ledger_accounts,
        ledger_balance: filters.ledger_balance,
        ledger_side: filters.ledger_side,
        ledger_min_amount: filters.ledger_min_amount,
        ledger_max_amount: filters.ledger_max_amount,
        ledger_from: filters.ledger_from,
        ledger_to: filters.ledger_to,
        ledger_sort: filters.ledger_sort,
        ledger_direction: filters.ledger_direction,
    };
    const applyLedgerFilter = (values: Partial<Props['filters']>) =>
        onFilter({ ...values, account: values.account ?? 0 });

    return (
        <div id="libro-mayor" className="scroll-mt-20 space-y-4">
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {[
                    {
                        label: 'Cuentas con movimiento',
                        value: ledgerOverview.accounts_with_movement,
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
                        label: 'Movimientos',
                        value: ledgerOverview.movement_count,
                        hint: ledgerOverview.balanced
                            ? 'Contabilidad cuadrada'
                            : `Diferencia ${money(Math.abs(totalDebit - totalCredit))}`,
                        icon: NotebookTabs,
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
                        <div className="flex flex-col items-start gap-2">
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block text-base whitespace-nowrap text-[#172033] tabular-nums">
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
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <label className="relative xl:col-span-2">
                        <span className="sr-only">Buscar cuenta</span>
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            defaultValue={filters.ledger_search ?? ''}
                            onBlur={(event) =>
                                applyLedgerFilter({
                                    ledger_search: event.target.value,
                                })
                            }
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    applyLedgerFilter({
                                        ledger_search:
                                            event.currentTarget.value,
                                    });
                                }
                            }}
                            placeholder="Cuenta, asiento, documento..."
                            className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-xs outline-none focus:border-[#8a1630]"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Período
                        <select
                            value={filters.period}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    period: Number(event.target.value),
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.name}{' '}
                                    {period.closed_at
                                        ? '— Cerrado'
                                        : '— Abierto'}
                                </option>
                            ))}
                            <option value={0}>Todos los períodos</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Grupo contable
                        <select
                            value={filters.ledger_group || 'all'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_group:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
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
                        Cuentas
                        <select
                            value={filters.ledger_accounts || 'with'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_accounts: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="with">Con movimiento</option>
                            <option value="without">Sin movimiento</option>
                            <option value="all">Todas</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Tipo cuenta
                        <select
                            value={filters.ledger_account_mode || 'all'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_account_mode:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todas</option>
                            <option value="movement">Movimiento</option>
                            <option value="group">Grupo</option>
                            <option value="auxiliary">Auxiliar</option>
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
                                    ...ledgerQuery,
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
                <div className="mt-3 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <label className="text-[10px] font-medium text-slate-500">
                        Desde
                        <input
                            type="date"
                            value={filters.ledger_from ?? ''}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_from: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Hasta
                        <input
                            type="date"
                            value={filters.ledger_to ?? ''}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_to: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Naturaleza
                        <select
                            value={filters.ledger_nature || 'all'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_nature:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todas</option>
                            <option value="debit">Deudora</option>
                            <option value="credit">Acreedora</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Tipo asiento
                        <select
                            value={filters.ledger_kind || 'all'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_kind:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(kinds).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Origen
                        <select
                            value={filters.ledger_source || 'all'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_source:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(entrySources).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Movimiento
                        <select
                            value={filters.ledger_side || 'both'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_side:
                                        event.target.value === 'both'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="both">Debe y haber</option>
                            <option value="debit">Solo debe</option>
                            <option value="credit">Solo haber</option>
                        </select>
                    </label>
                </div>
                <div className="mt-3 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {[
                        ['ledger_third_party', 'Tercero'],
                        ['ledger_cost_center', 'Centro costo'],
                        ['ledger_project', 'Proyecto'],
                        ['ledger_document', 'Documento'],
                        ['ledger_reference', 'Referencia'],
                        ['ledger_entry_number', 'No. asiento'],
                    ].map(([key, label]) => (
                        <label
                            key={key}
                            className="text-[10px] font-medium text-slate-500"
                        >
                            {label}
                            <input
                                defaultValue={String(
                                    filters[key as keyof Props['filters']] ??
                                        '',
                                )}
                                onBlur={(event) =>
                                    applyLedgerFilter({
                                        [key]: event.target.value,
                                    } as Partial<Props['filters']>)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        applyLedgerFilter({
                                            [key]: event.currentTarget.value,
                                        } as Partial<Props['filters']>);
                                    }
                                }}
                                className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                            />
                        </label>
                    ))}
                </div>
                <div className="mt-3 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <label className="text-[10px] font-medium text-slate-500">
                        Saldo
                        <select
                            value={filters.ledger_balance || 'all'}
                            onChange={(event) =>
                                applyLedgerFilter({
                                    ledger_balance:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            <option value="nonzero">Con saldo</option>
                            <option value="zero">Saldo cero</option>
                            <option value="unusual">Saldo inusual</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Importe mín.
                        <input
                            defaultValue={filters.ledger_min_amount ?? ''}
                            inputMode="decimal"
                            onBlur={(event) =>
                                applyLedgerFilter({
                                    ledger_min_amount: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Importe máx.
                        <input
                            defaultValue={filters.ledger_max_amount ?? ''}
                            inputMode="decimal"
                            onBlur={(event) =>
                                applyLedgerFilter({
                                    ledger_max_amount: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Ordenar por
                        <select
                            value={filters.ledger_sort || 'date'}
                            onChange={(event) =>
                                onFilter({ ledger_sort: event.target.value })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="date">Fecha</option>
                            <option value="entry">Asiento</option>
                            <option value="amount">Importe</option>
                            <option value="debit">Debe</option>
                            <option value="credit">Haber</option>
                            <option value="third_party">Tercero</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Dirección
                        <select
                            value={filters.ledger_direction || 'asc'}
                            onChange={(event) =>
                                onFilter({
                                    ledger_direction: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="asc">Ascendente</option>
                            <option value="desc">Descendente</option>
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={() =>
                                onFilter({
                                    ledger_search: '',
                                    ledger_group: '',
                                    ledger_account_mode: '',
                                    ledger_nature: '',
                                    ledger_third_party: '',
                                    ledger_third_party_type: '',
                                    ledger_cost_center: '',
                                    ledger_project: '',
                                    ledger_source: '',
                                    ledger_kind: '',
                                    ledger_entry_number: '',
                                    ledger_document: '',
                                    ledger_reference: '',
                                    ledger_posted_by: 0,
                                    ledger_accounts: 'with',
                                    ledger_balance: '',
                                    ledger_side: '',
                                    ledger_min_amount: '',
                                    ledger_max_amount: '',
                                    ledger_from: '',
                                    ledger_to: '',
                                    ledger_sort: 'date',
                                    ledger_direction: 'asc',
                                    account: 0,
                                })
                            }
                            className={secondary}
                        >
                            <RefreshCw size={15} /> Limpiar
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
                                    {selectedPeriod?.name ??
                                        'Todos los períodos'}
                                </span>
                            </div>
                            <div className="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 xl:grid-cols-5">
                                {[
                                    {
                                        label: 'Saldo inicial',
                                        value: money(
                                            Math.abs(
                                                ledgerSummary?.opening_balance_cents ??
                                                    0,
                                            ),
                                        ),
                                        tone: 'text-[#243d78]',
                                    },
                                    {
                                        label: 'Débitos del período',
                                        value: money(
                                            ledgerSummary?.debit_cents ??
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
                                            ledgerSummary?.credit_cents ??
                                                Number(
                                                    selectedBalance?.credit_cents ??
                                                        0,
                                                ),
                                        ),
                                        tone: 'text-rose-700',
                                    },
                                    {
                                        label: 'Saldo final',
                                        value: money(
                                            Math.abs(
                                                ledgerSummary?.closing_balance_cents ??
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
                                    {
                                        label: 'Movimientos',
                                        value:
                                            ledgerSummary?.movement_count ??
                                            Number(
                                                selectedBalance?.movement_count ??
                                                    0,
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
                                            Esta cuenta no posee movimientos
                                            contabilizados
                                            {selectedPeriod
                                                ? ` durante ${selectedPeriod.name}.`
                                                : ' en el rango seleccionado.'}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[980px] text-xs">
                                        <thead className="bg-[#18294a] text-left text-white">
                                            <tr>
                                                <th className="px-3 py-2">
                                                    Fecha
                                                </th>
                                                <th className="px-3 py-2">
                                                    Asiento
                                                </th>
                                                <th className="px-3 py-2">
                                                    Documento
                                                </th>
                                                <th className="px-3 py-2">
                                                    Concepto
                                                </th>
                                                <th className="px-3 py-2">
                                                    Origen
                                                </th>
                                                <th className="px-3 py-2">
                                                    Tercero
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
                                                        key={`${movement.entry_public_id}-${index}`}
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
                                                                movement.number ??
                                                                    0,
                                                            ).padStart(6, '0')}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {movement.document_name ??
                                                                movement.reference ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {movement.line_description ??
                                                                movement.description}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {entrySources[
                                                                movement.source_type ??
                                                                    'manual'
                                                            ] ??
                                                                kinds[
                                                                    movement
                                                                        .kind
                                                                ] ??
                                                                'Manual'}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {movement.third_party_type
                                                                ? `${movement.third_party_type} #${movement.third_party_id ?? ''}`
                                                                : '—'}
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
                                                                    Number(
                                                                        movement.running_balance_cents,
                                                                    ),
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
    trialBalance,
    filters,
    onFilter,
    onViewLedger,
}: {
    uuid: string;
    periods: Period[];
    trialBalance: TrialBalanceData;
    filters: Props['filters'];
    onFilter: BookFilter;
    onViewLedger: (accountId: number) => void;
}) {
    const rows = trialBalance.rows;
    const summary = trialBalance.summary;
    const selectedPeriod = periods.find(
        (period) => period.id === Number(filters.period),
    );
    const from =
        filters.trial_from ||
        selectedPeriod?.starts_on ||
        periods.at(-1)?.starts_on ||
        today();
    const to =
        filters.trial_to ||
        selectedPeriod?.ends_on ||
        periods[0]?.ends_on ||
        today();
    const movementSquared = summary.movement_difference_cents === 0;
    const closingSquared = summary.closing_difference_cents === 0;
    const official = trialBalance.mode === 'official';
    const completeView = (filters.trial_view || 'compact') === 'complete';
    const applyTrialFilter = (values: Partial<Props['filters']>) =>
        onFilter({ ...values, account: 0 });
    const exportQuery = {
        kind: 'trial',
        from,
        to,
        trial_search: filters.trial_search,
        trial_group: filters.trial_group,
        trial_level: filters.trial_level,
        trial_nature: filters.trial_nature,
        trial_account_mode: filters.trial_account_mode,
        trial_accounts: filters.trial_accounts,
        trial_balance: filters.trial_balance,
        trial_third_party: filters.trial_third_party,
        trial_cost_center: filters.trial_cost_center,
        trial_project: filters.trial_project,
        trial_source: filters.trial_source,
        trial_kind: filters.trial_kind,
        trial_view: filters.trial_view,
    };

    return (
        <div id="balance-comprobacion" className="scroll-mt-20 space-y-4">
            <div className="flex flex-wrap justify-end gap-2">
                {(['xlsx', 'pdf'] as const).map((format) => (
                    <a
                        key={format}
                        href={routes.exportMethod.url(uuid, {
                            query: {
                                ...exportQuery,
                                format,
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
                        value: summary.accounts_with_movements,
                        hint: `de ${summary.accounts_total} cuentas`,
                        icon: BookOpen,
                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                    },
                    {
                        label: 'Total débitos',
                        value: money(summary.period_debits_cents),
                        hint: 'En el período',
                        icon: ArrowDownLeft,
                        tone: 'bg-[#e7f5ec] text-[#3b9b68]',
                    },
                    {
                        label: 'Total créditos',
                        value: money(summary.period_credits_cents),
                        hint: 'En el período',
                        icon: ArrowUpRight,
                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                    },
                    {
                        label: 'Cuadratura',
                        value: money(
                            Math.abs(summary.movement_difference_cents),
                        ),
                        hint: official
                            ? 'Movimientos del período'
                            : 'Vista analítica filtrada',
                        icon: Scale,
                        tone: movementSquared
                            ? 'bg-[#e9f0fb] text-[#315fa8]'
                            : 'bg-[#fff0e7] text-[#d2632d]',
                    },
                    {
                        label: 'Estado',
                        value:
                            official && movementSquared && closingSquared
                                ? 'Cuadrado'
                                : official
                                  ? 'Por revisar'
                                  : 'Analítico',
                        hint: `${summary.unusual_accounts} incidencias`,
                        icon: Clock3,
                        tone:
                            official && movementSquared && closingSquared
                                ? 'bg-[#e7f5ec] text-[#218451]'
                                : 'bg-[#fff0e7] text-[#d2632d]',
                    },
                ].map((metric) => (
                    <article
                        key={metric.label}
                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]"
                    >
                        <div className="flex flex-col items-start gap-2">
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block text-base whitespace-nowrap text-[#172033] tabular-nums">
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
                        label: movementSquared
                            ? 'Débitos y créditos coinciden'
                            : official
                              ? 'Cuadratura contable inválida'
                              : 'Vista analítica filtrada',
                        detail: official
                            ? `Diferencia ${money(Math.abs(summary.movement_difference_cents))}`
                            : 'El subconjunto filtrado no necesariamente cuadra',
                        icon: Check,
                        tone:
                            movementSquared || !official
                                ? 'text-emerald-600'
                                : 'text-rose-600',
                    },
                    {
                        label: `${summary.pending_entries} asientos pendientes`,
                        detail: 'No afectan el Balance oficial',
                        icon: Clock3,
                        tone: 'text-amber-500',
                    },
                    {
                        label: `${summary.accounts_analyzed} cuentas analizadas`,
                        detail: selectedPeriod?.closed_at
                            ? `${selectedPeriod.name} · Cerrado`
                            : (selectedPeriod?.name ?? 'Todos los períodos'),
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

            <section className="grid gap-3 md:grid-cols-3">
                {[
                    {
                        label: 'Saldos iniciales',
                        debit: summary.opening_debits_cents,
                        credit: summary.opening_credits_cents,
                        diff: summary.opening_difference_cents,
                        ok: trialBalance.integrity.opening_balanced,
                    },
                    {
                        label: 'Movimientos',
                        debit: summary.period_debits_cents,
                        credit: summary.period_credits_cents,
                        diff: summary.movement_difference_cents,
                        ok: trialBalance.integrity.movement_balanced,
                    },
                    {
                        label: 'Saldos finales',
                        debit: summary.closing_debits_cents,
                        credit: summary.closing_credits_cents,
                        diff: summary.closing_difference_cents,
                        ok: trialBalance.integrity.closing_balanced,
                    },
                ].map((check) => (
                    <article
                        key={check.label}
                        className="rounded-2xl border border-[#e8e4e2] bg-white p-4 text-xs shadow-[0_7px_18px_rgba(31,38,55,.05)]"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <strong>{check.label}</strong>
                            <span
                                className={`rounded-full px-2 py-1 text-[10px] font-semibold ${check.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}
                            >
                                {check.ok ? 'Cuadrado' : 'Diferencia'}
                            </span>
                        </div>
                        <div className="mt-3 grid grid-cols-3 gap-2 text-[10px] text-slate-500">
                            <span>Debe {money(check.debit)}</span>
                            <span>Haber {money(check.credit)}</span>
                            <span>Dif. {money(Math.abs(check.diff))}</span>
                        </div>
                    </article>
                ))}
            </section>

            <section className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_7px_18px_rgba(31,38,55,.05)]">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <label className="relative xl:col-span-2">
                        <span className="sr-only">Buscar cuenta</span>
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            defaultValue={filters.trial_search ?? ''}
                            onBlur={(event) =>
                                applyTrialFilter({
                                    trial_search: event.target.value,
                                })
                            }
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    applyTrialFilter({
                                        trial_search: event.currentTarget.value,
                                    });
                                }
                            }}
                            placeholder="Buscar por código o nombre..."
                            className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-xs outline-none focus:border-[#8a1630]"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Período
                        <select
                            value={filters.period}
                            onChange={(event) =>
                                applyTrialFilter({
                                    period: Number(event.target.value),
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.name}{' '}
                                    {period.closed_at
                                        ? '— Cerrado'
                                        : '— Abierto'}
                                </option>
                            ))}
                            <option value={0}>Todos los períodos</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Grupo contable
                        <select
                            value={filters.trial_group || 'all'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_group:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
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
                        Vista
                        <select
                            value={filters.trial_view || 'compact'}
                            onChange={(event) =>
                                onFilter({ trial_view: event.target.value })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="compact">Compacta</option>
                            <option value="complete">Completa</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Cuentas
                        <select
                            value={filters.trial_accounts || 'with'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_accounts: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="with">Con movimiento</option>
                            <option value="without">Sin movimiento</option>
                            <option value="all">Todas</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Saldo
                        <select
                            value={filters.trial_balance || 'all'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_balance:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            <option value="nonzero">Con saldo</option>
                            <option value="zero">Saldo cero</option>
                            <option value="unusual">Inusual</option>
                        </select>
                    </label>
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <label className="text-[10px] font-medium text-slate-500">
                        Desde
                        <input
                            type="date"
                            value={filters.trial_from ?? ''}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_from: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Hasta
                        <input
                            type="date"
                            value={filters.trial_to ?? ''}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_to: event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        />
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Naturaleza
                        <select
                            value={filters.trial_nature || 'all'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_nature:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todas</option>
                            <option value="debit">Deudora</option>
                            <option value="credit">Acreedora</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Nivel
                        <select
                            value={filters.trial_level || 'all'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_level:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            <option value="1">Nivel 1</option>
                            <option value="2">Nivel 2</option>
                            <option value="3">Nivel 3</option>
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Origen
                        <select
                            value={filters.trial_source || 'all'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_source:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(entrySources).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className="text-[10px] font-medium text-slate-500">
                        Tipo asiento
                        <select
                            value={filters.trial_kind || 'all'}
                            onChange={(event) =>
                                applyTrialFilter({
                                    trial_kind:
                                        event.target.value === 'all'
                                            ? ''
                                            : event.target.value,
                                })
                            }
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(kinds).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        ['trial_third_party', 'Tercero'],
                        ['trial_cost_center', 'Centro de costo'],
                        ['trial_project', 'Proyecto'],
                    ].map(([key, label]) => (
                        <label
                            key={key}
                            className="text-[10px] font-medium text-slate-500"
                        >
                            {label}
                            <input
                                defaultValue={String(
                                    filters[key as keyof Props['filters']] ??
                                        '',
                                )}
                                onBlur={(event) =>
                                    applyTrialFilter({
                                        [key]: event.target.value,
                                    } as Partial<Props['filters']>)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        applyTrialFilter({
                                            [key]: event.currentTarget.value,
                                        } as Partial<Props['filters']>);
                                    }
                                }}
                                className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                            />
                        </label>
                    ))}
                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={() =>
                                onFilter({
                                    trial_search: '',
                                    trial_group: '',
                                    trial_level: '',
                                    trial_nature: '',
                                    trial_account_mode: '',
                                    trial_accounts: 'with',
                                    trial_balance: '',
                                    trial_third_party: '',
                                    trial_cost_center: '',
                                    trial_project: '',
                                    trial_source: '',
                                    trial_kind: '',
                                    trial_view: 'compact',
                                    trial_from: '',
                                    trial_to: '',
                                    account: 0,
                                })
                            }
                            className={secondary}
                        >
                            <RefreshCw size={15} /> Limpiar
                        </button>
                    </div>
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
                                    {completeView && (
                                        <>
                                            <th className="px-4 py-3 text-right">
                                                SI debe
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                SI haber
                                            </th>
                                        </>
                                    )}
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
                                    <th className="px-4 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((account) => (
                                    <tr
                                        key={account.account_id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onViewLedger(
                                                        account.account_id,
                                                    )
                                                }
                                                className="text-[#7d0914] hover:underline"
                                            >
                                                {account.code}
                                            </button>
                                        </td>
                                        <td className="px-4 py-3">
                                            {account.name}
                                        </td>
                                        <td className="px-4 py-3 text-slate-500">
                                            {types[account.type]}
                                        </td>
                                        {completeView && (
                                            <>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {account.opening_debit_cents
                                                        ? money(
                                                              account.opening_debit_cents,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {account.opening_credit_cents
                                                        ? money(
                                                              account.opening_credit_cents,
                                                          )
                                                        : '—'}
                                                </td>
                                            </>
                                        )}
                                        <td className="px-4 py-3 text-right text-emerald-700 tabular-nums">
                                            {money(account.period_debit_cents)}
                                        </td>
                                        <td className="px-4 py-3 text-right text-rose-700 tabular-nums">
                                            {money(account.period_credit_cents)}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium tabular-nums">
                                            {account.closing_debit_cents
                                                ? money(
                                                      account.closing_debit_cents,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium tabular-nums">
                                            {account.closing_credit_cents
                                                ? money(
                                                      account.closing_credit_cents,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2 py-1 text-[10px] font-semibold ${account.unusual_balance ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}
                                            >
                                                {account.alert}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t-2 border-slate-200 bg-[#faf8f7] font-semibold">
                                <tr>
                                    <td colSpan={3} className="px-4 py-3">
                                        Totales generales
                                    </td>
                                    {completeView && (
                                        <>
                                            <td className="px-4 py-3 text-right">
                                                {money(
                                                    summary.opening_debits_cents,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {money(
                                                    summary.opening_credits_cents,
                                                )}
                                            </td>
                                        </>
                                    )}
                                    <td className="px-4 py-3 text-right text-emerald-700">
                                        {money(summary.period_debits_cents)}
                                    </td>
                                    <td className="px-4 py-3 text-right text-rose-700">
                                        {money(summary.period_credits_cents)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {money(summary.closing_debits_cents)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {money(summary.closing_credits_cents)}
                                    </td>
                                    <td className="px-4 py-3" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
                <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-[10px] text-slate-500">
                    <span>{rows.length} cuentas mostradas</span>
                    <span
                        className={
                            movementSquared && closingSquared
                                ? 'text-emerald-700'
                                : 'text-amber-700'
                        }
                    >
                        {official
                            ? movementSquared && closingSquared
                                ? 'Balance oficial cuadrado'
                                : 'Balance oficial por revisar'
                            : 'Vista analítica filtrada'}
                    </span>
                </div>
            </section>
        </div>
    );
}

const chartGroups: Record<
    string,
    { label: string; folder: string; badge: string }
> = {
    asset: {
        label: 'Activo',
        folder: 'fill-[#f6d5d8] text-[#b0233a]',
        badge: 'bg-[#f8e3e5] text-[#9a1c30]',
    },
    liability: {
        label: 'Pasivo',
        folder: 'fill-[#d9e6f8] text-[#3567b3]',
        badge: 'bg-[#e6eefa] text-[#2f5ea6]',
    },
    equity: {
        label: 'Patrimonio',
        folder: 'fill-[#d6efdf] text-[#23864f]',
        badge: 'bg-[#e3f4e9] text-[#1f7a48]',
    },
    income: {
        label: 'Ingresos',
        folder: 'fill-[#fbe8c4] text-[#c98a12]',
        badge: 'bg-[#fdf1d8] text-[#9a6a0c]',
    },
    expense: {
        label: 'Costos y gastos',
        folder: 'fill-[#fde0cf] text-[#d2632d]',
        badge: 'bg-[#fdebe0] text-[#b4531f]',
    },
};

/** Second level of the Guatemalan chart, from the first two digits of the code. */
const chartSubgroups: Record<string, string> = {
    '11': 'Disponible y exigible',
    '12': 'Inventarios',
    '13': 'Impuestos y pagos anticipados',
    '14': 'Propiedad, planta y equipo',
    '15': 'Otros activos',
    '21': 'Cuentas por pagar',
    '22': 'Impuestos y obligaciones laborales',
    '23': 'Préstamos',
    '24': 'Otros pasivos',
    '31': 'Capital',
    '32': 'Resultados',
    '33': 'Superávit',
    '41': 'Ingresos por servicios',
    '42': 'Ventas',
    '43': 'Otros ingresos',
    '51': 'Costos',
    '52': 'Gastos de administración',
    '53': 'Otros gastos y gastos financieros',
    '54': 'Gastos de venta',
};

const subgroupOf = (code: string) => {
    const prefix = code.slice(0, 2);

    return {
        key: prefix,
        label: `${prefix.padEnd(4, '0')} · ${chartSubgroups[prefix] ?? 'Otras cuentas'}`,
    };
};

function ChartOfAccountsView({
    uuid,
    accounts,
    periods,
    balances,
    closingBalances,
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
    closingBalances: Props['closingBalances'];
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
    const [menuOpen, setMenuOpen] = useState(false);
    const selectedAccount = accounts.find(
        (account) => account.id === Number(filters.account),
    );
    const [expanded, setExpanded] = useState<Set<string>>(() => {
        const start = selectedAccount ?? accounts[0];

        return new Set(
            start
                ? [start.type, `${start.type}:${subgroupOf(start.code).key}`]
                : [],
        );
    });
    const normalizedSearch = search.trim().toLocaleLowerCase();
    const searching = normalizedSearch !== '';
    const visibleAccounts = accounts.filter(
        (account) =>
            (group === 'all' || account.type === group) &&
            (!searching ||
                `${account.code} ${account.name}`
                    .toLocaleLowerCase()
                    .includes(normalizedSearch)),
    );
    const balanceOf = (id: number) => balances.find((row) => row.id === id);
    const closing = new Map(
        closingBalances.map((row) => [row.id, Number(row.net_cents)]),
    );
    const closingOf = (account: Account) => {
        const net = closing.get(account.id) ?? 0;

        return ['asset', 'expense'].includes(account.type) ? net : -net;
    };
    const selectedBalance = selectedAccount
        ? balanceOf(selectedAccount.id)
        : undefined;
    const accountsWithMovement = new Set(balances.map((account) => account.id));
    const operative = accounts.filter(
        (account) =>
            account.is_active !== false && account.accepts_movements !== false,
    ).length;
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
    const isOpen = (key: string) => searching || expanded.has(key);
    const toggle = (key: string) =>
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
    const tree = Object.keys(chartGroups)
        .map((type) => {
            const groupAccounts = visibleAccounts
                .filter((account) => account.type === type)
                .sort((a, b) => a.code.localeCompare(b.code));
            const subgroups = new Map<
                string,
                { label: string; accounts: Account[] }
            >();

            for (const account of groupAccounts) {
                const subgroup = subgroupOf(account.code);
                const entry = subgroups.get(subgroup.key) ?? {
                    label: subgroup.label,
                    accounts: [],
                };
                entry.accounts.push(account);
                subgroups.set(subgroup.key, entry);
            }

            return { type, count: groupAccounts.length, subgroups };
        })
        .filter((node) => node.count > 0);
    const selectedSubgroup = selectedAccount
        ? subgroupOf(selectedAccount.code).label
        : '';
    const card =
        'rounded-2xl border border-[#ece7e5] bg-white shadow-[0_6px_18px_rgba(31,38,55,.04)]';

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

            <section
                className={`${card} grid divide-y divide-slate-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0`}
            >
                {[
                    {
                        label: 'Total de cuentas',
                        value: accounts.length,
                        hint: 'En el catálogo actual',
                        icon: BookOpen,
                        tone: 'bg-[#eee9fb] text-[#6754b7]',
                    },
                    {
                        label: 'Cuentas operativas',
                        value: operative,
                        hint: 'Disponibles para registro',
                        icon: Check,
                        tone: 'bg-[#e7f5ec] text-[#218451]',
                    },
                    {
                        label: 'Cuentas con movimiento',
                        value: accountsWithMovement.size,
                        hint: 'Con asientos contabilizados',
                        icon: FileChartColumn,
                        tone: 'bg-[#e9f0fb] text-[#315fa8]',
                    },
                ].map((metric) => (
                    <div
                        key={metric.label}
                        className="flex items-center gap-4 px-6 py-5"
                    >
                        <span
                            className={`grid size-14 shrink-0 place-items-center rounded-full ${metric.tone}`}
                        >
                            <metric.icon size={24} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-xs text-slate-500">
                                {metric.label}
                            </p>
                            <strong className="mt-0.5 block text-2xl font-bold text-[#172033] tabular-nums">
                                {metric.value}
                            </strong>
                            <p className="text-[11px] text-slate-400">
                                {metric.hint}
                            </p>
                        </div>
                    </div>
                ))}
            </section>

            <section className={`${card} p-4`}>
                <div className="grid gap-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] md:items-end">
                    <label className="relative">
                        <span className="sr-only">Buscar cuenta</span>
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por código o nombre de cuenta..."
                            className="h-10 w-full rounded-xl border border-slate-200 bg-[#fbfaf9] pr-3 pl-9 text-sm outline-none focus:border-[#8a1630]"
                        />
                    </label>
                    <label className="text-xs font-medium text-slate-500">
                        Grupo contable
                        <select
                            value={group}
                            onChange={(event) => setGroup(event.target.value)}
                            className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800"
                        >
                            <option value="all">Todos</option>
                            {Object.entries(chartGroups).map(
                                ([value, meta]) => (
                                    <option key={value} value={value}>
                                        {meta.label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className="text-xs font-medium text-slate-500">
                        Período de saldos
                        <span className="relative mt-1 block">
                            <CalendarDays className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <select
                                value={filters.period}
                                onChange={(event) =>
                                    onFilter({
                                        period: Number(event.target.value),
                                    })
                                }
                                className="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-sm text-slate-800"
                            >
                                <option value={0}>Todos los períodos</option>
                                {periods.map((period) => (
                                    <option key={period.id} value={period.id}>
                                        {period.name}
                                    </option>
                                ))}
                            </select>
                        </span>
                    </label>
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-[minmax(320px,.85fr)_minmax(0,1.6fr)]">
                <aside className={`${card} overflow-hidden`}>
                    <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div className="flex items-start gap-3">
                            <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#fbf4f4] text-[#7d0914]">
                                <ListTree size={18} />
                            </span>
                            <div>
                                <h2 className="text-sm font-semibold text-[#172033]">
                                    Árbol de cuentas contables
                                </h2>
                                <p className="mt-0.5 text-xs text-slate-400">
                                    Organizado por clasificación contable.
                                </p>
                            </div>
                        </div>
                        <div className="relative">
                            <button
                                type="button"
                                aria-label="Opciones del árbol"
                                onClick={() => setMenuOpen((open) => !open)}
                                className="grid size-9 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"
                            >
                                <MoreHorizontal size={17} />
                            </button>
                            {menuOpen && (
                                <div className="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-lg">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setExpanded(
                                                new Set(
                                                    tree.flatMap((node) => [
                                                        node.type,
                                                        ...[
                                                            ...node.subgroups.keys(),
                                                        ].map(
                                                            (key) =>
                                                                `${node.type}:${key}`,
                                                        ),
                                                    ]),
                                                ),
                                            );
                                            setMenuOpen(false);
                                        }}
                                        className="block w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50"
                                    >
                                        Expandir todo
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setExpanded(new Set());
                                            setMenuOpen(false);
                                        }}
                                        className="block w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50"
                                    >
                                        Contraer todo
                                    </button>
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setMenuOpen(false);
                                                onInstall();
                                            }}
                                            className="block w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50"
                                        >
                                            Aplicar plantilla de cuentas
                                            <span className="block text-[11px] text-slate-400">
                                                Agrega las que falten, sin
                                                duplicar códigos
                                            </span>
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="max-h-[640px] overflow-y-auto p-2">
                        {tree.map((node) => {
                            const meta = chartGroups[node.type];
                            const groupOpen = isOpen(node.type);

                            return (
                                <div key={node.type} className="mb-0.5">
                                    <button
                                        type="button"
                                        onClick={() => toggle(node.type)}
                                        className={`flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 text-left transition ${groupOpen ? 'bg-[#fbf2f2]' : 'hover:bg-[#faf8f7]'}`}
                                    >
                                        <ChevronRight
                                            size={15}
                                            className={`shrink-0 text-slate-400 transition-transform ${groupOpen ? 'rotate-90' : ''}`}
                                        />
                                        <Folder
                                            size={18}
                                            className={`shrink-0 ${meta.folder}`}
                                        />
                                        <span className="flex-1 text-sm font-semibold tracking-wide text-[#172033] uppercase">
                                            {meta.label}
                                        </span>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ${meta.badge}`}
                                        >
                                            {node.count}
                                        </span>
                                    </button>
                                    {groupOpen && (
                                        <div className="ml-4 border-l border-slate-200 pl-2">
                                            {[...node.subgroups.entries()].map(
                                                ([key, subgroup]) => {
                                                    const subgroupKey = `${node.type}:${key}`;
                                                    const subgroupOpen =
                                                        isOpen(subgroupKey);

                                                    return (
                                                        <div key={subgroupKey}>
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    toggle(
                                                                        subgroupKey,
                                                                    )
                                                                }
                                                                className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left hover:bg-[#faf8f7]"
                                                            >
                                                                <ChevronRight
                                                                    size={14}
                                                                    className={`shrink-0 text-slate-400 transition-transform ${subgroupOpen ? 'rotate-90' : ''}`}
                                                                />
                                                                <Folder
                                                                    size={16}
                                                                    className={`shrink-0 ${meta.folder}`}
                                                                />
                                                                <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-slate-700">
                                                                    {
                                                                        subgroup.label
                                                                    }
                                                                </span>
                                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 tabular-nums">
                                                                    {
                                                                        subgroup
                                                                            .accounts
                                                                            .length
                                                                    }
                                                                </span>
                                                            </button>
                                                            {subgroupOpen && (
                                                                <div className="ml-4 border-l border-dashed border-slate-200 pl-2">
                                                                    {subgroup.accounts.map(
                                                                        (
                                                                            account,
                                                                        ) => {
                                                                            const active =
                                                                                Number(
                                                                                    filters.account,
                                                                                ) ===
                                                                                account.id;
                                                                            const balance =
                                                                                closingOf(
                                                                                    account,
                                                                                );

                                                                            return (
                                                                                <button
                                                                                    key={
                                                                                        account.id
                                                                                    }
                                                                                    type="button"
                                                                                    onClick={() =>
                                                                                        onFilter(
                                                                                            {
                                                                                                account:
                                                                                                    account.id,
                                                                                            },
                                                                                        )
                                                                                    }
                                                                                    className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition ${active ? 'bg-[#f8e3e5] text-[#7d0914]' : 'text-slate-700 hover:bg-[#faf8f7]'}`}
                                                                                >
                                                                                    <FileText
                                                                                        size={
                                                                                            14
                                                                                        }
                                                                                        className={`shrink-0 ${active ? 'text-[#9a1c30]' : 'text-slate-400'}`}
                                                                                    />
                                                                                    <span className="min-w-0 flex-1 truncate text-[13px]">
                                                                                        <span
                                                                                            className={
                                                                                                active
                                                                                                    ? 'font-semibold'
                                                                                                    : 'font-medium text-slate-900'
                                                                                            }
                                                                                        >
                                                                                            {
                                                                                                account.code
                                                                                            }
                                                                                        </span>{' '}
                                                                                        ·{' '}
                                                                                        {
                                                                                            account.name
                                                                                        }
                                                                                    </span>
                                                                                    <span
                                                                                        className={`shrink-0 text-xs whitespace-nowrap tabular-nums ${balance === 0 ? 'text-slate-400' : 'font-semibold'}`}
                                                                                    >
                                                                                        {money(
                                                                                            balance,
                                                                                        )}
                                                                                    </span>
                                                                                </button>
                                                                            );
                                                                        },
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                },
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                        {!tree.length && (
                            <p className="p-6 text-center text-sm text-slate-500">
                                No hay cuentas con estos filtros.
                            </p>
                        )}
                    </div>
                </aside>

                <div className={`${card} overflow-hidden`}>
                    {selectedAccount ? (
                        <div className="space-y-4 p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-2xl font-bold tracking-tight text-[#172033]">
                                        {selectedAccount.code} ·{' '}
                                        {selectedAccount.name}
                                    </h2>
                                    <div className="mt-2 flex flex-wrap gap-2 text-[11px] font-semibold">
                                        <span className="rounded-full bg-[#eee9fb] px-2.5 py-1 text-[#6754b7]">
                                            Auxiliar
                                        </span>
                                        <span className="rounded-full bg-[#f8e3e5] px-2.5 py-1 text-[#9a1c30]">
                                            {selectedAccount.is_active === false
                                                ? 'Inactiva'
                                                : 'Operativa'}
                                        </span>
                                        <span className="rounded-full bg-[#e6eefa] px-2.5 py-1 text-[#2f5ea6]">
                                            {selectedAccount.accepts_movements ===
                                            false
                                                ? 'Sin movimientos'
                                                : 'Permite movimientos'}
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

                            <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                                {[
                                    {
                                        label: selectedPeriod
                                            ? `Saldo al ${shortDate(selectedPeriod.ends_on)}`
                                            : 'Saldo actual',
                                        value: money(
                                            closingOf(selectedAccount),
                                        ),
                                        icon: Wallet,
                                        tone: 'bg-[#eee9fb] text-[#6754b7]',
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
                                        tone: 'bg-[#e7f5ec] text-[#218451]',
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
                                        tone: 'bg-[#fce9eb] text-[#ba3246]',
                                    },
                                    {
                                        label: 'Movimientos',
                                        value: String(
                                            selectedBalance?.movement_count ??
                                                ledger?.data.length ??
                                                0,
                                        ),
                                        icon: NotebookTabs,
                                        tone: 'bg-[#e9f0fb] text-[#315fa8]',
                                    },
                                ].map((summary) => (
                                    <div
                                        key={summary.label}
                                        className="flex items-center gap-3 rounded-xl border border-slate-200 p-3"
                                    >
                                        <span
                                            className={`grid size-10 shrink-0 place-items-center rounded-full ${summary.tone}`}
                                        >
                                            <summary.icon size={18} />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-[11px] text-slate-500">
                                                {summary.label}
                                            </p>
                                            <strong className="block text-base whitespace-nowrap text-[#172033] tabular-nums">
                                                {summary.value}
                                            </strong>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="rounded-xl border border-slate-200 p-4">
                                <h3 className="flex items-center gap-2 text-sm font-semibold text-[#172033]">
                                    <Info
                                        size={16}
                                        className="text-slate-500"
                                    />
                                    Información general
                                </h3>
                                <div className="mt-3 grid gap-x-8 gap-y-2 text-[13px] md:grid-cols-2">
                                    {[
                                        ['Código', selectedAccount.code],
                                        ['Nombre', selectedAccount.name],
                                        ['Grupo contable', selectedSubgroup],
                                        ['Tipo de cuenta', 'Auxiliar'],
                                        [
                                            'Naturaleza de saldo',
                                            ['asset', 'expense'].includes(
                                                selectedAccount.type,
                                            )
                                                ? 'Deudora'
                                                : 'Acreedora',
                                        ],
                                        [
                                            'Clasificación',
                                            chartGroups[selectedAccount.type]
                                                ?.label ?? '',
                                        ],
                                        ['Moneda', 'GTQ'],
                                        [
                                            'Permite movimientos',
                                            selectedAccount.accepts_movements ===
                                            false
                                                ? 'No'
                                                : 'Sí',
                                        ],
                                        ['Es cuenta de mayor', 'No'],
                                        ['Cuenta padre', selectedSubgroup],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="grid grid-cols-[minmax(110px,auto)_minmax(0,1fr)] gap-3"
                                        >
                                            <dt className="text-slate-500">
                                                {label}
                                            </dt>
                                            <dd className="font-medium text-slate-800">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                    <div className="grid grid-cols-[minmax(110px,auto)_minmax(0,1fr)] gap-3">
                                        <dt className="text-slate-500">
                                            Estado
                                        </dt>
                                        <dd>
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ${selectedAccount.is_active === false ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700'}`}
                                            >
                                                <span
                                                    className={`size-1.5 rounded-full ${selectedAccount.is_active === false ? 'bg-slate-400' : 'bg-emerald-500'}`}
                                                />
                                                {selectedAccount.is_active ===
                                                false
                                                    ? 'Inactiva'
                                                    : 'Activa'}
                                            </span>
                                        </dd>
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-xl border border-slate-200">
                                <div className="flex items-center justify-between gap-3 px-4 py-3">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-[#172033]">
                                        <Clock3
                                            size={16}
                                            className="text-slate-500"
                                        />
                                        Movimientos recientes
                                    </h3>
                                    {!!ledger?.data.length && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onViewLedger(selectedAccount.id)
                                            }
                                            className="rounded-lg border border-[#e5dada] px-3 py-1.5 text-xs font-semibold text-[#7d0914] hover:bg-[#fbf4f4]"
                                        >
                                            Ver todos
                                        </button>
                                    )}
                                </div>
                                {!ledger?.data.length ? (
                                    <div className="mx-4 mb-4 rounded-xl border border-dashed border-slate-200 p-7 text-center text-sm text-slate-500">
                                        Esta cuenta todavía no tiene movimientos
                                        contabilizados en el período.
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[520px] text-xs">
                                            <thead className="bg-[#f8f7f6] text-left text-xs text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-2 font-medium">
                                                        Fecha
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Tipo
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Descripción
                                                    </th>
                                                    <th className="px-3 py-2 text-right font-medium">
                                                        Débito (Q)
                                                    </th>
                                                    <th className="px-3 py-2 text-right font-medium">
                                                        Crédito (Q)
                                                    </th>
                                                    <th className="px-3 py-2 text-right font-medium">
                                                        Saldo (Q)
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {ledger.data
                                                    .slice(0, 6)
                                                    .map((movement, index) => {
                                                        const debit =
                                                            Number(
                                                                movement.debit_cents,
                                                            ) > 0;

                                                        return (
                                                            <tr
                                                                key={`${movement.number}-${index}`}
                                                                className="border-t border-slate-100"
                                                            >
                                                                <td className="px-3 py-2.5 whitespace-nowrap">
                                                                    {shortDate(
                                                                        movement.entry_date,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2.5">
                                                                    <span
                                                                        className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${debit ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}
                                                                    >
                                                                        {debit
                                                                            ? 'Cargo'
                                                                            : 'Abono'}
                                                                    </span>
                                                                </td>
                                                                <td className="max-w-[240px] px-3 py-2.5">
                                                                    <span className="block truncate">
                                                                        {movement.line_description ??
                                                                            movement.description}
                                                                    </span>
                                                                    <span className="block text-[11px] text-[#8a1630]">
                                                                        DI-
                                                                        {String(
                                                                            movement.number,
                                                                        ).padStart(
                                                                            6,
                                                                            '0',
                                                                        )}
                                                                    </span>
                                                                </td>
                                                                <td className="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">
                                                                    {money(
                                                                        movement.debit_cents,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">
                                                                    {money(
                                                                        movement.credit_cents,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2.5 text-right font-semibold whitespace-nowrap tabular-nums">
                                                                    {money(
                                                                        movement.running_balance_cents,
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {selectedHistory.length > 0 && (
                                <div className="rounded-xl border border-slate-200 p-4">
                                    <h3 className="text-sm font-semibold text-[#172033]">
                                        Historial de cambios
                                    </h3>
                                    <div className="mt-3 space-y-2">
                                        {selectedHistory
                                            .slice(0, 4)
                                            .map((change) => (
                                                <p
                                                    key={change.id}
                                                    className="text-[13px] text-slate-600"
                                                >
                                                    <strong className="text-slate-800">
                                                        {change.actor ??
                                                            'Usuario'}
                                                    </strong>{' '}
                                                    · {shortDate(change.date)} ·{' '}
                                                    {change.after.reason}
                                                </p>
                                            ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="grid min-h-[580px] place-items-center p-8 text-center">
                            <div>
                                <ListTree className="mx-auto text-[#b67e7d]" />
                                <p className="mt-3 text-sm font-medium">
                                    Selecciona una cuenta del árbol
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Aquí aparecerán su información, saldos y
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
    if (
        period.status === 'reopen_requested' ||
        period.status === 'reopen_approved'
    ) {
        return 'review';
    }

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
    reopeningRequests,
    filters,
    canManage,
    busy,
    onFilter,
    onNew,
    onClose,
    onRequestReopen,
    onApproveReopen,
    onExecuteReopen,
}: {
    uuid: string;
    periods: Period[];
    reopeningRequests: ReopeningRequest[];
    filters: Props['filters'];
    canManage: boolean;
    busy: boolean;
    onFilter: BookFilter;
    onNew: () => void;
    onClose: (period: Period) => void;
    onRequestReopen: (period: Period) => void;
    onApproveReopen: (request: ReopeningRequest) => void;
    onExecuteReopen: (request: ReopeningRequest) => void;
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
    const selectedRequests = selected
        ? reopeningRequests.filter(
              (request) => request.accounting_period_id === selected.id,
          )
        : [];
    const pendingReopenRequest = selectedRequests.find(
        (request) => request.status === 'pending',
    );
    const approvedReopenRequest = selectedRequests.find(
        (request) => request.status === 'approved',
    );
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

    // Every check that gates the close comes from the server, so the list shows what really blocks the period.
    const [loaded, setLoaded] = useState<{
        key: string;
        checks: CloseCheck[];
    } | null>(null);
    const selectedId = selected?.id;
    const selectedMovements = `${selected?.draft_count}-${selected?.posted_count}-${selected?.closed_at}`;

    const checksKey = `${uuid}:${selectedId}:${selectedMovements}`;
    const serverChecks = loaded?.key === checksKey ? loaded.checks : null;

    useEffect(() => {
        if (!selectedId) {
            return;
        }

        let active = true;
        fetch(`/accounting/companies/${uuid}/periods/${selectedId}/checks`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { checks?: CloseCheck[] } | null) => {
                if (active) {
                    setLoaded({ key: checksKey, checks: data?.checks ?? [] });
                }
            })
            .catch(() => {
                if (active) {
                    setLoaded({ key: checksKey, checks: [] });
                }
            });

        return () => {
            active = false;
        };
    }, [uuid, selectedId, checksKey]);

    const closeItems: {
        key: string;
        label: string;
        message: string;
        tone: 'ok' | 'warning' | 'blocked';
    }[] =
        serverChecks !== null && serverChecks.length > 0
            ? serverChecks.map((check) => ({
                  key: check.key,
                  label: check.label,
                  message: check.message,
                  tone:
                      check.status === 'passed'
                          ? 'ok'
                          : check.status === 'blocked'
                            ? 'blocked'
                            : 'warning',
              }))
            : [
                  {
                      key: 'balanced',
                      label: 'Débitos y créditos cuadran',
                      message: '',
                      tone: selectedDifference === 0 ? 'ok' : 'blocked',
                  },
                  {
                      key: 'pending',
                      label: 'No existen asientos pendientes',
                      message: '',
                      tone: selected?.draft_count === 0 ? 'ok' : 'blocked',
                  },
                  {
                      key: 'posted',
                      label: 'Asientos contabilizados',
                      message: '',
                      tone:
                          selected?.entries_count === selected?.posted_count
                              ? 'ok'
                              : 'warning',
                  },
              ];

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
                                {canManage && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        {!selected.closed_at && (
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    onClose(selected)
                                                }
                                                className={primary}
                                            >
                                                <LockKeyhole size={15} /> Cerrar
                                                período
                                            </button>
                                        )}
                                        {selected.closed_at &&
                                            !pendingReopenRequest &&
                                            !approvedReopenRequest && (
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        onRequestReopen(
                                                            selected,
                                                        )
                                                    }
                                                    className={secondary}
                                                >
                                                    <RefreshCw size={15} />{' '}
                                                    Solicitar reapertura
                                                </button>
                                            )}
                                        {pendingReopenRequest && (
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    onApproveReopen(
                                                        pendingReopenRequest,
                                                    )
                                                }
                                                className={primary}
                                            >
                                                <Check size={15} /> Aprobar
                                                reapertura
                                            </button>
                                        )}
                                        {approvedReopenRequest && (
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    onExecuteReopen(
                                                        approvedReopenRequest,
                                                    )
                                                }
                                                className={primary}
                                            >
                                                <RefreshCw size={15} /> Ejecutar
                                                reapertura
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                            <div className="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                                <div>
                                    <h3 className="mb-3 text-xs font-semibold">
                                        Checklist de cierre
                                    </h3>
                                    <div className="overflow-hidden rounded-xl border border-slate-200">
                                        {closeItems.map((item) => (
                                            <div
                                                key={item.key}
                                                className="flex items-start justify-between gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0"
                                            >
                                                <span className="flex items-start gap-2 text-[10px]">
                                                    <span
                                                        className={`mt-0.5 grid size-4 shrink-0 place-items-center rounded-full ${item.tone === 'ok' ? 'bg-emerald-100 text-emerald-700' : item.tone === 'blocked' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'}`}
                                                    >
                                                        {item.tone === 'ok' ? (
                                                            <Check size={10} />
                                                        ) : (
                                                            <Clock3 size={10} />
                                                        )}
                                                    </span>
                                                    <span>
                                                        <b className="block font-semibold">
                                                            {item.label}
                                                        </b>
                                                        {item.message && (
                                                            <span className="block text-[9px] text-slate-500">
                                                                {item.message}
                                                            </span>
                                                        )}
                                                    </span>
                                                </span>
                                                <strong
                                                    className={`shrink-0 text-[9px] ${item.tone === 'ok' ? 'text-emerald-700' : item.tone === 'blocked' ? 'text-red-700' : 'text-orange-700'}`}
                                                >
                                                    {item.tone === 'ok'
                                                        ? 'Completado'
                                                        : item.tone ===
                                                            'blocked'
                                                          ? 'Bloquea el cierre'
                                                          : 'Advertencia'}
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
                            <div className="border-t border-slate-100 px-5 py-4">
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="text-xs font-semibold">
                                        Solicitudes de reapertura
                                    </h3>
                                    <span className="text-[10px] text-slate-400">
                                        {selectedRequests.length} registro(s)
                                    </span>
                                </div>
                                {selectedRequests.length ? (
                                    <div className="mt-3 space-y-2">
                                        {selectedRequests.map((request) => {
                                            const requestTone =
                                                request.status === 'executed'
                                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                                    : request.status ===
                                                        'approved'
                                                      ? 'bg-blue-50 text-blue-700 border-blue-200'
                                                      : 'bg-orange-50 text-orange-700 border-orange-200';
                                            const requestLabel =
                                                request.status === 'executed'
                                                    ? 'Ejecutada'
                                                    : request.status ===
                                                        'approved'
                                                      ? 'Aprobada'
                                                      : 'Pendiente';

                                            return (
                                                <article
                                                    key={request.id}
                                                    className="rounded-xl border border-slate-200 p-3"
                                                >
                                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                                        <div>
                                                            <span
                                                                className={`inline-flex rounded-full border px-2 py-1 text-[9px] font-semibold ${requestTone}`}
                                                            >
                                                                {requestLabel}
                                                            </span>
                                                            <p className="mt-2 text-[10px] text-slate-500">
                                                                Solicitada por{' '}
                                                                <strong className="text-slate-700">
                                                                    {
                                                                        request.requester_name
                                                                    }
                                                                </strong>{' '}
                                                                el{' '}
                                                                {dateLabel(
                                                                    request.created_at,
                                                                )}
                                                            </p>
                                                        </div>
                                                        {request.approver_name && (
                                                            <p className="text-[10px] text-slate-500">
                                                                Aprobó:{' '}
                                                                {
                                                                    request.approver_name
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <p className="mt-2 rounded-lg bg-slate-50 p-2 text-[10px] text-slate-600">
                                                        {request.reason}
                                                    </p>
                                                </article>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="mt-3 rounded-xl border border-dashed border-slate-200 p-4 text-center text-[10px] text-slate-500">
                                        Este período no tiene solicitudes de
                                        reapertura.
                                    </div>
                                )}
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
        journalPermissions,
        accounts,
        periods,
        journal,
        balances,
        ledger,
        ledgerOverview,
        ledgerSummary,
        trialBalance,
        accountingDashboard,
        reopeningRequests,
        members,
        journalCreators,
        filters,
        importPreview,
        notice,
    } = props;
    const uuid = company.public_id;
    const [tab, setTab] = useState('overview');
    const [activeModuleGroup, setModuleGroup] = useState('main');
    const [journalSearch, setJournalSearch] = useState(
        filters.journal_search ?? '',
    );
    const [journalStatus, setJournalStatus] = useState(
        filters.journal_status || 'all',
    );
    const [journalKind, setJournalKind] = useState(
        filters.journal_kind || 'all',
    );
    const [journalSource, setJournalSource] = useState(
        filters.journal_source || 'all',
    );
    const [journalSupport, setJournalSupport] = useState(
        filters.journal_support || 'all',
    );
    const [journalThirdParty, setJournalThirdParty] = useState(
        filters.journal_third_party ?? '',
    );
    const [journalCostCenter, setJournalCostCenter] = useState(
        filters.journal_cost_center ?? '',
    );
    const [journalProject, setJournalProject] = useState(
        filters.journal_project ?? '',
    );
    const [journalCreatedBy, setJournalCreatedBy] = useState(
        filters.journal_created_by ? String(filters.journal_created_by) : 'all',
    );
    const [journalFrom, setJournalFrom] = useState(filters.journal_from ?? '');
    const [journalTo, setJournalTo] = useState(filters.journal_to ?? '');
    const [importFile, setImportFile] = useState<File | null>(null);
    // A link from a source document (purchase, sale, payment...) arrives with the entry to show.
    const [selectedEntryId, setSelectedEntryId] = useState<number | null>(
        () =>
            (filters.entry
                ? journal.data.find((row) => row.public_id === filters.entry)
                      ?.id
                : undefined) ?? null,
    );
    const [draftOpen, setDraftOpen] = useState(false);
    const [newAccountOpen, setNewAccountOpen] = useState(false);
    const [newPeriodOpen, setNewPeriodOpen] = useState(false);
    const [target, setTarget] = useState<Entry | null>(null);
    const [reopenTarget, setReopenTarget] = useState<Period | null>(null);
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
    const reopenForm = useForm({ reason: '' });
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
    const postedEntries = journal.data.filter((entry) =>
        ['posted', 'reversed'].includes(entry.status),
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
    const pendingEntries = journal.data.filter((entry) =>
        ['draft', 'pending_review', 'reviewed', 'approved'].includes(
            entry.status,
        ),
    ).length;
    const currentPeriod =
        periods.find((period) => period.id === filters.period) ??
        openPeriods[0] ??
        periods[0];
    const accountingPeriodName =
        accountingDashboard.period?.name ??
        currentPeriod?.name ??
        'Sin configurar';
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
            (journalCreatedBy === 'all' ||
                entry.author ===
                    journalCreators.find(
                        (creator) => String(creator.id) === journalCreatedBy,
                    )?.name) &&
            (!journalFrom || entry.entry_date >= journalFrom) &&
            (!journalTo || entry.entry_date <= journalTo) &&
            (journalSource === 'all' ||
                (journalSource === 'manual'
                    ? !entry.source_type
                    : entry.source_type === journalSource)) &&
            (journalSupport === 'all' ||
                (journalSupport === 'with'
                    ? Number(entry.support_count) > 0
                    : Number(entry.support_count) === 0)) &&
            (!journalThirdParty ||
                entry.lines.some((line) =>
                    `${line.third_party_type ?? ''} ${line.third_party_id ?? ''}`
                        .toLocaleLowerCase()
                        .includes(journalThirdParty.toLocaleLowerCase()),
                )) &&
            (!journalCostCenter ||
                entry.lines.some((line) =>
                    (line.cost_center ?? '')
                        .toLocaleLowerCase()
                        .includes(journalCostCenter.toLocaleLowerCase()),
                )) &&
            (!journalProject ||
                entry.lines.some((line) =>
                    (line.project ?? '')
                        .toLocaleLowerCase()
                        .includes(journalProject.toLocaleLowerCase()),
                ))
        );
    });
    const selectedEntry =
        journalRows.find((entry) => entry.id === selectedEntryId) ??
        journalRows[0] ??
        null;
    const tabs = [
        { id: 'journal', label: 'Libro diario' },
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
    function filter(values: Partial<Props['filters']>) {
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
        key:
            | 'account_id'
            | 'description'
            | 'third_party_type'
            | 'third_party_id'
            | 'cost_center'
            | 'project'
            | 'debit'
            | 'credit',
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
    function submitImport(mode: 'preview' | 'import') {
        if (!importFile) {
            return;
        }

        const data = new FormData();
        data.append('file', importFile);
        setBusy(true);
        router.post(
            `/accounting/companies/${uuid}/entries/import${mode === 'preview' ? '/preview' : ''}`,
            data,
            {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => setBusy(false),
            },
        );
    }
    function applyJournalFilters() {
        filter({
            journal_search: journalSearch,
            journal_status: journalStatus === 'all' ? '' : journalStatus,
            journal_kind: journalKind === 'all' ? '' : journalKind,
            journal_source: journalSource === 'all' ? '' : journalSource,
            journal_support: journalSupport === 'all' ? '' : journalSupport,
            journal_third_party: journalThirdParty,
            journal_cost_center: journalCostCenter,
            journal_project: journalProject,
            journal_created_by:
                journalCreatedBy === 'all' ? 0 : Number(journalCreatedBy),
            journal_from: journalFrom,
            journal_to: journalTo,
        });
    }

    /** Opens the journal and, when the user can write, the new-entry form. */
    const openJournal = (withForm: boolean) => {
        switchSection('journal', 'libro-diario');

        if (withForm) {
            setDraftOpen(true);
        }
    };
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
            action: () => openJournal(canWrite),
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
            action: () => switchSection('ledger', 'libro-mayor'),
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
            action: () =>
                router.visit(`/accounting/companies/${uuid}/cost-centers`),
            tone: 'wine',
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
            action: () =>
                router.visit(`/accounting/companies/${uuid}/fixed-assets`),
            tone: 'blue',
        },
        {
            title: 'Ajustes Contables',
            subtitle: 'Reclasificaciones y provisiones',
            icon: SlidersHorizontal,
            action: () => openJournal(canWrite),
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
            action: () => openJournal(canWrite),
            tone: 'purple',
        },
        {
            title: 'Importación Contable',
            subtitle: 'Carga desde Excel o CSV',
            icon: FolderUp,
            action: () => switchSection('journal', 'libro-diario'),
            tone: 'blue',
        },
    ];
    const visibleAccountingModules = accountingModules.filter(
        (module) =>
            module.requiredFeature === undefined ||
            hasPlanFeature(planFeatures, module.requiredFeature),
    );
    const moduleGroups: { id: string; label: string; modules: string[] }[] = [
        { id: 'main', label: 'Módulos principales', modules: [] },
        {
            id: 'reports',
            label: 'Reportes',
            modules: [
                'Libro Diario',
                'Libro Mayor',
                'Balance de Comprobación',
                'Auxiliares Contables',
                'Estados Financieros',
                'Presupuestos',
            ],
        },
        {
            id: 'processes',
            label: 'Procesos',
            modules: [
                'Asientos Contables',
                'Ajustes Contables',
                'Activos Fijos',
                'Períodos Contables',
                'Cierre Contable',
                'Importación Contable',
            ],
        },
        {
            id: 'setup',
            label: 'Configuración',
            modules: [
                'Plan de Cuentas',
                'Centros de Costos',
                'Saldos Iniciales',
                'Períodos Contables',
                'Importación Contable',
            ],
        },
    ];
    const selectedModuleGroup = moduleGroups.find(
        (item) => item.id === activeModuleGroup,
    );
    const groupedModules = selectedModuleGroup?.modules.length
        ? visibleAccountingModules.filter((module) =>
              selectedModuleGroup.modules.includes(module.title),
          )
        : visibleAccountingModules;
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
                                    onClick={() => setDraftOpen(true)}
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
                        <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_300px] 2xl:grid-cols-[minmax(0,1fr)_330px]">
                            <div className="min-w-0 space-y-4">
                                <div
                                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                                    aria-label="Resumen contable"
                                >
                                    {[
                                        {
                                            label: 'Débitos del período',
                                            value: money(
                                                accountingDashboard
                                                    .trial_balance.debits_cents,
                                            ),
                                            icon: ArrowDownLeft,
                                            tone: 'bg-[#fce9eb] text-[#ba3246]',
                                        },
                                        {
                                            label: 'Créditos del período',
                                            value: money(
                                                accountingDashboard
                                                    .trial_balance
                                                    .credits_cents,
                                            ),
                                            icon: ArrowUpRight,
                                            tone: 'bg-[#e7f5ec] text-[#3b9b68]',
                                        },
                                        {
                                            label: 'Asientos contabilizados',
                                            value: accountingDashboard.summary
                                                .posted_entries,
                                            hint: 'En este período',
                                            icon: FileChartColumn,
                                            tone: 'bg-[#eee9fb] text-[#6754b7]',
                                        },
                                        {
                                            label: 'Pendientes de contabilizar',
                                            value: accountingDashboard.summary
                                                .pending_entries,
                                            link: true,
                                            icon: Clock3,
                                            tone: 'bg-[#fff0e7] text-[#d2632d]',
                                        },
                                    ].map((metric) => (
                                        <article
                                            key={metric.label}
                                            className="flex items-center gap-3 rounded-2xl border border-[#ece7e5] bg-white px-3.5 py-3 shadow-[0_6px_18px_rgba(31,38,55,.04)]"
                                        >
                                            <span
                                                className={`grid size-10 shrink-0 place-items-center rounded-full ${metric.tone}`}
                                            >
                                                <metric.icon size={19} />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-xs text-slate-500">
                                                    {metric.label}
                                                </p>
                                                <strong className="block text-base font-bold whitespace-nowrap text-[#172033] tabular-nums 2xl:text-lg">
                                                    {metric.value}
                                                </strong>
                                                {metric.link ? (
                                                    <a
                                                        href="#pendientes-contabilizacion"
                                                        className="text-[11px] font-semibold text-[#8a1630]"
                                                    >
                                                        Ver pendientes →
                                                    </a>
                                                ) : (
                                                    metric.hint && (
                                                        <p className="text-[11px] text-slate-400">
                                                            {metric.hint}
                                                        </p>
                                                    )
                                                )}
                                            </div>
                                        </article>
                                    ))}
                                </div>

                                <article className="rounded-2xl border border-[#ece7e5] bg-white shadow-[0_6px_18px_rgba(31,38,55,.04)]">
                                    <nav
                                        aria-label="Grupos de módulos"
                                        className="flex gap-6 overflow-x-auto border-b border-slate-100 px-5"
                                    >
                                        {moduleGroups.map((moduleGroup) => (
                                            <button
                                                key={moduleGroup.id}
                                                type="button"
                                                onClick={() =>
                                                    setModuleGroup(
                                                        moduleGroup.id,
                                                    )
                                                }
                                                aria-current={
                                                    activeModuleGroup ===
                                                    moduleGroup.id
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className={`-mb-px border-b-2 py-3 text-sm whitespace-nowrap transition ${activeModuleGroup === moduleGroup.id ? 'border-[#8a1630] font-semibold text-[#172033]' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
                                            >
                                                {moduleGroup.label}
                                            </button>
                                        ))}
                                    </nav>
                                    <div
                                        className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                                        aria-label="Módulos de contabilidad general"
                                    >
                                        {groupedModules.map((module, index) => (
                                            <button
                                                key={module.title}
                                                type="button"
                                                onClick={module.action}
                                                className="group relative flex min-h-[104px] flex-col items-start gap-1.5 rounded-xl border border-[#ece7e5] bg-white p-3 text-left transition hover:border-[#e2b9bf] hover:bg-[#fffafa] hover:shadow-[0_10px_24px_rgba(91,0,7,.08)]"
                                            >
                                                <ChevronRight
                                                    size={16}
                                                    className="absolute top-3.5 right-3 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-[#640000]"
                                                />
                                                <span className="flex items-center gap-3">
                                                    <span
                                                        className={`grid size-10 shrink-0 place-items-center rounded-xl ${moduleTones[module.tone]}`}
                                                    >
                                                        <module.icon
                                                            size={19}
                                                        />
                                                    </span>
                                                    <span className="min-w-0 pr-4">
                                                        <span className="block text-[11px] font-semibold text-slate-400 tabular-nums">
                                                            {String(
                                                                index + 1,
                                                            ).padStart(2, '0')}
                                                        </span>
                                                        <strong className="block text-[13px] leading-tight text-[#172033]">
                                                            {module.title}
                                                        </strong>
                                                    </span>
                                                </span>
                                                <span className="text-[11px] leading-snug text-slate-500">
                                                    {module.subtitle}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </article>
                            </div>

                            <aside className="space-y-4">
                                <div className="overflow-hidden rounded-2xl border border-[#ece7e5] bg-white shadow-[0_6px_18px_rgba(31,38,55,.04)]">
                                    {[
                                        {
                                            icon: Check,
                                            label: accountingDashboard
                                                .trial_balance.balanced
                                                ? 'Balance cuadrado'
                                                : 'Balance por revisar',
                                            tone: accountingDashboard
                                                .trial_balance.balanced
                                                ? 'text-emerald-600'
                                                : 'text-red-600',
                                            action: () =>
                                                switchSection(
                                                    'trial',
                                                    'balance-comprobacion',
                                                ),
                                        },
                                        {
                                            icon: NotebookTabs,
                                            label: `${accounts.length} cuentas configuradas`,
                                            tone: 'text-[#6550b5]',
                                            action: () =>
                                                switchSection(
                                                    'setup',
                                                    'plan-cuentas',
                                                ),
                                        },
                                        {
                                            icon: CalendarDays,
                                            label: `Período ${accountingDashboard.period?.status === 'closed' ? 'cerrado' : 'abierto'}: ${accountingPeriodName}`,
                                            tone: 'text-[#315fa8]',
                                            action: () =>
                                                switchSection(
                                                    'periods',
                                                    'periodos-contables',
                                                ),
                                        },
                                        {
                                            icon: Clock3,
                                            label: `Avance de cierre: ${accountingDashboard.summary.close_progress}%`,
                                            tone: 'text-amber-500',
                                            action: () =>
                                                document
                                                    .getElementById(
                                                        'estado-contable',
                                                    )
                                                    ?.scrollIntoView({
                                                        behavior: 'smooth',
                                                    }),
                                        },
                                    ].map((item, index) => (
                                        <button
                                            key={item.label}
                                            type="button"
                                            onClick={item.action}
                                            className={`flex w-full items-center gap-3 px-4 py-2.5 text-left text-[13px] font-medium text-slate-700 hover:bg-[#faf8f7] ${index ? 'border-t border-slate-100' : ''}`}
                                        >
                                            <item.icon
                                                size={16}
                                                className={`shrink-0 ${item.tone}`}
                                            />
                                            <span className="flex-1">
                                                {item.label}
                                            </span>
                                            <ChevronRight
                                                size={15}
                                                className="text-slate-400"
                                            />
                                        </button>
                                    ))}
                                </div>

                                <div className="rounded-2xl border border-[#ece7e5] bg-white p-4 shadow-[0_6px_18px_rgba(31,38,55,.04)]">
                                    <h2 className="flex items-center gap-2 text-sm font-semibold text-[#172033]">
                                        <SlidersHorizontal
                                            size={16}
                                            className="text-slate-500"
                                        />
                                        Accesos rápidos
                                    </h2>
                                    <div className="mt-2 divide-y divide-slate-100">
                                        {[
                                            canWrite && {
                                                label: 'Nuevo asiento',
                                                icon: Plus,
                                                tone: 'bg-[#e7f5ec] text-[#218451]',
                                                action: () => openJournal(true),
                                            },
                                            canManage && {
                                                label: 'Nueva cuenta',
                                                icon: ListTree,
                                                tone: 'bg-[#eee9fb] text-[#6754b7]',
                                                action: () => {
                                                    switchSection(
                                                        'setup',
                                                        'plan-cuentas',
                                                    );
                                                    setNewAccountOpen(true);
                                                },
                                            },
                                            {
                                                label: 'Importar Excel',
                                                icon: FileSpreadsheet,
                                                tone: 'bg-[#e7f5ec] text-[#218451]',
                                                action: () =>
                                                    openPreparedModule(
                                                        'importacion-contable',
                                                    ),
                                            },
                                            {
                                                label: 'Exportar catálogo',
                                                icon: FileDown,
                                                tone: 'bg-[#e9f0fb] text-[#315fa8]',
                                                action: () =>
                                                    window.location.assign(
                                                        routes.exportMethod.url(
                                                            uuid,
                                                            {
                                                                query: {
                                                                    kind: 'accounts',
                                                                    format: 'xlsx',
                                                                    from: today(),
                                                                    to: today(),
                                                                },
                                                            },
                                                        ),
                                                    ),
                                            },
                                            {
                                                label: 'Configuración fiscal',
                                                icon: SlidersHorizontal,
                                                tone: 'bg-[#f8e9ea] text-[#8a1630]',
                                                action: () =>
                                                    router.visit(
                                                        `/accounting/companies/${uuid}/fiscal-settings`,
                                                    ),
                                            },
                                        ]
                                            .filter((item) => !!item)
                                            .map((item) => (
                                                <button
                                                    key={item.label}
                                                    type="button"
                                                    onClick={item.action}
                                                    className="flex w-full items-center gap-3 py-2 text-left text-[13px] font-medium text-slate-700 hover:text-[#640000]"
                                                >
                                                    <span
                                                        className={`grid size-8 shrink-0 place-items-center rounded-full ${item.tone}`}
                                                    >
                                                        <item.icon size={15} />
                                                    </span>
                                                    <span className="flex-1">
                                                        {item.label}
                                                    </span>
                                                    <ChevronRight
                                                        size={15}
                                                        className="text-slate-400"
                                                    />
                                                </button>
                                            ))}
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-[#ece7e5] bg-white p-4 shadow-[0_6px_18px_rgba(31,38,55,.04)]">
                                    <h2 className="flex items-center gap-2 text-sm font-semibold text-[#172033]">
                                        <Clock3
                                            size={16}
                                            className="text-slate-500"
                                        />
                                        Actividad reciente
                                    </h2>
                                    <div className="mt-2 space-y-1">
                                        {accountingDashboard.recent_activity
                                            .slice(0, 4)
                                            .map((item) => (
                                                <div
                                                    key={item.entry_id}
                                                    className="flex items-center gap-3 py-1.5"
                                                >
                                                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#e7f5ec] text-[#218451]">
                                                        <FilePenLine
                                                            size={15}
                                                        />
                                                    </span>
                                                    <span className="min-w-0">
                                                        <strong className="block truncate text-[13px] text-[#172033]">
                                                            {item.number} ·{' '}
                                                            {item.description}
                                                        </strong>
                                                        <small className="text-[11px] text-slate-400">
                                                            {shortDate(
                                                                item.date,
                                                            )}
                                                            {item.actor
                                                                ? ` · ${item.actor}`
                                                                : ''}{' '}
                                                            ·{' '}
                                                            {
                                                                statuses[
                                                                    item.status
                                                                ]
                                                            }
                                                        </small>
                                                    </span>
                                                </div>
                                            ))}
                                        {accountingDashboard.recent_activity
                                            .length === 0 && (
                                            <p className="py-4 text-center text-xs text-slate-400">
                                                Sin movimientos en este período.
                                            </p>
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            switchSection(
                                                'journal',
                                                'libro-diario',
                                            )
                                        }
                                        className="mt-2 text-xs font-semibold text-[#8a1630] underline-offset-2 hover:underline"
                                    >
                                        Ver toda la actividad →
                                    </button>
                                </div>
                            </aside>
                        </section>
                        <section className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,.8fr)]">
                            <article
                                id="pendientes-contabilizacion"
                                className="scroll-mt-20 overflow-hidden rounded-2xl border border-[#e8e4e2] bg-white shadow-[0_8px_22px_rgba(31,38,55,.06)]"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                                    <div>
                                        <h2 className="text-sm font-bold text-[#172033]">
                                            Pendientes de contabilización
                                        </h2>
                                        <p className="text-xs text-slate-500">
                                            Operaciones reales sin asiento
                                            vinculado al libro.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            switchSection(
                                                'journal',
                                                'libro-diario',
                                            )
                                        }
                                        className="rounded-xl border border-[#e5dada] px-3 py-2 text-xs font-semibold text-[#640000]"
                                    >
                                        Revisar diario
                                    </button>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[760px] text-left text-xs">
                                        <thead className="bg-slate-50 text-slate-500">
                                            <tr>
                                                <th className="px-4 py-2">
                                                    Origen
                                                </th>
                                                <th>Documento</th>
                                                <th>Fecha</th>
                                                <th>Monto</th>
                                                <th>Problema</th>
                                                <th className="pr-4 text-right">
                                                    Acción
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {accountingDashboard.pending.map(
                                                (item) => (
                                                    <tr key={item.document}>
                                                        <td className="px-4 py-2 font-semibold text-[#172033]">
                                                            {item.origin}
                                                        </td>
                                                        <td>{item.document}</td>
                                                        <td>
                                                            {shortDate(
                                                                item.date,
                                                            )}
                                                        </td>
                                                        <td>
                                                            {money(
                                                                item.amount_cents,
                                                            )}
                                                        </td>
                                                        <td>{item.problem}</td>
                                                        <td className="pr-4 text-right">
                                                            <button
                                                                type="button"
                                                                className="font-semibold text-[#8a1630]"
                                                            >
                                                                {item.action}
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                            {accountingDashboard.pending
                                                .length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={6}
                                                        className="px-4 py-8 text-center text-slate-400"
                                                    >
                                                        No existen movimientos
                                                        contables pendientes
                                                        para este período.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </article>
                            <article
                                id="estado-contable"
                                className="scroll-mt-20 rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_8px_22px_rgba(31,38,55,.06)]"
                            >
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-bold text-[#172033]">
                                        Estado contable del período
                                    </h2>
                                    <span className="rounded-full bg-[#f8e9ea] px-3 py-1 text-xs font-bold text-[#8a1630]">
                                        {
                                            accountingDashboard.summary
                                                .close_progress
                                        }
                                        %
                                    </span>
                                </div>
                                <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className="h-full rounded-full bg-[#8a1630]"
                                        style={{
                                            width: `${accountingDashboard.summary.close_progress}%`,
                                        }}
                                    />
                                </div>
                                <div className="mt-4 space-y-2">
                                    {accountingDashboard.checklist.map(
                                        (item) => (
                                            <div
                                                key={item.label}
                                                className="flex items-start gap-2 rounded-xl border border-slate-100 px-3 py-2"
                                            >
                                                <span
                                                    className={`mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-white ${
                                                        item.status === 'ok'
                                                            ? 'bg-emerald-500'
                                                            : item.status ===
                                                                'blocking'
                                                              ? 'bg-red-500'
                                                              : 'bg-amber-500'
                                                    }`}
                                                >
                                                    <Check size={13} />
                                                </span>
                                                <span className="min-w-0">
                                                    <strong className="block text-xs text-[#172033]">
                                                        {item.label}
                                                    </strong>
                                                    <small className="text-[11px] text-slate-500">
                                                        {item.detail}
                                                    </small>
                                                </span>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </article>
                        </section>
                        <section>
                            <article className="rounded-2xl border border-[#e8e4e2] bg-white p-4 shadow-[0_8px_22px_rgba(31,38,55,.06)]">
                                <h2 className="text-sm font-bold text-[#172033]">
                                    Conciliación de auxiliares
                                </h2>
                                <div className="mt-3 divide-y divide-slate-100">
                                    {accountingDashboard.reconciliations.map(
                                        (item) => (
                                            <button
                                                key={item.label}
                                                type="button"
                                                onClick={() =>
                                                    openPreparedModule(
                                                        item.module,
                                                    )
                                                }
                                                className="flex w-full items-center justify-between gap-3 py-3 text-left text-xs"
                                            >
                                                <span>
                                                    <strong className="block text-[#172033]">
                                                        {item.label}
                                                    </strong>
                                                    <small className="text-slate-500">
                                                        Auxiliar:{' '}
                                                        {item.auxiliary_cents ===
                                                        null
                                                            ? 'No verificado'
                                                            : money(
                                                                  item.auxiliary_cents,
                                                              )}{' '}
                                                        · Mayor:{' '}
                                                        {money(
                                                            item.ledger_cents,
                                                        )}
                                                    </small>
                                                </span>
                                                <span
                                                    className={`rounded-full px-3 py-1 font-semibold ${
                                                        item.difference_cents ===
                                                        0
                                                            ? 'bg-emerald-50 text-emerald-700'
                                                            : item.difference_cents ===
                                                                null
                                                              ? 'bg-slate-100 text-slate-500'
                                                              : 'bg-amber-50 text-amber-700'
                                                    }`}
                                                >
                                                    {item.difference_cents ===
                                                    null
                                                        ? 'No verificado'
                                                        : item.difference_cents ===
                                                            0
                                                          ? 'Cuadra'
                                                          : `Diferencia ${money(item.difference_cents)}`}
                                                </span>
                                            </button>
                                        ),
                                    )}
                                </div>
                            </article>
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
                                <label className="text-[10px] font-medium text-slate-500">
                                    Origen
                                    <select
                                        value={journalSource}
                                        onChange={(event) =>
                                            setJournalSource(event.target.value)
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option value="all">Todos</option>
                                        {Object.entries(entrySources).map(
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
                                    Soporte documental
                                    <select
                                        value={journalSupport}
                                        onChange={(event) =>
                                            setJournalSupport(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option value="all">Todos</option>
                                        <option value="with">
                                            Con soporte
                                        </option>
                                        <option value="without">
                                            Sin soporte
                                        </option>
                                    </select>
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Creado por
                                    <select
                                        value={journalCreatedBy}
                                        onChange={(event) =>
                                            setJournalCreatedBy(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    >
                                        <option value="all">Todos</option>
                                        {journalCreators.map((creator) => (
                                            <option
                                                key={creator.id}
                                                value={creator.id}
                                            >
                                                {creator.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Tercero
                                    <input
                                        value={journalThirdParty}
                                        onChange={(event) =>
                                            setJournalThirdParty(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="customer, supplier o ID"
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    />
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Centro de costo
                                    <input
                                        value={journalCostCenter}
                                        onChange={(event) =>
                                            setJournalCostCenter(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    />
                                </label>
                                <label className="text-[10px] font-medium text-slate-500">
                                    Proyecto
                                    <input
                                        value={journalProject}
                                        onChange={(event) =>
                                            setJournalProject(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-1 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs"
                                    />
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
                                        onClick={applyJournalFilters}
                                        className={primary}
                                    >
                                        <Search size={15} /> Aplicar filtros
                                    </button>
                                    {journalPermissions.import && (
                                        <>
                                            <a
                                                href={`/accounting/companies/${uuid}/entries/import/template`}
                                                className={secondary}
                                            >
                                                <FileSpreadsheet size={15} />
                                                Plantilla
                                            </a>
                                            <label
                                                className={`${secondary} cursor-pointer`}
                                            >
                                                <FolderUp size={15} />
                                                CSV
                                                <input
                                                    type="file"
                                                    accept=".csv,text/csv"
                                                    className="sr-only"
                                                    onChange={(event) =>
                                                        setImportFile(
                                                            event.target
                                                                .files?.[0] ??
                                                                null,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <button
                                                type="button"
                                                disabled={!importFile || busy}
                                                onClick={() =>
                                                    submitImport('preview')
                                                }
                                                className={secondary}
                                            >
                                                Prevalidar
                                            </button>
                                            <button
                                                type="button"
                                                disabled={!importFile || busy}
                                                onClick={() =>
                                                    submitImport('import')
                                                }
                                                className={primary}
                                            >
                                                Importar
                                            </button>
                                        </>
                                    )}
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
                                                            status:
                                                                journalStatus ===
                                                                'all'
                                                                    ? undefined
                                                                    : journalStatus,
                                                            entry_kind:
                                                                journalKind ===
                                                                'all'
                                                                    ? undefined
                                                                    : journalKind,
                                                            source:
                                                                journalSource ===
                                                                'all'
                                                                    ? undefined
                                                                    : journalSource,
                                                            support:
                                                                journalSupport ===
                                                                'all'
                                                                    ? undefined
                                                                    : journalSupport,
                                                            third_party:
                                                                journalThirdParty ||
                                                                undefined,
                                                            cost_center:
                                                                journalCostCenter ||
                                                                undefined,
                                                            project:
                                                                journalProject ||
                                                                undefined,
                                                            created_by:
                                                                journalCreatedBy ===
                                                                'all'
                                                                    ? undefined
                                                                    : journalCreatedBy,
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
                            {journalPermissions.import && importFile && (
                                <p className="mt-3 text-[10px] text-slate-500">
                                    Archivo seleccionado:{' '}
                                    <strong>{importFile.name}</strong>
                                </p>
                            )}
                            {importPreview && (
                                <div className="mt-3 rounded-xl border border-slate-200 bg-[#fbfaf9] p-3 text-xs">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <strong>
                                            Prevalidación de importación
                                        </strong>
                                        <span className="text-slate-500">
                                            {
                                                Object.keys(
                                                    importPreview.entries ?? {},
                                                ).length
                                            }{' '}
                                            asiento(s) · Debe{' '}
                                            {money(importPreview.total_debit)} ·
                                            Haber{' '}
                                            {money(importPreview.total_credit)}
                                        </span>
                                    </div>
                                    {importPreview.errors?.length ? (
                                        <ul className="mt-2 space-y-1 text-red-700">
                                            {importPreview.errors
                                                .slice(0, 5)
                                                .map((error) => (
                                                    <li key={error}>{error}</li>
                                                ))}
                                        </ul>
                                    ) : (
                                        <p className="mt-2 text-emerald-700">
                                            Sin errores de estructura ni partida
                                            doble. Listo para crear borradores.
                                        </p>
                                    )}
                                </div>
                            )}
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
                                                                <span className="block font-medium">
                                                                    {entrySources[
                                                                        item.source_type ??
                                                                            'manual'
                                                                    ] ??
                                                                        item.source_type ??
                                                                        'Manual'}
                                                                </span>
                                                                <span className="mt-0.5 block text-[10px] text-slate-400">
                                                                    {item.reference ??
                                                                        item.accounting_event ??
                                                                        kinds[
                                                                            item
                                                                                .kind
                                                                        ] ??
                                                                        item.kind}
                                                                </span>
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
                                                                <div className="flex flex-col gap-1">
                                                                    <span
                                                                        className={`w-fit rounded-full px-2 py-1 text-[9px] font-semibold ${item.status === 'posted' ? 'bg-emerald-100 text-emerald-800' : ['draft', 'pending_review', 'reviewed', 'approved'].includes(item.status) ? 'bg-amber-100 text-amber-800' : item.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}
                                                                    >
                                                                        {statuses[
                                                                            item
                                                                                .status
                                                                        ] ??
                                                                            item.status}
                                                                    </span>
                                                                    <span
                                                                        className={`w-fit rounded-full px-2 py-0.5 text-[9px] font-semibold ${Number(item.support_count) > 0 ? 'bg-blue-50 text-blue-700' : 'bg-orange-50 text-orange-700'}`}
                                                                    >
                                                                        {Number(
                                                                            item.support_count,
                                                                        ) > 0
                                                                            ? `${item.support_count} soporte(s)`
                                                                            : 'Sin soporte'}
                                                                    </span>
                                                                </div>
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
                                                    ] ??
                                                        selectedEntry.kind}{' '}
                                                    ·{' '}
                                                    {entrySources[
                                                        selectedEntry.source_type ??
                                                            'manual'
                                                    ] ??
                                                        selectedEntry.source_type ??
                                                        'Manual'}
                                                </p>
                                            </div>
                                            <span
                                                className={`rounded-full px-2 py-1 text-[9px] font-semibold ${selectedEntry.status === 'posted' ? 'bg-emerald-100 text-emerald-800' : ['draft', 'pending_review', 'reviewed', 'approved'].includes(selectedEntry.status) ? 'bg-amber-100 text-amber-800' : selectedEntry.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}
                                            >
                                                {statuses[
                                                    selectedEntry.status
                                                ] ?? selectedEntry.status}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-2 gap-x-4 gap-y-2 px-4 py-3 text-[10px]">
                                            <p className="text-slate-500">
                                                Fecha
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {shortDate(
                                                        selectedEntry.entry_date,
                                                    )}
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
                                                    {selectedEntry.reviewer ??
                                                        'Pendiente'}
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Aprobado por
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.approver ??
                                                        'Pendiente'}
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Contabilizado por
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.poster ??
                                                        'Pendiente'}
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Soporte
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {
                                                        selectedEntry.support_count
                                                    }{' '}
                                                    documento(s)
                                                </strong>
                                            </p>
                                            <p className="text-slate-500">
                                                Evento contable
                                                <strong className="mt-0.5 block text-[#25334b]">
                                                    {selectedEntry.accounting_event ??
                                                        'Manual'}
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
                                                    {selectedEntry.source_type && (
                                                        <span className="mt-2 block text-xs text-slate-500">
                                                            Origen:{' '}
                                                            {entrySources[
                                                                selectedEntry
                                                                    .source_type
                                                            ] ??
                                                                selectedEntry.source_type}{' '}
                                                            #
                                                            {
                                                                selectedEntry.source_id
                                                            }
                                                        </span>
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
                                                        <th className="px-3 py-2 text-left">
                                                            Tercero / Dimensión
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
                                                                <td className="px-3 py-2 text-slate-500">
                                                                    {[
                                                                        line.description,
                                                                        line.third_party_type
                                                                            ? `${line.third_party_type} #${line.third_party_id ?? ''}`
                                                                            : '',
                                                                        line.cost_center
                                                                            ? `CC: ${line.cost_center}`
                                                                            : '',
                                                                        line.project
                                                                            ? `Proyecto: ${line.project}`
                                                                            : '',
                                                                    ]
                                                                        .filter(
                                                                            Boolean,
                                                                        )
                                                                        .join(
                                                                            ' · ',
                                                                        ) ||
                                                                        '—'}
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
                                                            colSpan={3}
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
                                        {Object.values(journalPermissions).some(
                                            Boolean,
                                        ) && (
                                            <div className="flex flex-wrap gap-2 border-b border-slate-100 p-3">
                                                {selectedEntry.status ===
                                                    'draft' && (
                                                    <>
                                                        {journalPermissions.submit && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    primary
                                                                }
                                                                onClick={() => {
                                                                    action.clearErrors();
                                                                    action.setData(
                                                                        'action',
                                                                        'submit',
                                                                    );
                                                                    setTarget(
                                                                        selectedEntry,
                                                                    );
                                                                }}
                                                            >
                                                                <Check
                                                                    size={15}
                                                                />
                                                                Enviar a
                                                                revisión
                                                            </button>
                                                        )}
                                                        {journalPermissions.post && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    secondary
                                                                }
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
                                                                Contabilizar
                                                                directo
                                                            </button>
                                                        )}
                                                        {journalPermissions.void && (
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
                                                        )}
                                                    </>
                                                )}
                                                {selectedEntry.status ===
                                                    'pending_review' &&
                                                    journalPermissions.review && (
                                                        <button
                                                            type="button"
                                                            className={primary}
                                                            onClick={() => {
                                                                action.clearErrors();
                                                                action.setData(
                                                                    'action',
                                                                    'review',
                                                                );
                                                                setTarget(
                                                                    selectedEntry,
                                                                );
                                                            }}
                                                        >
                                                            <Check size={15} />
                                                            Marcar revisado
                                                        </button>
                                                    )}
                                                {selectedEntry.status ===
                                                    'reviewed' &&
                                                    journalPermissions.approve && (
                                                        <button
                                                            type="button"
                                                            className={primary}
                                                            onClick={() => {
                                                                action.clearErrors();
                                                                action.setData(
                                                                    'action',
                                                                    'approve',
                                                                );
                                                                setTarget(
                                                                    selectedEntry,
                                                                );
                                                            }}
                                                        >
                                                            <Check size={15} />
                                                            Aprobar asiento
                                                        </button>
                                                    )}
                                                {selectedEntry.status ===
                                                    'approved' &&
                                                    journalPermissions.post && (
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
                                                            Contabilizar
                                                        </button>
                                                    )}
                                                {[
                                                    'draft',
                                                    'pending_review',
                                                    'reviewed',
                                                    'approved',
                                                ].includes(
                                                    selectedEntry.status,
                                                ) &&
                                                    journalPermissions.reject && (
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondary
                                                            }
                                                            onClick={() => {
                                                                action.clearErrors();
                                                                action.setData(
                                                                    'action',
                                                                    'reject',
                                                                );
                                                                setTarget(
                                                                    selectedEntry,
                                                                );
                                                            }}
                                                        >
                                                            Rechazar
                                                        </button>
                                                    )}
                                                {selectedEntry.status ===
                                                    'posted' &&
                                                    !selectedEntry.reversal_of &&
                                                    !selectedEntry.reversed_by &&
                                                    journalPermissions.reverse && (
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
                                                canWrite={
                                                    journalPermissions.attachSupport
                                                }
                                            />
                                            <div className="mt-3 border-t border-slate-100 pt-3">
                                                <h3 className="text-xs font-semibold">
                                                    Historial
                                                </h3>
                                                <div className="mt-2 space-y-2 text-[10px] text-slate-500">
                                                    {selectedEntry.history
                                                        ?.length ? (
                                                        selectedEntry.history.map(
                                                            (item) => (
                                                                <div
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="rounded-lg border border-slate-100 bg-white px-3 py-2"
                                                                >
                                                                    <strong className="block text-[#25334b]">
                                                                        {
                                                                            item.action
                                                                        }
                                                                    </strong>
                                                                    <span>
                                                                        {item.actor ??
                                                                            'Sistema'}{' '}
                                                                        ·{' '}
                                                                        {
                                                                            item.date
                                                                        }
                                                                    </span>
                                                                </div>
                                                            ),
                                                        )
                                                    ) : (
                                                        <p>
                                                            Sin eventos de
                                                            auditoría asociados.
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
                {canWrite && (
                    <Dialog open={draftOpen} onOpenChange={setDraftOpen}>
                        <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-6xl">
                            <DialogTitle>Nuevo asiento</DialogTitle>
                            <DialogDescription>
                                Se guardará como borrador. No afectará los
                                libros hasta que el propietario lo apruebe.
                            </DialogDescription>
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
                                                lines: [
                                                    emptyLine(),
                                                    emptyLine(),
                                                ],
                                            });
                                            setDraftOpen(false);
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
                                            <option value="">
                                                Seleccionar
                                            </option>
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
                                            <option value="reclassification">
                                                Reclasificación
                                            </option>
                                            <option value="provision">
                                                Provisión
                                            </option>
                                            <option value="automatic">
                                                Automático
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
                                            className="grid grid-cols-2 items-end gap-3 rounded-xl bg-[#faf8f7] p-3 lg:grid-cols-[minmax(220px,1.2fr)_minmax(160px,.9fr)_130px_130px_40px]"
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
                                            <label className="col-span-2 text-xs font-medium lg:col-span-1">
                                                Descripción / tercero
                                                <input
                                                    maxLength={255}
                                                    className={field}
                                                    value={line.description}
                                                    onChange={(e) =>
                                                        updateLine(
                                                            i,
                                                            'description',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Detalle de la línea"
                                                />
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
                                            <div className="col-span-2 grid gap-3 md:grid-cols-3 lg:col-span-5">
                                                <label className="text-xs font-medium">
                                                    Centro de costo
                                                    <input
                                                        maxLength={120}
                                                        className={field}
                                                        value={line.cost_center}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                i,
                                                                'cost_center',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Opcional"
                                                    />
                                                </label>
                                                <label className="text-xs font-medium">
                                                    Proyecto
                                                    <input
                                                        maxLength={120}
                                                        className={field}
                                                        value={line.project}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                i,
                                                                'project',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Opcional"
                                                    />
                                                </label>
                                                <label className="text-xs font-medium">
                                                    Tercero
                                                    <input
                                                        maxLength={60}
                                                        className={field}
                                                        value={
                                                            line.third_party_type
                                                        }
                                                        onChange={(e) =>
                                                            updateLine(
                                                                i,
                                                                'third_party_type',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Cliente, proveedor..."
                                                    />
                                                </label>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
                                    <button
                                        type="button"
                                        disabled={
                                            draft.data.lines.length >= 100
                                        }
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
                                        Debe {money(debit)} · Haber{' '}
                                        {money(credit)} · Diferencia{' '}
                                        {money(debit - credit)}
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
                                        Necesitas cuentas y un período abierto
                                        para continuar.
                                    </p>
                                )}
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
                {tab === 'ledger' && (
                    <LedgerView
                        uuid={uuid}
                        accounts={accounts}
                        periods={periods}
                        balances={balances}
                        ledger={ledger}
                        ledgerOverview={ledgerOverview}
                        ledgerSummary={ledgerSummary}
                        filters={filters}
                        onFilter={filter}
                    />
                )}
                {tab === 'trial' && (
                    <TrialBalanceView
                        uuid={uuid}
                        periods={periods}
                        trialBalance={trialBalance}
                        filters={filters}
                        onFilter={filter}
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
                {tab === 'setup' && (
                    <ChartOfAccountsView
                        uuid={uuid}
                        accounts={accounts}
                        periods={periods}
                        balances={balances}
                        closingBalances={props.closingBalances}
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
                        reopeningRequests={reopeningRequests}
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
                        onRequestReopen={(period) => {
                            reopenForm.clearErrors();
                            reopenForm.setData('reason', '');
                            setReopenTarget(period);
                        }}
                        onApproveReopen={(request) =>
                            operation(
                                `/accounting/companies/${uuid}/period-reopenings/${request.id}/approve`,
                                {},
                            )
                        }
                        onExecuteReopen={(request) => {
                            if (
                                window.confirm(
                                    'El período volverá a quedar disponible para contabilización. ¿Continuar?',
                                )
                            ) {
                                operation(
                                    `/accounting/companies/${uuid}/period-reopenings/${request.id}/execute`,
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
                                {{
                                    submit: 'Enviar a revisión',
                                    review: 'Marcar como revisado',
                                    approve: 'Aprobar asiento',
                                    post: 'Contabilizar asiento',
                                    reject: 'Rechazar asiento',
                                    void: 'Descartar borrador',
                                    reverse: 'Registrar reverso',
                                }[action.data.action] ?? 'Procesar asiento'}
                            </DialogTitle>
                            <DialogDescription className="mt-2 text-sm text-slate-600">
                                {target.description}
                            </DialogDescription>
                            <p className="mt-3 rounded-xl bg-[#faf4f4] p-3 text-sm text-[#640000]">
                                {{
                                    submit: 'El asiento pasará a revisión y todavía no afectará los libros.',
                                    review: 'Se registrará quién revisó el asiento y quedará listo para aprobación.',
                                    approve:
                                        'Se registrará la aprobación formal antes de contabilizar.',
                                    post: 'El asiento quedará bloqueado para edición y afectará Libro Diario, Mayor y Balance.',
                                    reject: 'El asiento quedará rechazado con motivo en la bitácora.',
                                    void: 'Se conservarán las líneas y el motivo en la bitácora; no afectará los libros.',
                                    reverse:
                                        'Se contabilizará un nuevo asiento con debe y haber intercambiados. El original quedará marcado como revertido, sin borrar sus líneas.',
                                }[action.data.action] ??
                                    'Confirma la operación del asiento.'}
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
                                {['reverse', 'void', 'reject'].includes(
                                    action.data.action,
                                ) && (
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
                    open={!!reopenTarget}
                    onOpenChange={(open) => {
                        if (!open && !reopenForm.processing) {
                            setReopenTarget(null);
                        }
                    }}
                >
                    <DialogContent className="bg-white text-[#142135] sm:max-w-lg">
                        <DialogTitle>Solicitar reapertura</DialogTitle>
                        <DialogDescription>
                            Registra el motivo formal para reabrir el período
                            {reopenTarget ? ` ${reopenTarget.name}` : ''}.
                        </DialogDescription>
                        <form
                            className="mt-4 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (!reopenTarget) {
                                    return;
                                }

                                reopenForm.post(
                                    `/accounting/companies/${uuid}/periods/${reopenTarget.id}/reopen/request`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            reopenForm.reset();
                                            setReopenTarget(null);
                                        },
                                    },
                                );
                            }}
                        >
                            <label className="block text-sm">
                                Motivo de reapertura
                                <textarea
                                    required
                                    minLength={10}
                                    maxLength={1000}
                                    className={field}
                                    rows={5}
                                    placeholder="Describe la corrección, documento o ajuste que obliga a reabrir el período."
                                    value={reopenForm.data.reason}
                                    onChange={(event) =>
                                        reopenForm.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <Errors errors={reopenForm.errors} />
                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    disabled={reopenForm.processing}
                                    onClick={() => setReopenTarget(null)}
                                    className={secondary}
                                >
                                    Cancelar
                                </button>
                                <button
                                    disabled={reopenForm.processing}
                                    className={primary}
                                >
                                    {reopenForm.processing
                                        ? 'Solicitando…'
                                        : 'Solicitar reapertura'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
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
