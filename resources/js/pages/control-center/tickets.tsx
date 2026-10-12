import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CCPage,
    Panel,
    Pill,
    Table,
    ago,
    ccButton,
    ccInput,
    ccTextarea,
    date,
    statusTone,
} from '@/components/control-center/kit';

type Ticket = {
    id: number;
    code: string;
    organization_id: number;
    organization: string;
    requester: string | null;
    subject: string;
    module: string | null;
    module_label: string | null;
    priority: string;
    priority_label: string;
    status: string;
    status_label: string;
    assigned_to: number | null;
    assignee: string | null;
    created_at: string;
    resolved_at: string | null;
    first_response_at: string | null;
};
type Detail = Ticket & {
    description: string;
    messages: {
        id: number;
        author: string;
        side: string;
        internal: boolean;
        body: string;
        at: string;
    }[];
};

const priorityTone: Record<string, string> = {
    low: 'slate',
    normal: 'blue',
    high: 'amber',
    urgent: 'red',
    critical: 'red',
};

function Conversation({
    ticket,
    statuses,
    priorities,
    staff,
    canManage,
    canRequestAccess,
}: {
    ticket: Detail;
    statuses: Record<string, string>;
    priorities: Record<string, string>;
    staff: { id: number; name: string }[];
    canManage: boolean;
    canRequestAccess: boolean;
}) {
    const form = useForm({ body: '', internal: false, status: '' });
    const update = (values: Record<string, string | number>) =>
        router.put(`/control-center/support/tickets/${ticket.id}`, values, {
            preserveScroll: true,
        });

    return (
        <Panel
            title={
                <>
                    {ticket.code} · {ticket.subject}
                </>
            }
            action={
                canRequestAccess && (
                    <Link
                        href={`/control-center/support-access?organization=${ticket.organization_id}`}
                        className="text-xs text-[#e8b04b]"
                    >
                        Solicitar acceso de soporte →
                    </Link>
                )
            }
        >
            <p className="mb-3 text-xs text-slate-400">
                {ticket.organization} · {ticket.requester ?? '—'} ·{' '}
                {ticket.module_label} · abierto {date(ticket.created_at, true)}
            </p>
            {canManage && (
                <div className="mb-3 grid gap-2 sm:grid-cols-3">
                    <select
                        className={ccInput}
                        value={ticket.status}
                        onChange={(e) => update({ status: e.target.value })}
                    >
                        {Object.entries(statuses).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <select
                        className={ccInput}
                        value={ticket.priority}
                        onChange={(e) => update({ priority: e.target.value })}
                    >
                        {Object.entries(priorities).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <select
                        className={ccInput}
                        value={ticket.assigned_to ?? ''}
                        onChange={(e) =>
                            e.target.value &&
                            update({ assigned_to: Number(e.target.value) })
                        }
                    >
                        <option value="">Sin asignar</option>
                        {staff.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div className="max-h-[420px] space-y-2 overflow-y-auto pr-1">
                {ticket.messages.map((message) => (
                    <div
                        key={message.id}
                        className={`rounded-lg border p-3 text-sm ${message.internal ? 'border-[#f59e0b]/30 bg-[#f59e0b]/10' : message.side === 'staff' ? 'ml-8 border-[#3b82f6]/30 bg-[#3b82f6]/10' : 'mr-8 border-white/10 bg-white/[0.03]'}`}
                    >
                        <p className="mb-1 text-[11px] text-slate-400">
                            {message.author} ·{' '}
                            {message.internal
                                ? 'Nota interna'
                                : message.side === 'staff'
                                  ? 'Atlantia'
                                  : 'Cliente'}{' '}
                            · {ago(message.at)}
                        </p>
                        <p className="whitespace-pre-wrap text-slate-200">
                            {message.body}
                        </p>
                    </div>
                ))}
            </div>
            {canManage && ticket.status !== 'closed' && (
                <form
                    className="mt-3 space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            ...d,
                            status: d.status || null,
                        }));
                        form.post(
                            `/control-center/support/tickets/${ticket.id}/reply`,
                            {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            },
                        );
                    }}
                >
                    <textarea
                        className={ccTextarea}
                        rows={3}
                        placeholder="Escribe la respuesta…"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        required
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-xs text-slate-300">
                            <input
                                type="checkbox"
                                checked={form.data.internal}
                                onChange={(e) =>
                                    form.setData('internal', e.target.checked)
                                }
                            />{' '}
                            Nota interna (el cliente no la ve)
                        </label>
                        <select
                            className={`${ccInput} h-8 w-52`}
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            <option value="">Mantener estado</option>
                            {Object.entries(statuses).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <button className={ccButton} disabled={form.processing}>
                            {form.data.internal ? 'Guardar nota' : 'Responder'}
                        </button>
                    </div>
                </form>
            )}
        </Panel>
    );
}

export default function Tickets({
    tickets,
    filters,
    selected,
    statuses,
    priorities,
    staff,
    organizations,
    can_manage,
    can_request_access,
}: {
    tickets: Ticket[];
    filters: Record<string, string | number | undefined>;
    selected: Detail | null;
    statuses: Record<string, string>;
    priorities: Record<string, string>;
    staff: { id: number; name: string }[];
    organizations: { id: number; name: string }[];
    can_manage: boolean;
    can_request_access: boolean;
}) {
    const filter = (key: string, value: string) =>
        router.get(
            '/control-center/support/tickets',
            { ...filters, [key]: value || undefined },
            { preserveState: true },
        );

    return (
        <>
            <Head title="Tickets · Control Center" />
            <CCPage
                title="Tickets de soporte"
                subtitle="Solicitudes de clientes. Un ticket no da acceso a los datos de la organización: para revisar información se solicita un acceso de soporte formal."
            >
                <div className="flex flex-wrap gap-2">
                    <select
                        className={`${ccInput} max-w-[200px]`}
                        value={filters.status ?? ''}
                        onChange={(e) => filter('status', e.target.value)}
                    >
                        <option value="">Abiertos (todos)</option>
                        {Object.entries(statuses).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <select
                        className={`${ccInput} max-w-[160px]`}
                        value={filters.priority ?? ''}
                        onChange={(e) => filter('priority', e.target.value)}
                    >
                        <option value="">Toda prioridad</option>
                        {Object.entries(priorities).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <select
                        className={`${ccInput} max-w-[220px]`}
                        value={filters.organization_id ?? ''}
                        onChange={(e) =>
                            filter('organization_id', e.target.value)
                        }
                    >
                        <option value="">Toda organización</option>
                        {organizations.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={`${ccInput} max-w-[200px]`}
                        value={filters.assigned_to ?? ''}
                        onChange={(e) => filter('assigned_to', e.target.value)}
                    >
                        <option value="">Cualquier responsable</option>
                        {staff.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-3 xl:grid-cols-[1fr_1fr]">
                    <Panel title={`${tickets.length} tickets`}>
                        <Table
                            headers={[
                                'Ticket',
                                'Organización',
                                'Prioridad',
                                'Estado',
                                'Responsable',
                                'Abierto',
                            ]}
                            empty={
                                tickets.length === 0 &&
                                'Sin tickets con ese filtro.'
                            }
                        >
                            {tickets.map((ticket) => (
                                <tr
                                    key={ticket.id}
                                    className={
                                        selected?.id === ticket.id
                                            ? 'bg-white/[0.04]'
                                            : ''
                                    }
                                >
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/support/tickets?${new URLSearchParams(
                                                {
                                                    ...Object.fromEntries(
                                                        Object.entries(filters)
                                                            .filter(
                                                                ([, v]) =>
                                                                    v !==
                                                                        undefined &&
                                                                    v !== null,
                                                            )
                                                            .map(([k, v]) => [
                                                                k,
                                                                String(v),
                                                            ]),
                                                    ),
                                                    ticket: String(ticket.id),
                                                },
                                            )}`}
                                            preserveScroll
                                            className="font-semibold text-white"
                                        >
                                            {ticket.code}
                                        </Link>
                                        <span className="block max-w-[220px] truncate text-[11px] text-slate-400">
                                            {ticket.subject}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {ticket.organization}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill
                                            tone={priorityTone[ticket.priority]}
                                        >
                                            {ticket.priority_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[ticket.status]}>
                                            {ticket.status_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        {ticket.assignee ?? '—'}
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {ago(ticket.created_at)}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                    {selected ? (
                        <Conversation
                            key={selected.id}
                            ticket={selected}
                            statuses={statuses}
                            priorities={priorities}
                            staff={staff}
                            canManage={can_manage}
                            canRequestAccess={can_request_access}
                        />
                    ) : (
                        <Panel>
                            <p className="py-10 text-center text-sm text-slate-500">
                                Selecciona un ticket para ver la conversación.
                            </p>
                        </Panel>
                    )}
                </div>
            </CCPage>
        </>
    );
}
