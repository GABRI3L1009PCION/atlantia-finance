import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    EmptyRow,
    PageHeader,
    Shell,
    formatDate,
    panel,
} from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';

type Event = {
    event_id: number;
    actor: string;
    action: string;
    action_label: string;
    description: string;
    entity_type: string;
    entity_name: string | null;
    created_at: string;
    destination: string | null;
};

const groups = [
    { key: '', label: 'Todo' },
    { key: 'office.', label: 'Oficinas' },
    { key: 'company.', label: 'Empresas y asignaciones' },
    { key: 'user.', label: 'Usuarios' },
    { key: 'workspace_role.', label: 'Roles' },
];

const ENTITY: Record<string, string> = {
    offices: 'Oficina',
    companies: 'Empresa',
    company_assignments: 'Asignación',
    users: 'Usuario',
    user_role_assignments: 'Rol de usuario',
    workspace_roles: 'Rol',
    invitations: 'Invitación',
};

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const searchText = (event: Event): string =>
    [
        event.actor,
        event.action_label,
        event.entity_name,
        ENTITY[event.entity_type],
        event.action,
    ].join(' ');

export default function OfficeAudit({
    events,
    group,
}: {
    events: Event[];
    group: string;
}) {
    const page = usePage();
    const path = page.url.split('?')[0];
    const officeId = new URL(
        page.url,
        'https://atlantia.local',
    ).searchParams.get('office_id');
    const [actor, setActor] = useState('');
    const actors = useMemo(
        () => Array.from(new Set(events.map((event) => event.actor))).sort(),
        [events],
    );
    const predicate = useMemo(
        () => (event: Event) => actor === '' || event.actor === actor,
        [actor],
    );
    const table = useTableControls(events, { searchText, predicate });
    const choose = (key: string) =>
        router.get(
            path,
            {
                ...(officeId ? { office_id: officeId } : {}),
                ...(key ? { group: key } : {}),
            },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Auditoría administrativa" />
            <Shell>
                <PageHeader
                    title="Auditoría administrativa"
                    subtitle="Quién creó, asignó, invitó, cambió roles o desactivó, y cuándo"
                />
                <div className="flex flex-wrap gap-1.5">
                    {groups.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => choose(item.key)}
                            className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${group === item.key ? 'bg-[#640000] text-white ring-[#640000]' : 'bg-white text-[#395277] ring-slate-200'}`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <h2 className="mb-3 text-lg font-black text-[#061633]">
                            Registro de actividad ({table.count}
                            {table.count !== table.total
                                ? ` de ${table.total}`
                                : ''}
                            )
                        </h2>
                    </div>
                    <TableToolbar
                        search={table.search}
                        onSearch={table.setSearch}
                        placeholder="Buscar por responsable, acción o registro"
                        pageSize={table.pageSize}
                        onPageSize={table.setPageSize}
                    >
                        {actors.length > 1 && (
                            <TableFilterSelect
                                label="Todos los responsables"
                                value={actor}
                                onChange={setActor}
                                options={actors.map((name) => ({
                                    value: name,
                                    label: name,
                                }))}
                            />
                        )}
                    </TableToolbar>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-3 py-2">Fecha</th>
                                    <th className="px-3 py-2">Responsable</th>
                                    <th className="px-3 py-2">Acción</th>
                                    <th className="px-3 py-2">Registro</th>
                                    <th className="px-3 py-2">Código</th>
                                    <th className="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {table.pageRows.map((event) => (
                                    <tr key={event.event_id}>
                                        <td className="px-3 py-2 whitespace-nowrap text-[#526b92]">
                                            {formatDate(event.created_at, true)}
                                        </td>
                                        <td className="px-3 py-2 font-bold text-[#061633]">
                                            {event.actor}
                                        </td>
                                        <td className="px-3 py-2">
                                            {event.action_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            <p className="font-semibold">
                                                {event.entity_name ?? '—'}
                                            </p>
                                            <p className="text-[11px] text-[#526b92]">
                                                {ENTITY[event.entity_type] ??
                                                    event.entity_type}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[11px] text-[#526b92]">
                                            {event.action}
                                        </td>
                                        <td className="px-3 py-2">
                                            {event.destination && (
                                                <Link
                                                    href={event.destination}
                                                    className={iconButton}
                                                    title="Abrir el registro"
                                                    aria-label={`Abrir ${event.entity_name ?? 'registro'}`}
                                                >
                                                    <Eye className="size-4" />
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {table.pageRows.length === 0 && (
                                    <EmptyRow
                                        colSpan={6}
                                        text={
                                            table.total === 0
                                                ? 'No hay actividad administrativa registrada en este alcance.'
                                                : 'No hay eventos que coincidan con la búsqueda o los filtros.'
                                        }
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
                        noun="eventos"
                    />
                </section>
            </Shell>
        </>
    );
}
