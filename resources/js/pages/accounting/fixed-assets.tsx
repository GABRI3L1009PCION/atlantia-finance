import { router, useForm } from '@inertiajs/react';
import { Boxes, CalendarClock, ChevronDown, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
    shortDate,
} from '@/components/accounting-ui';
import { ConfirmDialog } from '@/components/office-admin/kit';

type Asset = {
    id: number;
    code: string;
    name: string;
    category_label: string;
    kind_label: string;
    in_service_on: string;
    cost_cents: number;
    salvage_cents: number;
    useful_life_months: number;
    method_label: string;
    status: string;
    cost_center: string | null;
    accumulated_cents: number;
    book_cents: number;
    month_cents: number;
    months_charged: number;
    disposed_on: string | null;
    disposal_reason: string | null;
};
type Row = {
    month: string;
    amount_cents: number;
    accumulated_cents: number;
    book_cents: number;
    charged: boolean;
};

type Props = {
    company: Company;
    assets: Asset[];
    totals: {
        cost_cents: number;
        accumulated_cents: number;
        book_cents: number;
        month_cents: number;
    };
    categories: Record<string, string>;
    methods: Record<string, string>;
    defaults: {
        method: string;
        lives: Record<string, number>;
        capitalization_minimum_cents: number;
    };
    expenseAccounts: { id: number; code: string; name: string }[];
    costCenters: { code: string; name: string }[];
    canManage: boolean;
    today: string;
};

