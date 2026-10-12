import { Head, Link } from '@inertiajs/react';
import {
    BriefcaseBusiness,
    Building2,
    CheckCircle2,
    UserCheck,
    UserMinus,
    UserRoundX,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    AssignDialog,
    EmptyRow,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    panel,
    priorityLabel,
    scoped,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import type {
    AssignTarget,
    CompatibleUser,
    OfficeScope,
    Tone,
} from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';

type Row = AssignTarget & {
    tax_id: string;
    priority: string;
    reason: string;
    compatible_users: CompatibleUser[];
    compatible_users_count: number;
    company_public_id: string;
};

type HistoryEvent = {
    event_id: number;
    actor: string;
    description: string;
    created_at: string;
};

type Props = {
    officeScope: OfficeScope;
    assignments: {
        incomplete: Row[];
        summary: {
            companies: number;
            complete: number;
            without_accountant: number;
            without_assistant: number;
        };
        history: HistoryEvent[];
    };
    permissions: Record<string, boolean>;
};

const rowSearchText = (row: Row): string =>
    [
        row.company_name,
        row.tax_id,
        row.office_name,
        row.missing_label,
        row.reason,
    ].join(' ');

const historySearchText = (event: HistoryEvent): string =>
    `${event.actor} ${event.description}`;

export default function Assignments({
    officeScope,
    assignments,
    permissions,
}: Props) {
    const officeQuery = useOfficeQuery();
    const [target, setTarget] = useState<AssignTarget | null>(null);
    const [role, setRole] = useState('');
    const [priority, setPriority] = useState('');
    const scopeName =
        officeScope.offices.find((office) => office.id === officeScope.selected)
            ?.name ?? 'Todas las oficinas';
    const { summary } = assignments;
    const predicate = useMemo(
        () => (row: Row) =>
            (role === '' || row.missing_role === role) &&
            (priority === '' || row.priority === priority),
        [role, priority],
    );
    const table = useTableControls(assignments.incomplete, {
        searchText: rowSearchText,
        predicate,
    });
    const history = useTableControls(assignments.history, {
        searchText: historySearchText,
        initialPageSize: 5,
    });
    const toggleRole = (value: string) => setRole(role === value ? '' : value);

    return (
        <>
            <Head title="Asignaciones" />
            <Shell>
                <PageHeader
                    title="Asignaciones"
                    subtitle={`Responsables de cada empresa cliente · ${scopeName}`}
                    actions={
                        <Link
                            href={scoped('/office/companies', officeQuery)}
                            className="inline-flex h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-bold"
                        >
                            Ver todas las empresas
                        </Link>
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-xl bg-blue-50 p-3 text-blue-700">
                        <BriefcaseBusiness className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {summary.companies}
                        </p>
                        <p className="text-xs font-semibold">Empresas</p>
                    </div>
                    <div className="rounded-xl bg-emerald-50 p-3 text-emerald-700">
                        <CheckCircle2 className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {summary.complete}
                        </p>
                        <p className="text-xs font-semibold">
                            Con responsables completos
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => toggleRole('accountant_in_charge')}
                        aria-pressed={role === 'accountant_in_charge'}
                        className={`rounded-xl bg-red-50 p-3 text-left text-red-700 ${role === 'accountant_in_charge' ? 'ring-2 ring-[#640000]' : ''}`}
                    >
                        <UserRoundX className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {summary.without_accountant}
                        </p>
                        <p className="text-xs font-semibold">
                            Sin contador encargado
                        </p>
                    </button>
                    <button
                        type="button"
                        onClick={() => toggleRole('accounting_assistant')}
                        aria-pressed={role === 'accounting_assistant'}
                        className={`rounded-xl bg-amber-50 p-3 text-left text-amber-700 ${role === 'accounting_assistant' ? 'ring-2 ring-[#640000]' : ''}`}
                    >
                        <UserMinus className="size-5" />
                        <p className="mt-2 text-2xl font-black">
                            {summary.without_assistant}
                        </p>
                        <p className="text-xs font-semibold">
                            Sin auxiliar contable
                        </p>
                    </button>
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            title={`Asignaciones pendientes (${table.count}${table.count !== table.total ? ` de ${table.total}` : ''})`}
                        />
                    </div>
                    <TableToolbar
                        search={table.search}
                        onSearch={table.setSearch}
                        placeholder="Buscar por empresa, NIT, oficina o responsabilidad"
                        pageSize={table.pageSize}
                        onPageSize={table.setPageSize}
                    >
                        <TableFilterSelect
                            label="Toda responsabilidad"
                            value={role}
                            onChange={setRole}
                            options={[
                                {
                                    value: 'accountant_in_charge',
                                    label: 'Contador encargado',
                                },
                                {
                                    value: 'accounting_assistant',
                                    label: 'Auxiliar contable',
                                },
                            ]}
                        />
                        <TableFilterSelect
                            label="Toda prioridad"
                            value={priority}
                            onChange={setPriority}
                            options={[
                                { value: 'high', label: 'Alta' },
                                { value: 'medium', label: 'Media' },
                                { value: 'low', label: 'Baja' },
                            ]}
                        />
                    </TableToolbar>
                    <div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                        {table.pageRows.map((row) => {
                            const [label, tone] = priorityLabel[
                                row.priority
                            ] ?? ['Media', 'amber'];

                            return (
                                <article
                                    key={`${row.company_id}-${row.missing_role}`}
                                    className="flex flex-col rounded-2xl border border-[#ebe3df] bg-white p-4 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)] transition hover:-translate-y-0.5 hover:shadow-[0_14px_30px_-20px_rgba(49,11,8,.6)]"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                            <UserRoundX className="size-6" />
                                        </span>
                                        <span className={badge(tone as Tone)}>
                                            {label}
                                        </span>
                                    </div>
                                    <h3 className="mt-3 truncate text-base font-black text-[#061633]">
                                        {row.company_name}
                                    </h3>
                                    <p className="mt-0.5 flex items-center gap-1 text-xs text-[#526b92]">
                                        <Building2 className="size-3.5" /> NIT{' '}
                                        {row.tax_id} · {row.office_name}
                                    </p>
                                    <p className="mt-3 text-sm font-bold text-red-700">
                                        Falta {row.missing_label.toLowerCase()}
                                    </p>
                                    <p className="mt-1 flex-1 text-xs text-[#526b92]">
                                        {row.reason}
                                    </p>
                                    {permissions.canAssign ? (
                                        <button
                                            type="button"
                                            className="mt-4 inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-xs font-bold text-[#172033] transition hover:border-[#c9a36a]"
                                            onClick={() => setTarget(row)}
                                            title={`${row.compatible_users_count} personas disponibles`}
                                        >
                                            <UserCheck className="size-4" />{' '}
                                            Asignar
                                            <span className="font-normal text-[#8a6d3b]">
                                                ({row.compatible_users_count})
                                            </span>
                                        </button>
                                    ) : (
                                        <p className="mt-4 text-xs text-[#526b92]">
                                            Sin permiso para asignar
                                        </p>
                                    )}
                                </article>
                            );
                        })}
                        {table.pageRows.length === 0 && (
                            <p className="col-span-full px-3 py-10 text-center text-sm text-slate-500">
                                {table.total === 0
                                    ? 'Todas las empresas tienen sus responsables configurados.'
                                    : 'No hay asignaciones que coincidan con la búsqueda o los filtros.'}
                            </p>
                        )}
                    </div>
                    <TablePagination
                        page={table.page}
                        pages={table.pages}
                        pageSize={table.pageSize}
                        count={table.count}
                        onPage={table.setPage}
                        noun="asignaciones"
                    />
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader title="Últimos cambios de responsables" />
                    </div>
                    <TableToolbar
                        search={history.search}
                        onSearch={history.setSearch}
                        placeholder="Buscar por persona o empresa"
                        pageSize={history.pageSize}
                        onPageSize={history.setPageSize}
                    />
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-3 py-2">Cambio</th>
                                    <th className="px-3 py-2">Fecha</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {history.pageRows.map((event) => (
                                    <tr key={event.event_id}>
                                        <td className="px-3 py-2.5 text-sm">
                                            {event.description}
                                        </td>
                                        <td className="px-3 py-2.5 whitespace-nowrap text-[#526b92]">
                                            {formatDate(event.created_at, true)}
                                        </td>
                                    </tr>
                                ))}
                                {history.pageRows.length === 0 && (
                                    <EmptyRow
                                        colSpan={2}
                                        text="Aún no hay cambios de responsables."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        page={history.page}
                        pages={history.pages}
                        pageSize={history.pageSize}
                        count={history.count}
                        onPage={history.setPage}
                        noun="cambios"
                    />
                </section>
            </Shell>
            <AssignDialog target={target} onClose={() => setTarget(null)} />
        </>
    );
}
