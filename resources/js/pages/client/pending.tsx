import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { PendingItem } from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    requestTone,
    toneBadge,
} from '@/components/client-portal/kit';
import { input } from '@/components/office-admin/kit';

const filters: Record<string, string> = {
    all: 'Todos',
    document_request: 'Documentos',
    approval_request: 'Autorizaciones',
    audit_evidence_request: 'Evidencias',
    other: 'Información y otros',
};

export default function ClientPending({
    items,
    notice,
}: {
    items: PendingItem[];
    notice: string | null;
}) {
    const [filter, setFilter] = useState('all');
    const visible = items.filter((item) =>
        filter === 'all'
            ? true
            : filter === 'other'
              ? !(item.type in filters)
              : item.type === filter,
    );

    return (
        <ClientPage
            title="Pendientes"
            subtitle="Todo lo que tu oficina contable espera de ti, ordenado por fecha límite"
            notice={notice}
        >
            <Card
                title={`${visible.length} pendiente(s)`}
                action={
                    <select
                        aria-label="Tipo"
                        className={`${input} h-8 w-auto`}
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                    >
                        {Object.entries(filters).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                }
            >
                <Table
                    headers={[
                        'Tipo',
                        'Descripción',
                        'Solicitado por',
                        'Fecha límite',
                        'Estado',
                        'Acción',
                    ]}
                    empty={
                        visible.length === 0 &&
                        'No tienes pendientes. Te avisaremos cuando tu oficina necesite algo.'
                    }
                >
                    {visible.map((item) => (
                        <tr key={item.id}>
                            <td className="px-2 py-2">{item.type_label}</td>
                            <td className="px-2 py-2">
                                <span className="block font-bold">
                                    {item.title}
                                </span>
                                {item.description && (
                                    <span className="block text-[#526b92]">
                                        {item.description}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">{item.source_label}</td>
                            <td
                                className={`px-2 py-2 whitespace-nowrap ${item.overdue ? 'font-bold text-red-700' : ''}`}
                            >
                                {formatDate(item.due_date)}
                                {item.overdue && ' · vencido'}
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
        </ClientPage>
    );
}
