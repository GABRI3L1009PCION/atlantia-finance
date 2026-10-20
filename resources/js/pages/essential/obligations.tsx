import { Head, Link, router } from '@inertiajs/react';
import { CalendarSync } from 'lucide-react';
import { useState } from 'react';
import {
    Pager,
    PrimaryActionButton,
    ProgressBar,
    Stat,
    obligationTone,
    priorityTone,
    selectClass,
    useServerFilters,
} from '@/components/essential/kit';
import type {
    EssentialContext,
    Paged,
    PrimaryAction,
} from '@/components/essential/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Obligation = {
    id: number;
    name: string;
    period: string;
    company: string;
    due_on: string;
    status: string;
    status_label: string;
    overdue: boolean;
    days: number;
    days_overdue: number;
    progress: number;
    priority: { key: string; label: string; reason: string | null };
    preparer: string | null;
    reviewer: string | null;
    filing_reference: string | null;
    filed_on: string | null;
    last_observation: string | null;
    stale: boolean;
    primary_action: PrimaryAction;
    href: string;
};

type Props = {
    essential: EssentialContext;
    obligations: {
        rows: Obligation[];
        summary: {
            open: number;
            overdue: number;
            week: number;
            review: number;
            filed: number;
        };
    } & Paged;
    companies: { id: number; name: string }[];
    filters: Record<string, string>;
};

export default function EssentialObligations({
    essential,
    obligations,
    companies,
    filters,
}: Props) {
    const apply = useServerFilters('/essential/obligations');
    const [busy, setBusy] = useState(false);
    const s = obligations.summary;

    return (
        <>
            <Head title="Obligaciones fiscales" />
            <Shell>
                <PageHeader
                    title="Obligaciones fiscales"
                    subtitle="Cada obligación se prepara en su espacio de trabajo, se revisa, se aprueba, se presenta en la SAT y guarda su constancia"
                    actions={
                        essential.owner && (
                            <button
                                type="button"
                                className={secondaryButton}
                                disabled={busy}
                                onClick={() => {
                                    setBusy(true);
                                    router.post(
                                        '/essential/obligations/refresh',
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setBusy(false),
                                        },
                                    );
                                }}
                            >
                                <CalendarSync className="size-4" /> Actualizar
                                calendario
                            </button>
                        )
                    }
                />
                {essential.solo && (
                    <p className="rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                        Eres la única persona de la oficina: cuando termines de
                        preparar una obligación podrás finalizarla sin enviarte
                        una revisión. Queda auditado con la política que lo
                        permite.
                    </p>
                )}

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Stat
                        label="Pendientes"
                        value={s.open}
                        href="/essential/obligations"
                    />
                    <Stat
                        label="Vencidas"
                        value={s.overdue}
                        tone={s.overdue > 0 ? 'red' : 'slate'}
                        href="/essential/obligations?status=overdue"
                    />
                    <Stat
                        label="Vencen esta semana"
                        value={s.week}
                        tone={s.week > 0 ? 'amber' : 'slate'}
                    />
                    <Stat
                        label="En revisión / aprobadas"
                        value={s.review}
                        tone="blue"
                    />
                    <Stat
                        label="Presentadas"
                        value={s.filed}
                        tone="green"
                        href="/essential/obligations?status=completed"
                    />
                </section>

                <Card
                    title={`${obligations.total} obligación(es)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Estado"
                                className={selectClass}
                                value={filters.status ?? 'open'}
                                onChange={(event) =>
                                    apply({ status: event.target.value })
                                }
                            >
                                <option value="open">Pendientes</option>
                                <option value="overdue">Vencidas</option>
                                <option value="pending">Sin iniciar</option>
                                <option value="in_progress">En proceso</option>
                                <option value="ready_for_review">
                                    En revisión
                                </option>
                                <option value="changes_requested">
                                    Con cambios
                                </option>
                                <option value="approved">Aprobadas</option>
                                <option value="completed">Presentadas</option>
                                <option value="all">Todas</option>
                            </select>
                            <select
                                aria-label="Cliente"
                                className={selectClass}
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    apply({
                                        company_id: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Todos los clientes</option>
                                {companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Obligación',
                            'Cliente',
                            'Vence',
                            'Estado',
                            'Progreso',
                            'Prioridad',
                            'Prepara',
                            'Revisa',
                            'Acción',
                        ]}
                        empty={
                            obligations.rows.length === 0 &&
                            'No hay obligaciones con estos filtros.'
                        }
                    >
                        {obligations.rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={row.href}
                                        className="font-bold hover:text-[#640000]"
                                    >
                                        {row.name}
                                    </Link>
                                    <p className="text-[11px] text-slate-500">
                                        {row.period}
                                    </p>
                                    {row.last_observation &&
                                        row.status === 'changes_requested' && (
                                            <p className="mt-1 max-w-xs text-[11px] text-violet-700">
                                                Corregir: {row.last_observation}
                                            </p>
                                        )}
                                </td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td
                                    className={`px-2 py-2 whitespace-nowrap ${row.overdue ? 'font-bold text-red-700' : ''}`}
                                >
                                    {formatDate(row.due_on)}
                                    <p className="text-[11px] font-normal text-slate-500">
                                        {row.status === 'completed'
                                            ? `Presentada ${formatDate(row.filed_on)}`
                                            : row.overdue
                                              ? `vencida hace ${row.days_overdue} d`
                                              : row.days === 0
                                                ? 'vence hoy'
                                                : `en ${row.days} d`}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            obligationTone[row.status] ??
                                                'slate',
                                        )}
                                    >
                                        {row.status_label}
                                    </span>
                                    {row.filing_reference && (
                                        <p className="mt-1 text-[11px] text-slate-500">
                                            Ref. {row.filing_reference}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <ProgressBar value={row.progress} />
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            priorityTone[row.priority.key] ??
                                                'slate',
                                        )}
                                    >
                                        {row.priority.label}
                                    </span>
                                    {row.priority.reason && (
                                        <p className="mt-1 max-w-36 text-[11px] text-slate-500">
                                            {row.priority.reason}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {row.preparer ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {row.reviewer ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <PrimaryActionButton
                                        action={row.primary_action}
                                        startUrl={`/essential/obligations/${row.id}/start`}
                                    />
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <Pager
                        paged={obligations}
                        onPage={(page) => apply({ page })}
                    />
                </Card>
            </Shell>
        </>
    );
}
