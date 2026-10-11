import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Lock, XCircle } from 'lucide-react';
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
import {
    Card,
    ObservationDialog,
    Table,
    post,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';
import type {
    SupervisedCompany,
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Review = {
    id: number;
    company_id: number;
    company_name: string;
    period_name: string;
    submitted_by: string | null;
    reviewer_name: string | null;
    status: string;
    status_label: string;
    fiscal_status: string | null;
    submitted_at: string | null;
    waiting_hours: number | null;
    observations_count: number;
    last_observation: string | null;
    destination: string;
};
type Closable = SupervisedCompany & {
    close: {
        checks: { label: string; ok: boolean; detail: string }[];
        can_close: boolean;
        company: string;
        period: string;
    };
};

export default function SupervisorReviews({
    reviews,
    closable,
    permissions,
    notice,
}: {
    context: SupervisorContext;
    reviews: Review[];
    closable: Closable[];
    permissions: SupervisorPermissions;
    notice: string | null;
}) {
    const [correction, setCorrection] = useState<{
        review: Review;
        fiscal: boolean;
    } | null>(null);
    const [approving, setApproving] = useState<{
        review: Review;
        fiscal: boolean;
    } | null>(null);
    const [comment, setComment] = useState('');
    const [closing, setClosing] = useState<Closable | null>(null);
    const queue = reviews.filter(
        (review) =>
            ['ready_for_review', 'in_review', 'changes_requested'].includes(
                review.status,
            ) ||
            (review.status === 'approved' &&
                review.fiscal_status !== 'approved'),
    );

    return (
        <>
            <Head title="Revisión y aprobación" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Revisión y aprobación"
                    subtitle="Revisa el trabajo enviado, solicita correcciones, aprueba contabilidad y fiscal, y cierra períodos"
                />
                <Card title="Pendientes de revisión">
                    <Table
                        headers={[
                            'Empresa',
                            'Período',
                            'Enviado por',
                            'Enviado',
                            'Etapa',
                            'Observaciones',
                            'Acciones',
                        ]}
                        empty={
                            queue.length === 0 &&
                            'No hay trabajo esperando tu revisión.'
                        }
                    >
                        {queue.map((review) => {
                            const fiscalStep = review.status === 'approved';

                            return (
                                <tr key={review.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={review.destination}
                                            className="hover:text-[#640000]"
                                        >
                                            {review.company_name}
                                        </Link>
                                        {review.last_observation &&
                                            review.status ===
                                                'changes_requested' && (
                                                <p className="text-[11px] font-normal text-violet-700">
                                                    “{review.last_observation}”
                                                </p>
                                            )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {review.period_name}
                                    </td>
                                    <td className="px-2 py-2">
                                        {review.submitted_by ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(review.submitted_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                fiscalStep
                                                    ? 'amber'
                                                    : (statusTone[
                                                          review.status
                                                      ] ?? 'slate'),
                                            )}
                                        >
                                            {fiscalStep
                                                ? 'Fiscal pendiente'
                                                : review.status ===
                                                    'changes_requested'
                                                  ? 'Corrección requerida'
                                                  : review.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {review.observations_count}
                                    </td>
                                    <td className="px-2 py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {review.status ===
                                                'ready_for_review' && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        post(
                                                            `/supervisor/reviews/${review.id}/start`,
                                                        )
                                                    }
                                                >
                                                    Iniciar revisión
                                                </button>
                                            )}
                                            {review.status === 'in_review' &&
                                                permissions.canApprove && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() => {
                                                                setComment('');
                                                                setApproving({
                                                                    review,
                                                                    fiscal: false,
                                                                });
                                                            }}
                                                        >
                                                            <CheckCircle2 className="size-3.5 text-emerald-600" />{' '}
                                                            Aprobar
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() =>
                                                                setCorrection({
                                                                    review,
                                                                    fiscal: false,
                                                                })
                                                            }
                                                        >
                                                            <XCircle className="size-3.5 text-violet-600" />{' '}
                                                            Solicitar corrección
                                                        </button>
                                                    </>
                                                )}
                                            {fiscalStep &&
                                                permissions.canApproveFiscal && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() => {
                                                                setComment('');
                                                                setApproving({
                                                                    review,
                                                                    fiscal: true,
                                                                });
                                                            }}
                                                        >
                                                            <CheckCircle2 className="size-3.5 text-emerald-600" />{' '}
                                                            Aprobación fiscal
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            onClick={() =>
                                                                setCorrection({
                                                                    review,
                                                                    fiscal: true,
                                                                })
                                                            }
                                                        >
                                                            <XCircle className="size-3.5 text-violet-600" />{' '}
                                                            Corrección fiscal
                                                        </button>
                                                    </>
                                                )}
                                            {review.status ===
                                                'changes_requested' && (
                                                <span className="text-xs text-[#526b92]">
                                                    Esperando que el responsable
                                                    corrija y reenvíe.
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>

                <Card title="Cierre de períodos">
                    <Table
                        headers={[
                            'Empresa',
                            'Período',
                            'Estado',
                            'Checklist',
                            'Acción',
                        ]}
                        empty={
                            closable.length === 0 &&
                            'No hay períodos abiertos en tus empresas.'
                        }
                    >
                        {closable.map((company) => (
                            <tr key={company.id} className="align-top">
                                <td className="px-2 py-2 font-bold">
                                    {company.name}
                                </td>
                                <td className="px-2 py-2">
                                    {company.period_name}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            statusTone[company.status] ??
                                                'slate',
                                        )}
                                    >
                                        {company.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {
                                        company.close.checks.filter(
                                            (check) => check.ok,
                                        ).length
                                    }{' '}
                                    de {company.close.checks.length} requisitos
                                </td>
                                <td className="px-2 py-2">
                                    <button
                                        type="button"
                                        className={
                                            company.close.can_close
                                                ? primaryButton
                                                : secondaryButton
                                        }
                                        onClick={() => setClosing(company)}
                                    >
                                        <Lock className="size-3.5" />{' '}
                                        {company.close.can_close
                                            ? 'Cerrar período'
                                            : 'Ver qué falta'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>

            <ObservationDialog
                open={correction !== null}
                title={
                    correction?.fiscal
                        ? 'Solicitar corrección fiscal'
                        : 'Solicitar corrección'
                }
                onClose={() => setCorrection(null)}
                onSubmit={(message) =>
                    correction &&
                    post(
                        `/supervisor/reviews/${correction.review.id}/${correction.fiscal ? 'fiscal-changes' : 'changes'}`,
                        { message },
                        () => setCorrection(null),
                    )
                }
            />

            <Dialog
                open={approving !== null}
                onOpenChange={(value) => !value && setApproving(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {approving?.fiscal
                                ? 'Aprobación fiscal'
                                : 'Aprobar contabilidad'}
                        </DialogTitle>
                        <DialogDescription>
                            {approving?.review.company_name} ·{' '}
                            {approving?.review.period_name}. Se registran tu
                            usuario, la fecha, la hora y el comentario.
                            {approving?.fiscal
                                ? ' Revisa IVA, ISR, FEL y retenciones antes de aprobar.'
                                : ' Después pasa a revisión fiscal.'}
                        </DialogDescription>
                    </DialogHeader>
                    <textarea
                        className={`${input} h-24 py-2`}
                        placeholder="Comentario (opcional)"
                        value={comment}
                        onChange={(event) => setComment(event.target.value)}
                    />
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setApproving(null)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            onClick={() =>
                                approving &&
                                post(
                                    `/supervisor/reviews/${approving.review.id}/${approving.fiscal ? 'fiscal-approve' : 'approve'}`,
                                    { comment },
                                    () => setApproving(null),
                                )
                            }
                        >
                            Aprobar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={closing !== null}
                onOpenChange={(value) => !value && setClosing(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Cerrar período</DialogTitle>
                        <DialogDescription>
                            Está por cerrar el período {closing?.close.period}{' '}
                            de {closing?.close.company}. Después de cerrar,
                            cualquier modificación requerirá reapertura
                            autorizada.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="space-y-1.5 text-sm">
                        {closing?.close.checks.map((check) => (
                            <li
                                key={check.label}
                                className="flex items-start gap-2"
                            >
                                {check.ok ? (
                                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                ) : (
                                    <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                                )}
                                <span>
                                    <b>{check.label}.</b>{' '}
                                    <span className="text-[#526b92]">
                                        {check.detail}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setClosing(null)}
                        >
                            Cancelar
                        </button>
                        {permissions.canClose && (
                            <button
                                type="button"
                                className={primaryButton}
                                disabled={!closing?.close.can_close}
                                onClick={() =>
                                    closing &&
                                    post(
                                        `/supervisor/companies/${closing.id}/periods/${closing.period_id}/close`,
                                        {},
                                        () => setClosing(null),
                                    )
                                }
                            >
                                Cerrar período
                            </button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
