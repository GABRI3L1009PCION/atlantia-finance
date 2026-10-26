import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Building2,
    ExternalLink,
    Plus,
    UserCheck,
    UserRoundX,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    AssignDialog,
    EmptyRow,
    NewCompanyDialog,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    input,
    panel,
    primaryButton,
    priorityLabel,
    secondaryButton,
} from '@/components/office-admin/kit';
import type {
    AssignTarget,
    OfficeScope,
    Tone,
} from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type CompanyRow = {
    id: number;
    public_id: string;
    name: string;
    tax_id: string;
    office_id: number;
    office_name: string;
    accountant: string | null;
    assistants: string[];
    tax_specialist: string | null;
    complete: boolean;
    open_tasks: number;
    last_activity: string | null;
    href: string;
};

type Incomplete = AssignTarget & { priority: string; reason: string };

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const companySearchText = (company: CompanyRow): string =>
    [
        company.name,
        company.tax_id,
        company.office_name,
        company.accountant,
        company.assistants.join(' '),
        company.tax_specialist,
    ].join(' ');

const incompleteSearchText = (row: Incomplete): string =>
    [row.company_name, row.missing_label, row.office_name].join(' ');

export default function OfficeCompanies({
    officeScope,
    companies,
    incomplete,
    assignmentRoles,
    permissions,
    notice,
}: {
    officeScope: OfficeScope;
    companies: CompanyRow[];
    incomplete: Incomplete[];
    assignmentRoles: { code: string; name: string }[];
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const [assignTarget, setAssignTarget] = useState<AssignTarget | null>(null);
    const [companyOpen, setCompanyOpen] = useState(false);
    const [moving, setMoving] = useState<{
        company: CompanyRow;
        officeId: string;
    } | null>(null);
    const [status, setStatus] = useState('');
    const [office, setOffice] = useState('');
    const predicate = useMemo(
        () => (company: CompanyRow) =>
            (status === '' ||
                (status === 'complete'
                    ? company.complete
                    : !company.complete)) &&
            (office === '' || String(company.office_id) === office),
        [status, office],
    );
    const table = useTableControls(companies, {
        searchText: companySearchText,
        predicate,
    });
    const cards = useTableControls(incomplete, {
        searchText: incompleteSearchText,
        initialPageSize: 6,
    });
    const openAssign = (company: CompanyRow, role: string) =>
        setAssignTarget({
            company_id: company.id,
            company_name: company.name,
            office_id: company.office_id,
            office_name: company.office_name,
            missing_role: role,
            missing_label:
                assignmentRoles.find((item) => item.code === role)?.name ??
                role,
        });

    return (
        <>
            <Head title="Empresas de la oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Empresas"
                    subtitle="Clientes de la oficina, su equipo responsable y su oficina de atención"
                    actions={
                        permissions.canCreateCompany && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setCompanyOpen(true)}
                            >
                                <Plus className="size-4" /> Nuevo cliente
                            </button>
                        )
                    }
                />

                {incomplete.length > 0 && (
                    <section className={`${panel} p-4`}>
                        <SectionHeader
                            id="asignaciones"
                            title={`Asignaciones incompletas (${incomplete.length})`}
                        />
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {cards.pageRows.map((row) => {
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
                                            <span
                                                className={badge(tone as Tone)}
                                            >
                                                {label}
                                            </span>
                                        </div>
                                        <h3 className="mt-3 truncate text-base font-black text-[#061633]">
                                            {row.company_name}
                                        </h3>
                                        <p className="mt-0.5 flex items-center gap-1 text-xs text-[#526b92]">
                                            <Building2 className="size-3.5" />{' '}
                                            {row.office_name}
                                        </p>
                                        <p className="mt-3 text-sm font-bold text-red-700">
                                            Falta{' '}
                                            {row.missing_label.toLowerCase()}
                                        </p>
                                        <p className="mt-1 flex-1 text-xs text-[#526b92]">
                                            {row.reason}
                                        </p>
                                        {permissions.canAssign && (
                                            <button
                                                type="button"
                                                className={`${secondaryButton} mt-4 w-full`}
                                                onClick={() =>
                                                    setAssignTarget(row)
                                                }
                                            >
                                                <UserCheck className="size-4" />{' '}
                                                Asignar
                                            </button>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                        {cards.pages > 1 && (
                            <div className="-mx-4 mt-3 -mb-4">
                                <TablePagination
                                    page={cards.page}
                                    pages={cards.pages}
                                    pageSize={cards.pageSize}
                                    count={cards.count}
                                    onPage={cards.setPage}
                                    noun="asignaciones"
                                />
                            </div>
                        )}
                    </section>
                )}

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            title={`Empresas (${table.count}${table.count !== table.total ? ` de ${table.total}` : ''})`}
                        />
                    </div>
                    <TableToolbar
                        search={table.search}
                        onSearch={table.setSearch}
                        placeholder="Buscar por empresa, NIT, oficina o responsable"
                        pageSize={table.pageSize}
                        onPageSize={table.setPageSize}
                    >
                        <TableFilterSelect
                            label="Toda asignación"
                            value={status}
                            onChange={setStatus}
                            options={[
                                { value: 'complete', label: 'Completa' },
                                {
                                    value: 'incomplete',
                                    label: 'Con equipo incompleto',
                                },
                            ]}
                        />
                        {officeScope.offices.length > 1 && (
                            <TableFilterSelect
                                label="Todas las oficinas"
                                value={office}
                                onChange={setOffice}
                                options={officeScope.offices.map((item) => ({
                                    value: String(item.id),
                                    label: item.name,
                                }))}
                            />
                        )}
                    </TableToolbar>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-3 py-2">Empresa</th>
                                    <th className="px-3 py-2">Oficina</th>
                                    <th className="px-3 py-2">Contador</th>
                                    <th className="px-3 py-2">Auxiliar(es)</th>
                                    <th className="px-3 py-2">Fiscal</th>
                                    <th className="px-3 py-2">Asignación</th>
                                    <th className="px-3 py-2">
                                        Última actividad
                                    </th>
                                    <th className="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {table.pageRows.map((company) => (
                                    <tr key={company.id}>
                                        <td className="px-3 py-2">
                                            <p className="font-bold">
                                                {company.name}
                                            </p>
                                            <p className="text-[11px] text-[#526b92]">
                                                NIT {company.tax_id} ·{' '}
                                                {company.open_tasks} tarea(s)
                                                abiertas
                                            </p>
                                        </td>
                                        <td className="px-3 py-2">
                                            {company.office_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {company.accountant ?? (
                                                <span className="font-bold text-red-700">
                                                    Sin contador
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {company.assistants.length > 0 ? (
                                                company.assistants.join(', ')
                                            ) : (
                                                <span className="font-bold text-amber-700">
                                                    Sin auxiliar
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {company.tax_specialist ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span
                                                className={badge(
                                                    company.complete
                                                        ? 'green'
                                                        : 'amber',
                                                )}
                                            >
                                                {company.complete
                                                    ? 'Completa'
                                                    : 'Incompleta'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-[#526b92]">
                                            {formatDate(company.last_activity)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1.5">
                                                <Link
                                                    href={company.href}
                                                    className={iconButton}
                                                    title="Ver empresa"
                                                    aria-label={`Ver ${company.name}`}
                                                >
                                                    <ExternalLink className="size-4" />
                                                </Link>
                                                {permissions.canAssign && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            className={
                                                                iconButton
                                                            }
                                                            title="Asignar responsable"
                                                            aria-label={`Asignar responsable en ${company.name}`}
                                                        >
                                                            <UserCheck className="size-4" />
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuLabel>
                                                                Asignar como…
                                                            </DropdownMenuLabel>
                                                            {assignmentRoles.map(
                                                                (role) => (
                                                                    <DropdownMenuItem
                                                                        key={
                                                                            role.code
                                                                        }
                                                                        onSelect={() =>
                                                                            openAssign(
                                                                                company,
                                                                                role.code,
                                                                            )
                                                                        }
                                                                    >
                                                                        {
                                                                            role.name
                                                                        }
                                                                    </DropdownMenuItem>
                                                                ),
                                                            )}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                                {permissions.canMoveCompany &&
                                                    officeScope.offices.length >
                                                        1 && (
                                                        <button
                                                            type="button"
                                                            className={
                                                                iconButton
                                                            }
                                                            title="Mover a otra oficina"
                                                            aria-label={`Mover ${company.name} a otra oficina`}
                                                            onClick={() =>
                                                                setMoving({
                                                                    company,
                                                                    officeId:
                                                                        officeScope.offices
                                                                            .find(
                                                                                (
                                                                                    item,
                                                                                ) =>
                                                                                    item.id !==
                                                                                    company.office_id,
                                                                            )
                                                                            ?.id.toString() ??
                                                                        '',
                                                                })
                                                            }
                                                        >
                                                            <ArrowRightLeft className="size-4" />
                                                        </button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {table.pageRows.length === 0 && (
                                    <EmptyRow
                                        colSpan={8}
                                        text="No hay empresas que coincidan con la búsqueda o los filtros."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        page={table.page}
                        pages={table.pages}
                        pageSize={table.pageSize}
                        count={table.count}
                        onPage={table.setPage}
                        noun="empresas"
                    />
                </section>

                {moving && (
                    <section className={`${panel} border-amber-200 p-3`}>
                        <h2 className="text-lg font-black">
                            Mover {moving.company.name}
                        </h2>
                        <p className="text-sm text-[#526b92]">
                            Los responsables que también trabajan en la oficina
                            destino se conservan; las demás asignaciones
                            terminan (quedan en el historial) y el trabajo
                            abierto se muda con la empresa.
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <select
                                className={`${input} max-w-72`}
                                value={moving.officeId}
                                onChange={(event) =>
                                    setMoving({
                                        ...moving,
                                        officeId: event.target.value,
                                    })
                                }
                            >
                                {officeScope.offices
                                    .filter(
                                        (item) =>
                                            item.id !==
                                            moving.company.office_id,
                                    )
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                            </select>
                            <button
                                type="button"
                                className={primaryButton}
                                disabled={!moving.officeId}
                                onClick={() =>
                                    router.post(
                                        `/office/companies/${moving.company.id}/move`,
                                        { office_id: moving.officeId },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setMoving(null),
                                        },
                                    )
                                }
                            >
                                Confirmar traslado
                            </button>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setMoving(null)}
                            >
                                Cancelar
                            </button>
                        </div>
                    </section>
                )}
            </Shell>
            <AssignDialog
                target={assignTarget}
                onClose={() => setAssignTarget(null)}
            />
            <NewCompanyDialog
                open={companyOpen}
                onClose={() => setCompanyOpen(false)}
                scope={officeScope}
            />
        </>
    );
}
