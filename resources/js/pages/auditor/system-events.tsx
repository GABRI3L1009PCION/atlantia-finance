import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';

export type SystemEvent = {
    id: number;
    occurred_at: string;
    user: string;
    action: string;
    entity: string | null;
    ip: string | null;
    details: Record<string, unknown> | null;
    category_label?: string;
};

export function EventsTable({
    events,
    category = false,
}: {
    events: SystemEvent[];
    category?: boolean;
}) {
    return (
        <Table
            headers={[
                'Fecha',
                'Usuario',
                ...(category ? ['Categoría'] : []),
                'Evento',
                'Entidad',
                'IP',
                'Detalle',
            ]}
            empty={
                events.length === 0 &&
                'Sin eventos para los filtros seleccionados.'
            }
        >
            {events.map((event) => (
                <tr key={event.id}>
                    <td className="px-2 py-2 whitespace-nowrap">
                        {formatDate(event.occurred_at, true)}
                    </td>
                    <td className="px-2 py-2">{event.user}</td>
                    {category && (
                        <td className="px-2 py-2">{event.category_label}</td>
                    )}
                    <td className="px-2 py-2 font-mono text-[11px]">
                        {event.action}
                    </td>
                    <td className="px-2 py-2">{event.entity ?? '—'}</td>
                    <td className="px-2 py-2">{event.ip ?? '—'}</td>
                    <td
                        className="max-w-md truncate px-2 py-2 text-[11px] text-[#526b92]"
                        title={
                            event.details ? JSON.stringify(event.details) : ''
                        }
                    >
                        {event.details ? JSON.stringify(event.details) : '—'}
                    </td>
                </tr>
            ))}
        </Table>
    );
}

export default function AuditorSystemEvents({
    context,
    events,
    filters,
}: {
    context: AuditorContext;
    events: SystemEvent[];
    filters: Record<string, string>;
}) {
    const href = useAuditorHref();
    const [form, setForm] = useState({
        company_id: '',
        user: '',
        action: '',
        from: '',
        to: '',
        ...filters,
    });

    return (
        <>
            <Head title="Registro de auditoría" />
            <Shell>
                <PageHeader
                    title="Registro de auditoría"
                    subtitle="Eventos del sistema de tus empresas, incluidas las acciones de los propios auditores"
                />
                <Card title="Filtros">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                href('/work/audit/system-events'),
                                Object.fromEntries(
                                    Object.entries(form).filter(([, v]) => v),
                                ),
                            );
                        }}
                    >
                        <select
                            aria-label="Empresa"
                            className={`${input} w-52`}
                            value={form.company_id}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    company_id: event.target.value,
                                })
                            }
                        >
                            <option value="">Todas mis empresas</option>
                            {context.companies.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <input
                            className={`${input} w-40`}
                            placeholder="Usuario"
                            value={form.user}
                            onChange={(event) =>
                                setForm({ ...form, user: event.target.value })
                            }
                        />
                        <input
                            className={`${input} w-48`}
                            placeholder="Evento (p. ej. journal)"
                            value={form.action}
                            onChange={(event) =>
                                setForm({ ...form, action: event.target.value })
                            }
                        />
                        <input
                            type="date"
                            className={`${input} w-40`}
                            value={form.from}
                            onChange={(event) =>
                                setForm({ ...form, from: event.target.value })
                            }
                        />
                        <input
                            type="date"
                            className={`${input} w-40`}
                            value={form.to}
                            onChange={(event) =>
                                setForm({ ...form, to: event.target.value })
                            }
                        />
                        <button type="submit" className={secondaryButton}>
                            Filtrar
                        </button>
                    </form>
                </Card>
                <Card title={`${events.length} evento(s)`}>
                    <EventsTable events={events} />
                </Card>
            </Shell>
        </>
    );
}
