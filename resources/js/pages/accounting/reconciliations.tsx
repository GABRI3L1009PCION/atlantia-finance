import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, Plus, RefreshCw, Scale, X } from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    head,
    Label,
    PageShell,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';

type Item = {
    id: number;
    kind: string;
    kind_label: string;
    description: string;
    amount_cents: number;
};
type Reconciliation = {
    id: number;
    account: string;
    as_of: string;
    counterpart: string;
    ledger_balance_cents: number;
    supporting_balance_cents: number;
    items_cents: number;
    difference_cents: number;
    status: 'draft' | 'reconciled' | 'reviewed';
    notes: string | null;
    prepared_by: string | null;
    reviewed_at: string | null;
    items: Item[];
};

type Props = {
    company: Company;
    reconciliations: Reconciliation[];
    kinds: Record<string, string>;
    accounts: { id: number; code: string; name: string }[];
    canPrepare: boolean;
    canReview: boolean;
    userId: number;
    today: string;
};

const statusBadge = (status: Reconciliation['status']) =>
    status === 'draft' ? (
        <Badge tone="amber">Borrador</Badge>
    ) : status === 'reconciled' ? (
        <Badge tone="slate">Pendiente de revisión</Badge>
    ) : (
        <Badge tone="green">Revisada</Badge>
    );

