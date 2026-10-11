import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    CircleSlash,
    Download,
    Info,
    Play,
    RefreshCw,
    Send,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    ObservationDialog,
    Table,
    toneBadge,
} from '@/components/supervisor/kit';
import { obligationTone, useTaxHref } from '@/components/tax/kit';
import type { Obligation, TaxContext } from '@/components/tax/kit';

type Check = {
    key: string;
    label: string;
    status: string;
    detail: string;
    destination: string | null;
};
type Observation = {
    id: number;
    related_type_label: string;
    related_label: string | null;
    message: string;
    status: string;
    status_label: string;
    author_name: string | null;
    assignee_name: string | null;
    created_at: string;
};
type Detail = Obligation & {
    filing_mode: string | null;
    evidence_name: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    completed_at: string | null;
};

const checkIcon = (status: string) => {
    if (status === 'PASSED') {
        return <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />;
    }

    if (status === 'NOT_APPLICABLE') {
        return <CircleSlash className="size-4 shrink-0 text-slate-400" />;
    }

    return status === 'WARNING' ? (
        <Info className="size-4 shrink-0 text-amber-600" />
    ) : (
        <XCircle className="size-4 shrink-0 text-red-600" />
    );
};

const checkLabel: Record<string, string> = {
    PASSED: 'Correcto',
    WARNING: 'Advertencia',
    BLOCKED: 'Bloqueante',
    NOT_APPLICABLE: 'No aplica',
    ERROR: 'No disponible',
};

