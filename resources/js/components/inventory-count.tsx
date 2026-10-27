import { router, useForm } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import { Badge, cell, head, Label } from '@/components/accounting-page';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';

export type InventoryCount = {
    id: number;
    count_date: string;
    status: string;
    notes: string | null;
    lines: {
        id: number;
        item_id: number;
        code: string;
        name: string;
        unit: string;
        system: number;
        counted: number | null;
        difference: number | null;
        unit_cost_cents: number;
        difference_cents: number;
    }[];
    difference_cents: number;
};

export type InventoryCountSummary = {
    id: number;
    count_date: string;
    status: string;
    lines: number;
    counted: number;
    difference_cents: number;
};

const quantity = (value: number) =>
    new Intl.NumberFormat('es-GT', { maximumFractionDigits: 4 }).format(value);

/** Year-end physical count: start it, write down what was counted and apply the differences. */
export function InventoryCountCard({
    base,
    count,
    counts,
    canManage,
    today,
}: {
    base: string;
    count: InventoryCount | null;
    counts: InventoryCountSummary[];
    canManage: boolean;
    today: string;
}) {
    const start = useForm({ count_date: today, notes: '' });
    const [counted, setCounted] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            (count?.lines ?? []).map((line) => [
                line.id,
                line.counted === null ? '' : String(line.counted),
            ]),
        ),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Card title="Toma física de inventario">
            {count === null ? (
                <>
                    <p className="mb-3 text-sm text-slate-500">
                        Al cierre del año cuenta lo que hay en bodega. El
                        sistema compara con el kardex a esa fecha y, al
                        aplicarla, registra cada diferencia como ajuste con su
                        asiento. Así el libro de inventarios muestra lo contado.
                    </p>
                    {canManage && (
                        <form
                            className="grid gap-3 md:grid-cols-[180px_1fr_auto]"
                            onSubmit={(event) => {
                                event.preventDefault();
                                start.post(`${base}/counts`, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Label text="Fecha del conteo">
                                <input
                                    type="date"
                                    max={today}
                                    className={field}
                                    value={start.data.count_date}
                                    onChange={(e) =>
                                        start.setData(
                                            'count_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Notas (opcional)">
                                <input
                                    className={field}
                                    placeholder="Conteo de cierre con el encargado de bodega"
                                    value={start.data.notes}
                                    onChange={(e) =>
                                        start.setData('notes', e.target.value)
                                    }
                                />
                            </Label>
                            <button
                                className={`${primary} self-end`}
                                disabled={start.processing}
                            >
                                <ClipboardCheck className="size-4" /> Iniciar
                                toma física
                            </button>
                            <div className="md:col-span-3">
                                <Errors errors={start.errors} />
                            </div>
                        </form>
                    )}
                </>
            ) : (
                <>
                    <p className="mb-3 text-sm">
                        Toma física del <strong>{count.count_date}</strong>
                        {count.notes && ` · ${count.notes}`}. Escribe lo
                        contado; deja en blanco lo que aún no cuentas.
                    </p>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px]">
                            <thead>
                                <tr>
                                    {[
                                        'Producto',
                                        'Según kardex',
                                        'Contado',
                                        'Diferencia',
                                        'Valor de la diferencia',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {count.lines.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {line.code} {line.name}
                                        </td>
                                        <td className={cell}>
                                            {quantity(line.system)} {line.unit}
                                        </td>
                                        <td className={cell}>
                                            <input
                                                aria-label={`Contado de ${line.name}`}
                                                inputMode="decimal"
                                                disabled={!canManage}
                                                className={`${field} !mt-0 w-32`}
                                                value={counted[line.id] ?? ''}
                                                onChange={(e) =>
                                                    setCounted({
                                                        ...counted,
                                                        [line.id]:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </td>
                                        <td className={cell}>
                                            {line.difference === null
                                                ? '—'
                                                : quantity(line.difference)}
                                        </td>
                                        <td className={cell}>
                                            {line.difference_cents === 0 ? (
                                                '—'
                                            ) : (
                                                <Badge
                                                    tone={
                                                        line.difference_cents >
                                                        0
                                                            ? 'green'
                                                            : 'red'
                                                    }
                                                >
                                                    {money(
                                                        line.difference_cents,
                                                    )}
                                                </Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-sm font-semibold">
                        Diferencia neta: {money(count.difference_cents)}
                    </p>
                    <Errors errors={errors} />
                    {canManage && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={secondary}
                                onClick={() =>
                                    router.put(
                                        `${base}/counts/${count.id}`,
                                        { counted },
                                        {
                                            preserveScroll: true,
                                            onError: setErrors,
                                        },
                                    )
                                }
                            >
                                Guardar conteo
                            </button>
                            <button
                                type="button"
                                className={primary}
                                onClick={() =>
                                    router.post(
                                        `${base}/counts/${count.id}/apply`,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onError: setErrors,
                                        },
                                    )
                                }
                            >
                                Aplicar diferencias
                            </button>
                            <button
                                type="button"
                                className="text-xs font-semibold text-[#8f1f30] hover:underline"
                                onClick={() =>
                                    router.delete(
                                        `${base}/counts/${count.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Eliminar toma física
                            </button>
                        </div>
                    )}
                </>
            )}
            {counts.filter((row) => row.status === 'applied').length > 0 && (
                <ul className="mt-4 space-y-1 text-sm text-slate-600">
                    {counts
                        .filter((row) => row.status === 'applied')
                        .map((row) => (
                            <li key={row.id}>
                                Toma física del {row.count_date} · {row.lines}{' '}
                                producto(s) · diferencia{' '}
                                {money(row.difference_cents)}
                            </li>
                        ))}
                </ul>
            )}
        </Card>
    );
}
