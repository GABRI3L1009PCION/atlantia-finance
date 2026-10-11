import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    panel,
} from '@/components/office-admin/kit';

type Event = {
    event_id: number;
    actor: string;
    action: string;
    description: string;
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
                <section className={`${panel} divide-y divide-slate-100`}>
                    {events.map((event) => (
                        <div
                            key={event.event_id}
                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                        >
                            <div>
                                <p className="font-semibold text-[#061633]">
                                    {event.description}
                                </p>
                                <p className="text-xs text-[#526b92]">
                                    {event.action}
                                </p>
                            </div>
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
                            No hay actividad administrativa registrada en este
                            alcance.
                        </p>
                    )}
                </section>
            </Shell>
        </>
    );
}
