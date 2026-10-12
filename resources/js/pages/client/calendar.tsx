import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import { secondaryButton } from '@/components/office-admin/kit';

type Event = {
    date: string;
    type: string;
    title: string;
    subtitle: string;
    priority: string;
    destination: string;
};

const typeLabel: Record<string, [string, string]> = {
    task: ['Entrega', 'amber'],
    review: ['Autorización', 'violet'],
    statutory: ['Obligación', 'blue'],
    internal: ['Reporte', 'green'],
};

export default function ClientCalendar({
    month,
    events,
}: {
    month: string;
    events: Event[];
}) {
    const shift = (delta: number) => {
        const [year, current] = month.split('-').map(Number);
        const next = new Date(year, current - 1 + delta, 1);
        router.get(
            '/client/calendar',
            {
                month: `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`,
            },
            { preserveScroll: true },
        );
    };

    return (
        <ClientPage
            title="Calendario"
            subtitle="Fechas límite de entregas y autorizaciones, obligaciones y reportes publicados"
        >
            <Card
                title={new Date(`${month}-01T12:00:00`).toLocaleDateString(
                    'es-GT',
                    { month: 'long', year: 'numeric' },
                )}
                action={
                    <div className="flex gap-1">
                        <button
                            type="button"
                            aria-label="Mes anterior"
                            className={secondaryButton}
                            onClick={() => shift(-1)}
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                        <button
                            type="button"
                            aria-label="Mes siguiente"
                            className={secondaryButton}
                            onClick={() => shift(1)}
                        >
                            <ChevronRight className="size-4" />
                        </button>
                    </div>
                }
            >
                <Table
                    headers={['Fecha', 'Tipo', 'Evento', 'Detalle']}
                    empty={events.length === 0 && 'Sin fechas este mes.'}
                >
                    {events.map((event, index) => {
                        const [label, tone] = typeLabel[event.type] ?? [
                            event.type,
                            'slate',
                        ];

                        return (
                            <tr key={`${event.date}-${index}`}>
                                <td
                                    className={`px-2 py-2 whitespace-nowrap ${event.priority === 'critical' ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(event.date)}
                                </td>
                                <td className="px-2 py-2">
                                    <span className={toneBadge(tone)}>
                                        {label}
                                    </span>
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={event.destination}
                                        className="hover:text-[#640000]"
                                    >
                                        {event.title}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{event.subtitle}</td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>
        </ClientPage>
    );
}
