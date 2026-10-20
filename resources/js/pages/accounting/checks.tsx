import { router, useForm } from '@inertiajs/react';
import { BookOpen, FileCheck2, Plus, Send, XCircle } from 'lucide-react';
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
import { ConfirmDialog } from '@/components/office-admin/kit';

type Checkbook = {
    id: number;
    account: string;
    series: string | null;
    first_number: number;
    last_number: number;
    next_number: number;
    remaining: number;
    status: string;
};
type Check = {
    id: number;
    label: string;
    account: string;
    payee: string;
    amount_cents: number;
    issued_on: string;
    memo: string | null;
    counter_account: string;
    status: 'issued' | 'cleared' | 'voided';
    status_label: string;
    voided_reason: string | null;
};
type Props = {
    company: Company;
    checkbooks: Checkbook[];
    checks: Check[];
    bankAccounts: {
        id: number;
        bank_name: string;
        account_name: string;
        last_four: string;
    }[];
    accounts: { id: number; code: string; name: string }[];
    canManage: boolean;
    today: string;
};

export default function Checks({
    company,
    checkbooks,
    checks,
    bankAccounts,
    accounts,
    canManage,
    today,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/checks`;
    const book = useForm({
        bank_account_id: '',
        series: '',
        first_number: '',
        last_number: '',
    });
    const check = useForm({
        bank_account_id: '',
        payee: '',
        amount: '',
        issued_on: today,
        memo: '',
        counter_account_id: '',
    });
    const [voiding, setVoiding] = useState<Check | null>(null);
    const bankLabel = (account: Props['bankAccounts'][number]) =>
        `${account.bank_name} · ${account.account_name} ••••${account.last_four}`;
    const tone = (status: Check['status']) =>
        status === 'cleared' ? 'green' : status === 'voided' ? 'red' : 'amber';

    return (
        <PageShell
            company={company}
            title="Cheques"
            subtitle="Chequeras, emisión y control de numeración"
            icon={FileCheck2}
        >
            {canManage && (
                <div className="grid gap-5 xl:grid-cols-2">
                    <Card title="Registrar chequera">
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                book.post(`${base}/checkbooks`, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        book.reset(
                                            'first_number',
                                            'last_number',
                                        ),
                                });
                            }}
                        >
                            <div className="sm:col-span-2">
                                <Label text="Cuenta bancaria">
                                    <select
                                        className={field}
                                        value={book.data.bank_account_id}
                                        onChange={(e) =>
                                            book.setData(
                                                'bank_account_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="">
                                            Elige una cuenta
                                        </option>
                                        {bankAccounts.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {bankLabel(account)}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                            </div>
                            <Label text="Serie">
                                <input
                                    className={field}
                                    maxLength={10}
                                    value={book.data.series}
                                    onChange={(e) =>
                                        book.setData('series', e.target.value)
                                    }
                                />
                            </Label>
                            <span />
                            <Label text="Primer número">
                                <input
                                    type="number"
                                    className={field}
                                    value={book.data.first_number}
                                    onChange={(e) =>
                                        book.setData(
                                            'first_number',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </Label>
                            <Label text="Último número">
                                <input
                                    type="number"
                                    className={field}
                                    value={book.data.last_number}
                                    onChange={(e) =>
                                        book.setData(
                                            'last_number',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </Label>
                            <button
                                className={secondary}
                                disabled={book.processing}
                            >
                                <BookOpen className="size-4" /> Registrar
                                chequera
                            </button>
                        </form>
                        <Errors errors={book.errors} />
                    </Card>

                    <Card title="Emitir cheque">
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                check.post(base, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        check.reset('payee', 'amount', 'memo'),
                                });
                            }}
                        >
                            <div className="sm:col-span-2">
                                <Label text="Cuenta bancaria">
                                    <select
                                        className={field}
                                        value={check.data.bank_account_id}
                                        onChange={(e) =>
                                            check.setData(
                                                'bank_account_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="">
                                            Elige una cuenta
                                        </option>
                                        {bankAccounts.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {bankLabel(account)}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                            </div>
                            <Label text="Beneficiario">
                                <input
                                    className={field}
                                    value={check.data.payee}
                                    onChange={(e) =>
                                        check.setData('payee', e.target.value)
                                    }
                                    required
                                />
                            </Label>
                            <Label text="Monto (Q)">
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    value={check.data.amount}
                                    onChange={(e) =>
                                        check.setData('amount', e.target.value)
                                    }
                                    required
                                />
                            </Label>
                            <Label text="Fecha">
                                <input
                                    type="date"
                                    max={today}
                                    className={field}
                                    value={check.data.issued_on}
                                    onChange={(e) =>
                                        check.setData(
                                            'issued_on',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </Label>
                            <Label text="Cuenta contra la que se registra">
                                <select
                                    className={field}
                                    value={check.data.counter_account_id}
                                    onChange={(e) =>
                                        check.setData(
                                            'counter_account_id',
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
                            <div className="sm:col-span-2">
                                <Label text="Concepto">
                                    <input
                                        className={field}
                                        value={check.data.memo}
                                        onChange={(e) =>
                                            check.setData(
                                                'memo',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                            </div>
                            <button
                                className={primary}
                                disabled={check.processing}
                            >
                                <Plus className="size-4" /> Emitir con el
                                siguiente número
                            </button>
                        </form>
                        <Errors errors={check.errors} />
                    </Card>
                </div>
            )}

            <Card title="Chequeras">
                {checkbooks.length === 0 ? (
                    <Empty>No hay chequeras registradas.</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px]">
                            <thead>
                                <tr>
                                    {[
                                        'Cuenta',
                                        'Serie',
                                        'Rango',
                                        'Siguiente',
                                        'Disponibles',
                                        'Estado',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {checkbooks.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>{item.account}</td>
                                        <td className={cell}>
                                            {item.series ?? '—'}
                                        </td>
                                        <td className={cell}>
                                            {item.first_number} –{' '}
                                            {item.last_number}
                                        </td>
                                        <td className={`${cell} font-semibold`}>
                                            {item.next_number}
                                        </td>
                                        <td className={cell}>
                                            {item.remaining}
                                        </td>
                                        <td className={cell}>
                                            {item.status === 'active' ? (
                                                <Badge tone="green">
                                                    Activa
                                                </Badge>
                                            ) : (
                                                <Badge>Agotada</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card title="Cheques emitidos">
                {checks.length === 0 ? (
                    <Empty>Aún no se han emitido cheques.</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px]">
                            <thead>
                                <tr>
                                    {[
                                        'No.',
                                        'Fecha',
                                        'Beneficiario',
                                        'Cuenta',
                                        'Monto',
                                        'Estado',
                                        '',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {checks.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td
                                            className={`${cell} font-mono font-semibold`}
                                        >
                                            {item.label}
                                        </td>
                                        <td className={cell}>
                                            {item.issued_on}
                                        </td>
                                        <td className={cell}>
                                            {item.payee}
                                            <p className="text-xs text-slate-500">
                                                {item.memo} ·{' '}
                                                {item.counter_account}
                                            </p>
                                        </td>
                                        <td className={cell}>{item.account}</td>
                                        <td className={cell}>
                                            {money(item.amount_cents)}
                                        </td>
                                        <td className={cell}>
                                            <Badge tone={tone(item.status)}>
                                                {item.status_label}
                                            </Badge>
                                            {item.voided_reason && (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {item.voided_reason}
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${cell} whitespace-nowrap`}
                                        >
                                            {canManage &&
                                                item.status === 'issued' && (
                                                    <>
                                                        <button
                                                            className="mr-3 inline-flex items-center gap-1 text-xs font-semibold text-emerald-700"
                                                            onClick={() =>
                                                                router.post(
                                                                    `${base}/${item.id}/clear`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <Send className="size-3" />{' '}
                                                            Cobrado
                                                        </button>
                                                        <button
                                                            className="inline-flex items-center gap-1 text-xs font-semibold text-red-700"
                                                            onClick={() =>
                                                                setVoiding(item)
                                                            }
                                                        >
                                                            <XCircle className="size-3" />{' '}
                                                            Anular
                                                        </button>
                                                    </>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <ConfirmDialog
                open={voiding !== null}
                tone="danger"
                icon={<XCircle className="size-6" />}
                title="Anular cheque"
                description={
                    voiding
                        ? `El cheque ${voiding.label} quedará anulado, su número no podrá reutilizarse y el movimiento bancario se revertirá.`
                        : ''
                }
                confirmLabel="Anular cheque"
                noteLabel="Motivo de la anulación"
                noteRequired
                onClose={() => setVoiding(null)}
                onConfirm={(reason) =>
                    voiding &&
                    router.post(
                        `${base}/${voiding.id}/void`,
                        { reason },
                        {
                            preserveScroll: true,
                            onFinish: () => setVoiding(null),
                        },
                    )
                }
            />
        </PageShell>
    );
}
