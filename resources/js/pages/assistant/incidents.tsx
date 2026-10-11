import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Incident = {
    id: number;
    responsible_id?: number | null;
    company_name: string | null;
    type_label: string;
    title: string;
    description: string | null;
    severity: string;
    status: string;
    resolution: string | null;
    created_at: string;
};

const severity: Record<string, [string, string]> = {
    low: ['Baja', 'slate'],
    medium: ['Media', 'blue'],
    high: ['Alta', 'amber'],
    critical: ['Crítica', 'red'],
};
const tone: Record<string, string> = {
    open: 'red',
    in_progress: 'blue',
    blocked: 'violet',
    resolved: 'green',
    dismissed: 'slate',
};

export default function AssistantIncidents({
    incidents,
    statusLabels,
    notice,
    basePath = '/assistant',
    userId,
}: {
    context: unknown;
    incidents: Incident[];
    statusLabels: Record<string, string>;
    notice: string | null;
    basePath?: string;
    userId?: number;
}) {
    const focus = Number(
        new URL(usePage().url, 'https://atlantia.local').searchParams.get(
            'incident',
        ),
    );
    const [resolving, setResolving] = useState<Incident | null>(null);
    const [resolution, setResolution] = useState('');
    const update = (
        incident: Incident,
        status: string,
        text: string | null = null,
    ) =>
        router.patch(
            `${basePath}/incidents/${incident.id}`,
            { status, resolution: text },
            { preserveScroll: true, onSuccess: () => setResolving(null) },
        );

    return (
        <>
            <Head title="Incidencias" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Incidencias"
                    subtitle="Incidencias de tus empresas donde tú eres el responsable"
                />
                <Card title={`${incidents.length} incidencia(s)`}>
                    <Table
                        headers={[
                            'Incidencia',
                            'Empresa',
                            'Tipo',
                            'Severidad',
                            'Registrada',
                            'Estado',
                            'Acción',
                        ]}
                        empty={
                            incidents.length === 0 &&
                            'No tienes incidencias a tu cargo.'
                        }
                    >
                        {incidents.map((incident) => {
                            const [label, color] = severity[
                                incident.severity
                            ] ?? [incident.severity, 'slate'];
                            const active = [
                                'open',
                                'in_progress',
                                'blocked',
                            ].includes(incident.status);

                            return (
                                <tr
                                    key={incident.id}
                                    className={`align-top ${incident.id === focus ? 'bg-[#fbf6f3]' : ''}`}
                                >
                                    <td className="px-2 py-2">
                                        <p className="font-bold">
                                            {incident.title}
                                        </p>
                                        {incident.description && (
                                            <p className="text-[11px] text-[#526b92]">
                                                {incident.description}
                                            </p>
                                        )}
                                        {incident.resolution && (
                                            <p className="text-[11px] text-emerald-700">
                                                Resolución:{' '}
                                                {incident.resolution}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {incident.company_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {incident.type_label}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(color)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(incident.created_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                tone[incident.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {statusLabels[incident.status] ??
                                                incident.status}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {active &&
                                            (userId === undefined ||
                                                incident.responsible_id ===
                                                    userId) && (
                                                <div className="flex flex-wrap gap-1">
                                                    {incident.status ===
                                                        'open' && (
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() =>
                                                                update(
                                                                    incident,
                                                                    'in_progress',
                                                                )
                                                            }
                                                        >
                                                            En análisis
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        className={
                                                            secondaryButton
                                                        }
                                                        onClick={() => {
                                                            setResolution('');
                                                            setResolving(
                                                                incident,
                                                            );
                                                        }}
                                                    >
                                                        Resolver
                                                    </button>
                                                </div>
                                            )}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
            <Dialog
                open={resolving !== null}
                onOpenChange={(value) => !value && setResolving(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Resolver incidencia</DialogTitle>
                        <DialogDescription>
                            {resolving?.title}. Describe cómo se resolvió; queda
                            en la auditoría.
                        </DialogDescription>
                    </DialogHeader>
                    <textarea
                        aria-label="Resolución"
                        className={`${input} h-24 py-2`}
                        value={resolution}
                        onChange={(event) => setResolution(event.target.value)}
                    />
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setResolving(null)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={resolution.trim().length < 5}
                            onClick={() =>
                                resolving &&
                                update(resolving, 'resolved', resolution)
                            }
                        >
                            Resolver
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
