import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    FileCheck2,
    FileUp,
    MessagesSquare,
    Sparkles,
} from 'lucide-react';
import type {
    ClientContext,
    ClientDocument,
    ClientPermissions,
    Conversation,
    Obligation,
    PendingItem,
    PublishedReport,
} from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Metric,
    Table,
    ViewAll,
    documentTone,
    formatDate,
    obligationTone,
    relativeTime,
    requestTone,
    toneBadge,
} from '@/components/client-portal/kit';
import { askLuzma } from '@/components/luzma/client';

type Panel = {
    metrics: {
        pending_items: number;
        requested_documents: number;
        pending_approvals: number;
        upcoming_obligations: number | null;
        documents_submitted_this_month: number;
        unread_messages: number | null;
    };
    attention_items: PendingItem[];
    upcoming_dates: {
        date: string;
        type: string;
        title: string;
        subtitle: string;
        destination: string;
    }[];
    recent_documents: ClientDocument[];
    published_reports: PublishedReport[];
    obligations: { error: boolean; rows: Obligation[] } | null;
    recent_messages: Conversation[];
    last_updated_at: string;
};

const luzmaQuestions = [
    '¿Qué tengo pendiente?',
    '¿Qué documentos me solicitaron?',
    '¿Qué obligaciones tengo próximas?',
    '¿Qué falta para completar este mes?',
];

