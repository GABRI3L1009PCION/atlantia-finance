import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BadgeCheck,
    Building2,
    CalendarCheck2,
    ClipboardCheck,
    Eye,
    FileText,
    ListChecks,
    Pencil,
    Plus,
    Send,
    ShieldCheck,
    Trash2,
    UserCog,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { InviteDialog } from '@/components/office-admin/kit';
import type { FormOptions } from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type AnyRow = Record<string, any>;

type Props = {
    screen: string;
    notice: string | null;
    officeQuota?: { limit: number | null; used: number };
    userQuota?: { limit: number | null; used: number } | null;
    canCreateOffice?: boolean;
    invite?: { officeIds: number[]; options: FormOptions } | null;
    admin: {
        summary: Record<string, number>;
        lookups: {
            offices: AnyRow[];
            companies: AnyRow[];
            periods: AnyRow[];
            users: AnyRow[];
            roles: AnyRow[];
            permissions: AnyRow[];
            assignmentRoles: string[];
            modules: string[];
            priorities: string[];
        };
        offices: AnyRow[];
        office: AnyRow | null;
        usersRoles: {
            users: AnyRow[];
            roles: AnyRow[];
            roleAssignments: AnyRow[];
        };
        roleHistory: AnyRow[];
        companyAssignments: AnyRow[];
        task: AnyRow | null;
        taskTemplates: AnyRow[];
        reviews: AnyRow[];
        review: AnyRow | null;
        closings: AnyRow[];
    };
};

const panel =
    'rounded-xl border border-[#eadfda] bg-white shadow-[0_12px_30px_-24px_rgba(49,11,8,.45)]';
const input =
    'h-11 rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none focus:border-[#640000] focus:ring-2 focus:ring-[#640000]/10';
