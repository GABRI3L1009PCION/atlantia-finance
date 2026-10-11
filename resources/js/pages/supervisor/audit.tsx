import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    PageHeader,
    Shell,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';
import type { SupervisorContext } from '@/components/supervisor/kit';

type Event = {
    id: number;
    user: string;
    action: string;
    module: string;
    record: string;
    old_values: string | null;
    new_values: string | null;
    ip: string | null;
    occurred_at: string;
};

export default function SupervisorAudit({
    events,
    modules,
    filters,
}: {
    context: SupervisorContext;
    events: Event[];
    modules: Record<string, string>;
    filters: Record<string, string>;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [open, setOpen] = useState<number | null>(null);
    const apply = (next: Record<string, string>) =>
        router.get(
            '/supervisor/audit',
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(
                    ([, value]) => value !== '',
                ),
            ),
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title="Historial de auditoría" />
            <Shell>
                <PageHeader
                    title="Historial de auditoría"
                    subtitle="Solo lectura: quién hizo qué, cuándo y desde dónde en tus empresas"
                />
                <Card
                    title={`${events.length} evento(s)`}
                    action={
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                apply({ search });
                            }}
                        >
                            <select
                                aria-label="Módulo"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.module ?? ''}
                                onChange={(event) =>
                                    apply({ module: event.target.value })
                                }
                            >
                                <option value="">Todos los módulos</option>
                                {Object.entries(modules).map(
                                    ([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <input
                                aria-label="Buscar acción"
                                className={`${input} h-9 w-48`}
                                placeholder="Buscar acción"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <button type="submit" className={secondaryButton}>
                                Buscar
                            </button>
                        </form>
                    }
                >
                    <Table
                        headers={[
                            'Fecha',
                            'Usuario',
                            'Módulo',
                            'Acción',
                            'Registro',
                            'IP',
                            '',
                        ]}
                        empty={events.length === 0 && 'Sin eventos.'}
                    >
                        {events.map((event) => (
                            <Fragment key={event.id}>
                                <tr>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {new Date(
                                            event.occurred_at,
                                        ).toLocaleString('es-GT')}
                                    </td>
                                    <td className="px-2 py-2">{event.user}</td>
                                    <td className="px-2 py-2">
                                        {event.module}
                                    </td>
                                    <td className="px-2 py-2 font-mono text-[11px]">
                                        {event.action}
                                    </td>
                                    <td className="px-2 py-2">
                                        {event.record}
                                    </td>
                                    <td className="px-2 py-2">
                                        {event.ip ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {(event.old_values ||
                                            event.new_values) && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    setOpen(
                                                        open === event.id
                                                            ? null
                                                            : event.id,
                                                    )
                                                }
                                            >
                                                {open === event.id
                                                    ? 'Ocultar'
                                                    : 'Detalle'}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                                {open === event.id && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="bg-[#f8fafc] px-2 py-2"
                                        >
                                            <div className="grid gap-2 md:grid-cols-2">
                                                <div>
                                                    <p className="text-[11px] font-bold text-[#526b92]">
                                                        Antes
                                                    </p>
                                                    <pre className="overflow-x-auto text-[11px] whitespace-pre-wrap">
                                                        {event.old_values ??
                                                            '—'}
                                                    </pre>
                                                </div>
                                                <div>
                                                    <p className="text-[11px] font-bold text-[#526b92]">
                                                        Después
                                                    </p>
                                                    <pre className="overflow-x-auto text-[11px] whitespace-pre-wrap">
                                                        {event.new_values ??
                                                            '—'}
                                                    </pre>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
