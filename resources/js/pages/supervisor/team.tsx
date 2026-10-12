import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    AssignWorkDialog,
    Card,
    Table,
    toneBadge,
    useSupervisorHref,
} from '@/components/supervisor/kit';
import type {
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';

type Member = {
    user_id: number;
    name: string;
    role_label: string;
    supervised_companies: number;
    open_tasks: number;
    in_review: number;
    completed: number;
    tasks: Record<string, number>;
    utilization_percentage: number;
    status: string;
};

const WORK_TYPES = {
    purchases: 'Compras',
    sales: 'Ventas',
    accounting: 'Contabilidad',
    banking: 'Bancos',
    reconciliation: 'Conciliación',
    receivables: 'Cuentas por cobrar',
    payables: 'Cuentas por pagar',
    tax: 'Declaraciones',
    reports: 'Reportes',
    documents: 'Documentos',
};

export default function SupervisorTeam({
    context,
    team,
    permissions,
    notice,
}: {
    context: SupervisorContext;
    team: Member[];
    permissions: SupervisorPermissions;
    notice: string | null;
}) {
    const href = useSupervisorHref();
    const [assignOpen, setAssignOpen] = useState(false);

    return (
        <>
            <Head title="Equipo contable" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Equipo contable"
                    subtitle="Contadores y auxiliares que trabajan en tus empresas"
                    actions={
                        permissions.canAssign && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setAssignOpen(true)}
                            >
                                <Plus className="size-4" /> Asignar trabajo
                            </button>
                        )
                    }
                />
                <Card title={`${team.length} colaborador(es)`}>
                    <Table
                        headers={[
                            'Nombre',
                            'Rol',
                            'Empresas',
                            'Tareas',
                            'Pendientes',
                            'Vencidas',
                            'En revisión',
                            'Completadas',
                            'Carga',
                            'Acciones',
                        ]}
                        empty={
                            team.length === 0 &&
                            'Nadie trabaja todavía en tus empresas.'
                        }
                    >
                        {team.map((member) => (
                            <tr key={member.user_id}>
                                <td className="px-2 py-2 font-bold">
                                    {member.name}
                                </td>
                                <td className="px-2 py-2">
                                    {member.role_label}
                                </td>
                                <td className="px-2 py-2">
                                    {member.supervised_companies}
                                </td>
                                <td className="px-2 py-2">
                                    {member.open_tasks + member.in_review}
                                </td>
                                <td className="px-2 py-2">
                                    {member.open_tasks}
                                </td>
                                <td
                                    className={`px-2 py-2 ${member.tasks.overdue > 0 ? 'font-bold text-red-700' : ''}`}
                                >
                                    {member.tasks.overdue}
                                </td>
                                <td className="px-2 py-2">
                                    {member.in_review}
                                </td>
                                <td className="px-2 py-2">
                                    {member.completed}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            member.utilization_percentage >= 85
                                                ? 'red'
                                                : member.utilization_percentage >=
                                                    65
                                                  ? 'amber'
                                                  : 'green',
                                        )}
                                    >
                                        {member.utilization_percentage}%
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex gap-1">
                                        <Link
                                            href={href(
                                                `/supervisor/tasks?assigned_to=${member.user_id}`,
                                            )}
                                            className={secondaryButton}
                                        >
                                            Ver tareas y redistribuir
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
            <AssignWorkDialog
                open={assignOpen}
                onClose={() => setAssignOpen(false)}
                context={context}
                workTypes={WORK_TYPES}
            />
        </>
    );
}