const button =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[#640000] px-4 py-2 text-sm font-bold text-white shadow-[0_12px_24px_-20px_#640000] disabled:opacity-60';
const ghostButton =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-[#eadfda] bg-white px-4 py-2 text-sm font-bold text-[#640000] disabled:opacity-60';

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-[#eadfda] bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const screens = [
    ['offices', 'Oficinas'],
    ['users-roles', 'Personal'],
    ['roles', 'Roles y permisos'],
    ['company-assignments', 'Asignaciones'],
    ['task-templates', 'Plantillas de tareas'],
    ['reviews', 'Revisiones'],
    ['closings', 'Cierres'],
] as const;

const statusLabels: Record<string, string> = {
    active: 'Activo',
    inactive: 'Inactivo',
    pending: 'Pendiente',
    in_progress: 'En proceso',
    ready_for_review: 'Lista para revisión',
    in_review: 'En revisión',
    first_level_approved: 'Revisión superior',
    changes_requested: 'Cambios solicitados',
    approved: 'Aprobada',
    completed: 'Completada',
    cancelled: 'Cancelada',
    blocked: 'Bloqueada',
    ended: 'Finalizada',
    draft: 'Borrador',
    not_started: 'Sin iniciar',
    ready_to_close: 'Lista para cierre',
    closed: 'Cerrado',
    accountant_in_charge: 'Contador encargado',
    accounting_assistant: 'Auxiliar contable',
    supervisor: 'Supervisor',
    tax_specialist: 'Encargado fiscal',
    treasury: 'Tesorería',
    accounts_receivable: 'Cuentas por cobrar',
    accounts_payable: 'Cuentas por pagar',
    payroll: 'Nómina',
    auditor: 'Auditor',
    read_only: 'Consulta (solo lectura)',
};

function label(value?: string | null) {
    if (!value) {
        return 'Sin dato';
    }

    return statusLabels[value] ?? value.replaceAll('_', ' ');
}

function date(value?: string | null) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat('es-GT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function tone(status?: string | null) {
    if (['active', 'approved', 'completed', 'closed'].includes(status ?? '')) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }

    if (
        ['pending', 'in_progress', 'ready_to_close', 'draft'].includes(
            status ?? '',
        )
    ) {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }

    if (
        ['ready_for_review', 'in_review', 'first_level_approved'].includes(
            status ?? '',
        )
    ) {
        return 'bg-blue-50 text-blue-700 ring-blue-200';
    }

    if (
        [
            'changes_requested',
            'blocked',
            'cancelled',
            'inactive',
            'ended',
        ].includes(status ?? '')
    ) {
        return 'bg-red-50 text-red-700 ring-red-200';
    }

    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function Badge({ value }: { value?: string | null }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold capitalize ring-1 ${tone(value)}`}
        >
            {label(value)}
        </span>
    );
}

function AdminShell({
    screen,
    admin,
    actions,
    children,
}: Props & { actions?: React.ReactNode; children: React.ReactNode }) {
    const readOnly = Boolean(usePage().props.executiveReadOnly);
    const active = screen.includes('office-detail')
        ? 'offices'
        : screen.includes('task-detail')
          ? 'task-templates'
          : screen.includes('review-detail')
            ? 'reviews'
            : screen;
    const title =
        screens.find(([key]) => key === active)?.[1] ?? 'Administración';
    const summaryCards: [string, number, LucideIcon][] = [
        ['Oficinas', admin.summary.offices, Building2],
        ['Usuarios', admin.summary.active_users, UsersRound],
        ['Empresas', admin.summary.companies, FileText],
        ['Asignaciones', admin.summary.active_assignments, ShieldCheck],
        ['Tareas abiertas', admin.summary.open_tasks, ListChecks],
        ['Revisiones', admin.summary.pending_reviews, BadgeCheck],
        ['Cierres abiertos', admin.summary.open_closings, CalendarCheck2],
    ];

    return (
        <>
            <Head title={title} />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#071a38] sm:p-6 lg:p-8">
                <div className="mx-auto max-w-7xl space-y-6">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-black tracking-[0.2em] text-[#9b5757] uppercase">
                                Administración operativa
                            </p>
                            <h1 className="mt-2 text-3xl font-black tracking-tight">
                                {title}
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                Gestión real de estructura, responsables,
                                plantillas, tareas, revisiones y cierres del
                                plan empresarial.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {actions}
                            <Link
                                className={ghostButton}
                                href={
                                    readOnly
                                        ? '/office/executive-dashboard'
                                        : '/office/dashboard'
                                }
                            >
                                Volver al panel{' '}
                                <ArrowRight className="size-4" />
                            </Link>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        {summaryCards.map(([name, value, Icon]) => (
                            <div className={`${panel} p-4`} key={String(name)}>
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-xs font-bold text-slate-500 uppercase">
                                        {String(name)}
                                    </p>
                                    <Icon className="size-5 text-[#640000]" />
                                </div>
                                <p className="mt-2 text-2xl font-black">
                                    {Number(value).toLocaleString('es-GT')}
                                </p>
                            </div>
                        ))}
                    </section>

                    {readOnly && (
                        <div className="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900">
                            <ShieldCheck className="size-5" />
                            Vista de consulta: como Socio Director solo
                            supervisas esta información. Los cambios los realiza
                            el Administrador de la firma.
                        </div>
                    )}
                    <div className={readOnly ? '[&_form]:hidden' : undefined}>
                        {children}
                    </div>
                </div>
            </main>
        </>
    );
}

export default function WorkAdmin(props: Props) {
    const readOnly = Boolean(usePage().props.executiveReadOnly);
    const [manageUser, setManageUser] = useState<string | null>(null);
    const [roleOpen, setRoleOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);
    const [userLimitOpen, setUserLimitOpen] = useState(false);
    const userLimitReached =
        props.userQuota?.limit != null &&
        props.userQuota.used >= props.userQuota.limit;
    const inviteScope = {
        global: true,
        selected: null,
        offices: props.admin.lookups.offices.filter((office) =>
            props.invite?.officeIds.includes(Number(office.id)),
        ) as { id: number; name: string }[],
    };

    return (
        <>
            <AdminShell
                {...props}
                actions={
                    props.screen === 'offices' &&
                    !readOnly &&
                    props.canCreateOffice !== false ? (
                        <NewOfficeButton quota={props.officeQuota} />
                    ) : props.screen === 'users-roles' &&
                      !readOnly &&
                      props.invite ? (
                        <button
                            type="button"
                            className={button}
                            onClick={() =>
                                userLimitReached
                                    ? setUserLimitOpen(true)
                                    : setInviteOpen(true)
                            }
                        >
                            <Plus className="size-4" /> Nuevo colaborador
                        </button>
                    ) : props.screen === 'company-assignments' && !readOnly ? (
                        <button
                            type="button"
                            className={button}
                            onClick={() => setAssignOpen(true)}
                        >
                            <Plus className="size-4" /> Asignar responsable
                        </button>
                    ) : props.screen === 'roles' && !readOnly ? (
                        <button
                            type="button"
                            className={button}
                            onClick={() => setRoleOpen(true)}
                        >
                            <Plus className="size-4" /> Crear rol
                        </button>
                    ) : undefined
                }
            >
                {props.screen === 'offices' && (
                    <OfficesScreen admin={props.admin} />
                )}
                {props.screen === 'office-detail' && (
                    <OfficeDetailScreen admin={props.admin} />
                )}
                {props.screen === 'users-roles' && (
                    <UsersRolesScreen
                        admin={props.admin}
                        readOnly={readOnly}
                        onManage={setManageUser}
                    />
                )}
                {props.screen === 'roles' && (
                    <RolesScreen admin={props.admin} />
                )}
                {props.screen === 'company-assignments' && (
                    <CompanyAssignmentsScreen admin={props.admin} />
                )}
                {props.screen === 'task-detail' && (
                    <TaskDetailScreen admin={props.admin} />
                )}
                {props.screen === 'task-templates' && (
                    <TaskTemplatesScreen admin={props.admin} />
                )}
                {props.screen === 'reviews' && (
                    <ReviewsScreen admin={props.admin} />
                )}
                {props.screen === 'review-detail' && (
                    <ReviewDetailScreen admin={props.admin} />
                )}
                {props.screen === 'closings' && (
                    <ClosingsScreen admin={props.admin} />
                )}
            </AdminShell>
            {props.screen === 'users-roles' && props.userQuota && (
                <Dialog open={userLimitOpen} onOpenChange={setUserLimitOpen}>
                    <DialogContent className="bg-white text-[#142135] sm:max-w-md">
                        <div className="mx-auto grid size-14 place-items-center rounded-full bg-red-50 text-[#640000]">
                            <UsersRound className="size-7" />
                        </div>
                        <DialogTitle className="text-center">
                            Alcanzaste el límite de usuarios
                        </DialogTitle>
                        <DialogDescription className="text-center">
                            Tu plan permite hasta {props.userQuota.limit}{' '}
                            usuarios y ya tienes {props.userQuota.used}{' '}
                            ocupados, contando las invitaciones pendientes. Para
                            agregar otro puedes desactivar a alguien que ya no
                            use el sistema, cancelar una invitación pendiente o
                            ampliar tu plan desde la sección de suscripción.
                        </DialogDescription>
                        <div className="flex flex-wrap justify-center gap-2 pt-2">
                            <Link
                                href="/office/subscription"
                                className={button}
                            >
                                Ver mi plan
                            </Link>
                            <button
                                type="button"
                                className={ghostButton}
                                onClick={() => setUserLimitOpen(false)}
                            >
                                Entendido
                            </button>
                        </div>
                    </DialogContent>
                </Dialog>
            )}
            {props.screen === 'company-assignments' && !readOnly && (
                <AssignmentDialog
                    admin={props.admin}
                    open={assignOpen}
                    onClose={() => setAssignOpen(false)}
                />
            )}
            {props.screen === 'users-roles' && !readOnly && (
                <>
                    <Dialog
                        open={manageUser !== null}
                        onOpenChange={(open) => !open && setManageUser(null)}
                    >
                        <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-3xl">
                            <DialogTitle>Gestionar usuario</DialogTitle>
                            <DialogDescription>
                                Cambia el rol de organización, el estado, la
                                oficina o asigna un rol operativo.
                            </DialogDescription>
                            {manageUser !== null && (
                                <UserMembershipForm
                                    admin={props.admin}
                                    userId={manageUser}
                                    onDone={() => setManageUser(null)}
                                />
                            )}
                        </DialogContent>
                    </Dialog>
                    {props.invite && (
                        <InviteDialog
                            open={inviteOpen}
                            onClose={() => setInviteOpen(false)}
                            scope={inviteScope}
                            options={props.invite.options}
                        />
                    )}
                </>
            )}
            {props.screen === 'roles' && !readOnly && (
                <>
                    <Dialog open={roleOpen} onOpenChange={setRoleOpen}>
                        <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-3xl">
                            <DialogTitle>Crear rol</DialogTitle>
                            <DialogDescription>
                                Define el nombre del rol y los permisos que
                                tendrá.
                            </DialogDescription>
                            {roleOpen && (
                                <RoleForm
                                    admin={props.admin}
                                    onDone={() => setRoleOpen(false)}
                                />
                            )}
                        </DialogContent>
                    </Dialog>
                </>
            )}
        </>
    );
}

function OfficeForm({
    office,
    onDone,
    bare = false,
}: {
    office?: AnyRow | null;
    onDone?: () => void;
    bare?: boolean;
}) {
    const form = useForm({
        code: office?.code ?? '',
        name: office?.name ?? '',
        email: office?.email ?? '',
        phone: office?.phone ?? '',
        address_line_1: office?.address_line_1 ?? '',
        city: office?.city ?? '',
        state: office?.state ?? '',
        country_code: office?.country_code ?? 'GT',
        timezone: office?.timezone ?? 'America/Guatemala',
        is_primary: Boolean(office?.is_primary),
        status: office?.status ?? 'active',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (office?.id) {
            form.patch(`/office/offices/${office.id}`, {
                preserveScroll: true,
            });

            return;
        }

        form.post('/office/offices', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        });
    }

    return (
        <form
            id={office ? 'editar' : undefined}
            onSubmit={submit}
            className={`scroll-mt-24 ${bare ? '' : `${panel} p-5`}`}
        >
            {!bare && (
                <div className="mb-4 flex items-center gap-2">
                    <Building2 className="size-5 text-[#640000]" />
                    <h2 className="text-lg font-black">
                        {office ? 'Editar oficina' : 'Nueva oficina'}
                    </h2>
                </div>
            )}
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <input
                    className={input}
                    placeholder="Código"
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value)
                    }
                    required
                />
                <input
                    className={input}
                    placeholder="Nombre formal"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    required
                />
                <input
                    className={input}
                    type="email"
                    placeholder="Correo"
                    value={form.data.email}
                    onChange={(event) =>
                        form.setData('email', event.target.value)
                    }
                />
                <input
                    className={input}
                    placeholder="Teléfono"
                    value={form.data.phone}
                    onChange={(event) =>
                        form.setData('phone', event.target.value)
                    }
                />
                <input
                    className={input}
                    placeholder="Dirección"
                    value={form.data.address_line_1}
                    onChange={(event) =>
                        form.setData('address_line_1', event.target.value)
                    }
                />
                <input
                    className={input}
                    placeholder="Ciudad"
                    value={form.data.city}
                    onChange={(event) =>
                        form.setData('city', event.target.value)
                    }
                />
                <input
                    className={input}
                    placeholder="Departamento / Estado"
                    value={form.data.state}
                    onChange={(event) =>
                        form.setData('state', event.target.value)
                    }
                />
                <input
                    className={input}
                    placeholder="País"
                    value={form.data.country_code}
                    onChange={(event) =>
                        form.setData('country_code', event.target.value)
                    }
                />
                <input
                    className={input}
                    placeholder="Zona horaria"
                    value={form.data.timezone}
                    onChange={(event) =>
                        form.setData('timezone', event.target.value)
                    }
                />
                <select
                    className={input}
                    value={form.data.status}
                    onChange={(event) =>
                        form.setData('status', event.target.value)
                    }
                >
                    <option value="active">Activa</option>
                    <option value="inactive">Inactiva</option>
                </select>
                <label className="flex min-h-11 items-center gap-2 rounded-xl border border-slate-300 px-3 text-sm font-bold text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.data.is_primary}
                        onChange={(event) =>
                            form.setData('is_primary', event.target.checked)
                        }
                    />
                    Oficina principal
                </label>
                <button className={button} disabled={form.processing}>
                    <Plus className="size-4" /> Guardar oficina
                </button>
            </div>
        </form>
    );
}

function NewOfficeButton({
    quota,
}: {
    quota?: { limit: number | null; used: number };
}) {
    const [creating, setCreating] = useState(false);
    const [limitReached, setLimitReached] = useState(false);
    const atLimit = quota?.limit != null && quota.used >= quota.limit;

    return (
        <>
            <button
                type="button"
                className={button}
                onClick={() =>
                    atLimit ? setLimitReached(true) : setCreating(true)
                }
            >
                <Plus className="size-4" /> Nueva oficina
            </button>
            <Dialog open={limitReached} onOpenChange={setLimitReached}>
                <DialogContent className="bg-white text-[#142135] sm:max-w-md">
                    <div className="mx-auto grid size-14 place-items-center rounded-full bg-red-50 text-[#640000]">
                        <Building2 className="size-7" />
                    </div>
                    <DialogTitle className="text-center">
                        Alcanzaste el límite de oficinas
                    </DialogTitle>
                    <DialogDescription className="text-center">
                        Tu plan permite hasta {quota?.limit} oficinas y ya
                        tienes {quota?.used} registradas. Para agregar otra
                        puedes desactivar una que ya no uses o ampliar tu plan
                        desde la sección de suscripción.
                    </DialogDescription>
                    <div className="flex flex-wrap justify-center gap-2 pt-2">
                        <Link href="/office/subscription" className={button}>
                            Ver mi plan
                        </Link>
                        <button
                            type="button"
                            className={ghostButton}
                            onClick={() => setLimitReached(false)}
                        >
                            Entendido
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-4xl">
                    <DialogTitle>Nueva oficina</DialogTitle>
                    <DialogDescription>
                        Registra la sede con sus datos de contacto y ubicación.
                    </DialogDescription>
                    <OfficeForm bare onDone={() => setCreating(false)} />
                </DialogContent>
            </Dialog>
        </>
    );
}

const officeSearchText = (office: AnyRow): string =>
    [office.name, office.code, office.city, office.email, office.phone].join(
        ' ',
    );

function OfficesScreen({ admin }: { admin: Props['admin'] }) {
    const readOnly = Boolean(usePage().props.executiveReadOnly);
    const [status, setStatus] = useState('');
    const [city, setCity] = useState('');
    const cities = useMemo(
        () =>
            Array.from(
                new Set(
                    admin.offices
                        .map((office) => office.city as string | null)
                        .filter((value): value is string => Boolean(value)),
                ),
            ).sort(),
        [admin.offices],
    );
    const predicate = useMemo(
        () => (office: AnyRow) =>
            (status === '' || office.status === status) &&
            (city === '' || office.city === city),
        [status, city],
    );
    const table = useTableControls(admin.offices, {
        searchText: officeSearchText,
        predicate,
    });

    return (
        <div className="space-y-5">
            <section className={`${panel} overflow-hidden`}>
                <TableHeader
                    title="Pantalla formal de Oficinas"
                    action={`${table.count} de ${table.total} oficinas`}
                />
                <TableToolbar
                    search={table.search}
                    onSearch={table.setSearch}
                    placeholder="Buscar por nombre, código, ciudad o correo"
                    pageSize={table.pageSize}
                    onPageSize={table.setPageSize}
                >
                    <TableFilterSelect
                        label="Todos los estados"
                        value={status}
                        onChange={setStatus}
                        options={[
                            { value: 'active', label: 'Activas' },
                            { value: 'inactive', label: 'Inactivas' },
                        ]}
                    />
                    {cities.length > 1 && (
                        <TableFilterSelect
                            label="Todas las ciudades"
                            value={city}
                            onChange={setCity}
                            options={cities.map((value) => ({
                                value,
                                label: value,
                            }))}
                        />
                    )}
                </TableToolbar>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                            <tr>
                                <th className="px-5 py-3">Oficina</th>
                                <th className="px-5 py-3">Contacto</th>
                                <th className="px-5 py-3">Operación</th>
                                <th className="px-5 py-3">Estado</th>
                                <th className="px-5 py-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {table.pageRows.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-5 py-10 text-center text-sm text-slate-500"
                                    >
                                        No hay oficinas que coincidan con la
                                        búsqueda o los filtros.
                                    </td>
                                </tr>
                            )}
                            {table.pageRows.map((office) => (
                                <tr key={office.id}>
                                    <td className="px-5 py-4">
                                        <p className="font-black">
                                            {office.name}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {office.code} ·{' '}
                                            {office.city ?? 'Ciudad pendiente'}{' '}
                                            {office.is_primary
                                                ? '· Principal'
                                                : ''}
                                        </p>
                                    </td>
                                    <td className="px-5 py-4 text-slate-600">
                                        {office.email ?? 'Sin correo'}
                                        <br />
                                        {office.phone ?? 'Sin teléfono'}
                                    </td>
                                    <td className="px-5 py-4 text-slate-600">
                                        {office.companies_count} empresas ·{' '}
                                        {office.users_count} usuarios
                                        <br />
                                        {office.open_tasks_count} tareas ·{' '}
                                        {office.pending_reviews_count}{' '}
                                        revisiones
                                    </td>
                                    <td className="px-5 py-4">
                                        <Badge value={office.status} />
                                    </td>
                                    <td className="px-5 py-4">
                                        <div className="flex items-center gap-1.5">
                                            <Link
                                                className={iconButton}
                                                href={`/office/offices/${office.id}`}
                                                title="Ver detalle"
                                                aria-label={`Ver detalle de ${office.name}`}
                                            >
                                                <Eye className="size-4" />
                                            </Link>
                                            {!readOnly && (
                                                <Link
                                                    className={iconButton}
                                                    href={`/office/offices/${office.id}#editar`}
                                                    title="Editar oficina"
                                                    aria-label={`Editar ${office.name}`}
                                                >
                                                    <Pencil className="size-4" />
                                                </Link>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <TablePagination
                    page={table.page}
                    pages={table.pages}
                    pageSize={table.pageSize}
                    count={table.count}
                    onPage={table.setPage}
                    noun="oficinas"
                />
            </section>
        </div>
    );
}

function OfficeDetailScreen({ admin }: { admin: Props['admin'] }) {
    const office = admin.office?.info;

    if (!office) {
        return <EmptyState title="Oficina no encontrada" />;
    }

    return (
        <div className="space-y-5">
            <OfficeForm office={office} />
            <section className="grid gap-5 xl:grid-cols-[.9fr_1.1fr]">
                <div className={`${panel} overflow-hidden`}>
                    <TableHeader title="Empresas de la oficina" />
                    <SimpleTable
                        columns={['Empresa', 'NIT', 'Asignaciones', 'Tareas']}
                        rows={admin.office?.companies ?? []}
                        render={(company) => [
                            company.name,
                            company.tax_id,
                            company.active_assignments,
                            company.open_tasks,
                        ]}
                    />
                </div>
                <div className={`${panel} overflow-hidden`}>
                    <TableHeader title="Equipo asignado" />
                    <SimpleTable
                        columns={['Usuario', 'Correo', 'Rol oficina', 'Estado']}
                        rows={admin.office?.users ?? []}
                        render={(user) => [
                            user.name,
                            user.email,
                            user.role,
                            <Badge key="status" value={user.status} />,
                        ]}
                    />
                </div>
            </section>
            <section className={`${panel} overflow-hidden`}>
                <TableHeader title="Tareas recientes de la oficina" />
                <TaskTable rows={admin.office?.tasks ?? []} />
            </section>
        </div>
    );
}

const organizationRoles: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    member: 'Colaborador',
    viewer: 'Lectura',
};