export default function TaxObligation({
    obligation,
    readiness,
    observations,
    history,
    can,
    notice,
}: {
    context: TaxContext;
    obligation: Detail;
    readiness: { checks: Check[]; ready: boolean };
    observations: Observation[];
    history: { action: string; user: string; occurred_at: string }[];
    can: Record<string, boolean>;
    notice: string | null;
}) {
    const href = useTaxHref();
    const errors = usePage().props.errors as Record<string, string>;
    const [returning, setReturning] = useState(false);
    const [comment, setComment] = useState('');
    const filing = useForm<{
        filed_on: string;
        filing_reference: string;
        evidence: File | null;
        notes: string;
    }>({
        filed_on: new Date().toISOString().slice(0, 10),
        filing_reference: '',
        evidence: null,
        notes: '',
    });
    const act = (action: string, data: Record<string, string> = {}) =>
        router.post(`/tax/obligations/${obligation.id}/${action}`, data, {
            preserveScroll: true,
        });
    const file = (event: FormEvent) => {
        event.preventDefault();
        filing.post(`/tax/obligations/${obligation.id}/complete`, {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    return (
        <>
            <Head title={`${obligation.name} · ${obligation.company_name}`} />
            <Shell>
                <Notice message={notice} />
                {Object.values(errors).length > 0 && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <Link
                    href={href('/tax/obligations')}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-[#526b92] hover:text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Obligaciones
                </Link>
                <PageHeader
                    title={`${obligation.name} · ${obligation.reference_period}`}
                    subtitle={`${obligation.company_name} · Responsable: ${obligation.assignee_name ?? 'sin asignar'} · Revisor: ${obligation.reviewer_name ?? 'sin asignar'}`}
                />

                {obligation.stale && (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        <span className="flex items-center gap-2">
                            <AlertTriangle className="size-4" /> Las ventas,
                            compras, FEL o asientos del período cambiaron
                            después de{' '}
                            {obligation.status === 'approved'
                                ? 'la aprobación'
                                : 'enviarla a revisión'}
                            . Debe recalcularse antes de continuar.
                        </span>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => act('recalculate')}
                        >
                            <RefreshCw className="size-4" /> Recalcular
                        </button>
                    </div>
                )}

                <section className="grid gap-3 md:grid-cols-5">
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Estado</p>
                        <span
                            className={toneBadge(
                                obligation.overdue
                                    ? 'red'
                                    : (obligationTone[obligation.status] ??
                                          'slate'),
                            )}
                        >
                            {obligation.overdue
                                ? 'Vencida · ' + obligation.status_label
                                : obligation.status_label}
                        </span>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">
                            Límite interno de preparación
                        </p>
                        <b
                            className={
                                obligation.internal_overdue
                                    ? 'text-amber-700'
                                    : ''
                            }
                        >
                            {formatDate(obligation.internal_due_on)}
                        </b>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">
                            Límite de revisión
                        </p>
                        <b>{formatDate(obligation.review_due_on)}</b>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">
                            Vencimiento oficial
                        </p>
                        <b className={obligation.overdue ? 'text-red-700' : ''}>
                            {formatDate(obligation.due_on)}
                        </b>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Presentación</p>
                        {obligation.status === 'completed' ? (
                            <b>
                                {formatDate(obligation.filed_on)} ·{' '}
                                {obligation.filing_reference}
                            </b>
                        ) : (
                            <span className="text-sm text-[#526b92]">
                                Sin registrar
                            </span>
                        )}
                    </div>
                </section>

                <Card
                    title="Acciones"
                    action={
                        <div className="flex flex-wrap gap-1.5">
                            {can.prepare &&
                                ['pending', 'changes_requested'].includes(
                                    obligation.status,
                                ) && (
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() => act('start')}
                                    >
                                        <Play className="size-4" /> Iniciar
                                        preparación
                                    </button>
                                )}
                            {can.submit &&
                                ['in_progress', 'changes_requested'].includes(
                                    obligation.status,
                                ) && (
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        disabled={!readiness.ready}
                                        onClick={() => act('submit')}
                                    >
                                        <Send className="size-4" /> Enviar a
                                        revisión
                                    </button>
                                )}
                            {can.review &&
                                obligation.status === 'ready_for_review' &&
                                !obligation.stale && (
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() =>
                                            act('approve', { comment })
                                        }
                                    >
                                        <CheckCircle2 className="size-4" />{' '}
                                        Aprobar
                                    </button>
                                )}
                            {can.review &&
                                ['ready_for_review', 'approved'].includes(
                                    obligation.status,
                                ) && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => setReturning(true)}
                                    >
                                        Solicitar cambios
                                    </button>
                                )}
                        </div>
                    }
                >
                    {obligation.last_observation &&
                        ['changes_requested', 'in_progress'].includes(
                            obligation.status,
                        ) && (
                            <p className="mb-2 rounded-lg bg-violet-50 p-2 text-sm text-violet-800">
                                Observación del revisor: “
                                {obligation.last_observation}”
                            </p>
                        )}
                    {can.review && obligation.status === 'ready_for_review' && (
                        <textarea
                            aria-label="Comentario"
                            className={`${input} h-16 py-2`}
                            placeholder="Comentario de aprobación (opcional)"
                            value={comment}
                            onChange={(event) => setComment(event.target.value)}
                        />
                    )}
                    {!can.review && !can.prepare && (
                        <p className="text-sm text-[#526b92]">Solo consulta.</p>
                    )}
                    {can.submit &&
                        !readiness.ready &&
                        ['in_progress', 'changes_requested'].includes(
                            obligation.status,
                        ) && (
                            <p className="text-sm text-red-700">
                                Resuelve los bloqueos del checklist para poder
                                enviarla a revisión.
                            </p>
                        )}
                    {obligation.status === 'approved' && !can.file && (
                        <p className="text-sm text-[#526b92]">
                            Aprobada. Quien tiene permiso de presentación
                            registra la constancia.
                        </p>
                    )}
                    {obligation.status === 'approved' &&
                        can.file &&
                        !obligation.stale && (
                            <form
                                onSubmit={file}
                                className="mt-2 grid gap-3 rounded-xl border border-[#e7e1dd] p-3 sm:grid-cols-2"
                            >
                                <p className="text-sm font-bold sm:col-span-2">
                                    Registrar presentación (externa a Atlantia)
                                </p>
                                <label className="text-sm font-semibold">
                                    Fecha de presentación
                                    <input
                                        type="date"
                                        className={`${input} mt-1`}
                                        value={filing.data.filed_on}
                                        onChange={(event) =>
                                            filing.setData(
                                                'filed_on',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="text-sm font-semibold">
                                    Número de formulario / referencia
                                    <input
                                        className={`${input} mt-1`}
                                        value={filing.data.filing_reference}
                                        onChange={(event) =>
                                            filing.setData(
                                                'filing_reference',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="text-sm font-semibold sm:col-span-2">
                                    Constancia (PDF o imagen)
                                    <input
                                        type="file"
                                        accept=".pdf,.png,.jpg,.jpeg"
                                        className="mt-1 block text-sm"
                                        onChange={(event) =>
                                            filing.setData(
                                                'evidence',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="text-sm font-semibold sm:col-span-2">
                                    Notas
                                    <textarea
                                        className={`${input} mt-1 h-16 py-2`}
                                        value={filing.data.notes}
                                        onChange={(event) =>
                                            filing.setData(
                                                'notes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                {Object.values(filing.errors).length > 0 && (
                                    <p className="text-sm text-red-700 sm:col-span-2">
                                        {Object.values(filing.errors)[0]}
                                    </p>
                                )}
                                <div className="sm:col-span-2">
                                    <button
                                        type="submit"
                                        className={primaryButton}
                                        disabled={filing.processing}
                                    >
                                        Registrar presentación
                                    </button>
                                </div>
                            </form>
                        )}
                    {obligation.status === 'completed' &&
                        obligation.evidence_name && (
                            <a
                                href={`/tax/obligations/${obligation.id}/evidence`}
                                className={`${secondaryButton} mt-2`}
                            >
                                <Download className="size-4" />{' '}
                                {obligation.evidence_name}
                            </a>
                        )}
                </Card>

                <Card
                    title="Checklist de la obligación"
                    action={
                        <span
                            className={toneBadge(
                                readiness.ready ? 'green' : 'red',
                            )}
                        >
                            {readiness.ready ? 'Sin bloqueos' : 'Con bloqueos'}
                        </span>
                    }
                >
                    <ul className="grid gap-1.5 text-sm md:grid-cols-2">
                        {readiness.checks.map((check) => (
                            <li
                                key={check.key}
                                className="flex items-start gap-2 rounded-lg border border-slate-100 p-2"
                            >
                                {checkIcon(check.status)}
                                <span className="min-w-0 flex-1">
                                    <b>{check.label}</b>{' '}
                                    <span className="text-[11px] text-[#526b92]">
                                        (
                                        {checkLabel[check.status] ??
                                            check.status}
                                        )
                                    </span>
                                    <span className="block text-xs text-[#526b92]">
                                        {check.detail}
                                    </span>
                                </span>
                                {check.destination && (
                                    <a
                                        href={check.destination}
                                        className="shrink-0 text-xs font-bold text-[#640000] hover:underline"
                                    >
                                        Ir al origen
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Observaciones de la obligación">
                        <Table
                            headers={[
                                'Observación',
                                'Creó',
                                'Responsable',
                                'Estado',
                            ]}
                            empty={
                                observations.length === 0 &&
                                'Sin observaciones.'
                            }
                        >
                            {observations.map((observation) => (
                                <tr key={observation.id}>
                                    <td className="px-2 py-2">
                                        {observation.message}
                                    </td>
                                    <td className="px-2 py-2">
                                        {observation.author_name ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {observation.assignee_name ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {observation.status_label}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Historial">
                        <Table
                            headers={['Fecha', 'Usuario', 'Acción']}
                            empty={history.length === 0 && 'Sin eventos.'}
                        >
                            {history.map((event, index) => (
                                <tr key={index}>
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {new Date(
                                            event.occurred_at,
                                        ).toLocaleString('es-GT')}
                                    </td>
                                    <td className="px-2 py-2">{event.user}</td>
                                    <td className="px-2 py-2 font-mono text-[11px]">
                                        {event.action}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
            </Shell>
            <ObservationDialog
                open={returning}
                title="Solicitar cambios"
                onClose={() => setReturning(false)}
                onSubmit={(message) =>
                    router.post(
                        `/tax/obligations/${obligation.id}/changes`,
                        { message },
                        {
                            preserveScroll: true,
                            onSuccess: () => setReturning(false),
                        },
                    )
                }
            />
        </>
    );
}
