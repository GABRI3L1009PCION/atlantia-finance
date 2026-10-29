import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    FileCheck2,
    Gauge,
    ListChecks,
    Play,
    Plus,
    RefreshCcw,
    Send,
    ShieldCheck,
    UserCog,
    Users,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { ExecutiveDashboard } from '@/components/executive-dashboard';
import type { ExecutiveDashboardData } from '@/components/executive-dashboard';

type Summary = {
    active_offices: number;
    active_companies: number;
    active_users: number;
    periods_in_progress: number;
    ready_for_review: number;
    completed_closings: number;
    overdue_tasks: number;
    critical_incidents: number;
    unassigned_tasks: number;
    upcoming_tasks: number;
    companies_without_accountant: number;
    companies_without_assistant: number;
};

type Company = {
    id: number;
    public_id: string;
    name: string;
    office_id: number;
    office_name: string;
    period_id: number | null;
    period_name: string | null;
    progress: number;
    pending_tasks: number;
    status: string;
    accountant: string | null;
    assistant: string | null;
};

type Task = {
    id: number;
    company_name: string | null;
    module: string;
    title: string;
    status: string;
    due_at: string | null;
    assignee_name: string | null;
    reviewer_name: string | null;
    is_overdue: boolean;
    remaining_label?: string;
    href?: string;
};

type Review = {
    id: number;
    company_name: string;
    period_name: string;
    reviewer_name: string | null;
    status: string;
};

type WorkloadRow = {
    user_id: number;
    name: string;
    organization_role: string;
    role_label?: string;
    assigned_companies: number;
    tasks: Record<string, number>;
    load_score: number;
    utilization_percentage?: number;
    status?: string;
};

type OfficeSummary = {
    id: number;
    name: string;
    code: string;
    city: string | null;
    companies: number;
    users: number;
    progress: number;
    overdue: number;
    critical: number;
    status: string;
    completed_companies?: number;
    responsible?: string;
    health_label?: string;
    health_tone?: 'green' | 'amber' | 'red' | 'blue' | 'slate';
};

type Comparison = {
    label: string;
    direction: 'up' | 'down' | 'flat';
    tone: 'positive' | 'negative' | 'neutral';
};

type ExecutiveAttention = {
    title: string;
    detail: string;
    tone: 'green' | 'amber' | 'red' | 'blue' | 'slate';
    href: string;
};

type Specialized = {
    title: string;
    cards: {
        label: string;
        value: string;
        hint: string;
        tone: 'green' | 'amber' | 'red' | 'blue' | 'slate';
    }[];
    alerts: {
        title: string;
        detail: string;
        tone: 'green' | 'amber' | 'red' | 'blue' | 'slate';
    }[];
    sections: { title: string; columns: string[]; rows: string[][] }[];
};

type Props = {
    dashboard: {
        scope: string;
        role: string;
        title: string;
        subtitle: string;
        summary: Summary;
        offices: OfficeSummary[];
        companies: Company[];
        tasks: Task[];
        reviews: Review[];
        workload: WorkloadRow[];
        activity: {
            id: number;
            actor: string | null;
            action: string;
            date: string;
        }[];
        specialized: Specialized;
        roles: {
            id: number;
            code: string;
            name: string;
            description: string | null;
            is_system: boolean;
        }[];
        team: {
            id: number;
            name: string;
            email: string;
            organization_role: string;
            roles: string[];
        }[];
        comparisons?: Record<string, Comparison>;
        attention?: ExecutiveAttention[];
        filters?: { office_id: number | null; period: string | null };
        selectorOptions?: {
            offices: { id: number; name: string }[];
            periods: {
                key: string;
                name: string;
                starts_on: string;
                ends_on: string;
                companies: number;
            }[];
        };
        permissions: {
            canCreateTasks: boolean;
            canReview: boolean;
            canManageRoles: boolean;
            canManageUsers: boolean;
        };
    };
    notice: string | null;
};

