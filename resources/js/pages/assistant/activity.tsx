import { Head } from '@inertiajs/react';
import type { AssistantContext } from '@/components/assistant/kit';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Event = {
    id: number;
    action: string;
    module: string;
    record: string;
    occurred_at: string;
};

export default function AssistantActivity({
    events,
}: {
    context: AssistantContext;
    events: Event[];
}) {
    return (
        <>
            <Head title="Actividad" />
            <Shell>
                <PageHeader
                    title="Mi actividad"
                    subtitle="Lo que registraste, corregiste y enviaste (tomado de la bitácora de auditoría)"
                />
                <Card title={`${events.length} evento(s)`}>
                    <Table
                        headers={['Fecha', 'Módulo', 'Acción', 'Registro']}
                        empty={
                            events.length === 0 && 'Sin actividad registrada.'
                        }
                    >
                        {events.map((event) => (
                            <tr key={event.id}>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {new Date(event.occurred_at).toLocaleString(
                                        'es-GT',
                                    )}
                                </td>
                                <td className="px-2 py-2">{event.module}</td>
                                <td className="px-2 py-2 font-mono text-[11px]">
                                    {event.action}
                                </td>
                                <td className="px-2 py-2">{event.record}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
