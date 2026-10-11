import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    PageHeader,
    Shell,
    panel,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { Context, TeamUser } from '@/components/operations/kit';

type Event = {
    date: string;
    type: string;
    title: string;
    subtitle: string | null;
    priority: string;
    responsible: string | null;
    responsible_id: number | null;
    destination: string | null;
};

const types: Record<string, [string, string]> = {
    task: ['Tareas', 'bg-blue-500'],
    fiscal: ['Fiscal', 'bg-amber-400'],
    review: ['Revisiones', 'bg-indigo-500'],
    closing: ['Fin de período', 'bg-emerald-500'],
    deadline: ['Límite de cierre', 'bg-red-500'],
};

export default function OperationsCalendar({
    month,
    events,
    team,
}: {
    context: Context;
    month: string;
    events: Event[];
    team: TeamUser[];
}) {
    const page = usePage();
    const [type, setType] = useState('');
    const [responsible, setResponsible] = useState('');
    const first = new Date(`${month}-01T00:00:00`);
    const days = new Date(
        first.getFullYear(),
        first.getMonth() + 1,
        0,
    ).getDate();
    const offset = (first.getDay() + 6) % 7;
    const visible = events.filter(
        (event) =>
            (!type || event.type === type) &&
            (!responsible || event.responsible_id?.toString() === responsible),
    );
    const byDay = (day: number) =>
        visible.filter(
            (event) =>
                new Date(
                    event.date.length === 10
                        ? `${event.date}T00:00:00`
                        : event.date,
                ).getDate() === day,
        );
    const move = (delta: number) => {
        const target = new Date(
            first.getFullYear(),
            first.getMonth() + delta,
            1,
        );
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            page.url.split('?')[0],
            {
                ...params,
                month: `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}`,
            },
            { preserveScroll: true },
        );
    };
    const today = new Date();

    return (
        <>
            <Head title="Calendario operativo" />
            <Shell>
                <PageHeader
                    title="Calendario"
                    subtitle="Vencimientos de tareas, revisiones, fin de períodos y límites de cierre"
                />
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={() => move(-1)}
                        aria-label="Mes anterior"
                    >
                        <ChevronLeft className="size-4" />
                    </button>
                    <p className="min-w-40 text-center text-lg font-black capitalize">
                        {new Intl.DateTimeFormat('es-GT', {
                            month: 'long',
                            year: 'numeric',
                        }).format(first)}
                    </p>
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={() => move(1)}
                        aria-label="Mes siguiente"
                    >
                        <ChevronRight className="size-4" />
                    </button>
                    <select
                        aria-label="Tipo"
                        className="h-9 rounded-lg border border-slate-200 px-2 text-xs"
                        value={type}
                        onChange={(event) => setType(event.target.value)}
                    >
                        <option value="">Todos los tipos</option>
                        {Object.entries(types).map(([key, [label]]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Responsable"
                        className="h-9 rounded-lg border border-slate-200 px-2 text-xs"
                        value={responsible}
                        onChange={(event) => setResponsible(event.target.value)}
                    >
                        <option value="">Todos los responsables</option>
                        {team.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                    <div className="ml-auto flex flex-wrap gap-3 text-xs">
                        {Object.entries(types).map(([key, [label, color]]) => (
                            <span key={key} className="flex items-center gap-1">
                                <span
                                    className={`size-2.5 rounded-full ${color}`}
                                />
                                {label}
                            </span>
                        ))}
                    </div>
                </div>
                <section className={`${panel} overflow-x-auto p-3`}>
                    <div className="grid min-w-[760px] grid-cols-7 gap-1.5">
                        {['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map(
                            (day) => (
                                <p
                                    key={day}
                                    className="text-center text-xs font-bold text-[#526b92]"
                                >
                                    {day}
                                </p>
                            ),
                        )}
                        {Array.from({ length: offset }).map((_, index) => (
                            <div key={`empty-${index}`} />
                        ))}
                        {Array.from(
                            { length: days },
                            (_, index) => index + 1,
                        ).map((day) => {
                            const isToday =
                                today.getFullYear() === first.getFullYear() &&
                                today.getMonth() === first.getMonth() &&
                                today.getDate() === day;

                            return (
                                <div
                                    key={day}
                                    className={`min-h-28 rounded-lg border p-1.5 ${isToday ? 'border-[#640000] bg-red-50/40' : 'border-slate-100'}`}
                                >
                                    <p className="text-xs font-black">{day}</p>
                                    <ul className="mt-1 space-y-1">
                                        {byDay(day).map((event, index) => (
                                            <li key={index}>
                                                <Link
                                                    href={
                                                        event.destination ?? '#'
                                                    }
                                                    className="flex items-start gap-1 rounded bg-slate-50 px-1 py-0.5 text-[11px] leading-tight hover:bg-slate-100"
                                                    title={`${event.title} · ${event.subtitle ?? ''}`}
                                                >
                                                    <span
                                                        className={`mt-1 size-2 shrink-0 rounded-full ${types[event.type]?.[1] ?? 'bg-slate-400'}`}
                                                    />
                                                    <span className="line-clamp-2">
                                                        {event.title}
                                                        {event.subtitle
                                                            ? ` · ${event.subtitle}`
                                                            : ''}
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>
                </section>
            </Shell>
        </>
    );
}
