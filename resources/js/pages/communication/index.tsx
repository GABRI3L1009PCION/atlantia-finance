import { Head, router, useForm } from '@inertiajs/react';
import { Mail, MailOpen, Reply, Send } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    panel,
    primaryButton,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';
import { toneBadge } from '@/components/supervisor/kit';

type Message = {
    id: number;
    type: string;
    type_label: string;
    subject: string;
    body: string;
    due_at: string | null;
    read: boolean;
    incoming: boolean;
    sender_name: string | null;
    recipient_name: string | null;
    company_name: string | null;
    parent_id: number | null;
    created_at: string;
};
type Recipient = {
    id: number;
    name: string;
    kind: string;
    company_ids: number[];
};

const typeTone: Record<string, string> = {
    message: 'slate',
    document_request: 'amber',
    reminder: 'blue',
    observation: 'violet',
    notification: 'green',
};

export default function Communication({
    messages,
    recipients,
    companies,
    types,
    canSend,
    focus,
    notice,
}: {
    messages: Message[];
    recipients: Recipient[];
    companies: { id: number; name: string }[];
    types: Record<string, string>;
    canSend: boolean;
    focus: number | null;
    notice: string | null;
}) {
    const [selected, setSelected] = useState<number | null>(
        focus ?? messages[0]?.id ?? null,
    );
    const [folder, setFolder] = useState<'all' | 'in' | 'out'>('all');
    const compose = useForm({
        company_id: '',
        recipient_id: '',
        type: 'message',
        subject: '',
        body: '',
        due_at: '',
    });
    const reply = useForm({
        parent_id: '',
        type: 'message',
        subject: '',
        body: '',
    });
    const message = messages.find((item) => item.id === selected) ?? null;
    const thread = useMemo(() => {
        if (!message) {
            return [];
        }

        const root = message.parent_id ?? message.id;

        return messages
            .filter((item) => item.id === root || item.parent_id === root)
            .sort((a, b) => a.created_at.localeCompare(b.created_at));
    }, [message, messages]);
    const visible = messages.filter(
        (item) =>
            folder === 'all' ||
            (folder === 'in' ? item.incoming : !item.incoming),
    );
    const availableRecipients = recipients.filter(
        (recipient) =>
            recipient.kind === 'Equipo' ||
            compose.data.company_id === '' ||
            recipient.company_ids.includes(Number(compose.data.company_id)),
    );
    const open = (item: Message) => {
        setSelected(item.id);

        if (item.incoming && !item.read) {
            router.post(
                `/communication/${item.id}/read`,
                {},
                { preserveScroll: true, preserveState: true },
            );
        }
    };
    const send = (event: FormEvent) => {
        event.preventDefault();
        compose.post('/communication', {
            preserveScroll: true,
            onSuccess: () => compose.reset('subject', 'body', 'due_at'),
        });
    };
    const answer = (event: FormEvent) => {
        event.preventDefault();

        if (!message) {
            return;
        }

        reply.transform((data) => ({
            ...data,
            parent_id: String(message.parent_id ?? message.id),
            subject: message.subject.startsWith('Re: ')
                ? message.subject
                : `Re: ${message.subject}`,
        }));
        reply.post('/communication', {
            preserveScroll: true,
            onSuccess: () => reply.reset('body'),
        });
    };

    return (
        <>
            <Head title="Comunicación" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Comunicación"
                    subtitle="Mensajes, solicitudes de documentos, recordatorios y observaciones con historial"
                />
                <div className="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <section className={`${panel} min-w-0 p-3`}>
                        <div className="mb-2 flex gap-1">
                            {(
                                [
                                    ['all', 'Todos'],
                                    ['in', 'Recibidos'],
                                    ['out', 'Enviados'],
                                ] as const
                            ).map(([code, label]) => (
                                <button
                                    key={code}
                                    type="button"
                                    className={`${secondaryButton} ${folder === code ? 'border-[#640000] text-[#640000]' : ''}`}
                                    onClick={() => setFolder(code)}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <ul className="max-h-[560px] divide-y divide-slate-100 overflow-y-auto text-sm">
                            {visible.length === 0 && (
                                <li className="py-8 text-center text-slate-500">
                                    Sin mensajes.
                                </li>
                            )}
                            {visible.map((item) => (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        className={`flex w-full items-start gap-2 px-1 py-2 text-left ${item.id === selected ? 'bg-[#fbf6f3]' : ''}`}
                                        onClick={() => open(item)}
                                    >
                                        {item.incoming && !item.read ? (
                                            <Mail className="mt-0.5 size-4 shrink-0 text-[#640000]" />
                                        ) : (
                                            <MailOpen className="mt-0.5 size-4 shrink-0 text-slate-400" />
                                        )}
                                        <span className="min-w-0 flex-1">
                                            <span
                                                className={`block truncate ${item.incoming && !item.read ? 'font-bold' : ''}`}
                                            >
                                                {item.subject}
                                            </span>
                                            <span className="block truncate text-xs text-[#526b92]">
                                                {item.incoming
                                                    ? `De ${item.sender_name ?? 'Sistema'}`
                                                    : `Para ${item.recipient_name ?? '—'}`}
                                                {item.company_name
                                                    ? ` · ${item.company_name}`
                                                    : ''}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-[11px] text-[#526b92]">
                                            {relativeTime(item.created_at)}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <div className="grid min-w-0 content-start gap-3">
                        {message && (
                            <section className={`${panel} p-3`}>
                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                    <h2 className="text-lg font-black text-[#061633]">
                                        {message.subject}
                                    </h2>
                                    <span
                                        className={toneBadge(
                                            typeTone[message.type] ?? 'slate',
                                        )}
                                    >
                                        {message.type_label}
                                    </span>
                                    {message.company_name && (
                                        <span className="text-xs text-[#526b92]">
                                            {message.company_name}
                                        </span>
                                    )}
                                </div>
                                <ol className="space-y-2">
                                    {thread.map((item) => (
                                        <li
                                            key={item.id}
                                            className={`rounded-xl border p-3 text-sm ${item.incoming ? 'border-[#e7e1dd] bg-white' : 'border-[#f0e2d8] bg-[#fbf6f3]'}`}
                                        >
                                            <p className="text-xs text-[#526b92]">
                                                {item.sender_name ?? 'Sistema'}{' '}
                                                → {item.recipient_name ?? '—'} ·{' '}
                                                {new Date(
                                                    item.created_at,
                                                ).toLocaleString('es-GT')}
                                                {item.due_at
                                                    ? ` · Fecha límite ${new Date(item.due_at).toLocaleDateString('es-GT')}`
                                                    : ''}
                                            </p>
                                            <p className="mt-1 whitespace-pre-wrap">
                                                {item.body}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                                <form
                                    onSubmit={answer}
                                    className="mt-3 grid gap-2"
                                >
                                    <textarea
                                        aria-label="Respuesta"
                                        className={`${input} h-20 py-2`}
                                        placeholder="Escribe una respuesta"
                                        value={reply.data.body}
                                        onChange={(event) =>
                                            reply.setData(
                                                'body',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {reply.errors.body && (
                                        <p className="text-sm text-red-700">
                                            {reply.errors.body}
                                        </p>
                                    )}
                                    <div className="flex justify-end">
                                        <button
                                            type="submit"
                                            className={secondaryButton}
                                            disabled={reply.processing}
                                        >
                                            <Reply className="size-3.5" />{' '}
                                            Responder
                                        </button>
                                    </div>
                                </form>
                            </section>
                        )}

                        {canSend && (
                            <section className={`${panel} p-3`}>
                                <h2 className="mb-2 text-lg font-black text-[#061633]">
                                    Nuevo mensaje
                                </h2>
                                <form
                                    onSubmit={send}
                                    className="grid gap-3 sm:grid-cols-2"
                                >
                                    <label className="text-sm font-semibold">
                                        Empresa
                                        <select
                                            className={`${input} mt-1`}
                                            value={compose.data.company_id}
                                            onChange={(event) =>
                                                compose.setData({
                                                    ...compose.data,
                                                    company_id:
                                                        event.target.value,
                                                    recipient_id: '',
                                                })
                                            }
                                        >
                                            <option value="">
                                                Sin empresa
                                            </option>
                                            {companies.map((company) => (
                                                <option
                                                    key={company.id}
                                                    value={company.id}
                                                >
                                                    {company.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-sm font-semibold">
                                        Destinatario
                                        <select
                                            className={`${input} mt-1`}
                                            value={compose.data.recipient_id}
                                            onChange={(event) =>
                                                compose.setData(
                                                    'recipient_id',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">Elegir</option>
                                            {availableRecipients.map(
                                                (recipient) => (
                                                    <option
                                                        key={recipient.id}
                                                        value={recipient.id}
                                                    >
                                                        {recipient.name} ·{' '}
                                                        {recipient.kind}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <label className="text-sm font-semibold">
                                        Tipo
                                        <select
                                            className={`${input} mt-1`}
                                            value={compose.data.type}
                                            onChange={(event) =>
                                                compose.setData(
                                                    'type',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {Object.entries(types).map(
                                                ([code, label]) => (
                                                    <option
                                                        key={code}
                                                        value={code}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <label className="text-sm font-semibold">
                                        Fecha límite
                                        <input
                                            type="date"
                                            className={`${input} mt-1`}
                                            value={compose.data.due_at}
                                            onChange={(event) =>
                                                compose.setData(
                                                    'due_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="text-sm font-semibold sm:col-span-2">
                                        Asunto
                                        <input
                                            className={`${input} mt-1`}
                                            value={compose.data.subject}
                                            onChange={(event) =>
                                                compose.setData(
                                                    'subject',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-sm font-semibold sm:col-span-2">
                                        Mensaje
                                        <textarea
                                            className={`${input} mt-1 h-24 py-2`}
                                            value={compose.data.body}
                                            onChange={(event) =>
                                                compose.setData(
                                                    'body',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </label>
                                    {compose.data.type ===
                                        'document_request' && (
                                        <p className="text-xs text-amber-700 sm:col-span-2">
                                            La solicitud de documentos también
                                            registra una incidencia
                                            “Documentación incompleta” en la
                                            empresa.
                                        </p>
                                    )}
                                    {Object.values(compose.errors).length >
                                        0 && (
                                        <p className="text-sm text-red-700 sm:col-span-2">
                                            {Object.values(compose.errors)[0]}
                                        </p>
                                    )}
                                    <div className="flex justify-end sm:col-span-2">
                                        <button
                                            type="submit"
                                            className={primaryButton}
                                            disabled={compose.processing}
                                        >
                                            <Send className="size-4" /> Enviar
                                        </button>
                                    </div>
                                </form>
                            </section>
                        )}
                    </div>
                </div>
            </Shell>
        </>
    );
}
