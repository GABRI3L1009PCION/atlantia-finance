import { Head, Link, router, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useMemo } from 'react';
import {
    CCPage,
    Field,
    Panel,
    Pill,
    SensitiveAction,
    Table,
    ccButton,
    ccGhost,
    ccInput,
    ccTextarea,
    date,
    statusTone,
} from '@/components/control-center/kit';

type AccessRequest = {
    id: number;
    organization_id: number;
    organization: string;
    ticket: string | null;
    requested_by: string;
    requested_by_id: number;
    approved_by: string | null;
    reason: string;
    scopes: string[];
    duration_minutes: number;
    status: string;
    status_label: string;
    decision_note: string | null;
    starts_at: string | null;
    expires_at: string | null;
    created_at: string;
};

export default function SupportAccess({
    requests,
    scopes,
    organizations,
    tickets,
    max_minutes,
    can,
    current_user_id,
}: {
    requests: AccessRequest[];
    scopes: Record<string, string>;
    statuses: Record<string, string>;
    organizations: { id: number; name: string }[];
    tickets: {
        id: number;
        code: string;
        subject: string;
        organization_id: number;
    }[];
    max_minutes: number;
    can: { request: boolean; approve: boolean };
    current_user_id: number;
}) {
    const preselected =
        typeof window === 'undefined'
            ? ''
            : (new URLSearchParams(window.location.search).get(
                  'organization',
              ) ?? '');
    const form = useForm<{
        organization_id: string;
        support_ticket_id: string;
        reason: string;
        scopes: string[];
        duration_minutes: number;
    }>({
        organization_id: preselected,
        support_ticket_id: '',
        reason: '',
        scopes: ['structure'],
        duration_minutes: Math.min(60, max_minutes),
    });
    const organizationTickets = useMemo(
        () =>
            tickets.filter(
                (t) => String(t.organization_id) === form.data.organization_id,
            ),
        [tickets, form.data.organization_id],
    );

    return (
        <>
            <Head title="Accesos de soporte · Control Center" />
            <CCPage
                title="Accesos de soporte"
                subtitle="Por defecto el personal SaaS no ve datos financieros de los clientes. Para diagnosticar se solicita un acceso con motivo, alcance y duración; otra persona lo aprueba, vence solo y todo lo consultado queda auditado."
            >
                {can.request && (
                    <Panel
                        title="Solicitar acceso"
                        icon={<KeyRound className="size-4 text-[#e8b04b]" />}
                    >
                        <form
                            className="grid gap-3 md:grid-cols-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/control-center/support-access', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('reason'),
                                });
                            }}
                        >
                            <Field label="Organización">
                                <select
                                    className={ccInput}
                                    value={form.data.organization_id}
                                    onChange={(e) =>
                                        form.setData((d) => ({
                                            ...d,
                                            organization_id: e.target.value,
                                            support_ticket_id: '',
                                        }))
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {organizations.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Ticket relacionado">
                                <select
                                    className={ccInput}
                                    value={form.data.support_ticket_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'support_ticket_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Sin ticket</option>
                                    {organizationTickets.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.code} · {t.subject}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={`Duración (máx. ${max_minutes} min)`}>
                                <input
                                    type="number"
                                    min={5}
                                    max={max_minutes}
                                    className={ccInput}
                                    value={form.data.duration_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'duration_minutes',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Alcance" wide>
                                <div className="grid gap-1 sm:grid-cols-2">
                                    {Object.entries(scopes).map(
                                        ([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-start gap-2 text-xs text-slate-300"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="mt-0.5"
                                                    checked={form.data.scopes.includes(
                                                        key,
                                                    )}
                                                    onChange={() =>
                                                        form.setData(
                                                            'scopes',
                                                            form.data.scopes.includes(
                                                                key,
                                                            )
                                                                ? form.data.scopes.filter(
                                                                      (s) =>
                                                                          s !==
                                                                          key,
                                                                  )
                                                                : [
                                                                      ...form
                                                                          .data
                                                                          .scopes,
                                                                      key,
                                                                  ],
                                                        )
                                                    }
                                                />
                                                {label}
                                            </label>
                                        ),
                                    )}
                                </div>
                            </Field>
                            <Field
                                label="Motivo (visible en la auditoría)"
                                wide
                            >
                                <textarea
                                    className={ccTextarea}
                                    rows={2}
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                    required
                                    minLength={10}
                                />
                            </Field>
                            {Object.values(form.errors).length > 0 && (
                                <p className="text-xs text-[#f87171] md:col-span-3">
                                    {Object.values(form.errors).join(' ')}
                                </p>
                            )}
                            <div className="md:col-span-3">
                                <button
                                    className={ccButton}
                                    disabled={form.processing}
                                >
                                    Enviar solicitud
                                </button>
                            </div>
                        </form>
                    </Panel>
                )}
                <Panel title="Solicitudes">
                    <Table
                        headers={[
                            'Organización',
                            'Solicitó',
                            'Motivo',
                            'Alcance',
                            'Duración',
                            'Estado',
                            'Vence',
                            '',
                        ]}
                        empty={
                            requests.length === 0 &&
                            'Sin solicitudes de acceso.'
                        }
                    >
                        {requests.map((request) => {
                            const own =
                                request.requested_by_id === current_user_id;

                            return (
                                <tr key={request.id}>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/organizations/${request.organization_id}?tab=support`}
                                            className="text-white"
                                        >
                                            {request.organization}
                                        </Link>
                                        {request.ticket && (
                                            <span className="block font-mono text-[10px] text-slate-500">
                                                {request.ticket}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {request.requested_by}
                                        <span className="block text-[10px] text-slate-500">
                                            {date(request.created_at, true)}
                                        </span>
                                    </td>
                                    <td className="max-w-xs px-2 py-2 text-slate-400">
                                        {request.reason}
                                        {request.decision_note && (
                                            <span className="block text-[10px] text-slate-500">
                                                Decisión:{' '}
                                                {request.decision_note} (
                                                {request.approved_by})
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2 text-[11px]">
                                        {request.scopes.join(', ')}
                                    </td>
                                    <td className="px-2 py-2">
                                        {request.duration_minutes} min
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[request.status]}>
                                            {request.status === 'expired'
                                                ? 'Vencido'
                                                : request.status_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {date(request.expires_at, true)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {request.status === 'requested' &&
                                                can.approve &&
                                                !own && (
                                                    <>
                                                        <SensitiveAction
                                                            url={`/control-center/support-access/${request.id}/approve`}
                                                            label="Aprobar"
                                                            title={`Aprobar acceso a ${request.organization}`}
                                                            description={`Alcance: ${request.scopes.join(', ')} durante ${request.duration_minutes} minutos.`}
                                                        />
                                                        <SensitiveAction
                                                            danger
                                                            url={`/control-center/support-access/${request.id}/reject`}
                                                            label="Rechazar"
                                                            title="Rechazar acceso"
                                                        />
                                                    </>
                                                )}
                                            {request.status === 'requested' &&
                                                own && (
                                                    <span className="text-[11px] text-slate-500">
                                                        Esperando aprobación de
                                                        otra persona
                                                    </span>
                                                )}
                                            {request.status === 'approved' &&
                                                own && (
                                                    <SensitiveAction
                                                        url={`/control-center/support-access/${request.id}/start`}
                                                        requireReason={false}
                                                        label="Iniciar modo soporte"
                                                        title="Iniciar modo soporte"
                                                        description={`El acceso dura ${request.duration_minutes} minutos desde ahora y cada consulta queda auditada.`}
                                                    />
                                                )}
                                            {request.status === 'active' &&
                                                own &&
                                                request.scopes[0] && (
                                                    <Link
                                                        href={`/control-center/support-access/${request.id}/view/${request.scopes[0]}`}
                                                        className={ccGhost}
                                                    >
                                                        Abrir
                                                    </Link>
                                                )}
                                            {['approved', 'active'].includes(
                                                request.status,
                                            ) &&
                                                (own || can.approve) && (
                                                    <button
                                                        type="button"
                                                        className={`${ccGhost} border-[#ef4444]/40 text-[#fca5a5]`}
                                                        onClick={() => {
                                                            const reason =
                                                                window.prompt(
                                                                    'Motivo para terminar el acceso',
                                                                );

                                                            if (reason) {
                                                                router.post(
                                                                    `/control-center/support-access/${request.id}/revoke`,
                                                                    { reason },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Terminar
                                                    </button>
                                                )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