const panel =
    'rounded-xl border border-[#ebe5e1] bg-white shadow-[0_8px_22px_-18px_rgba(49,11,8,.5)]';
const statusLabels: Record<string, string> = {
    pending: 'Pendiente',
    in_progress: 'En proceso',
    ready_for_review: 'Lista para revisión',
    in_review: 'En revisión',
    changes_requested: 'Cambios solicitados',
    approved: 'Aprobada',
    completed: 'Completada',
    cancelled: 'Cancelada',
    blocked: 'Bloqueada',
    not_started: 'Sin iniciar',
    ready_to_close: 'Lista para cierre',
    closed: 'Cerrada',
};

function number(value: number) {
    return new Intl.NumberFormat('es-GT').format(value);
}

function date(value: string | null) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat('es-GT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function badge(tone: 'green' | 'amber' | 'red' | 'blue' | 'slate') {
    const styles = {
        green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        amber: 'bg-amber-50 text-amber-700 ring-amber-200',
        red: 'bg-red-50 text-red-700 ring-red-200',
        blue: 'bg-blue-50 text-blue-700 ring-blue-200',
        slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    };

    return `inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${styles[tone]}`;
}

function statusTone(
    status: string,
): 'green' | 'amber' | 'red' | 'blue' | 'slate' {
    if (['completed', 'approved', 'closed'].includes(status)) {
        return 'green';
    }

    if (['blocked', 'changes_requested', 'cancelled'].includes(status)) {
        return 'red';
    }

    if (['ready_for_review', 'in_review', 'ready_to_close'].includes(status)) {
        return 'blue';
    }

    if (['pending', 'in_progress'].includes(status)) {
        return 'amber';
    }

    return 'slate';
}

