import { Head, Link } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    badge,
    panel,
} from '@/components/office-admin/kit';
import { useScopedHref } from '@/components/operations/kit';
import type { Context } from '@/components/operations/kit';

type Company = {
    id: number;
    name: string;
    structural: {
        user_id: number;
        name: string;
        role: string;
        is_primary: boolean;
    }[];
    operational: {
        user_id: number;
        name: string;
        module: string;
        tasks: number;
    }[];
    unassigned_tasks: number;
};

export default function OperationsAssignments({
    companies,
    permissions,
    notice,
}: {
    context: Context;
    companies: Company[];
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const scoped = useScopedHref();

    return (
        <>
            <Head title="Asignaciones operativas" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Asignaciones operativas"
                    subtitle="Quién es responsable de cada empresa (estructura) y quién tiene hoy su trabajo (tareas)"
                />
                <p className="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">
                    Los responsables estructurales los define el administrador
                    de oficina. Aquí distribuyes el trabajo operativo;{' '}
                    {permissions.canManageAssignments
                        ? 'también puedes cambiar responsables desde el panel administrativo.'
                        : 'para cambiar un responsable usa "Solicitar cambio" en el detalle de la empresa.'}
                </p>
                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {companies.map((company) => (
                        <div key={company.id} className={`${panel} p-3`}>
                            <div className="flex items-center justify-between">
                                <Link
                                    href={scoped(
                                        `/operations/companies?company=${company.id}`,
                                    )}
                                    className="font-black hover:text-[#640000]"
                                >
                                    {company.name}
                                </Link>
                                {company.unassigned_tasks > 0 && (
                                    <span className={badge('amber')}>
                                        {company.unassigned_tasks} sin
                                        responsable
                                    </span>
                                )}
                            </div>
                            <p className="mt-2 text-xs font-bold text-[#526b92]">
                                Estructura
                            </p>
                            <ul className="text-xs">
                                {company.structural.map((person) => (
                                    <li
                                        key={`${person.user_id}-${person.role}`}
                                    >
                                        {person.name} · {person.role}
                                        {person.is_primary
                                            ? ' (principal)'
                                            : ''}
                                    </li>
                                ))}
                                {company.structural.length === 0 && (
                                    <li className="text-red-700">
                                        Sin responsables asignados.
                                    </li>
                                )}
                            </ul>
                            <p className="mt-2 text-xs font-bold text-[#526b92]">
                                Trabajo abierto
                            </p>
                            <ul className="text-xs">
                                {company.operational.map((row) => (
                                    <li key={`${row.user_id}-${row.module}`}>
                                        <Link
                                            href={scoped(
                                                `/operations/tasks?company_id=${company.id}&assigned_to=${row.user_id}`,
                                            )}
                                            className="hover:text-[#640000]"
                                        >
                                            {row.name}
                                        </Link>{' '}
                                        · {row.module}: {row.tasks}
                                    </li>
                                ))}
                                {company.operational.length === 0 && (
                                    <li className="text-[#526b92]">
                                        Sin tareas abiertas.
                                    </li>
                                )}
                            </ul>
                        </div>
                    ))}
                </section>
            </Shell>
        </>
    );
}
