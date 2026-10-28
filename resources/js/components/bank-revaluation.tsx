import { router } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { useState } from 'react';
import { Badge, cell, head } from '@/components/accounting-page';
import { Card, field, money, primary } from '@/components/accounting-ui';

export type BankRevaluation = {
    as_of: string;
    rows: {
        id: number;
        bank: string;
        currency: string;
        foreign_cents: number | null;
        source: string;
        rate: string | null;
        ledger_cents: number;
        revalued_cents: number | null;
        difference_cents: number;
    }[];
    net_cents: number;
    entry: { status: string; public_id: string } | null;
};

/** Foreign-currency bank accounts valued at the month's closing rate against their quetzal balance in the ledger. */
export function BankRevaluationCard({
    base,
    month,
    revaluation,
    canManage,
}: {
    base: string;
    month: string;
    revaluation: BankRevaluation;
    canManage: boolean;
}) {
    const [balances, setBalances] = useState<Record<number, string>>({});

    if (revaluation.rows.length === 0) {
        return null;
    }

    return (
        <Card title="Bancos en moneda extranjera">
            <p className="mb-3 text-sm text-slate-500">
                El saldo en dólares o euros sale del último movimiento del
                estado de cuenta del mes; si no lo cargaste, escríbelo. Se
                valora al tipo de cambio del cierre y la diferencia contra el
                Mayor va a diferencial cambiario. No se revierte: cada mes se
                valora de nuevo.
            </p>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[760px]">
                    <thead>
                        <tr>
                            {[
                                'Cuenta',
                                'Saldo en moneda extranjera',
                                'Tipo de cambio',
                                'Valorado',
                                'En el Mayor',
                                'Diferencia',
                            ].map((title) => (
                                <th key={title} className={head}>
                                    {title}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {revaluation.rows.map((row) => (
                            <tr
                                key={row.id}
                                className="border-t border-slate-100"
                            >
                                <td className={cell}>{row.bank}</td>
                                <td className={cell}>
                                    <input
                                        aria-label={`Saldo de ${row.bank}`}
                                        inputMode="decimal"
                                        disabled={
                                            !canManage ||
                                            revaluation.entry !== null
                                        }
                                        placeholder={
                                            row.foreign_cents === null
                                                ? 'Escribe el saldo'
                                                : (
                                                      row.foreign_cents / 100
                                                  ).toFixed(2)
                                        }
                                        className={`${field} !mt-0 w-36`}
                                        value={balances[row.id] ?? ''}
                                        onChange={(event) =>
                                            setBalances({
                                                ...balances,
                                                [row.id]: event.target.value,
                                            })
                                        }
                                    />
                                    <span className="ml-1 text-xs text-slate-500">
                                        {row.currency}
                                    </span>
                                </td>
                                <td className={cell}>{row.rate ?? '—'}</td>
                                <td className={cell}>
                                    {row.revalued_cents === null
                                        ? '—'
                                        : money(row.revalued_cents)}
                                </td>
                                <td className={cell}>
                                    {money(row.ledger_cents)}
                                </td>
                                <td className={cell}>
                                    {row.difference_cents === 0 ? (
                                        <Badge tone="green">Cuadra</Badge>
                                    ) : (
                                        <Badge
                                            tone={
                                                row.difference_cents > 0
                                                    ? 'green'
                                                    : 'red'
                                            }
                                        >
                                            {money(row.difference_cents)}
                                        </Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {revaluation.entry ? (
                <p className="mt-3 text-sm text-emerald-700">
                    La revaluación de bancos de este mes ya está registrada.
                </p>
            ) : (
                canManage && (
                    <button
                        type="button"
                        className={`${primary} mt-4`}
                        onClick={() =>
                            router.post(
                                `${base}/year-end/bank-revaluation`,
                                { month, balances },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Landmark className="size-4" /> Registrar revaluación de
                        bancos
                    </button>
                )
            )}
        </Card>
    );
}