export default function ClientDashboard({
    context,
    permissions,
    notice,
    panel,
}: {
    context: ClientContext;
    permissions: ClientPermissions;
    notice: string | null;
    panel: Panel;
}) {
    const metrics = panel.metrics;

    return (
        <ClientPage
            title="Portal del cliente"
            heading={`Hola, ${context.company.name}`}
            subtitle="Lo que tu oficina contable necesita de ti, lo que ya entregaste y lo que puedes consultar"
            notice={notice}
        >
            <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                <Metric
                    label="Pendientes para mí"
                    value={metrics.pending_items}
                    icon={ClipboardList}
                    tone="bg-red-50 text-red-700"
                    href="/client/pending"
                />
                <Metric
                    label="Documentos solicitados"
                    value={metrics.requested_documents}
                    icon={FileUp}
                    tone="bg-amber-50 text-amber-700"
                    href="/client/document-requests"
                />
                {permissions['client.approvals.view'] && (
                    <Metric
                        label="Autorizaciones pendientes"
                        value={metrics.pending_approvals}
                        icon={CheckCircle2}
                        tone="bg-violet-50 text-violet-700"
                        href="/client/approvals"
                    />
                )}
                {metrics.upcoming_obligations !== null && (
                    <Metric
                        label="Próximas obligaciones"
                        value={metrics.upcoming_obligations}
                        icon={CalendarClock}
                        tone="bg-blue-50 text-blue-700"
                        href="/client/obligations"
                    />
                )}
                <Metric
                    label="Documentos entregados este mes"
                    value={metrics.documents_submitted_this_month}
                    icon={FileCheck2}
                    tone="bg-emerald-50 text-emerald-700"
                    href="/client/documents"
                />
                {metrics.unread_messages !== null && (
                    <Metric
                        label="Mensajes sin leer"
                        value={metrics.unread_messages}
                        icon={MessagesSquare}
                        tone="bg-slate-100 text-slate-700"
                        href="/client/messages"
                    />
                )}
            </section>

            <div className="grid gap-3 xl:grid-cols-3">
                <Card
                    title="Requiere tu atención"
                    className="xl:col-span-2"
                    action={<ViewAll href="/client/pending" />}
                >
                    <Table
                        headers={[
                            'Tipo',
                            'Descripción',
                            'Solicitado por',
                            'Fecha límite',
                            'Estado',
                            '',
                        ]}
                        empty={
                            panel.attention_items.length === 0 &&
                            'No tienes pendientes. ¡Todo al día!'
                        }
                    >
                        {panel.attention_items.map((item) => (
                            <tr key={item.id}>
                                <td className="px-2 py-2">{item.type_label}</td>
                                <td className="px-2 py-2 font-bold">
                                    {item.title}
                                </td>
                                <td className="px-2 py-2">
                                    {item.source_label}
                                </td>
                                <td
                                    className={`px-2 py-2 whitespace-nowrap ${item.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(item.due_date)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            requestTone[item.status] ?? 'slate',
                                        )}
                                    >
                                        {item.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={item.destination}
                                        className="rounded-md bg-[#640000] px-3 py-1 text-xs font-bold text-white"
                                    >
                                        {item.action_label}
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                <Card
                    title="Próximas fechas"
                    action={
                        <ViewAll href="/client/calendar" label="Calendario" />
                    }
                >
                    <ul className="grid gap-2 text-sm">
                        {panel.upcoming_dates.length === 0 && (
                            <li className="py-6 text-center text-slate-500">
                                Sin fechas próximas.
                            </li>
                        )}
                        {panel.upcoming_dates.map((event, index) => (
                            <li
                                key={`${event.date}-${index}`}
                                className="flex items-start gap-3"
                            >
                                <span className="grid w-12 shrink-0 place-items-center rounded-lg bg-red-50 py-1 text-[#640000]">
                                    <span className="text-lg leading-none font-black">
                                        {event.date.slice(8, 10)}
                                    </span>
                                    <span className="text-[10px] uppercase">
                                        {new Date(
                                            `${event.date}T12:00:00`,
                                        ).toLocaleDateString('es-GT', {
                                            month: 'short',
                                        })}
                                    </span>
                                </span>
                                <Link
                                    href={event.destination}
                                    className="min-w-0"
                                >
                                    <span className="block font-bold text-[#061633]">
                                        {event.title}
                                    </span>
                                    <span className="block text-xs text-[#526b92]">
                                        {event.subtitle}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <div className="grid gap-3 xl:grid-cols-3">
                {permissions['client.documents.view'] && (
                    <Card
                        title="Documentos recientes"
                        action={<ViewAll href="/client/documents" />}
                    >
                        <ul className="grid gap-2 text-sm">
                            {panel.recent_documents.length === 0 && (
                                <li className="py-6 text-center text-slate-500">
                                    Aún no has enviado documentos.
                                </li>
                            )}
                            {panel.recent_documents.map((document) => (
                                <li
                                    key={document.id}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate font-bold">
                                            {document.title}
                                        </span>
                                        <span className="block text-xs text-[#526b92]">
                                            {document.category_label} ·{' '}
                                            {formatDate(document.uploaded_at)}
                                        </span>
                                    </span>
                                    <span
                                        className={toneBadge(
                                            documentTone[document.status] ??
                                                'slate',
                                        )}
                                    >
                                        {document.status_label}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                {permissions['client.reports.view'] && (
                    <Card
                        title="Reportes disponibles"
                        action={<ViewAll href="/client/reports" />}
                    >
                        <ul className="grid gap-2 text-sm">
                            {panel.published_reports.length === 0 && (
                                <li className="py-6 text-center text-slate-500">
                                    Tu oficina aún no ha publicado reportes.
                                </li>
                            )}
                            {panel.published_reports.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate font-bold">
                                            {report.title}
                                        </span>
                                        <span className="block text-xs text-[#526b92]">
                                            {report.period} · v{report.version}
                                        </span>
                                    </span>
                                    {report.downloadable && (
                                        <a
                                            href={report.destination}
                                            className="text-xs font-bold text-[#640000]"
                                        >
                                            Descargar
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                <Card title="Luzma">
                    <div className="grid gap-2 text-sm">
                        <p className="flex items-start gap-2 text-[#526b92]">
                            <Sparkles className="mt-0.5 size-4 shrink-0 text-[#b8893f]" />
                            Pregúntame por tus pendientes, documentos y fechas.
                            Solo uso la información que tu oficina compartió
                            contigo.
                        </p>
                        {permissions['client.luzma.use'] &&
                            luzmaQuestions.map((question) => (
                                <button
                                    key={question}
                                    type="button"
                                    className="rounded-lg border border-[#e7e1dd] px-3 py-2 text-left text-xs font-semibold hover:border-[#640000]/40"
                                    onClick={() => askLuzma(question)}
                                >
                                    {question}
                                </button>
                            ))}
                    </div>
                </Card>
            </div>

            <div className="grid gap-3 xl:grid-cols-2">
                {panel.obligations && (
                    <Card
                        title="Obligaciones próximas"
                        action={<ViewAll href="/client/obligations" />}
                    >
                        {panel.obligations.error ? (
                            <p className="py-6 text-center text-sm text-red-700">
                                No fue posible cargar tus obligaciones. Intenta
                                de nuevo más tarde.
                            </p>
                        ) : (
                            <Table
                                headers={[
                                    'Obligación',
                                    'Período',
                                    'Vence',
                                    'Estado',
                                ]}
                                empty={
                                    panel.obligations.rows.length === 0 &&
                                    'Sin obligaciones próximas.'
                                }
                            >
                                {panel.obligations.rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-2 py-2 font-bold">
                                            {row.name}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.period}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(row.due_date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    obligationTone[
                                                        row.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {row.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </Card>
                )}
                {permissions['client.messages.view'] && (
                    <Card
                        title="Mensajes recientes"
                        action={<ViewAll href="/client/messages" />}
                    >
                        <ul className="grid gap-2 text-sm">
                            {panel.recent_messages.length === 0 && (
                                <li className="py-6 text-center text-slate-500">
                                    Sin conversaciones con tu oficina.
                                </li>
                            )}
                            {panel.recent_messages.map((conversation) => (
                                <li key={conversation.id}>
                                    <Link
                                        href={`/client/messages?conversation=${conversation.id}`}
                                        className="flex items-start justify-between gap-2"
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-bold">
                                                {conversation.subject}
                                            </span>
                                            <span className="block truncate text-xs text-[#526b92]">
                                                {conversation.last_sender}:{' '}
                                                {conversation.preview}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-[#526b92]">
                                            {conversation.last_message_at
                                                ? relativeTime(
                                                      conversation.last_message_at,
                                                  )
                                                : ''}
                                            {conversation.unread > 0 && (
                                                <span className="ml-1 rounded-full bg-[#640000] px-1.5 text-white">
                                                    {conversation.unread}
                                                </span>
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
            <p className="text-right text-xs text-[#526b92]">
                Actualizado {relativeTime(panel.last_updated_at)}
            </p>
        </ClientPage>
    );
}
