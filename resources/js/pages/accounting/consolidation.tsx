import { Head, router, useForm } from '@inertiajs/react';
import { Download, Network, Plus, Trash2 } from 'lucide-react';
import { Badge, cell, Empty, head, Label } from '@/components/accounting-page';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';

type Line = {
    code: string;
    name: string;
    type: string;
    companies: Record<number, number>;
    eliminations: number;
    consolidated: number;
};

type Statement = {
    group: { id: number; name: string };
    from: string;
    to: string;
    companies: { id: number; name: string; ownership_bp: number }[];
    sections: Record<string, { label: string; lines: Line[]; total: number }>;
    non_controlling: { equity_cents: number; result_cents: number };
    result_cents: number;
    balanced: boolean;
    difference_cents: number;
    warnings: string[];
};

type Props = {
    groups: { id: number; name: string; parent: string; members: number }[];
    group: {
        id: number;
        name: string;
        parent_company_id: number;
        investment_account_code: string | null;
        members: { company_id: number; ownership: number }[];
    } | null;
    statement: Statement | null;
    companies: { id: number; name: string }[];
    filters: { from: string; to: string };
    canManage: boolean;
};

export default function Consolidation({
    groups,
    group,
    statement,
    companies,
    filters,
    canManage,
}: Props) {
    const form = useForm({
        name: group?.name ?? '',
        parent_company_id: String(
            group?.parent_company_id ?? companies[0]?.id ?? '',
        ),
        investment_account_code: group?.investment_account_code ?? '',
        members: (group?.members ?? []).map((member) => ({
            company_id: String(member.company_id),
            ownership: String(member.ownership),
        })),
    });
    const visit = (params: Record<string, string | number>) =>
        router.get(
            '/accounting/consolidation',
            { group: group?.id ?? '', ...filters, ...params },
            { preserveState: true },
        );

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Consolidación" />
            <div className="mx-auto max-w-[1500px] space-y-5">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid size-12 place-items-center rounded-2xl bg-[#7d0808] text-white">
                            <Network className="size-6" />
                        </span>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Consolidación de grupos
                            </h1>
                            <p className="text-sm text-slate-500">
                                Estados financieros de varias empresas del mismo
                                grupo, sin lo que se hicieron entre ellas
                            </p>
                        </div>
                    </div>
                    {groups.length > 0 && (
                        <select
                            aria-label="Grupo"
                            className={`${field} !mt-0 w-64`}
                            value={group?.id ?? ''}
                            onChange={(event) =>
                                visit({ group: event.target.value })
                            }
                        >
                            {groups.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.name} · {row.members} empresas
                                </option>
                            ))}
                        </select>
                    )}
                </header>

                {statement && (
                    <Card
                        title={`${statement.group.name} · del ${statement.from} al ${statement.to}`}
                    >
                        <div className="mb-3 flex flex-wrap items-end gap-3">
                            <Label text="Desde">
                                <input
                                    type="date"
                                    className={field}
                                    defaultValue={filters.from}
                                    onBlur={(event) =>
                                        visit({ from: event.target.value })
                                    }
                                />
                            </Label>
                            <Label text="Hasta">
                                <input
                                    type="date"
                                    className={field}
                                    defaultValue={filters.to}
                                    onBlur={(event) =>
                                        visit({ to: event.target.value })
                                    }
                                />
                            </Label>
                            <a
                                className={secondary}
                                href={`/accounting/consolidation/${statement.group.id}/export?from=${filters.from}&to=${filters.to}&format=xlsx`}
                            >
                                <Download className="size-4" /> Excel
                            </a>
                            <a
                                className={secondary}
                                href={`/accounting/consolidation/${statement.group.id}/export?from=${filters.from}&to=${filters.to}&format=pdf`}
                            >
                                <Download className="size-4" /> PDF
                            </a>
                            <Badge
                                tone={statement.balanced ? 'green' : 'amber'}
                            >
                                {statement.balanced
                                    ? 'El balance consolidado cuadra'
                                    : `Diferencia ${money(statement.difference_cents)}`}
                            </Badge>
                        </div>
                        {statement.warnings.length > 0 && (
                            <ul className="mb-3 list-disc space-y-1 rounded-xl bg-amber-50 p-3 pl-7 text-sm text-amber-900">
                                {statement.warnings.map((warning) => (
                                    <li key={warning}>{warning}</li>
                                ))}
                            </ul>
                        )}
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px]">
                                <thead>
                                    <tr>
                                        <th className={head}>Cuenta</th>
                                        {statement.companies.map((company) => (
                                            <th
                                                key={company.id}
                                                className={head}
                                            >
                                                {company.name}
                                                {company.ownership_bp < 10000 &&
                                                    ` (${company.ownership_bp / 100} %)`}
                                            </th>
                                        ))}
                                        <th className={head}>Eliminaciones</th>
                                        <th className={head}>Consolidado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {Object.entries(statement.sections).map(
                                        ([type, section]) => [
                                            <tr key={`${type}-head`}>
                                                <td
                                                    className={`${cell} font-bold uppercase`}
                                                    colSpan={
                                                        statement.companies
                                                            .length + 3
                                                    }
                                                >
                                                    {section.label}
                                                </td>
                                            </tr>,
                                            ...section.lines.map((line) => (
                                                <tr
                                                    key={`${type}-${line.code}`}
                                                    className="border-t border-slate-100"
                                                >
                                                    <td className={cell}>
                                                        {line.code.startsWith(
                                                            '3999',
                                                        )
                                                            ? ''
                                                            : line.code}{' '}
                                                        {line.name}
                                                    </td>
                                                    {statement.companies.map(
                                                        (company) => (
                                                            <td
                                                                key={company.id}
                                                                className={cell}
                                                            >
                                                                {money(
                                                                    line
                                                                        .companies[
                                                                        company
                                                                            .id
                                                                    ] ?? 0,
                                                                )}
                                                            </td>
                                                        ),
                                                    )}
                                                    <td className={cell}>
                                                        {line.eliminations
                                                            ? money(
                                                                  line.eliminations,
                                                              )
                                                            : '—'}
                                                    </td>
                                                    <td
                                                        className={`${cell} font-semibold`}
                                                    >
                                                        {money(
                                                            line.consolidated,
                                                        )}
                                                    </td>
                                                </tr>
                                            )),
                                            <tr
                                                key={`${type}-total`}
                                                className="border-t-2 border-slate-300"
                                            >
                                                <td
                                                    className={`${cell} font-bold`}
                                                    colSpan={
                                                        statement.companies
                                                            .length + 2
                                                    }
                                                >
                                                    Total{' '}
                                                    {section.label.toLowerCase()}
                                                </td>
                                                <td
                                                    className={`${cell} font-bold`}
                                                >
                                                    {money(section.total)}
                                                </td>
                                            </tr>,
                                        ],
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-3 text-sm">
                            Resultado consolidado del período:{' '}
                            <strong>{money(statement.result_cents)}</strong> ·
                            participación no controladora en el patrimonio:{' '}
                            <strong>
                                {money(statement.non_controlling.equity_cents)}
                            </strong>{' '}
                            (en el resultado:{' '}
                            {money(statement.non_controlling.result_cents)})
                        </p>
                    </Card>
                )}

                {groups.length === 0 && (
                    <Empty>
                        Sin grupos todavía. Crea uno con la empresa controladora
                        y sus subsidiarias.
                    </Empty>
                )}

                {canManage && (
                    <Card title={group ? 'Editar grupo' : 'Nuevo grupo'}>
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (group) {
                                    form.put(
                                        `/accounting/consolidation/${group.id}`,
                                    );
                                } else {
                                    form.post('/accounting/consolidation');
                                }
                            }}
                        >
                            <div className="grid gap-3 md:grid-cols-3">
                                <Label text="Nombre del grupo">
                                    <input
                                        className={field}
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                    />
                                </Label>
                                <Label text="Empresa controladora">
                                    <select
                                        className={field}
                                        value={form.data.parent_company_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'parent_company_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {companies.map((company) => (
                                            <option
                                                key={company.id}
                                                value={company.id}
                                            >
                                                {company.name}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                                <Label text="Cuenta de inversión en subsidiarias (opcional)">
                                    <input
                                        className={field}
                                        placeholder="Ej. 1501"
                                        value={
                                            form.data.investment_account_code
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'investment_account_code',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Label>
                            </div>
                            {form.data.members.map((member, index) => (
                                <div
                                    key={index}
                                    className="grid gap-3 md:grid-cols-[2fr_1fr_auto]"
                                >
                                    <Label text="Subsidiaria">
                                        <select
                                            className={field}
                                            value={member.company_id}
                                            onChange={(e) =>
                                                form.setData(
                                                    'members',
                                                    form.data.members.map(
                                                        (row, i) =>
                                                            i === index
                                                                ? {
                                                                      ...row,
                                                                      company_id:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : row,
                                                    ),
                                                )
                                            }
                                        >
                                            {companies.map((company) => (
                                                <option
                                                    key={company.id}
                                                    value={company.id}
                                                >
                                                    {company.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Label>
                                    <Label text="% de participación">
                                        <input
                                            className={field}
                                            value={member.ownership}
                                            onChange={(e) =>
                                                form.setData(
                                                    'members',
                                                    form.data.members.map(
                                                        (row, i) =>
                                                            i === index
                                                                ? {
                                                                      ...row,
                                                                      ownership:
                                                                          e
                                                                              .target
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
                                        className={`${secondary} self-end`}
                                        aria-label="Quitar subsidiaria"
                                        onClick={() =>
                                            form.setData(
                                                'members',
                                                form.data.members.filter(
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
                                        form.setData('members', [
                                            ...form.data.members,
                                            {
                                                company_id: String(
                                                    companies[0]?.id ?? '',
                                                ),
                                                ownership: '100',
                                            },
                                        ])
                                    }
                                >
                                    <Plus className="size-4" /> Agregar
                                    subsidiaria
                                </button>
                                <button
                                    className={primary}
                                    disabled={form.processing}
                                >
                                    Guardar grupo
                                </button>
                            </div>
                            <Errors errors={form.errors} />
                            <p className="text-xs text-slate-500">
                                Las operaciones entre empresas del grupo se
                                reconocen por el NIT: lo que una le debe a otra
                                y las ventas de una a otra se eliminan del
                                consolidado.
                            </p>
                        </form>
                    </Card>
                )}
            </div>
        </div>
    );
}
