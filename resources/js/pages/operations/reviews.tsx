import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    EmptyRow,
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    secondaryButton,
} from '@/components/office-admin/kit';
import { companyStatusTone } from '@/components/operations/kit';
import type { Context, TeamUser } from '@/components/operations/kit';

type Review = {
    id: number;
    office_id: number;
    company_name: string;
    period_name: string;
    submitted_by: string | null;
    reviewer_id: number | null;
    reviewer_name: string | null;
    status: string;
    status_label: string;
    submitted_at: string | null;
    waiting_hours: number | null;
    observations_count: number;
    last_observation: string | null;
    destination: string;
};

const queues = [
    { key: 'ready_for_review', label: 'Esperando revisión' },
    { key: 'in_review', label: 'En revisión' },
    { key: 'changes_requested', label: 'Cambios solicitados' },
    { key: 'approved', label: 'Aprobadas' },
] as const;

function waiting(hours: number | null): string {
    if (hours === null) {
        return '—';
    }

    return hours < 24 ? `${hours} h` : `${Math.floor(hours / 24)} día(s)`;
}

export default function OperationsReviews({
    reviews,
    reviewers,
    permissions,
    notice,
}: {
    context: Context;
    reviews: Review[];
    reviewers: TeamUser[];
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const [queue, setQueue] =
        useState<(typeof queues)[number]['key']>('ready_for_review');
    const [choice, setChoice] = useState<Record<number, string>>({});
    const rows = reviews.filter((review) => review.status === queue);

    return (
        <>
            <Head title="Revisiones" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Revisiones"
                    subtitle="Cola de revisión de los períodos enviados por el equipo"
                />
                <div className="flex flex-wrap gap-1.5">
                    {queues.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setQueue(item.key)}
                            className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${queue === item.key ? 'bg-[#640000] text-white ring-[#640000]' : 'bg-white text-[#395277] ring-slate-200'}`}
                        >
                            {item.label} (
                            {
                                reviews.filter(
                                    (review) => review.status === item.key,
                                ).length
                            }
                            )
                        </button>
                    ))}
                </div>
                <section className={`${panel} p-3`}>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Período</th>
                                    <th className="px-2 py-2">Preparado por</th>
                                    <th className="px-2 py-2">Revisor</th>
                                    <th className="px-2 py-2">Enviado</th>
                                    <th className="px-2 py-2">Esperando</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="px-2 py-2 text-right">
                                        Observaciones
                                    </th>
                                    <th className="px-2 py-2">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((review) => (
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
                                                    <p className="text-[11px] font-normal text-amber-800">
                                                        “
                                                        {
                                                            review.last_observation
                                                        }
                                                        ”
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
                                            {review.reviewer_name ?? (
                                                <span className="font-bold text-amber-700">
                                                    Sin revisor
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(review.submitted_at)}
                                        </td>
                                        <td
                                            className={`px-2 py-2 font-black ${(review.waiting_hours ?? 0) > 48 ? 'text-red-700' : ''}`}
                                        >
                                            {waiting(review.waiting_hours)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={badge(
                                                    companyStatusTone[
                                                        review.status
                                                    ] ?? 'slate',
                                                )}
                                            >
                                                {review.status_label}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2 text-right font-black">
                                            {review.observations_count}
                                        </td>
                                        <td className="px-2 py-2">
                                            {permissions.canReassignReviews &&
                                                review.status !==
                                                    'approved' && (
                                                    <div className="flex gap-1.5">
                                                        <select
                                                            aria-label="Revisor"
                                                            className="h-9 rounded-lg border border-slate-300 px-2 text-xs"
                                                            value={
                                                                choice[
                                                                    review.id
                                                                ] ?? ''
                                                            }
                                                            onChange={(event) =>
                                                                setChoice({
                                                                    ...choice,
                                                                    [review.id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                        >
                                                            <option value="">
                                                                Elegir revisor
                                                            </option>
                                                            {reviewers
                                                                .filter(
                                                                    (user) =>
                                                                        user.office_ids.includes(
                                                                            review.office_id,
                                                                        ) &&
                                                                        user.id !==
                                                                            review.reviewer_id,
                                                                )
                                                                .map((user) => (
                                                                    <option
                                                                        key={
                                                                            user.id
                                                                        }
                                                                        value={
                                                                            user.id
                                                                        }
                                                                    >
                                                                        {
                                                                            user.name
                                                                        }
                                                                    </option>
                                                                ))}
                                                        </select>
                                                        <button
                                                            type="button"
                                                            className={
                                                                secondaryButton
                                                            }
                                                            disabled={
                                                                !choice[
                                                                    review.id
                                                                ]
                                                            }
                                                            onClick={() =>
                                                                router.post(
                                                                    `/operations/reviews/${review.id}/reassign`,
                                                                    {
                                                                        reviewer_id:
                                                                            choice[
                                                                                review
                                                                                    .id
                                                                            ],
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Reasignar
                                                        </button>
                                                    </div>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <EmptyRow
                                        colSpan={9}
                                        text="No hay revisiones en esta cola."
                                    />
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </Shell>
        </>
    );
}
