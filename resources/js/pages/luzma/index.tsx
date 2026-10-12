import { Head } from '@inertiajs/react';
import { Archive, MessageSquare, Pencil, Search } from 'lucide-react';
import { useState } from 'react';
import { luzmaRequest } from '@/components/luzma/client';
import type {
    LuzmaInsight,
    LuzmaMessage,
    LuzmaReminder,
} from '@/components/luzma/client';
import { LuzmaChat } from '@/components/luzma/luzma-chat';
import { LuzmaInsightItem } from '@/components/luzma/luzma-parts';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, toneBadge } from '@/components/supervisor/kit';

type Conversation = {
    id: string;
    title: string;
    module: string;
    company: string | null;
    updated_at: string;
    archived: boolean;
};

type Health = {
    available: boolean;
    gateway: boolean;
    model: string | null;
    model_loaded: boolean;
    latency_ms: number | null;
    detail: string | null;
    enabled: boolean;
    features: Record<string, boolean>;
    queries_this_month: number;
    fallbacks_this_month: number;
    avg_latency_ms: number;
};

const reminderTone: Record<string, string> = {
    delivered: 'amber',
    scheduled: 'blue',
    snoozed: 'violet',
    completed: 'green',
    skipped: 'slate',
    cancelled: 'slate',
    expired: 'slate',
};

function formatWhen(value: string): string {
    return new Intl.DateTimeFormat('es-GT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function ReminderForm({
    companies,
    conditions,
    onSaved,
}: {
    companies: { id: string; name: string }[];
    conditions: Record<string, string>;
    onSaved: (reminders: LuzmaReminder[]) => void;
}) {
    const [data, setData] = useState({
        title: '',
        remind_at: '',
        company: '',
        condition_type: '',
    });
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="grid gap-2 md:grid-cols-5"
            onSubmit={async (event) => {
                event.preventDefault();
                setBusy(true);
                setError(null);

                try {
                    const result = await luzmaRequest<{
                        reminders: LuzmaReminder[];
                    }>('/luzma/reminders', 'POST', {
                        title: data.title,
                        remind_at: data.remind_at,
                        company: data.company || null,
                        condition_type: data.condition_type || null,
                    });
                    onSaved(result.reminders);
                    setData({ ...data, title: '', remind_at: '' });
                } catch (exception) {
                    setError((exception as Error).message);
                } finally {
                    setBusy(false);
                }
            }}
        >
            <input
                aria-label="Qué recordar"
                className={`${input} md:col-span-2`}
                placeholder="Qué recordar (ej. revisar conciliación)"
                value={data.title}
                onChange={(event) =>
                    setData({ ...data, title: event.target.value })
                }
            />
            <input
                aria-label="Cuándo"
                type="datetime-local"
                className={input}
                value={data.remind_at}
                onChange={(event) =>
                    setData({ ...data, remind_at: event.target.value })
                }
            />
            <select
                aria-label="Empresa"
                className={input}
                value={data.company}
                onChange={(event) =>
                    setData({ ...data, company: event.target.value })
                }
            >
                <option value="">Sin empresa</option>
                {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                        {company.name}
                    </option>
                ))}
            </select>
            <select
                aria-label="Condición"
                className={input}
                value={data.condition_type}
                onChange={(event) =>
                    setData({ ...data, condition_type: event.target.value })
                }
            >
                <option value="">Recordar siempre</option>
                {Object.entries(conditions)
                    .filter(([key]) => key !== 'source_open')
                    .map(([key, label]) => (
                        <option key={key} value={key}>
                            Solo si: {label}
                        </option>
                    ))}
            </select>
            <div className="md:col-span-5">
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={busy || data.title.length < 3 || !data.remind_at}
                >
                    Crear recordatorio
                </button>
                {error && (
                    <span className="ml-2 text-xs text-red-700">{error}</span>
                )}
            </div>
        </form>
    );
}