function ItemForm({
    base,
    reconciliation,
    kinds,
}: {
    base: string;
    reconciliation: Reconciliation;
    kinds: Record<string, string>;
}) {
    const form = useForm({ kind: 'in_transit', description: '', amount: '' });

    return (
        <form
            className="mt-3 grid gap-2 md:grid-cols-[180px_1fr_140px_auto] md:items-end"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${base}/${reconciliation.id}/items`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('description', 'amount'),
                });
            }}
        >
            <Label text="Tipo">
                <select
                    className={field}
                    value={form.data.kind}
                    onChange={(e) => form.setData('kind', e.target.value)}
                >
                    {Object.entries(kinds).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </Label>
            <Label text="Descripción">
                <input
                    className={field}
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData('description', e.target.value)
                    }
                    required
                />
            </Label>
            <Label text="Monto (Q, ± )">
                <input
                    className={field}
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    required
                />
            </Label>
            <button className={secondary} disabled={form.processing}>
                <Plus className="size-4" /> Partida
            </button>
            <div className="md:col-span-4">
                <Errors errors={form.errors} />
            </div>
        </form>
    );
}

export default function Reconciliations({
    company,
    reconciliations,
    kinds,
    accounts,
    canPrepare,
    canReview,
    userId,
    today,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/reconciliations`;
    const form = useForm({
        ledger_account_id: '',
        as_of: today,
        counterpart: '',
        supporting_balance: '',
        notes: '',
    });
    const [open, setOpen] = useState<number | null>(
        reconciliations.find((item) => item.status === 'draft')?.id ?? null,
    );

    return (
        <PageShell
            company={company}
            title="Conciliación de cuentas"
            subtitle="Cuentas de enlace, intercompañías y transitorias"
            icon={Scale}
        >
            {canPrepare && (
                <Card title="Nueva conciliación">
                    <form
                        className="grid gap-3 md:grid-cols-5 md:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(base, {
                                preserveScroll: true,
                                onSuccess: () =>
                                    form.reset(
                                        'counterpart',
                                        'supporting_balance',
                                        'notes',
                                    ),
                            });
                        }}
                    >
                        <div className="md:col-span-2">
                            <Label text="Cuenta contable">
                                <select
                                    className={field}
                                    value={form.data.ledger_account_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'ledger_account_id',
                                            e.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Elige una cuenta</option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.code} · {account.name}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                        </div>
                        <Label text="Al corte">
                            <input
                                type="date"
                                max={today}
                                className={field}
                                value={form.data.as_of}
                                onChange={(e) =>
                                    form.setData('as_of', e.target.value)
                                }
                                required
                            />
                        </Label>
                        <Label text="Contra quién / qué">
                            <input
                                className={field}
                                value={form.data.counterpart}
                                onChange={(e) =>
                                    form.setData('counterpart', e.target.value)
                                }
                                placeholder="Ej. Estado de cuenta de la afiliada"
                                required
                            />
                        </Label>
                        <Label text="Saldo de soporte (Q)">
                            <input
                                className={field}
                                inputMode="decimal"
                                value={form.data.supporting_balance}
                                onChange={(e) =>
                                    form.setData(
                                        'supporting_balance',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                        </Label>
                        <div className="md:col-span-4">
                            <Label text="Notas">
                                <input
                                    className={field}
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </Label>
                        </div>
                        <button className={primary} disabled={form.processing}>
                            Iniciar
                        </button>
                    </form>
                    <Errors errors={form.errors} />
                </Card>
            )}

            {reconciliations.length === 0 ? (
                <Card title="Conciliaciones">
                    <Empty>Aún no hay conciliaciones de cuentas.</Empty>
                </Card>
            ) : (
                reconciliations.map((item) => {
                    const balanced = item.difference_cents === 0;
                    const expanded = open === item.id;

                    return (
                        <Card
                            key={item.id}
                            title={`${item.account} · al ${item.as_of}`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex flex-wrap items-center gap-3 text-sm">
                                    {statusBadge(item.status)}
                                    <span className="text-slate-500">
                                        Contra: {item.counterpart} · Prepara:{' '}
                                        {item.prepared_by ?? '—'}
                                    </span>
                                </div>
                                <button
                                    className="text-sm font-semibold text-[#640000]"
                                    onClick={() =>
                                        setOpen(expanded ? null : item.id)
                                    }
                                >
                                    {expanded ? 'Ocultar' : 'Ver detalle'}
                                </button>
                            </div>
                            <div className="mt-4 grid gap-3 text-sm sm:grid-cols-4">
                                <div>
                                    <p className="text-xs text-slate-500 uppercase">
                                        Saldo contable
                                    </p>
                                    <p className="font-semibold">
                                        {money(item.ledger_balance_cents)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-slate-500 uppercase">
                                        Saldo de soporte
                                    </p>
                                    <p className="font-semibold">
                                        {money(item.supporting_balance_cents)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-slate-500 uppercase">
                                        Partidas conciliatorias
                                    </p>
                                    <p className="font-semibold">
                                        {money(item.items_cents)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-slate-500 uppercase">
                                        Diferencia
                                    </p>
                                    <p
                                        className={`font-semibold ${balanced ? 'text-emerald-700' : 'text-red-700'}`}
                                    >
                                        {money(item.difference_cents)}
                                    </p>
                                </div>
                            </div>
                            {expanded && (
                                <div className="mt-4 border-t border-slate-100 pt-4">
                                    {item.items.length === 0 ? (
                                        <Empty>
                                            Sin partidas. Agrega las que
                                            explican la diferencia.
                                        </Empty>
                                    ) : (
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr>
                                                    {[
                                                        'Tipo',
                                                        'Descripción',
                                                        'Monto',
                                                        '',
                                                    ].map((title) => (
                                                        <th
                                                            key={title}
                                                            className={head}
                                                        >
                                                            {title}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {item.items.map((row) => (
                                                    <tr
                                                        key={row.id}
                                                        className="border-t border-slate-100"
                                                    >
                                                        <td className={cell}>
                                                            {row.kind_label}
                                                        </td>
                                                        <td className={cell}>
                                                            {row.description}
                                                        </td>
                                                        <td className={cell}>
                                                            {money(
                                                                row.amount_cents,
                                                            )}
                                                        </td>
                                                        <td className={cell}>
                                                            {item.status ===
                                                                'draft' &&
                                                                canPrepare && (
                                                                    <button
                                                                        aria-label="Retirar partida"
                                                                        onClick={() =>
                                                                            router.delete(
                                                                                `${base}/${item.id}/items/${row.id}`,
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <X className="size-4 text-red-700" />
                                                                    </button>
                                                                )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                    {item.status === 'draft' && canPrepare && (
                                        <ItemForm
                                            base={base}
                                            reconciliation={item}
                                            kinds={kinds}
                                        />
                                    )}
                                </div>
                            )}
                            <div className="mt-4 flex flex-wrap gap-2">
                                {item.status === 'draft' && canPrepare && (
                                    <>
                                        <button
                                            className={secondary}
                                            onClick={() =>
                                                router.post(
                                                    `${base}/${item.id}/refresh`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RefreshCw className="size-4" />{' '}
                                            Actualizar saldo
                                        </button>
                                        <button
                                            className={primary}
                                            disabled={!balanced}
                                            onClick={() =>
                                                router.post(
                                                    `${base}/${item.id}/reconcile`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <CheckCircle2 className="size-4" />{' '}
                                            Cerrar conciliación
                                        </button>
                                    </>
                                )}
                                {item.status === 'reconciled' && canReview && (
                                    <button
                                        className={primary}
                                        onClick={() =>
                                            router.post(
                                                `${base}/${item.id}/review`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Aprobar revisión
                                    </button>
                                )}
                                {item.status === 'reconciled' && userId > 0 && (
                                    <p className="self-center text-xs text-slate-500">
                                        La revisión debe hacerla una persona
                                        distinta de quien preparó.
                                    </p>
                                )}
                            </div>
                        </Card>
                    );
                })
            )}
        </PageShell>
    );
}
