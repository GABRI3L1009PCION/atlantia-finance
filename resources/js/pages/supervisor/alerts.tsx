import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    PageHeader,
    Shell,
    formatDate,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    Table,
    toneBadge,
    useSupervisorHref,
} from '@/components/supervisor/kit';
import type { SupervisorContext } from '@/components/supervisor/kit';

type Alert = {
    key: string;
    category: string;
    title: string;
    detail: string;
    company: string | null;
    date: string | null;
    priority: string;
    destination: string;
};
type Inbox = {
    notifications: {
        id: number;
        title: string;
        body: string | null;
        href: string | null;
        read: boolean;
        created_at: string;
    }[];
};

const priority: Record<string, [string, string]> = {
    high: ['Alta', 'red'],
    medium: ['Media', 'amber'],
    low: ['Baja', 'slate'],
};

export default function SupervisorAlerts({
    alerts,
    inbox,
}: {
    context: SupervisorContext;
    alerts: Alert[];
    inbox: Inbox;
}) {
    const href = useSupervisorHref();
    const [category, setCategory] = useState('');
    const categories = [...new Set(alerts.map((alert) => alert.category))];
    const visible = alerts.filter(
        (alert) => category === '' || alert.category === category,
    );

    return (
        <>
            <Head title="Alertas" />
            <Shell>
                <PageHeader
                    title="Alertas"
                    subtitle="Situaciones que requieren atención en tus empresas, calculadas con datos reales"
                />
                <Card
                    title={`${visible.length} alerta(s)`}
                    action={
                        <select
                            aria-label="Categoría"
                            className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                            value={category}
                            onChange={(event) =>
                                setCategory(event.target.value)
                            }
                        >
                            <option value="">Todas las categorías</option>
                            {categories.map((item) => (
                                <option key={item} value={item}>
                                    {item}
                                </option>
                            ))}
                        </select>
                    }
                >
                    <Table
                        headers={[
                            'Prioridad',
                            'Categoría',
                            'Alerta',
                            'Empresa',
                            'Fecha',
                            '',
                        ]}
                        empty={visible.length === 0 && 'Sin alertas activas.'}
                    >
                        {visible.map((alert) => {
                            const [label, tone] = priority[alert.priority] ?? [
                                alert.priority,
                                'slate',
                            ];

                            return (
                                <tr key={alert.key}>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {alert.category}
                                    </td>
                                    <td className="px-2 py-2">
                                        <p className="font-bold">
                                            {alert.title}
                                        </p>
                                        <p className="text-[11px] text-[#526b92]">
                                            {alert.detail}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {alert.company ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {alert.date
                                            ? formatDate(alert.date)
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={href(alert.destination)}
                                            className={secondaryButton}
                                        >
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
                <Card
                    title="Notificaciones"
                    action={
                        inbox.notifications.some((item) => !item.read) && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    router.post(
                                        '/work/notifications/read-all',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Marcar todas como leídas
                            </button>
                        )
                    }
                >
                    <ul className="divide-y divide-slate-100 text-sm">
                        {inbox.notifications.length === 0 && (
                            <li className="py-6 text-center text-slate-500">
                                Sin notificaciones.
                            </li>
                        )}
                        {inbox.notifications.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-start justify-between gap-3 py-2"
                            >
                                <div>
                                    <p className={item.read ? '' : 'font-bold'}>
                                        {item.href ? (
                                            <a
                                                href={item.href}
                                                className="hover:text-[#640000]"
                                            >
                                                {item.title}
                                            </a>
                                        ) : (
                                            item.title
                                        )}
                                    </p>
                                    {item.body && (
                                        <p className="text-xs text-[#526b92]">
                                            {item.body}
                                        </p>
                                    )}
                                </div>
                                <div className="flex shrink-0 items-center gap-2 text-xs text-[#526b92]">
                                    {relativeTime(item.created_at)}
                                    {!item.read && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            onClick={() =>
                                                router.post(
                                                    `/work/notifications/${item.id}/read`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Leída
                                        </button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            </Shell>
        </>
    );
}
