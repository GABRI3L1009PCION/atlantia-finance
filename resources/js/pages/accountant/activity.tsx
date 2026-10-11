import { Head } from '@inertiajs/react';
import type { AccountantContext } from '@/components/accountant/kit';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Event = {
    id: number;
    user: string;
    action: string;
    module: string;
    record: string;
    occurred_at: string;
};

export default function AccountantActivity({
    events,
}: {
    context: AccountantContext;
    events: Event[];
}) {
    return (
        <>
            <Head title="Actividad" />
            <Shell>
                <PageHeader
                    title="Actividad"
                    subtitle="Últimos movimientos registrados en tus empresas (solo lectura)"
                />
                <Card title={`${events.length} evento(s)`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Usuario',
                            'Módulo',
                            'Acción',
                            'Registro',
                        ]}
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
                                <td className="px-2 py-2">{event.user}</td>
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
