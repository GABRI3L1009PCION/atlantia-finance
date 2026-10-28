import { router, useForm } from '@inertiajs/react';
import { Factory, Plus, Trash2 } from 'lucide-react';
import {
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
} from '@/components/accounting-ui';

type Recipe = {
    id: number;
    product_id: number;
    product: string;
    unit: string;
    labor_cents: number;
    overhead_cents: number;
    components: {
        item_id: number;
        item: string;
        unit: string;
        quantity: number;
        unit_cost_cents: number;
        cost_cents: number;
    }[];
    standard_cost_cents: number;
};

type Props = {
    company: Company;
    recipes: Recipe[];
    items: { id: number; name: string; unit: string }[];
    production: {
        orders: {
            id: number;
            date: string;
            product: string;
            quantity: number;
            unit: string;
            materials_cents: number;
            labor_cents: number;
            overhead_cents: number;
            total_cents: number;
            unit_cost_cents: number;
        }[];
        totals: {
            materials_cents: number;
            labor_cents: number;
            overhead_cents: number;
            total_cents: number;
        };
    };
    filters: { from: string; to: string };
    canManage: boolean;
    today: string;
};

export default function Production({
    company,
    recipes,
    items,
    production,
    filters,
    canManage,
    today,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/production`;
    const recipe = useForm({
        product_id: String(items[0]?.id ?? ''),
        labor: '',
        overhead: '',
        components: [{ item_id: String(items[1]?.id ?? ''), quantity: '1' }],
    });
    const order = useForm({
        recipe_id: String(recipes[0]?.id ?? ''),
        quantity: '',
        produced_on: today,
        reference: '',
    });

    return (
        <PageShell
            company={company}
            title="Producción y costos"
            subtitle="Recetas, órdenes de producción y costo de lo fabricado"
            icon={Factory}
        >
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat
                    label="Materia prima consumida"
                    value={money(production.totals.materials_cents)}
                />
                <Stat
                    label="Mano de obra aplicada"
                    value={money(production.totals.labor_cents)}
                />
                <Stat
                    label="Costos indirectos aplicados"
                    value={money(production.totals.overhead_cents)}
                />
                <Stat
                    label="Costo de producción"
                    value={money(production.totals.total_cents)}
                />
            </div>

            <Card title="Recetas">
                {recipes.length === 0 ? (
                    <Empty>
                        Sin recetas. Una receta dice qué materias primas lleva
                        una unidad de producto y cuánto de mano de obra y costos
                        indirectos se le aplica.
                    </Empty>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2">
                        {recipes.map((row) => (
                            <article
                                key={row.id}
                                className="rounded-xl border border-slate-200 p-3 text-sm"
                            >
                                <p className="font-semibold">
                                    {row.product} · costo estándar{' '}
                                    {money(row.standard_cost_cents)} por{' '}
                                    {row.unit}
                                </p>
                                <ul className="mt-1 text-slate-600">
                                    {row.components.map((component) => (
                                        <li key={component.item_id}>
                                            {component.quantity}{' '}
                                            {component.unit} de {component.item}{' '}
                                            · {money(component.cost_cents)}
                                        </li>
                                    ))}
                                    <li>
                                        Mano de obra {money(row.labor_cents)} ·
                                        indirectos {money(row.overhead_cents)}
                                    </li>
                                </ul>
                            </article>
                        ))}
                    </div>
                )}
                {canManage && items.length > 1 && (
                    <form
                        className="mt-4 space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            recipe.post(`${base}/recipes`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="grid gap-3 md:grid-cols-3">
                            <Label text="Producto que se fabrica">
                                <select
                                    className={field}
                                    value={recipe.data.product_id}
                                    onChange={(e) =>
                                        recipe.setData(
                                            'product_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {items.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label text="Mano de obra por unidad (Q)">
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    value={recipe.data.labor}
                                    onChange={(e) =>
                                        recipe.setData('labor', e.target.value)
                                    }
                                />
                            </Label>
                            <Label text="Costos indirectos por unidad (Q)">
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    value={recipe.data.overhead}
                                    onChange={(e) =>
                                        recipe.setData(
                                            'overhead',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Label>
                        </div>
                        {recipe.data.components.map((component, index) => (
                            <div
                                key={index}
                                className="grid gap-3 md:grid-cols-[2fr_1fr_auto]"
                            >
                                <Label text="Materia prima">
                                    <select
                                        className={field}
                                        value={component.item_id}
                                        onChange={(e) =>
                                            recipe.setData(
                                                'components',
                                                recipe.data.components.map(
                                                    (row, i) =>
                                                        i === index
                                                            ? {
                                                                  ...row,
                                                                  item_id:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : row,
                                                ),
                                            )
                                        }
                                    >
                                        {items.map((item) => (
                                            <option
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {item.name}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                                <Label text="Cantidad por unidad">
                                    <input
                                        className={field}
                                        inputMode="decimal"
                                        value={component.quantity}
                                        onChange={(e) =>
                                            recipe.setData(
                                                'components',
                                                recipe.data.components.map(
                                                    (row, i) =>
                                                        i === index
                                                            ? {
                                                                  ...row,
                                                                  quantity:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : row,
                                                ),
                                            )
                                        }
                                    />
                                </Label>
                                <button
                                    type="button"
                                    aria-label="Quitar materia prima"
                                    className={`${secondary} self-end`}
                                    onClick={() =>
                                        recipe.setData(
                                            'components',
                                            recipe.data.components.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={secondary}
                                onClick={() =>
                                    recipe.setData('components', [
                                        ...recipe.data.components,
                                        {
                                            item_id: String(items[0]?.id ?? ''),
                                            quantity: '1',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" /> Materia prima
                            </button>
                            <button
                                className={primary}
                                disabled={recipe.processing}
                            >
                                Guardar receta
                            </button>
                        </div>
                        <Errors errors={recipe.errors} />
                    </form>
                )}
            </Card>

            {canManage && recipes.length > 0 && (
                <Card title="Registrar producción">
                    <form
                        className="grid gap-3 md:grid-cols-[2fr_1fr_1fr_1fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            order.post(`${base}/orders`, {
                                preserveScroll: true,
                                onSuccess: () => order.reset('quantity'),
                            });
                        }}
                    >
                        <Label text="Receta">
                            <select
                                className={field}
                                value={order.data.recipe_id}
                                onChange={(e) =>
                                    order.setData('recipe_id', e.target.value)
                                }
                            >
                                {recipes.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.product}
                                    </option>
                                ))}
                            </select>
                        </Label>
                        <Label text="Unidades producidas">
                            <input
                                className={field}
                                inputMode="decimal"
                                value={order.data.quantity}
                                onChange={(e) =>
                                    order.setData('quantity', e.target.value)
                                }
                            />
                        </Label>
                        <Label text="Fecha">
                            <input
                                type="date"
                                max={today}
                                className={field}
                                value={order.data.produced_on}
                                onChange={(e) =>
                                    order.setData('produced_on', e.target.value)
                                }
                            />
                        </Label>
                        <Label text="Referencia (opcional)">
                            <input
                                className={field}
                                value={order.data.reference}
                                onChange={(e) =>
                                    order.setData('reference', e.target.value)
                                }
                            />
                        </Label>
                        <button
                            className={`${primary} self-end`}
                            disabled={order.processing}
                        >
                            Producir
                        </button>
                        <div className="md:col-span-5">
                            <Errors errors={order.errors} />
                        </div>
                    </form>
                </Card>
            )}

            <Card title="Órdenes de producción">
                <div className="mb-3 flex flex-wrap items-end gap-3">
                    <Label text="Desde">
                        <input
                            type="date"
                            className={field}
                            defaultValue={filters.from}
                            onBlur={(e) =>
                                router.get(
                                    base,
                                    { ...filters, from: e.target.value },
                                    { preserveState: true },
                                )
                            }
                        />
                    </Label>
                    <Label text="Hasta">
                        <input
                            type="date"
                            className={field}
                            defaultValue={filters.to}
                            onBlur={(e) =>
                                router.get(
                                    base,
                                    { ...filters, to: e.target.value },
                                    { preserveState: true },
                                )
                            }
                        />
                    </Label>
                </div>
                {production.orders.length === 0 ? (
                    <Empty>Sin producción en el período.</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px]">
                            <thead>
                                <tr>
                                    {[
                                        'Orden',
                                        'Fecha',
                                        'Producto',
                                        'Cantidad',
                                        'Materia prima',
                                        'Mano de obra',
                                        'Indirectos',
                                        'Costo total',
                                        'Costo unitario',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {production.orders.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>OP-{row.id}</td>
                                        <td className={cell}>{row.date}</td>
                                        <td className={cell}>{row.product}</td>
                                        <td className={cell}>
                                            {row.quantity} {row.unit}
                                        </td>
                                        <td className={cell}>
                                            {money(row.materials_cents)}
                                        </td>
                                        <td className={cell}>
                                            {money(row.labor_cents)}
                                        </td>
                                        <td className={cell}>
                                            {money(row.overhead_cents)}
                                        </td>
                                        <td className={`${cell} font-semibold`}>
                                            {money(row.total_cents)}
                                        </td>
                                        <td className={cell}>
                                            {money(row.unit_cost_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </PageShell>
    );
}
