import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Notice, PageHeader, Shell } from '@/components/office-admin/kit';
import {
    Card,
    ProgressBar,
    Table,
    statusTone,
    toneBadge,
    useSupervisorHref,
} from '@/components/supervisor/kit';
import type {
    SupervisedCompany,
    SupervisorContext,
} from '@/components/supervisor/kit';

export default function SupervisorCompanies({
    context,
    companies,
    filters,
    notice,
}: {
    context: SupervisorContext;
    companies: SupervisedCompany[];
    filters: Record<string, string>;
    notice: string | null;
}) {
    const page = usePage();
    const href = useSupervisorHref();
    const view = new URL(page.url, 'https://atlantia.local').searchParams.get(
        'view',
    );
    const [search, setSearch] = useState('');
    const rows = useMemo(
        () =>
            companies.filter((company) =>
                `${company.name} ${company.tax_id}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
            ),
        [companies, search],
    );
    const apply = (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            '/supervisor/companies',
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, v]) => v !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };
    const title =
        view === 'status'
            ? 'Estado de empresas'
            : view === 'files'
              ? 'Expediente contable'
              : 'Empresas asignadas';

    return (
        <>
            <Head title={title} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={title}
                    subtitle={`Solo las empresas que tienes asignadas · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card
                    title={`${rows.length} empresa(s)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <input
                                className="h-9 rounded-lg border border-slate-200 px-3 text-sm"
                                placeholder="Buscar empresa o NIT"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <select
                                aria-label="Responsable"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.responsible_id ?? ''}
                                onChange={(event) =>
                                    apply('responsible_id', event.target.value)
                                }
                            >
                                <option value="">Todos los responsables</option>
                                {context.team.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Estado"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    apply('status', event.target.value)
                                }
                            >
                                <option value="">Todos los estados</option>
                                <option value="not_started">Pendiente</option>
                                <option value="in_progress">En proceso</option>
                                <option value="ready_for_review">
                                    En revisión
                                </option>
                                <option value="changes_requested">
                                    Corrección requerida
                                </option>
                                <option value="fiscal_pending">
                                    Fiscal pendiente
                                </option>
                                <option value="ready_to_close">
                                    Lista para cierre
                                </option>
                                <option value="closed">Cerrado</option>
                                <option value="blocked">Bloqueado</option>
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Empresa',
                            'Oficina',
                            'Responsable',
                            'Auxiliar',
                            'Período',
                            'Contabilidad',
                            'Fiscal',
                            'Documentos',
                            'Progreso',
                            'Límite',
                            'Estado',
                        ]}
                        empty={
                            rows.length === 0 && 'No tienes empresas asignadas.'
                        }
                    >
                        {rows.map((company) => (
                            <tr key={company.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(company.expediente)}
                                        className="font-bold hover:text-[#640000]"
                                    >
                                        {company.name}
                                    </Link>
                                    <p className="text-[11px] text-[#526b92]">
                                        NIT {company.tax_id}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {company.office_name}
                                </td>
                                <td className="px-2 py-2">
                                    {company.accountant?.name ?? (
                                        <span className="text-red-700">
                                            Sin asignar
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {company.assistant?.name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {company.period_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <ProgressBar
                                        value={company.accounting_progress}
                                    />
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            company.fiscal_tone,
                                        )}
                                    >
                                        {company.fiscal_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {company.documents_done} /{' '}
                                    {company.documents_total}
                                </td>
                                <td className="px-2 py-2">
                                    <ProgressBar value={company.progress} />
                                </td>
                                <td
                                    className={`px-2 py-2 ${company.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {company.due_at
                                        ? new Date(
                                              company.due_at,
                                          ).toLocaleDateString('es-GT')
                                        : '—'}
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
                                            ? 'Bloqueado'
                                            : company.status_label}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
