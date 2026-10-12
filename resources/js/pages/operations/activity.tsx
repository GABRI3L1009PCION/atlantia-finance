import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    panel,
} from '@/components/office-admin/kit';
import type { Context } from '@/components/operations/kit';

type Event = {
    event_id: number;
    description: string;
    action: string;
    created_at: string;
    destination: string | null;
};

export default function OperationsActivity({
    events,
}: {
    context: Context;
    events: Event[];
}) {
    return (
        <>
            <Head title="Actividad operativa" />
            <Shell>
                <PageHeader
                    title="Actividad"
                    subtitle="Movimientos del trabajo: tareas, revisiones e incidencias de la oficina"
                />
                <section className={`${panel} divide-y divide-slate-100`}>
                    {events.map((event) => (
                        <div
                            key={event.event_id}
                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                        >
                            <p className="font-semibold text-[#061633]">
                                {event.description}
                            </p>
                            <div className="flex items-center gap-3 text-xs text-[#526b92]">
                                {formatDate(event.created_at, true)}
                                {event.destination && (
                                    <Link
                                        href={event.destination}
                                        className="font-bold text-[#640000]"
                                    >
                                        Abrir
                                    </Link>
                                )}
                            </div>
                        </div>
                    ))}
                    {events.length === 0 && (
                        <p className="p-6 text-center text-sm text-slate-500">
                            Sin actividad operativa registrada.
                        </p>
                    )}
                </section>
            </Shell>
        </>
    );
}
