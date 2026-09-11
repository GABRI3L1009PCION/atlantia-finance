import { Head } from '@inertiajs/react';
import {
    Building2,
    CheckCircle2,
    ChevronDown,
    Crown,
    Download,
    FileText,
    KeyRound,
    Laptop,
    LockKeyhole,
    MoreHorizontal,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    ShieldCheck,
    UserPlus,
    Users,
    X,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type UserStatus = 'Activo' | 'Invitado' | 'Inactivo';
type RoleName =
    | 'Administrador'
    | 'Contador General'
    | 'Auxiliar Contable'
    | 'Supervisor'
    | 'Cliente';
type UserRow = {
    id: number;
    username: string;
    name: string;
    email: string;
    role: RoleName;
    companies: string;
    status: UserStatus;
    lastAccess: string;
    twoFactor: boolean;
};

const users: UserRow[] = [
    {
        id: 1,
        username: 'mcontreras',
        name: 'María Contreras',
        email: 'maria.contreras@atlantia.com',
        role: 'Contador General',
        companies: 'Demo Atlántia S.A.',
        status: 'Activo',
        lastAccess: '29 Sep 2026 14:20',
        twoFactor: true,
    },
    {
        id: 2,
        username: 'alopez',
        name: 'Ana López',
        email: 'ana.lopez@atlantia.com',
        role: 'Auxiliar Contable',
        companies: 'Demo Atlántia S.A.',
        status: 'Activo',
        lastAccess: '29 Sep 2026 11:38',
        twoFactor: true,
    },
    {
        id: 3,
        username: 'cmendoza',
        name: 'Carlos Mendoza',
        email: 'carlos.mendoza@atlantia.com',
        role: 'Supervisor',
        companies: 'Demo Atlántia S.A. · Comercial del Norte S.A.',
        status: 'Activo',
        lastAccess: '28 Sep 2026 16:10',
        twoFactor: true,
    },
    {
        id: 4,
        username: 'lramirez',
        name: 'Luis Ramírez',
        email: 'luis.ramirez@atlantia.com',
        role: 'Administrador',
        companies: 'Todas las empresas',
        status: 'Activo',
        lastAccess: '29 Sep 2026 15:02',
        twoFactor: true,
    },
    {
        id: 5,
        username: 'dmartinez',
        name: 'Diego Martínez',
        email: 'diego.martinez@atlantia.com',
        role: 'Cliente',
        companies: 'Inversiones La Costa S.A.',
        status: 'Invitado',
        lastAccess: '27 Sep 2026 09:25',
        twoFactor: false,
    },
    {
        id: 6,
        username: 'sherrera',
        name: 'Sofía Herrera',
        email: 'sofia.herrera@atlantia.com',
        role: 'Auxiliar Contable',
        companies: 'Demo Atlántia S.A.',
        status: 'Activo',
        lastAccess: '29 Sep 2026 12:11',
        twoFactor: true,
    },
    {
        id: 7,
        username: 'jruiz',
        name: 'Javier Ruiz',
        email: 'javier.ruiz@atlantia.com',
        role: 'Contador General',
        companies: 'Holding Atlantia S.A.',
        status: 'Activo',
        lastAccess: '28 Sep 2026 18:45',
        twoFactor: true,
    },
    {
        id: 8,
        username: 'pgarcia',
        name: 'Paula García',
        email: 'paula.garcia@atlantia.com',
        role: 'Supervisor',
        companies: 'Demo Atlántia S.A. · Comercial del Norte S.A.',
        status: 'Inactivo',
        lastAccess: '15 Sep 2026 10:30',
        twoFactor: true,
    },
];

const roles: {
    name: RoleName;
    users: number;
    detail: string;
    icon: LucideIcon;
}[] = [
    {
        name: 'Administrador',
        users: 8,
        detail: 'Acceso total al sistema',
        icon: Crown,
    },
    {
        name: 'Contador General',
        users: 6,
        detail: 'Gestión contable y fiscal',
        icon: FileText,
    },
    {
        name: 'Auxiliar Contable',
        users: 7,
        detail: 'Registro y consulta',
        icon: FileText,
    },
    {
        name: 'Supervisor',
        users: 4,
        detail: 'Supervisión y aprobaciones',
        icon: Users,
    },
    {
        name: 'Cliente',
        users: 3,
        detail: 'Acceso de solo lectura',
        icon: Users,
    },
];

const roleStyle: Record<RoleName, string> = {
    Administrador: 'bg-red-100 text-red-700',
    'Contador General': 'bg-blue-100 text-blue-700',
    'Auxiliar Contable': 'bg-sky-100 text-sky-700',
    Supervisor: 'bg-violet-100 text-violet-700',
    Cliente: 'bg-slate-100 text-slate-600',
};
const statusStyle: Record<UserStatus, string> = {
    Activo: 'bg-emerald-100 text-emerald-700',
    Invitado: 'bg-amber-100 text-amber-700',
    Inactivo: 'bg-red-100 text-red-700',
};
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    change,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
    change?: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-center gap-4 p-5`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div>
                <p className="text-xs font-semibold text-slate-600">{label}</p>
                <div className="mt-1 flex items-end gap-3">
                    <strong className="text-2xl">{value}</strong>
                    {change && (
                        <span className="pb-1 text-xs font-semibold text-emerald-600">
                            {change}
                        </span>
                    )}
                </div>
                <p className="text-[11px] text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

function Toggle({
    enabled,
    onChange,
    label,
}: {
    enabled: boolean;
    onChange: () => void;
    label: string;
}) {
    return (
        <button
            type="button"
            onClick={onChange}
            aria-label={label}
            aria-pressed={enabled}
            className={`relative h-6 w-11 rounded-full transition ${enabled ? 'bg-[#74000a]' : 'bg-slate-300'}`}
        >
            <span
                className={`absolute top-1 size-4 rounded-full bg-white shadow transition ${enabled ? 'left-6' : 'left-1'}`}
            />
        </button>
    );
}

export default function UsersRolesWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [query, setQuery] = useState('');
    const [roleFilter, setRoleFilter] = useState('Todos');
    const [statusFilter, setStatusFilter] = useState('Todos');
    const [selectedRole, setSelectedRole] = useState<RoleName>('Administrador');
    const [selectedUsers, setSelectedUsers] = useState<number[]>([]);
    const [notice, setNotice] = useState('');
    const [security, setSecurity] = useState({
        twoFactor: true,
        failedAttempts: true,
        multipleSessions: true,
        externalIp: false,
    });

    const filtered = useMemo(
        () =>
            users.filter((user) => {
                const matchesRole =
                    roleFilter === 'Todos' || user.role === roleFilter;
                const matchesStatus =
                    statusFilter === 'Todos' || user.status === statusFilter;
                const haystack =
                    `${user.username} ${user.name} ${user.email}`.toLowerCase();

                return (
                    matchesRole &&
                    matchesStatus &&
                    haystack.includes(query.toLowerCase())
                );
            }),
        [query, roleFilter, statusFilter],
    );

    const showNotice = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3400);
    };
    const toggleUser = (id: number) =>
        setSelectedUsers((current) =>
            current.includes(id)
                ? current.filter((item) => item !== id)
                : [...current, id],
        );
    const permissions =
        selectedRole === 'Cliente'
            ? ['ventas.ver', 'reportes.ver', 'documentos.ver']
            : [
                  'ventas.ver',
                  'compras.aprobar',
                  'bancos.conciliar',
                  'periodos.cerrar',
                  'fiscal.configurar',
                  'auditoria.ver',
              ];

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Usuarios y Roles · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Usuarios y
                            Roles
                        </p>
                        <h1 className="text-3xl font-bold text-[#74000a]">
                            Usuarios y Roles
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Control de acceso, permisos y seguridad por empresa
                        </p>
                    </div>
                    <button
                        type="button"
                        className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2.5 text-xs font-semibold"
                    >
                        <Building2 className="size-4 text-[#74000a]" />
                        {company.name}
                        <ChevronDown className="size-4" />
                    </button>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
                    <Metric
                        icon={Users}
                        label="Usuarios activos"
                        value="28"
                        change="↑ 12%"
                        detail="de 32 usuarios totales"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={ShieldCheck}
                        label="Roles"
                        value="5"
                        detail="roles configurados"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={LockKeyhole}
                        label="2FA habilitado"
                        value="24"
                        change="↑ 20%"
                        detail="86% del total de usuarios"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={Laptop}
                        label="Sesiones activas"
                        value="7"
                        change="↑ 40%"
                        detail="usuarios en línea"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                </section>
                <section
                    className={`${panel} grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_1.3fr_auto]`}
                >
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todas las empresas
                        <ChevronDown className="size-4" />
                    </button>
                    <select
                        value={statusFilter}
                        onChange={(event) =>
                            setStatusFilter(event.target.value)
                        }
                        className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                    >
                        <option>Todos</option>
                        <option>Activo</option>
                        <option>Invitado</option>
                        <option>Inactivo</option>
                    </select>
                    <select
                        value={roleFilter}
                        onChange={(event) => setRoleFilter(event.target.value)}
                        className="rounded-lg border bg-white px-3 py-2.5 text-xs"
                    >
                        <option>Todos</option>
                        {roles.map((role) => (
                            <option key={role.name}>{role.name}</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        className="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        Todas las áreas
                        <ChevronDown className="size-4" />
                    </button>
                    <label className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Buscar por nombre, correo o usuario..."
                            className="w-full rounded-lg border py-2.5 pr-3 pl-9 text-xs"
                        />
                    </label>
                    <button
                        type="button"
                        onClick={() =>
                            showNotice(
                                `${filtered.length} usuarios encontrados.`,
                            )
                        }
                        className="flex items-center justify-center gap-2 rounded-lg bg-[#74000a] px-5 py-2.5 text-xs font-semibold text-white"
                    >
                        <Search className="size-4" />
                        Buscar
                    </button>
                </section>
                <article className={`${panel} overflow-hidden`}>
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                        <div>
                            <h2 className="flex items-center gap-2 font-bold">
                                <Users className="size-5 text-[#74000a]" />
                                Usuarios (28)
                            </h2>
                            <p className="text-xs text-slate-500">
                                Gestiona los usuarios del sistema, sus roles y
                                permisos.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Listado de usuarios preparado para exportar.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Download className="size-4" />
                                Exportar usuarios
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Formulario de invitación preparado.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border border-[#9b1923] px-4 py-2 text-xs font-semibold text-[#74000a]"
                            >
                                <UserPlus className="size-4" />
                                Invitar usuario
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Formulario de creación de rol preparado.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg bg-[#74000a] px-5 py-2 text-xs font-semibold text-white"
                            >
                                <Plus className="size-4" />
                                Crear rol
                            </button>
                        </div>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1060px] text-left text-xs">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            aria-label="Seleccionar todos"
                                            checked={
                                                selectedUsers.length ===
                                                    filtered.length &&
                                                filtered.length > 0
                                            }
                                            onChange={() =>
                                                setSelectedUsers(
                                                    selectedUsers.length ===
                                                        filtered.length
                                                        ? []
                                                        : filtered.map(
                                                              (user) => user.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </th>
                                    <th>Usuario</th>
                                    <th>Nombre</th>
                                    <th>Correo</th>
                                    <th>Rol</th>
                                    <th>Empresas asignadas</th>
                                    <th>Estado</th>
                                    <th>Último acceso</th>
                                    <th>2FA</th>
                                    <th className="pr-4 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filtered.map((user) => (
                                    <tr
                                        key={user.id}
                                        className={
                                            selectedUsers.includes(user.id)
                                                ? 'bg-rose-50'
                                                : ''
                                        }
                                    >
                                        <td className="px-4 py-2.5">
                                            <input
                                                type="checkbox"
                                                aria-label={`Seleccionar ${user.name}`}
                                                checked={selectedUsers.includes(
                                                    user.id,
                                                )}
                                                onChange={() =>
                                                    toggleUser(user.id)
                                                }
                                            />
                                        </td>
                                        <td className="font-bold">
                                            {user.username}
                                        </td>
                                        <td>{user.name}</td>
                                        <td>{user.email}</td>
                                        <td>
                                            <span
                                                className={`rounded-full px-3 py-1 font-semibold ${roleStyle[user.role]}`}
                                            >
                                                {user.role}
                                            </span>
                                        </td>
                                        <td className="max-w-48">
                                            {user.companies}
                                        </td>
                                        <td>
                                            <span
                                                className={`inline-flex items-center gap-1 rounded-full px-3 py-1 font-semibold ${statusStyle[user.status]}`}
                                            >
                                                <span className="size-1.5 rounded-full bg-current" />
                                                {user.status}
                                            </span>
                                        </td>
                                        <td>{user.lastAccess}</td>
                                        <td>
                                            {user.twoFactor ? (
                                                <span className="flex items-center gap-2 text-emerald-700">
                                                    <CheckCircle2 className="size-4" />
                                                    Sí
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-2 text-red-600">
                                                    <XCircle className="size-4" />
                                                    No
                                                </span>
                                            )}
                                        </td>
                                        <td className="pr-4">
                                            <div className="flex justify-center gap-3">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        showNotice(
                                                            `Editando a ${user.name}.`,
                                                        )
                                                    }
                                                    aria-label={`Editar ${user.name}`}
                                                >
                                                    <Pencil className="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    aria-label={`Más opciones de ${user.name}`}
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {!filtered.length && (
                            <div className="p-10 text-center text-sm text-slate-400">
                                No se encontraron usuarios.
                            </div>
                        )}
                    </div>
                </article>
                <section className="grid items-stretch gap-4 2xl:grid-cols-[minmax(0,1.5fr)_380px]">
                    <article className={`${panel} overflow-hidden`}>
                        <header className="flex items-center justify-between border-b px-5 py-3">
                            <div>
                                <h2 className="flex items-center gap-2 font-bold">
                                    <ShieldCheck className="size-5 text-[#74000a]" />
                                    Roles y permisos
                                </h2>
                                <p className="text-xs text-slate-500">
                                    Roles predefinidos y sus permisos en el
                                    sistema.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Gestión completa de roles abierta.',
                                    )
                                }
                                className="text-xs font-semibold text-[#74000a]"
                            >
                                Gestionar roles
                            </button>
                        </header>
                        <div className="grid md:grid-cols-[330px_1fr]">
                            <nav className="border-r p-3">
                                {roles.map(
                                    ({
                                        name,
                                        users: count,
                                        detail,
                                        icon: Icon,
                                    }) => (
                                        <button
                                            key={name}
                                            type="button"
                                            onClick={() =>
                                                setSelectedRole(name)
                                            }
                                            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left ${selectedRole === name ? 'bg-rose-50 text-[#74000a]' : 'hover:bg-slate-50'}`}
                                        >
                                            <Icon className="size-5 shrink-0" />
                                            <span className="min-w-0 flex-1">
                                                <strong className="block text-xs">
                                                    {name}
                                                </strong>
                                                <small className="block truncate text-[10px] text-slate-500">
                                                    {detail}
                                                </small>
                                            </span>
                                            <span className="text-[10px] text-slate-500">
                                                {count} usuarios
                                            </span>
                                        </button>
                                    ),
                                )}
                            </nav>
                            <div className="p-4">
                                <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h3 className="text-sm font-bold">
                                            Permisos del rol:{' '}
                                            <span className="text-[#74000a]">
                                                {selectedRole}
                                            </span>
                                        </h3>
                                        <p className="text-[11px] text-slate-500">
                                            {
                                                roles.find(
                                                    (role) =>
                                                        role.name ===
                                                        selectedRole,
                                                )?.detail
                                            }
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            showNotice(
                                                `Permisos de ${selectedRole} listos para editar.`,
                                            )
                                        }
                                        className="flex items-center gap-2 rounded-lg border border-[#9b1923] px-4 py-2 text-xs text-[#74000a]"
                                    >
                                        <Pencil className="size-4" />
                                        Editar permisos
                                    </button>
                                </header>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[580px] text-left text-[11px]">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="p-2">
                                                    Módulo / Permiso
                                                </th>
                                                <th>Ver</th>
                                                <th>Crear</th>
                                                <th>Editar</th>
                                                <th>Aprobar</th>
                                                <th>Eliminar</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {permissions.map((permission) => (
                                                <tr key={permission}>
                                                    <td className="p-2 font-medium">
                                                        {permission}
                                                    </td>
                                                    {[
                                                        'Ver',
                                                        'Crear',
                                                        'Editar',
                                                        'Aprobar',
                                                        'Eliminar',
                                                    ].map((action, index) => (
                                                        <td key={action}>
                                                            {selectedRole ===
                                                                'Cliente' &&
                                                            index > 0 ? (
                                                                <XCircle className="size-4 text-slate-300" />
                                                            ) : (
                                                                <CheckCircle2 className="size-4 text-emerald-600" />
                                                            )}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </article>
                    <article className={`${panel} p-5`}>
                        <h2 className="mb-4 flex items-center gap-2 font-bold">
                            <LockKeyhole className="size-5 text-[#74000a]" />
                            Seguridad de usuarios
                        </h2>
                        <div className="space-y-4">
                            {[
                                [
                                    'twoFactor',
                                    'Autenticación de dos factores (2FA)',
                                    'Requerir 2FA para todos los usuarios',
                                    KeyRound,
                                ],
                                [
                                    'failedAttempts',
                                    'Bloqueo por intentos fallidos',
                                    'Bloquear cuenta tras 5 intentos',
                                    LockKeyhole,
                                ],
                                [
                                    'multipleSessions',
                                    'Sesiones múltiples',
                                    'Permitir múltiples sesiones',
                                    Laptop,
                                ],
                                [
                                    'externalIp',
                                    'Inicio de sesión desde IP externa',
                                    'Requerir verificación adicional',
                                    Building2,
                                ],
                            ].map(([key, title, detail, Icon]) => (
                                <div
                                    key={String(key)}
                                    className="flex items-center gap-3"
                                >
                                    <Icon className="size-5 text-slate-600" />
                                    <div className="min-w-0 flex-1">
                                        <strong className="block text-xs">
                                            {String(title)}
                                        </strong>
                                        <p className="text-[10px] text-slate-500">
                                            {String(detail)}
                                        </p>
                                    </div>
                                    <Toggle
                                        label={`Cambiar ${title}`}
                                        enabled={
                                            security[
                                                key as keyof typeof security
                                            ] as boolean
                                        }
                                        onChange={() =>
                                            setSecurity((current) => ({
                                                ...current,
                                                [key as string]:
                                                    !current[
                                                        key as keyof typeof current
                                                    ],
                                            }))
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={() =>
                                showNotice(
                                    'Proceso de restablecimiento de acceso preparado.',
                                )
                            }
                            className="mt-5 flex w-full items-center justify-center gap-2 rounded-lg border border-[#9b1923] px-4 py-2.5 text-xs font-semibold text-[#74000a]"
                        >
                            <RotateCcw className="size-4" />
                            Restablecer acceso de usuario
                        </button>
                    </article>
                </section>
            </div>
        </main>
    );
}
