import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CircleSlash,
    Info,
    Plus,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { EntriesTable } from '@/components/accountant/entries';
import {
    checkLabel,
    checkTone,
    useAccountantHref,
} from '@/components/accountant/kit';
import type {
    AccountantCompany,
    AccountantContext,
    AccountantPermissions,
    Entry,
    Observation,
} from '@/components/accountant/kit';
import {
    NewObservationDialog,
    ObservationTable,
} from '@/components/accountant/observations';
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
    ProgressBar,
    Table,
    post,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';
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
    group: string;
    label: string;
    status: string;
    detail: string;
    destination: string | null;
};
type ReviewFile = {
    company: AccountantCompany;
    workflow: 'single_level' | 'double_level';
    review: {
        id: number;
        status: string;
        status_label: string;
        fiscal_status: string | null;
        submitted_by: string | null;
        submitted_at: string | null;
        first_level_by: string | null;
        first_level_at: string | null;
        upper_reviewer: string | null;
        approval_comment: string | null;
    } | null;
    checklist: {
        checks: Check[];
        summary: Record<string, number>;
        ready_for_approval: boolean;
    } | null;
    checklist_error: string | null;
    observations: Observation[];
    entries: Entry[];
    fiscal_obligations: {
        id: number;
        name: string;
        reference_period: string;
        due_on: string;
        status: string;
        status_label: string;
        assignee_name: string | null;
        stale: boolean;
        destination: string;
    }[];
    assignees: { id: number; name: string }[];
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

export default function AccountantReview({
    file,
    relatedTypes,
    severities,
    permissions,
    notice,
}: {
    context: AccountantContext;
    file: ReviewFile;
    relatedTypes: Record<string, string>;
    severities: Record<string, string>;
    permissions: AccountantPermissions;
    notice: string | null;
}) {
    const href = useAccountantHref();
    const page = usePage();
    const userId = (page.props.auth as { user: { id: number } }).user.id;
    const errors = page.props.errors as Record<string, string>;
    const { company, review, checklist } = file;
    const [observing, setObserving] = useState<
        | { related_type: string; related_id: number; related_label: string }
        | null
        | undefined
    >(undefined);
    const [approving, setApproving] = useState(false);
    const [changes, setChanges] = useState(false);
    const [comment, setComment] = useState('');
    const groups = checklist
        ? [...new Set(checklist.checks.map((check) => check.group))]
        : [];
    const blockingObservations = file.observations.filter(
        (observation) =>
            ['open', 'in_progress', 'reopened'].includes(observation.status) &&
            ['high', 'critical'].includes(observation.severity),
    ).length;

    return (
        <>
            <Head title={`Revisión · ${company.name}`} />
            <Shell>
                <Notice message={notice} />
                {Object.values(errors).length > 0 && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <Link
                    href={href('/accountant/companies')}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-[#526b92] hover:text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Empresas a cargo
                </Link>
                <PageHeader
                    title={company.name}
                    subtitle={`NIT ${company.tax_id} · ${company.period_name ?? 'Sin período abierto'} · Auxiliar: ${company.assistant?.name ?? 'sin asignar'}`}
                    actions={
                        <a
                            href={company.destination}
                            className={secondaryButton}
                        >
                            Abrir contabilidad de la empresa
                        </a>
                    }
                />

                <section className="grid gap-3 lg:grid-cols-4">
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Estado</p>
                        <span
                            className={toneBadge(
                                company.blocked
                                    ? 'red'
                                    : (statusTone[company.status] ?? 'slate'),
                            )}
                        >
                            {company.blocked
                                ? 'Bloqueada'
                                : company.status_label}
                        </span>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">
                            Avance del período
                        </p>
                        <ProgressBar value={company.progress} />
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">
                            Flujo de revisión
                        </p>
                        <p className="font-bold">
                            {file.workflow === 'double_level'
                                ? 'Dos niveles (contador general revisa después)'
                                : 'Un nivel'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">
                            Observaciones abiertas
                        </p>
                        <p className="text-2xl font-black text-violet-700">
                            {company.observations}
                        </p>
                    </div>
                </section>

                <Card
                    title="Revisión del período"
                    action={
                        review && (
                            <div className="flex flex-wrap gap-1.5">
                                {review.status === 'ready_for_review' &&
                                    permissions.canStartReview && (
                                        <button
                                            type="button"
                                            className={primaryButton}
                                            onClick={() =>
                                                post(
                                                    `/accountant/reviews/${review.id}/start`,
                                                )
                                            }
                                        >
                                            Iniciar revisión
                                        </button>
                                    )}
                                {review.status === 'in_review' &&
                                    permissions.canApproveFirstLevel && (
                                        <button
                                            type="button"
                                            className={primaryButton}
                                            onClick={() => {
                                                setComment('');
                                                setApproving(true);
                                            }}
                                        >
                                            <CheckCircle2 className="size-4" />{' '}
                                            {file.workflow === 'double_level'
                                                ? 'Aprobar primer nivel'
                                                : 'Aprobar revisión'}
                                        </button>
                                    )}
                                {['in_review', 'first_level_approved'].includes(
                                    review.status,
                                ) &&
                                    permissions.canRequestChanges && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            onClick={() => setChanges(true)}
                                        >
                                            Solicitar cambios
                                        </button>
                                    )}
                            </div>
                        )
                    }
                >
                    {review === null ? (
                        <p className="text-sm text-[#526b92]">
                            El auxiliar todavía no ha enviado este período a
                            revisión.
                        </p>
                    ) : (
                        <dl className="grid gap-2 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-[#526b92]">
                                    Estado de la revisión
                                </dt>
                                <dd>
                                    <span
                                        className={toneBadge(
                                            statusTone[review.status] ??
                                                'slate',
                                        )}
                                    >
                                        {review.status_label}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#526b92]">
                                    Enviado por
                                </dt>
                                <dd>
                                    {review.submitted_by ?? '—'} ·{' '}
                                    {formatDate(review.submitted_at)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#526b92]">
                                    Primer nivel
                                </dt>
                                <dd>
                                    {review.first_level_by
                                        ? `${review.first_level_by} · ${formatDate(review.first_level_at)}`
                                        : 'Pendiente'}
                                </dd>
                            </div>
                            {file.workflow === 'double_level' && (
                                <div>
                                    <dt className="text-xs text-[#526b92]">
                                        Revisión superior
                                    </dt>
                                    <dd>
                                        {review.upper_reviewer ??
                                            'Contador general por asignar'}
                                    </dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-xs text-[#526b92]">
                                    Fiscal
                                </dt>
                                <dd>
                                    {review.fiscal_status === 'approved'
                                        ? 'Aprobado'
                                        : review.fiscal_status === 'pending'
                                          ? 'Pendiente'
                                          : review.fiscal_status ===
                                              'changes_requested'
                                            ? 'Con corrección'
                                            : '—'}
                                </dd>
                            </div>
                            {review.approval_comment && (
                                <div className="sm:col-span-3">
                                    <dt className="text-xs text-[#526b92]">
                                        Comentario
                                    </dt>
                                    <dd>{review.approval_comment}</dd>
                                </div>
                            )}
                        </dl>
                    )}
                </Card>

                <Card
                    title="Checklist de revisión"
                    action={
                        checklist && (
                            <span
                                className={toneBadge(
                                    checklist.ready_for_approval
                                        ? 'green'
                                        : 'red',
                                )}
                            >
                                {checklist.ready_for_approval
                                    ? 'Sin bloqueantes'
                                    : 'Con bloqueantes'}
                            </span>
                        )
                    }
                >
                    {file.checklist_error && (
                        <p className="text-sm text-red-700">
                            {file.checklist_error}
                        </p>
                    )}
                    {!checklist && !file.checklist_error && (
                        <p className="text-sm text-[#526b92]">
                            No hay un período abierto para revisar.
                        </p>
                    )}
                    {checklist && (
                        <>
                            <div className="mb-3 flex flex-wrap gap-1.5">
                                {Object.entries(checklist.summary).map(
                                    ([status, count]) => (
                                        <span
                                            key={status}
                                            className={toneBadge(
                                                checkTone[status] ?? 'slate',
                                            )}
                                        >
                                            {checkLabel[status]}: {count}
                                        </span>
                                    ),
                                )}
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                {groups.map((group) => (
                                    <div key={group}>
                                        <p className="mb-1 text-xs font-bold tracking-wide text-[#526b92] uppercase">
                                            {group}
                                        </p>
                                        <ul className="space-y-1.5 text-sm">
                                            {checklist.checks
                                                .filter(
                                                    (check) =>
                                                        check.group === group,
                                                )
                                                .map((check) => (
                                                    <li
                                                        key={check.key}
                                                        className="flex items-start gap-2 rounded-lg border border-slate-100 p-2"
                                                    >
                                                        {checkIcon(
                                                            check.status,
                                                        )}
                                                        <span className="min-w-0 flex-1">
                                                            <b>{check.label}</b>
                                                            <span className="block text-xs text-[#526b92]">
                                                                {check.detail}
                                                            </span>
                                                        </span>
                                                        {check.destination && (
                                                            <a
                                                                href={
                                                                    check.destination
                                                                }
                                                                className="shrink-0 text-xs font-bold text-[#640000] hover:underline"
                                                            >
                                                                Ver
                                                            </a>
                                                        )}
                                                    </li>
                                                ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </Card>

                <Card
                    title="Observaciones"
                    action={
                        permissions.canRequestChanges && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setObserving(null)}
                            >
                                <Plus className="size-3.5" /> Nueva observación
                            </button>
                        )
                    }
                >
                    <ObservationTable
                        observations={file.observations}
                        showCompany={false}
                    />
                </Card>

                <Card title="Obligaciones fiscales del período">
                    <Table
                        headers={[
                            'Obligación',
                            'Período',
                            'Vence',
                            'Preparó',
                            'Estado',
                            '',
                        ]}
                        empty={
                            file.fiscal_obligations.length === 0 &&
                            'Sin obligaciones fiscales generadas para el período.'
                        }
                    >
                        {file.fiscal_obligations.map((obligation) => (
                            <tr key={obligation.id}>
                                <td className="px-2 py-2 font-bold">
                                    {obligation.name}
                                    {obligation.stale && (
                                        <p className="text-[11px] text-red-700">
                                            Datos cambiados: recalcular
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.reference_period}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(obligation.due_on)}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.assignee_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            ['approved', 'completed'].includes(
                                                obligation.status,
                                            )
                                                ? 'green'
                                                : obligation.status ===
                                                    'ready_for_review'
                                                  ? 'violet'
                                                  : 'amber',
                                        )}
                                    >
                                        {obligation.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={obligation.destination}
                                        className={secondaryButton}
                                    >
                                        {obligation.status ===
                                        'ready_for_review'
                                            ? 'Revisar'
                                            : 'Ver'}
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                <Card title="Pólizas pendientes del período">
                    <EntriesTable
                        entries={file.entries}
                        permissions={permissions}
                        userId={userId}
                        showCompany={false}
                        onObserve={
                            permissions.canRequestChanges
                                ? (entry) =>
                                      setObserving({
                                          related_type: 'journal_entries',
                                          related_id: entry.id,
                                          related_label:
                                              `${formatDate(entry.date)} ${entry.description}`.slice(
                                                  0,
                                                  180,
                                              ),
                                      })
                                : undefined
                        }
                    />
                </Card>
            </Shell>

            <NewObservationDialog
                open={observing !== undefined}
                onClose={() => setObserving(undefined)}
                companyId={company.id}
                periodId={company.period_id}
                reviewId={review?.id ?? null}
                assignees={file.assignees}
                relatedTypes={relatedTypes}
                severities={severities}
                preset={observing ?? null}
            />

            <ObservationDialog
                open={changes}
                title="Solicitar cambios"
                onClose={() => setChanges(false)}
                onSubmit={(message) =>
                    review &&
                    post(
                        `/accountant/reviews/${review.id}/changes`,
                        { message },
                        () => setChanges(false),
                    )
                }
            />

            <Dialog open={approving} onOpenChange={setApproving}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {file.workflow === 'double_level'
                                ? 'Aprobar primer nivel'
                                : 'Aprobar revisión'}
                        </DialogTitle>
                        <DialogDescription>
                            {company.name} · {company.period_name}. Se registran
                            tu usuario, la fecha, la hora y el comentario.
                            {file.workflow === 'double_level'
                                ? ' Después la revisa el contador general.'
                                : ' Después pasa a revisión fiscal.'}
                        </DialogDescription>
                    </DialogHeader>
                    {blockingObservations > 0 && (
                        <p className="rounded-lg bg-red-50 p-2 text-sm text-red-700">
                            Hay {blockingObservations} observación(es) altas o
                            críticas sin resolver; la aprobación será rechazada.
                        </p>
                    )}
                    {checklist && !checklist.ready_for_approval && (
                        <p className="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">
                            El checklist tiene bloqueantes. Puedes aprobar la
                            revisión, pero el cierre no procederá hasta
                            resolverlos.
                        </p>
                    )}
                    <textarea
                        aria-label="Comentario"
                        className={`${input} h-24 py-2`}
                        placeholder="Comentario (opcional)"
                        value={comment}
                        onChange={(event) => setComment(event.target.value)}
                    />
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setApproving(false)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            onClick={() =>
                                review &&
                                post(
                                    `/accountant/reviews/${review.id}/approve`,
                                    { comment },
                                    () => setApproving(false),
                                )
                            }
                        >
                            Aprobar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