const roleUserSearchText = (user: AnyRow): string =>
    [
        user.name,
        user.email,
        (user.roles ?? []).join(' '),
        (user.offices ?? []).map((office: AnyRow) => office.name).join(' '),
    ].join(' ');

function UsersRolesScreen({
    admin,
    onManage,
    readOnly,
}: {
    admin: Props['admin'];
    onManage: (userId: string) => void;
    readOnly: boolean;
}) {
    const [orgRole, setOrgRole] = useState('');
    const [state, setState] = useState('');
    const [office, setOffice] = useState('');
    const predicate = useMemo(
        () => (user: AnyRow) =>
            (orgRole === '' || user.organization_role === orgRole) &&
            (state === '' || user.status === state) &&
            (office === '' ||
                (user.offices ?? []).some(
                    (item: AnyRow) => String(item.id ?? item.name) === office,
                )),
        [orgRole, state, office],
    );
    const table = useTableControls(admin.usersRoles.users as AnyRow[], {
        searchText: roleUserSearchText,
        predicate,
    });

    return (
        <div className="space-y-5">
            <section className={`${panel} overflow-hidden`}>
                <TableHeader
                    title="Usuarios"
                    action={`${table.count} de ${table.total} usuarios`}
                />
                <TableToolbar
                    search={table.search}
                    onSearch={table.setSearch}
                    placeholder="Buscar por nombre, correo, rol u oficina"
                    pageSize={table.pageSize}
                    onPageSize={table.setPageSize}
                >
                    <TableFilterSelect
                        label="Todo rol de organización"
                        value={orgRole}
                        onChange={setOrgRole}
                        options={Object.entries(organizationRoles).map(
                            ([value, label]) => ({ value, label }),
                        )}
                    />
                    <TableFilterSelect
                        label="Todos los estados"
                        value={state}
                        onChange={setState}
                        options={[
                            { value: 'active', label: 'Activos' },
                            { value: 'inactive', label: 'Inactivos' },
                        ]}
                    />
                    <TableFilterSelect
                        label="Todas las oficinas"
                        value={office}
                        onChange={setOffice}
                        options={admin.lookups.offices.map((item) => ({
                            value: String(item.id),
                            label: item.name,
                        }))}
                    />
                </TableToolbar>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                            <tr>
                                <th className="px-5 py-3">Usuario</th>
                                <th className="px-5 py-3">Rol organización</th>
                                <th className="px-5 py-3">Roles operativos</th>
                                <th className="px-5 py-3">Oficinas</th>
                                <th className="px-5 py-3">Estado</th>
                                <th className="px-5 py-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {table.pageRows.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-5 py-3">
                                        <p className="font-bold">{user.name}</p>
                                        <p className="text-xs text-slate-500">
                                            {user.email}
                                        </p>
                                    </td>
                                    <td className="px-5 py-3">
                                        {organizationRoles[
                                            user.organization_role
                                        ] ?? user.organization_role}
                                    </td>
                                    <td className="px-5 py-3">
                                        {(user.roles ?? []).join(', ') ||
                                            'Sin rol operativo'}
                                    </td>
                                    <td className="px-5 py-3">
                                        {(user.offices ?? [])
                                            .map((item: AnyRow) => item.name)
                                            .join(', ') || 'Sin oficina'}
                                    </td>
                                    <td className="px-5 py-3">
                                        <Badge value={user.status} />
                                    </td>
                                    <td className="px-5 py-3">
                                        <div className="flex items-center gap-1.5">
                                            <Link
                                                className={iconButton}
                                                href={`/office/users?user=${user.id}`}
                                                title="Ver colaborador"
                                                aria-label={`Ver a ${user.name}`}
                                            >
                                                <Eye className="size-4" />
                                            </Link>
                                            {!readOnly && (
                                                <button
                                                    type="button"
                                                    className={iconButton}
                                                    title="Gestionar rol y acceso"
                                                    aria-label={`Gestionar a ${user.name}`}
                                                    onClick={() =>
                                                        onManage(
                                                            String(user.id),
                                                        )
                                                    }
                                                >
                                                    <UserCog className="size-4" />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {table.pageRows.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-5 py-10 text-center text-sm text-slate-500"
                                    >
                                        No hay usuarios que coincidan con la
                                        búsqueda o los filtros.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <TablePagination
                    page={table.page}
                    pages={table.pages}
                    pageSize={table.pageSize}
                    count={table.count}
                    onPage={table.setPage}
                    noun="usuarios"
                />
            </section>
            <RoleAssignmentHistory items={admin.usersRoles.roleAssignments} />
        </div>
    );
}

function RolesScreen({ admin }: { admin: Props['admin'] }) {
    const roles = admin.usersRoles.roles;

    return (
        <div className="space-y-5">
            <section className={`${panel} overflow-hidden`}>
                <TableHeader
                    title="Roles"
                    action={`${roles.length} roles · ${roles.filter((role) => !role.is_system).length} personalizados`}
                />
                <div className="grid gap-4 p-5 lg:grid-cols-2">
                    {roles.map((role) => (
                        <RoleCard key={role.id} role={role} admin={admin} />
                    ))}
                </div>
            </section>
            <RoleHistory items={admin.roleHistory} />
        </div>
    );
}

const assignmentSearchText = (item: AnyRow): string =>
    [item.user_name, item.role_name, item.office_name].join(' ');

function RoleAssignmentHistory({ items }: { items: AnyRow[] }) {
    const [status, setStatus] = useState('');
    const [role, setRole] = useState('');
    const roles = useMemo(
        () =>
            Array.from(
                new Set(items.map((item) => String(item.role_name))),
            ).sort(),
        [items],
    );
    const predicate = useMemo(
        () => (item: AnyRow) =>
            (status === '' || item.status === status) &&
            (role === '' || item.role_name === role),
        [status, role],
    );
    const table = useTableControls(items, {
        searchText: assignmentSearchText,
        predicate,
    });

    return (
        <section className={`${panel} overflow-hidden`}>
            <TableHeader
                title="Historial de asignaciones de rol"
                action={`${table.count} de ${table.total} registros`}
            />
            <TableToolbar
                search={table.search}
                onSearch={table.setSearch}
                placeholder="Buscar por usuario, rol u oficina"
                pageSize={table.pageSize}
                onPageSize={table.setPageSize}
            >
                <TableFilterSelect
                    label="Todos los roles"
                    value={role}
                    onChange={setRole}
                    options={roles.map((value) => ({ value, label: value }))}
                />
                <TableFilterSelect
                    label="Todos los estados"
                    value={status}
                    onChange={setStatus}
                    options={[
                        { value: 'active', label: 'Activos' },
                        { value: 'inactive', label: 'Inactivos' },
                        { value: 'ended', label: 'Finalizados' },
                    ]}
                />
            </TableToolbar>
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                        <tr>
                            <th className="px-5 py-3">Usuario</th>
                            <th className="px-5 py-3">Rol</th>
                            <th className="px-5 py-3">Ámbito</th>
                            <th className="px-5 py-3">Estado</th>
                            <th className="px-5 py-3">Desde</th>
                            <th className="px-5 py-3">Hasta</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {table.pageRows.map((item, index) => (
                            <tr key={`${item.id ?? index}-${item.user_name}`}>
                                <td className="px-5 py-3 font-semibold">
                                    {item.user_name}
                                </td>
                                <td className="px-5 py-3">{item.role_name}</td>
                                <td className="px-5 py-3">
                                    {item.office_name ?? 'Organización'}
                                </td>
                                <td className="px-5 py-3">
                                    <Badge value={item.status} />
                                </td>
                                <td className="px-5 py-3">
                                    {date(item.starts_at)}
                                </td>
                                <td className="px-5 py-3">
                                    {date(item.ends_at)}
                                </td>
                            </tr>
                        ))}
                        {table.pageRows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="px-5 py-10 text-center text-sm text-slate-500"
                                >
                                    No hay asignaciones que coincidan con la
                                    búsqueda o los filtros.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <TablePagination
                page={table.page}
                pages={table.pages}
                pageSize={table.pageSize}
                count={table.count}
                onPage={table.setPage}
                noun="registros"
            />
        </section>
    );
}

function UserMembershipForm({
    admin,
    userId,
    onDone,
}: {
    admin: Props['admin'];
    userId?: string;
    onDone?: () => void;
}) {
    const initialUser =
        admin.lookups.users.find((item) => String(item.id) === userId) ??
        admin.lookups.users[0];
    const form = useForm({
        user_id: String(initialUser?.id ?? ''),
        organization_role: initialUser?.organization_role ?? 'member',
        status: initialUser?.status ?? 'active',
        role_code: admin.lookups.roles[0]?.code ?? '',
        office_id: String(admin.lookups.offices[0]?.id ?? ''),
        office_role: 'member',
        office_status: 'active',
    });

    function submitMembership(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!form.data.user_id) {
            return;
        }

        router.patch(
            `/office/users/${form.data.user_id}/membership`,
            {
                organization_role: form.data.organization_role,
                status: form.data.status,
                office_id: form.data.office_id,
                office_role: form.data.office_role,
                office_status: form.data.office_status,
            },
            { preserveScroll: true, onSuccess: () => onDone?.() },
        );
    }

    function assignRole() {
        if (!form.data.user_id || !form.data.role_code) {
            return;
        }

        router.post(
            `/office/users/${form.data.user_id}/roles`,
            {
                role_code: form.data.role_code,
                office_id: form.data.office_id || null,
            },
            { preserveScroll: true, onSuccess: () => onDone?.() },
        );
    }

    return (
        <form onSubmit={submitMembership}>
            <div className="grid gap-3 md:grid-cols-2">
                <select
                    className={input}
                    value={form.data.user_id}
                    onChange={(event) => {
                        const user = admin.lookups.users.find(
                            (item) => String(item.id) === event.target.value,
                        );
                        form.setData({
                            ...form.data,
                            user_id: event.target.value,
                            organization_role:
                                user?.organization_role ?? 'member',
                            status: user?.status ?? 'active',
                        });
                    }}
                >
                    {admin.lookups.users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name}
                        </option>
                    ))}
                </select>
                <select
                    className={input}
                    value={form.data.organization_role}
                    onChange={(event) =>
                        form.setData('organization_role', event.target.value)
                    }
                >
                    <option value="owner">Owner</option>
                    <option value="admin">Admin</option>
                    <option value="member">Member</option>
                    <option value="viewer">Viewer</option>
                </select>
                <select
                    className={input}
                    value={form.data.status}
                    onChange={(event) =>
                        form.setData('status', event.target.value)
                    }
                >
                    <option value="active">Activo</option>
                    <option value="inactive">Inactivo</option>
                </select>
                <select
                    className={input}
                    value={form.data.office_id}
                    onChange={(event) =>
                        form.setData('office_id', event.target.value)
                    }
                >
                    <option value="">Sin oficina</option>
                    {admin.lookups.offices.map((office) => (
                        <option key={office.id} value={office.id}>
                            {office.name}
                        </option>
                    ))}
                </select>
                <select
                    className={input}
                    value={form.data.office_role}
                    onChange={(event) =>
                        form.setData('office_role', event.target.value)
                    }
                >
                    <option value="admin">Admin oficina</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="member">Colaborador</option>
                    <option value="viewer">Lectura</option>
                </select>
                <select
                    className={input}
                    value={form.data.role_code}
                    onChange={(event) =>
                        form.setData('role_code', event.target.value)
                    }
                >
                    {admin.lookups.roles.map((role) => (
                        <option key={role.code} value={role.code}>
                            {role.name}
                        </option>
                    ))}
                </select>
                <button className={button} type="submit">
                    Guardar usuario
                </button>
                <button
                    className={ghostButton}
                    type="button"
                    onClick={assignRole}
                >
                    Asignar rol operativo
                </button>
            </div>
        </form>
    );
}

