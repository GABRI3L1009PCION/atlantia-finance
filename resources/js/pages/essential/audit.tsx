import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import {
    Pager,
    SearchBox,
    selectClass,
    useServerFilters,
} from '@/components/essential/kit';
import type { Paged } from '@/components/essential/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Props = {
    log: {
        rows: {
            id: number;
            action: string;
            entity: string | null;
            entity_id: number | null;
            actor: string;
            ip: string | null;
            at: string;
            detail: string | null;
        }[];
        actions: string[];
        users: { id: number; name: string }[];
    } & Paged;
    filters: Record<string, string>;
};

export default function EssentialAudit({ log, filters }: Props) {
    const apply = useServerFilters('/essential/audit');
    const query = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => value),
    );
    const exportHref = (format: string) =>
        `/essential/audit/export?${new URLSearchParams([...query, ['format', format]])}`;

    return (
        <>
            <Head title="Auditoría" />
            <Shell>
                <PageHeader
                    title="Registro de auditoría"
                    subtitle="Quién hizo qué y cuándo en tu oficina. Los registros no se pueden editar ni borrar"
                    actions={
                        <>
                            <a
                                href={exportHref('xlsx')}
                                className={secondaryButton}
                            >
                                <Download className="size-3.5" /> Excel
                            </a>
                            <a
                                href={exportHref('pdf')}
                                className={secondaryButton}
                            >
                                <Download className="size-3.5" /> PDF
                            </a>
                        </>
                    }
                />
                <Card
                    title={`${log.total} evento(s)`}
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <SearchBox
                                value={filters.search ?? ''}
                                placeholder="Buscar acción o persona…"
                                onSubmit={(value) =>
                                    apply({ search: value || null })
                                }
                            />
                            <select
                                aria-label="Persona"
                                className={selectClass}
                                value={filters.user_id ?? ''}
                                onChange={(event) =>
                                    apply({
                                        user_id: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Todas las personas</option>
                                {log.users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Acción"
                                className={selectClass}
                                value={filters.action ?? ''}
                                onChange={(event) =>
                                    apply({
                                        action: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Todas las acciones</option>
                                {log.actions.map((action) => (
                                    <option key={action} value={action}>
                                        {action}
                                    </option>
                                ))}
                            </select>
                            <input
                                type="date"
                                aria-label="Desde"
                                className={`${input} !w-auto`}
                                value={filters.from ?? ''}
                                onChange={(event) =>
                                    apply({ from: event.target.value || null })
                                }
                            />
                            <input
                                type="date"
                                aria-label="Hasta"
                                className={`${input} !w-auto`}
                                value={filters.to ?? ''}
                                onChange={(event) =>
                                    apply({ to: event.target.value || null })
                                }
                            />
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Fecha',
                            'Persona',
                            'Acción',
                            'Registro',
                            'Detalle',
                            'IP',
                        ]}
                        empty={
                            log.rows.length === 0 &&
                            'No hay eventos con estos filtros.'
                        }
                    >
                        {log.rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(row.at, true)}
                                </td>
                                <td className="px-2 py-2">{row.actor}</td>
                                <td className="px-2 py-2 font-mono text-[11px] font-bold">
                                    {row.action}
                                </td>
                                <td className="px-2 py-2">
                                    {row.entity
                                        ? `${row.entity}${row.entity_id ? ` #${row.entity_id}` : ''}`
                                        : '—'}
                                </td>
                                <td className="max-w-md px-2 py-2 text-[11px] break-words text-slate-600">
                                    {row.detail ?? '—'}
                                </td>
                                <td className="px-2 py-2">{row.ip ?? '—'}</td>
                            </tr>
                        ))}
                    </Table>
                    <Pager paged={log} onPage={(page) => apply({ page })} />
                </Card>
            </Shell>
        </>
    );
}
