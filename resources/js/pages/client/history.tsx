import { useState } from 'react';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import { input } from '@/components/office-admin/kit';

type HistoryEvent = {
    at: string;
    kind: string;
    title: string;
    detail: string | null;
};

const kinds: Record<string, [string, string]> = {
    document: ['Documento', 'blue'],
    request: ['Solicitud', 'amber'],
    approval: ['Autorización', 'violet'],
    report: ['Reporte', 'green'],
    obligation: ['Obligación', 'green'],
    message: ['Mensaje', 'slate'],
};

export default function ClientHistory({ events }: { events: HistoryEvent[] }) {
    const [kind, setKind] = useState('');
    const visible = events.filter((event) => !kind || event.kind === kind);

    return (
        <ClientPage
            title="Historial"
            subtitle="Lo que ha ocurrido entre tu empresa y tu oficina contable"
        >
            <Card
                title={`${visible.length} evento(s)`}
                action={
                    <select
                        aria-label="Tipo"
                        className={`${input} h-8 w-auto`}
                        value={kind}
                        onChange={(event) => setKind(event.target.value)}
                    >
                        <option value="">Todo</option>
                        {Object.entries(kinds).map(([key, [label]]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                }
            >
                <Table
                    headers={['Fecha', 'Tipo', 'Evento', 'Detalle']}
                    empty={visible.length === 0 && 'Sin eventos todavía.'}
                >
                    {visible.map((event, index) => {
                        const [label, tone] = kinds[event.kind] ?? [
                            event.kind,
                            'slate',
                        ];

                        return (
                            <tr key={`${event.at}-${index}`}>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(event.at, true)}
                                </td>
                                <td className="px-2 py-2">
                                    <span className={toneBadge(tone)}>
                                        {label}
                                    </span>
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {event.title}
                                </td>
                                <td className="px-2 py-2">
                                    {event.detail ?? '—'}
                                </td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>
        </ClientPage>
    );
}