function RoleForm({
    admin,
    onDone,
}: {
    admin: Props['admin'];
    onDone?: () => void;
}) {
    const form = useForm({
        name: '',
        code: '',
        description: '',
        permission_ids: [] as number[],
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/office/roles', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        });
    }

    function toggle(id: number) {
        const selected = form.data.permission_ids.includes(id);
        form.setData(
            'permission_ids',
            selected
                ? form.data.permission_ids.filter((item) => item !== id)
                : [...form.data.permission_ids, id],
        );
    }

    return (
        <form onSubmit={submit}>
            <div className="grid gap-3">
                <input
                    className={input}
                    placeholder="Nombre del rol"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    required
                />
                <input
                    className={input}
                    placeholder="Código opcional"
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value)
                    }
                />
                <textarea
                    className="min-h-24 rounded-xl border border-slate-300 p-3 text-sm"
                    placeholder="Descripción"
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
                <PermissionGrid
                    permissions={admin.lookups.permissions}
                    selected={form.data.permission_ids}
                    toggle={toggle}
                />
                <button className={button} disabled={form.processing}>
                    <Plus className="size-4" /> Crear rol
                </button>
            </div>
        </form>
    );
}

function RoleCard({ role, admin }: { role: AnyRow; admin: Props['admin'] }) {
    const selected = (role.permissions ?? []).map((permission: AnyRow) =>
        Number(permission.id),
    );
    const form = useForm({
        name: role.name ?? '',
        description: role.description ?? '',
        permission_ids: selected as number[],
    });

    function toggle(id: number) {
        const exists = form.data.permission_ids.includes(id);
        form.setData(
            'permission_ids',
            exists
                ? form.data.permission_ids.filter((item) => item !== id)
                : [...form.data.permission_ids, id],
        );
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(`/office/roles/${role.id}`, { preserveScroll: true });
    }

    function toggleStatus() {
        router.patch(
            `/office/roles/${role.id}/status`,
            { status: role.status === 'active' ? 'inactive' : 'active' },
            { preserveScroll: true },
        );
    }

    return (
        <form
            onSubmit={submit}
            className={`rounded-xl border p-4 ${role.status === 'inactive' ? 'border-red-100 bg-red-50/30' : 'border-slate-100'}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-black">{role.name}</p>
                    <p className="text-xs text-slate-500">
                        {role.code} ·{' '}
                        {role.is_system ? 'Sistema' : 'Personalizado'} ·{' '}
                        {role.active_assignments ?? 0} usuarios activos
                    </p>
                </div>
                <Badge value={role.status ?? 'active'} />
            </div>
            {role.is_system ? (
                <p className="mt-3 text-sm text-slate-600">
                    {role.description}
                </p>
            ) : (
                <div className="mt-3 grid gap-3">
                    <input
                        className={input}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                    <textarea
                        className="min-h-20 rounded-xl border border-slate-300 p-3 text-sm"
                        value={form.data.description ?? ''}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                    <PermissionGrid
                        permissions={admin.lookups.permissions}
                        selected={form.data.permission_ids}
                        toggle={toggle}
                        compact
                    />
                    <div className="flex flex-wrap gap-2">
                        <button
                            className={ghostButton}
                            disabled={form.processing}
                        >
                            <Pencil className="size-4" /> Actualizar permisos
                        </button>
                        <button
                            className={
                                role.status === 'active'
                                    ? 'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-700 disabled:opacity-60'
                                    : 'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 disabled:opacity-60'
                            }
                            type="button"
                            onClick={toggleStatus}
                        >
                            {role.status === 'active'
                                ? 'Desactivar rol'
                                : 'Reactivar rol'}
                        </button>
                    </div>
                </div>
            )}
            <div className="mt-3 flex flex-wrap gap-2">
                {(role.permissions ?? [])
                    .slice(0, 8)
                    .map((permission: AnyRow) => (
                        <span
                            key={permission.code}
                            className="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600"
                        >
                            {permission.name}
                        </span>
                    ))}
            </div>
        </form>
    );
}

function RoleHistory({ items }: { items: AnyRow[] }) {
    return (
        <section className={`${panel} overflow-hidden`}>
            <TableHeader
                title="Historial visual de cambios de rol"
                action="Creación, permisos, desactivación y reactivación"
            />
            <div className="grid gap-3 p-5">
                {items.map((item) => (
                    <div
                        key={item.id}
                        className="grid gap-3 rounded-xl border border-slate-100 p-4 md:grid-cols-[180px_1fr_auto]"
                    >
                        <div>
                            <Badge
                                value={
                                    item.action?.includes('deactivated')
                                        ? 'inactive'
                                        : item.action?.includes('reactivated')
                                          ? 'active'
                                          : 'draft'
                                }
                            />
                            <p className="mt-2 text-xs font-semibold text-slate-500">
                                {date(item.occurred_at)}
                            </p>
                        </div>
                        <div>
                            <p className="font-black">
                                {roleActionLabel(item.action)}
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                {item.role_name}{' '}
                                {item.role_code ? `· ${item.role_code}` : ''}
                            </p>
                            <p className="mt-2 text-xs text-slate-500">
                                {roleHistoryDetails(item.details)}
                            </p>
                        </div>
                        <p className="text-sm font-semibold text-slate-500">
                            {item.user_name ?? 'Sistema'}
                        </p>
                    </div>
                ))}
                {items.length === 0 && (
                    <p className="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">
                        Aún no hay cambios de roles registrados.
                    </p>
                )}
            </div>
        </section>
    );
}

function roleActionLabel(action?: string) {
    if (action === 'workspace_role.created') {
        return 'Rol creado';
    }

    if (action === 'workspace_role.updated') {
        return 'Permisos o datos actualizados';
    }

    if (action === 'workspace_role.deactivated') {
        return 'Rol desactivado';
    }

    if (action === 'workspace_role.reactivated') {
        return 'Rol reactivado';
    }

    return action ?? 'Cambio de rol';
}

function roleHistoryDetails(details: AnyRow = {}) {
    const before = Array.isArray(details.before_permissions)
        ? details.before_permissions.length
        : null;
    const after = Array.isArray(details.after_permissions)
        ? details.after_permissions.length
        : null;

    if (before !== null || after !== null) {
        return `Permisos: ${before ?? 0} -> ${after ?? 0}`;
    }

    if (details.from || details.to) {
        return `Estado: ${label(details.from)} -> ${label(details.to)}`;
    }

    if (details.permissions !== undefined) {
        return `Permisos asignados: ${details.permissions}`;
    }

    return details.name
        ? `Nombre: ${details.name}`
        : 'Cambio registrado en auditoría.';
}

function PermissionGrid({
    permissions,
    selected,
    toggle,
    compact = false,
}: {
    permissions: AnyRow[];
    selected: number[];
    toggle: (id: number) => void;
    compact?: boolean;
}) {
    const grouped = permissions.reduce<Record<string, AnyRow[]>>(
        (acc, permission) => {
            const group = permission.group ?? 'General';
            acc[group] = [...(acc[group] ?? []), permission];

            return acc;
        },
        {},
    );

    return (
        <div
            className={`grid gap-3 ${compact ? '' : 'max-h-64 overflow-auto rounded-xl border border-slate-200 p-3'}`}
        >
            {Object.entries(grouped).map(([group, items]) => (
                <div key={group}>
                    <p className="mb-2 text-xs font-black text-slate-500 uppercase">
                        {group}
                    </p>
                    <div className="grid gap-2">
                        {items.map((permission) => (
                            <label
                                key={permission.id}
                                className="flex items-start gap-2 text-sm text-slate-700"
                            >
                                <input
                                    type="checkbox"
                                    checked={selected.includes(
                                        Number(permission.id),
                                    )}
                                    onChange={() =>
                                        toggle(Number(permission.id))
                                    }
                                />
                                <span>
                                    {permission.name}
                                    <span className="block text-xs text-slate-400">
                                        {permission.code}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

function CompanyAssignmentsScreen({ admin }: { admin: Props['admin'] }) {
    return (
        <div className="space-y-5">
            <section className={`${panel} overflow-hidden`}>
                <TableHeader
                    title="Asignaciones por empresa"
                    action="Responsables reales por empresa y rol"
                />
                <SimpleTable
                    columns={[
                        'Empresa',
                        'Usuario',
                        'Rol',
                        'Ámbito',
                        'Estado',
                        'Acción',
                    ]}
                    rows={admin.companyAssignments}
                    render={(item) => [
                        item.company_name,
                        <span key="user">
                            {item.user_name}
                            <span className="block text-xs text-slate-500">
                                {item.user_email}
                            </span>
                        </span>,
                        `${assignmentRoleLabels[item.assignment_role] ?? label(item.assignment_role)}${item.is_primary ? ' · Principal' : ''}`,
                        item.office_name,
                        <Badge key="status" value={item.status} />,
                        item.status === 'active' ? (
                            <button
                                key="delete"
                                className="text-[#640000]"
                                onClick={() =>
                                    router.delete(
                                        `/office/company-assignments/${item.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </button>
                        ) : (
                            'Histórico'
                        ),
                    ]}
                />
            </section>
        </div>
    );
}

