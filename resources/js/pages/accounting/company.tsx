import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    BarChart3,
    BookOpen,
    Check,
    FileDown,
    FilePlus2,
    LockKeyhole,
    NotebookTabs,
    Paperclip,
    Plus,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
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
import * as routes from '@/routes/accounting';

type Account = { id: number; code: string; name: string; type: string };
type Period = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    closed_at: string | null;
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

export default function CompanyAccounting(props: Props) {
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
    const [tab, setTab] = useState(
        accounts.length && periods.length ? 'journal' : 'setup',
    );
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
    const tabs = [
        { id: 'journal', label: 'Libro diario' },
        ...(canWrite ? [{ id: 'draft', label: 'Nuevo asiento' }] : []),
        { id: 'ledger', label: 'Mayor y balance' },
        { id: 'setup', label: 'Configuración contable' },
        ...(canManage ? [{ id: 'access', label: 'Accesos' }] : []),
    ];
    function filter(values: { period?: number; account?: number }) {
        router.get(
            routes.show.url(uuid),
            { ...filters, ...values },
            { preserveState: true, preserveScroll: true },
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

    return (
        <>
            <Head title={`${company.name} · Contabilidad`} />
            <main className="min-h-full space-y-5 bg-[#faf8f7] p-4 text-[#142135] sm:p-8">
                <Link
                    href={routes.index()}
                    className="inline-flex items-center gap-2 text-sm font-medium text-[#640000]"
                >
                    <ArrowLeft size={16} /> Todas las empresas
                </Link>
                <header className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#640000] via-[#7d0909] to-[#420001] p-6 text-white shadow-[0_22px_60px_rgba(100,0,0,.18)] sm:p-8">
                    <div className="pointer-events-none absolute -top-28 -right-20 size-80 rounded-full border border-white/15" />
                    <div className="pointer-events-none absolute -top-12 -right-8 size-52 rounded-full border border-white/10" />
                    <p className="text-xs font-semibold tracking-[.2em] text-rose-200">
                        ESPACIO CONTABLE / GTQ
                    </p>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 className="text-3xl font-semibold">
                                {company.name}
                            </h1>
                            <p className="mt-2 text-sm text-rose-100">
                                NIT {company.tax_id} · {company.office_name}
                            </p>
                        </div>
                        <span className="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm">
                            <LockKeyhole size={16} />
                            {canManage
                                ? 'Propietario · revisión y aprobación'
                                : canWrite
                                  ? 'Captura de borradores'
                                  : 'Solo consulta'}
                        </span>
                    </div>
                </header>
                <section
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    aria-label="Resumen contable"
                >
                    {[
                        {
                            label: 'Cuentas contables',
                            value: accounts.length,
                            hint: 'Catálogo activo',
                            icon: NotebookTabs,
                        },
                        {
                            label: 'Períodos abiertos',
                            value: openPeriods.length,
                            hint: `${periods.length} períodos registrados`,
                            icon: ShieldCheck,
                        },
                        {
                            label: 'Borradores pendientes',
                            value: journal.data.filter(
                                (entry) => entry.status === 'draft',
                            ).length,
                            hint: 'Pendientes de aprobación',
                            icon: FilePlus2,
                        },
                        {
                            label: 'Asientos contabilizados',
                            value: journal.data.filter(
                                (entry) => entry.status === 'posted',
                            ).length,
                            hint: 'Visibles en esta página',
                            icon: BarChart3,
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="rounded-2xl border border-[#eadfdf] bg-white p-5 shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-sm text-slate-500">
                                        {metric.label}
                                    </p>
                                    <strong className="mt-2 block text-3xl text-[#142135]">
                                        {metric.value}
                                    </strong>
                                </div>
                                <span className="rounded-xl bg-[#f8eaea] p-2.5 text-[#7d0909]">
                                    <metric.icon size={20} />
                                </span>
                            </div>
                            <p className="mt-3 text-xs text-slate-500">
                                {metric.hint}
                            </p>
                        </article>
                    ))}
                </section>
                {notice && (
                    <p
                        role="status"
                        className="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800"
                    >
                        {notice}
                    </p>
                )}
                <Errors errors={operationErrors} />
                <nav
                    aria-label="Secciones contables"
                    className="flex flex-wrap gap-2 rounded-2xl border border-[#ece3e3] bg-white p-2"
                >
                    {tabs.map((item) => (
                        <button
                            key={item.id}
                            onClick={() => setTab(item.id)}
                            aria-current={tab === item.id ? 'page' : undefined}
                            className={`rounded-xl px-4 py-3 text-sm font-medium ${tab === item.id ? 'bg-[#640000] text-white' : 'text-slate-600 hover:bg-[#f8eeee]'}`}
                        >
                            {item.label}
                        </button>
                    ))}
                </nav>
                {(tab === 'journal' || tab === 'ledger') && (
                    <div className="flex flex-wrap items-end gap-4">
                        <label className="text-sm font-medium">
                            Período de consulta
                            <select
                                className={field}
                                value={filters.period}
                                onChange={(e) =>
                                    filter({ period: Number(e.target.value) })
                                }
                            >
                                <option value={0}>Todos los períodos</option>
                                {periods.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                        {p.closed_at ? ' · Cerrado' : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <p className="pb-2 text-sm text-slate-500">
                            Los reportes solo incluyen asientos contabilizados.
                        </p>
                    </div>
                )}
                {tab === 'journal' && (
                    <Card title="Libro diario y revisión de borradores">
                        {!journal.data.length && (
                            <div className="py-10 text-center">
                                <BookOpen
                                    className="mx-auto mb-3 text-[#b67e7d]"
                                    size={34}
                                />
                                <p>No hay asientos en esta selección.</p>
                                <p className="mt-2 text-sm text-slate-500">
                                    Configura las cuentas y un período antes de
                                    registrar movimientos.
                                </p>
                            </div>
                        )}
                        <div className="space-y-4">
                            {journal.data.map((item) => (
                                <article
                                    key={item.id}
                                    className="overflow-hidden rounded-xl border border-slate-200"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3 bg-[#faf8f7] p-4">
                                        <div>
                                            <p className="text-xs text-slate-500">
                                                {item.entry_date} ·{' '}
                                                {item.period_name} ·{' '}
                                                {item.number
                                                    ? `Asiento #${item.number}`
                                                    : `Borrador ${item.id}`}
                                            </p>
                                            <h3 className="mt-1 font-semibold">
                                                {item.description}
                                            </h3>
                                            <p className="mt-1 text-xs text-slate-500">
                                                Preparado por {item.author}
                                                {item.approver
                                                    ? ` · Aprobado por ${item.approver}`
                                                    : ''}
                                                {item.kind === 'opening'
                                                    ? ' · Saldos iniciales'
                                                    : ''}
                                                {item.kind !== 'opening'
                                                    ? ` · ${kinds[item.kind] ?? item.kind}`
                                                    : ''}
                                                {item.reference
                                                    ? ` · Ref. ${item.reference}`
                                                    : ''}
                                                {item.reversal_of
                                                    ? ` · Reversa del registro ${item.reversal_of}`
                                                    : ''}
                                                {item.reversed_by
                                                    ? ' · Tiene reverso'
                                                    : ''}
                                            </p>
                                        </div>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${item.status === 'posted' ? 'bg-emerald-50 text-emerald-800' : item.status === 'draft' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}
                                        >
                                            {statuses[item.status]}
                                        </span>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[480px] text-sm">
                                            <thead className="text-left text-xs text-slate-500">
                                                <tr>
                                                    <th className="p-3">
                                                        Cuenta
                                                    </th>
                                                    <th className="p-3 text-right">
                                                        Debe
                                                    </th>
                                                    <th className="p-3 text-right">
                                                        Haber
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {item.lines.map((line, i) => (
                                                    <tr
                                                        key={i}
                                                        className="border-t border-slate-100"
                                                    >
                                                        <td className="p-3">
                                                            {line.code} ·{' '}
                                                            {line.name}
                                                        </td>
                                                        <td className="p-3 text-right tabular-nums">
                                                            {money(
                                                                line.debit_cents,
                                                            )}
                                                        </td>
                                                        <td className="p-3 text-right tabular-nums">
                                                            {money(
                                                                line.credit_cents,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                            <tfoot className="border-t border-slate-200 font-semibold">
                                                <tr>
                                                    <td className="p-3">
                                                        Total
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        {money(
                                                            item.lines.reduce(
                                                                (sum, line) =>
                                                                    sum +
                                                                    Number(
                                                                        line.debit_cents,
                                                                    ),
                                                                0,
                                                            ),
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        {money(
                                                            item.lines.reduce(
                                                                (sum, line) =>
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
                                    <EntryDocuments
                                        company={uuid}
                                        entry={item}
                                        canWrite={canWrite}
                                    />
                                    {canManage && (
                                        <div className="flex flex-wrap gap-2 border-t border-slate-100 p-3">
                                            {item.status === 'draft' && (
                                                <>
                                                    <button
                                                        className={primary}
                                                        onClick={() => {
                                                            action.clearErrors();
                                                            action.setData(
                                                                'action',
                                                                'post',
                                                            );
                                                            setTarget(item);
                                                        }}
                                                    >
                                                        <Check size={16} />{' '}
                                                        Revisar y aprobar
                                                    </button>
                                                    <button
                                                        className={secondary}
                                                        onClick={() => {
                                                            action.clearErrors();
                                                            action.setData(
                                                                'action',
                                                                'void',
                                                            );
                                                            setTarget(item);
                                                        }}
                                                    >
                                                        Descartar borrador
                                                    </button>
                                                </>
                                            )}
                                            {item.status === 'posted' &&
                                                !item.reversal_of &&
                                                !item.reversed_by && (
                                                    <button
                                                        className={secondary}
                                                        onClick={() => {
                                                            action.clearErrors();
                                                            action.setData(
                                                                'action',
                                                                'reverse',
                                                            );
                                                            setTarget(item);
                                                        }}
                                                    >
                                                        Registrar reverso
                                                    </button>
                                                )}
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                        <Pager page={journal} />
                    </Card>
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
                    <div className="space-y-5">
                        <Card title="Balance de comprobación">
                            <p className="mb-4 text-sm text-slate-500">
                                Movimientos del período seleccionado y saldo
                                neto (debe − haber). No incluye arrastre
                                automático de períodos anteriores. Un saldo
                                negativo es acreedor; no significa pérdida.
                            </p>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[600px] text-sm">
                                    <thead className="bg-[#faf8f7] text-left">
                                        <tr>
                                            <th className="p-3">Cuenta</th>
                                            <th className="p-3 text-right">
                                                Debe
                                            </th>
                                            <th className="p-3 text-right">
                                                Haber
                                            </th>
                                            <th className="p-3 text-right">
                                                Saldo neto
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {balances.map((b) => (
                                            <tr
                                                key={b.id}
                                                className="border-b border-slate-100"
                                            >
                                                <td className="p-3">
                                                    {b.code} · {b.name}
                                                    <span className="block text-xs text-slate-500">
                                                        {types[b.type]}
                                                    </span>
                                                </td>
                                                <td className="p-3 text-right">
                                                    {money(b.debit_cents)}
                                                </td>
                                                <td className="p-3 text-right">
                                                    {money(b.credit_cents)}
                                                </td>
                                                <td className="p-3 text-right font-medium">
                                                    {money(
                                                        Number(b.debit_cents) -
                                                            Number(
                                                                b.credit_cents,
                                                            ),
                                                    )}
                                                    <span className="block text-xs text-slate-500">
                                                        {Number(b.debit_cents) >
                                                        Number(b.credit_cents)
                                                            ? 'Deudor'
                                                            : Number(
                                                                    b.credit_cents,
                                                                ) >
                                                                Number(
                                                                    b.debit_cents,
                                                                )
                                                              ? 'Acreedor'
                                                              : 'Sin saldo'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot className="font-semibold">
                                        <tr>
                                            <td className="p-3">Totales</td>
                                            <td className="p-3 text-right">
                                                {money(
                                                    balances.reduce(
                                                        (sum, b) =>
                                                            sum +
                                                            Number(
                                                                b.debit_cents,
                                                            ),
                                                        0,
                                                    ),
                                                )}
                                            </td>
                                            <td className="p-3 text-right">
                                                {money(
                                                    balances.reduce(
                                                        (sum, b) =>
                                                            sum +
                                                            Number(
                                                                b.credit_cents,
                                                            ),
                                                        0,
                                                    ),
                                                )}
                                            </td>
                                            <td className="p-3 text-right">
                                                {money(
                                                    balances.reduce(
                                                        (sum, b) =>
                                                            sum +
                                                            Number(
                                                                b.debit_cents,
                                                            ) -
                                                            Number(
                                                                b.credit_cents,
                                                            ),
                                                        0,
                                                    ),
                                                )}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            {!balances.length && (
                                <p className="py-4 text-sm text-slate-500">
                                    Todavía no hay movimientos contabilizados.
                                </p>
                            )}
                        </Card>
                        <Card title="Libro mayor por cuenta">
                            <label className="block max-w-md text-sm font-medium">
                                Cuenta a consultar
                                <select
                                    className={field}
                                    value={filters.account}
                                    onChange={(e) =>
                                        filter({
                                            account: Number(e.target.value),
                                        })
                                    }
                                >
                                    <option value={0}>
                                        Seleccionar cuenta
                                    </option>
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.code} · {a.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {ledger && (
                                <>
                                    <div className="mt-5 overflow-x-auto">
                                        <table className="w-full min-w-[600px] text-sm">
                                            <thead className="bg-[#faf8f7] text-left">
                                                <tr>
                                                    <th className="p-3">
                                                        Fecha / asiento
                                                    </th>
                                                    <th className="p-3">
                                                        Concepto
                                                    </th>
                                                    <th className="p-3 text-right">
                                                        Debe
                                                    </th>
                                                    <th className="p-3 text-right">
                                                        Haber
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {ledger.data.map((l, i) => (
                                                    <tr
                                                        key={i}
                                                        className="border-b border-slate-100"
                                                    >
                                                        <td className="p-3">
                                                            {l.entry_date}
                                                            <span className="block text-xs text-slate-500">
                                                                {l.period_name}{' '}
                                                                · #{l.number}
                                                            </span>
                                                        </td>
                                                        <td className="p-3">
                                                            {l.description}
                                                        </td>
                                                        <td className="p-3 text-right">
                                                            {money(
                                                                l.debit_cents,
                                                            )}
                                                        </td>
                                                        <td className="p-3 text-right">
                                                            {money(
                                                                l.credit_cents,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <Pager page={ledger} />
                                </>
                            )}
                        </Card>
                    </div>
                )}
                {tab === 'setup' && (
                    <div className="grid items-start gap-5 xl:grid-cols-2">
                        <Card title="Catálogo de cuentas">
                            {canManage && (
                                <div className="mb-5 rounded-2xl border border-[#b67e7d]/35 bg-gradient-to-r from-[#fff8f8] to-white p-4">
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="font-semibold text-slate-950">
                                                Plantilla contable para comenzar
                                            </p>
                                            <p className="mt-1 max-w-xl text-sm leading-6 text-slate-600">
                                                Instala un catálogo base de
                                                activos, pasivos, patrimonio,
                                                ingresos y gastos. Es seguro
                                                ejecutarlo nuevamente: no
                                                duplica cuentas existentes.
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            className="shrink-0 rounded-xl border border-[#640000]/20 bg-white px-4 py-2.5 text-sm font-semibold text-[#640000] shadow-sm transition hover:border-[#640000]/40 hover:bg-[#fff7f7]"
                                            onClick={() =>
                                                operation(
                                                    routes.accountTemplate.url(
                                                        uuid,
                                                    ),
                                                    {},
                                                )
                                            }
                                        >
                                            Instalar catálogo inicial
                                        </button>
                                    </div>
                                </div>
                            )}
                            {canManage && (
                                <form
                                    className="mb-6 space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        accountForm.post(
                                            routes.account.url(uuid),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    accountForm.reset(),
                                            },
                                        );
                                    }}
                                >
                                    <div className="grid grid-cols-[100px_1fr] gap-3">
                                        <label className="text-sm">
                                            Código
                                            <input
                                                required
                                                maxLength={20}
                                                className={field}
                                                value={accountForm.data.code}
                                                onChange={(e) =>
                                                    accountForm.setData(
                                                        'code',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="1101"
                                            />
                                        </label>
                                        <label className="text-sm">
                                            Nombre
                                            <input
                                                required
                                                maxLength={150}
                                                className={field}
                                                value={accountForm.data.name}
                                                onChange={(e) =>
                                                    accountForm.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Caja"
                                            />
                                        </label>
                                    </div>
                                    <label className="block text-sm">
                                        Clasificación
                                        <select
                                            className={field}
                                            value={accountForm.data.type}
                                            onChange={(e) =>
                                                accountForm.setData(
                                                    'type',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {Object.entries(types).map(
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
                                    <Errors errors={accountForm.errors} />
                                    <button
                                        disabled={accountForm.processing}
                                        className={primary}
                                    >
                                        Crear cuenta
                                    </button>
                                </form>
                            )}
                            <p className="mb-4 text-xs text-slate-500">
                                Catálogo de cuentas de movimiento. La estructura
                                fiscal debe revisarla el contador responsable.
                            </p>
                            <div className="max-h-96 space-y-2 overflow-auto">
                                {accounts.map((a) => (
                                    <div
                                        key={a.id}
                                        className="flex justify-between gap-3 rounded-xl border border-slate-100 p-3 text-sm"
                                    >
                                        <span>
                                            <strong>{a.code}</strong> · {a.name}
                                        </span>
                                        <span className="text-slate-500">
                                            {types[a.type]}
                                        </span>
                                        {canManage && (
                                            <button
                                                type="button"
                                                className="font-semibold text-[#640000] underline"
                                                onClick={() => {
                                                    editAccount.clearErrors();
                                                    editAccount.setData({
                                                        code: a.code,
                                                        name: a.name,
                                                        type: a.type,
                                                        reason: '',
                                                        acknowledged: false,
                                                    });
                                                    setEditingAccount(a);
                                                }}
                                            >
                                                Editar
                                                <span className="sr-only">
                                                    {' '}
                                                    cuenta {a.code}
                                                </span>
                                            </button>
                                        )}
                                    </div>
                                ))}
                                {!accounts.length && (
                                    <p className="text-sm text-slate-500">
                                        Sin cuentas registradas.
                                    </p>
                                )}
                            </div>
                        </Card>
                        <Card title="Períodos contables">
                            {canManage && (
                                <form
                                    className="mb-6 space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        periodForm.post(
                                            routes.period.url(uuid),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    periodForm.reset(),
                                            },
                                        );
                                    }}
                                >
                                    <label className="block text-sm">
                                        Nombre del período
                                        <input
                                            required
                                            className={field}
                                            maxLength={80}
                                            placeholder="Agosto 2026"
                                            value={periodForm.data.name}
                                            onChange={(e) =>
                                                periodForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <div className="grid grid-cols-2 gap-3">
                                        <label className="text-sm">
                                            Inicio
                                            <input
                                                required
                                                type="date"
                                                className={field}
                                                value={
                                                    periodForm.data.starts_on
                                                }
                                                onChange={(e) =>
                                                    periodForm.setData(
                                                        'starts_on',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="text-sm">
                                            Fin
                                            <input
                                                required
                                                type="date"
                                                className={field}
                                                value={periodForm.data.ends_on}
                                                onChange={(e) =>
                                                    periodForm.setData(
                                                        'ends_on',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                    </div>
                                    <Errors errors={periodForm.errors} />
                                    <button
                                        disabled={periodForm.processing}
                                        className={primary}
                                    >
                                        Abrir período
                                    </button>
                                </form>
                            )}
                            <div className="space-y-3">
                                {periods.map((p) => (
                                    <div
                                        key={p.id}
                                        className="rounded-xl border border-slate-200 p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <strong className="text-sm">
                                                {p.name}
                                            </strong>
                                            <span
                                                className={`text-xs ${p.closed_at ? 'text-slate-500' : 'text-emerald-700'}`}
                                            >
                                                {p.closed_at
                                                    ? 'Cerrado'
                                                    : 'Abierto'}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {p.starts_on} — {p.ends_on}
                                        </p>
                                        {canManage && !p.closed_at && (
                                            <button
                                                disabled={busy}
                                                className="mt-3 text-sm font-medium text-[#640000] underline"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
                                                            'Se bloquearán nuevas contabilizaciones en este período. No se generan asientos de cierre y no se puede reabrir desde esta pantalla. ¿Continuar?',
                                                        )
                                                    ) {
                                                        operation(
                                                            routes.close.url({
                                                                company: uuid,
                                                                period: p.id,
                                                            }),
                                                            {},
                                                        );
                                                    }
                                                }}
                                            >
                                                Cerrar período
                                            </button>
                                        )}
                                    </div>
                                ))}
                                {!periods.length && (
                                    <p className="text-sm text-slate-500">
                                        Sin períodos registrados.
                                    </p>
                                )}
                            </div>
                        </Card>
                    </div>
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
                {tab === 'setup' && canManage && (
                    <div className="mt-5">
                        <Card title="Historial de cambios de cuentas">
                            <p className="mb-4 text-sm text-slate-500">
                                Últimas 30 correcciones de esta empresa. Solo el
                                propietario puede consultarlas.
                            </p>
                            {!props.accountHistory.length && (
                                <p className="text-sm text-slate-500">
                                    Todavía no hay correcciones registradas.
                                </p>
                            )}
                            {props.accountHistory.map((change) => (
                                <article
                                    key={change.id}
                                    className="mb-3 rounded-xl border border-[#eadbdb] p-4 text-sm"
                                >
                                    <p className="font-semibold">
                                        {change.actor ??
                                            'Usuario no disponible'}{' '}
                                        · {change.date}
                                    </p>
                                    <p className="mt-2">
                                        Antes: {change.before.code} ·{' '}
                                        {change.before.name} ·{' '}
                                        {types[change.before.type]}
                                    </p>
                                    <p>
                                        Después: {change.after.code} ·{' '}
                                        {change.after.name} ·{' '}
                                        {types[change.after.type]}
                                    </p>
                                    <p className="mt-2 text-slate-600">
                                        Motivo: {change.after.reason}
                                    </p>
                                </article>
                            ))}
                        </Card>
                    </div>
                )}
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
