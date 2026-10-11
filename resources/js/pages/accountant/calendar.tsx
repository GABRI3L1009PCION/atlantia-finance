import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useAccountantHref } from '@/components/accountant/kit';
import type { AccountantContext } from '@/components/accountant/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Event = {
    date: string;
    type: string;
    title: string;
    subtitle?: string | null;
    responsible?: string | null;
    destination?: string | null;
};

const typeLabel: Record<string, string> = {
    declaration: 'Fiscal',
    task: 'Tarea',
    closing: 'Cierre',
    review: 'Revisión',
};

export default function AccountantCalendar({
    month,
    events,
}: {
    context: AccountantContext;
    month: string;
    events: Event[];
}) {
    const href = useAccountantHref();
    const shift = (delta: number) => {
        const [year, current] = month.split('-').map(Number);
        const next = new Date(year, current - 1 + delta, 1);
        router.get(
            href('/accountant/calendar'),
            {
                month: `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Calendario" />
            <Shell>
                <PageHeader
                    title="Calendario"
                    subtitle="Vencimientos fiscales, tareas y cierres de tus empresas"
                />
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
                        headers={[
                            'Fecha',
                            'Tipo',
                            'Evento',
                            'Detalle',
                            'Responsable',
                        ]}
                        empty={events.length === 0 && 'Sin eventos este mes.'}
                    >
                        {[...events]
                            .sort((a, b) => a.date.localeCompare(b.date))
                            .map((event, index) => (
                                <tr key={`${event.date}-${index}`}>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {formatDate(event.date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {typeLabel[event.type] ?? event.type}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {event.destination ? (
                                            <a
                                                href={event.destination}
                                                className="hover:text-[#640000]"
                                            >
                                                {event.title}
                                            </a>
                                        ) : (
                                            event.title
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {event.subtitle ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {event.responsible ?? '—'}
                                    </td>
                                </tr>
                            ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
