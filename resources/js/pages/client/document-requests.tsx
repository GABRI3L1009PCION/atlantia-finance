import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { textarea } from '@/components/auditor/kit';
import type { ClientRequest } from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    formatDate,
    requestTone,
    toneBadge,
} from '@/components/client-portal/kit';
import {
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';

type Evidence = {
    id: number;
    code: string;
    title: string;
    description: string;
    due_date: string;
    status: string;
    status_label: string;
    review_note: string | null;
    open: boolean;
};

const openStatuses = ['pending', 'in_progress', 'more_information_required'];

function RespondForm({ request }: { request: ClientRequest }) {
    const form = useForm<{ response: string; files: File[] }>({
        response: '',
        files: [],
    });

    return (
        <form
            className="mt-3 grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/client/document-requests/${request.id}/respond`, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <textarea
                className={textarea}
                placeholder={
                    request.type === 'document'
                        ? 'Comentario para tu oficina (opcional)'
                        : 'Tu respuesta'
                }
                value={form.data.response}
                onChange={(event) =>
                    form.setData('response', event.target.value)
                }
            />
            <div className="flex flex-wrap items-center gap-2">
                <input
                    type="file"
                    multiple
                    aria-label="Archivos"
                    className={`${input} w-auto pt-1.5`}
                    onChange={(event) =>
                        form.setData(
                            'files',
                            Array.from(event.target.files ?? []),
                        )
                    }
                />
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing}
                >
                    {request.type === 'document'
                        ? 'Adjuntar y enviar'
                        : 'Enviar respuesta'}
                </button>
            </div>
        </form>
    );
}

function EvidenceForm({ evidence }: { evidence: Evidence }) {
    const form = useForm<{ note: string; file: File | null }>({
        note: '',
        file: null,
    });

    return (
        <form
            className="mt-3 grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/client/evidence/${evidence.id}`, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <textarea
                className={textarea}
                placeholder="Describe lo que entregas"
                value={form.data.note}
                onChange={(event) => form.setData('note', event.target.value)}
                required
            />
            <div className="flex flex-wrap items-center gap-2">
                <input
                    type="file"
                    aria-label="Archivo"
                    className={`${input} w-auto pt-1.5`}
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                    required
                />
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing}
                >
                    Entregar evidencia
                </button>
            </div>
        </form>
    );
}

export default function ClientDocumentRequests({
    requests,
    evidence,
    focus,
    notice,
}: {
    requests: ClientRequest[];
    evidence: Evidence[];
    focus: { request: number | null; evidence: number | null };
    notice: string | null;
}) {
    const [onlyOpen, setOnlyOpen] = useState(true);
    const visible = requests.filter(
        (request) =>
            !onlyOpen ||
            openStatuses.includes(request.status) ||
            request.id === focus.request,
    );
    const visibleEvidence = evidence.filter(
        (item) => !onlyOpen || item.open || item.id === focus.evidence,
    );

    return (
        <ClientPage
            title="Solicitudes de documentos"
            subtitle="Lo que tu oficina te pidió: adjunta, responde y sigue su revisión"
            notice={notice}
            actions={
                <button
                    type="button"
                    className={secondaryButton}
                    onClick={() => setOnlyOpen(!onlyOpen)}
                >
                    {onlyOpen ? 'Ver también las atendidas' : 'Solo pendientes'}
                </button>
            }
        >
            {visible.length === 0 && visibleEvidence.length === 0 && (
                <Card title="Sin solicitudes">
                    <p className="py-6 text-center text-sm text-slate-500">
                        Tu oficina no tiene solicitudes pendientes para ti.
                    </p>
                </Card>
            )}
            {visible.map((request) => (
                <Card
                    key={request.id}
                    title={`${request.code} · ${request.title}`}
                    className={
                        request.id === focus.request
                            ? 'ring-2 ring-[#640000]/40'
                            : ''
                    }
                    action={
                        <span
                            className={toneBadge(
                                requestTone[request.status] ?? 'slate',
                            )}
                        >
                            {request.status_label}
                        </span>
                    }
                >
                    <dl className="grid gap-2 text-xs sm:grid-cols-4">
                        <div>
                            <dt className="text-[#526b92]">Tipo</dt>
                            <dd className="font-semibold">
                                {request.type_label}
                                {request.origin && ` · ${request.origin}`}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[#526b92]">Solicitado por</dt>
                            <dd className="font-semibold">
                                {request.requested_by}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[#526b92]">Período</dt>
                            <dd className="font-semibold">
                                {request.period ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[#526b92]">Fecha límite</dt>
                            <dd
                                className={`font-semibold ${request.overdue ? 'text-red-700' : ''}`}
                            >
                                {formatDate(request.due_date)}
                                {request.overdue && ' · vencida'}
                            </dd>
                        </div>
                    </dl>
                    {request.description && (
                        <p className="mt-2 text-sm">{request.description}</p>
                    )}
                    {request.status === 'more_information_required' &&
                        request.review_note && (
                            <p className="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-800">
                                Tu oficina pide: {request.review_note}
                            </p>
                        )}
                    {request.response && (
                        <p className="mt-2 text-xs text-[#526b92]">
                            Tu última respuesta: {request.response}
                        </p>
                    )}
                    {request.documents > 0 && (
                        <p className="mt-1 text-xs text-[#526b92]">
                            {request.documents} documento(s) adjunto(s).
                        </p>
                    )}
                    {openStatuses.includes(request.status) && (
                        <RespondForm request={request} />
                    )}
                </Card>
            ))}
            {visibleEvidence.map((item) => (
                <Card
                    key={`evidence-${item.id}`}
                    title={`${item.code} · ${item.title}`}
                    className={
                        item.id === focus.evidence
                            ? 'ring-2 ring-[#640000]/40'
                            : ''
                    }
                    action={
                        <span className={toneBadge(item.open ? 'red' : 'blue')}>
                            {item.status_label}
                        </span>
                    }
                >
                    <p className="text-xs text-[#526b92]">
                        Auditoría · fecha límite {formatDate(item.due_date)}
                    </p>
                    <p className="mt-2 text-sm">{item.description}</p>
                    {item.review_note && (
                        <p className="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-800">
                            {item.review_note}
                        </p>
                    )}
                    {item.open && <EvidenceForm evidence={item} />}
                </Card>
            ))}
        </ClientPage>
    );
}