const assignmentRoleLabels: Record<string, string> = {
    accountant_in_charge: 'Contador encargado',
    accounting_assistant: 'Auxiliar contable',
    supervisor: 'Supervisor',
    tax_specialist: 'Encargado fiscal',
    treasury: 'Tesorería',
    accounts_receivable: 'Cuentas por cobrar',
    accounts_payable: 'Cuentas por pagar',
    payroll: 'Nómina',
    auditor: 'Auditor',
    read_only: 'Consulta (solo lectura)',
};

const sectionTitle =
    'mb-2.5 flex items-center gap-2 text-sm font-black text-[#142135]';
const stepBadge =
    'grid size-5 place-items-center rounded-full bg-[#640000] text-[11px] font-black text-white';

/** Form of a new responsibility: who looks after which company, in which role; only people of the company's office. */
function AssignmentDialog({
    admin,
    open,
    onClose,
}: {
    admin: Props['admin'];
    open: boolean;
    onClose: () => void;
}) {
    const firstCompany = admin.lookups.companies[0];
    const form = useForm({
        office_id: String(
            firstCompany?.office_id ?? admin.lookups.offices[0]?.id ?? '',
        ),
        company_id: String(firstCompany?.id ?? ''),
        user_id: '',
        assignment_role: 'accountant_in_charge',
        primary: false,
    });
    const people = admin.lookups.users.filter(
        (user) =>
            user.status === 'active' &&
            (user.offices as AnyRow[]).some(
                (office) =>
                    String(office.id) === form.data.office_id &&
                    office.status === 'active',
            ),
    );
    const company = admin.lookups.companies.find(
        (item) => String(item.id) === form.data.company_id,
    );
    const office = admin.lookups.offices.find(
        (item) => String(item.id) === form.data.office_id,
    );

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/office/company-assignments', {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('user_id', '');
                form.setData('primary', false);
                onClose();
            },
        });
    }

    const selectedUser = people.find(
        (user) => String(user.id) === form.data.user_id,
    );
    const initials = (name: string) =>
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase();

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[92dvh] gap-0 overflow-hidden bg-white p-0 text-[#142135] sm:max-w-2xl [&>button]:top-5 [&>button]:right-5 [&>button]:text-white [&>button]:opacity-80 [&>button]:hover:opacity-100">
                <div className="bg-gradient-to-br from-[#640000] to-[#8a1c1c] px-6 py-5 text-white">
                    <div className="flex items-center gap-4">
                        <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/25">
                            <ShieldCheck className="size-6" />
                        </span>
                        <div>
                            <DialogTitle className="text-xl font-black text-white">
                                Asignar responsable
                            </DialogTitle>
                            <DialogDescription className="mt-0.5 text-sm text-white/75">
                                Define quién atiende una empresa y con qué rol.
                            </DialogDescription>
                        </div>
                    </div>
                </div>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <div className="max-h-[62dvh] space-y-6 overflow-y-auto px-6 py-6">
                        <section>
                            <p className={sectionTitle}>
                                <span className={stepBadge}>1</span> Empresa
                                cliente
                            </p>
                            <select
                                id="assign-company"
                                aria-label="Empresa cliente"
                                className={`${input} w-full font-semibold`}
                                value={form.data.company_id}
                                onChange={(event) => {
                                    const next = admin.lookups.companies.find(
                                        (item) =>
                                            String(item.id) ===
                                            event.target.value,
                                    );
                                    form.setData({
                                        ...form.data,
                                        company_id: event.target.value,
                                        office_id: String(
                                            next?.office_id ?? '',
                                        ),
                                        user_id: '',
                                    });
                                }}
                            >
                                {admin.lookups.companies.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-[#f6e6e4] px-3 py-1 text-xs font-bold text-[#640000]">
                                <Building2 className="size-3.5" />
                                {office?.name ?? 'Sin oficina'}
                            </p>
                            {form.errors.company_id && (
                                <p className="mt-1 text-xs text-red-600">
                                    {form.errors.company_id}
                                </p>
                            )}
                        </section>

                        <section>
                            <p className={sectionTitle}>
                                <span className={stepBadge}>2</span> Rol en la
                                empresa
                            </p>
                            <div
                                role="radiogroup"
                                aria-label="Rol en la empresa"
                                className="grid grid-cols-2 gap-2 sm:grid-cols-3"
                            >
                                {admin.lookups.assignmentRoles.map((role) => (
                                    <button
                                        key={role}
                                        type="button"
                                        role="radio"
                                        aria-checked={
                                            form.data.assignment_role === role
                                        }
                                        onClick={() =>
                                            form.setData(
                                                'assignment_role',
                                                role,
                                            )
                                        }
                                        className={`rounded-xl border px-3 py-2.5 text-left text-sm font-bold transition ${form.data.assignment_role === role ? 'border-[#640000] bg-[#640000] text-white shadow-md' : 'border-slate-200 bg-white text-slate-700 hover:border-[#c9a36a] hover:bg-amber-50'}`}
                                    >
                                        {assignmentRoleLabels[role] ??
                                            label(role)}
                                    </button>
                                ))}
                            </div>
                            {form.errors.assignment_role && (
                                <p className="mt-1 text-xs text-red-600">
                                    {form.errors.assignment_role}
                                </p>
                            )}
                        </section>

                        <section>
                            <p className={sectionTitle}>
                                <span className={stepBadge}>3</span> Colaborador
                                <span className="ml-auto text-xs font-semibold text-slate-400">
                                    {people.length} disponibles en la oficina
                                </span>
                            </p>
                            <div
                                role="radiogroup"
                                aria-label="Colaborador"
                                className="max-h-56 space-y-1.5 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50/60 p-1.5"
                            >
                                {people.map((user) => {
                                    const chosen =
                                        String(user.id) === form.data.user_id;

                                    return (
                                        <button
                                            key={user.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={chosen}
                                            onClick={() =>
                                                form.setData(
                                                    'user_id',
                                                    String(user.id),
                                                )
                                            }
                                            className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left transition ${chosen ? 'border-[#640000] bg-white shadow-sm ring-1 ring-[#640000]' : 'border-transparent bg-white hover:border-slate-200'}`}
                                        >
                                            <span
                                                className={`grid size-9 shrink-0 place-items-center rounded-full text-xs font-black ${chosen ? 'bg-[#640000] text-white' : 'bg-[#f6e6e4] text-[#640000]'}`}
                                            >
                                                {initials(user.name)}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-bold">
                                                    {user.name}
                                                </span>
                                                <span className="block truncate text-xs text-slate-500">
                                                    {(
                                                        user.roles as string[]
                                                    )[0] ?? 'Sin rol'}{' '}
                                                    · {user.email}
                                                </span>
                                            </span>
                                            {chosen && (
                                                <BadgeCheck className="size-5 shrink-0 text-[#640000]" />
                                            )}
                                        </button>
                                    );
                                })}
                                {people.length === 0 && (
                                    <p className="p-4 text-center text-sm text-amber-700">
                                        La oficina de{' '}
                                        {company?.name ?? 'esta empresa'} no
                                        tiene colaboradores activos.
                                    </p>
                                )}
                            </div>
                            {form.errors.user_id && (
                                <p className="mt-1 text-xs text-red-600">
                                    {form.errors.user_id}
                                </p>
                            )}
                        </section>

                        <label className="flex cursor-pointer items-center gap-4 rounded-xl border border-slate-200 p-4">
                            <span className="min-w-0 flex-1">
                                <b className="block text-sm">
                                    Responsable principal
                                </b>
                                <span className="block text-xs text-slate-500">
                                    Reemplaza al principal actual de este rol en
                                    la empresa; el anterior queda en el
                                    histórico.
                                </span>
                            </span>
                            <input
                                type="checkbox"
                                role="switch"
                                className="peer sr-only"
                                checked={form.data.primary}
                                onChange={(event) =>
                                    form.setData(
                                        'primary',
                                        event.target.checked,
                                    )
                                }
                            />
                            <span
                                aria-hidden
                                className={`relative h-6 w-11 shrink-0 rounded-full transition ${form.data.primary ? 'bg-[#640000]' : 'bg-slate-300'}`}
                            >
                                <span
                                    className={`absolute top-0.5 size-5 rounded-full bg-white shadow transition-all ${form.data.primary ? 'left-[22px]' : 'left-0.5'}`}
                                />
                            </span>
                        </label>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <p className="min-w-0 flex-1 text-xs text-slate-500">
                            {selectedUser ? (
                                <>
                                    <b className="text-[#142135]">
                                        {selectedUser.name}
                                    </b>{' '}
                                    será{' '}
                                    {(
                                        assignmentRoleLabels[
                                            form.data.assignment_role
                                        ] ?? ''
                                    ).toLowerCase()}{' '}
                                    de {company?.name}
                                    {form.data.primary ? ' (principal)' : ''}.
                                </>
                            ) : (
                                'Selecciona un colaborador para continuar.'
                            )}
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className={ghostButton}
                                onClick={onClose}
                            >
                                Cancelar
                            </button>
                            <button
                                className={button}
                                disabled={
                                    form.processing || form.data.user_id === ''
                                }
                            >
                                Asignar
                            </button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
function TaskTemplatesScreen({ admin }: { admin: Props['admin'] }) {
    return (
        <div className="space-y-5">
            <TemplateForm admin={admin} />
            <section className={`${panel} overflow-hidden`}>
                <TableHeader
                    title="Plantillas de tareas"
                    action="Plantillas mensuales y recurrentes"
                />
                <div className="grid gap-4 p-5 lg:grid-cols-2">
                    {admin.taskTemplates.map((template) => (
                        <TemplateCard
                            key={template.id}
                            template={template}
                            admin={admin}
                        />
                    ))}
                </div>
            </section>
        </div>
    );
}

function TemplateForm({
    admin,
    template,
}: {
    admin: Props['admin'];
    template?: AnyRow;
}) {
    const form = useForm({
        module: template?.module ?? admin.lookups.modules[0] ?? 'accounting',
        task_type: template?.task_type ?? '',
        title: template?.title ?? '',
        description: template?.description ?? '',
        assignment_role: template?.assignment_role ?? '',
        reviewer_role: template?.reviewer_role ?? '',
        default_priority: template?.default_priority ?? 'normal',
        default_due_days: String(template?.default_due_days ?? 5),
        is_active: template ? Boolean(template.is_active) : true,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const payload = {
            ...form.data,
            default_due_days: Number(form.data.default_due_days),
        };

        if (template?.id) {
            router.patch(`/work/task-templates/${template.id}`, payload, {
                preserveScroll: true,
            });

            return;
        }

        router.post('/work/task-templates', payload, {
            preserveScroll: true,
            onSuccess: () => form.reset('task_type', 'title', 'description'),
        });
    }

    return (
        <form
            onSubmit={submit}
            className={`${template ? 'rounded-xl border border-slate-100 p-4' : panel + ' p-5'}`}
        >
            <h2 className="mb-4 flex items-center gap-2 text-lg font-black">
                <ClipboardCheck className="size-5 text-[#640000]" />{' '}
                {template ? 'Editar plantilla' : 'Nueva plantilla'}
            </h2>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <select
                    className={input}
                    value={form.data.module}
                    onChange={(event) =>
                        form.setData('module', event.target.value)
                    }
                >
                    {admin.lookups.modules.map((module) => (
                        <option key={module} value={module}>
                            {module}
                        </option>
                    ))}
                </select>
                <input
                    className={input}
                    placeholder="Tipo técnico"
                    value={form.data.task_type}
                    onChange={(event) =>
                        form.setData('task_type', event.target.value)
                    }
                    required
                />
                <input
                    className={input}
                    placeholder="Título"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    required
                />
                <select
                    className={input}
                    value={form.data.default_priority}
                    onChange={(event) =>
                        form.setData('default_priority', event.target.value)
                    }
                >
                    {admin.lookups.priorities.map((priority) => (
                        <option key={priority} value={priority}>
                            {label(priority)}
                        </option>
                    ))}
                </select>
                <select
                    className={input}
                    value={form.data.assignment_role ?? ''}
                    onChange={(event) =>
                        form.setData('assignment_role', event.target.value)
                    }
                >
                    <option value="">Sin asignación automática</option>
                    {admin.lookups.assignmentRoles.map((role) => (
                        <option key={role} value={role}>
                            {label(role)}
                        </option>
                    ))}
                </select>
                <select
                    className={input}
                    value={form.data.reviewer_role ?? ''}
                    onChange={(event) =>
                        form.setData('reviewer_role', event.target.value)
                    }
                >
                    <option value="">Sin revisor automático</option>
                    {admin.lookups.assignmentRoles.map((role) => (
                        <option key={role} value={role}>
                            {label(role)}
                        </option>
                    ))}
                </select>
                <input
                    className={input}
                    type="number"
                    min="0"
                    max="120"
                    value={form.data.default_due_days}
                    onChange={(event) =>
                        form.setData('default_due_days', event.target.value)
                    }
                />
                <label className="flex min-h-11 items-center gap-2 rounded-xl border border-slate-300 px-3 text-sm font-bold">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(event) =>
                            form.setData('is_active', event.target.checked)
                        }
                    />
                    Activa
                </label>
                <textarea
                    className="min-h-20 rounded-xl border border-slate-300 p-3 text-sm md:col-span-2 xl:col-span-3"
                    placeholder="Descripción"
                    value={form.data.description ?? ''}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
                <button className={button} disabled={form.processing}>
                    Guardar
                </button>
            </div>
        </form>
    );
}

function TemplateCard({
    template,
    admin,
}: {
    template: AnyRow;
    admin: Props['admin'];
}) {
    return (
        <div className="rounded-xl border border-slate-100">
            <div className="border-b border-slate-100 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="font-black">{template.title}</p>
                        <p className="text-xs text-slate-500">
                            {template.module} · {template.task_type}
                        </p>
                    </div>
                    <Badge value={template.is_active ? 'active' : 'inactive'} />
                </div>
                <p className="mt-2 text-sm text-slate-600">
                    {template.description}
                </p>
                <p className="mt-2 text-xs font-bold text-slate-500">
                    {template.generated_tasks} tareas generadas · vence en{' '}
                    {template.default_due_days} días
                </p>
            </div>
            <TemplateForm admin={admin} template={template} />
        </div>
    );
}

function TaskDetailScreen({ admin }: { admin: Props['admin'] }) {
    const task = admin.task?.info;

    if (!task) {
        return <EmptyState title="Tarea no encontrada" />;
    }

    return (
        <div className="space-y-5">
            <section className={`${panel} p-5`}>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-black text-slate-500 uppercase">
                            Task Detail
                        </p>
                        <h2 className="mt-1 text-2xl font-black">
                            {task.title}
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            {task.company_name ?? 'Sin empresa'} ·{' '}
                            {task.office_name} ·{' '}
                            {task.period_name ?? 'Sin período'}
                        </p>
                    </div>
                    <Badge value={task.status} />
                </div>
                <div className="mt-5 grid gap-4 md:grid-cols-4">
                    <Info label="Módulo" value={task.module} />
                    <Info label="Tipo" value={task.task_type} />
                    <Info label="Prioridad" value={label(task.priority)} />
                    <Info label="Vence" value={date(task.due_at)} />
                    <Info
                        label="Asignado"
                        value={task.assignee_name ?? 'Sin responsable'}
                    />
                    <Info
                        label="Revisor"
                        value={task.reviewer_name ?? 'Sin revisor'}
                    />
                    <Info
                        label="Creado por"
                        value={task.creator_name ?? 'Sistema'}
                    />
                    <Info label="Completado" value={date(task.completed_at)} />
                </div>
                {task.description && (
                    <p className="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">
                        {task.description}
                    </p>
                )}
            </section>
            <TaskActionPanel admin={admin} task={task} />
            <section className="grid gap-5 xl:grid-cols-[1fr_.8fr]">
                <div className={`${panel} overflow-hidden`}>
                    <TableHeader title="Comentarios y observaciones" />
                    <SimpleTable
                        columns={['Tipo', 'Mensaje', 'Usuario', 'Fecha']}
                        rows={admin.task?.comments ?? []}
                        render={(comment) => [
                            label(comment.type),
                            comment.message,
                            comment.user_name ?? 'Sistema',
                            date(comment.created_at),
                        ]}
                    />
                </div>
                <div className={`${panel} overflow-hidden`}>
                    <TableHeader title="Auditoría" />
                    <SimpleTable
                        columns={['Acción', 'Usuario', 'Fecha']}
                        rows={admin.task?.audit ?? []}
                        render={(item) => [
                            item.action,
                            item.user_name ?? 'Sistema',
                            date(item.occurred_at),
                        ]}
                    />
                </div>
            </section>
        </div>
    );
}

function TaskActionPanel({
    admin,
    task,
}: {
    admin: Props['admin'];
    task: AnyRow;
}) {
    const comment = useForm({ message: '', type: 'comment' });
    const reassign = useForm({
        assigned_to: String(task.assigned_to ?? ''),
        reviewer_id: String(task.reviewer_id ?? ''),
    });

    function addComment(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        comment.post(`/work/tasks/${task.id}/comments`, {
            preserveScroll: true,
            onSuccess: () => comment.reset('message'),
        });
    }

    function saveReassign(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        reassign.post(`/work/tasks/${task.id}/reassign`, {
            preserveScroll: true,
        });
    }

    return (
        <section className="grid gap-5 xl:grid-cols-3">
            <form onSubmit={addComment} className={`${panel} p-5`}>
                <h3 className="mb-3 font-black">Agregar comentario</h3>
                <select
                    className={`${input} mb-3 w-full`}
                    value={comment.data.type}
                    onChange={(event) =>
                        comment.setData('type', event.target.value)
                    }
                >
                    <option value="comment">Comentario</option>
                    <option value="observation">Observación</option>
                    <option value="blocker">Bloqueo</option>
                </select>
                <textarea
                    className="min-h-24 w-full rounded-xl border border-slate-300 p-3 text-sm"
                    value={comment.data.message}
                    onChange={(event) =>
                        comment.setData('message', event.target.value)
                    }
                    required
                />
                <button
                    className={`${button} mt-3`}
                    disabled={comment.processing}
                >
                    Comentar
                </button>
            </form>
            <form onSubmit={saveReassign} className={`${panel} p-5`}>
                <h3 className="mb-3 font-black">Reasignar</h3>
                <select
                    className={`${input} mb-3 w-full`}
                    value={reassign.data.assigned_to}
                    onChange={(event) =>
                        reassign.setData('assigned_to', event.target.value)
                    }
                >
                    <option value="">Sin responsable</option>
                    {admin.lookups.users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name}
                        </option>
                    ))}
                </select>
                <select
                    className={`${input} mb-3 w-full`}
                    value={reassign.data.reviewer_id}
                    onChange={(event) =>
                        reassign.setData('reviewer_id', event.target.value)
                    }
                >
                    <option value="">Sin revisor</option>
                    {admin.lookups.users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name}
                        </option>
                    ))}
                </select>
                <button className={button} disabled={reassign.processing}>
                    Guardar asignación
                </button>
            </form>
            <div className={`${panel} p-5`}>
                <h3 className="mb-3 font-black">Cambiar estado</h3>
                <div className="grid gap-2">
                    {[
                        'in_progress',
                        'ready_for_review',
                        'in_review',
                        'changes_requested',
                        'approved',
                        'completed',
                        'cancelled',
                        'blocked',
                    ].map((status) => (
                        <button
                            key={status}
                            className={ghostButton}
                            onClick={() =>
                                router.post(
                                    `/work/tasks/${task.id}/transition`,
                                    { status },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {label(status)}
                        </button>
                    ))}
                </div>
            </div>
        </section>
    );
}

function ReviewsScreen({ admin }: { admin: Props['admin'] }) {
    return (
        <section className={`${panel} overflow-hidden`}>
            <TableHeader
                title="Revisiones"
                action="Revisiones de períodos empresariales"
            />
            <SimpleTable
                columns={[
                    'Empresa',
                    'Período',
                    'Revisor',
                    'Estado',
                    'Fechas',
                    'Acción',
                ]}
                rows={admin.reviews}
                render={(review) => [
                    <span key="company" className="font-bold">
                        {review.company_name}
                        <span className="block text-xs font-normal text-slate-500">
                            {review.office_name}
                        </span>
                    </span>,
                    review.period_name,
                    review.reviewer_name ?? 'Sin revisor',
                    <Badge key="status" value={review.status} />,
                    `Enviada ${date(review.submitted_at)} · aprobada ${date(review.approved_at)}`,
                    <Link
                        key="link"
                        className={ghostButton}
                        href={`/work/reviews/${review.id}`}
                    >
                        Detalle
                    </Link>,
                ]}
            />
        </section>
    );
}

function ReviewDetailScreen({ admin }: { admin: Props['admin'] }) {
    const review = admin.review?.info;
    const changeForm = useForm({ message: '' });

    if (!review) {
        return <EmptyState title="Revisión no encontrada" />;
    }

    return (
        <div className="space-y-5">
            <section className={`${panel} p-5`}>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-black text-slate-500 uppercase">
                            Review Detail
                        </p>
                        <h2 className="mt-1 text-2xl font-black">
                            {review.company_name}
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            {review.office_name} · {review.period_name} ·
                            Revisor: {review.reviewer_name ?? 'Sin revisor'}
                        </p>
                    </div>
                    <Badge value={review.status} />
                </div>
                <div className="mt-5 flex flex-wrap gap-2">
                    {review.status === 'ready_for_review' && (
                        <button
                            className={button}
                            onClick={() =>
                                router.post(
                                    `/work/reviews/${review.id}/start`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Iniciar revisión
                        </button>
                    )}
                    {review.status === 'in_review' && (
                        <button
                            className={button}
                            onClick={() =>
                                router.post(
                                    `/work/reviews/${review.id}/approve`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Aprobar
                        </button>
                    )}
                    {review.status === 'first_level_approved' && (
                        <button
                            className={button}
                            onClick={() =>
                                router.post(
                                    `/accountant/reviews/${review.id}/upper-approve`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Aprobar revisión superior
                        </button>
                    )}
                </div>
                {review.status === 'first_level_approved' && (
                    <p className="mt-2 text-sm text-slate-600">
                        El contador encargado aprobó el primer nivel. Como
                        contador general, confirma o solicita cambios.
                    </p>
                )}
                <form
                    className="mt-4 flex flex-wrap gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        changeForm.post(`/work/reviews/${review.id}/changes`, {
                            preserveScroll: true,
                            onSuccess: () => changeForm.reset('message'),
                        });
                    }}
                >
                    <input
                        className={`${input} min-w-72 flex-1`}
                        placeholder="Mensaje para solicitar cambios"
                        value={changeForm.data.message}
                        onChange={(event) =>
                            changeForm.setData('message', event.target.value)
                        }
                    />
                    <button
                        className={ghostButton}
                        disabled={changeForm.processing}
                    >
                        Solicitar cambios
                    </button>
                </form>
            </section>
            <section className={`${panel} overflow-hidden`}>
                <TableHeader title="Tareas del período revisado" />
                <TaskTable rows={admin.review?.tasks ?? []} />
            </section>
            <section className={`${panel} overflow-hidden`}>
                <TableHeader title="Auditoría de revisión" />
                <SimpleTable
                    columns={['Acción', 'Usuario', 'Fecha']}
                    rows={admin.review?.audit ?? []}
                    render={(item) => [
                        item.action,
                        item.user_name ?? 'Sistema',
                        date(item.occurred_at),
                    ]}
                />
            </section>
        </div>
    );
}

function ClosingsScreen({ admin }: { admin: Props['admin'] }) {
    return (
        <section className={`${panel} overflow-hidden`}>
            <TableHeader
                title="Resumen de cierres"
                action="Control de cierres por empresa y período"
            />
            <SimpleTable
                columns={[
                    'Empresa',
                    'Período',
                    'Trabajo',
                    'Revisión',
                    'Tareas',
                    'Acciones',
                ]}
                rows={admin.closings}
                render={(closing) => [
                    <span key="company" className="font-bold">
                        {closing.company_name}
                        <span className="block text-xs font-normal text-slate-500">
                            {closing.office_name}
                        </span>
                    </span>,
                    <span key="period">
                        {closing.period_name}
                        <span className="block text-xs text-slate-500">
                            {date(closing.starts_on)} - {date(closing.ends_on)}
                        </span>
                    </span>,
                    <span key="work">
                        <Badge
                            value={
                                closing.closed_at
                                    ? 'closed'
                                    : (closing.work_status ?? 'not_started')
                            }
                        />
                        <span className="mt-1 block text-xs text-slate-500">
                            {closing.progress ?? 0}% avance
                        </span>
                    </span>,
                    <Badge
                        key="review"
                        value={closing.review_status ?? 'draft'}
                    />,
                    `${closing.tasks_count} tareas · ${closing.pending_tasks ?? 0} pendientes · ${closing.blocked_tasks ?? 0} bloqueadas`,
                    <div key="actions" className="flex flex-wrap gap-2">
                        <button
                            className={ghostButton}
                            onClick={() =>
                                router.post(
                                    '/work/period-tasks',
                                    {
                                        company_id: closing.company_id,
                                        accounting_period_id: closing.id,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Generar
                        </button>
                        <button
                            className={button}
                            onClick={() =>
                                router.post(
                                    '/work/reviews',
                                    {
                                        company_id: closing.company_id,
                                        accounting_period_id: closing.id,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Send className="size-4" /> Revisión
                        </button>
                        {closing.review_id && (
                            <Link
                                className={ghostButton}
                                href={`/work/reviews/${closing.review_id}`}
                            >
                                Abrir
                            </Link>
                        )}
                    </div>,
                ]}
            />
        </section>
    );
}

function TableHeader({ title, action }: { title: string; action?: string }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
            <h2 className="text-lg font-black">{title}</h2>
            {action && (
                <p className="text-sm font-semibold text-slate-500">{action}</p>
            )}
        </div>
    );
}

function SimpleTable({
    columns,
    rows,
    render,
}: {
    columns: string[];
    rows: AnyRow[];
    render: (row: AnyRow) => React.ReactNode[];
}) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                    <tr>
                        {columns.map((column) => (
                            <th key={column} className="px-5 py-3">
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map((row, index) => (
                        <tr key={row.id ?? index}>
                            {render(row).map((cell, cellIndex) => (
                                <td
                                    key={cellIndex}
                                    className="px-5 py-4 align-top"
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <tr>
                            <td
                                className="px-5 py-8 text-center text-slate-500"
                                colSpan={columns.length}
                            >
                                Sin registros.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function TaskTable({ rows }: { rows: AnyRow[] }) {
    return (
        <SimpleTable
            columns={[
                'Tarea',
                'Empresa',
                'Asignado',
                'Estado',
                'Vence',
                'Acción',
            ]}
            rows={rows}
            render={(task) => [
                <span key="task" className="font-bold">
                    {task.title}
                    <span className="block text-xs font-normal text-slate-500">
                        {task.module} · {task.task_type}
                    </span>
                </span>,
                task.company_name ?? 'Sin empresa',
                task.assignee_name ?? 'Sin responsable',
                <Badge key="status" value={task.status} />,
                date(task.due_at),
                <Link
                    key="link"
                    className={ghostButton}
                    href={`/work/tasks/${task.id}`}
                >
                    Detalle
                </Link>,
            ]}
        />
    );
}

function Info({ label: name, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-slate-100 bg-slate-50 p-3">
            <p className="text-xs font-black text-slate-500 uppercase">
                {name}
            </p>
            <p className="mt-1 font-bold">{value}</p>
        </div>
    );
}

function EmptyState({ title }: { title: string }) {
    return (
        <section className={`${panel} p-10 text-center`}>
            <AlertTriangle className="mx-auto size-8 text-[#640000]" />
            <h2 className="mt-3 text-xl font-black">{title}</h2>
        </section>
    );
}
