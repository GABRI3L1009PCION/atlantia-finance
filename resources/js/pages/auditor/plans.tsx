import { Head, Link } from '@inertiajs/react';
import { useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import { PageHeader, Shell, formatDate } from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Plan = {
    id: number;
    engagement_id: number;
    engagement: string;
    title: string;
    company: string;
    version: number;
    status: string;
    objective: string;
    approved_at: string | null;
    updated_at: string;
};

const labels: Record<string, [string, string]> = {
    draft: ['Borrador', 'slate'],
    in_review: ['En revisión', 'amber'],
    approved: ['Aprobado', 'green'],
    superseded: ['Reemplazado', 'slate'],
};

export default function AuditorPlans({
    plans,
}: {
    context: AuditorContext;
    plans: Plan[];
}) {
    const href = useAuditorHref();

    return (
        <>
            <Head title="Planes de auditoría" />
            <Shell>
                <PageHeader
                    title="Planes de auditoría"
                    subtitle="Versiones de los planes; un plan aprobado no se edita, se reemplaza con una nueva versión"
                />
                <Card title={`${plans.length} plan(es)`}>
                    <Table
                        headers={[
                            'Auditoría',
                            'Empresa',
                            'Versión',
                            'Objetivo',
                            'Estado',
                            'Aprobado',
                            'Actualizado',
                        ]}
                        empty={
                            plans.length === 0 &&
                            'Aún no hay planes de auditoría.'
                        }
                    >
                        {plans.map((plan) => {
                            const [label, tone] = labels[plan.status] ?? [
                                plan.status,
                                'slate',
                            ];

                            return (
                                <tr key={plan.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(
                                                `/work/audit/engagements/${plan.engagement_id}?tab=planning`,
                                            )}
                                            className="text-blue-700 hover:underline"
                                        >
                                            {plan.engagement}
                                        </Link>
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {plan.title}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {plan.company}
                                    </td>
                                    <td className="px-2 py-2">
                                        v{plan.version}
                                    </td>
                                    <td className="max-w-md px-2 py-2">
                                        {plan.objective}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(tone)}>
                                            {label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(plan.approved_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(plan.updated_at)}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
