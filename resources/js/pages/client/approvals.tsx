import { useForm } from '@inertiajs/react';
import { textarea } from '@/components/auditor/kit';
import type {
    Approval,
    ClientPermissions,
} from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    approvalTone,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import { primaryButton, secondaryButton } from '@/components/office-admin/kit';

function RespondApproval({ approval }: { approval: Approval }) {
    const form = useForm({ decision: '', response: '' });
    const send = (decision: string) => {
        if (decision !== 'approved' && form.data.response.trim().length < 3) {
            form.setError('response', 'Explica tu respuesta a la oficina.');

            return;
        }

        form.transform((data) => ({ ...data, decision }));
        form.post(`/client/approvals/${approval.id}/respond`, {
            preserveScroll: true,
        });
    };

    return (
        <div className="mt-3 grid gap-2">
            <textarea
                className={textarea}
                placeholder="Comentario (obligatorio si rechazas o pides información)"
                value={form.data.response}
                onChange={(event) =>
                    form.setData('response', event.target.value)
                }
            />
            {form.errors.response && (
                <p className="text-xs font-semibold text-red-700">
                    {form.errors.response}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    className={primaryButton}
                    disabled={form.processing}
                    onClick={() => send('approved')}
                >
                    Aprobar
                </button>
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={form.processing}
                    onClick={() => send('more_information_required')}
                >
                    Pedir más información
                </button>
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={form.processing}
                    onClick={() => send('rejected')}
                >
                    Rechazar
                </button>
            </div>
            <p className="text-xs text-[#526b92]">
                Tu respuesta queda registrada; la oficina contable continúa el
                proceso. Aprobar aquí no ejecuta pagos ni presentaciones.
            </p>
        </div>
    );
}

export default function ClientApprovals({
    approvals,
    focus,
    permissions,
    notice,
}: {
    approvals: Approval[];
    focus: number | null;
    permissions: ClientPermissions;
    notice: string | null;
}) {
    return (
        <ClientPage
            title="Autorizaciones"
            subtitle="Confirmaciones que tu oficina contable necesita de ti"
            notice={notice}
        >
            {approvals.length === 0 && (
                <Card title="Sin autorizaciones">
                    <p className="py-6 text-center text-sm text-slate-500">
                        No tienes autorizaciones solicitadas.
                    </p>
                </Card>
            )}
            {approvals.map((approval) => {
                const open = ['pending', 'viewed'].includes(approval.status);

                return (
                    <Card
                        key={approval.id}
                        title={`${approval.code} · ${approval.title}`}
                        className={
                            approval.id === focus
                                ? 'ring-2 ring-[#640000]/40'
                                : ''
                        }
                        action={
                            <span
                                className={toneBadge(
                                    approvalTone[approval.status] ?? 'slate',
                                )}
                            >
                                {approval.status_label}
                            </span>
                        }
                    >
                        <dl className="grid gap-2 text-xs sm:grid-cols-3">
                            <div>
                                <dt className="text-[#526b92]">Tipo</dt>
                                <dd className="font-semibold">
                                    {approval.type_label}
                                    {approval.source_label &&
                                        ` · ${approval.source_label}`}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-[#526b92]">
                                    Solicitado por
                                </dt>
                                <dd className="font-semibold">
                                    {approval.requested_by}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-[#526b92]">Fecha límite</dt>
                                <dd
                                    className={`font-semibold ${approval.overdue ? 'text-red-700' : ''}`}
                                >
                                    {formatDate(approval.due_date)}
                                </dd>
                            </div>
                        </dl>
                        {approval.description && (
                            <p className="mt-2 text-sm whitespace-pre-line">
                                {approval.description}
                            </p>
                        )}
                        {approval.response && (
                            <p className="mt-2 text-xs text-[#526b92]">
                                Respuesta: {approval.response}
                                {approval.responded_at &&
                                    ` · ${formatDate(approval.responded_at, true)}`}
                            </p>
                        )}
                        {open &&
                            (permissions['client.approvals.respond'] ? (
                                <RespondApproval approval={approval} />
                            ) : (
                                <p className="mt-2 text-xs text-[#526b92]">
                                    Tu rol de contacto puede consultar esta
                                    autorización, pero no responderla.
                                </p>
                            ))}
                    </Card>
                );
            })}
        </ClientPage>
    );
}
