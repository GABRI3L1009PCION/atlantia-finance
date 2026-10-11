import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import { obligationTone, useTaxHref } from '@/components/tax/kit';
import type { Obligation, TaxContext } from '@/components/tax/kit';

export default function TaxObligations({
    context,
    obligations,
    filters,
    statuses,
    notice,
}: {
    context: TaxContext;
    obligations: Obligation[];
    filters: Record<string, string>;
    statuses: Record<string, string>;
    notice: string | null;
}) {
    const page = usePage();
    const href = useTaxHref();
    const apply = (key: string, value: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            '/tax/obligations',
            Object.fromEntries(
                Object.entries({ ...params, [key]: value }).filter(
                    ([, current]) => current !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Obligaciones" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Obligaciones fiscales"
                    subtitle="Generadas desde la configuración fiscal de cada empresa, con vencimiento oficial, límite interno y límite de revisión"
                />
                <Card
                    title={`${obligations.length} obligación(es)`}
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
                                value={filters.status ?? 'open'}
                                onChange={(event) =>
                                    apply('status', event.target.value)
                                }
                            >
                                <option value="open">Abiertas</option>
                                <option value="all">Todas</option>
                                {Object.entries(statuses).map(
                                    ([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    apply('overdue', filters.overdue ? '' : '1')
                                }
                            >
                                {filters.overdue ? 'Todas' : 'Solo vencidas'}
                            </button>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Obligación',
                            'Empresa',
                            'Período',
                            'Límite interno',
                            'Revisión',
                            'Vence (oficial)',
                            'Responsable',
                            'Revisor',
                            'Estado',
                        ]}
                        empty={
                            obligations.length === 0 &&
                            'No existen obligaciones pendientes.'
                        }
                    >
                        {obligations.map((obligation) => (
                            <tr key={obligation.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(obligation.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {obligation.name}
                                    </Link>
                                    {obligation.stale && (
                                        <p className="flex items-center gap-1 text-[11px] font-semibold text-red-700">
                                            <AlertTriangle className="size-3" />{' '}
                                            Datos cambiados: recalcular
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.company_name}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.reference_period}
                                </td>
                                <td
                                    className={`px-2 py-2 ${obligation.internal_overdue ? 'font-bold text-amber-700' : ''}`}
                                >
                                    {formatDate(obligation.internal_due_on)}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(obligation.review_due_on)}
                                </td>
                                <td
                                    className={`px-2 py-2 ${obligation.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(obligation.due_on)}
                                    {obligation.status !== 'completed' && (
                                        <span className="block text-[11px]">
                                            {obligation.days_left < 0
                                                ? `vencida hace ${Math.abs(obligation.days_left)} d`
                                                : obligation.days_left === 0
                                                  ? 'vence hoy'
                                                  : `en ${obligation.days_left} d`}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.assignee_name ?? (
                                        <span className="text-amber-700">
                                            Sin asignar
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {obligation.reviewer_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            obligation.overdue
                                                ? 'red'
                                                : (obligationTone[
                                                      obligation.status
                                                  ] ?? 'slate'),
                                        )}
                                    >
                                        {obligation.overdue
                                            ? 'Vencida · ' +
                                              obligation.status_label
                                            : obligation.status_label}
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
