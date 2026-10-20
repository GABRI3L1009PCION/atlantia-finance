import { router, useForm } from '@inertiajs/react';
import { Download, Plus, Tags } from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    exportUrl,
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

type Center = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    lines: number;
};
type ReportRow = {
    code: string | null;
    name: string;
    income_cents: number;
    expense_cents: number;
    result_cents: number;
};

type Props = {
    company: Company;
    centers: Center[];
    report: {
        rows: ReportRow[];
        totals: {
            income_cents: number;
            expense_cents: number;
            result_cents: number;
        };
    };
    filters: { from: string; to: string };
    canManage: boolean;
    canExport: boolean;
};

export default function CostCenters({
    company,
    centers,
    report,
    filters,
    canManage,
    canExport,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/cost-centers`;
    const form = useForm({ code: '', name: '', description: '' });
    const [range, setRange] = useState(filters);

    return (
        <PageShell
            company={company}
            title="Centros de costo"
            subtitle="Catálogo y resultado por centro"
            icon={Tags}
            actions={
                canExport && (
                    <>
                        <a
                            className={secondary}
                            href={exportUrl(`${base}/export`, {
                                ...range,
                                format: 'xlsx',
                            })}
                        >
                            <Download className="size-4" /> Excel
                        </a>
                        <a
                            className={secondary}
                            href={exportUrl(`${base}/export`, {
                                ...range,
                                format: 'pdf',
                            })}
                        >
                            <Download className="size-4" /> PDF
                        </a>
                    </>
                )
            }
        >
            <Card title="Resultado por centro de costo">
                <form
                    className="mb-4 flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(base, range, { preserveState: true });
                    }}
                >
                    <Label text="Desde">
                        <input
                            type="date"
                            className={field}
                            value={range.from}
                            onChange={(e) =>
                                setRange({ ...range, from: e.target.value })
                            }
                        />
                    </Label>
                    <Label text="Hasta">
                        <input
                            type="date"
                            className={field}
                            value={range.to}
                            onChange={(e) =>
                                setRange({ ...range, to: e.target.value })
                            }
                        />
                    </Label>
                    <button className={primary}>Consultar</button>
                </form>
                {report.rows.length === 0 ? (
                    <Empty>
                        No hay ingresos ni gastos contabilizados en el período.
                    </Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[560px]">
                            <thead>
                                <tr>
                                    {[
                                        'Centro de costo',
                                        'Ingresos',
                                        'Gastos',
                                        'Resultado',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {report.rows.map((row) => (
                                    <tr
                                        key={row.code ?? 'none'}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {row.code ? `${row.code} · ` : ''}
                                            {row.name}
                                        </td>
                                        <td className={cell}>
                                            {money(row.income_cents)}
                                        </td>
                                        <td className={cell}>
                                            {money(row.expense_cents)}
                                        </td>
                                        <td
                                            className={`${cell} font-semibold ${row.result_cents < 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                        >
                                            {money(row.result_cents)}
                                        </td>
                                    </tr>
                                ))}
                                <tr className="border-t-2 border-slate-300 font-semibold">
                                    <td className={cell}>Total</td>
                                    <td className={cell}>
                                        {money(report.totals.income_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(report.totals.expense_cents)}
                                    </td>
                                    <td className={cell}>
                                        {money(report.totals.result_cents)}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card title="Catálogo">
                {canManage && (
                    <form
                        className="mb-5 grid gap-3 md:grid-cols-[160px_1fr_1fr_auto] md:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(base, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Label text="Código">
                            <input
                                className={field}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                maxLength={30}
                                required
                            />
                        </Label>
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
                        <Label text="Descripción">
                            <input
                                className={field}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                        </Label>
                        <button className={primary} disabled={form.processing}>
                            <Plus className="size-4" /> Crear
                        </button>
                    </form>
                )}
                <Errors errors={form.errors} />
                {centers.length === 0 ? (
                    <Empty>
                        Sin catálogo todavía: los asientos aceptan un centro de
                        costo libre. Al crear el primero, solo se permitirán los
                        del catálogo.
                    </Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[560px]">
                            <thead>
                                <tr>
                                    {[
                                        'Código',
                                        'Nombre',
                                        'Líneas',
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
                                {centers.map((center) => (
                                    <tr
                                        key={center.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={`${cell} font-mono`}>
                                            {center.code}
                                        </td>
                                        <td className={cell}>
                                            {center.name}
                                            <p className="text-xs text-slate-500">
                                                {center.description}
                                            </p>
                                        </td>
                                        <td className={cell}>{center.lines}</td>
                                        <td className={cell}>
                                            {center.is_active ? (
                                                <Badge tone="green">
                                                    Activo
                                                </Badge>
                                            ) : (
                                                <Badge>Inactivo</Badge>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            {canManage && (
                                                <button
                                                    className="text-xs font-semibold text-[#640000]"
                                                    onClick={() =>
                                                        router.patch(
                                                            `${base}/${center.id}`,
                                                            {
                                                                is_active:
                                                                    !center.is_active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {center.is_active
                                                        ? 'Desactivar'
                                                        : 'Activar'}
                                                </button>
                                            )}
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
