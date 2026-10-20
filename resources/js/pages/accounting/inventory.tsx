import { router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Boxes,
    FilePlus2,
    PackagePlus,
    Plus,
} from 'lucide-react';
import type { FormEvent } from 'react';
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
} from '@/components/accounting-ui';

type Item = {
    id: number;
    code: string;
    name: string;
    unit: string;
    is_active: boolean;
    quantity: number;
    value_cents: number;
    average_cost_cents: number;
    min_stock: number;
    below_minimum: boolean;
};
type Movement = {
    id: number;
    date: string;
    type: string;
    type_label: string;
    inbound: boolean;
    quantity: number;
    unit_cost_cents: number;
    total_cents: number;
    balance: number;
    balance_value_cents: number;
    reference: string | null;
    memo: string | null;
    journal_entry_id: number | null;
};

type Props = {
    company: Company;
    items: Item[];
    selectedItem: number | null;
    kardex: Movement[];
    reconciliation: {
        kardex_cents: number;
        ledger_cents: number;
        difference_cents: number;
    };
    types: Record<string, string>;
    canManage: boolean;
    today: string;
};

const quantity = (value: number) =>
    new Intl.NumberFormat('es-GT', { maximumFractionDigits: 4 }).format(value);

export default function Inventory({
    company,
    items,
    selectedItem,
    kardex,
    reconciliation,
    types,
    canManage,
    today,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/inventory`;
    const current = items.find((item) => item.id === selectedItem) ?? null;
    const itemForm = useForm({
        code: '',
        name: '',
        unit: 'unidad',
        min_stock: '',
    });
    const movementForm = useForm({
        inventory_item_id: String(selectedItem ?? ''),
        date: today,
        type: 'purchase',
        quantity: '',
        unit_cost: '',
        reference: '',
        memo: '',
    });
    const inbound = ['opening', 'purchase', 'adjustment_in'].includes(
        movementForm.data.type,
    );
    const pendingCosts = kardex.filter(
        (movement) => !movement.inbound && movement.journal_entry_id === null,
    ).length;

    const saveItem = (event: FormEvent) => {
        event.preventDefault();
        itemForm.post(`${base}/items`, {
            preserveScroll: true,
            onSuccess: () => itemForm.reset('code', 'name', 'min_stock'),
        });
    };
    const saveMovement = (event: FormEvent) => {
        event.preventDefault();
        movementForm.transform((data) => ({
            ...data,
            inventory_item_id: String(selectedItem ?? data.inventory_item_id),
        }));
        movementForm.post(`${base}/movements`, {
            preserveScroll: true,
            onSuccess: () =>
                movementForm.reset(
                    'quantity',
                    'unit_cost',
                    'reference',
                    'memo',
                ),
        });
    };

    return (
        <PageShell
            company={company}
            title="Inventario y kardex"
            subtitle="Existencias por producto con costo promedio ponderado"
            icon={Boxes}
            actions={
                canManage && (
                    <button
                        type="button"
                        className={primary}
                        onClick={() =>
                            router.post(
                                `${base}/costs`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <FilePlus2 className="size-4" /> Generar asientos de
                        costo
                    </button>
                )
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="Productos" value={items.length} />
                <Stat
                    label="Valor según kardex"
                    value={money(reconciliation.kardex_cents)}
                />
                <Stat
                    label="Inventarios en el Mayor"
                    value={money(reconciliation.ledger_cents)}
                />
                <Stat
                    label="Diferencia kardex vs Mayor"
                    value={money(reconciliation.difference_cents)}
                    tone={
                        reconciliation.difference_cents === 0 ? 'good' : 'bad'
                    }
                />
            </div>

            {reconciliation.difference_cents !== 0 && (
                <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    El kardex y la cuenta de Inventarios del Mayor no coinciden.
                    Las compras se contabilizan desde Compras y la existencia
                    inicial desde el asiento de apertura; revisa que cada
                    movimiento tenga su asiento.
                </p>
            )}

            <div className="grid gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">
                <div className="space-y-5">
                    <Card title="Productos">
                        {items.length === 0 ? (
                            <Empty>
                                Aún no hay productos. Registra el primero.
                            </Empty>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {items.map((item) => (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.get(
                                                    base,
                                                    { item: item.id },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className={`flex w-full items-center justify-between gap-3 px-2 py-2.5 text-left ${item.id === selectedItem ? 'rounded-lg bg-[#f6e6e4]' : 'hover:bg-slate-50'}`}
                                        >
                                            <span>
                                                <b className="block text-sm">
                                                    {item.name}
                                                </b>
                                                <span className="text-xs text-slate-500">
                                                    {item.code} ·{' '}
                                                    {quantity(item.quantity)}{' '}
                                                    {item.unit}
                                                </span>
                                            </span>
                                            <span className="text-right text-sm">
                                                {money(item.value_cents)}
                                                {item.below_minimum && (
                                                    <span className="block">
                                                        <Badge tone="amber">
                                                            Bajo mínimo
                                                        </Badge>
                                                    </span>
                                                )}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {canManage && (
                        <Card title="Nuevo producto">
                            <form onSubmit={saveItem} className="grid gap-3">
                                <Label text="Código (opcional)">
                                    <input
                                        className={field}
                                        value={itemForm.data.code}
                                        maxLength={40}
                                        onChange={(e) =>
                                            itemForm.setData(
                                                'code',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <Label text="Nombre">
                                    <input
                                        className={field}
                                        value={itemForm.data.name}
                                        required
                                        onChange={(e) =>
                                            itemForm.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <div className="grid grid-cols-2 gap-3">
                                    <Label text="Unidad">
                                        <input
                                            className={field}
                                            value={itemForm.data.unit}
                                            maxLength={20}
                                            onChange={(e) =>
                                                itemForm.setData(
                                                    'unit',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Existencia mínima">
                                        <input
                                            className={field}
                                            inputMode="decimal"
                                            value={itemForm.data.min_stock}
                                            onChange={(e) =>
                                                itemForm.setData(
                                                    'min_stock',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                </div>
                                <button
                                    className={primary}
                                    disabled={itemForm.processing}
                                >
                                    <PackagePlus className="size-4" /> Registrar
                                    producto
                                </button>
                                <Errors errors={itemForm.errors} />
                            </form>
                        </Card>
                    )}
                </div>

                <div className="space-y-5">
                    {canManage && current && (
                        <Card title={`Registrar movimiento · ${current.name}`}>
                            <form
                                onSubmit={saveMovement}
                                className="grid gap-3 md:grid-cols-4"
                            >
                                <Label text="Tipo">
                                    <select
                                        className={field}
                                        value={movementForm.data.type}
                                        onChange={(e) =>
                                            movementForm.setData(
                                                'type',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {Object.entries(types).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Label>
                                <Label text="Fecha">
                                    <input
                                        type="date"
                                        max={today}
                                        className={field}
                                        value={movementForm.data.date}
                                        onChange={(e) =>
                                            movementForm.setData(
                                                'date',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Label>
                                <Label text={`Cantidad (${current.unit})`}>
                                    <input
                                        className={field}
                                        inputMode="decimal"
                                        value={movementForm.data.quantity}
                                        onChange={(e) =>
                                            movementForm.setData(
                                                'quantity',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Label>
                                <Label
                                    text={
                                        inbound
                                            ? 'Costo unitario (Q)'
                                            : 'Costo unitario (automático)'
                                    }
                                >
                                    <input
                                        className={field}
                                        inputMode="decimal"
                                        disabled={!inbound}
                                        value={
                                            inbound
                                                ? movementForm.data.unit_cost
                                                : ''
                                        }
                                        onChange={(e) =>
                                            movementForm.setData(
                                                'unit_cost',
                                                e.target.value,
                                            )
                                        }
                                        required={inbound}
                                    />
                                </Label>
                                <Label text="Referencia">
                                    <input
                                        className={field}
                                        maxLength={80}
                                        value={movementForm.data.reference}
                                        onChange={(e) =>
                                            movementForm.setData(
                                                'reference',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                                <div className="md:col-span-2">
                                    <Label text="Nota">
                                        <input
                                            className={field}
                                            maxLength={255}
                                            value={movementForm.data.memo}
                                            onChange={(e) =>
                                                movementForm.setData(
                                                    'memo',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                </div>
                                <div className="flex items-end">
                                    <button
                                        className={`${primary} w-full`}
                                        disabled={movementForm.processing}
                                    >
                                        <Plus className="size-4" /> Registrar
                                    </button>
                                </div>
                            </form>
                            <Errors errors={movementForm.errors} />
                        </Card>
                    )}

                    <Card
                        title={
                            current
                                ? `Kardex · ${current.code} ${current.name}`
                                : 'Kardex'
                        }
                    >
                        {pendingCosts > 0 && (
                            <p className="mb-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-900">
                                {pendingCosts} salida(s) sin asiento de costo.
                                Usa "Generar asientos de costo".
                            </p>
                        )}
                        {!current ? (
                            <Empty>Elige un producto para ver su kardex.</Empty>
                        ) : kardex.length === 0 ? (
                            <Empty>
                                Este producto aún no tiene movimientos.
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[860px]">
                                    <thead>
                                        <tr className="border-b border-slate-200">
                                            {[
                                                'Fecha',
                                                'Movimiento',
                                                'Cantidad',
                                                'Costo unit.',
                                                'Total',
                                                'Saldo',
                                                'Valor saldo',
                                                'Asiento',
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
                                        {kardex.map((movement) => (
                                            <tr
                                                key={movement.id}
                                                className="border-b border-slate-100"
                                            >
                                                <td className={cell}>
                                                    {movement.date}
                                                </td>
                                                <td className={cell}>
                                                    {movement.type_label}
                                                    {movement.reference && (
                                                        <span className="block text-xs text-slate-500">
                                                            {movement.reference}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    <span
                                                        className={
                                                            movement.inbound
                                                                ? 'text-emerald-700'
                                                                : 'text-red-700'
                                                        }
                                                    >
                                                        {movement.inbound
                                                            ? '+'
                                                            : '−'}
                                                        {quantity(
                                                            movement.quantity,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        movement.unit_cost_cents,
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        movement.total_cents,
                                                    )}
                                                </td>
                                                <td
                                                    className={`${cell} font-semibold`}
                                                >
                                                    {quantity(movement.balance)}
                                                </td>
                                                <td className={cell}>
                                                    {money(
                                                        movement.balance_value_cents,
                                                    )}
                                                </td>
                                                <td className={cell}>
                                                    {movement.inbound ? (
                                                        <span className="text-xs text-slate-400">
                                                            Por compras /
                                                            apertura
                                                        </span>
                                                    ) : movement.journal_entry_id ? (
                                                        <Badge tone="green">
                                                            Con asiento
                                                        </Badge>
                                                    ) : (
                                                        <Badge tone="amber">
                                                            Pendiente
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <p className="mt-3 flex items-center gap-2 text-xs text-slate-500">
                            <ArrowLeftRight className="size-3" /> Costo promedio
                            ponderado: las salidas se valúan al costo promedio
                            vigente en su fecha.
                        </p>
                    </Card>
                </div>
            </div>
        </PageShell>
    );
}
