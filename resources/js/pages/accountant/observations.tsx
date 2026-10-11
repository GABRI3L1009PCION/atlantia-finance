import { Head } from '@inertiajs/react';
import { useFilterNavigation } from '@/components/accountant/kit';
import type {
    AccountantContext,
    Observation,
} from '@/components/accountant/kit';
import { ObservationTable } from '@/components/accountant/observations';
import { Notice, PageHeader, Shell } from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function AccountantObservations({
    context,
    observations,
    filters,
    focus,
    statuses,
    notice,
}: {
    context: AccountantContext;
    observations: Observation[];
    filters: Record<string, string>;
    focus: number | null;
    statuses: Record<string, string>;
    notice: string | null;
}) {
    const apply = useFilterNavigation('/accountant/observations');

    return (
        <>
            <Head title="Observaciones" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Observaciones"
                    subtitle="Hallazgos de revisión por registro: abierta → en corrección → resuelta, o reabierta si la corrección no basta"
                />
                <Card
                    title={`${observations.length} observación(es)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Empresa"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    apply('company_id', event.target.value)
                                }
                            >
                                <option value="">Todas las empresas</option>
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Estado"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.status ?? 'active'}
                                onChange={(event) =>
                                    apply('status', event.target.value)
                                }
                            >
                                <option value="active">Pendientes</option>
                                {Object.entries(statuses).map(
                                    ([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    }
                >
                    <ObservationTable
                        observations={observations}
                        focus={focus}
                    />
                </Card>
            </Shell>
        </>
    );
}
