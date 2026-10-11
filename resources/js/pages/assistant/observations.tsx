import { Head, router, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { priorityTone } from '@/components/assistant/kit';
import type { AssistantContext } from '@/components/assistant/kit';
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

type Observation = {
    id: number;
    company_name: string;
    period_name: string | null;
    related_type_label: string;
    related_label: string | null;
    related_href: string | null;
    severity: string;
    severity_label: string;
    status: string;
    status_label: string;
    message: string;
    resolution: string | null;
    author_name: string | null;
    due_at: string | null;
    overdue: boolean;
    created_at: string;
};

const statusTone: Record<string, string> = {
    open: 'red',
    reopened: 'red',
    in_progress: 'blue',
    ready_for_review: 'violet',
    resolved: 'green',
};

export default function AssistantObservations({
    context,
    observations,
    filters,
    focus,
    notice,
    basePath = '/assistant',
}: {
    context: AssistantContext;
    observations: Observation[];
    filters: Record<string, string>;
    focus: number | null;
    notice: string | null;
    basePath?: string;
}) {
    const page = usePage();
    const [correcting, setCorrecting] = useState<Observation | null>(null);
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const apply = (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            `${basePath}/observations`,
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, current]) => current !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };
    const move = (
        observation: Observation,
        status: string,
        value: string | null = null,
    ) => {
        router.patch(
            `${basePath}/observations/${observation.id}`,
            { status, note: value },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCorrecting(null);
                    setNote('');
                    setError(null);
                },
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'No se pudo actualizar la observación.',
                    ),
            },
        );
    };

    return (
        <>
            <Head title="Observaciones" />
            <Shell>
                <Notice message={notice} />
                {error && !correcting && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {error}
                    </p>
                )}
                <PageHeader
                    title="Observaciones recibidas"
                    subtitle="Abre el origen, corrige allí y envía la corrección: el revisor la valida y la da por resuelta"
                />
                <Card
                    title={`${observations.length} observación(es)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Empresa"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    apply('company_id', event.target.value)
                                }
                            >
                                <option value="">Todas las empresas</option>
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Estado"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.status ?? 'active'}
                                onChange={(event) =>
                                    apply('status', event.target.value)
                                }
                            >
                                <option value="active">Pendientes</option>
                                <option value="correctable">
                                    Por corregir
                                </option>
                                <option value="ready_for_review">
                                    Corregidas, por validar
                                </option>
                                <option value="resolved">Resueltas</option>
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Empresa',
                            'Origen',
                            'Observación',
                            'Creó',
                            'Prioridad',
                            'Límite',
                            'Estado',
                            'Acción',
                        ]}
                        empty={
                            observations.length === 0 &&
                            'No tienes observaciones pendientes.'
                        }
                    >
                        {observations.map((observation) => {
                            const [priority, tone] = priorityTone[
                                observation.severity
                            ] ?? [observation.severity_label, 'slate'];

                            return (
                                <tr
                                    key={observation.id}
                                    className={`align-top ${observation.id === focus ? 'bg-[#fbf6f3]' : ''}`}
                                >
                                    <td className="px-2 py-2 font-bold">
                                        {observation.company_name}
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {observation.period_name}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {observation.related_href ? (
                                            <a
                                                href={observation.related_href}
                                                className="inline-flex items-center gap-1 font-bold text-[#640000] hover:underline"
                                            >
                                                {observation.related_type_label}{' '}
                                                {observation.related_label}{' '}
                                                <ExternalLink className="size-3" />
                                            </a>
                                        ) : (
                                            `${observation.related_type_label} ${observation.related_label ?? ''}`
                                        )}
                                    </td>
                                    <td className="max-w-md px-2 py-2">
                                        <p>{observation.message}</p>
                                        {observation.resolution && (
                                            <p className="text-[11px] text-emerald-700">
                                                Corrección:{' '}
                                                {observation.resolution}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {observation.author_name ?? '—'}
                                        <p className="text-[11px] text-[#526b92]">
                                            {formatDate(observation.created_at)}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {priority}
                                        </span>
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${observation.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {observation.due_at
                                            ? formatDate(observation.due_at)
                                            : '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                statusTone[
                                                    observation.status
                                                ] ?? 'slate',
                                            )}
                                        >
                                            {observation.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {['open', 'reopened'].includes(
                                                observation.status,
                                            ) && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        move(
                                                            observation,
                                                            'in_progress',
                                                        )
                                                    }
                                                >
                                                    Empezar corrección
                                                </button>
                                            )}
                                            {[
                                                'open',
                                                'in_progress',
                                                'reopened',
                                            ].includes(observation.status) && (
                                                <button
                                                    type="button"
                                                    className={primaryButton}
                                                    onClick={() => {
                                                        setNote('');
                                                        setError(null);
                                                        setCorrecting(
                                                            observation,
                                                        );
                                                    }}
                                                >
                                                    Enviar corrección
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>

            <Dialog
                open={correcting !== null}
                onOpenChange={(value) => !value && setCorrecting(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Enviar corrección</DialogTitle>
                        <DialogDescription>
                            Describe qué corregiste en el origen. Si la
                            observación apunta a un registro, ese registro debe
                            haberse modificado; el revisor valida y la resuelve.
                        </DialogDescription>
                    </DialogHeader>
                    <p className="rounded-lg bg-slate-50 p-2 text-sm">
                        {correcting?.message}
                    </p>
                    <textarea
                        aria-label="Corrección realizada"
                        className={`${input} h-24 py-2`}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                    {error && <p className="text-sm text-red-700">{error}</p>}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setCorrecting(null)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={note.trim().length < 5}
                            onClick={() =>
                                correcting &&
                                move(correcting, 'ready_for_review', note)
                            }
                        >
                            Enviar corrección
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
