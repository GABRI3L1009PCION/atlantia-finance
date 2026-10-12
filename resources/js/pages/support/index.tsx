import { Head, Link, useForm } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import {
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    input,
    panel,
    primaryButton,
    relativeTime,
} from '@/components/office-admin/kit';
import type { Tone } from '@/components/office-admin/kit';

type Ticket = {
    id: number;
    code: string;
    subject: string;
    module_label: string | null;
    priority: string;
    priority_label: string;
    status: string;
    status_label: string;
    created_at: string;
    resolved_at: string | null;
};
type Detail = Ticket & {
    description: string;
    messages: {
        id: number;
        author: string;
        side: string;
        body: string;
        at: string;
    }[];
};

const statusTone: Record<string, Tone> = {
    open: 'blue',
    in_progress: 'amber',
    waiting_customer: 'red',
    waiting_internal: 'amber',
    resolved: 'green',
    closed: 'slate',
};

function NewTicket({
    modules,
    priorities,
}: {
    modules: Record<string, string>;
    priorities: Record<string, string>;
}) {
    const form = useForm({
        subject: '',
        description: '',
        module: Object.keys(modules)[0] ?? 'otro',
        priority: 'normal',
    });

    return (
        <section className={`${panel} p-4`}>
            <h2 className="mb-3 text-lg font-bold">Nueva solicitud</h2>
            <form
                className="grid gap-3 md:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/support', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <label className="text-xs font-semibold text-slate-600 md:col-span-3">
                    Asunto
                    <input
                        className={`${input} mt-1`}
                        value={form.data.subject}
                        onChange={(e) =>
                            form.setData('subject', e.target.value)
                        }
                        required
                        maxLength={200}
                    />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Módulo
                    <select
                        className={`${input} mt-1`}
                        value={form.data.module}
                        onChange={(e) => form.setData('module', e.target.value)}
                    >
                        {Object.entries(modules).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Prioridad
                    <select
                        className={`${input} mt-1`}
                        value={form.data.priority}
                        onChange={(e) =>
                            form.setData('priority', e.target.value)
                        }
                    >
                        {Object.entries(priorities).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600 md:col-span-3">
                    Describe el problema
                    <textarea
                        className="mt-1 min-h-28 w-full rounded-lg border border-slate-300 bg-white p-3 text-sm outline-none focus:border-[#8a5b32]"
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        required
                        maxLength={5000}
                    />
                </label>
                <p className="text-xs text-slate-500 md:col-span-2">
                    El equipo de Atlantia no puede ver tu información contable
                    ni de nómina con solo abrir un ticket; si lo necesita, te lo
                    indicará y el acceso quedará registrado.
                </p>
                <div className="flex justify-end">
                    <button
                        className={primaryButton}
                        disabled={form.processing}
                    >
                        Enviar
                    </button>
                </div>
            </form>
        </section>
    );
}

function Conversation({ ticket }: { ticket: Detail }) {
    const form = useForm({ body: '' });

    return (
        <section className={`${panel} p-4`}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-bold">
                    {ticket.code} · {ticket.subject}
                </h2>
                <span className={badge(statusTone[ticket.status] ?? 'slate')}>
                    {ticket.status_label}
                </span>
            </div>
            <div className="max-h-[460px] space-y-2 overflow-y-auto pr-1">
                {ticket.messages.map((message) => (
                    <div
                        key={message.id}
                        className={`rounded-lg border p-3 text-sm ${message.side === 'staff' ? 'mr-8 border-blue-200 bg-blue-50' : 'ml-8 border-slate-200 bg-slate-50'}`}
                    >
                        <p className="mb-1 text-[11px] text-slate-500">
                            {message.side === 'staff'
                                ? `Soporte Atlantia · ${message.author}`
                                : message.author}{' '}
                            · {relativeTime(message.at)}
                        </p>
                        <p className="whitespace-pre-wrap">{message.body}</p>
                    </div>
                ))}
            </div>
            {ticket.status !== 'closed' ? (
                <form
                    className="mt-3 space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/support/${ticket.id}/reply`, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <textarea
                        className="min-h-20 w-full rounded-lg border border-slate-300 bg-white p-3 text-sm outline-none focus:border-[#8a5b32]"
                        placeholder="Escribe tu mensaje…"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        required
                    />
                    <button
                        className={primaryButton}
                        disabled={form.processing}
                    >
                        Responder
                    </button>
                </form>
            ) : (
                <p className="mt-3 text-sm text-slate-500">
                    Este ticket está cerrado. Si el problema continúa, abre una
                    solicitud nueva.
                </p>
            )}
        </section>
    );
}

export default function SupportIndex({
    tickets,
    selected,
    modules,
    priorities,
    notice,
}: {
    tickets: Ticket[];
    selected: Detail | null;
    modules: Record<string, string>;
    priorities: Record<string, string>;
    notice: string | null;
}) {
    return (
        <>
            <Head title="Soporte" />
            <Shell>
                <PageHeader
                    title="Soporte Atlantia"
                    subtitle="Abre solicitudes al equipo de Atlantia Systems y sigue la conversación."
                />
                <Notice message={notice} />
                <NewTicket modules={modules} priorities={priorities} />
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                    <section className={`${panel} p-4`}>
                        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold">
                            <LifeBuoy className="size-5 text-[#640000]" /> Mis
                            solicitudes
                        </h2>
                        {tickets.length === 0 ? (
                            <p className="py-6 text-center text-sm text-slate-500">
                                Aún no has abierto solicitudes.
                            </p>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {tickets.map((ticket) => (
                                    <li key={ticket.id}>
                                        <Link
                                            href={`/support?ticket=${ticket.id}`}
                                            preserveScroll
                                            className={`flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50 ${selected?.id === ticket.id ? 'bg-slate-50' : ''}`}
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold">
                                                    {ticket.code} ·{' '}
                                                    {ticket.subject}
                                                </span>
                                                <span className="text-xs text-slate-500">
                                                    {ticket.module_label} ·{' '}
                                                    {formatDate(
                                                        ticket.created_at,
                                                    )}
                                                </span>
                                            </span>
                                            <span
                                                className={badge(
                                                    statusTone[ticket.status] ??
                                                        'slate',
                                                )}
                                            >
                                                {ticket.status_label}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    {selected ? (
                        <Conversation key={selected.id} ticket={selected} />
                    ) : (
                        <section
                            className={`${panel} grid place-items-center p-10 text-sm text-slate-500`}
                        >
                            Selecciona una solicitud para ver la conversación.
                        </section>
                    )}
                </div>
            </Shell>
        </>
    );
}
