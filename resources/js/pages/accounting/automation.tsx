import { router, useForm } from '@inertiajs/react';
import { Trash2, Workflow } from 'lucide-react';
import {
    Badge,
    cell,
    Empty,
    head,
    Label,
    PageShell,
    Stat,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
    shortDate,
} from '@/components/accounting-ui';

type BankRule = {
    id: number;
    name: string;
    match_text: string;
    flow: string;
    min_amount_cents: number | null;
    max_amount_cents: number | null;
    account: string;
    priority: number;
    is_active: boolean;
    times_applied: number;
};

type Template = {
    id: number;
    kind: string;
    name: string;
    frequency: string;
    day_of_month: number;
    next_run_on: string;
    ends_on: string | null;
    auto_reverse: boolean;
    is_active: boolean;
    runs: number;
    last_run_on: string | null;
    last_error: string | null;
};

type Line = {
    account_id: string;
    debit: string;
    credit: string;
    description: string;
    quantity: number;
    unit_price: string;
    tax_treatment: string;
};

type Props = {
    company: Company;
    rules: BankRule[];
    templates: Template[];
    reversals: {
        public_id: string;
        description: string;
        entry_date: string;
        status: string;
        auto_reverse_on: string | null;
        reversed: boolean;
    }[];
    frequencies: Record<string, string>;
    accounts: { id: number; code: string; name: string }[];
    customers: { id: number; name: string }[];
    drafts: {
        public_id: string;
        description: string;
        entry_date: string;
        auto_reverse_on: string | null;
    }[];
    pendingMovements: number;
    can: { banks: boolean; entries: boolean; invoices: boolean };
};

const flows: Record<string, string> = {
    any: 'Cualquiera',
    outflow: 'Débito (salida)',
    inflow: 'Crédito (entrada)',
};

const emptyLine = (): Line => ({
    account_id: '',
    debit: '',
    credit: '',
    description: '',
    quantity: 1,
    unit_price: '',
    tax_treatment: 'services',
});

function AccountSelect({
    accounts,
    value,
    onChange,
}: {
    accounts: Props['accounts'];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <select
            className={field}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Cuenta</option>
            {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                    {account.code} · {account.name}
                </option>
            ))}
        </select>
    );
}

