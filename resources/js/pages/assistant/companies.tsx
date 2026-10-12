import { Head, Link } from '@inertiajs/react';
import {
    ProgressValue,
    StageIcon,
    StageLegend,
    useAssistantHref,
} from '@/components/assistant/kit';
import type {
    AssistantCompany,
    AssistantContext,
    AssistantPermissions,
} from '@/components/assistant/kit';
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
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';

export default function AssistantCompanies({
    context,
    companies,
    permissions,
    notice,
}: {
    context: AssistantContext;
    companies: AssistantCompany[];
    permissions: AssistantPermissions;
    notice: string | null;
}) {
    const href = useAssistantHref();

    return (
        <>
            <Head title="Mis empresas" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Mis empresas"
                    subtitle={`Empresas que te asignaron como auxiliar · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card
                    title={`${companies.length} empresa(s)`}
                    action={<StageLegend />}
                >
                    <Table
                        headers={[
                            'Empresa',
                            'Contador encargado',
                            'Período',
                            'Etapas',
                            'Avance',
                            'Pendientes',
                            'Estado',
                            'Vencimiento',
                            'Siguiente paso',
                        ]}
                        empty={
                            companies.length === 0 &&
                            'No tienes empresas asignadas.'
                        }
                    >
                        {companies.map((company) => (
                            <tr key={company.id} className="align-top">
                                <td className="px-2 py-2">
                                    <a
                                        href={company.destination}
                                        className="font-bold hover:text-[#640000]"
                                    >
                                        {company.name}
                                    </a>
                                    <p className="text-[11px] text-[#526b92]">
                                        NIT {company.tax_id}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {company.accountant?.name ?? (
                                        <span className="text-amber-700">
                                            Sin asignar
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {company.period_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex gap-1">
                                        {company.stages.map((stage) => (
                                            <StageIcon
                                                key={stage.key}
                                                stage={stage}
                                            />
                                        ))}
                                    </div>
                                </td>
                                <td className="px-2 py-2">
                                    <ProgressValue value={company.progress} />
                                </td>
                                <td className="px-2 py-2">
                                    {company.pending_items}
                                    {company.observations > 0 && (
                                        <span className="block text-[11px] text-violet-700">
                                            {company.observations}{' '}
                                            observación(es)
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            company.blocked
                                                ? 'red'
                                                : (statusTone[company.status] ??
                                                      'slate'),
                                        )}
                                    >
                                        {company.blocked
                                            ? 'Bloqueada'
                                            : company.status_label}
                                    </span>
                                </td>
                                <td
                                    className={`px-2 py-2 ${company.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(company.due_at)}
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        <a
                                            href={company.destination}
                                            className={secondaryButton}
                                        >
                                            Abrir empresa
                                        </a>
                                        {company.can_submit &&
                                            permissions.canSubmitReview && (
                                                <Link
                                                    href={href(
                                                        `/assistant/companies/${company.id}/submit`,
                                                    )}
                                                    className={secondaryButton}
                                                >
                                                    Enviar a revisión
                                                </Link>
                                            )}
                                    </div>
                                    <p className="mt-1 text-[11px] text-[#526b92]">
                                        {company.next_action}
                                    </p>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
