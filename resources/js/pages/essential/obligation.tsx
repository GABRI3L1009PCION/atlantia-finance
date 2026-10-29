import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Circle,
    FileUp,
    Loader2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ProgressBar, obligationTone } from '@/components/essential/kit';
import type { EssentialContext } from '@/components/essential/kit';
import {
    Shell,
    formatDate,
    input,
    primaryButton,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, money, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Check = {
    key: string;
    label: string;
    kind: 'manual' | 'automatic';
    status: string;
    detail: string | null;
    destination: string | null;
    requires_file: boolean;
    completed_by: string | null;
    completed_at: string | null;
    note: string | null;
    file_name: string | null;
};

type Props = {
    essential: EssentialContext;
    obligation: {
        id: number;
        name: string;
        period: string;
        type: string;
        status: string;
        status_label: string;
        company: { id: number; name: string; public_id: string };
        due_on: string;
        internal_due_on: string;
        days_overdue: number;
        days_to_due: number;
        preparer: string | null;
        reviewer: string | null;
        started_at: string | null;
        submitted_at: string | null;
        approved_at: string | null;
        last_observation: string | null;
        filing: {
            reference: string;
            filed_on: string;
            evidence_name: string | null;
        } | null;
    };
    readiness: {
        ready: boolean;
        progress: number;
        error: string | null;
        checks: Check[];
        completed: string[];
        pending: string[];
        blockers: { label: string; detail: string | null }[];
        warnings: { label: string; detail: string | null }[];
        next_action: string | null;
    };
    calculation: {
        sales_vat_cents: number;
        purchase_vat_cents: number;
        previous_credit_cents: number;
        payable_cents: number;
        carry_forward_cents: number;
        sales: number;
        purchases: number;
        book_status: string;
    } | null;
    observations: {
        id: number;
        code: string;
        message: string;
        severity: string;
        status: string;
        status_label: string;
        resolution: string | null;
        related_label: string | null;
        destination: string | null;
        author: string | null;
        created_at: string;
    }[];
    timeline: {
        at: string;
        label: string;
        actor: string;
        detail: string | null;
    }[];
    can: {
        start: boolean;
        submit: boolean;
        approve: boolean;
        changes: boolean;
        complete: boolean;
        recalculate: boolean;
    };
    review_policy: string;
    may_finish_own: boolean;
    is_preparer: boolean;
    confirm: 'submit' | 'finalize' | null;
    version: string;
    also_editing: string[];
    task: { id: number; status: string } | null;
    waits: {
        id: number;
        kind: string;
        status: string;
        reason: string;
        expected_on: string | null;
        overdue: boolean;
        via_portal: boolean;
        requested_by: string | null;
        resolution: string | null;
    }[];
};

type Dialogs =
    | { kind: 'check'; check: Check }
    | { kind: 'corrected'; observation: number }
    | { kind: 'wait_client' | 'wait_external' }
    | { kind: 'receive'; wait: number }
    | { kind: 'submit' | 'finalize' | 'approve' | 'changes' | 'complete' }
    | null;

export default function EssentialObligation(props: Props) {
    const { obligation, readiness, can, essential } = props;
    const [dialog, setDialog] = useState<Dialogs>(
        props.confirm && readiness.ready ? { kind: props.confirm } : null,
    );
    const [busy, setBusy] = useState(false);
    const post = (action: string) => {
        setBusy(true);
        router.post(
            `/essential/obligations/${obligation.id}/${action}`,
            { version: props.version },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };
    const preparing = ['in_progress', 'changes_requested'].includes(
        obligation.status,
    );
    const manual = readiness.checks.filter((check) => check.kind === 'manual');
    const automatic = readiness.checks.filter(
        (check) => check.kind !== 'manual',
    );
    const labelOf = (key: string) =>
        readiness.checks.find((check) => check.key === key)?.label ?? key;
    const missing = readiness.pending.length;

    return (
        <>
            <Head title={`${obligation.name} · ${obligation.period}`} />
            <Shell>
                <Link
                    href="/essential/obligations"
                    className="inline-flex items-center gap-1 text-sm font-semibold text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Obligaciones fiscales
                </Link>

                <header className="rounded-xl border border-[#e7e1dd] bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-black text-[#061633]">
                                {obligation.name} · {obligation.period}
                            </h1>
                            <p className="text-sm text-[#526b92]">
                                {obligation.company.name}
                            </p>
                        </div>
                        <span
                            className={toneBadge(
                                obligationTone[obligation.status] ?? 'slate',
                            )}
                        >
                            {obligation.status_label}
                        </span>
                    </div>
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-5">
                        <Meta label="Prepara" value={obligation.preparer} />
                        <Meta label="Revisa" value={obligation.reviewer} />
                        <Meta
                            label="Vence"
                            value={formatDate(obligation.due_on)}
                            tone={obligation.days_overdue > 0 ? 'red' : null}
                            hint={
                                obligation.status === 'completed'
                                    ? null
                                    : obligation.days_overdue > 0
                                      ? `Vencida hace ${obligation.days_overdue} día(s)`
                                      : `Faltan ${obligation.days_to_due} día(s)`
                            }
                        />
                        <Meta
                            label="Iniciada"
                            value={
                                obligation.started_at
                                    ? formatDate(obligation.started_at, true)
                                    : 'Sin iniciar'
                            }
                        />
                        <div>
                            <dt className="text-xs font-semibold text-slate-500 uppercase">
                                Progreso
                            </dt>
                            <dd className="mt-1">
                                {readiness.error ? (
                                    <span className="text-red-700">
                                        {readiness.error}
                                    </span>
                                ) : (
                                    <ProgressBar value={readiness.progress} />
                                )}
                            </dd>
                        </div>
                    </dl>
                </header>

                {props.also_editing.length > 0 && (
                    <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <strong>{props.also_editing.join(', ')}</strong> también
                        tiene abierta esta obligación. Coordinen para no
                        preparar lo mismo a la vez: si alguien guarda cambios
                        antes, se te pedirá recargar.
                    </p>
                )}
                {props.waits.some((wait) => wait.status === 'open') && (
                    <div className="rounded-xl border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900">
                        <p className="font-bold">En espera</p>
                        <ul className="mt-1 space-y-2">
                            {props.waits
                                .filter((wait) => wait.status === 'open')
                                .map((wait) => (
                                    <li
                                        key={wait.id}
                                        className="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <span>
                                            {wait.kind === 'client'
                                                ? 'Cliente'
                                                : 'Tercero'}
                                            {wait.via_portal ? ' (portal)' : ''}
                                            : {wait.reason}
                                            {wait.expected_on
                                                ? ` · esperado ${formatDate(wait.expected_on)}`
                                                : ''}
                                            {wait.overdue ? ' · vencida' : ''}
                                        </span>
                                        {!wait.via_portal && props.task && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    setDialog({
                                                        kind: 'receive',
                                                        wait: wait.id,
                                                    })
                                                }
                                            >
                                                Registrar lo recibido
                                            </button>
                                        )}
                                    </li>
                                ))}
                        </ul>
                    </div>
                )}
                {obligation.status === 'changes_requested' &&
                    obligation.last_observation && (
                        <p className="rounded-xl border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900">
                            <strong>Cambios solicitados:</strong>{' '}
                            {obligation.last_observation}
                        </p>
                    )}
                {obligation.status === 'approved' && (
                    <p className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                        <strong>Aprobada, lista para presentar.</strong> Aprobar
                        no es presentar: preséntala en el portal de la SAT y
                        registra aquí la constancia.
                    </p>
                )}
                {obligation.filing && (
                    <p className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                        <strong>Presentada</strong> el{' '}
                        {formatDate(obligation.filing.filed_on)} · referencia{' '}
                        {obligation.filing.reference}
                        {obligation.filing.evidence_name
                            ? ` · constancia ${obligation.filing.evidence_name}`
                            : ''}
                    </p>
                )}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="space-y-4">
                        <Card title="Requisitos de la preparación">
                            {obligation.status === 'pending' && (
                                <p className="mb-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                                    Inicia la obligación para empezar a
                                    trabajarla. Iniciar solo la asume; los
                                    requisitos se cumplen con trabajo real.
                                </p>
                            )}
                            <ul className="divide-y divide-slate-100">
                                {manual.map((check) => (
                                    <li
                                        key={check.key}
                                        className="flex items-start gap-3 py-3"
                                    >
                                        {check.status === 'PASSED' ? (
                                            <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                        ) : (
                                            <Circle className="mt-0.5 size-5 shrink-0 text-slate-300" />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold">
                                                {check.label}
                                            </p>
                                            {check.completed_at && (
                                                <p className="text-xs text-slate-500">
                                                    {check.completed_by} ·{' '}
                                                    {formatDate(
                                                        check.completed_at,
                                                        true,
                                                    )}
                                                    {check.file_name
                                                        ? ` · ${check.file_name}`
                                                        : ''}
                                                </p>
                                            )}
                                            {check.note && (
                                                <p className="text-xs text-slate-600">
                                                    “{check.note}”
                                                </p>
                                            )}
                                        </div>
                                        {preparing && props.is_preparer && (
                                            <div className="flex gap-1">
                                                {check.status === 'PASSED' ? (
                                                    <button
                                                        type="button"
                                                        className={
                                                            secondaryButton
                                                        }
                                                        onClick={() =>
                                                            router.delete(
                                                                `/essential/obligations/${obligation.id}/checks/${check.key}`,
                                                                {
                                                                    preserveScroll: true,
                                                                    data: {
                                                                        version:
                                                                            props.version,
                                                                    },
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Reabrir
                                                    </button>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className={
                                                            secondaryButton
                                                        }
                                                        onClick={() =>
                                                            setDialog({
                                                                kind: 'check',
                                                                check,
                                                            })
                                                        }
                                                    >
                                                        {check.requires_file ? (
                                                            <>
                                                                <FileUp className="size-3.5" />{' '}
                                                                Adjuntar
                                                            </>
                                                        ) : (
                                                            'Marcar hecho'
                                                        )}
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <h3 className="mt-5 mb-1 text-sm font-black text-[#061633]">
                                Validaciones automáticas
                            </h3>
                            <p className="mb-2 text-xs text-slate-500">
                                Atlantia las verifica con los datos reales; no
                                se marcan a mano.
                            </p>
                            <ul className="divide-y divide-slate-100">
                                {automatic.map((check) => (
                                    <li
                                        key={check.key}
                                        className="flex items-start gap-3 py-2.5"
                                    >
                                        <AutoIcon status={check.status} />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold">
                                                {check.label}
                                            </p>
                                            {check.detail && (
                                                <p className="text-xs text-slate-500">
                                                    {check.detail}
                                                </p>
                                            )}
                                        </div>
                                        {check.destination &&
                                            check.status !== 'PASSED' && (
                                                <Link
                                                    href={check.destination}
                                                    className={secondaryButton}
                                                >
                                                    Resolver
                                                </Link>
                                            )}
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        {props.calculation && (
                            <Card title="Cálculo del IVA (de los libros reales)">
                                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                    <Meta
                                        label={`IVA débito (${props.calculation.sales} venta(s))`}
                                        value={money(
                                            props.calculation.sales_vat_cents,
                                        )}
                                    />
                                    <Meta
                                        label={`IVA crédito (${props.calculation.purchases} compra(s))`}
                                        value={money(
                                            props.calculation
                                                .purchase_vat_cents,
                                        )}
                                    />
                                    <Meta
                                        label="Crédito del mes anterior"
                                        value={money(
                                            props.calculation
                                                .previous_credit_cents,
                                        )}
                                    />
                                    <Meta
                                        label="Resultado a pagar"
                                        value={money(
                                            props.calculation.payable_cents,
                                        )}
                                        hint={
                                            props.calculation
                                                .carry_forward_cents > 0
                                                ? `Crédito a favor ${money(props.calculation.carry_forward_cents)}`
                                                : null
                                        }
                                    />
                                </dl>
                                <Link
                                    href={`/accounting/companies/${obligation.company.public_id}/vat-books`}
                                    className={`${secondaryButton} mt-3`}
                                >
                                    Abrir libros de IVA
                                </Link>
                            </Card>
                        )}

                        <Card title="Observaciones de la revisión">
                            {props.observations.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    Sin observaciones.
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {props.observations.map((item) => (
                                        <li
                                            key={item.id}
                                            className="rounded-lg border border-slate-200 p-3 text-sm"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <strong>{item.code}</strong>
                                                <span
                                                    className={toneBadge(
                                                        item.status ===
                                                            'resolved'
                                                            ? 'green'
                                                            : 'violet',
                                                    )}
                                                >
                                                    {item.status_label}
                                                </span>
                                            </div>
                                            <p className="mt-1">
                                                {item.message}
                                            </p>
                                            {item.related_label && (
                                                <p className="mt-1 text-xs font-semibold text-violet-700">
                                                    Registro:{' '}
                                                    {item.related_label}
                                                </p>
                                            )}
                                            <p className="mt-1 text-xs text-slate-500">
                                                {item.author} ·{' '}
                                                {relativeTime(item.created_at)}
                                            </p>
                                            {props.is_preparer &&
                                                [
                                                    'open',
                                                    'reopened',
                                                    'in_progress',
                                                ].includes(item.status) && (
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {item.destination && (
                                                            <Link
                                                                href={
                                                                    item.destination
                                                                }
                                                                className={
                                                                    secondaryButton
                                                                }
                                                            >
                                                                Corregir en su
                                                                origen
                                                            </Link>
                                                        )}
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() =>
                                                                setDialog({
                                                                    kind: 'corrected',
                                                                    observation:
                                                                        item.id,
                                                                })
                                                            }
                                                        >
                                                            Marcar corregida
                                                        </button>
                                                    </div>
                                                )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <div className="space-y-4">
                        <Card title="Estado de la preparación">
                            {readiness.error ? (
                                <p className="text-sm font-semibold text-red-700">
                                    {readiness.error}
                                </p>
                            ) : readiness.ready ? (
                                <p className="text-sm font-semibold text-emerald-700">
                                    Todos los requisitos están completos y no
                                    hay bloqueos.
                                </p>
                            ) : (
                                <>
                                    <p className="text-sm font-semibold">
                                        Faltan {missing} requisito(s):
                                    </p>
                                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
                                        {readiness.pending.map((item) => (
                                            <li key={item}>{item}</li>
                                        ))}
                                    </ul>
                                </>
                            )}
                            {readiness.warnings.length > 0 && (
                                <div className="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
                                    <p className="font-bold">
                                        {readiness.warnings.length}{' '}
                                        advertencia(s)
                                    </p>
                                    {readiness.warnings.map((warning) => (
                                        <p key={warning.label}>
                                            {warning.label}
                                            {warning.detail
                                                ? `: ${warning.detail}`
                                                : ''}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </Card>

                        <Card title="Historial">
                            {props.timeline.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    Sin movimientos todavía.
                                </p>
                            ) : (
                                <ol className="space-y-3 border-l-2 border-slate-100 pl-4">
                                    {props.timeline.map((event, index) => (
                                        <li key={index} className="text-sm">
                                            <p className="font-semibold">
                                                {event.label}
                                                {event.detail &&
                                                event.label.startsWith(
                                                    'Requisito',
                                                )
                                                    ? `: ${labelOf(event.detail)}`
                                                    : ''}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {event.actor} ·{' '}
                                                {formatDate(event.at, true)}
                                            </p>
                                            {event.detail &&
                                                !event.label.startsWith(
                                                    'Requisito',
                                                ) && (
                                                    <p className="text-xs text-slate-600">
                                                        {event.detail}
                                                    </p>
                                                )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Card>
                    </div>
                </div>

                <footer className="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 rounded-xl border border-[#e7e1dd] bg-white p-3 shadow-lg">
                    {essential.solo && props.may_finish_own && (
                        <span className="mr-auto text-xs text-slate-500">
                            Política: {props.review_policy}
                        </span>
                    )}
                    {preparing && props.is_preparer && props.task && (
                        <>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    setDialog({ kind: 'wait_client' })
                                }
                            >
                                Esperar al cliente
                            </button>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    setDialog({ kind: 'wait_external' })
                                }
                            >
                                Esperar a un tercero
                            </button>
                        </>
                    )}{' '}
                    {obligation.status === 'pending' && can.start && (
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={busy}
                            onClick={() => post('start')}
                        >
                            Iniciar
                        </button>
                    )}
                    {preparing && props.is_preparer && (
                        <>
                            <Link
                                href="/essential/obligations"
                                className={secondaryButton}
                            >
                                Salir
                            </Link>
                            {props.may_finish_own ? (
                                <button
                                    type="button"
                                    className={primaryButton}
                                    disabled={!readiness.ready || busy}
                                    title={
                                        readiness.ready
                                            ? undefined
                                            : `Completa ${missing} requisito(s) antes de finalizar.`
                                    }
                                    onClick={() =>
                                        setDialog({ kind: 'finalize' })
                                    }
                                >
                                    Finalizar preparación
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    className={primaryButton}
                                    disabled={!readiness.ready || busy}
                                    title={
                                        readiness.ready
                                            ? undefined
                                            : readiness.error
                                              ? readiness.error
                                              : `Completa ${missing} requisito(s) antes de enviar.`
                                    }
                                    onClick={() =>
                                        setDialog({ kind: 'submit' })
                                    }
                                >
                                    Enviar a revisión
                                </button>
                            )}
                        </>
                    )}
                    {can.recalculate && (
                        <button
                            type="button"
                            className={secondaryButton}
                            disabled={busy}
                            onClick={() => post('recalculate')}
                        >
                            Recalcular
                        </button>
                    )}
                    {can.changes && (
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setDialog({ kind: 'changes' })}
                        >
                            Solicitar cambios
                        </button>
                    )}
                    {can.approve && (
                        <button
                            type="button"
                            className={primaryButton}
                            onClick={() => setDialog({ kind: 'approve' })}
                        >
                            Aprobar
                        </button>
                    )}
                    {can.complete && (
                        <button
                            type="button"
                            className={primaryButton}
                            onClick={() => setDialog({ kind: 'complete' })}
                        >
                            Registrar presentación externa
                        </button>
                    )}
                </footer>
            </Shell>

            <ActionDialog
                dialog={dialog}
                onClose={() => setDialog(null)}
                props={props}
            />
        </>
    );
}

function Meta({
    label,
    value,
    hint,
    tone,
}: {
    label: string;
    value: string | null;
    hint?: string | null;
    tone?: 'red' | null;
}) {
    return (
        <div>
            <dt className="text-xs font-semibold text-slate-500 uppercase">
                {label}
            </dt>
            <dd
                className={`mt-1 font-semibold ${tone === 'red' ? 'text-red-700' : ''}`}
            >
                {value ?? '—'}
            </dd>
            {hint && <p className="text-xs text-slate-500">{hint}</p>}
        </div>
    );
}

function AutoIcon({ status }: { status: string }) {
    if (status === 'PASSED' || status === 'NOT_APPLICABLE') {
        return (
            <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
        );
    }

    return (
        <TriangleAlert
            className={`mt-0.5 size-5 shrink-0 ${status === 'WARNING' ? 'text-amber-500' : 'text-red-600'}`}
        />
    );
}

function ActionDialog({
    dialog,
    onClose,
    props,
}: {
    dialog: Dialogs;
    onClose: () => void;
    props: Props;
}) {
    const { obligation, readiness } = props;
    const form = useForm({
        note: '',
        file: null as File | null,
        comment: '',
        message: '',
        related_type: '',
        related_id: '',
        filed_on: new Date().toISOString().slice(0, 10),
        filing_reference: '',
        evidence: null as File | null,
        notes: '',
        expected_on: '',
    });
    const base = `/essential/obligations/${obligation.id}`;
    const done = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            onClose();
        },
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!dialog) {
            return;
        }

        if (dialog.kind === 'check') {
            form.transform((data) => ({
                note: data.note,
                file: data.file,
                version: props.version,
            }));
            form.post(`${base}/checks/${dialog.check.key}`, done);

            return;
        }

        if (
            dialog.kind === 'wait_client' ||
            dialog.kind === 'wait_external' ||
            dialog.kind === 'receive'
        ) {
            const taskId = props.task?.id;
            form.transform((data) => ({
                note: data.note,
                expected_on:
                    dialog.kind === 'receive' ? null : data.expected_on || null,
                wait_id: dialog.kind === 'receive' ? dialog.wait : null,
                file: dialog.kind === 'receive' ? data.file : null,
            }));
            form.post(`/essential/tasks/${taskId}/${dialog.kind}`, done);

            return;
        }

        if (dialog.kind === 'corrected') {
            form.transform((data) => ({ note: data.note }));
            form.post(
                `${base}/observations/${dialog.observation}/corrected`,
                done,
            );

            return;
        }

        form.transform((data) => {
            switch (dialog.kind) {
                case 'approve':
                    return { comment: data.comment, version: props.version };
                case 'changes':
                    return {
                        version: props.version,
                        message: data.message,
                        related_type: data.related_type || null,
                        related_id: data.related_id || null,
                    };
                case 'complete':
                    return {
                        version: props.version,
                        filed_on: data.filed_on,
                        filing_reference: data.filing_reference,
                        evidence: data.evidence,
                        notes: data.notes,
                    };
                default:
                    return {};
            }
        });
        form.post(`${base}/${dialog.kind}`, done);
    };
    const title = !dialog
        ? ''
        : dialog.kind === 'check'
          ? dialog.check.label
          : dialog.kind === 'corrected'
            ? 'Marcar observación como corregida'
            : dialog.kind === 'wait_client'
              ? 'Esperar al cliente'
              : dialog.kind === 'wait_external'
                ? 'Esperar a un tercero'
                : dialog.kind === 'receive'
                  ? 'Registrar lo recibido'
                  : {
                        submit: '¿Enviar a revisión?',
                        finalize: '¿Finalizar la preparación?',
                        approve: 'Aprobar obligación',
                        changes: 'Solicitar cambios',
                        complete: 'Registrar presentación externa',
                    }[dialog.kind];
    const confirmOnly =
        dialog?.kind === 'submit' || dialog?.kind === 'finalize';

    return (
        <Dialog
            open={dialog !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {obligation.name} · {obligation.period} ·{' '}
                        {obligation.company.name}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {confirmOnly && (
                        <div className="rounded-lg bg-slate-50 p-3 text-sm">
                            <p>
                                <strong>
                                    {
                                        readiness.checks.filter(
                                            (check) =>
                                                check.status !== 'PENDING' &&
                                                check.status !== 'BLOCKED' &&
                                                check.status !== 'ERROR',
                                        ).length
                                    }{' '}
                                    de {readiness.checks.length}
                                </strong>{' '}
                                requisitos completos ·{' '}
                                <strong>{readiness.blockers.length}</strong>{' '}
                                bloqueos ·{' '}
                                <strong>{readiness.warnings.length}</strong>{' '}
                                advertencias
                            </p>
                            <p className="mt-2 text-xs text-slate-600">
                                {dialog?.kind === 'finalize'
                                    ? `Tú preparaste esta obligación y tu política (${props.review_policy}) permite finalizarla sin enviarte una revisión. Quedará aprobada y auditada.`
                                    : `${obligation.reviewer ?? 'La persona que revisa'} recibirá la obligación para revisarla.`}
                            </p>
                        </div>
                    )}
                    {dialog?.kind === 'check' && (
                        <>
                            <label className="block text-sm font-semibold">
                                Nota (opcional)
                                <textarea
                                    className={`${input} mt-1 h-20 py-2`}
                                    maxLength={500}
                                    value={form.data.note}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                />
                            </label>
                            {dialog.check.requires_file && (
                                <label className="block text-sm font-semibold">
                                    Archivo (PDF, imagen o Excel, máx. 10 MB)
                                    <input
                                        type="file"
                                        required
                                        className={`${input} mt-1 pt-1.5`}
                                        onChange={(event) =>
                                            form.setData(
                                                'file',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </label>
                            )}
                            {form.errors.file && (
                                <p className="text-xs text-red-700">
                                    {form.errors.file}
                                </p>
                            )}
                        </>
                    )}
                    {(dialog?.kind === 'wait_client' ||
                        dialog?.kind === 'wait_external' ||
                        dialog?.kind === 'receive') && (
                        <>
                            <label className="block text-sm font-semibold">
                                {dialog.kind === 'receive'
                                    ? '¿Qué llegó?'
                                    : '¿Qué se espera y de quién?'}
                                <textarea
                                    className={`${input} mt-1 h-24 py-2`}
                                    required
                                    maxLength={1000}
                                    value={form.data.note}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                />
                            </label>
                            {dialog.kind !== 'receive' && (
                                <label className="block text-sm font-semibold">
                                    Fecha esperada (opcional)
                                    <input
                                        type="date"
                                        className={`${input} mt-1`}
                                        value={form.data.expected_on}
                                        onChange={(event) =>
                                            form.setData(
                                                'expected_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            )}
                            {dialog.kind === 'receive' && (
                                <label className="block text-sm font-semibold">
                                    Archivo recibido (opcional)
                                    <input
                                        type="file"
                                        className={`${input} mt-1 pt-1.5`}
                                        onChange={(event) =>
                                            form.setData(
                                                'file',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </label>
                            )}
                            {(form.errors as Record<string, string>).action && (
                                <p className="text-xs text-red-700">
                                    {
                                        (form.errors as Record<string, string>)
                                            .action
                                    }
                                </p>
                            )}
                        </>
                    )}{' '}
                    {dialog?.kind === 'corrected' && (
                        <label className="block text-sm font-semibold">
                            ¿Qué corregiste?
                            <textarea
                                className={`${input} mt-1 h-24 py-2`}
                                required
                                minLength={3}
                                maxLength={1000}
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                            {form.errors.note && (
                                <span className="text-xs text-red-700">
                                    {form.errors.note}
                                </span>
                            )}
                        </label>
                    )}
                    {dialog?.kind === 'approve' && (
                        <label className="block text-sm font-semibold">
                            Comentario (opcional)
                            <textarea
                                className={`${input} mt-1 h-20 py-2`}
                                maxLength={1000}
                                value={form.data.comment}
                                onChange={(event) =>
                                    form.setData('comment', event.target.value)
                                }
                            />
                        </label>
                    )}
                    {dialog?.kind === 'changes' && (
                        <>
                            <label className="block text-sm font-semibold">
                                ¿Qué debe corregirse?
                                <textarea
                                    className={`${input} mt-1 h-24 py-2`}
                                    required
                                    minLength={5}
                                    maxLength={1200}
                                    value={form.data.message}
                                    onChange={(event) =>
                                        form.setData(
                                            'message',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-2">
                                <label className="text-sm font-semibold">
                                    Registro observado (opcional)
                                    <select
                                        className={`${input} mt-1`}
                                        value={form.data.related_type}
                                        onChange={(event) =>
                                            form.setData(
                                                'related_type',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Toda la obligación
                                        </option>
                                        <option value="purchase_documents">
                                            Compra
                                        </option>
                                        <option value="sales_invoices">
                                            Venta
                                        </option>
                                        <option value="journal_entries">
                                            Asiento
                                        </option>
                                    </select>
                                </label>
                                <label className="text-sm font-semibold">
                                    Número de registro
                                    <input
                                        className={`${input} mt-1`}
                                        inputMode="numeric"
                                        disabled={!form.data.related_type}
                                        value={form.data.related_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'related_id',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            {form.errors.related_id && (
                                <p className="text-xs text-red-700">
                                    {form.errors.related_id}
                                </p>
                            )}
                        </>
                    )}
                    {dialog?.kind === 'complete' && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm font-semibold">
                                Fecha de presentación
                                <input
                                    type="date"
                                    required
                                    className={`${input} mt-1`}
                                    max={new Date().toISOString().slice(0, 10)}
                                    value={form.data.filed_on}
                                    onChange={(event) =>
                                        form.setData(
                                            'filed_on',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="text-sm font-semibold">
                                Formulario o referencia
                                <input
                                    required
                                    maxLength={120}
                                    className={`${input} mt-1`}
                                    value={form.data.filing_reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'filing_reference',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="text-sm font-semibold sm:col-span-2">
                                Constancia de la SAT (PDF o imagen)
                                <input
                                    type="file"
                                    required
                                    accept="application/pdf,image/png,image/jpeg"
                                    className={`${input} mt-1 pt-1.5`}
                                    onChange={(event) =>
                                        form.setData(
                                            'evidence',
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </label>
                            <label className="text-sm font-semibold sm:col-span-2">
                                Observaciones (opcional)
                                <textarea
                                    className={`${input} mt-1 h-16 py-2`}
                                    maxLength={1000}
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <p className="text-xs text-slate-500 sm:col-span-2">
                                Atlantia no presenta nada ante la SAT: aquí
                                registras lo que ya presentaste en su portal.
                            </p>
                        </div>
                    )}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            {form.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}{' '}
                            {dialog?.kind === 'submit'
                                ? 'Enviar a revisión'
                                : dialog?.kind === 'finalize'
                                  ? 'Finalizar preparación'
                                  : 'Confirmar'}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
