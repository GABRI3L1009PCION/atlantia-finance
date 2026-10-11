import { Head, Link } from '@inertiajs/react';
import {
    EmptyRow,
    PageHeader,
    Shell,
    badge,
    panel,
} from '@/components/office-admin/kit';
import { companyStatusTone } from '@/components/operations/kit';
import type { Context } from '@/components/operations/kit';

type Closing = {
    id: number;
    name: string;
    period_name: string;
    progress: number;
    status: string;
    status_label: string;
    review_status: string | null;
    blocked: boolean;
    overdue: boolean;
    accountant: { name: string } | null;
    blockers: {
        label: string;
        status: string;
        message: string;
        destination: string;
    }[];
    blocking_count: number;
    public_id: string;
};

const reviewLabel: Record<string, string> = {
    ready_for_review: 'Esperando',
    in_review: 'En revisión',
    changes_requested: 'Con cambios',
    approved: 'Aprobada',
};

export default function OperationsClosings({
    context,
    closings,
    permissions,
}: {
    context: Context;
    closings: Closing[];
    permissions: Record<string, boolean>;
}) {
    return (
        <>
            <Head title="Cierres" />
            <Shell>
                <PageHeader
                    title="Cierres"
                    subtitle={`Qué falta para cerrar ${context.period?.name ?? 'el período'} en cada empresa`}
                />
                {!permissions.canClosePeriods && (
                    <p className="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">
                        Supervisas los cierres; ejecutar el cierre contable
                        requiere el permiso de gestión de períodos del contador
                        responsable.
                    </p>
                )}
                <section className={`${panel} p-3`}>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Período</th>
                                    <th className="px-2 py-2">Responsable</th>
                                    <th className="px-2 py-2">Avance</th>
                                    <th className="px-2 py-2">Revisión</th>
                                    <th className="px-2 py-2">Checklist</th>
                                    <th className="px-2 py-2">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {closings.map((closing) => (
                                    <tr key={closing.id} className="align-top">
                                        <td className="px-2 py-2 font-bold">
                                            <Link
                                                href={`/accounting/companies/${closing.public_id}`}
                                                className="hover:text-[#640000]"
                                            >
                                                {closing.name}
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2">
                                            {closing.period_name}
                                        </td>
                                        <td className="px-2 py-2">
                                            {closing.accountant?.name ?? (
                                                <span className="text-red-700">
                                                    Sin contador
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2 font-black">
                                            {closing.progress}%
                                        </td>
                                        <td className="px-2 py-2">
                                            {closing.review_status
                                                ? (reviewLabel[
                                                      closing.review_status
                                                  ] ?? closing.review_status)
                                                : 'Sin enviar'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {closing.blockers.length === 0 ? (
                                                <span className="text-emerald-700">
                                                    Sin bloqueos
                                                </span>
                                            ) : (
                                                <ul className="space-y-1">
                                                    {closing.blockers.map(
                                                        (blocker) => (
                                                            <li
                                                                key={
                                                                    blocker.label
                                                                }
                                                            >
                                                                <span
                                                                    className={badge(
                                                                        blocker.status ===
                                                                            'blocked'
                                                                            ? 'red'
                                                                            : 'amber',
                                                                    )}
                                                                >
                                                                    {blocker.status ===
                                                                    'blocked'
                                                                        ? 'Bloquea'
                                                                        : 'Revisar'}
                                                                </span>{' '}
                                                                <a
                                                                    href={
                                                                        blocker.destination
                                                                    }
                                                                    className="hover:text-[#640000]"
                                                                >
                                                                    {
                                                                        blocker.label
                                                                    }
                                                                </a>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={badge(
                                                    closing.blocked
                                                        ? 'red'
                                                        : (companyStatusTone[
                                                              closing.status
                                                          ] ?? 'slate'),
                                                )}
                                            >
                                                {closing.blocked
                                                    ? 'Bloqueada'
                                                    : closing.status_label}
                                            </span>
                                            {closing.overdue && (
                                                <span
                                                    className={`ml-1 ${badge('red')}`}
                                                >
                                                    Atrasada
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {closings.length === 0 && (
                                    <EmptyRow
                                        colSpan={7}
                                        text="No existen cierres pendientes."
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
