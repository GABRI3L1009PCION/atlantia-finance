import { Head, Link, router } from '@inertiajs/react';
import {
    CCPage,
    Panel,
    Pill,
    Table,
    ccInput,
    date,
    money,
    statusTone,
} from '@/components/control-center/kit';

type Payment = {
    id: number;
    organization_id: number;
    organization: string;
    invoice: string | null;
    provider: string;
    reference: string | null;
    amount: number;
    currency: string;
    status: string;
    attempt: number;
    created: string;
    paid_at: string | null;
    attempts: {
        status: string;
        failure_code: string | null;
        failure_message: string | null;
        attempted_at: string;
    }[];
};
type FelRequest = {
    payment_id: number;
    organization_id: number;
    organization: string;
    amount: number;
    currency: string;
    status: string;
    error_code: string | null;
    created: string;
};
const FEL_STATUS: Record<string, string> = {
    pending: 'Pendiente',
    needs_configuration: 'Requiere configuración',
    failed: 'Con error',
};
const FEL_ERROR: Record<string, string> = {
    issuer_not_configured: 'El emisor FEL de la plataforma no está configurado',
};
type Event = {
    provider: string;
    provider_event_id: string;
    event_type: string;
    status: string;
    received_at: string;
    processed_at: string | null;
    error_message: string | null;
};

export default function Payments({
    payments,
    webhook_events,
    fel_requests,
    filters,
}: {
    fel_requests: FelRequest[];
    payments: Payment[];
    webhook_events: Event[];
    filters: { status?: string; provider?: string };
}) {
    return (
        <>
            <Head title="Pagos · Control Center" />
            <CCPage
                title="Pagos"
                subtitle="Cobros de suscripciones: proveedor, referencia, intentos y resultado. Los webhooks del proveedor se procesan una sola vez (idempotencia por id de evento)."
            >
                <Panel>
                    <div className="mb-3 flex gap-2">
                        <select
                            className={`${ccInput} max-w-[180px]`}
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get('/control-center/payments', {
                                    ...filters,
                                    status: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Todos los estados</option>
                            {['pending', 'succeeded', 'failed', 'refunded'].map(
                                (s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ),
                            )}
                        </select>
                        <select
                            className={`${ccInput} max-w-[180px]`}
                            value={filters.provider ?? ''}
                            onChange={(e) =>
                                router.get('/control-center/payments', {
                                    ...filters,
                                    provider: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Todos los proveedores</option>
                            {['recurrente', 'manual', 'internal'].map((p) => (
                                <option key={p} value={p}>
                                    {p}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Table
                        headers={[
                            'Organización',
                            'Factura',
                            'Proveedor',
                            'Referencia',
                            'Monto',
                            'Estado',
                            'Intento',
                            'Creado',
                            'Pagado',
                        ]}
                        empty={payments.length === 0 && 'Sin pagos.'}
                    >
                        {payments.map((payment) => (
                            <tr key={payment.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={`/control-center/organizations/${payment.organization_id}?tab=billing`}
                                        className="text-white"
                                    >
                                        {payment.organization}
                                    </Link>
                                </td>
                                <td className="px-2 py-2 font-mono">
                                    {payment.invoice ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {payment.provider}
                                </td>
                                <td className="px-2 py-2 font-mono text-[11px]">
                                    {payment.reference ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {money(payment.amount, payment.currency)}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill tone={statusTone[payment.status]}>
                                        {payment.status}
                                    </Pill>
                                    {payment.attempts
                                        .filter((a) => a.failure_message)
                                        .slice(-1)
                                        .map((a, i) => (
                                            <span
                                                key={i}
                                                className="block text-[10px] text-[#f87171]"
                                            >
                                                {a.failure_message}
                                            </span>
                                        ))}
                                </td>
                                <td className="px-2 py-2">{payment.attempt}</td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {date(payment.created, true)}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {date(payment.paid_at, true)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
                <Panel title="Facturas FEL de suscripción pendientes o con error">
                    <Table
                        headers={[
                            'Pago',
                            'Firma',
                            'Monto',
                            'Estado',
                            'Motivo',
                            'Desde',
                        ]}
                        empty={
                            fel_requests.length === 0 &&
                            'Todas las facturas de suscripción están emitidas.'
                        }
                    >
                        {fel_requests.map((request) => (
                            <tr key={request.payment_id}>
                                <td className="px-2 py-2 font-mono">
                                    #{request.payment_id}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={`/control-center/organizations/${request.organization_id}`}
                                    >
                                        {request.organization}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {money(request.amount, request.currency)}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill
                                        tone={
                                            request.status === 'pending'
                                                ? 'amber'
                                                : 'red'
                                        }
                                    >
                                        {FEL_STATUS[request.status] ??
                                            request.status}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2 text-[#f87171]">
                                    {request.error_code
                                        ? (FEL_ERROR[request.error_code] ??
                                          request.error_code)
                                        : ''}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {date(request.created, true)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
                <Panel title="Webhooks del proveedor de pagos">
                    <Table
                        headers={[
                            'Proveedor',
                            'Evento',
                            'Id del evento',
                            'Estado',
                            'Recibido',
                            'Procesado',
                            'Error',
                        ]}
                        empty={webhook_events.length === 0 && 'Sin eventos.'}
                    >
                        {webhook_events.map((event) => (
                            <tr key={event.provider_event_id}>
                                <td className="px-2 py-2">{event.provider}</td>
                                <td className="px-2 py-2 font-mono">
                                    {event.event_type}
                                </td>
                                <td className="px-2 py-2 font-mono text-[10px]">
                                    {event.provider_event_id}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill
                                        tone={
                                            event.status === 'processed'
                                                ? 'green'
                                                : event.status === 'failed'
                                                  ? 'red'
                                                  : 'amber'
                                        }
                                    >
                                        {event.status}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2">
                                    {date(event.received_at, true)}
                                </td>
                                <td className="px-2 py-2">
                                    {date(event.processed_at, true)}
                                </td>
                                <td className="px-2 py-2 text-[#f87171]">
                                    {event.error_message ?? ''}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