export default function LuzmaCenter({
    tab: initialTab,
    conversations: initialConversations,
    reminders: initialReminders,
    reminderHistory,
    insights,
    suggestions,
    conditions,
    companies,
    health,
    can,
}: {
    tab: string;
    conversations: Conversation[];
    reminders: LuzmaReminder[];
    reminderHistory: LuzmaReminder[];
    insights: LuzmaInsight[];
    suggestions: string[];
    conditions: Record<string, string>;
    companies: { id: string; name: string }[];
    health: Health | null;
    can: { history: boolean; reminders: boolean; insights: boolean };
}) {
    const [tab, setTab] = useState(initialTab);
    const [reminders, setReminders] = useState(initialReminders);
    const [conversations, setConversations] = useState(initialConversations);
    const [search, setSearch] = useState('');
    const [opened, setOpened] = useState<{
        id: string;
        messages: LuzmaMessage[];
    } | null>(null);

    const updateReminder = async (
        id: string,
        action: string,
        until?: string,
    ) => {
        const result = await luzmaRequest<{ reminders: LuzmaReminder[] }>(
            `/luzma/reminders/${id}`,
            'PATCH',
            { action, until },
        );
        setReminders(result.reminders);
    };
    const reloadConversations = async (term = search) => {
        const result = await luzmaRequest<{ conversations: Conversation[] }>(
            `/luzma/conversations?q=${encodeURIComponent(term)}`,
        );
        setConversations(result.conversations);
    };
    const tabs: [string, string][] = [
        ['chat', 'Conversación'],
        ['insights', `Alertas (${insights.length})`],
        ['reminders', `Recordatorios (${reminders.length})`],
        ...(can.history ? [['history', 'Historial'] as [string, string]] : []),
        ...(health ? [['status', 'Estado'] as [string, string]] : []),
    ];

    return (
        <>
            <Head title="Luzma" />
            <Shell>
                <PageHeader
                    title="Luzma"
                    subtitle="Asistente inteligente de Atlantia Finance: interpreta y explica; los cálculos, permisos y ejecuciones siguen siendo de Atlantia"
                />
                <div className="flex flex-wrap gap-1.5">
                    {tabs.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => {
                                setTab(key);
                                window.history.replaceState(
                                    window.history.state,
                                    '',
                                    `/luzma?tab=${key}`,
                                );
                            }}
                            className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${tab === key ? 'border-[#061633] bg-[#061633] text-white' : 'border-slate-200 bg-white text-[#526b92]'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'chat' && (
                    <Card title="Conversación">
                        <div className="flex h-[65vh] flex-col">
                            <LuzmaChat
                                key={opened?.id ?? 'new'}
                                screen={{ module: 'global', route: '/luzma' }}
                                suggestions={suggestions}
                                conversationId={opened?.id ?? null}
                                initialMessages={opened?.messages ?? []}
                            />
                        </div>
                    </Card>
                )}

                {tab === 'insights' && (
                    <Card title="Luzma detectó">
                        <div className="grid gap-2 lg:grid-cols-2">
                            {insights.length === 0 && (
                                <p className="text-sm text-slate-500">
                                    No hay alertas abiertas en tus empresas.
                                </p>
                            )}
                            {insights.map((insight) => (
                                <LuzmaInsightItem
                                    key={insight.id}
                                    insight={insight}
                                />
                            ))}
                        </div>
                        <p className="mt-3 text-[11px] text-[#526b92]">
                            Las alertas salen de reglas sobre datos reales (no
                            del modelo), no se duplican y se resuelven solas
                            cuando se corrige su origen.
                        </p>
                    </Card>
                )}

                {tab === 'reminders' && (
                    <>
                        {can.reminders && (
                            <Card title="Nuevo recordatorio">
                                <ReminderForm
                                    companies={companies}
                                    conditions={conditions}
                                    onSaved={setReminders}
                                />
                                <p className="mt-2 text-[11px] text-[#526b92]">
                                    También puedes pedírselo a Luzma:
                                    "Recuérdame revisar Comercial Maya mañana a
                                    las 9" o "Avísame si CxP sigue sin cuadrar
                                    el viernes".
                                </p>
                            </Card>
                        )}
                        <Card title="Recordatorios activos">
                            <ul className="divide-y divide-slate-100">
                                {reminders.length === 0 && (
                                    <li className="py-3 text-sm text-slate-500">
                                        No tienes recordatorios activos.
                                    </li>
                                )}
                                {reminders.map((reminder) => (
                                    <li
                                        key={reminder.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-2"
                                    >
                                        <div>
                                            <p className="text-sm font-bold text-[#061633]">
                                                {reminder.title}
                                            </p>
                                            <p className="text-xs text-[#526b92]">
                                                {formatWhen(reminder.remind_at)}
                                                {reminder.company &&
                                                    ` · ${reminder.company}`}
                                                {reminder.condition &&
                                                    ` · solo si: ${reminder.condition}`}
                                                {reminder.kind ===
                                                    'automatic' &&
                                                    ' · automático'}
                                            </p>
                                            {reminder.message && (
                                                <p className="text-xs text-slate-600">
                                                    {reminder.message}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <span
                                                className={toneBadge(
                                                    reminderTone[
                                                        reminder.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {reminder.status_label}
                                            </span>
                                            {reminder.destination && (
                                                <a
                                                    href={reminder.destination}
                                                    className={secondaryButton}
                                                >
                                                    Ir
                                                </a>
                                            )}
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    updateReminder(
                                                        reminder.id,
                                                        'snooze',
                                                        '1h',
                                                    )
                                                }
                                            >
                                                En 1 hora
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    updateReminder(
                                                        reminder.id,
                                                        'snooze',
                                                        'tomorrow',
                                                    )
                                                }
                                            >
                                                Mañana
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    updateReminder(
                                                        reminder.id,
                                                        'complete',
                                                    )
                                                }
                                            >
                                                Hecho
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    updateReminder(
                                                        reminder.id,
                                                        'cancel',
                                                    )
                                                }
                                            >
                                                Cancelar
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                        <Card title="Historial reciente">
                            <ul className="space-y-1 text-xs text-slate-600">
                                {reminderHistory.length === 0 && (
                                    <li>Sin historial.</li>
                                )}
                                {reminderHistory.map((reminder) => (
                                    <li key={reminder.id}>
                                        <b>{reminder.title}</b> ·{' '}
                                        {reminder.status_label}
                                        {reminder.resolution &&
                                            ` · ${reminder.resolution}`}{' '}
                                        · {formatDate(reminder.remind_at)}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </>
                )}

                {tab === 'history' && (
                    <Card
                        title="Conversaciones"
                        action={
                            <form
                                className="relative"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void reloadConversations();
                                }}
                            >
                                <Search className="absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-slate-400" />
                                <input
                                    aria-label="Buscar conversaciones"
                                    className={`${input} h-8 pl-7 text-xs`}
                                    placeholder="Buscar"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </form>
                        }
                    >
                        <ul className="divide-y divide-slate-100">
                            {conversations.length === 0 && (
                                <li className="py-3 text-sm text-slate-500">
                                    Aún no hay conversaciones.
                                </li>
                            )}
                            {conversations.map((conversation) => (
                                <li
                                    key={conversation.id}
                                    className="flex items-center justify-between gap-2 py-2"
                                >
                                    <button
                                        type="button"
                                        className="flex min-w-0 items-center gap-2 text-left"
                                        onClick={async () => {
                                            const result = await luzmaRequest<{
                                                messages: LuzmaMessage[];
                                            }>(
                                                `/luzma/conversations/${conversation.id}`,
                                            );
                                            setOpened({
                                                id: conversation.id,
                                                messages: result.messages,
                                            });
                                            setTab('chat');
                                        }}
                                    >
                                        <MessageSquare className="size-4 shrink-0 text-[#640000]" />
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-bold text-[#061633]">
                                                {conversation.title}
                                            </span>
                                            <span className="block text-xs text-[#526b92]">
                                                {conversation.company ??
                                                    'Todas mis empresas'}{' '}
                                                ·{' '}
                                                {formatWhen(
                                                    conversation.updated_at,
                                                )}
                                            </span>
                                        </span>
                                    </button>
                                    <span className="flex shrink-0 gap-1">
                                        <button
                                            type="button"
                                            aria-label="Renombrar"
                                            className={secondaryButton}
                                            onClick={async () => {
                                                const title = window.prompt(
                                                    'Nuevo nombre',
                                                    conversation.title,
                                                );

                                                if (title) {
                                                    await luzmaRequest(
                                                        `/luzma/conversations/${conversation.id}`,
                                                        'PATCH',
                                                        { title },
                                                    );
                                                    await reloadConversations();
                                                }
                                            }}
                                        >
                                            <Pencil className="size-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            aria-label="Archivar"
                                            className={secondaryButton}
                                            onClick={async () => {
                                                await luzmaRequest(
                                                    `/luzma/conversations/${conversation.id}`,
                                                    'PATCH',
                                                    { archived: true },
                                                );
                                                await reloadConversations();
                                            }}
                                        >
                                            <Archive className="size-3.5" />
                                        </button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {tab === 'status' && health && (
                    <Card title="Estado técnico de Luzma">
                        <div className="grid gap-3 text-sm md:grid-cols-3">
                            <p>
                                <b>Servicio:</b>{' '}
                                {health.gateway ? 'responde' : 'sin respuesta'}
                            </p>
                            <p>
                                <b>Modelo:</b> {health.model ?? '—'} (
                                {health.model_loaded ? 'cargado' : 'no cargado'}
                                )
                            </p>
                            <p>
                                <b>Latencia del servicio:</b>{' '}
                                {health.latency_ms ?? '—'} ms
                            </p>
                            <p>
                                <b>Consultas este mes:</b>{' '}
                                {health.queries_this_month}
                            </p>
                            <p>
                                <b>Respuestas sin modelo o con error:</b>{' '}
                                {health.fallbacks_this_month}
                            </p>
                            <p>
                                <b>Latencia media (7 días):</b>{' '}
                                {health.avg_latency_ms} ms
                            </p>
                            <p className="md:col-span-3">
                                <b>Funciones:</b>{' '}
                                {Object.entries(health.features)
                                    .map(
                                        ([key, value]) =>
                                            `${key} ${value ? 'activa' : 'inactiva'}`,
                                    )
                                    .join(' · ')}
                            </p>
                            {health.detail && (
                                <p className="text-amber-800 md:col-span-3">
                                    {health.detail}
                                </p>
                            )}
                        </div>
                    </Card>
                )}
            </Shell>
        </>
    );
}
