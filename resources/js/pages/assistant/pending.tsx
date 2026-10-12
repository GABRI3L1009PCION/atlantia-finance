import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { priorityTone } from '@/components/assistant/kit';
import type { AssistantContext } from '@/components/assistant/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Item = {
    kind: string;
    title: string;
    company: string | null;
    priority: string;
    due_at: string | null;
    status_label: string;
    destination: string;
};

export default function AssistantPending({
    items,
}: {
    context: AssistantContext;
    items: Item[];
}) {
    const [kind, setKind] = useState('');
    const kinds = [...new Set(items.map((item) => item.kind))];
    const visible = items.filter((item) => kind === '' || item.kind === kind);

    return (
        <>
            <Head title="Pendientes" />
            <Shell>
                <PageHeader
                    title="Pendientes"
                    subtitle="Todo lo que tienes por hacer, ordenado por prioridad y vencimiento"
                />
                <Card
                    title={`${visible.length} pendiente(s)`}
                    action={
                        <select
                            aria-label="Tipo"
                            className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                            value={kind}
                            onChange={(event) => setKind(event.target.value)}
                        >
                            <option value="">Todos los tipos</option>
                            {kinds.map((item) => (
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
                            'Tipo',
                            'Pendiente',
                            'Empresa',
                            'Vencimiento',
                            'Estado',
                            '',
                        ]}
                        empty={visible.length === 0 && 'No tienes pendientes.'}
                    >
                        {visible.map((item, index) => {
                            const [priority, tone] = priorityTone[
                                item.priority
                            ] ?? [item.priority, 'slate'];

                            return (
                                <tr key={`${item.kind}-${index}`}>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {priority}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">{item.kind}</td>
                                    <td className="max-w-md px-2 py-2 font-bold">
                                        {item.title}
                                    </td>
                                    <td className="px-2 py-2">
                                        {item.company ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {item.due_at
                                            ? formatDate(item.due_at)
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {item.status_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        <a
                                            href={item.destination}
                                            className={secondaryButton}
                                        >
                                            Ir
                                        </a>
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
