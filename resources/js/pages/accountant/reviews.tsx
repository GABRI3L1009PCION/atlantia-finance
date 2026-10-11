import { Head, Link } from '@inertiajs/react';
import { useAccountantHref } from '@/components/accountant/kit';
import type {
    AccountantContext,
    AccountantPermissions,
} from '@/components/accountant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    Table,
    post,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';

type Review = {
    id: number;
    company_id: number;
    company_name: string;
    period_name: string;
    submitted_by: string | null;
    reviewer_name: string | null;
    status: string;
    status_label: string;
    submitted_at: string | null;
    waiting_hours: number | null;
    observations_count: number;
    last_observation: string | null;
    is_mine: boolean;
    review_href: string;
};

export default function AccountantReviews({
    reviews,
    permissions,
    notice,
}: {
    context: AccountantContext;
    reviews: Review[];
    permissions: AccountantPermissions;
    notice: string | null;
}) {
    const href = useAccountantHref();

    return (
        <>
            <Head title="Revisión de empresas" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Revisión de empresas"
                    subtitle="Períodos enviados por los auxiliares: revisa, observa y aprueba el primer nivel"
                />
                <Card title={`${reviews.length} revisión(es)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Período',
                            'Envió',
                            'Enviado',
                            'Esperando',
                            'Observaciones',
                            'Estado',
                            'Acciones',
                        ]}
                        empty={
                            reviews.length === 0 &&
                            'No hay períodos esperando revisión.'
                        }
                    >
                        {reviews.map((review) => (
                            <tr key={review.id} className="align-top">
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(review.review_href)}
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
                                <td
                                    className={`px-2 py-2 ${(review.waiting_hours ?? 0) > 48 ? 'font-bold text-red-700' : ''}`}
                                >
                                    {review.waiting_hours === null
                                        ? '—'
                                        : `${review.waiting_hours} h`}
                                </td>
                                <td className="px-2 py-2">
                                    {review.observations_count}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            statusTone[review.status] ??
                                                'slate',
                                        )}
                                    >
                                        {review.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {review.status === 'ready_for_review' &&
                                            permissions.canStartReview && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        post(
                                                            `/accountant/reviews/${review.id}/start`,
                                                        )
                                                    }
                                                >
                                                    Iniciar revisión
                                                </button>
                                            )}
                                        <Link
                                            href={href(review.review_href)}
                                            className={secondaryButton}
                                        >
                                            Abrir revisión
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
