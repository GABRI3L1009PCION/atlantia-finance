import { Link, useForm } from '@inertiajs/react';
import { textarea } from '@/components/auditor/kit';
import type {
    ClientPermissions,
    Conversation,
} from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    formatDate,
    relativeTime,
} from '@/components/client-portal/kit';
import { input, primaryButton } from '@/components/office-admin/kit';

export default function ClientMessages({
    conversations,
    open,
    permissions,
    notice,
}: {
    conversations: Conversation[];
    open: number | null;
    permissions: ClientPermissions;
    notice: string | null;
}) {
    const current = conversations.find(
        (conversation) => conversation.id === open,
    );
    const form = useForm({
        conversation_id: open,
        subject: '',
        body: '',
    });
    const canSend = permissions['client.messages.send'];

    return (
        <ClientPage
            title="Mensajes"
            subtitle="Conversaciones con tu oficina contable"
            notice={notice}
        >
            <div className="grid gap-3 lg:grid-cols-3">
                <Card title="Conversaciones">
                    <ul className="grid gap-1 text-sm">
                        {canSend && (
                            <li>
                                <Link
                                    href="/client/messages"
                                    className={`block rounded-lg px-3 py-2 font-bold ${open === null ? 'bg-red-50 text-[#640000]' : 'hover:bg-slate-50'}`}
                                >
                                    + Nueva conversación
                                </Link>
                            </li>
                        )}
                        {conversations.length === 0 && (
                            <li className="py-6 text-center text-slate-500">
                                Aún no hay conversaciones.
                            </li>
                        )}
                        {conversations.map((conversation) => (
                            <li key={conversation.id}>
                                <Link
                                    href={`/client/messages?conversation=${conversation.id}`}
                                    className={`block rounded-lg px-3 py-2 ${conversation.id === open ? 'bg-red-50' : 'hover:bg-slate-50'}`}
                                >
                                    <span className="flex justify-between gap-2">
                                        <span className="font-bold">
                                            {conversation.subject}
                                        </span>
                                        {conversation.unread > 0 && (
                                            <span className="rounded-full bg-[#640000] px-1.5 text-xs text-white">
                                                {conversation.unread}
                                            </span>
                                        )}
                                    </span>
                                    <span className="block truncate text-xs text-[#526b92]">
                                        {conversation.preview}
                                    </span>
                                    {conversation.last_message_at && (
                                        <span className="block text-[10px] text-[#526b92]">
                                            {relativeTime(
                                                conversation.last_message_at,
                                            )}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card
                    title={current ? current.subject : 'Nueva conversación'}
                    className="lg:col-span-2"
                >
                    {current && (
                        <ul className="mb-3 grid max-h-[28rem] gap-2 overflow-y-auto">
                            {current.messages.map((message) => (
                                <li
                                    key={message.id}
                                    className={`max-w-[85%] rounded-xl p-3 text-sm ${message.side === 'client' ? 'ml-auto bg-red-50' : 'bg-slate-100'}`}
                                >
                                    <p className="text-xs font-bold text-[#526b92]">
                                        {message.side === 'client'
                                            ? message.author
                                            : `${message.author} · Oficina contable`}{' '}
                                        · {formatDate(message.at, true)}
                                    </p>
                                    <p className="whitespace-pre-line">
                                        {message.body}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    {canSend ? (
                        <form
                            className="grid gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/client/messages', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('body'),
                                });
                            }}
                        >
                            {!current && (
                                <input
                                    className={input}
                                    placeholder="Asunto"
                                    value={form.data.subject}
                                    onChange={(event) =>
                                        form.setData(
                                            'subject',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                            <textarea
                                className={textarea}
                                placeholder="Escribe tu mensaje"
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                required
                            />
                            <button
                                type="submit"
                                className={`${primaryButton} justify-self-end`}
                                disabled={form.processing}
                            >
                                Enviar
                            </button>
                        </form>
                    ) : (
                        <p className="text-xs text-[#526b92]">
                            Tu rol de contacto puede leer los mensajes, pero no
                            enviarlos.
                        </p>
                    )}
                </Card>
            </div>
        </ClientPage>
    );
}