export default function FixedAssets({
    company,
    assets,
    totals,
    categories,
    methods,
    defaults,
    expenseAccounts,
    costCenters,
    canManage,
    today,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/fixed-assets`;
    const form = useForm({
        code: '',
        name: '',
        category: 'computers',
        acquired_on: today,
        in_service_on: today,
        cost: '',
        salvage: '0.00',
        useful_life_months: '',
        method: '',
        cost_center: '',
    });
    const [month, setMonth] = useState(today.slice(0, 7));
    const [schedule, setSchedule] = useState<{
        id: number;
        rows: Row[];
    } | null>(null);
    const [disposing, setDisposing] = useState<Asset | null>(null);
    const [disposedOn, setDisposedOn] = useState(today);
    const [lossAccount, setLossAccount] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(base, {
            preserveScroll: true,
            onSuccess: () =>
                form.reset('code', 'name', 'cost', 'useful_life_months'),
        });
    };
    const toggleSchedule = async (asset: Asset) => {
        if (schedule?.id === asset.id) {
            setSchedule(null);

            return;
        }

        const response = await fetch(`${base}/${asset.id}/schedule`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        setSchedule({ id: asset.id, rows: (await response.json()).schedule });
    };
    const defaultLife = defaults.lives[form.data.category];

    return (
        <PageShell
            company={company}
            title="Activos fijos"
            subtitle="Registro, depreciación y amortización"
            icon={Boxes}
            actions={
                canManage && (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.post(
                                `${base}/depreciation`,
                                { month },
                                { preserveScroll: true },
                            );
                        }}
                        className="flex items-center gap-2"
                    >
                        <input
                            type="month"
                            value={month}
                            onChange={(event) => setMonth(event.target.value)}
                            className={`${field} !mt-0 w-auto`}
                        />
                        <button className={primary}>
                            <CalendarClock className="size-4" /> Depreciar mes
                        </button>
                    </form>
                )
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat
                    label="Costo de adquisición"
                    value={money(totals.cost_cents)}
                />
                <Stat
                    label="Depreciación acumulada"
                    value={money(totals.accumulated_cents)}
                />
                <Stat
                    label="Valor en libros"
                    value={money(totals.book_cents)}
                    tone="good"
                />
                <Stat
                    label="Cargo estimado del mes"
                    value={money(totals.month_cents)}
                />
            </div>

            {canManage && (
                <Card title="Registrar activo">
                    <form
                        onSubmit={submit}
                        className="grid gap-3 md:grid-cols-4"
                    >
                        <Label text="Código (opcional)">
                            <input
                                className={field}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                maxLength={30}
                            />
                        </Label>
                        <div className="md:col-span-2">
                            <Label text="Nombre">
                                <input
                                    className={field}
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    required
                                />
                            </Label>
                        </div>
                        <Label text="Categoría">
                            <select
                                className={field}
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                            >
                                {Object.entries(categories).map(
                                    ([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Label>
                        <Label text="Fecha de adquisición">
                            <input
                                type="date"
                                max={today}
                                className={field}
                                value={form.data.acquired_on}
                                onChange={(e) =>
                                    form.setData('acquired_on', e.target.value)
                                }
                                required
                            />
                        </Label>
                        <Label text="Entrada en servicio">
                            <input
                                type="date"
                                className={field}
                                value={form.data.in_service_on}
                                onChange={(e) =>
                                    form.setData(
                                        'in_service_on',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                        </Label>
                        <Label text="Costo (Q)">
                            <input
                                inputMode="decimal"
                                className={field}
                                value={form.data.cost}
                                onChange={(e) =>
                                    form.setData('cost', e.target.value)
                                }
                                required
                            />
                        </Label>
                        <Label text="Valor residual (Q)">
                            <input
                                inputMode="decimal"
                                className={field}
                                value={form.data.salvage}
                                onChange={(e) =>
                                    form.setData('salvage', e.target.value)
                                }
                            />
                        </Label>
                        <Label
                            text={`Vida útil en meses (política: ${defaultLife ?? '—'})`}
                        >
                            <input
                                type="number"
                                min={1}
                                className={field}
                                value={form.data.useful_life_months}
                                onChange={(e) =>
                                    form.setData(
                                        'useful_life_months',
                                        e.target.value,
                                    )
                                }
                                placeholder={String(defaultLife ?? '')}
                            />
                        </Label>
                        <Label text="Método">
                            <select
                                className={field}
                                value={form.data.method}
                                onChange={(e) =>
                                    form.setData('method', e.target.value)
                                }
                            >
                                <option value="">
                                    Según política ({methods[defaults.method]})
                                </option>
                                {Object.entries(methods).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </Label>
                        <Label text="Centro de costo">
                            {costCenters.length > 0 ? (
                                <select
                                    className={field}
                                    value={form.data.cost_center}
                                    onChange={(e) =>
                                        form.setData(
                                            'cost_center',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Sin asignar</option>
                                    {costCenters.map((center) => (
                                        <option
                                            key={center.code}
                                            value={center.code}
                                        >
                                            {center.code} · {center.name}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <input
                                    className={field}
                                    value={form.data.cost_center}
                                    onChange={(e) =>
                                        form.setData(
                                            'cost_center',
                                            e.target.value,
                                        )
                                    }
                                    maxLength={30}
                                />
                            )}
                        </Label>
                        <div className="flex items-end">
                            <button
                                className={`${primary} w-full`}
                                disabled={form.processing}
                            >
                                <Plus className="size-4" /> Registrar
                            </button>
                        </div>
                    </form>
                    <Errors errors={form.errors} />
                    {defaults.capitalization_minimum_cents > 0 && (
                        <p className="mt-3 text-xs text-slate-500">
                            Política de capitalización: solo se registran como
                            activo las compras de{' '}
                            {money(defaults.capitalization_minimum_cents)} o
                            más.
                        </p>
                    )}
                </Card>
            )}

            <Card title="Registro de activos">
                {assets.length === 0 ? (
                    <Empty>
                        Aún no hay activos fijos. Registra el primero para
                        calcular su depreciación mensual.
                    </Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[980px]">
                            <thead>
                                <tr className="border-b border-slate-200">
                                    {[
                                        'Activo',
                                        'Categoría',
                                        'Método / vida',
                                        'Costo',
                                        'Acumulada',
                                        'En libros',
                                        'Mes',
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
                                {assets.map((asset) => (
                                    <>
                                        <tr
                                            key={asset.id}
                                            className="border-b border-slate-100"
                                        >
                                            <td className={cell}>
                                                <p className="font-semibold">
                                                    {asset.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {asset.code} · en servicio{' '}
                                                    {shortDate(
                                                        asset.in_service_on,
                                                    )}
                                                    {asset.cost_center
                                                        ? ` · CC ${asset.cost_center}`
                                                        : ''}
                                                </p>
                                            </td>
                                            <td className={cell}>
                                                {asset.category_label}
                                            </td>
                                            <td className={cell}>
                                                {asset.method_label}
                                                <p className="text-xs text-slate-500">
                                                    {asset.useful_life_months}{' '}
                                                    meses ·{' '}
                                                    {asset.months_charged}{' '}
                                                    cargados
                                                </p>
                                            </td>
                                            <td className={cell}>
                                                {money(asset.cost_cents)}
                                            </td>
                                            <td className={cell}>
                                                {money(asset.accumulated_cents)}
                                            </td>
                                            <td
                                                className={`${cell} font-semibold`}
                                            >
                                                {money(asset.book_cents)}
                                            </td>
                                            <td className={cell}>
                                                {asset.status === 'active'
                                                    ? money(asset.month_cents)
                                                    : '—'}
                                            </td>
                                            <td className={cell}>
                                                {asset.status === 'active' && (
                                                    <Badge tone="green">
                                                        Activo
                                                    </Badge>
                                                )}
                                                {asset.status ===
                                                    'disposed' && (
                                                    <Badge tone="red">
                                                        Baja{' '}
                                                        {shortDate(
                                                            asset.disposed_on,
                                                        )}
                                                    </Badge>
                                                )}
                                                {asset.status ===
                                                    'fully_depreciated' && (
                                                    <Badge tone="amber">
                                                        Depreciado
                                                    </Badge>
                                                )}
                                            </td>
                                            <td
                                                className={`${cell} whitespace-nowrap`}
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggleSchedule(asset)
                                                    }
                                                    className="mr-2 inline-flex items-center gap-1 text-xs font-semibold text-[#640000]"
                                                >
                                                    Plan{' '}
                                                    <ChevronDown className="size-3" />
                                                </button>
                                                {canManage &&
                                                    asset.status !==
                                                        'disposed' && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDisposing(
                                                                    asset,
                                                                )
                                                            }
                                                            className="inline-flex items-center gap-1 text-xs font-semibold text-red-700"
                                                        >
                                                            <Trash2 className="size-3" />{' '}
                                                            Dar de baja
                                                        </button>
                                                    )}
                                            </td>
                                        </tr>
                                        {schedule?.id === asset.id && (
                                            <tr key={`${asset.id}-plan`}>
                                                <td
                                                    colSpan={9}
                                                    className="bg-[#fbf8f8] p-3"
                                                >
                                                    <div className="max-h-64 overflow-auto">
                                                        <table className="w-full text-sm">
                                                            <thead>
                                                                <tr>
                                                                    {[
                                                                        'Mes',
                                                                        'Cargo',
                                                                        'Acumulada',
                                                                        'En libros',
                                                                        '',
                                                                    ].map(
                                                                        (
                                                                            title,
                                                                        ) => (
                                                                            <th
                                                                                key={
                                                                                    title
                                                                                }
                                                                                className={
                                                                                    head
                                                                                }
                                                                            >
                                                                                {
                                                                                    title
                                                                                }
                                                                            </th>
                                                                        ),
                                                                    )}
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {schedule.rows.map(
                                                                    (row) => (
                                                                        <tr
                                                                            key={
                                                                                row.month
                                                                            }
                                                                            className="border-t border-slate-100"
                                                                        >
                                                                            <td
                                                                                className={
                                                                                    cell
                                                                                }
                                                                            >
                                                                                {row.month.slice(
                                                                                    0,
                                                                                    7,
                                                                                )}
                                                                            </td>
                                                                            <td
                                                                                className={
                                                                                    cell
                                                                                }
                                                                            >
                                                                                {money(
                                                                                    row.amount_cents,
                                                                                )}
                                                                            </td>
                                                                            <td
                                                                                className={
                                                                                    cell
                                                                                }
                                                                            >
                                                                                {money(
                                                                                    row.accumulated_cents,
                                                                                )}
                                                                            </td>
                                                                            <td
                                                                                className={
                                                                                    cell
                                                                                }
                                                                            >
                                                                                {money(
                                                                                    row.book_cents,
                                                                                )}
                                                                            </td>
                                                                            <td
                                                                                className={
                                                                                    cell
                                                                                }
                                                                            >
                                                                                {row.charged && (
                                                                                    <Badge tone="green">
                                                                                        Cargado
                                                                                    </Badge>
                                                                                )}
                                                                            </td>
                                                                        </tr>
                                                                    ),
                                                                )}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <ConfirmDialog
                open={disposing !== null}
                tone="danger"
                icon={<Trash2 className="size-6" />}
                title="Dar de baja el activo"
                description={
                    disposing
                        ? `Se retirará ${disposing.name} del registro. El asiento de baja queda en borrador para revisión.`
                        : ''
                }
                confirmLabel="Dar de baja"
                noteLabel="Motivo de la baja"
                noteRequired
                onClose={() => setDisposing(null)}
                onConfirm={(reason) => {
                    if (disposing) {
                        router.post(
                            `${base}/${disposing.id}/dispose`,
                            {
                                disposed_on: disposedOn,
                                reason,
                                loss_account_id: lossAccount || null,
                            },
                            {
                                preserveScroll: true,
                                onFinish: () => setDisposing(null),
                            },
                        );
                    }
                }}
            >
                <div className="mt-4 grid gap-3">
                    <Label text="Fecha de baja">
                        <input
                            type="date"
                            max={today}
                            className={field}
                            value={disposedOn}
                            onChange={(e) => setDisposedOn(e.target.value)}
                        />
                    </Label>
                    <Label text="Cuenta de pérdida (si la hay)">
                        <select
                            className={field}
                            value={lossAccount}
                            onChange={(e) => setLossAccount(e.target.value)}
                        >
                            <option value="">Automática</option>
                            {expenseAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.code} · {account.name}
                                </option>
                            ))}
                        </select>
                    </Label>
                </div>
            </ConfirmDialog>
        </PageShell>
    );
}