export default function WorkDashboard({ dashboard, notice }: Props) {
    const taskForm = useForm({
        office_id: dashboard.offices[0]?.id?.toString() ?? '',
        company_id: dashboard.companies[0]?.id?.toString() ?? '',
        accounting_period_id:
            dashboard.companies[0]?.period_id?.toString() ?? '',
        module:
            dashboard.scope === 'tax'
                ? 'tax'
                : dashboard.scope === 'treasury'
                  ? 'banking'
                  : 'accounting',
        task_type:
            dashboard.scope === 'tax'
                ? 'prepare_tax_obligation'
                : dashboard.scope === 'treasury'
                  ? 'reconcile_bank'
                  : 'prepare_entry',
        title: '',
        description: '',
        priority: 'normal',
        due_at: '',
    });
    const roleForm = useForm({
        user_id: dashboard.team[0]?.id?.toString() ?? '',
        role_code: dashboard.roles[0]?.code ?? '',
        office_id: '',
    });
    const assignmentForm = useForm({
        office_id: dashboard.offices[0]?.id?.toString() ?? '',
        company_id: dashboard.companies[0]?.id?.toString() ?? '',
        user_id: dashboard.team[0]?.id?.toString() ?? '',
        assignment_role: 'accounting_assistant',
        primary: false,
    });

    function createTask(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        taskForm.post('/work/tasks', {
            preserveScroll: true,
            onSuccess: () => taskForm.reset('title', 'description', 'due_at'),
        });
    }

    function transition(task: Task, status: string) {
        router.post(
            `/work/tasks/${task.id}/transition`,
            { status },
            { preserveScroll: true },
        );
    }

    function generate(company: Company) {
        if (!company.period_id) {
            return;
        }

        router.post(
            '/work/period-tasks',
            { company_id: company.id, accounting_period_id: company.period_id },
            { preserveScroll: true },
        );
    }

    function submitReview(company: Company) {
        if (!company.period_id) {
            return;
        }

        router.post(
            '/work/reviews',
            { company_id: company.id, accounting_period_id: company.period_id },
            { preserveScroll: true },
        );
    }

    function assignRole(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!roleForm.data.user_id) {
            return;
        }

        roleForm.post(`/office/users/${roleForm.data.user_id}/roles`, {
            preserveScroll: true,
        });
    }

    function assignCompany(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        assignmentForm.post('/office/company-assignments', {
            preserveScroll: true,
        });
    }

    if (dashboard.scope === 'executive') {
        return (
            <ExecutiveDashboard
                dashboard={dashboard as unknown as ExecutiveDashboardData}
                notice={notice}
            />
        );
    }

    const metrics = [
        ['Oficinas activas', dashboard.summary.active_offices, Building2],
        [
            'Empresas clientes',
            dashboard.summary.active_companies,
            BriefcaseBusiness,
        ],
        ['Usuarios activos', dashboard.summary.active_users, Users],
        [
            'Períodos en proceso',
            dashboard.summary.periods_in_progress,
            CalendarClock,
        ],
        [
            'Listas para revisión',
            dashboard.summary.ready_for_review,
            ClipboardCheck,
        ],
        ['Cierres del mes', dashboard.summary.completed_closings, FileCheck2],
        ['Tareas vencidas', dashboard.summary.overdue_tasks, AlertTriangle],
        [
            'Incidencias críticas',
            dashboard.summary.critical_incidents,
            ShieldCheck,
        ],
        [
            'Sin responsable',
            dashboard.summary.companies_without_accountant +
                dashboard.summary.companies_without_assistant,
            UserCog,
        ],
        ['Próximos 7 días', dashboard.summary.upcoming_tasks, Clock3],
    ] as const;

    return (
        <>
            <Head title={dashboard.title} />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6 lg:p-8">
                <div className="mx-auto max-w-7xl space-y-6">
                    {notice && (
                        <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
                            <CheckCircle2 className="size-5" />
                            {notice}
                        </div>
                    )}

                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-bold tracking-[0.22em] text-[#9b5757] uppercase">
                                {dashboard.role.replaceAll('_', ' ')}
                            </p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight text-[#061633]">
                                {dashboard.title}
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                {dashboard.subtitle}
                            </p>
                        </div>
                        <a
                            href="/accounting"
                            className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#b77913] px-4 py-2 text-sm font-bold text-white shadow-[0_12px_28px_-18px_#6b3c00]"
                        >
                            Empresas clientes <ArrowRight className="size-4" />
                        </a>
                    </header>

                    <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        {metrics.map(([label, value, Icon]) => (
                            <div key={label} className={`${panel} p-4`}>
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-sm font-semibold text-slate-600">
                                        {label}
                                    </p>
                                    <span className="grid size-10 place-items-center rounded-full bg-[#640000]/8 text-[#640000]">
                                        <Icon className="size-5" />
                                    </span>
                                </div>
                                <p className="mt-3 text-2xl font-black text-[#061633]">
                                    {number(value)}
                                </p>
                            </div>
                        ))}
                    </section>

                    <SpecializedDashboard data={dashboard.specialized} />

                    {dashboard.permissions.canCreateTasks && (
                        <section className={`${panel} p-5`}>
                            <div className="mb-4 flex items-center gap-2">
                                <Plus className="size-5 text-[#640000]" />
                                <h2 className="text-lg font-bold">
                                    Nueva tarea real
                                </h2>
                            </div>
                            <form
                                onSubmit={createTask}
                                className="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]"
                            >
                                <select
                                    className="h-11 rounded-xl border border-slate-300 bg-white px-3 text-sm"
                                    value={taskForm.data.company_id}
                                    onChange={(event) => {
                                        const selected =
                                            dashboard.companies.find(
                                                (company) =>
                                                    String(company.id) ===
                                                    event.target.value,
                                            );
                                        taskForm.setData({
                                            ...taskForm.data,
                                            company_id: event.target.value,
                                            accounting_period_id:
                                                selected?.period_id?.toString() ??
                                                '',
                                        });
                                    }}
                                >
                                    <option value="">Sin empresa</option>
                                    {dashboard.companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    className="h-11 rounded-xl border border-slate-300 px-3 text-sm"
                                    placeholder="Título"
                                    value={taskForm.data.title}
                                    onChange={(event) =>
                                        taskForm.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <input
                                    className="h-11 rounded-xl border border-slate-300 px-3 text-sm"
                                    placeholder="Tipo de tarea"
                                    value={taskForm.data.task_type}
                                    onChange={(event) =>
                                        taskForm.setData(
                                            'task_type',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <input
                                    className="h-11 rounded-xl border border-slate-300 px-3 text-sm"
                                    type="datetime-local"
                                    value={taskForm.data.due_at}
                                    onChange={(event) =>
                                        taskForm.setData(
                                            'due_at',
                                            event.target.value,
                                        )
                                    }
                                />
                                <button
                                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#640000] px-4 text-sm font-bold text-white disabled:opacity-60"
                                    disabled={taskForm.processing}
                                >
                                    <Plus className="size-4" /> Crear
                                </button>
                            </form>
                        </section>
                    )}

                    <section className="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
                        <div className={`${panel} overflow-hidden`}>
                            <div className="flex items-center justify-between border-b border-slate-100 p-5">
                                <h2 className="text-lg font-bold">
                                    Empresas y avance
                                </h2>
                                <span className={badge('slate')}>
                                    {dashboard.companies.length} visibles
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs tracking-wide text-slate-500 uppercase">
                                        <tr>
                                            <th className="px-5 py-3">
                                                Empresa
                                            </th>
                                            <th className="px-5 py-3">
                                                Período
                                            </th>
                                            <th className="px-5 py-3">
                                                Responsables
                                            </th>
                                            <th className="px-5 py-3">
                                                Avance
                                            </th>
                                            <th className="px-5 py-3">
                                                Estado
                                            </th>
                                            <th className="px-5 py-3">
                                                Acción
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {dashboard.companies.map((company) => (
                                            <tr key={company.id}>
                                                <td className="px-5 py-3 font-semibold">
                                                    {company.name}
                                                    <p className="text-xs font-normal text-slate-500">
                                                        {company.office_name}
                                                    </p>
                                                </td>
                                                <td className="px-5 py-3">
                                                    {company.period_name ??
                                                        'Sin período'}
                                                </td>
                                                <td className="px-5 py-3 text-xs text-slate-600">
                                                    Contador:{' '}
                                                    {company.accountant ??
                                                        'Sin asignar'}
                                                    <br />
                                                    Auxiliar:{' '}
                                                    {company.assistant ??
                                                        'Sin asignar'}
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="h-2 w-28 rounded-full bg-slate-100">
                                                        <div
                                                            className="h-2 rounded-full bg-emerald-500"
                                                            style={{
                                                                width: `${company.progress}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        {company.progress}% ·{' '}
                                                        {company.pending_tasks}{' '}
                                                        pendientes
                                                    </p>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <span
                                                        className={badge(
                                                            statusTone(
                                                                company.status,
                                                            ),
                                                        )}
                                                    >
                                                        {statusLabels[
                                                            company.status
                                                        ] ?? company.status}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex gap-2">
                                                        <a
                                                            className="rounded-lg border border-slate-200 px-3 py-1.5 font-semibold text-[#640000]"
                                                            href={`/accounting/companies/${company.public_id}/dashboard`}
                                                        >
                                                            Abrir
                                                        </a>
                                                        {dashboard.permissions
                                                            .canCreateTasks &&
                                                            company.period_id && (
                                                                <button
                                                                    onClick={() =>
                                                                        generate(
                                                                            company,
                                                                        )
                                                                    }
                                                                    className="rounded-lg border border-slate-200 px-3 py-1.5 font-semibold text-slate-700"
                                                                    title="Generar tareas del período"
                                                                >
                                                                    <RefreshCcw className="size-4" />
                                                                </button>
                                                            )}
                                                        {dashboard.permissions
                                                            .canReview &&
                                                            company.period_id && (
                                                                <button
                                                                    onClick={() =>
                                                                        submitReview(
                                                                            company,
                                                                        )
                                                                    }
                                                                    className="rounded-lg border border-slate-200 px-3 py-1.5 font-semibold text-slate-700"
                                                                    title="Enviar a revisión"
                                                                >
                                                                    <Send className="size-4" />
                                                                </button>
                                                            )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {dashboard.companies.length === 0 && (
                                            <tr>
                                                <td
                                                    className="px-5 py-8 text-center text-slate-500"
                                                    colSpan={6}
                                                >
                                                    No existen empresas
                                                    asignadas.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className={`${panel} p-5`}>
                            <h2 className="mb-4 flex items-center gap-2 text-lg font-bold">
                                <Gauge className="size-5 text-[#640000]" />{' '}
                                Carga de trabajo
                            </h2>
                            <div className="space-y-3">
                                {dashboard.workload.map((row) => (
                                    <div
                                        key={row.user_id}
                                        className="rounded-xl border border-slate-100 p-3"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <p className="font-bold">
                                                    {row.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {row.assigned_companies}{' '}
                                                    empresas ·{' '}
                                                    {row.tasks.pending ?? 0}{' '}
                                                    pendientes ·{' '}
                                                    {row.tasks.overdue ?? 0}{' '}
                                                    vencidas
                                                </p>
                                            </div>
                                            <span
                                                className={badge(
                                                    row.load_score > 20
                                                        ? 'red'
                                                        : row.load_score > 10
                                                          ? 'amber'
                                                          : 'green',
                                                )}
                                            >
                                                {row.load_score}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                                {dashboard.workload.length === 0 && (
                                    <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
                                        La carga global solo se muestra a roles
                                        de supervisión.
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,.85fr)]">
                        <div className={`${panel} overflow-hidden`}>
                            <div className="flex items-center justify-between border-b border-slate-100 p-5">
                                <h2 className="flex items-center gap-2 text-lg font-bold">
                                    <ListChecks className="size-5 text-[#640000]" />{' '}
                                    Tareas
                                </h2>
                            </div>
                            <div className="divide-y divide-slate-100">
                                {dashboard.tasks.map((task) => (
                                    <div
                                        key={task.id}
                                        className="grid gap-3 p-4 lg:grid-cols-[1fr_auto]"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-bold">
                                                    {task.title}
                                                </p>
                                                <span
                                                    className={badge(
                                                        statusTone(task.status),
                                                    )}
                                                >
                                                    {statusLabels[
                                                        task.status
                                                    ] ?? task.status}
                                                </span>
                                                {task.is_overdue && (
                                                    <span
                                                        className={badge('red')}
                                                    >
                                                        Vencida
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-1 text-sm text-slate-600">
                                                {task.company_name ??
                                                    'Sin empresa'}{' '}
                                                · {task.module} · vence{' '}
                                                {date(task.due_at)}
                                            </p>
                                            <p className="mt-1 text-xs text-slate-500">
                                                Asignado:{' '}
                                                {task.assignee_name ??
                                                    'Sin responsable'}{' '}
                                                · Revisor:{' '}
                                                {task.reviewer_name ??
                                                    'Sin revisor'}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {task.status === 'pending' && (
                                                <button
                                                    onClick={() =>
                                                        transition(
                                                            task,
                                                            'in_progress',
                                                        )
                                                    }
                                                    className="rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white"
                                                >
                                                    <Play className="inline size-3" />{' '}
                                                    Iniciar
                                                </button>
                                            )}
                                            {task.status === 'in_progress' && (
                                                <button
                                                    onClick={() =>
                                                        transition(
                                                            task,
                                                            'ready_for_review',
                                                        )
                                                    }
                                                    className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white"
                                                >
                                                    Enviar
                                                </button>
                                            )}
                                            {task.status ===
                                                'ready_for_review' && (
                                                <button
                                                    onClick={() =>
                                                        transition(
                                                            task,
                                                            'in_review',
                                                        )
                                                    }
                                                    className="rounded-lg bg-blue-700 px-3 py-2 text-xs font-bold text-white"
                                                >
                                                    Revisar
                                                </button>
                                            )}
                                            {task.status === 'in_review' && (
                                                <button
                                                    onClick={() =>
                                                        transition(
                                                            task,
                                                            'approved',
                                                        )
                                                    }
                                                    className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white"
                                                >
                                                    Aprobar
                                                </button>
                                            )}
                                            {task.status === 'approved' && (
                                                <button
                                                    onClick={() =>
                                                        transition(
                                                            task,
                                                            'completed',
                                                        )
                                                    }
                                                    className="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white"
                                                >
                                                    Completar
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {dashboard.tasks.length === 0 && (
                                    <p className="p-8 text-center text-sm text-slate-500">
                                        No tienes tareas pendientes.
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-5">
                            <div className={`${panel} p-5`}>
                                <h2 className="mb-4 text-lg font-bold">
                                    Revisiones
                                </h2>
                                <div className="space-y-3">
                                    {dashboard.reviews.map((review) => (
                                        <div
                                            key={review.id}
                                            className="rounded-xl border border-slate-100 p-3"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-bold">
                                                        {review.company_name}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {review.period_name} ·{' '}
                                                        {review.reviewer_name ??
                                                            'Sin revisor'}
                                                    </p>
                                                </div>
                                                <span
                                                    className={badge(
                                                        statusTone(
                                                            review.status,
                                                        ),
                                                    )}
                                                >
                                                    {statusLabels[
                                                        review.status
                                                    ] ?? review.status}
                                                </span>
                                            </div>
                                            {dashboard.permissions.canReview &&
                                                review.status ===
                                                    'ready_for_review' && (
                                                    <button
                                                        onClick={() =>
                                                            router.post(
                                                                `/work/reviews/${review.id}/start`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className="mt-3 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold"
                                                    >
                                                        Iniciar revisión
                                                    </button>
                                                )}
                                            {dashboard.permissions.canReview &&
                                                review.status ===
                                                    'in_review' && (
                                                    <button
                                                        onClick={() =>
                                                            router.post(
                                                                `/work/reviews/${review.id}/approve`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className="mt-3 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white"
                                                    >
                                                        Aprobar revisión
                                                    </button>
                                                )}
                                        </div>
                                    ))}
                                    {dashboard.reviews.length === 0 && (
                                        <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
                                            No hay empresas esperando revisión.
                                        </p>
                                    )}
                                </div>
                            </div>

                            {(dashboard.permissions.canManageRoles ||
                                dashboard.permissions.canManageUsers) && (
                                <div className={`${panel} p-5`}>
                                    <h2 className="mb-4 text-lg font-bold">
                                        Equipo y roles
                                    </h2>
                                    {dashboard.permissions.canManageUsers && (
                                        <form
                                            onSubmit={assignRole}
                                            className="mb-4 grid gap-2 rounded-xl bg-slate-50 p-3"
                                        >
                                            <select
                                                className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                                value={roleForm.data.user_id}
                                                onChange={(event) =>
                                                    roleForm.setData(
                                                        'user_id',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                {dashboard.team.map(
                                                    (member) => (
                                                        <option
                                                            key={member.id}
                                                            value={member.id}
                                                        >
                                                            {member.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <select
                                                className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                                value={roleForm.data.role_code}
                                                onChange={(event) =>
                                                    roleForm.setData(
                                                        'role_code',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                {dashboard.roles.map((role) => (
                                                    <option
                                                        key={role.code}
                                                        value={role.code}
                                                    >
                                                        {role.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <select
                                                className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                                value={roleForm.data.office_id}
                                                onChange={(event) =>
                                                    roleForm.setData(
                                                        'office_id',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Toda la organización
                                                </option>
                                                {dashboard.offices.map(
                                                    (office) => (
                                                        <option
                                                            key={office.id}
                                                            value={office.id}
                                                        >
                                                            {office.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <button
                                                className="h-10 rounded-lg bg-[#640000] px-3 text-sm font-bold text-white disabled:opacity-60"
                                                disabled={roleForm.processing}
                                            >
                                                Asignar rol
                                            </button>
                                        </form>
                                    )}
                                    <div className="space-y-2">
                                        {dashboard.team
                                            .slice(0, 8)
                                            .map((member) => (
                                                <div
                                                    key={member.id}
                                                    className="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3"
                                                >
                                                    <div>
                                                        <p className="font-bold">
                                                            {member.name}
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {member.email}
                                                        </p>
                                                    </div>
                                                    <span
                                                        className={badge(
                                                            'slate',
                                                        )}
                                                    >
                                                        {member.roles[0] ??
                                                            member.organization_role}
                                                    </span>
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            )}

                            {dashboard.permissions.canManageUsers &&
                                dashboard.companies.length > 0 && (
                                    <div className={`${panel} p-5`}>
                                        <h2 className="mb-4 text-lg font-bold">
                                            Asignación de empresa
                                        </h2>
                                        <form
                                            onSubmit={assignCompany}
                                            className="grid gap-2"
                                        >
                                            <select
                                                className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                                value={
                                                    assignmentForm.data
                                                        .company_id
                                                }
                                                onChange={(event) => {
                                                    const selected =
                                                        dashboard.companies.find(
                                                            (company) =>
                                                                String(
                                                                    company.id,
                                                                ) ===
                                                                event.target
                                                                    .value,
                                                        );
                                                    assignmentForm.setData({
                                                        ...assignmentForm.data,
                                                        company_id:
                                                            event.target.value,
                                                        office_id:
                                                            selected?.office_id?.toString() ??
                                                            assignmentForm.data
                                                                .office_id,
                                                    });
                                                }}
                                            >
                                                {dashboard.companies.map(
                                                    (company) => (
                                                        <option
                                                            key={company.id}
                                                            value={company.id}
                                                        >
                                                            {company.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <select
                                                className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                                value={
                                                    assignmentForm.data.user_id
                                                }
                                                onChange={(event) =>
                                                    assignmentForm.setData(
                                                        'user_id',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                {dashboard.team.map(
                                                    (member) => (
                                                        <option
                                                            key={member.id}
                                                            value={member.id}
                                                        >
                                                            {member.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <select
                                                className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                                value={
                                                    assignmentForm.data
                                                        .assignment_role
                                                }
                                                onChange={(event) =>
                                                    assignmentForm.setData(
                                                        'assignment_role',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="accountant_in_charge">
                                                    Contador encargado
                                                </option>
                                                <option value="accounting_assistant">
                                                    Auxiliar contable
                                                </option>
                                                <option value="supervisor">
                                                    Supervisor
                                                </option>
                                                <option value="tax_specialist">
                                                    Fiscal
                                                </option>
                                                <option value="treasury">
                                                    Tesorería
                                                </option>
                                                <option value="payroll">
                                                    Nómina
                                                </option>
                                                <option value="auditor">
                                                    Auditor
                                                </option>
                                            </select>
                                            <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        assignmentForm.data
                                                            .primary
                                                    }
                                                    onChange={(event) =>
                                                        assignmentForm.setData(
                                                            'primary',
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                                Responsable principal
                                            </label>
                                            <button
                                                className="h-10 rounded-lg bg-[#640000] px-3 text-sm font-bold text-white disabled:opacity-60"
                                                disabled={
                                                    assignmentForm.processing
                                                }
                                            >
                                                Asignar a empresa
                                            </button>
                                        </form>
                                    </div>
                                )}
                        </div>
                    </section>

                    <section className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 text-lg font-bold">
                            <BadgeCheck className="size-5 text-[#640000]" />{' '}
                            Actividad reciente
                        </h2>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {dashboard.activity.map((item) => (
                                <div
                                    key={item.id}
                                    className="rounded-xl border border-slate-100 p-3"
                                >
                                    <p className="font-semibold">
                                        {item.action.replaceAll('_', ' ')}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {item.actor ?? 'Sistema'} ·{' '}
                                        {date(item.date)}
                                    </p>
                                </div>
                            ))}
                            {dashboard.activity.length === 0 && (
                                <p className="text-sm text-slate-500">
                                    Aún no hay actividad operativa registrada.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}

function SpecializedDashboard({ data }: { data: Specialized }) {
    if (!data.title && data.cards.length === 0 && data.sections.length === 0) {
        return null;
    }

    return (
        <section className="space-y-5">
            <div className="flex items-center justify-between gap-4">
                <h2 className="text-xl font-black text-[#061633]">
                    {data.title}
                </h2>
                {data.alerts.length > 0 && (
                    <span className={badge('amber')}>
                        {data.alerts.length} alerta(s)
                    </span>
                )}
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.cards.map((card) => (
                    <div key={card.label} className={`${panel} p-4`}>
                        <p className="text-sm font-semibold text-slate-600">
                            {card.label}
                        </p>
                        <p className="mt-2 text-2xl font-black text-[#061633]">
                            {card.value}
                        </p>
                        <span className={`mt-3 ${badge(card.tone)}`}>
                            {card.hint}
                        </span>
                    </div>
                ))}
            </div>

            {(data.alerts.length > 0 || data.sections.length > 0) && (
                <div className="grid gap-5 xl:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
                    <div className={`${panel} p-5`}>
                        <h3 className="mb-4 text-base font-bold">
                            Alertas prioritarias
                        </h3>
                        <div className="space-y-3">
                            {data.alerts.map((alert, index) => (
                                <div
                                    key={`${alert.title}-${index}`}
                                    className="flex gap-3 rounded-lg border border-slate-100 p-3"
                                >
                                    <span
                                        className={`mt-0.5 size-2.5 shrink-0 rounded-full ${alert.tone === 'red' ? 'bg-red-500' : alert.tone === 'amber' ? 'bg-amber-500' : alert.tone === 'green' ? 'bg-emerald-500' : 'bg-blue-500'}`}
                                    />
                                    <div>
                                        <p className="text-sm font-bold text-[#061633]">
                                            {alert.title}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-500">
                                            {alert.detail}
                                        </p>
                                    </div>
                                </div>
                            ))}
                            {data.alerts.length === 0 && (
                                <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">
                                    Sin alertas críticas en este alcance.
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="space-y-5">
                        {data.sections.map((section) => (
                            <div
                                key={section.title}
                                className={`${panel} overflow-hidden`}
                            >
                                <div className="border-b border-slate-100 p-5">
                                    <h3 className="text-base font-bold">
                                        {section.title}
                                    </h3>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-50 text-left text-xs tracking-wide text-slate-500 uppercase">
                                            <tr>
                                                {section.columns.map(
                                                    (column) => (
                                                        <th
                                                            key={column}
                                                            className="px-4 py-3"
                                                        >
                                                            {column}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {section.rows.map((row, index) => (
                                                <tr
                                                    key={`${section.title}-${index}`}
                                                >
                                                    {row.map(
                                                        (cell, cellIndex) => (
                                                            <td
                                                                key={`${section.title}-${index}-${cellIndex}`}
                                                                className="px-4 py-3 font-medium text-slate-700"
                                                            >
                                                                {cell}
                                                            </td>
                                                        ),
                                                    )}
                                                </tr>
                                            ))}
                                            {section.rows.length === 0 && (
                                                <tr>
                                                    <td
                                                        className="px-4 py-6 text-center text-sm text-slate-500"
                                                        colSpan={
                                                            section.columns
                                                                .length
                                                        }
                                                    >
                                                        Sin registros para
                                                        mostrar.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </section>
    );
}
