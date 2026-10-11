import { Head, router } from '@inertiajs/react';
import { EvidenceTable } from '@/components/auditor/engagement-sections';
import { useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext, EvidenceRequest } from '@/components/auditor/kit';
import {
    Notice,
    PageHeader,
    Shell,
    input,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function AuditorEvidence({
    requests,
    status,
    focus,
    statuses,
    canReview,
    notice,
}: {
    context: AuditorContext;
    requests: EvidenceRequest[];
    status: string;
    focus: number | null;
    statuses: Record<string, string>;
    canReview: boolean;
    notice: string | null;
}) {
    const href = useAuditorHref();

    return (
        <>
            <Head title="Evidencias" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Evidencias"
                    subtitle="Solicitudes de evidencia: el responsable entrega o vincula el documento real y la versión aceptada queda congelada"
                />
                <Card
                    title={`${requests.length} solicitud(es)`}
                    action={
                        <select
                            aria-label="Estado"
                            className={`${input} h-8 w-52 text-xs`}
                            value={status}
                            onChange={(event) =>
                                router.get(
                                    href('/work/audit/evidence'),
                                    { status: event.target.value },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <option value="pending">Pendientes</option>
                            <option value="overdue">Vencidas</option>
                            <option value="all">Todas</option>
                            {Object.entries(statuses).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    }
                >
                    <EvidenceTable
                        rows={requests}
                        canReview={canReview}
                        focus={focus}
                    />
                </Card>
            </Shell>
        </>
    );
}