export default function Automation({
    company,
    rules,
    templates,
    reversals,
    frequencies,
    accounts,
    customers,
    drafts,
    pendingMovements,
    can,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/automation`;
    const rule = useForm({
        name: '',
        match_text: '',
        flow: 'outflow',
        min_amount: '',
        max_amount: '',
        ledger_account_id: '',
        priority: 100,
    });
    const template = useForm({
        kind: can.entries ? 'entry' : 'invoice',
        name: '',
        frequency: 'monthly',
        day_of_month: 1,
        starts_on: new Date().toISOString().slice(0, 10),
        ends_on: '',
        auto_reverse: false,
        description: '',
        customer_id: '',
        due_days: 30,
        lines: [emptyLine(), emptyLine()],
    });
    const reversal = useForm({ entry: '', auto_reverse_on: '' });
    const isEntry = template.data.kind === 'entry';
    const setLine = (index: number, values: Partial<Line>) =>
        template.setData(
            'lines',
            template.data.lines.map((line, position) =>
                position === index ? { ...line, ...values } : line,
            ),
        );

    return (
        <PageShell
            company={company}
            title="Automatización contable"
            subtitle="Reglas bancarias, asientos y facturas recurrentes y reversos automáticos"
            icon={Workflow}
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <Stat label="Reglas bancarias" value={rules.length} />
                <Stat
                    label="Recurrentes activos"
                    value={templates.filter((item) => item.is_active).length}
                />
                <Stat
                    label="Movimientos sin clasificar"
                    value={pendingMovements}
                    tone={pendingMovements === 0 ? 'good' : 'default'}
                />
            </div>

            <Card title="Reglas bancarias">
                <p className="mb-3 text-sm text-slate-600">
                    Cuando importes un estado de cuenta, cada movimiento que
                    ninguna factura explique y cuya descripción contenga el
                    texto de una regla se clasifica solo, con su asiento en
                    borrador contra la cuenta del banco. Ejemplo: «ENERGUATE» →
                    gasto de energía eléctrica.
                </p>
                {rules.length === 0 ? (
                    <Empty>Aún no hay reglas.</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left">
                            <thead>
                                <tr>
                                    <th className={head}>Regla</th>
                                    <th className={head}>Si dice</th>
                                    <th className={head}>Movimiento</th>
                                    <th className={head}>Cuenta</th>
                                    <th className={head}>Aplicada</th>
                                    <th className={head} />
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            <span className="font-semibold">
                                                {item.name}
                                            </span>
                                            {!item.is_active && (
                                                <Badge tone="amber">
                                                    Pausada
                                                </Badge>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            «{item.match_text}»
                                        </td>
                                        <td className={cell}>
                                            {flows[item.flow]}
                                            {(item.min_amount_cents !== null ||
                                                item.max_amount_cents !==
                                                    null) && (
                                                <span className="block text-xs text-slate-500">
                                                    {item.min_amount_cents !==
                                                        null &&
                                                        `desde ${money(item.min_amount_cents)} `}
                                                    {item.max_amount_cents !==
                                                        null &&
                                                        `hasta ${money(item.max_amount_cents)}`}
                                                </span>
                                            )}
                                        </td>
                                        <td className={cell}>{item.account}</td>
                                        <td className={cell}>
                                            {item.times_applied} vez/veces
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {can.banks && (
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className={secondary}
                                                        onClick={() =>
                                                            router.patch(
                                                                `${base}/bank-rules/${item.id}`,
                                                                {
                                                                    active: !item.is_active,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {item.is_active
                                                            ? 'Pausar'
                                                            : 'Activar'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={secondary}
                                                        aria-label="Eliminar regla"
                                                        onClick={() =>
                                                            window.confirm(
                                                                `¿Eliminar la regla «${item.name}»?`,
                                                            ) &&
                                                            router.delete(
                                                                `${base}/bank-rules/${item.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {can.banks && (
                    <>
                        <form
                            className="mt-4 grid gap-3 md:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                rule.post(`${base}/bank-rules`, {
                                    preserveScroll: true,
                                    onSuccess: () => rule.reset(),
                                });
                            }}
                        >
                            <Label text="Nombre">
                                <input
                                    className={field}
                                    value={rule.data.name}
                                    placeholder="Energía eléctrica"
                                    onChange={(event) =>
                                        rule.setData('name', event.target.value)
                                    }
                                />
                            </Label>
                            <Label text="Si la descripción dice">
                                <input
                                    className={field}
                                    value={rule.data.match_text}
                                    placeholder="ENERGUATE"
                                    onChange={(event) =>
                                        rule.setData(
                                            'match_text',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Movimiento">
                                <select
                                    className={field}
                                    value={rule.data.flow}
                                    onChange={(event) =>
                                        rule.setData('flow', event.target.value)
                                    }
                                >
                                    {Object.entries(flows).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Label>
                            <Label text="Va a la cuenta">
                                <AccountSelect
                                    accounts={accounts}
                                    value={rule.data.ledger_account_id}
                                    onChange={(value) =>
                                        rule.setData('ledger_account_id', value)
                                    }
                                />
                            </Label>
                            <Label text="Monto desde (opcional)">
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    value={rule.data.min_amount}
                                    onChange={(event) =>
                                        rule.setData(
                                            'min_amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Monto hasta (opcional)">
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    value={rule.data.max_amount}
                                    onChange={(event) =>
                                        rule.setData(
                                            'max_amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Prioridad (menor gana)">
                                <input
                                    type="number"
                                    min={1}
                                    max={999}
                                    className={field}
                                    value={rule.data.priority}
                                    onChange={(event) =>
                                        rule.setData(
                                            'priority',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </Label>
                            <div className="flex items-end gap-2">
                                <button
                                    className={primary}
                                    disabled={rule.processing}
                                >
                                    Crear regla
                                </button>
                            </div>
                        </form>
                        <Errors
                            errors={rule.errors as Record<string, string>}
                        />
                        {pendingMovements > 0 && rules.length > 0 && (
                            <button
                                type="button"
                                className={`${secondary} mt-3`}
                                onClick={() =>
                                    router.post(
                                        `${base}/bank-rules/apply`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Aplicar reglas a {pendingMovements}{' '}
                                movimiento(s) pendientes
                            </button>
                        )}
                    </>
                )}
            </Card>

            <Card title="Asientos y facturas recurrentes">
                <p className="mb-3 text-sm text-slate-600">
                    Se crean solos en borrador el día indicado (alquiler,
                    depreciación, la cuota mensual de un cliente) y siguen la
                    revisión de siempre. Una provisión puede revertirse sola el
                    primer día del mes siguiente.
                </p>
                {templates.length === 0 ? (
                    <Empty>Aún no hay recurrentes.</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left">
                            <thead>
                                <tr>
                                    <th className={head}>Nombre</th>
                                    <th className={head}>Tipo</th>
                                    <th className={head}>Frecuencia</th>
                                    <th className={head}>Próxima</th>
                                    <th className={head}>Ejecutado</th>
                                    <th className={head} />
                                </tr>
                            </thead>
                            <tbody>
                                {templates.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-t border-slate-100 align-top"
                                    >
                                        <td className={cell}>
                                            <span className="font-semibold">
                                                {item.name}
                                            </span>
                                            {item.last_error && (
                                                <span className="block text-xs text-red-700">
                                                    {item.last_error}
                                                </span>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            {item.kind === 'entry'
                                                ? item.auto_reverse
                                                    ? 'Provisión (se revierte)'
                                                    : 'Asiento'
                                                : 'Factura'}
                                        </td>
                                        <td className={cell}>
                                            {frequencies[item.frequency]} · día{' '}
                                            {item.day_of_month}
                                        </td>
                                        <td className={cell}>
                                            {item.is_active ? (
                                                shortDate(item.next_run_on)
                                            ) : (
                                                <Badge tone="amber">
                                                    Pausado
                                                </Badge>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            {item.runs} vez/veces
                                            {item.last_run_on &&
                                                ` · última ${shortDate(item.last_run_on)}`}
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {can.entries && (
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() =>
                                                        router.patch(
                                                            `${base}/recurring/${item.id}`,
                                                            {
                                                                active: !item.is_active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {item.is_active
                                                        ? 'Pausar'
                                                        : 'Reanudar'}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {(can.entries || can.invoices) && (
                    <form
                        className="mt-4 space-y-3 rounded-xl border border-slate-100 p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            template.post(`${base}/recurring`, {
                                preserveScroll: true,
                                onSuccess: () => template.reset(),
                            });
                        }}
                    >
                        <div className="grid gap-3 md:grid-cols-4">
                            <Label text="Tipo">
                                <select
                                    className={field}
                                    value={template.data.kind}
                                    onChange={(event) =>
                                        template.setData(
                                            'kind',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {can.entries && (
                                        <option value="entry">
                                            Asiento contable
                                        </option>
                                    )}
                                    {can.invoices && (
                                        <option value="invoice">
                                            Factura a un cliente
                                        </option>
                                    )}
                                </select>
                            </Label>
                            <Label text="Nombre">
                                <input
                                    className={field}
                                    value={template.data.name}
                                    placeholder={
                                        isEntry
                                            ? 'Alquiler de oficina'
                                            : 'Cuota mensual'
                                    }
                                    onChange={(event) =>
                                        template.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Frecuencia">
                                <select
                                    className={field}
                                    value={template.data.frequency}
                                    onChange={(event) =>
                                        template.setData(
                                            'frequency',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(frequencies).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Label>
                            <Label text="Día del mes (1 a 28)">
                                <input
                                    type="number"
                                    min={1}
                                    max={28}
                                    className={field}
                                    value={template.data.day_of_month}
                                    onChange={(event) =>
                                        template.setData(
                                            'day_of_month',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Desde">
                                <input
                                    type="date"
                                    className={field}
                                    value={template.data.starts_on}
                                    onChange={(event) =>
                                        template.setData(
                                            'starts_on',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Hasta (opcional)">
                                <input
                                    type="date"
                                    className={field}
                                    value={template.data.ends_on}
                                    onChange={(event) =>
                                        template.setData(
                                            'ends_on',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            {isEntry ? (
                                <label className="flex items-end gap-2 pb-3 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-[#640000]"
                                        checked={template.data.auto_reverse}
                                        onChange={(event) =>
                                            template.setData(
                                                'auto_reverse',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Es una provisión: revertirla el día 1 del
                                    mes siguiente
                                </label>
                            ) : (
                                <>
                                    <Label text="Cliente">
                                        <select
                                            className={field}
                                            value={template.data.customer_id}
                                            onChange={(event) =>
                                                template.setData(
                                                    'customer_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Elige</option>
                                            {customers.map((customer) => (
                                                <option
                                                    key={customer.id}
                                                    value={customer.id}
                                                >
                                                    {customer.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Label>
                                    <Label text="Días de crédito">
                                        <input
                                            type="number"
                                            min={0}
                                            max={120}
                                            className={field}
                                            value={template.data.due_days}
                                            onChange={(event) =>
                                                template.setData(
                                                    'due_days',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                    </Label>
                                </>
                            )}
                        </div>
                        <div className="space-y-2">
                            {template.data.lines.map((line, index) => (
                                <div
                                    key={index}
                                    className="grid gap-2 md:grid-cols-[2fr_1fr_1fr_2fr_auto]"
                                >
                                    {isEntry ? (
                                        <>
                                            <AccountSelect
                                                accounts={accounts}
                                                value={line.account_id}
                                                onChange={(value) =>
                                                    setLine(index, {
                                                        account_id: value,
                                                    })
                                                }
                                            />
                                            <input
                                                className={field}
                                                inputMode="decimal"
                                                placeholder="Debe"
                                                value={line.debit}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        debit: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <input
                                                className={field}
                                                inputMode="decimal"
                                                placeholder="Haber"
                                                value={line.credit}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        credit: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <select
                                                className={field}
                                                value={line.tax_treatment}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        tax_treatment:
                                                            event.target.value,
                                                    })
                                                }
                                            >
                                                <option value="services">
                                                    Servicios
                                                </option>
                                                <option value="goods">
                                                    Bienes
                                                </option>
                                                <option value="exempt">
                                                    Exento
                                                </option>
                                            </select>
                                            <input
                                                type="number"
                                                min={1}
                                                className={field}
                                                placeholder="Cantidad"
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        quantity: Number(
                                                            event.target.value,
                                                        ),
                                                    })
                                                }
                                            />
                                            <input
                                                className={field}
                                                inputMode="decimal"
                                                placeholder="Precio con IVA"
                                                value={line.unit_price}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        unit_price:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        </>
                                    )}
                                    <input
                                        className={field}
                                        placeholder="Descripción"
                                        value={line.description}
                                        onChange={(event) =>
                                            setLine(index, {
                                                description: event.target.value,
                                            })
                                        }
                                    />
                                    <button
                                        type="button"
                                        className={`${secondary} mt-1`}
                                        aria-label="Quitar línea"
                                        onClick={() =>
                                            template.setData(
                                                'lines',
                                                template.data.lines.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                className={secondary}
                                onClick={() =>
                                    template.setData('lines', [
                                        ...template.data.lines,
                                        emptyLine(),
                                    ])
                                }
                            >
                                Agregar línea
                            </button>
                        </div>
                        <Errors
                            errors={template.errors as Record<string, string>}
                        />
                        <button
                            className={primary}
                            disabled={template.processing}
                        >
                            Programar recurrente
                        </button>
                    </form>
                )}
            </Card>

            <Card title="Reversos automáticos">
                <p className="mb-3 text-sm text-slate-600">
                    Programa la fecha en que un asiento (por ejemplo una
                    provisión de fin de mes) se revierte solo. El reverso se
                    contabiliza ese día, una vez que el asiento original está
                    contabilizado.
                </p>
                {reversals.length > 0 && (
                    <div className="mb-4 overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left">
                            <thead>
                                <tr>
                                    <th className={head}>Asiento</th>
                                    <th className={head}>Fecha</th>
                                    <th className={head}>Se revierte</th>
                                    <th className={head}>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reversals.map((item) => (
                                    <tr
                                        key={item.public_id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {item.description}
                                        </td>
                                        <td className={cell}>
                                            {shortDate(item.entry_date)}
                                        </td>
                                        <td className={cell}>
                                            {shortDate(item.auto_reverse_on)}
                                        </td>
                                        <td className={cell}>
                                            {item.reversed ? (
                                                <Badge tone="green">
                                                    Revertido
                                                </Badge>
                                            ) : item.status === 'posted' ? (
                                                <Badge tone="amber">
                                                    Programado
                                                </Badge>
                                            ) : (
                                                <Badge>
                                                    Falta contabilizar
                                                </Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {can.entries && drafts.length > 0 && (
                    <form
                        className="grid gap-3 md:grid-cols-[2fr_1fr_auto] md:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            reversal.post(`${base}/reversals`, {
                                preserveScroll: true,
                                onSuccess: () => reversal.reset(),
                            });
                        }}
                    >
                        <Label text="Asiento por contabilizar">
                            <select
                                className={field}
                                value={reversal.data.entry}
                                onChange={(event) =>
                                    reversal.setData(
                                        'entry',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Elige un asiento</option>
                                {drafts.map((entry) => (
                                    <option
                                        key={entry.public_id}
                                        value={entry.public_id}
                                    >
                                        {shortDate(entry.entry_date)} ·{' '}
                                        {entry.description}
                                    </option>
                                ))}
                            </select>
                        </Label>
                        <Label text="Revertir el">
                            <input
                                type="date"
                                className={field}
                                value={reversal.data.auto_reverse_on}
                                onChange={(event) =>
                                    reversal.setData(
                                        'auto_reverse_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </Label>
                        <button
                            className={primary}
                            disabled={
                                reversal.processing ||
                                reversal.data.entry === ''
                            }
                        >
                            Programar reverso
                        </button>
                    </form>
                )}
                <Errors errors={reversal.errors as Record<string, string>} />
            </Card>
        </PageShell>
    );
}
